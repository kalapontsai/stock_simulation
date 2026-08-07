<?php
// 技術指標參數 API
header('Content-Type: application/json');

$file = __DIR__ . '/data/indicator_settings.json';
$default = [
    'ma' => [
        'short' => 5,
        'long' => 20,
        'long60' => 60
    ],
    'rsi' => [
        'period' => 14
    ],
    'kd' => [
        'period' => 9,
        'k_smooth' => 3,
        'd_smooth' => 3
    ],
    'macd' => [
        'fast' => 12,
        'slow' => 26,
        'signal' => 9
    ],
    'bollinger' => [
        'period' => 20,
        'std_dev' => 2
    ],
    'thresholds' => [
        'rsi_oversold' => 30,
        'rsi_overbought' => 70,
        'kd_oversold' => 20,
        'kd_overbought' => 80,
        'ma_cross' => true
    ]
];

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (file_exists($file)) {
        $data = json_decode(file_get_contents($file), true);
        echo json_encode($data ?: $default, JSON_UNESCAPED_UNICODE);
    } else {
        echo json_encode($default, JSON_UNESCAPED_UNICODE);
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    if ($input) {
        // 合併預設值
        $merged = array_merge_recursive($default, $input);
        // 確保所有欄位存在
        foreach ($default as $key => $value) {
            if (!isset($merged[$key])) {
                $merged[$key] = $value;
            }
        }
        file_put_contents($file, json_encode($merged, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        echo json_encode(['success' => true, 'settings' => $merged], JSON_UNESCAPED_UNICODE);
    } else {
        echo json_encode(['error' => '無效資料'], JSON_UNESCAPED_UNICODE);
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'PUT') {
    // 重置為預設值
    file_put_contents($file, json_encode($default, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    echo json_encode(['success' => true, 'message' => '已重置為預設值'], JSON_UNESCAPED_UNICODE);
}