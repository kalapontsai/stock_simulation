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
    ],
    'tax' => [
        // 謚交稅（證券交易稅）：賣出按 %，買入按 %。
        // 台灣法規：一般股票賣出 0.3% 、買入 0%（此處以 % 為單位，0.3 = 3/1000）
        'sell' => 0.3,
        'buy' => 0.0
    ],
    'fee' => [
        // 券商手續費：買賣都收。
        // rate = 公定費率上限 0.1425%
        // discount = 電子下單折扣 % （例：2.8 折請填 28）
        // min = 最低手續費（元）
        'rate' => 0.1425,
        'discount' => 28.0,    // 2.8 折
        'min' => 20.0
    ],
    'position' => [
        // 資金配置（每策略獨立，存成陣列：[策略1, 策略2]）
        'buy_unit_pct' => [20, 20],          // 每次買進佔帳戶總值 %
        'sell_unit_pct' => [50, 50],         // 每次賣出佔持倉 %
        'max_positions' => [5, 5],           // 最大同時持倉數
        'min_cash_reserve_pct' => [10, 10],  // 最低現金保留 %
        'use_kd_strength' => [false, true],  // 是否用 KD 強度加權（方案 B）
        'kd_strength_max' => [30, 30],       // 達到最大加碼的 K 偏移量
                                            //   K=20（門檻）→ strength=0
                                            //   K=-10 → strength=1（滿倉加碼）
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