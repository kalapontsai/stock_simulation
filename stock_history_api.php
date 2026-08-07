<?php
// 股票歷史記錄 API
header('Content-Type: application/json');

$symbol = $_GET['symbol'] ?? '';

if (empty($symbol)) {
    echo json_encode(['error' => '缺少股票代碼']);
    exit;
}

$dataFile = __DIR__ . '/stock_data.json';
if (!file_exists($dataFile)) {
    echo json_encode(['error' => '資料檔案不存在']);
    exit;
}

$stockData = json_decode(file_get_contents($dataFile), true);

if (!isset($stockData[$symbol])) {
    echo json_encode(['error' => '找不到股票 ' . $symbol]);
    exit;
}

$prices = $stockData[$symbol];

// 計算技術指標
function calculateMA($closes, $period) {
    $ma = [];
    for ($i = 0; $i < count($closes); $i++) {
        if ($i < $period - 1) {
            $ma[] = null;
        } else {
            $sum = 0;
            for ($j = 0; $j < $period; $j++) {
                $sum += $closes[$i - $j];
            }
            $ma[] = round($sum / $period, 2);
        }
    }
    return $ma;
}

function calculateRSI($closes, $period = 14) {
    $rsi = [];
    for ($i = 0; $i < count($closes); $i++) {
        if ($i < $period) {
            $rsi[] = null;
        } else {
            $gains = 0;
            $losses = 0;
            for ($j = $i - $period; $j < $i; $j++) {
                $diff = $closes[$j + 1] - $closes[$j];
                if ($diff > 0) $gains += $diff;
                else $losses += abs($diff);
            }
            $avgGain = $gains / $period;
            $avgLoss = $losses / $period;
            if ($avgLoss == 0) {
                $rsi[] = 100;
            } else {
                $rs = $avgGain / $avgLoss;
                $rsi[] = round(100 - (100 / (1 + $rs)), 2);
            }
        }
    }
    return $rsi;
}

function calculateKD($prices, $period = 9) {
    $k = [];
    $d = [];
    $kValue = 50;
    $dValue = 50;
    
    for ($i = 0; $i < count($prices); $i++) {
        if ($i < $period - 1) {
            $k[] = null;
            $d[] = null;
        } else {
            $window = array_slice($prices, max(0, $i - $period + 1), $period);
            $highs = array_filter(array_column($window, 'high'), fn($v) => $v !== null);
            $lows = array_filter(array_column($window, 'low'), fn($v) => $v !== null);
            $closes = array_filter(array_column($window, 'close'), fn($v) => $v !== null);
            
            if (count($highs) && count($lows) && count($closes)) {
                $hh = max($highs);
                $ll = min($lows);
                $c = end($closes);
                if ($hh == $ll) {
                    $rsv = 50;
                } else {
                    $rsv = (($c - $ll) / ($hh - $ll)) * 100;
                }
                $kValue = $kValue * (2/3) + $rsv * (1/3);
                $dValue = $dValue * (2/3) + $kValue * (1/3);
                $k[] = round($kValue, 2);
                $d[] = round($dValue, 2);
            } else {
                $k[] = null;
                $d[] = null;
            }
        }
    }
    return ['k' => $k, 'd' => $d];
}

function calculateMACD($closes, $fast = 12, $slow = 26, $signal = 9) {
    // 計算 EMA
    $ema = function($data, $period) use (&$ema) {
        $result = [];
        $multiplier = 2 / ($period + 1);
        for ($i = 0; $i < count($data); $i++) {
            if ($i < $period - 1) {
                $result[] = null;
            } elseif ($i == $period - 1) {
                $sum = 0;
                for ($j = 0; $j < $period; $j++) {
                    $sum += $data[$j];
                }
                $result[] = $sum / $period;
            } else {
                $result[] = ($data[$i] - $result[$i - 1]) * $multiplier + $result[$i - 1];
            }
        }
        return $result;
    };
    
    $closesFiltered = array_filter($closes, fn($v) => $v !== null);
    $closesFiltered = array_values($closesFiltered);
    
    $ema12 = $ema($closesFiltered, $fast);
    $ema26 = $ema($closesFiltered, $slow);
    
    $macdLine = [];
    for ($i = 0; $i < count($ema12); $i++) {
        if ($ema12[$i] === null || $ema26[$i] === null) {
            $macdLine[] = null;
        } else {
            $macdLine[] = round($ema12[$i] - $ema26[$i], 4);
        }
    }
    
    $signalLine = $ema(array_filter($macdLine, fn($v) => $v !== null), $signal);
    
    // 對齊數組
    $resultMacd = [];
    $resultSignal = [];
    $resultHistogram = [];
    
    $macdFiltered = array_filter($macdLine, fn($v) => $v !== null);
    $macdFiltered = array_values($macdFiltered);
    $signalFiltered = array_filter($signalLine, fn($v) => $v !== null);
    $signalFiltered = array_values($signalFiltered);
    
    $offset = count($macdLine) - count($macdFiltered);
    
    for ($i = 0; $i < count($closes); $i++) {
        if ($i < $offset) {
            $resultMacd[] = null;
            $resultSignal[] = null;
            $resultHistogram[] = null;
        } else {
            $idx = $i - $offset;
            if (isset($macdFiltered[$idx])) {
                $resultMacd[] = $macdFiltered[$idx];
                if (isset($signalFiltered[$idx])) {
                    $resultSignal[] = $signalFiltered[$idx];
                    $resultHistogram[] = round($macdFiltered[$idx] - $signalFiltered[$idx], 4);
                } else {
                    $resultSignal[] = null;
                    $resultHistogram[] = null;
                }
            } else {
                $resultMacd[] = null;
                $resultSignal[] = null;
                $resultHistogram[] = null;
            }
        }
    }
    
    return [
        'macd' => $resultMacd,
        'signal' => $resultSignal,
        'histogram' => $resultHistogram
    ];
}

function calculateBollingerBands($closes, $period = 20, $stdDev = 2) {
    $upper = [];
    $middle = [];
    $lower = [];
    
    for ($i = 0; $i < count($closes); $i++) {
        if ($i < $period - 1) {
            $upper[] = null;
            $middle[] = null;
            $lower[] = null;
        } else {
            $window = array_slice($closes, $i - $period + 1, $period);
            $window = array_filter($window, fn($v) => $v !== null);
            if (count($window) < $period) {
                $upper[] = null;
                $middle[] = null;
                $lower[] = null;
                continue;
            }
            $mean = array_sum($window) / count($window);
            $variance = array_reduce($window, fn($carry, $v) => $carry + pow($v - $mean, 2), 0) / count($window);
            $sd = sqrt($variance);
            $middle[] = round($mean, 2);
            $upper[] = round($mean + ($stdDev * $sd), 2);
            $lower[] = round($mean - ($stdDev * $sd), 2);
        }
    }
    return ['upper' => $upper, 'middle' => $middle, 'lower' => $lower];
}

// 過濾有效資料
$validData = array_filter($prices, fn($p) => $p['close'] !== null);
$validData = array_values($validData);

$closes = array_column($validData, 'close');
$volumes = array_column($validData, 'volume');
$dates = array_column($validData, 'date');

$ma5 = calculateMA($closes, 5);
$ma10 = calculateMA($closes, 10);
$ma20 = calculateMA($closes, 20);
$ma60 = calculateMA($closes, 60);
$rsi = calculateRSI($closes, 14);
$kd = calculateKD($prices, 9);
$macd = calculateMACD($closes);
$bb = calculateBollingerBands($closes);

echo json_encode([
    'symbol' => $symbol,
    'dates' => $dates,
    'price' => [
        'open' => array_column($validData, 'open'),
        'high' => array_column($validData, 'high'),
        'low' => array_column($validData, 'low'),
        'close' => $closes
    ],
    'volume' => $volumes,
    'indicators' => [
        'ma5' => $ma5,
        'ma10' => $ma10,
        'ma20' => $ma20,
        'ma60' => $ma60,
        'rsi' => $rsi,
        'kd_k' => $kd['k'],
        'kd_d' => $kd['d'],
        'macd' => $macd['macd'],
        'macd_signal' => $macd['signal'],
        'macd_histogram' => $macd['histogram'],
        'bb_upper' => $bb['upper'],
        'bb_middle' => $bb['middle'],
        'bb_lower' => $bb['lower']
    ]
], JSON_UNESCAPED_UNICODE);