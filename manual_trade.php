<?php
/**
 * 手動投資操作頁
 *
 * 功能：
 *   - 顯示「手動操作」帳戶的現金、庫存、即時股價、損益
 *   - 選股票、買/賣、輸入股數 → 下單（同步扣稅費）
 *   - 一鍵歸零按鈕（清空庫存、現金回到 500 萬、清空交易紀錄）
 *
 * URL：
 *   /stock/manual_trade.php              → 顯示頁面
 *   ?action=api_get                      → JSON: 帳戶 + 股票即時價 + 庫存市值
 *   ?action=api_buy&stock=2330.TW&qty=1000   → JSON: 下單結果
 *   ?action=api_sell&stock=2330.TW&qty=500   → JSON: 下單結果
 *   ?action=api_reset                    → JSON: 歸零結果
 *
 * 帳戶 KEY = '手動操作'（與 stock_trader.py STRATEGIES 一致）
 * 初始資金 = 5,000,000（與 trader INITIAL_CAPITAL 一致）
 */

declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '0');

// ============================================================
// 設定
// ============================================================
$MANUAL_KEY = '手動操作';
$INITIAL_CAPITAL = 5_000_000;

$config = [
    'stock_list_file'  => __DIR__ . '/data/stock_list.json',
    'portfolio_file'   => __DIR__ . '/data/portfolio.json',
    'data_file'        => __DIR__ . '/data/stock_data.json',
    'settings_file'    => __DIR__ . '/data/indicator_settings.json',
];

$defaultTaxFee = [
    'tax' => ['sell' => 0.3, 'buy' => 0.0],
    'fee' => ['rate' => 0.1425, 'discount' => 28.0, 'min' => 20.0],
];

// ============================================================
// 共用函式（與 stock_trader.php 同步，維持獨立不互相 require）
// ============================================================

function mt_loadStockList(array $config): array {
    $file = $config['stock_list_file'];
    if (!file_exists($file)) return [];
    $data = json_decode(file_get_contents($file), true);
    if (!is_array($data) || !isset($data['stocks'])) return [];
    return $data['stocks'];
}

function mt_loadPortfolio(string $file): array {
    if (!file_exists($file)) return [];
    $data = json_decode(file_get_contents($file), true);
    return is_array($data) ? $data : [];
}

function mt_savePortfolio(string $file, array $data): bool {
    $tmp = $file . '.tmp';
    $ok = file_put_contents($tmp, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    if ($ok === false) return false;
    return rename($tmp, $file); // 原子寫入
}

function mt_loadTaxFee(array $config, array $default): array {
    if (!file_exists($config['settings_file'])) return $default;
    $s = json_decode(file_get_contents($config['settings_file']), true);
    if (!is_array($s)) return $default;
    $tax = isset($s['tax']) && is_array($s['tax']) ? $s['tax'] : $default['tax'];
    $fee = isset($s['fee']) && is_array($s['fee']) ? $s['fee'] : $default['fee'];
    return ['tax' => $tax, 'fee' => $fee];
}

function mt_calcTradeCost(string $action, float $total, array $tax_cfg, array $fee_cfg): array {
    $taxRate = $action === 'BUY' ? (float)($tax_cfg['buy'] ?? 0) : (float)($tax_cfg['sell'] ?? 0);
    $tax = $total * ($taxRate / 100);
    $feeRate = (float)($fee_cfg['rate'] ?? 0) * (float)($fee_cfg['discount'] ?? 100) / 100;
    $fee = max((float)($fee_cfg['min'] ?? 0), $total * ($feeRate / 100));
    return ['tax' => round($tax, 2), 'fee' => round($fee, 2)];
}

function mt_calculatePortfolioValue(array $portfolio, array $prices): float {
    $value = (float)($portfolio['cash'] ?? 0);
    $holdings = $portfolio['holdings'] ?? [];
    if (is_array($holdings)) {
        foreach ($holdings as $stock => $qty) {
            if ($qty > 0 && isset($prices[$stock]) && $prices[$stock] !== null) {
                $value += $qty * $prices[$stock];
            }
        }
    }
    return $value;
}

function mt_getLatestPrices(array $stocks, string $dataFile): array {
    $prices = [];
    if (!file_exists($dataFile)) return $prices;
    $all = json_decode(file_get_contents($dataFile), true);
    if (!is_array($all)) return $prices;
    foreach ($stocks as $s) {
        if (!isset($all[$s]) || !is_array($all[$s])) continue;
        for ($i = count($all[$s]) - 1; $i >= 0; $i--) {
            if (isset($all[$s][$i]['close']) && $all[$s][$i]['close'] !== null) {
                $prices[$s] = (float)$all[$s][$i]['close'];
                break;
            }
        }
    }
    return $prices;
}

function mt_ensureAccount(array &$portfolio, string $key, float $initialCash): void {
    if (!isset($portfolio[$key]) || !is_array($portfolio[$key])) {
        $portfolio[$key] = ['cash' => $initialCash, 'holdings' => [], 'trades' => []];
        return;
    }
    if (!isset($portfolio[$key]['cash']) || !is_numeric($portfolio[$key]['cash'])) {
        $portfolio[$key]['cash'] = $initialCash;
    }
    if (!isset($portfolio[$key]['holdings']) || !is_array($portfolio[$key]['holdings'])) {
        $portfolio[$key]['holdings'] = [];
    }
    if (!isset($portfolio[$key]['trades']) || !is_array($portfolio[$key]['trades'])) {
        $portfolio[$key]['trades'] = [];
    }
}

function mt_jsonResponse(array $data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// ============================================================
// API 路由
// ============================================================
$action = $_GET['action'] ?? '';

if ($action === 'api_get') {
    $stocks = mt_loadStockList($config);
    $portfolio = mt_loadPortfolio($config['portfolio_file']);
    mt_ensureAccount($portfolio, $MANUAL_KEY, $INITIAL_CAPITAL);

    $prices = mt_getLatestPrices($stocks, $config['data_file']);
    $acct = $portfolio[$MANUAL_KEY];
    $value = mt_calculatePortfolioValue($acct, $prices);
    $profit = $value - $INITIAL_CAPITAL;
    $profitRate = $INITIAL_CAPITAL > 0 ? ($profit / $INITIAL_CAPITAL) * 100 : 0;

    $holdings = [];
    if (isset($acct['holdings']) && is_array($acct['holdings'])) {
        foreach ($acct['holdings'] as $stock => $qty) {
            if ($qty <= 0) continue;
            $price = $prices[$stock] ?? null;
            $totalCost = 0.0; $totalQty = 0;
            foreach ($acct['trades'] as $t) {
                if ($t['stock'] !== $stock || $t['action'] !== 'BUY') continue;
                $totalCost += (float)($t['total_cost'] ?? 0);
                $totalQty += (int)($t['quantity'] ?? 0);
            }
            $costBasis = $totalQty > 0 ? $totalCost / $totalQty : null;
            $marketValue = $price !== null ? $qty * $price : null;
            $unrealized = ($price !== null && $costBasis !== null)
                ? round(($price - $costBasis) * $qty, 2)
                : null;

            $holdings[] = [
                'stock'         => $stock,
                'quantity'      => $qty,
                'price'         => $price,
                'cost_basis'    => $costBasis,
                'market_value'  => $marketValue,
                'unrealized_pl' => $unrealized,
            ];
        }
    }

    $trades = array_slice($acct['trades'] ?? [], -20);
    $trades = array_reverse($trades);

    mt_jsonResponse([
        'ok' => true,
        'account' => $MANUAL_KEY,
        'cash' => (float)$acct['cash'],
        'total_value' => round($value, 2),
        'profit' => round($profit, 2),
        'profit_rate' => round($profitRate, 2),
        'initial_capital' => $INITIAL_CAPITAL,
        'holdings' => $holdings,
        'recent_trades' => $trades,
        'stocks' => $stocks,
        'prices' => $prices,
    ]);
}

if ($action === 'api_buy' || $action === 'api_sell') {
    $stock = trim((string)($_GET['stock'] ?? $_POST['stock'] ?? ''));
    $qty = (int)($_GET['qty'] ?? $_POST['qty'] ?? 0);

    if ($stock === '' || $qty <= 0) {
        mt_jsonResponse(['ok' => false, 'error' => '股票代號與股數必填'], 400);
    }

    $stocks = mt_loadStockList($config);
    if (!in_array($stock, $stocks, true)) {
        mt_jsonResponse(['ok' => false, 'error' => "股票 {$stock} 不在清單中"], 400);
    }

    $prices = mt_getLatestPrices($stocks, $config['data_file']);
    if (!isset($prices[$stock])) {
        mt_jsonResponse(['ok' => false, 'error' => "查無 {$stock} 最新報價"], 400);
    }
    $price = $prices[$stock];

    // 手動操作的最小交易限制（沿用 stock_trader.py 的常數）
    $MIN_TRADE_AMOUNT = 10_000;
    $MIN_TRADE_SHARES = 100;

    $portfolio = mt_loadPortfolio($config['portfolio_file']);
    mt_ensureAccount($portfolio, $MANUAL_KEY, $INITIAL_CAPITAL);
    $acct = &$portfolio[$MANUAL_KEY];

    if ($action === 'api_buy') {
        // 對手動下單：直接用使用者輸入的股數（不做半倉），但套最小交易限制
        $targetAmount = max($qty * $price, $MIN_TRADE_AMOUNT, $MIN_TRADE_SHARES * $price);
        $actualQty = (int)floor($targetAmount / $price);
        if ($actualQty <= 0) {
            mt_jsonResponse(['ok' => false, 'error' => '股數無效'], 400);
        }
        $total = $price * $actualQty;
        $taxFee = mt_loadTaxFee($config, $defaultTaxFee);
        $cost = mt_calcTradeCost('BUY', $total, $taxFee['tax'], $taxFee['fee']);
        $totalCost = $total + $cost['tax'] + $cost['fee'];

        if ($acct['cash'] < $totalCost) {
            $msg = sprintf('現金不足：需要 NT$ %s（含稅費 NT$ %s + 手續費 NT$ %s），帳戶僅有 NT$ %s',
                number_format($totalCost, 0),
                number_format($cost['tax'], 0),
                number_format($cost['fee'], 0),
                number_format($acct['cash'], 0)
            );
            mt_jsonResponse(['ok' => false, 'error' => $msg, 'cash' => $acct['cash']], 400);
        }

        $acct['cash'] -= $totalCost;
        $acct['holdings'][$stock] = ($acct['holdings'][$stock] ?? 0) + $actualQty;
        $acct['trades'][] = [
            'date'       => date('Y-m-d H:i:s'),
            'stock'      => $stock,
            'action'     => 'BUY',
            'price'      => $price,
            'quantity'   => $actualQty,
            'total'      => round($total, 2),
            'tax'        => $cost['tax'],
            'fee'        => $cost['fee'],
            'total_cost' => round($totalCost, 2),
            'source'     => 'manual',
        ];
    } else { // api_sell
        $current = $acct['holdings'][$stock] ?? 0;
        if ($current < $qty) {
            mt_jsonResponse([
                'ok' => false,
                'error' => "庫存不足（持有 {$current} 股，欲賣 {$qty} 股）",
                'holding' => $current,
            ], 400);
        }

        $total = $price * $qty;
        $taxFee = mt_loadTaxFee($config, $defaultTaxFee);
        $cost = mt_calcTradeCost('SELL', $total, $taxFee['tax'], $taxFee['fee']);
        $netIncome = $total - $cost['tax'] - $cost['fee'];

        $acct['cash'] += $netIncome;
        $acct['holdings'][$stock] -= $qty;
        if ($acct['holdings'][$stock] <= 0) {
            unset($acct['holdings'][$stock]);
        }
        $acct['trades'][] = [
            'date'       => date('Y-m-d H:i:s'),
            'stock'      => $stock,
            'action'     => 'SELL',
            'price'      => $price,
            'quantity'   => $qty,
            'total'      => round($total, 2),
            'tax'        => $cost['tax'],
            'fee'        => $cost['fee'],
            'net_income' => round($netIncome, 2),
            'source'     => 'manual',
        ];
    }

    if (!mt_savePortfolio($config['portfolio_file'], $portfolio)) {
        mt_jsonResponse(['ok' => false, 'error' => '寫入 portfolio.json 失敗'], 500);
    }

    @mt_savePortfolio(__DIR__ . '/portfolio.json', $portfolio);

    mt_jsonResponse([
        'ok' => true,
        'action' => strtoupper(substr($action, 4)),
        'stock' => $stock,
        'price' => $price,
        'quantity' => $qty,
        'cash' => round($acct['cash'], 2),
        'message' => "{$stock} " . ($action === 'api_buy' ? '買進' : '賣出') . " {$qty} 股 @ {$price}",
    ]);
}

if ($action === 'api_reset') {
    $portfolio = mt_loadPortfolio($config['portfolio_file']);
    $portfolio[$MANUAL_KEY] = [
        'cash' => $INITIAL_CAPITAL,
        'holdings' => [],
        'trades' => [],
    ];

    if (!mt_savePortfolio($config['portfolio_file'], $portfolio)) {
        mt_jsonResponse(['ok' => false, 'error' => '寫入失敗'], 500);
    }
    @mt_savePortfolio(__DIR__ . '/portfolio.json', $portfolio);

    mt_jsonResponse([
        'ok' => true,
        'message' => sprintf('已歸零：現金重置為 NT$ %s，庫存與交易紀錄已清空',
            number_format($INITIAL_CAPITAL, 0)),
        'cash' => $INITIAL_CAPITAL,
    ]);
}

if ($action === 'api_reset_auto') {
    // 一鍵清空「自動帳戶」：策略1、策略2（不動手動帳戶）
    $portfolio = mt_loadPortfolio($config['portfolio_file']);
    foreach (['策略1', '策略2'] as $key) {
        $portfolio[$key] = [
            'cash' => $INITIAL_CAPITAL,
            'holdings' => [],
            'trades' => [],
        ];
    }

    if (!mt_savePortfolio($config['portfolio_file'], $portfolio)) {
        mt_jsonResponse(['ok' => false, 'error' => '寫入失敗'], 500);
    }
    @mt_savePortfolio(__DIR__ . '/portfolio.json', $portfolio);

    mt_jsonResponse([
        'ok' => true,
        'message' => sprintf('已重置自動帳戶：策略1、策略2 都回到 NT$ %s、庫存與交易紀錄已清空（手動帳戶不影響）',
            number_format($INITIAL_CAPITAL, 0)),
        'reset_accounts' => ['策略1', '策略2'],
    ]);
}

if ($action !== '') {
    mt_jsonResponse(['ok' => false, 'error' => "未知的 action: {$action}"], 400);
}

// ============================================================
// 頁面輸出（無 action 參數時）
// ============================================================
$stocks = mt_loadStockList($config);
$pageTitle = '手動投資操作';
include __DIR__ . '/manual_trade_view.php';
