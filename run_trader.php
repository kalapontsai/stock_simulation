<?php
/**
 * Run Trader Wrapper
 *
 * 觸發 stock_trader.py 的 wrapper。dashboard 的「手動執行交易」按鈕呼叫這支。
 * 不繞過 PHP 直接 fetch .py（Apache 預設不下載原始碼也不執行），
 * 所以 PHP 端用 shell_exec 呼叫 python3 CLI。
 *
 * 模式：
 *   ?mode=run   → python stock_trader.py        (預設，更新股價 + 跑自動策略)
 *   ?mode=update → python stock_trader.py --update (只更新股價，不下單)
 *
 * 回傳純文字（plain text），給前端 alert 用。
 * 不回 JSON，避免前端多一層解析。
 */

declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '0');

// 路徑：用 __DIR__ 確保從 web 訪問跟從 CLI 訪問都對
$PYTHON = '/usr/bin/python3';
$SCRIPT = __DIR__ . '/stock_trader.py';
$MODE = $_GET['mode'] ?? 'run';

// 防呆：確認檔案存在
if (!file_exists($SCRIPT)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "錯誤：找不到 {$SCRIPT}\n";
    exit;
}
if (!is_executable($PYTHON) && !is_file($PYTHON)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "錯誤：python3 不可執行 ({$PYTHON})\n";
    exit;
}

// 組指令（用 escapeshellarg 避免 injection；不過這裡變數都是寫死的）
$cmd = escapeshellcmd($PYTHON) . ' ' . escapeshellarg($SCRIPT);
if ($MODE === 'update') {
    $cmd .= ' --update';
}
// 沒帶 --update 就是預設的「run」（更新 + 交易）

// 給前端用 text/plain，不要 HTML
header('Content-Type: text/plain; charset=utf-8');

// 設定 timeout（trader 跑 11 支股票會很久，給 5 分鐘上限）
set_time_limit(300);

// 收 trader 即時輸出（passthru 不會 buffer，瀏覽器可即時看到進度）
passthru($cmd . ' 2>&1', $exitCode);

echo "\n[exit code: {$exitCode}]\n";
