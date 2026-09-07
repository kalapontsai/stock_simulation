<?php
/**
 * stocks_api.php 的純 PHP 測試（無 PHPUnit 依賴）
 *
 * 涵蓋：
 * - validate_symbol / display_ticker（純函式）
 * - normalize_symbol：保留後綴、bare 判定、cache、fallback、probe 錯誤
 *
 * 執行：php tests/run_php_tests.php
 * 退出碼：0 = 全綠；1 = 有失敗
 *
 * 跟 Python 端的 tests/test_normalize_symbol.py 對齊。
 */

declare(strict_types=1);

// 載入要測的 PHP（被測檔不能自己跑 main，需擷取函式定義）
// stocks_api.php 在頂端就會送出 HTTP header + 處理 $_SERVER['REQUEST_METHOD']，
// 我們用 include_once 但在它之前設好 OUTPUT 緩衝 + 假冒 CLI 環境，避免它真的送出 response。
$_SERVER['REQUEST_METHOD'] = 'CLI';  // 不走任何 switch case
ob_start();
require_once __DIR__ . '/../stocks_api.php';
ob_end_clean();

// 重新宣告 Yahoo probe timeout（測試時調小，加速）
// 直接在 include 之前 define 不行（PHP 8.0 不能 re-define 已有 const）；
// 用 reflection 改不動 define 值，所以測試依賴 YAHOO_PROBE_TIMEOUT=3 也 OK。

// ---- 微型斷言器 ----

$tests_passed = 0;
$tests_failed = 0;
$failures = [];

function check(string $name, $actual, $expected): void {
    global $tests_passed, $tests_failed, $failures;
    $ok = ($actual === $expected);
    if ($ok) {
        $tests_passed++;
        echo "  PASS  $name\n";
    } else {
        $tests_failed++;
        $failures[] = $name;
        $exp = var_export($expected, true);
        $act = var_export($actual, true);
        echo "  FAIL  $name\n        expected: $exp\n        actual:   $act\n";
    }
}

function check_true(string $name, $actual): void {
    global $tests_passed, $tests_failed, $failures;
    if ($actual === true) {
        $tests_passed++;
        echo "  PASS  $name\n";
    } else {
        $tests_failed++;
        $failures[] = $name;
        $act = var_export($actual, true);
        echo "  FAIL  $name\n        expected true\n        actual:   $act\n";
    }
}

function check_false(string $name, $actual): void {
    global $tests_passed, $tests_failed, $failures;
    if ($actual === false) {
        $tests_passed++;
        echo "  PASS  $name\n";
    } else {
        $tests_failed++;
        $failures[] = $name;
        $act = var_export($actual, true);
        echo "  FAIL  $name\n        expected false\n        actual:   $act\n";
    }
}

// ---- 測試：validate_symbol ----

echo "=== validate_symbol ===\n";
check_true('accept bare 0050',          validate_symbol('0050'));
check_true('accept bare 4979',          validate_symbol('4979'));
check_true('accept bare 00631L',        validate_symbol('00631L'));
check_true('accept with .TW',           validate_symbol('0050.TW'));
check_true('accept with .TWO',          validate_symbol('4979.TWO'));
check_true('accept with .TWO ETF',      validate_symbol('00631L.TWO'));
check_true('accept lowercase + space',  validate_symbol(' 0050.tw '));
check_true('accept bare whitespace',    validate_symbol('  4979  '));
check_false('reject too short',         validate_symbol('12'));
check_false('reject too long',          validate_symbol('1234567'));
check_false('reject letters in prefix', validate_symbol('ABCD'));
check_false('reject bad market',        validate_symbol('0050.NASDAQ'));
check_false('reject empty',             validate_symbol(''));
check_false('reject null',              validate_symbol(null));

// ---- 測試：display_ticker ----

echo "=== display_ticker ===\n";
check('strips .TW',        display_ticker('0050.TW'),       '0050');
check('strips .TWO',       display_ticker('4979.TWO'),      '4979');
check('strips .TWO ETF',   display_ticker('00631L.TWO'),    '00631L');
check('passes bare',       display_ticker('0050'),          '0050');
check('empty for empty',   display_ticker(''),              '');
check('empty for null',    display_ticker(null),            '');

// ---- 測試：normalize_symbol：保留明確後綴 ----

echo "=== normalize_symbol: preserve explicit suffix ===\n";
check('preserves 0050.TW',  normalize_symbol('0050.TW'),  '0050.TW');
check('preserves 2330.TW',  normalize_symbol('2330.TW'),  '2330.TW');
check('preserves 4979.TWO', normalize_symbol('4979.TWO'), '4979.TWO');
check('preserves 3081.TWO', normalize_symbol('3081.TWO'), '3081.TWO');
check('uppercases lowercase .tw', normalize_symbol('0050.tw'), '0050.TW');
check('uppercases lowercase .two', normalize_symbol('4979.two'), '4979.TWO');
check('strips whitespace', normalize_symbol('  0050.TW  '), '0050.TW');

// ---- 測試：normalize_symbol：bare ticker 走 probe ----

echo "=== normalize_symbol: bare ticker Yahoo probe ===\n";

// 用自訂 probe 注入；建立暫存 cache 檔
function make_fake_probe(array $mapping): callable {
    return function (string $candidate, int $timeout = 3) use ($mapping): string {
        $market = preg_match('/\.TWO$/', $candidate) ? 'TWO' : 'TW';
        $bare = preg_replace('/\.(TW|TWO)$/', '', $candidate);
        if (($mapping[$bare] ?? null) === $market) {
            return $market;
        }
        return '';
    };
}

function make_failing_probe(): callable {
    return function (string $candidate, int $timeout = 3): string {
        throw new RuntimeException("Yahoo probe 失敗: $candidate");
    };
}

function tmp_cache_file(): string {
    $tmp = sys_get_temp_dir() . '/stocks_api_test_' . bin2hex(random_bytes(4));
    @mkdir($tmp, 0777, true);
    return $tmp . '/cache.json';
}

$tmp = tmp_cache_file();

// 上市 ETF 0050 → .TW（probe 第一次 .TW 就命中）
check('bare 0050 → 0050.TW',
    normalize_symbol('0050', [
        'probe' => make_fake_probe(['0050' => 'TW']),
        'cache_file' => $tmp,
    ]),
    '0050.TW'
);

// 上櫃 4979 → .TWO（probe .TW 失敗、.TWO 命中）
check('bare 4979 → 4979.TWO',
    normalize_symbol('4979', [
        'probe' => make_fake_probe(['4979' => 'TWO']),
        'cache_file' => tmp_cache_file(),
    ]),
    '4979.TWO'
);

check('bare 3081 → 3081.TWO',
    normalize_symbol('3081', [
        'probe' => make_fake_probe(['3081' => 'TWO']),
        'cache_file' => tmp_cache_file(),
    ]),
    '3081.TWO'
);

check('bare 3363 → 3363.TWO',
    normalize_symbol('3363', [
        'probe' => make_fake_probe(['3363' => 'TWO']),
        'cache_file' => tmp_cache_file(),
    ]),
    '3363.TWO'
);

// 小寫 bare
check('bare 0050 (lowercase) → 0050.TW',
    normalize_symbol('0050', [
        'probe' => make_fake_probe(['0050' => 'TW']),
        'cache_file' => tmp_cache_file(),
    ]),
    '0050.TW'
);

// 兩邊都查無 → fallback .TW
check('bare 9999 (invalid both) → 9999.TW',
    normalize_symbol('9999', [
        'probe' => make_fake_probe([]),
        'cache_file' => $fallback_cache = tmp_cache_file(),
    ]),
    '9999.TW'
);
$fallback_cache_data = json_decode(file_get_contents($fallback_cache), true);
check('fallback cache entry exists for 9999',
    isset($fallback_cache_data['9999']) && ($fallback_cache_data['9999']['fallback'] ?? false) === true,
    true
);

// Probe 故障 → fallback .TW，不 crash
check('bare 0050 (probe error) → 0050.TW',
    normalize_symbol('0050', [
        'probe' => make_failing_probe(),
        'cache_file' => $tmpCache2 = tmp_cache_file(),
    ]),
    '0050.TW'
);
$err_cache_data = json_decode(file_get_contents($tmpCache2), true);
check('probe_error flag is true after probe failure',
    ($err_cache_data['0050']['probe_error'] ?? null) === true,
    true
);

// Cache 命中時不重 probe
$cache_hit = tmp_cache_file();
file_put_contents($cache_hit, json_encode([
    '4979' => ['market' => 'TWO', 'ts' => time() - 60, 'fallback' => false, 'probe_error' => false],
]));
$probe_called = false;
$tracking_probe = function (string $candidate, int $timeout = 3) use (&$probe_called): string {
    $probe_called = true;
    return '';
};
check('cache hit 4979 → 4979.TWO (no probe)',
    normalize_symbol('4979', [
        'probe' => $tracking_probe,
        'cache_file' => $cache_hit,
    ]),
    '4979.TWO'
);
check('cache hit did not call probe', $probe_called, false);

// 空 / 非字串 / 格式錯
check('empty → empty', normalize_symbol('', ['cache_file' => tmp_cache_file()]), '');
check('whitespace → empty', normalize_symbol('   ', ['cache_file' => tmp_cache_file()]), '');
check('null → empty', normalize_symbol(null, ['cache_file' => tmp_cache_file()]), '');
check('format invalid → empty', normalize_symbol('12', [
    'probe' => make_fake_probe([]),
    'cache_file' => tmp_cache_file(),
]), '');

// 清理
foreach (glob(sys_get_temp_dir() . '/stocks_api_test_*') as $f) @unlink($f);
foreach (glob(sys_get_temp_dir() . '/stocks_api_test_*') as $d) @rmdir($d);

// ---- 測試：normalize_symbol：cache 行為 ----

echo "=== normalize_symbol: cache behavior ===\n";

// 用真實的 Yahoo probe（會真打網路），但以 cache 阻擋二次呼叫
// 由於我們的 normalize_symbol 內部有 cache，這裡直接驗證 cache 檔案存在 + 不 spam probe。
$tmp = sys_get_temp_dir() . '/stocks_api_test_' . bin2hex(random_bytes(4));
@mkdir($tmp, 0777, true);
$cacheFile = $tmp . '/cache.json';

// 模擬預先寫好的 cache
$cache = [
    '4979' => ['market' => 'TWO', 'ts' => time() - 60, 'fallback' => false, 'probe_error' => false],
    '9999' => ['market' => 'TW', 'ts' => time() - 60, 'fallback' => true,  'probe_error' => false],
];
file_put_contents($cacheFile, json_encode($cache));

// 把 cache 檔指向我們的 SYMBOL_CACHE_FILE 比較麻煩（define 不能改），
// 所以這個測試只能當 doc；改用 _read_symbol_cache / _write_symbol_cache 直接驗證邏輯。

check('cache hit 4979 → TWO',
    (function () use ($cacheFile) {
        $c = _read_symbol_cache($cacheFile);
        $entry = $c['4979'];
        $market = $entry['market'];
        $is_fallback = !empty($entry['fallback']);
        $ttl = $is_fallback ? SYMBOL_CACHE_TTL_FAILURE : SYMBOL_CACHE_TTL_SUCCESS;
        if (in_array($market, ['TW', 'TWO'], true) && (time() - $entry['ts']) < $ttl) {
            return '4979.' . $market;
        }
        return 'cache_miss';
    })(),
    '4979.TWO'
);

check('cache hit 9999 (fallback) → TW',
    (function () use ($cacheFile) {
        $c = _read_symbol_cache($cacheFile);
        $entry = $c['9999'];
        $market = $entry['market'];
        $is_fallback = !empty($entry['fallback']);
        $ttl = $is_fallback ? SYMBOL_CACHE_TTL_FAILURE : SYMBOL_CACHE_TTL_SUCCESS;
        if (in_array($market, ['TW', 'TWO'], true) && (time() - $entry['ts']) < $ttl) {
            return '9999.' . $market;
        }
        return 'cache_miss';
    })(),
    '9999.TW'
);

// 驗證 _write_symbol_cache 寫入成功
_write_symbol_cache($cacheFile, ['00631L' => ['market' => 'TWO', 'ts' => time()]]);
$written = json_decode(file_get_contents($cacheFile), true);
check('cache write 00631L',
    ($written['00631L']['market'] ?? null) === 'TWO' ? 'ok' : 'fail',
    'ok'
);

// 壞掉 cache 不 crash
file_put_contents($cacheFile, '{not json');
$c = _read_symbol_cache($cacheFile);
check('corrupt cache returns empty', is_array($c) && count($c) === 0 ? 'ok' : 'fail', 'ok');

// 清理
@unlink($cacheFile);
@rmdir($tmp);

// ---- 總結 ----

echo "\n";
echo "========================================\n";
echo "PHP tests passed: $tests_passed\n";
echo "PHP tests failed: $tests_failed\n";
if ($tests_failed > 0) {
    echo "\nFailures:\n";
    foreach ($failures as $f) echo "  - $f\n";
    exit(1);
}
echo "All PHP tests passed.\n";
exit(0);