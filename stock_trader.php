<?php
/**
 * 股市模擬投資系統
 * 每日自動抓取股價並進行技術分析交易
 * 
 * 使用方式：
 *   ?update=1  - 只更新股價（不打交易）
 *   ?run=1     - 完整交易（含更新股價）
 *   ?history=1 - 查看獲利歷史
 */

// 策略名稱
$strategies = ['策略1', '策略2'];
$strategyNames = [
    '策略1' => 'MA + RSI 混合策略',
    '策略2' => 'KD 隨機指標策略'
];

// 設定
$config = [
    // 從 data/stock_list.json 動態讀取（維護頁面在 stocks.php）
    'stock_list_file' => __DIR__ . '/data/stock_list.json',
    'initial_capital' => 1000000, // 初始資金 100萬
    'data_file' => __DIR__ . '/stock_data.json',
    'portfolio_file' => __DIR__ . '/portfolio.json',
    'profit_history_file' => __DIR__ . '/profit_history.json',
    'analysis_file' => __DIR__ . '/data/daily_analysis.json',
    'snapshot_file' => __DIR__ . '/data/snapshot.json',
    'log_file' => __DIR__ . '/data/trade.log'
];


// 讀取股票清單（從維護頁面維護的 stock_list.json）
function loadStockList($config) {
    $file = $config['stock_list_file'];
    if (!file_exists($file)) {
        echo "警告: 找不到股票清單 $file\n";
        return [];
    }
    $data = json_decode(file_get_contents($file), true);
    if (!is_array($data) || !isset($data['stocks'])) {
        echo "警告: 股票清單格式錯誤\n";
        return [];
    }
    return $data['stocks'];
}

// 取得股票資料
function getStockData($symbol) {
    $url = "https://query1.finance.yahoo.com/v8/finance/chart/$symbol?interval=1d&range=1y";

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['User-Agent: Mozilla/5.0']);
    $response = curl_exec($ch);
    curl_close($ch);

    $data = json_decode($response, true);

    if (!isset($data['chart']['result'][0])) {
        return null;
    }

    $result = $data['chart']['result'][0];
    $timestamps = $result['timestamp'];
    $quotes = $result['indicators']['quote'][0];

    $prices = [];
    for ($i = 0; $i < count($timestamps); $i++) {
        $prices[] = [
            'date' => date('Y-m-d', $timestamps[$i]),
            'open' => $quotes['open'][$i],
            'high' => $quotes['high'][$i],
            'low' => $quotes['low'][$i],
            'close' => $quotes['close'][$i],
            'volume' => $quotes['volume'][$i]
        ];
    }

    return $prices;
}


// 載入技術指標參數設定
function loadIndicatorSettings($strategyIdx = 0) {
    $file = __DIR__ . '/data/indicator_settings.json';
    $default = [
        'ma' => ['short' => [5,5], 'long' => [20,15], 'long60' => [60,60]],
        'rsi' => ['period' => [14,14]],
        'kd' => ['period' => [9,9], 'k_smooth' => [3,3], 'd_smooth' => [3,3]],
        'macd' => ['fast' => [12,12], 'slow' => [26,26], 'signal' => [9,9]],
        'thresholds' => [
            'rsi_oversold' => [30,30], 'rsi_overbought' => [70,70],
            'kd_oversold' => [20,20], 'kd_overbought' => [80,80],
            'ma_cross' => [true, true]
        ]
    ];
    
    if (!file_exists($file)) {
        return $default;
    }
    
    $data = json_decode(file_get_contents($file), true);
    if (!$data) return $default;
    
    $result = $default;
    foreach ($data as $key => $val) {
        if (is_array($val)) {
            $result[$key] = $val;
        }
    }
    return $result;
}

function getSettingVal($category, $key, $default, $settings) {
    // 處理 indicator_settings.json 格式：{ma: {short: [5,5], long: [20,15]}, thresholds: {...}}
    if (isset($settings[$category][$key])) {
        $val = $settings[$category][$key];
        if (is_array($val) && count($val) > 0) {
            return $val[0];
        }
    }
    return $default;
}

function getThreshold($key, $default, $settings) {
    if (isset($settings['thresholds'][$key])) {
        $val = $settings['thresholds'][$key];
        if (is_array($val) && count($val) > 0) {
            return $val[0];
        }
        return $val;
    }
    return $default;
}

// 技術指標計算
function calculateIndicators($prices, $settings = null) {
    if (count($prices) < 20) return null;

    $closes = array_column($prices, 'close');

    // 取得參數
    $maShort = $settings ? getSettingVal('ma', 'short', 5, $settings) : 5;
    $maLong = $settings ? getSettingVal('ma', 'long', 20, $settings) : 20;
    $maLong60 = $settings ? getSettingVal('ma', 'long60', 60, $settings) : 60;
    $rsiPeriod = $settings ? getSettingVal('rsi', 'period', 14, $settings) : 14;
    $kdPeriod = $settings ? getSettingVal('kd', 'period', 9, $settings) : 9;
    $macdFast = $settings ? getSettingVal('macd', 'fast', 12, $settings) : 12;
    $macdSlow = $settings ? getSettingVal('macd', 'slow', 26, $settings) : 26;

    // MA (移動平均線) - 使用參數
    $maShortVal = count($closes) >= $maShort ? array_sum(array_slice($closes, -$maShort)) / $maShort : array_sum(array_slice($closes, -5)) / 5;
    $maLongVal = count($closes) >= $maLong ? array_sum(array_slice($closes, -$maLong)) / $maLong : array_sum(array_slice($closes, -20)) / 20;
    $maLong60Val = count($closes) >= $maLong60 ? array_sum(array_slice($closes, -$maLong60)) / $maLong60 : $maLongVal;

    // RSI
    $rsi = calculateRSI($closes, $rsiPeriod);

    // MACD
    $macd = calculateMACD($closes, $macdFast, $macdSlow);

    // KD
    $kd = calculateKD($prices, $kdPeriod);

    return [
        'price' => end($closes),
        'ma_short' => $maShortVal,
        'ma_long' => $maLongVal,
        'ma_long60' => $maLong60Val,
        'rsi' => $rsi,
        'macd' => $macd,
        'kd' => $kd,
        'params' => [
            'ma_short' => $maShort,
            'ma_long' => $maLong,
            'rsi_period' => $rsiPeriod,
            'kd_period' => $kdPeriod
        ]
    ];
}

function calculateRSI($closes, $period = 14) {
    $prices = array_slice($closes, -$period * 2);
    if (count($prices) < $period + 1) return 50;

    $gains = [];
    $losses = [];

    for ($i = 1; $i < count($prices); $i++) {
        $diff = $prices[$i] - $prices[$i - 1];
        if ($diff > 0) {
            $gains[] = $diff;
            $losses[] = 0;
        } else {
            $gains[] = 0;
            $losses[] = abs($diff);
        }
    }

    $avgGain = array_sum(array_slice($gains, -$period)) / $period;
    $avgLoss = array_sum(array_slice($losses, -$period)) / $period;

    if ($avgLoss == 0) return 100;

    $rs = $avgGain / $avgLoss;
    return 100 - (100 / (1 + $rs));
}

function calculateMACD($closes, $fast = 12, $slow = 26, $signal = 9) {
    $emaFast = calculateEMA($closes, $fast);
    $emaSlow = calculateEMA($closes, $slow);
    $macdLine = $emaFast - $emaSlow;

    // 簡化版 MACD
    return [
        'value' => $macdLine,
        'signal' => 0,
        'histogram' => $macdLine
    ];
}

function calculateEMA($prices, $period) {
    $prices = array_slice($prices, -$period * 2);
    if (count($prices) < $period) return $prices[0] ?? 0;

    $multiplier = 2 / ($period + 1);
    $ema = $prices[0];

    for ($i = 1; $i < count($prices); $i++) {
        $ema = ($prices[$i] * $multiplier) + ($ema * (1 - $multiplier));
    }

    return $ema;
}

function calculateKD($prices, $period = 9) {
    if (count($prices) < $period) {
        return ['k' => 50, 'd' => 50];
    }

    $k = 50.0;
    $d = 50.0;

    // 從第 $period-1 筆開始計算 RSV 並遞迴平滑（與 kd.py 一致）
    for ($i = $period - 1; $i < count($prices); $i++) {
        $window = array_slice($prices, $i - $period + 1, $period);
        $highs = array_filter(array_column($window, 'high'), fn($h) => $h !== null);
        $lows = array_filter(array_column($window, 'low'), fn($l) => $l !== null);
        $closes = array_filter(array_column($window, 'close'), fn($c) => $c !== null);

        if (count($highs) === 0 || count($lows) === 0 || count($closes) === 0) {
            continue;
        }

        $highestHigh = max($highs);
        $lowestLow = min($lows);
        $currentClose = end($closes);

        if ($highestHigh == $lowestLow) {
            $rsv = 50.0;
        } else {
            $rsv = (($currentClose - $lowestLow) / ($highestHigh - $lowestLow)) * 100;
        }

        // EMA 型平滑（與 kd.py 一致）
        $k = $k * (2 / 3) + $rsv * (1 / 3);
        $d = $d * (2 / 3) + $k * (1 / 3);
    }

    return ['k' => $k, 'd' => $d];
}

// 買賣訊號判斷
function getSignal($indicators) {
    $signals = [];

    // MA 黃金交叉/死亡交叉
    if ($indicators['ma_short'] > $indicators['ma_long']) {
        $signals[] = 'MA_GOLDEN'; // 買入訊號
    } elseif ($indicators['ma_short'] < $indicators['ma_long']) {
        $signals[] = 'MA_DEAD'; // 賣出訊號
    }

    // RSI
    if ($indicators['rsi'] < 30) {
        $signals[] = 'RSI_OVERSOLD'; // 買入訊號
    } elseif ($indicators['rsi'] > 70) {
        $signals[] = 'RSI_OVERBOUGHT'; // 賣出訊號
    }

    // MACD
    if ($indicators['macd']['value'] > $indicators['macd']['signal']) {
        $signals[] = 'MACD_BUY';
    } elseif ($indicators['macd']['value'] < $indicators['macd']['signal']) {
        $signals[] = 'MACD_SELL';
    }

    // KD
    $kd = $indicators['kd'] ?? ['k' => 50, 'd' => 50];
    if ($kd['k'] < 20) {
        $signals[] = 'KD_OVERSOLD';
    } elseif ($kd['k'] > 80) {
        $signals[] = 'KD_OVERBOUGHT';
    }

    return $signals;
}

// 計算交易成本（證交稅 + 券商手續費）
// $action: 'BUY' or 'SELL'
// $total: 成交金額（price × quantity）
// $tax_cfg: ['sell' => 0.3, 'buy' => 0]  // 百分比 (e.g. 0.3 = 3/1000)
// $fee_cfg: ['rate' => 0.1425, 'discount' => 28, 'min' => 20]
// 回傳 ['tax' => 稅額, 'fee' => 手續費, 'net' => 淨額]
function calcTradeCost($action, $total, $tax_cfg, $fee_cfg) {
    // 證交稅：依買/賣決定稅率
    $taxRate = $action === 'BUY' ? ($tax_cfg['buy'] ?? 0) : ($tax_cfg['sell'] ?? 0);
    $tax = $total * ($taxRate / 100);  // rate 是百分比，轉小數

    // 券商手續費：基本費率 × 折扣，最低 min
    $feeRate = ($fee_cfg['rate'] ?? 0) * ($fee_cfg['discount'] ?? 100) / 100;
    $fee = max($fee_cfg['min'] ?? 0, $total * ($feeRate / 100));

    return [
        'tax' => round($tax, 2),
        'fee' => round($fee, 2),
    ];
}

// 執行交易
function executeTrade(&$portfolio, $stock, $action, $price, $quantity, $tax_fee = null) {
    global $config;

    $total = $price * $quantity;

    // 取得稅費設定（從 indicator_settings.json 或用預設）
    if ($tax_fee === null) {
        $settings = loadIndicatorSettings(0);
        $tax_fee = [
            'tax' => $settings['tax'] ?? ['sell' => 0.3, 'buy' => 0],
            'fee' => $settings['fee'] ?? ['rate' => 0.1425, 'discount' => 28, 'min' => 20],
        ];
    }

    if ($action === 'BUY') {
        // 計算成本（含稅 + 手續費）
        $cost = calcTradeCost('BUY', $total, $tax_fee['tax'], $tax_fee['fee']);
        $totalCost = $total + $cost['tax'] + $cost['fee'];

        if ($portfolio['cash'] >= $totalCost) {
            $portfolio['cash'] -= $totalCost;
            $portfolio['holdings'][$stock] = ($portfolio['holdings'][$stock] ?? 0) + $quantity;
            $portfolio['trades'][] = [
                'date' => date('Y-m-d H:i:s'),
                'stock' => $stock,
                'action' => 'BUY',
                'price' => $price,
                'quantity' => $quantity,
                'total' => $total,
                'tax' => $cost['tax'],
                'fee' => $cost['fee'],
                'total_cost' => $totalCost
            ];
            return true;
        }
    } elseif ($action === 'SELL') {
        if (($portfolio['holdings'][$stock] ?? 0) >= $quantity) {
            // 計算成本（從收入扣稅 + 手續費）
            $cost = calcTradeCost('SELL', $total, $tax_fee['tax'], $tax_fee['fee']);
            $netIncome = $total - $cost['tax'] - $cost['fee'];

            $portfolio['cash'] += $netIncome;
            $portfolio['holdings'][$stock] -= $quantity;
            $portfolio['trades'][] = [
                'date' => date('Y-m-d H:i:s'),
                'stock' => $stock,
                'action' => 'SELL',
                'price' => $price,
                'quantity' => $quantity,
                'total' => $total,
                'tax' => $cost['tax'],
                'fee' => $cost['fee'],
                'net_income' => $netIncome
            ];
            return true;
        }
    }

    return false;
}

// 計算投資組合價值
function calculatePortfolioValue($portfolio, $prices) {
    $value = $portfolio['cash'];

    foreach ($portfolio['holdings'] as $stock => $qty) {
        if ($qty > 0 && isset($prices[$stock])) {
            $value += $qty * $prices[$stock];
        }
    }

    return $value;
}

// 動態載入股票清單（覆寫 \$config['stocks']）
$config['stocks'] = loadStockList($config);

if (empty($config['stocks'])) {
    echo "錯誤: 股票清單為空，請到 stocks.php 維護\n";
    exit(1);
}

// 主程式
if (php_sapi_name() === 'cli' || isset($_GET['run']) || isset($_GET['update']) || isset($_GET['snapshot']) || isset($_GET['restore'])) {
    $isUpdateOnly = isset($_GET['update']) && !isset($_GET['run']) && !isset($_GET['snapshot']) && !isset($_GET['restore']);
    $isSnapshot = isset($_GET['snapshot']);
    $isRestore = isset($_GET['restore']);

    echo "=== 股市模擬系統開始執行 ===\n";
    echo "時間: " . date('Y-m-d H:i:s') . "\n";
    echo "模式: ";
    if ($isRestore) {
        echo "還原快照\n\n";
        
        $snapshotFile = $config['snapshot_file'];
        if (!file_exists($snapshotFile)) {
            echo "錯誤: 找不到快照檔案\n";
            exit(1);
        }
        
        $snapshot = json_decode(file_get_contents($snapshotFile), true);
        if (!$snapshot) {
            echo "錯誤: 快照檔案格式錯誤\n";
            exit(1);
        }
        
        echo "快照時間: " . ($snapshot['timestamp'] ?? '未知') . "\n";
        echo "快照說明: " . ($snapshot['description'] ?? '無') . "\n\n";
        
        // 還原 portfolio
        $portfolioFile = $config['portfolio_file'];
        file_put_contents($portfolioFile, json_encode($snapshot['portfolio'], JSON_PRETTY_PRINT));
        echo "已還原投資組合到: $portfolioFile\n";
        
        // 還原 daily_analysis
        $analysisFile = $config['analysis_file'];
        file_put_contents($analysisFile, json_encode($snapshot['analysis'], JSON_PRETTY_PRINT));
        echo "已還原每日分析到: $analysisFile\n";
        
        echo "\n還原完成!\n";
        echo "警告: 還原會覆蓋目前的交易資料，請確認是否要繼續。\n";
        
    } elseif ($isSnapshot) {
        echo "快照模式 (只儲存當前狀態，不執行交易)\n\n";
        
        // 載入當前資料
        $portfolio = file_exists($config['portfolio_file']) ? json_decode(file_get_contents($config['portfolio_file']), true) : [];
        $analysis = file_exists($config['analysis_file']) ? json_decode(file_get_contents($config['analysis_file']), true) : [];
        
        $description = $_GET['desc'] ?? $_POST['desc'] ?? '';
        
        $snapshot = [
            'timestamp' => date('Y-m-d H:i:s'),
            'description' => $description ?: '手動快照',
            'portfolio' => $portfolio,
            'analysis' => $analysis
        ];
        
        $snapshotFile = $config['snapshot_file'];
        file_put_contents($snapshotFile, json_encode($snapshot, JSON_PRETTY_PRINT));
        
        echo "快照已儲存: $snapshotFile\n";
        echo "時間: " . $snapshot['timestamp'] . "\n";
        echo "說明: " . $snapshot['description'] . "\n";
        echo "\n包含資料:\n";
        echo "- 投資組合: " . count($portfolio) . " 個策略\n";
        echo "- 每日分析: " . count($analysis) . " 檔股票\n";
        echo "\n快照完成!\n";
        
    } else {
        echo "模式: " . ($isUpdateOnly ? "股價更新(只更新股價,不交易)" : "完整交易(含股價更新)") . "\n\n";

    $allData = file_exists($config['data_file']) ? json_decode(file_get_contents($config['data_file']), true) : [];
    $allPortfolios = file_exists($config['portfolio_file']) ? json_decode(file_get_contents($config['portfolio_file']), true) : [];
    
    // 確保兩個策略帳戶都存在
    foreach ($strategies as $s) {
        if (!isset($allPortfolios[$s])) {
            $allPortfolios[$s] = ['cash' => $config['initial_capital'], 'holdings' => [], 'trades' => []];
        }
    }

    $currentPrices = [];
    $dailyAnalysis = [];

    // 只在非只更新模式時讀取現有價格作為參考
    if ($isUpdateOnly) {
        foreach ($config['stocks'] as $symbol) {
            if (isset($allData[$symbol]) && !empty($allData[$symbol])) {
                $currentPrices[$symbol] = end($allData[$symbol])['close'];
            }
        }
    }

    foreach ($config['stocks'] as $symbol) {
        echo "抓取 $symbol ...\n";
        $prices = getStockData($symbol);

        if ($prices) {
            $currentPrices[$symbol] = end($prices)['close'];
            $allData[$symbol] = $prices;
            echo "  最新價格: " . $currentPrices[$symbol] . "\n";

            // 只在完整交易模式執行技術分析與交易
            if (!$isUpdateOnly) {
                // 為每個策略載入參數設定
                $allSettings = [];
                foreach ($strategies as $idx => $strategyName) {
                    $allSettings[$strategyName] = loadIndicatorSettings($idx);
                }

                // 技術分析（使用策略1的設定）
                $indicators = calculateIndicators($prices, $allSettings['策略1']);
                if ($indicators) {
                    $params = $indicators['params'] ?? [];
                    $signals = getSignal($indicators, $allSettings['策略1']);
                    echo "  技術指標: MA" . ($params['ma_short'] ?? 5) . "=" . round($indicators['ma_short'], 2) .
                         ", MA" . ($params['ma_long'] ?? 20) . "=" . round($indicators['ma_long'], 2) .
                         ", RSI=" . round($indicators['rsi'], 1) . "\n";
                    echo "  買賣訊號: " . implode(', ', $signals) . "\n";

                    // 記錄各策略的分析結果
                    $dailyAnalysis[$symbol] = [
                        'price' => $currentPrices[$symbol],
                        'ma_short' => round($indicators['ma_short'], 2),
                        'ma_long' => round($indicators['ma_long'], 2),
                        'rsi' => round($indicators['rsi'], 1),
                        'macd' => $indicators['macd']['value'],
                        'kd' => $indicators['kd']['k'] ?? 0,
                        'signals' => $signals,
                        'params' => $params,
                        'strategies' => []
                    ];

                    // 讓各個策略決定是否交易
                    foreach ($strategies as $strategyName) {
                        $settings = $allSettings[$strategyName];
                        $strategySignals = getSignal($indicators, $settings);

                        // 根據策略過濾訊號
                        if ($strategyName === '策略1') {
                            $strategySignals = array_filter($strategySignals, function($s) {
                                return in_array($s, ['MA_GOLDEN', 'RSI_OVERSOLD', 'MACD_BUY']);
                            });
                        } else {
                            $strategySignals = array_filter($strategySignals, function($s) {
                                return in_array($s, ['KD_OVERSOLD', 'KD_OVERBOUGHT']);
                            });
                        }

                        $tradeResult = ['action' => 'NONE', 'reason' => ''];
                        $cash = $allPortfolios[$strategyName]['cash'] ?? 0;
                        $holdings = $allPortfolios[$strategyName]['holdings'][$symbol] ?? 0;
                        
                        if (empty($strategySignals)) {
                            $tradeResult['reason'] = '無買賣訊號';
                        } elseif (rand(0, 1) === 0) {
                            $tradeResult['reason'] = '隨機跳過';
                        } elseif ($cash < $currentPrices[$symbol]) {
                            $tradeResult['reason'] = '現金不足 (' . number_format($cash) . ')';
                        } else {
                            $action = strpos(reset($strategySignals), 'BUY') !== false || 
                                      strpos(reset($strategySignals), 'OVERSOLD') !== false ? 'BUY' : 'SELL';
                            $quantity = floor($cash / $currentPrices[$symbol] / 2);
                            
                            if ($action === 'SELL' && $holdings <= 0) {
                                $tradeResult['reason'] = '無庫存可賣';
                            } elseif ($quantity > 0 && executeTrade($allPortfolios[$strategyName], $symbol, $action, $currentPrices[$symbol], $quantity)) {
                                $tradeResult = ['action' => $action, 'quantity' => $quantity, 'price' => $currentPrices[$symbol], 'reason' => '成交'];
                                echo "  [$strategyName] $action $quantity 股 $symbol @ {$currentPrices[$symbol]}\n";
                            } else {
                                $tradeResult['reason'] = '庫存不足或交易失敗';
                            }
                        }
                        
                        $dailyAnalysis[$symbol]['strategies'][$strategyName] = $tradeResult;
                    }
                }
            }
        }
    }

    // 只在完整交易模式計算績效
    if (!$isUpdateOnly) {
        // 計算各策略績效
        echo "\n=== 投資績效 ===\n";
        $today = date('Y-m-d');
        $profitHistory = file_exists($config['profit_history_file']) ? json_decode(file_get_contents($config['profit_history_file']), true) : [];
        
        foreach ($strategies as $strategyName) {
            $portfolio = $allPortfolios[$strategyName] ?? ['cash' => $config['initial_capital'], 'holdings' => [], 'trades' => []];
            $initial = $config['initial_capital'];
            $currentValue = calculatePortfolioValue($portfolio, $currentPrices);
            $profit = $currentValue - $initial;
            $profitRate = ($profit / $initial) * 100;
            
            echo "$strategyName ({$strategyNames[$strategyName]}):\n";
            echo "  初始資金: $initial\n";
            echo "  目前價值: " . round($currentValue, 2) . "\n";
            echo "  獲利: " . round($profit, 2) . " (" . round($profitRate, 2) . "%)\n";
            echo "  庫存: " . json_encode($portfolio['holdings']) . "\n";
            echo "  交易次數: " . count($portfolio['trades']) . "\n\n";
            
            // 記錄每日獲利
            $profitHistory[$strategyName][$today] = round($profitRate, 2);
        }
        
        // 儲存每日分析
        file_put_contents($config['analysis_file'], json_encode($dailyAnalysis, JSON_PRETTY_PRINT));
        
        // 儲存獲利歷史
        file_put_contents($config['profit_history_file'], json_encode($profitHistory, JSON_PRETTY_PRINT));
    }

    // 儲存股價資料(兩種模式都更新)
    file_put_contents($config['data_file'], json_encode($allData, JSON_PRETTY_PRINT));

    // 只在完整交易模式儲存投資組合
    if (!$isUpdateOnly) {
        file_put_contents($config['portfolio_file'], json_encode($allPortfolios, JSON_PRETTY_PRINT));
    }

    echo ($isUpdateOnly ? "股價更新" : "執行") . "完成!\n";
}

}
