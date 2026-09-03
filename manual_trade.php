<?php
/**
 * 手動投資操作頁 + Agent API
 *
 * 功能：
 *   - 顯示「手動操作」帳戶的現金、庫存、即時股價、損益
 *   - 選股票、買/賣、輸入股數 → 下單（同步扣稅費）
 *   - 一鍵歸零按鈕（清空庫存、現金回到 500 萬、清空交易紀錄）
 *   - Agent API（POST + JSON body 或 GET）：給 agent_stock.py 呼叫用
 *
 * API 路由（GET 與 POST 都接受；POST 支援 JSON body）：
 *   ?action=api_get                              → JSON: 帳戶 + 股票即時價 + 庫存市值
 *   ?action=api_buy&stock=2330.TW&qty=1000       → JSON: 下單結果
 *   ?action=api_buy&stock=2330.TW&amount=50000   → JSON: 下單結果（按金額自動算股數）
 *   ?action=api_sell&stock=2330.TW&qty=500       → JSON: 下單結果
 *   ?action=api_sell&stock=2330.TW&amount=30000  → JSON: 下單結果（按金額自動算股數）
 *   ?action=api_stock_info&stock=2330.TW         → JSON: 單股現在價、成本、損益、可買股數
 *   ?action=api_list_stocks                      → JSON: 可下單股票清單 + 現價
 *   ?action=api_reset                            → JSON: 歸零「手動操作」帳戶
 *   ?action=api_reset_auto                       → JSON: 歸零「策略1」「策略2」
 *
 * Agent 下單範例（POST + JSON）：
 *   curl -X POST http://10.35.32.11/stock/manual_trade.php \
 *        -H "Content-Type: application/json" \
 *        -d '{"action":"api_buy","stock":"2330.TW","amount":50000}'
 *
 * 帳戶 KEY = '手動操作'（與 stock_trader.py STRATEGIES 一致）
 * 初始資金 = 5,000,000
 */

declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '0');

// ============================================================
// 設定
// ============================================================
$MANUAL_KEY = '手動操作';
$INITIAL_CAPITAL = 5_000_000;

// 手動操作的最小交易限制（沿用 stock_trader.py 的常數）
$MIN_TRADE_AMOUNT = 10_000;
$MIN_TRADE_SHARES = 100;

$config = [
    'stock_list_file'  => __DIR__ . '/data/stock_list.json',
    'portfolio_file'   => __DIR__ . '/data/portfolio.json',
    'data_file'        => __DIR__ . '/stock_data.json',
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

/**
 * 解析 POST 進來的 JSON body。
 * 回傳 ['ok' => bool, 'data' => array]
 *   - 非 POST：回 ['ok' => true, 'data' => null]
 *   - POST 但 body 為空：回 ['ok' => true, 'data' => []]
 *   - POST 但 JSON 解析失敗：回 ['ok' => false, 'data' => null]
 */
function mt_readJsonInput(): array {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        return ['ok' => true, 'data' => null];
    }
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') {
        return ['ok' => true, 'data' => []];
    }
    $data = json_decode($raw, true);
    if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
        return ['ok' => false, 'data' => null];
    }
    if (!is_array($data)) {
        return ['ok' => false, 'data' => null];
    }
    return ['ok' => true, 'data' => $data];
}

/**
 * 統一讀取輸入參數：JSON body > $_GET > $_POST
 *
 * 用法：$stock = mt_input($json, 'stock', '');
 */
function mt_input(?array $json, string $key, $default = null) {
    if ($json !== null && array_key_exists($key, $json)) return $json[$key];
    if (isset($_GET[$key])) return $_GET[$key];
    if (isset($_POST[$key])) return $_POST[$key];
    return $default;
}

/**
 * 計算該股的平均成本（用 BUY 交易加權平均）
 */
function mt_calcCostBasis(array $trades, string $stock): array {
    $totalCost = 0.0;
    $totalQty = 0;
    foreach ($trades as $t) {
        if (($t['stock'] ?? null) !== $stock || ($t['action'] ?? null) !== 'BUY') continue;
        $totalCost += (float)($t['total_cost'] ?? 0);
        $totalQty += (int)($t['quantity'] ?? 0);
    }
    $basis = $totalQty > 0 ? $totalCost / $totalQty : null;
    return ['cost_basis' => $basis, 'total_cost' => $totalCost, 'total_qty' => $totalQty];
}

/**
 * 計算「以現在價格可買的最大股數」（含稅費）
 *
 * 用 max cash 算：cost_per_share(含稅費) = price * (1 + tax_rate + fee_rate)
 *                  qty = floor(cash / cost_per_share)
 */
function mt_calcCanBuy(float $cash, float $price, array $tax_cfg, array $fee_cfg, int $minShares): int {
    if ($price <= 0 || $cash <= 0) return 0;
    $taxRate = (float)($tax_cfg['buy'] ?? 0) / 100;
    $feeRate = (float)($fee_cfg['rate'] ?? 0) * (float)($fee_cfg['discount'] ?? 100) / 100 / 100;
    $minFee = (float)($fee_cfg['min'] ?? 0);
    // 保守估：fee = max(min, total * feeRate) → 用 feeRate 估（大額時 min fee 不影響）
    $costPerShare = $price * (1 + $taxRate + $feeRate);
    return max(0, (int)floor($cash / $costPerShare));
}

// ============================================================
// 解析 POST JSON body（一次）
// ============================================================
$jsonRead = mt_readJsonInput();
if (!$jsonRead['ok']) {
    mt_jsonResponse(['ok' => false, 'error' => 'JSON body 解析失敗（需為合法 JSON object）'], 400);
}
$jsonInput = $jsonRead['data'];

// ============================================================
// API 路由
// ============================================================
$action = mt_input($jsonInput, 'action', '');

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
            $cost = mt_calcCostBasis($acct['trades'] ?? [], $stock);
            $costBasis = $cost['cost_basis'];
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

if ($action === 'api_list_stocks') {
    $stocks = mt_loadStockList($config);
    $prices = mt_getLatestPrices($stocks, $config['data_file']);
    $list = [];
    foreach ($stocks as $s) {
        $list[] = [
            'code' => $s,
            'price' => $prices[$s] ?? null,
        ];
    }
    mt_jsonResponse([
        'ok' => true,
        'stocks' => $list,
    ]);
}

if ($action === 'api_stock_info') {
    $stock = trim((string)mt_input($jsonInput, 'stock', ''));
    if ($stock === '') {
        mt_jsonResponse(['ok' => false, 'error' => '股票代號必填'], 400);
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

    $portfolio = mt_loadPortfolio($config['portfolio_file']);
    mt_ensureAccount($portfolio, $MANUAL_KEY, $INITIAL_CAPITAL);
    $acct = $portfolio[$MANUAL_KEY];

    $holding = (int)($acct['holdings'][$stock] ?? 0);
    $cost = mt_calcCostBasis($acct['trades'] ?? [], $stock);
    $costBasis = $cost['cost_basis'];

    $marketValue = $holding > 0 ? $holding * $price : null;
    $unrealized = ($holding > 0 && $costBasis !== null)
        ? round(($price - $costBasis) * $holding, 2)
        : null;
    $unrealizedRate = ($holding > 0 && $costBasis !== null && $costBasis > 0)
        ? round((($price - $costBasis) / $costBasis) * 100, 2)
        : null;

    $taxFee = mt_loadTaxFee($config, $defaultTaxFee);
    $canBuy = mt_calcCanBuy((float)$acct['cash'], $price, $taxFee['tax'], $taxFee['fee'], $MIN_TRADE_SHARES);
    $canBuyAmount = $canBuy * $price;

    mt_jsonResponse([
        'ok' => true,
        'stock' => $stock,
        'price' => $price,
        'holding' => $holding,
        'cost_basis' => $costBasis,
        'total_cost' => $costBasis !== null ? round($cost['total_cost'], 2) : null,
        'market_value' => $marketValue,
        'unrealized_pl' => $unrealized,
        'unrealized_rate' => $unrealizedRate,
        'cash' => (float)$acct['cash'],
        'can_buy_qty' => $canBuy,
        'can_buy_amount' => round($canBuyAmount, 2),
    ]);
}

if ($action === 'api_buy' || $action === 'api_sell') {
    $stock = trim((string)mt_input($jsonInput, 'stock', ''));
    $qty = (int)mt_input($jsonInput, 'qty', 0);
    $amount = (float)mt_input($jsonInput, 'amount', 0);

    if ($stock === '') {
        mt_jsonResponse(['ok' => false, 'error' => '股票代號必填'], 400);
    }
    if ($qty <= 0 && $amount <= 0) {
        mt_jsonResponse(['ok' => false, 'error' => 'qty 與 amount 至少需填一個（且 > 0）'], 400);
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

    $portfolio = mt_loadPortfolio($config['portfolio_file']);
    mt_ensureAccount($portfolio, $MANUAL_KEY, $INITIAL_CAPITAL);
    $acct = &$portfolio[$MANUAL_KEY];

    if ($action === 'api_buy') {
        // 算實際下單股數（amount 優先）
        if ($amount > 0) {
            $targetAmount = max($amount, $MIN_TRADE_AMOUNT, $MIN_TRADE_SHARES * $price);
            $actualQty = (int)floor($targetAmount / $price);
        } else {
            $targetAmount = max($qty * $price, $MIN_TRADE_AMOUNT, $MIN_TRADE_SHARES * $price);
            $actualQty = (int)floor($targetAmount / $price);
        }
        if ($actualQty <= 0) {
            mt_jsonResponse(['ok' => false, 'error' => '股數無效（金額太小）'], 400);
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
        $resultQty = $actualQty;
    } else { // api_sell
        $current = (int)($acct['holdings'][$stock] ?? 0);
        if ($current <= 0) {
            mt_jsonResponse([
                'ok' => false,
                'error' => "未持有 {$stock}（持倉 0 股）",
                'holding' => $current,
            ], 400);
        }

        if ($amount > 0) {
            // 賣出金額 → 算股數（不能超過持倉）
            $sellQty = (int)floor($amount / $price);
            $actualQty = min($sellQty, $current);
            if ($actualQty <= 0) {
                mt_jsonResponse(['ok' => false, 'error' => '賣出金額太小，湊不到 1 股'], 400);
            }
        } else {
            $actualQty = $qty;
            if ($current < $actualQty) {
                mt_jsonResponse([
                    'ok' => false,
                    'error' => "庫存不足（持有 {$current} 股，欲賣 {$actualQty} 股）",
                    'holding' => $current,
                ], 400);
            }
        }

        $total = $price * $actualQty;
        $taxFee = mt_loadTaxFee($config, $defaultTaxFee);
        $cost = mt_calcTradeCost('SELL', $total, $taxFee['tax'], $taxFee['fee']);
        $netIncome = $total - $cost['tax'] - $cost['fee'];

        $acct['cash'] += $netIncome;
        $acct['holdings'][$stock] -= $actualQty;
        if ($acct['holdings'][$stock] <= 0) {
            unset($acct['holdings'][$stock]);
        }
        $acct['trades'][] = [
            'date'       => date('Y-m-d H:i:s'),
            'stock'      => $stock,
            'action'     => 'SELL',
            'price'      => $price,
            'quantity'   => $actualQty,
            'total'      => round($total, 2),
            'tax'        => $cost['tax'],
            'fee'        => $cost['fee'],
            'net_income' => round($netIncome, 2),
            'source'     => 'manual',
        ];
        $resultQty = $actualQty;
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
        'quantity' => $resultQty,
        'cash' => round($acct['cash'], 2),
        'message' => "{$stock} " . ($action === 'api_buy' ? '買進' : '賣出') . " {$resultQty} 股 @ {$price}",
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
