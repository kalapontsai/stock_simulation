<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>技術指標參數設定</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #0d1117; color: #c9d1d9; padding: 20px; }
        .container { max-width: 900px; margin: 0 auto; }
        
        h1 { color: #58a6ff; margin-bottom: 10px; }
        .subtitle { color: #8b949e; margin-bottom: 30px; }
        
        .indicator-section { background: #161b22; border-radius: 12px; padding: 20px; margin-bottom: 20px; border: 1px solid #30363d; }
        .indicator-title { color: #58a6ff; font-size: 18px; margin-bottom: 15px; display: flex; align-items: center; gap: 10px; }
        .indicator-title .icon { font-size: 24px; }
        .indicator-desc { color: #8b949e; font-size: 13px; margin-bottom: 15px; padding: 10px; background: #21262d; border-radius: 6px; }
        
        .param-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; }
        .param-group { background: #21262d; padding: 15px; border-radius: 8px; }
        .param-label { color: #8b949e; font-size: 12px; margin-bottom: 8px; }
        .param-input { width: 100%; padding: 10px; background: #0d1117; border: 1px solid #30363d; border-radius: 6px; color: #c9d1d9; font-size: 16px; }
        .param-input:focus { outline: none; border-color: #58a6ff; }
        .param-hint { color: #6e7681; font-size: 11px; margin-top: 5px; }
        
        .threshold-section { background: #21262d; padding: 15px; border-radius: 8px; margin-top: 15px; }
        .threshold-title { color: #8b949e; font-size: 14px; margin-bottom: 10px; }
        
        .threshold-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 10px; }
        
        .toggle-group { display: flex; align-items: center; gap: 10px; margin-top: 10px; }
        .toggle { position: relative; width: 44px; height: 24px; }
        .toggle input { opacity: 0; width: 0; height: 0; }
        .toggle-slider { position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0; background: #30363d; border-radius: 24px; transition: 0.3s; }
        .toggle-slider:before { position: absolute; content: ""; height: 18px; width: 18px; left: 3px; bottom: 3px; background: white; border-radius: 50%; transition: 0.3s; }
        .toggle input:checked + .toggle-slider { background: #238636; }
        .toggle input:checked + .toggle-slider:before { transform: translateX(20px); }
        
        .buttons { display: flex; gap: 15px; margin-top: 30px; flex-wrap: wrap; }
        .btn { padding: 12px 24px; border: none; border-radius: 8px; font-size: 16px; cursor: pointer; transition: 0.2s; }
        .btn-primary { background: #238636; color: white; }
        .btn-primary:hover { background: #2ea043; }
        .btn-secondary { background: #30363d; color: #c9d1d9; }
        .btn-secondary:hover { background: #3d444d; }
        .btn-warning { background: #d29922; color: #0d1117; }
        .btn-warning:hover { background: #e3b341; }
        
        .status { margin-top: 15px; padding: 15px; border-radius: 8px; display: none; }
        .status.success { background: #23863620; border: 1px solid #238636; color: #3fb950; }
        .status.error { background: #f8514920; border: 1px solid #f85149; color: #f85149; }
        
        .preset-buttons { display: flex; gap: 10px; margin-bottom: 20px; flex-wrap: wrap; }
        .preset-btn { padding: 8px 16px; background: #21262d; border: 1px solid #30363d; border-radius: 6px; color: #8b949e; cursor: pointer; font-size: 13px; }
        .preset-btn:hover { background: #30363d; color: #c9d1d9; }
        
        @media (max-width: 600px) {
            .param-grid { grid-template-columns: 1fr; }
            .threshold-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>技術指標參數設定</h1>
        <p class="subtitle">調整技術指標的計算參數，預設值參考元大證券說明</p>
        
        <div class="preset-buttons">
            <button class="preset-btn" onclick="loadPreset('yuanta')">元大證券預設</button>
            <button class="preset-btn" onclick="loadPreset('default')">系統預設</button>
            <button class="preset-btn" onclick="loadPreset('aggressive')">進取型</button>
            <button class="preset-btn" onclick="loadPreset('conservative')">穩健型</button>
        </div>
        
        <form id="settingsForm">
            <!-- MA 移動平均線 -->
            <div class="indicator-section">
                <div class="indicator-title">
                    <span class="icon">&#128200;</span> MA 移動平均線
                </div>
                <div class="indicator-desc">
                    預設：短均線 5 天、長均線 20 天（參考元大證券：參數一 5，參數二 15）
                </div>
                <div class="param-grid">
                    <div class="param-group">
                        <div class="param-label">短均線天數 (MA5)</div>
                        <input type="number" class="param-input" id="ma_short" min="1" max="250">
                        <div class="param-hint">短期均線週期</div>
                    </div>
                    <div class="param-group">
                        <div class="param-label">長均線天數 (MA20)</div>
                        <input type="number" class="param-input" id="ma_long" min="1" max="250">
                        <div class="param-hint">中期均線週期</div>
                    </div>
                    <div class="param-group">
                        <div class="param-label">長期均線天數 (MA60)</div>
                        <input type="number" class="param-input" id="ma_long60" min="1" max="250">
                        <div class="param-hint">長期均線週期</div>
                    </div>
                </div>
                <div class="threshold-section">
                    <div class="threshold-title">買賣判斷</div>
                    <div class="toggle-group">
                        <label class="toggle">
                            <input type="checkbox" id="ma_cross">
                            <span class="toggle-slider"></span>
                        </label>
                        <span>啟用 MA 黃金交叉/死亡交叉訊號</span>
                    </div>
                </div>
            </div>
            
            <!-- RSI 相對強度指標 -->
            <div class="indicator-section">
                <div class="indicator-title">
                    <span class="icon">&#9878;</span> RSI 相對強度指標
                </div>
                <div class="indicator-desc">
                    預設：14 天週期
                </div>
                <div class="param-grid">
                    <div class="param-group">
                        <div class="param-label">RSI 週期</div>
                        <input type="number" class="param-input" id="rsi_period" min="1" max="100">
                        <div class="param-hint">計算天數（通常 6-14）</div>
                    </div>
                </div>
                <div class="threshold-section">
                    <div class="threshold-title">超買超賣門檻</div>
                    <div class="threshold-grid">
                        <div class="param-group">
                            <div class="param-label">超賣門檻</div>
                            <input type="number" class="param-input" id="rsi_oversold" min="0" max="50">
                        </div>
                        <div class="param-group">
                            <div class="param-label">超買門檻</div>
                            <input type="number" class="param-input" id="rsi_overbought" min="50" max="100">
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- KD 隨機指標 -->
            <div class="indicator-section">
                <div class="indicator-title">
                    <span class="icon">&#9881;</span> KD 隨機指標
                </div>
                <div class="indicator-desc">
                    預設：9、3、3（參考元大證券：RSV 週期 9，K 平滑 3，D 平滑 3）
                </div>
                <div class="param-grid">
                    <div class="param-group">
                        <div class="param-label">RSV 週期</div>
                        <input type="number" class="param-input" id="kd_period" min="1" max="50">
                        <div class="param-hint">計算 RSV 的天數</div>
                    </div>
                    <div class="param-group">
                        <div class="param-label">K 值平滑</div>
                        <input type="number" class="param-input" id="kd_k_smooth" min="1" max="10">
                        <div class="param-hint">K 值平滑因子（權重）</div>
                    </div>
                    <div class="param-group">
                        <div class="param-label">D 值平滑</div>
                        <input type="number" class="param-input" id="kd_d_smooth" min="1" max="10">
                        <div class="param-hint">D 值平滑因子（權重）</div>
                    </div>
                </div>
                <div class="threshold-section">
                    <div class="threshold-title">超買超賣門檻</div>
                    <div class="threshold-grid">
                        <div class="param-group">
                            <div class="param-label">超賣門檻</div>
                            <input type="number" class="param-input" id="kd_oversold" min="0" max="50">
                        </div>
                        <div class="param-group">
                            <div class="param-label">超買門檻</div>
                            <input type="number" class="param-input" id="kd_overbought" min="50" max="100">
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- MACD -->
            <div class="indicator-section">
                <div class="indicator-title">
                    <span class="icon">&#128200;</span> MACD 指數平滑異同移動平均線
                </div>
                <div class="indicator-desc">
                    預設：26、12、9（參考元大證券：慢速 EMA 26，快速 EMA 12，訊號線 9）
                </div>
                <div class="param-grid">
                    <div class="param-group">
                        <div class="param-label">慢速 EMA</div>
                        <input type="number" class="param-input" id="macd_slow" min="1" max="100">
                        <div class="param-hint">長期 EMA 週期</div>
                    </div>
                    <div class="param-group">
                        <div class="param-label">快速 EMA</div>
                        <input type="number" class="param-input" id="macd_fast" min="1" max="100">
                        <div class="param-hint">短期 EMA 週期</div>
                    </div>
                    <div class="param-group">
                        <div class="param-label">訊號線</div>
                        <input type="number" class="param-input" id="macd_signal" min="1" max="50">
                        <div class="param-hint">Signal 線週期</div>
                    </div>
                </div>
            </div>
            
            <!-- 布林通道 -->
            <div class="indicator-section">
                <div class="indicator-title">
                    <span class="icon">&#128207;</span> 布林通道
                </div>
                <div class="indicator-desc">
                    預設：20 天週期，2 倍標準差
                </div>
                <div class="param-grid">
                    <div class="param-group">
                        <div class="param-label"> MA 週期</div>
                        <input type="number" class="param-input" id="bollinger_period" min="1" max="100">
                        <div class="param-hint">中線均線天數</div>
                    </div>
                    <div class="param-group">
                        <div class="param-label">標準差倍數</div>
                        <input type="number" class="param-input" id="bollinger_std_dev" min="1" max="5" step="0.5">
                        <div class="param-hint">上下��與中線的距離</div>
                    </div>
                </div>
            </div>

            <!-- 證交稅（證券交易稅） -->
            <div class="indicator-section">
                <div class="indicator-title">
                    <span class="icon">&#128181;</span> 證交稅（證券交易稅）
                </div>
                <div class="indicator-desc">
                    台灣法規：賣出一般股票收取 0.3%，國內股票型 ETF 0.1%（買入不收）。
                    提高稅率可模擬頻繁交易的成本拖累。
                </div>
                <div class="param-grid">
                    <div class="param-group">
                        <div class="param-label">賣出稅率 (%)</div>
                        <input type="number" class="param-input" id="tax_sell" min="0" max="1" step="0.01">
                        <div class="param-hint">預設 0.3（即 3/1000）</div>
                    </div>
                    <div class="param-group">
                        <div class="param-label">買入稅率 (%)</div>
                        <input type="number" class="param-input" id="tax_buy" min="0" max="1" step="0.01">
                        <div class="param-hint">預設 0（台灣買入不收稅）</div>
                    </div>
                </div>
            </div>

            <!-- 券商手續費 -->
            <div class="indicator-section">
                <div class="indicator-title">
                    <span class="icon">&#128178;</span> 券商手續費
                </div>
                <div class="indicator-desc">
                    公定費率上限 0.1425%，電子下單可折扣（2.8 折 = 28% 折扣）。
                    最低 20 元（未滿 20 元仍收 20 元）。買賣均收。
                </div>
                <div class="param-grid">
                    <div class="param-group">
                        <div class="param-label">基本費率 (%)</div>
                        <input type="number" class="param-input" id="fee_rate" min="0" max="1" step="0.001">
                        <div class="param-hint">公定 0.1425</div>
                    </div>
                    <div class="param-group">
                        <div class="param-label">電子下單折扣 (%)</div>
                        <input type="number" class="param-input" id="fee_discount" min="1" max="100" step="0.1">
                        <div class="param-hint">2.8 折請填 28</div>
                    </div>
                    <div class="param-group">
                        <div class="param-label">最低手續費 (元)</div>
                        <input type="number" class="param-input" id="fee_min" min="0" step="1">
                        <div class="param-hint">預設 20</div>
                    </div>
                </div>
            </div>

            <div class="buttons">
                <button type="button" class="btn btn-primary" onclick="saveSettings()">儲存設定</button>
                <button type="button" class="btn btn-secondary" onclick="resetToDefault()">重置為預設</button>
                <button type="button" class="btn btn-warning" onclick="testSettings()">測試參數</button>
            </div>
            
            <div class="status" id="status"></div>
        </form>
    </div>

    <script>
        const presets = {
            yuanta: {
                ma: { short: 5, long: 15, long60: 60 },
                rsi: { period: 14 },
                kd: { period: 9, k_smooth: 3, d_smooth: 3 },
                macd: { fast: 12, slow: 26, signal: 9 },
                bollinger: { period: 20, std_dev: 2 },
                thresholds: {
                    rsi_oversold: 30,
                    rsi_overbought: 70,
                    kd_oversold: 20,
                    kd_overbought: 80,
                    ma_cross: true
                }
                tax: { sell: 0.3, buy: 0 },
                fee: { rate: 0.1425, discount: 28, min: 20 }
            },
            default: {
                ma: { short: 5, long: 20, long60: 60 },
                rsi: { period: 14 },
                kd: { period: 9, k_smooth: 3, d_smooth: 3 },
                macd: { fast: 12, slow: 26, signal: 9 },
                bollinger: { period: 20, std_dev: 2 },
                thresholds: {
                    rsi_oversold: 30,
                    rsi_overbought: 70,
                    kd_oversold: 20,
                    kd_overbought: 80,
                    ma_cross: true
                }
                tax: { sell: 0.3, buy: 0 },
                fee: { rate: 0.1425, discount: 28, min: 20 }
            },
            aggressive: {
                ma: { short: 3, long: 10, long60: 30 },
                rsi: { period: 7 },
                kd: { period: 5, k_smooth: 3, d_smooth: 3 },
                macd: { fast: 6, slow: 13, signal: 5 },
                bollinger: { period: 10, std_dev: 2 },
                thresholds: {
                    rsi_oversold: 25,
                    rsi_overbought: 75,
                    kd_oversold: 15,
                    kd_overbought: 85,
                    ma_cross: true
                }
                tax: { sell: 0.3, buy: 0 },
                fee: { rate: 0.1425, discount: 28, min: 20 }
            },
            conservative: {
                ma: { short: 10, long: 30, long60: 120 },
                rsi: { period: 21 },
                kd: { period: 14, k_smooth: 3, d_smooth: 3 },
                macd: { fast: 19, slow: 39, signal: 9 },
                bollinger: { period: 30, std_dev: 2.5 },
                thresholds: {
                    rsi_oversold: 35,
                    rsi_overbought: 65,
                    kd_oversold: 25,
                    kd_overbought: 75,
                    ma_cross: true
                }
                tax: { sell: 0.3, buy: 0 },
                fee: { rate: 0.1425, discount: 28, min: 20 }
            }
        };
        
        async function loadSettings() {
            try {
                const response = await fetch('/stock/indicator_settings_api.php');
                const data = await response.json();
                applySettings(data);
            } catch (e) {
                loadPreset('yuanta');
            }
        }
        
        function applySettings(data) {
            if (data.ma) {
                document.getElementById('ma_short').value = data.ma.short || 5;
                document.getElementById('ma_long').value = data.ma.long || 20;
                document.getElementById('ma_long60').value = data.ma.long60 || 60;
            }
            if (data.rsi) {
                document.getElementById('rsi_period').value = data.rsi.period || 14;
            }
            if (data.thresholds) {
                document.getElementById('rsi_oversold').value = data.thresholds.rsi_oversold || 30;
                document.getElementById('rsi_overbought').value = data.thresholds.rsi_overbought || 70;
                document.getElementById('kd_oversold').value = data.thresholds.kd_oversold || 20;
                document.getElementById('kd_overbought').value = data.thresholds.kd_overbought || 80;
                document.getElementById('ma_cross').checked = data.thresholds.ma_cross !== false;
            }
            if (data.kd) {
                document.getElementById('kd_period').value = data.kd.period || 9;
                document.getElementById('kd_k_smooth').value = data.kd.k_smooth || 3;
                document.getElementById('kd_d_smooth').value = data.kd.d_smooth || 3;
            }
            if (data.macd) {
                document.getElementById('macd_fast').value = data.macd.fast || 12;
                document.getElementById('macd_slow').value = data.macd.slow || 26;
                document.getElementById('macd_signal').value = data.macd.signal || 9;
            }
            if (data.bollinger) {
                document.getElementById('bollinger_period').value = data.bollinger.period || 20;
                document.getElementById('bollinger_std_dev').value = data.bollinger.std_dev || 2;
            }
            if (data.tax) {
                document.getElementById('tax_sell').value = data.tax.sell ?? 0.3;
                document.getElementById('tax_buy').value = data.tax.buy ?? 0;
            }
            if (data.fee) {
                document.getElementById('fee_rate').value = data.fee.rate ?? 0.1425;
                document.getElementById('fee_discount').value = data.fee.discount ?? 28;
                document.getElementById('fee_min').value = data.fee.min ?? 20;
            }
        }
        
        function loadPreset(name) {
            applySettings(presets[name]);
            showStatus('已載入 ' + name + ' 預設', 'success');
        }
        
        function getSettingsFromForm() {
            return {
                ma: {
                    short: parseInt(document.getElementById('ma_short').value) || 5,
                    long: parseInt(document.getElementById('ma_long').value) || 20,
                    long60: parseInt(document.getElementById('ma_long60').value) || 60
                },
                rsi: {
                    period: parseInt(document.getElementById('rsi_period').value) || 14
                },
                kd: {
                    period: parseInt(document.getElementById('kd_period').value) || 9,
                    k_smooth: parseInt(document.getElementById('kd_k_smooth').value) || 3,
                    d_smooth: parseInt(document.getElementById('kd_d_smooth').value) || 3
                },
                macd: {
                    fast: parseInt(document.getElementById('macd_fast').value) || 12,
                    slow: parseInt(document.getElementById('macd_slow').value) || 26,
                    signal: parseInt(document.getElementById('macd_signal').value) || 9
                },
                bollinger: {
                    period: parseInt(document.getElementById('bollinger_period').value) || 20,
                    std_dev: parseFloat(document.getElementById('bollinger_std_dev').value) || 2
                },
                thresholds: {
                    rsi_oversold: parseInt(document.getElementById('rsi_oversold').value) || 30,
                    rsi_overbought: parseInt(document.getElementById('rsi_overbought').value) || 70,
                    kd_oversold: parseInt(document.getElementById('kd_oversold').value) || 20,
                    kd_overbought: parseInt(document.getElementById('kd_overbought').value) || 80,
                    ma_cross: document.getElementById('ma_cross').checked
                },
                tax: {
                    sell: parseFloat(document.getElementById('tax_sell').value) || 0.3,
                    buy: parseFloat(document.getElementById('tax_buy').value) || 0
                },
                fee: {
                    rate: parseFloat(document.getElementById('fee_rate').value) || 0.1425,
                    discount: parseFloat(document.getElementById('fee_discount').value) || 28,
                    min: parseFloat(document.getElementById('fee_min').value) || 20
                }
            };
        }
        
        async function saveSettings() {
            const settings = getSettingsFromForm();
            try {
                const response = await fetch('/stock/indicator_settings_api.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(settings)
                });
                const result = await response.json();
                if (result.success) {
                    showStatus('設定已儲存！', 'success');
                } else {
                    showStatus('儲存失敗：' + (result.error || '未知錯誤'), 'error');
                }
            } catch (e) {
                showStatus('���存���敗：' + e.message, 'error');
            }
        }
        
        async function resetToDefault() {
            if (!confirm('確定要重置為預設值嗎？')) return;
            try {
                const response = await fetch('/stock/indicator_settings_api.php', { method: 'PUT' });
                const result = await response.json();
                if (result.success) {
                    loadSettings();
                    showStatus('已重置為預設值', 'success');
                }
            } catch (e) {
                showStatus('重置失敗：' + e.message, 'error');
            }
        }
        
        function testSettings() {
            const settings = getSettingsFromForm();
            let message = '目前設定：\n';
            message += `MA: ${settings.ma.short}/${settings.ma.long}/${settings.ma.long60}\n`;
            message += `RSI: ${settings.rsi.period}天\n`;
            message += `KD: ${settings.kd.period} RSV, K平滑${settings.kd.k_smooth}, D平滑${settings.kd.d_smooth}\n`;
            message += `MACD: ${settings.macd.fast}/${settings.macd.slow}/${settings.macd.signal}\n`;
            message += `布林通道: ${settings.bollinger.period}天, ${settings.bollinger.std_dev}倍標準差`;
            alert(message);
        }
        
        function showStatus(msg, type) {
            const el = document.getElementById('status');
            el.textContent = msg;
            el.className = 'status ' + type;
            el.style.display = 'block';
            setTimeout(() => el.style.display = 'none', 3000);
        }
        
        loadSettings();
    </script>
</body>
</html>