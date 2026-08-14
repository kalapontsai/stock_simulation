<?php
/**
 * 回測 API
 *
 * 路由：
 *   POST  ?action=run         執行回測（body 為 JSON 參數）
 *   GET   ?action=list        列出歷史回測摘要
 *   GET   ?action=get&id=...  取單次回測完整結果
 *
 * 安全：
 *   - 所有參數經 intval/floatval/白名單
 *   - 暫存檔用 tempnam() 產生，執行後 unlink
 *   - shell exec 僅呼叫 Python 與固定指令
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

$action = $_GET['action'] ?? '';

switch ($action) {
    case 'run':
        handle_run();
        break;
    case 'list':
        handle_list();
        break;
    case 'get':
        handle_get();
        break;
    default:
        http_response_code(400);
        echo json_encode(['error' => 'unknown action']);
}

/**
 * 執行回測
 */
function handle_run(): void
{
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') {
        http_response_code(400);
        echo json_encode(['error' => 'empty body']);
        return;
    }

    $req = json_decode($raw, true);
    if (!is_array($req)) {
        http_response_code(400);
        echo json_encode(['error' => 'invalid JSON']);
        return;
    }

    // 參數驗證與標準化
    $start = $req['start_date'] ?? '';
    $end = $req['end_date'] ?? '';
    $strategy = isset($req['strategy']) ? intval($req['strategy']) : 0;
    $rebalance = isset($req['rebalance_n']) ? intval($req['rebalance_n']) : 1;
    $capital = isset($req['initial_capital']) ? floatval($req['initial_capital']) : 1_000_000;

    // 選股範圍：null / 空字串 → 全部；非空 → 單一代號（僅 1 個）
    $symbol = $req['symbol'] ?? null;
    if ($symbol !== null) {
        $symbol = trim((string)$symbol);
        if ($symbol === '') {
            $symbol = null;
        } elseif (!preg_match('/^[0-9A-Z.]{4,12}$/', $symbol)) {
            http_response_code(400);
            echo json_encode(['error' => 'symbol must be 4-12 chars [0-9A-Z.] (e.g., 2330.TW)']);
            return;
        } else {
            $symbol = strtoupper($symbol);
        }
    }

    // 日期格式檢查
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)) {
        http_response_code(400);
        echo json_encode(['error' => 'invalid date format (YYYY-MM-DD)']);
        return;
    }
    if (strcmp($start, $end) > 0) {
        http_response_code(400);
        echo json_encode(['error' => 'start_date must be <= end_date']);
        return;
    }
    if (!in_array($strategy, [0, 1], true)) {
        http_response_code(400);
        echo json_encode(['error' => 'strategy must be 0 or 1']);
        return;
    }
    if ($rebalance < 1 || $rebalance > 60) {
        http_response_code(400);
        echo json_encode(['error' => 'rebalance_n must be 1..60']);
        return;
    }
    if ($capital < 10_000 || $capital > 100_000_000) {
        http_response_code(400);
        echo json_encode(['error' => 'initial_capital must be 10000..100000000']);
        return;
    }

    // settings（指標參數）— 從前端傳入
    $settings = $req['settings'] ?? null;
    if (!is_array($settings)) {
        $settings = null;
    }

    // 寫入暫存 request 檔
    $payload = json_encode([
        'start_date' => $start,
        'end_date' => $end,
        'strategy' => $strategy,
        'rebalance_n' => $rebalance,
        'initial_capital' => $capital,
        'settings' => $settings,
        'symbol' => $symbol,
    ], JSON_UNESCAPED_UNICODE);

    $tmpfile = tempnam(sys_get_temp_dir(), 'bt_req_');
    if ($tmpfile === false) {
        http_response_code(500);
        echo json_encode(['error' => 'cannot create temp file']);
        return;
    }
    file_put_contents($tmpfile, $payload);

    // 呼叫 Python
    $stockDir = escapeshellarg(__DIR__);
    $tmpArg = escapeshellarg($tmpfile);
    $cmd = "cd $stockDir && python3 backtest.py --request-file $tmpArg --out json 2>&1";

    $output = shell_exec($cmd);
    unlink($tmpfile);

    if ($output === null || $output === '') {
        http_response_code(500);
        echo json_encode(['error' => 'python execution failed', 'detail' => $output]);
        return;
    }

    $result = json_decode($output, true);
    if (!is_array($result)) {
        http_response_code(500);
        echo json_encode(['error' => 'python output not JSON', 'raw' => substr($output, 0, 500)]);
        return;
    }

    // 加上 request echo（前端驗證用）
    $result['request'] = [
        'start_date' => $start,
        'end_date' => $end,
        'strategy' => $strategy,
        'rebalance_n' => $rebalance,
        'initial_capital' => $capital,
        'symbol' => $symbol,
    ];

    echo json_encode($result, JSON_UNESCAPED_UNICODE);
}

/**
 * 歷史回測摘要列表
 */
function handle_list(): void
{
    $file = __DIR__ . '/data/backtest_results.json';
    if (!file_exists($file)) {
        echo json_encode([]);
        return;
    }
    $content = file_get_contents($file);
    $history = json_decode($content, true);
    if (!is_array($history)) {
        echo json_encode([]);
        return;
    }
    // 只回傳摘要
    $summary = array_map(function ($r) {
        return [
            'id' => $r['id'] ?? '',
            'timestamp' => $r['timestamp'] ?? '',
            'strategy' => $r['run_meta']['strategy'] ?? '',
            'start_date' => $r['run_meta']['start_date'] ?? '',
            'end_date' => $r['run_meta']['end_date'] ?? '',
            'rebalance_n' => $r['run_meta']['rebalance_n'] ?? 1,
            'symbol' => $r['run_meta']['symbol'] ?? null,
            'n_stocks' => $r['run_meta']['n_stocks'] ?? null,
            'kpi' => $r['kpi'] ?? [],
        ];
    }, $history);
    echo json_encode($summary, JSON_UNESCAPED_UNICODE);
}

/**
 * 取單次回測完整結果
 */
function handle_get(): void
{
    $id = $_GET['id'] ?? '';
    if ($id === '' || !preg_match('/^bt_\d{8}_\d{6}_[0-9a-f]{6}$/', $id)) {
        http_response_code(400);
        echo json_encode(['error' => 'invalid id']);
        return;
    }
    $file = __DIR__ . '/data/backtest_results.json';
    if (!file_exists($file)) {
        http_response_code(404);
        echo json_encode(['error' => 'no results']);
        return;
    }
    $history = json_decode(file_get_contents($file), true);
    if (!is_array($history)) {
        http_response_code(500);
        echo json_encode(['error' => 'corrupt results']);
        return;
    }
    foreach ($history as $r) {
        if (($r['id'] ?? '') === $id) {
            echo json_encode($r, JSON_UNESCAPED_UNICODE);
            return;
        }
    }
    http_response_code(404);
    echo json_encode(['error' => 'not found']);
}
