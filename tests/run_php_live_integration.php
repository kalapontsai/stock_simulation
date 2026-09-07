<?php
/**
 * PHP 端 Yahoo probe 整合測試（會真打網路）。
 *
 * 跳過條件：連得到 query1.finance.yahoo.com:443 才執行，否則 skip。
 * 退出碼：0 = 全綠；非 0 = 有失敗（網路失敗視為 skip）。
 *
 * 執行：php tests/run_php_live_integration.php
 */

declare(strict_types=1);

$_SERVER['REQUEST_METHOD'] = 'CLI';
ob_start();
require_once __DIR__ . '/../stocks_api.php';
ob_end_clean();

// 網路連線檢查
$fp = @fsockopen('query1.finance.yahoo.com', 443, $errno, $errstr, 2.0);
if ($fp === false) {
    echo "[SKIP] 網路不可達或 sandbox 阻擋：$errstr\n";
    exit(0);
}
fclose($fp);

$tests_passed = 0;
$tests_failed = 0;

function check_live(string $name, $actual, $expected): void {
    global $tests_passed, $tests_failed;
    if ($actual === $expected) {
        $tests_passed++;
        echo "  PASS  $name\n";
    } else {
        $tests_failed++;
        echo "  FAIL  $name\n";
        echo "        expected: " . var_export($expected, true) . "\n";
        echo "        actual:   " . var_export($actual, true) . "\n";
    }
}

$tmp = sys_get_temp_dir() . '/stocks_api_live_' . bin2hex(random_bytes(4)) . '.json';
@mkdir(dirname($tmp), 0777, true);

echo "=== yahoo_probe_market: real Yahoo ===\n";
check_live('0050.TW → TW',  yahoo_probe_market('0050.TW'),  'TW');
check_live('2330.TW → TW',  yahoo_probe_market('2330.TW'),  'TW');

check_live('4979.TW → empty (invalid)', yahoo_probe_market('4979.TW'),  '');
check_live('4979.TWO → TWO',            yahoo_probe_market('4979.TWO'), 'TWO');

check_live('3081.TW → empty',  yahoo_probe_market('3081.TW'),  '');
check_live('3081.TWO → TWO',   yahoo_probe_market('3081.TWO'), 'TWO');

check_live('3363.TW → empty',  yahoo_probe_market('3363.TW'),  '');
check_live('3363.TWO → TWO',   yahoo_probe_market('3363.TWO'), 'TWO');

check_live('9999.TW → empty',  yahoo_probe_market('9999.TW'),  '');
check_live('9999.TWO → empty', yahoo_probe_market('9999.TWO'), '');

echo "\n=== normalize_symbol: bare via real Yahoo ===\n";
check_live('bare 0050 → 0050.TW',  normalize_symbol('0050',  ['cache_file' => $tmp]), '0050.TW');
check_live('bare 4979 → 4979.TWO', normalize_symbol('4979',  ['cache_file' => $tmp]), '4979.TWO');
check_live('bare 3081 → 3081.TWO', normalize_symbol('3081',  ['cache_file' => $tmp]), '3081.TWO');
check_live('bare 3363 → 3363.TWO', normalize_symbol('3363',  ['cache_file' => $tmp]), '3363.TWO');

// 第二次呼叫應該走 cache，不再打 Yahoo（time() - ts < TTL）
$start = microtime(true);
$r = normalize_symbol('0050', ['cache_file' => $tmp]);
$dur1 = microtime(true) - $start;
$start = microtime(true);
$r = normalize_symbol('0050', ['cache_file' => $tmp]);
$dur2 = microtime(true) - $start;
echo "  [info] cache 二次呼叫耗時: " . sprintf("%.4fs vs %.4fs\n", $dur1, $dur2);

@unlink($tmp);

echo "\n========================================\n";
echo "Live tests passed: $tests_passed\n";
echo "Live tests failed: $tests_failed\n";
exit($tests_failed > 0 ? 1 : 0);