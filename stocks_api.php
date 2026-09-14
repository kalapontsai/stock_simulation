<?php
/**
 * 股票清單維護 API
 *
 * GET    /stocks_api.php             — 取得目前清單
 * POST   /stocks_api.php             — 新增單筆  (body: {"symbol": "2330.TW"})
 * DELETE /stocks_api.php?symbol=...  — 刪除單筆（會以現價賣出持股，現金入帳）
 * PUT    /stocks_api.php             — 整批取代 (body: {"stocks": ["0050.TW", ...]})
 *
 * 上限：50 隻
 * 格式：NNNN.TW 或 NNNNNN.TW 或 NNNN.TWO
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$STOCK_LIST_FILE = __DIR__ . '/data/stock_list.json';
$MAX_STOCKS = 50;

// 工具：讀取清單
function read_stock_list($file) {
    if (!file_exists($file)) {
        return ['stocks' => []];
    }
    $data = json_decode(file_get_contents($file), true);
    if (!is_array($data) || !isset($data['stocks'])) {
        return ['stocks' => []];
    }
    return $data;
}

// 工具：寫入清單
function write_stock_list($file, $data) {
    return file_put_contents(
        $file,
        json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        LOCK_EX
    );
}

// 工具：驗證股票代號格式
// 接受帶或不帶 .TW/.TWO 後綴；ETF 末碼字母（00631L / 00981A）也收
// 會容忍前後空白與大小寫（內部以 trim + strtoupper 正規化）
function validate_symbol($symbol) {
    if (!is_string($symbol)) return false;
    $symbol = strtoupper(trim($symbol));
    // [0-9]{4,6}[A-Z]?(\.(TW|TWO))?
    // 例：0050 / 0050.TW / 00631L / 00631L.TWO / 00981A / 00981A.TW
    return preg_match('/^[0-9]{4,6}[A-Z]?(\.(TW|TWO))?$/', $symbol) === 1;
}

// 工具：把帶後綴的 ticker 轉成顯示用的裸號
// 0050.TW -> 0050；00631L.TWO -> 00631L；已 bare 直接回傳
function display_ticker($symbol) {
    if (!is_string($symbol)) return '';
    return preg_replace('/\.(TW|TWO)$/', '', $symbol);
}

// --- 市場別 cache ---
// 檔案路徑：data/.symbol_market_cache.json
// 結構：{ "<bare>": {"market":"TW"|"TWO", "ts": <unix>, "fallback": bool, "probe_error": bool} }
define('SYMBOL_CACHE_FILE', __DIR__ . '/data/.symbol_market_cache.json');
define('SYMBOL_CACHE_TTL_SUCCESS', 7 * 24 * 3600);   // 命中市場別 → 7 天（市場別不會變）
define('SYMBOL_CACHE_TTL_FAILURE', 3600);            // fallback → 1 小時（讓 Yahoo 恢復時能重試）
define('YAHOO_PROBE_TIMEOUT', 3);                    // 單次 HTTP probe 逾時秒數

function _read_symbol_cache($file) {
    if (!file_exists($file)) return [];
    $raw = @file_get_contents($file);
    if ($raw === false) return [];
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function _write_symbol_cache($file, $cache) {
    // 寫入失敗不應影響主流程
    @file_put_contents(
        $file,
        json_encode($cache, JSON_UNESCAPED_UNICODE),
        LOCK_EX
    );
}

// Yahoo probe：查 candidate 是否有效
// - 回傳 "TW" / "TWO" = 候選代號有效
// - 回傳 '' = 候選代號無效（chart.error / 404）
// - 拋 RuntimeException = 網路 / timeout / JSON 失敗（caller 視為 probe 故障）
//
// 用 cURL 而非 file_get_contents：
// - 部署端 Apache 通常 cURL 為主（yfinance 也在用），避免 SSL CA 路徑問題
// - 可讀 4xx body（invalid symbol 走 HTTP 404 + JSON），不用 ignore_errors hack
function yahoo_probe_market($candidate, $timeout = YAHOO_PROBE_TIMEOUT) {
    if (!is_string($candidate)) {
        throw new InvalidArgumentException('candidate 必須是字串');
    }
    if (!preg_match('/\.(TW|TWO)$/', $candidate)) {
        throw new InvalidArgumentException("candidate 必須帶 .TW/.TWO 後綴: $candidate");
    }
    $market = preg_match('/\.TWO$/', $candidate) ? 'TWO' : 'TW';
    $url = 'https://query1.finance.yahoo.com/v8/finance/chart/' . $candidate;

    if (!function_exists('curl_init')) {
        throw new RuntimeException('cURL extension 未啟用，無法 probe Yahoo');
    }

    $ch = curl_init($url);
    if ($ch === false) {
        throw new RuntimeException("curl_init 失敗: $candidate");
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => $timeout,
        CURLOPT_USERAGENT => 'Mozilla/5.0',
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
        // 4xx / 5xx 仍由我們讀 body 判斷，不要自動 fail
        CURLOPT_FAILONERROR => false,
        // 保留完整 TLS 憑證鏈與主機名稱驗證，避免 probe 被偽造。
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);

    $body = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);

    if ($body === false) {
        throw new RuntimeException("Yahoo probe 失敗 ($err): $candidate");
    }

    $data = json_decode($body, true);
    if (!is_array($data)) {
        throw new RuntimeException("Yahoo 回傳非 JSON: $candidate");
    }

    $chart = isset($data['chart']) && is_array($data['chart']) ? $data['chart'] : null;
    if ($chart === null) {
        throw new RuntimeException("Yahoo 回傳結構異常: $candidate");
    }

    // chart.error 存在 → ticker 無效（不論 HTTP 200 / 404）
    if (!empty($chart['error'])) {
        return '';
    }

    // HTTP 404 但沒 chart.error → 也視為無效
    if ($status === 404) {
        return '';
    }

    // HTTP 5xx / 429 → 視為 probe 故障
    if ($status >= 500 || $status === 429) {
        throw new RuntimeException("Yahoo HTTP $status: $candidate");
    }

    $result = isset($chart['result']) ? $chart['result'] : null;
    if (is_array($result) && count($result) > 0) {
        return $market;
    }

    // 其它未知狀況：chart.result 為空但也沒 error → 視為無效
    return '';
}

// 一次性 log：同一 candidate 在同一 process 只 log 一次（避免 spam）
function _log_probe_error($candidate, $msg) {
    static $logged = [];
    if (isset($logged[$candidate])) return;
    $logged[$candidate] = true;
    error_log("[stocks_api] normalize_symbol Yahoo probe 失敗: $candidate: $msg");
}

// bare ticker 判定市場別：
//   - 已附 .TW/.TWO → 保留（大小寫轉大寫）
//   - bare → 依序查 Yahoo .TW → .TWO，命中即停並寫 cache
//   - 兩邊都查無 → fallback .TW（向下相容舊行為）並寫 cache
//   - probe 故障（網路 / timeout / 5xx）→ 中止 fallback、log 一次、回 .TW
//
// $options 可選：
//   - cache_file: 預設 SYMBOL_CACHE_FILE；測試可換暫存檔
//   - probe: callable|null，預設 yahoo_probe_market；測試可注入 fake
function normalize_symbol($symbol, array $options = []) {
    if (!is_string($symbol)) return '';
    $symbol = strtoupper(trim($symbol));
    if ($symbol === '') return '';
    if (preg_match('/\.(TW|TWO)$/', $symbol) === 1) {
        return $symbol;
    }

    // bare → 驗證基本格式
    if (!validate_symbol($symbol)) {
        return '';
    }

    $bare = $symbol;
    $cache_file = isset($options['cache_file']) && is_string($options['cache_file'])
        ? $options['cache_file']
        : SYMBOL_CACHE_FILE;
    $probe = isset($options['probe']) && is_callable($options['probe'])
        ? $options['probe']
        : 'yahoo_probe_market';

    $cache = _read_symbol_cache($cache_file);

    // Cache hit
    if (isset($cache[$bare]) && is_array($cache[$bare])) {
        $entry = $cache[$bare];
        $ts = isset($entry['ts']) ? (int)$entry['ts'] : 0;
        $market = isset($entry['market']) ? $entry['market'] : null;
        $is_fallback = !empty($entry['fallback']);
        $ttl = $is_fallback ? SYMBOL_CACHE_TTL_FAILURE : SYMBOL_CACHE_TTL_SUCCESS;
        if (in_array($market, ['TW', 'TWO'], true) && (time() - $ts) < $ttl) {
            return $bare . '.' . $market;
        }
    }

    // Cache miss → 依序查 Yahoo
    $resolved = null;
    $probe_error = false;
    foreach (['TW', 'TWO'] as $market) {
        $candidate = $bare . '.' . $market;
        try {
            $result = call_user_func($probe, $candidate, YAHOO_PROBE_TIMEOUT);
        } catch (Exception $e) {
            $probe_error = true;
            _log_probe_error($candidate, $e->getMessage());
            break;  // 中止 fallback 避免 spam
        }
        if ($result === $market) {
            $resolved = $market;
            break;
        }
        // $result === '' → 此市場無效，繼續查下一個
    }

    // 寫 cache（無論命中 / fallback / probe 故障都寫，避免重複 probe）
    $cache[$bare] = [
        'market' => $resolved === null ? 'TW' : $resolved,
        'ts' => time(),
        'fallback' => $resolved === null,
        'probe_error' => $probe_error,
    ];
    _write_symbol_cache($cache_file, $cache);

    return $bare . '.' . ($resolved === null ? 'TW' : $resolved);
}

// 允許以 bare ticker 操作既有清單，例如 0050 對應 0050.TW
function resolve_symbol_from_list($symbol, array $stocks) {
    if (!is_string($symbol)) return null;
    $symbol = strtoupper(trim($symbol));
    if ($symbol === '') return null;
    if (in_array($symbol, $stocks, true)) {
        return $symbol;
    }

    $display = display_ticker($symbol);
    foreach ($stocks as $stock) {
        if (display_ticker($stock) === $display) {
            return $stock;
        }
    }

    return null;
}

// 工具：取本地最後收盤價（從 stock_data.json）
function get_last_close_price($symbol) {
    $dataFile = __DIR__ . '/stock_data.json';
    if (!file_exists($dataFile)) return null;
    $data = json_decode(file_get_contents($dataFile), true);
    if (!isset($data[$symbol]) || empty($data[$symbol])) return null;
    $entries = $data[$symbol];
    // 找最後一個有效的 close
    for ($i = count($entries) - 1; $i >= 0; $i--) {
        if (isset($entries[$i]['close']) && $entries[$i]['close'] !== null) {
            return floatval($entries[$i]['close']);
        }
    }
    return null;
}

// 工具：以現價賣出所有策略中此股票的持股
function liquidate_symbol($symbol, $reason_note = '') {
    $portfolioFile = __DIR__ . '/portfolio.json';
    $analysisFile = __DIR__ . '/daily_analysis.json';
    $profitFile = __DIR__ . '/profit_history.json';

    $result = ['liquidated' => [], 'total_cash_added' => 0, 'error' => null];

    $price = get_last_close_price($symbol);
    if ($price === null) {
        $result['error'] = "找不到 $symbol 的本地最後收盤價（請先執行 ?update=1 抓股價）";
        return $result;
    }

    if (!file_exists($portfolioFile)) return $result;

    $portfolio = json_decode(file_get_contents($portfolioFile), true);
    if (!is_array($portfolio)) return $result;

    $strategies = ['策略1', '策略2'];
    $today = date('Y-m-d');
    $now = date('Y-m-d H:i:s');

    foreach ($strategies as $strategy) {
        if (!isset($portfolio[$strategy])) continue;
        $strat = &$portfolio[$strategy];
        $qty = $strat['holdings'][$symbol] ?? 0;
        if ($qty <= 0) continue;

        $proceeds = $qty * $price;
        $strat['cash'] = ($strat['cash'] ?? 0) + $proceeds;
        unset($strat['holdings'][$symbol]);

        // 記一筆 SELL 交易
        $strat['trades'][] = [
            'date' => $today,
            'timestamp' => $now,
            'symbol' => $symbol,
            'action' => 'SELL',
            'quantity' => $qty,
            'price' => $price,
            'reason' => $reason_note ?: '股票清單移除',
        ];

        $result['liquidated'][] = [
            'strategy' => $strategy,
            'symbol' => $symbol,
            'quantity' => $qty,
            'price' => $price,
            'cash_added' => round($proceeds, 2),
        ];
        $result['total_cash_added'] += $proceeds;
    }
    unset($strat);

    file_put_contents($portfolioFile, json_encode($portfolio, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

    // daily_analysis.json 把該股的 strategies 標記為 SELL_ALL
    if (file_exists($analysisFile)) {
        $analysis = json_decode(file_get_contents($analysisFile), true);
        if (is_array($analysis) && isset($analysis[$symbol])) {
            foreach ($strategies as $strategy) {
                if (isset($analysis[$symbol]['strategies'][$strategy])) {
                    $analysis[$symbol]['strategies'][$strategy] = [
                        'action' => 'SELL_ALL',
                        'quantity' => $analysis[$symbol]['strategies'][$strategy]['quantity'] ?? 0,
                        'price' => $price,
                        'reason' => $reason_note ?: '股票清單移除（自動結算）',
                    ];
                }
            }
            file_put_contents($analysisFile, json_encode($analysis, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        }
    }

    return $result;
}

$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET': {
        $list = read_stock_list($STOCK_LIST_FILE);
        $displays = array_map('display_ticker', $list['stocks']);
        echo json_encode([
            'success' => true,
            'stocks' => $list['stocks'],
            'displays' => $displays,
            'count' => count($list['stocks']),
            'max' => $MAX_STOCKS,
        ], JSON_UNESCAPED_UNICODE);
        break;
    }

    case 'POST': {
        // 新增單筆
        $input = json_decode(file_get_contents('php://input'), true);
        $symbol = $input['symbol'] ?? $_POST['symbol'] ?? null;

        if (!validate_symbol($symbol)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => '代號格式錯誤（可輸入 0050、0050.TW、00631L、00631L.TWO）']);
            exit;
        }

        $symbol = normalize_symbol($symbol);
        $list = read_stock_list($STOCK_LIST_FILE);
        if (resolve_symbol_from_list($symbol, $list['stocks']) !== null) {
            http_response_code(409);
            echo json_encode(['success' => false, 'error' => display_ticker($symbol) . ' 已在清單中']);
            exit;
        }
        if (count($list['stocks']) >= $MAX_STOCKS) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => "已達上限 $MAX_STOCKS 隻"]);
            exit;
        }

        $list['stocks'][] = $symbol;
        write_stock_list($STOCK_LIST_FILE, $list);

        echo json_encode([
            'success' => true,
            'stocks' => $list['stocks'],
            'count' => count($list['stocks']),
            'added' => $symbol,
        ], JSON_UNESCAPED_UNICODE);
        break;
    }

    case 'DELETE': {
        // 刪除單筆（會以現價賣出）
        $symbol = $_GET['symbol'] ?? null;
        if (!validate_symbol($symbol)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => '代號格式錯誤或未提供']);
            exit;
        }

        $list = read_stock_list($STOCK_LIST_FILE);
        $symbol = resolve_symbol_from_list($symbol, $list['stocks']);
        if ($symbol === null) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => '股票不在清單中']);
            exit;
        }

        // 賣出結算
        $liq = liquidate_symbol($symbol, "從清單移除 $symbol");

        // 從清單移除
        $list['stocks'] = array_values(array_filter($list['stocks'], fn($s) => $s !== $symbol));
        write_stock_list($STOCK_LIST_FILE, $list);

        echo json_encode([
            'success' => true,
            'removed' => $symbol,
            'stocks' => $list['stocks'],
            'count' => count($list['stocks']),
            'liquidation' => $liq,
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        break;
    }

    case 'PUT': {
        // 整批取代（被刪除的會以現價賣出）
        $input = json_decode(file_get_contents('php://input'), true);
        $newStocks = $input['stocks'] ?? null;

        if (!is_array($newStocks)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => '需提供 stocks 陣列']);
            exit;
        }

        foreach ($newStocks as $s) {
            if (!validate_symbol($s)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => "代號格式錯誤: $s"]);
                exit;
            }
        }

        $newStocks = array_map('normalize_symbol', $newStocks);

        if (count($newStocks) === 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => '清單不可為空']);
            exit;
        }
        if (count($newStocks) > $MAX_STOCKS) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => "超過上限 $MAX_STOCKS"]);
            exit;
        }
        if (count($newStocks) !== count(array_unique($newStocks))) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => '清單內有重複']);
            exit;
        }

        $oldList = read_stock_list($STOCK_LIST_FILE);
        $removed = array_values(array_diff($oldList['stocks'], $newStocks));
        $added = array_values(array_diff($newStocks, $oldList['stocks']));

        // 對被移除的逐筆結算
        $liquidations = [];
        foreach ($removed as $sym) {
            $liq = liquidate_symbol($sym, "整批取代移除 $sym");
            $liquidations[$sym] = $liq;
        }

        write_stock_list($STOCK_LIST_FILE, ['stocks' => array_values($newStocks)]);

        echo json_encode([
            'success' => true,
            'stocks' => array_values($newStocks),
            'count' => count($newStocks),
            'removed' => $removed,
            'added' => $added,
            'liquidations' => $liquidations,
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        break;
    }

    default: {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => "不支援的方法: $method"]);
        break;
    }
}
