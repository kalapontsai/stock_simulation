<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>股市模擬投資 Dashboard</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #0d1117; color: #c9d1d9; padding: 20px; }
        .container { max-width: 1400px; margin: 0 auto; }
        h1 { text-align: center; margin-bottom: 10px; color: #58a6ff; }
        .subtitle { text-align: center; color: #8b949e; margin-bottom: 20px; }

        /* 即時更新控制列 */
        .realtime-bar {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 14px;
            margin-bottom: 30px;
            flex-wrap: wrap;
        }
        .realtime-bar .label { color: #8b949e; font-size: 14px; }
        .toggle-switch {
            position: relative;
            display: inline-block;
            width: 46px;
            height: 24px;
            flex-shrink: 0;
        }
        .toggle-switch input { opacity: 0; width: 0; height: 0; }
        .toggle-slider {
            position: absolute;
            cursor: pointer;
            top: 0; left: 0; right: 0; bottom: 0;
            background-color: #30363d;
            border-radius: 24px;
            transition: background-color 0.2s;
        }
        .toggle-slider::before {
            position: absolute;
            content: "";
            height: 18px; width: 18px;
            left: 3px; bottom: 3px;
            background-color: #c9d1d9;
            border-radius: 50%;
            transition: transform 0.2s, background-color 0.2s;
        }
        .toggle-switch input:checked + .toggle-slider { background-color: #2ea043; }
        .toggle-switch input:checked + .toggle-slider::before { transform: translateX(22px); background-color: #fff; }
        .toggle-switch input:disabled + .toggle-slider { opacity: 0.5; cursor: not-allowed; }
        .interval-select {
            background: #21262d;
            color: #c9d1d9;
            border: 1px solid #30363d;
            border-radius: 6px;
            padding: 4px 8px;
            font-size: 13px;
            cursor: pointer;
        }
        .interval-select:disabled { opacity: 0.5; cursor: not-allowed; }
        .rt-status {
            color: #8b949e;
            font-size: 13px;
            min-width: 220px;
            text-align: left;
        }
        .rt-status.updating { color: #d29922; }
        .rt-status.error { color: #f85149; }
        .rt-status.ok { color: #3fb950; }

        .bots { display: grid; grid-template-columns: repeat(auto-fit, minmax(400px, 1fr)); gap: 20px; margin-bottom: 30px; }
        .bot-card { background: #161b22; border-radius: 12px; padding: 20px; border: 1px solid #30363d; }
        .bot-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; padding-bottom: 15px; border-bottom: 1px solid #30363d; }
        .bot-name { font-size: 24px; font-weight: bold; }
        .bot-role { color: #8b949e; font-size: 14px; }
        .profit { font-size: 32px; font-weight: bold; }
        .profit.positive { color: #3fb950; }
        .profit.negative { color: #f85149; }

        .stats { display: grid; grid-template-columns: repeat(4, 1fr); gap: 15px; margin-bottom: 20px; }
        .stat { background: #21262d; padding: 15px; border-radius: 8px; }
        .stat-label { color: #8b949e; font-size: 12px; margin-bottom: 5px; }
        .stat-value { font-size: 18px; font-weight: 600; }

        .holdings { margin-bottom: 20px; }
        .holdings h3 { color: #8b949e; font-size: 14px; margin-bottom: 10px; }
        .holding-item { display: flex; justify-content: space-between; padding: 10px; background: #21262d; border-radius: 6px; margin-bottom: 8px; }
        .stock-symbol { color: #58a6ff; }
        .stock-qty { color: #c9d1d9; }

        .trades { max-height: 300px; overflow-y: auto; }
        .trades h3 { color: #8b949e; font-size: 14px; margin-bottom: 10px; }
        .trade-item { display: flex; justify-content: space-between; padding: 8px 10px; background: #21262d; border-radius: 6px; margin-bottom: 5px; font-size: 13px; }
        .trade-buy { border-left: 3px solid #3fb950; }
        .trade-sell { border-left: 3px solid #f85149; }
        .trade-action { font-weight: bold; }
        .trade-action.buy { color: #3fb950; }
        .trade-action.sell { color: #f85149; }
        .trade-cost { color: #8b949e; font-size: 11px; margin-top: 3px; font-family: monospace; }
        .trade-cost .tax-fee { color: #d29922; }
        .trade-cost .net { color: #c9d1d9; font-weight: 600; }
        .trade-item { flex-direction: column; align-items: stretch; }

        .market { background: #161b22; border-radius: 12px; padding: 20px; border: 1px solid #30363d; }
        .market h2 { color: #58a6ff; margin-bottom: 20px; }
        .stock-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 15px; }
        .stock-card { background: #21262d; padding: 12px; border-radius: 8px; text-decoration: none; color: inherit; display: flex; flex-direction: column; justify-content: space-between; min-height: 160px; }
        .stock-card:hover { background: #30363d; }
        .stock-header { display: flex; justify-content: space-between; align-items: baseline; margin-bottom: 8px; }
        .stock-symbol-title { font-weight: bold; color: #58a6ff; font-size: 16px; }
        .stock-price { font-size: 20px; font-weight: bold; }
        .stock-price-block { text-align: right; display: flex; flex-direction: column; align-items: flex-end; }
        .stock-change { font-size: 12px; font-weight: 600; margin-top: 2px; font-variant-numeric: tabular-nums; }
        .stock-change.up { color: #3fb950; }
        .stock-change.down { color: #f85149; }
        .stock-change.flat { color: #8b949e; }
        .stock-indicators { display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px; margin-top: 4px; }
        .indicator { text-align: center; background: #161b22; padding: 6px 4px; border-radius: 4px; }
        .indicator-label { color: #8b949e; font-size: 10px; margin-bottom: 2px; }
        .indicator-value { font-size: 13px; font-weight: 600; }
        .indicator-ma { grid-column: span 3; text-align: center; font-size: 11px; color: #8b949e; padding: 4px; background: #161b22; border-radius: 4px; margin-bottom: 4px; }

        .signal-box { padding: 8px 12px; border-radius: 6px; font-size: 13px; font-weight: 600; text-align: center; min-height: 36px; display: flex; align-items: center; justify-content: center; margin-top: auto; }
        .signal-box.buy { background: linear-gradient(135deg, #238636, #2ea043); color: white; }
        .signal-box.sell { background: linear-gradient(135deg, #f85149, #da3633); color: white; }
        .signal-box.none { background: #21262d; color: #6e7681; border: 1px solid #30363d; }

        .btn { display: inline-block; background: #238636; color: white; padding: 10px 20px; border: none; border-radius: 6px; cursor: pointer; text-decoration: none; margin-top: 20px; }
        .btn:hover { background: #2ea043; }

        @media (max-width: 768px) {
            .bots { grid-template-columns: 1fr; }
            .stats { grid-template-columns: 1fr 1fr; }
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>股市模擬投資 Dashboard</h1>
        <p class="subtitle">最後更新: <span id="updateTime">載入中...</span></p>

        <div class="realtime-bar">
            <span class="label">即時股價更新</span>
            <label class="toggle-switch" title="開啟後自動抓取最新股價（不打交易）">
                <input type="checkbox" id="realtimeToggle">
                <span class="toggle-slider"></span>
            </label>
            <select id="realtimeInterval" class="interval-select" title="更新間隔">
                <option value="60000">1 分鐘</option>
                <option value="180000">3 分鐘</option>
                <option value="300000" selected>5 分鐘</option>
                <option value="600000">10 分鐘</option>
                <option value="900000">15 分鐘</option>
            </select>
            <span id="realtimeStatus" class="rt-status">已關閉</span>
        </div>

        <div class="bots" id="bots"></div>

        <div class="market">
            <h2>市場概況</h2>
            <div class="stock-grid" id="stocks"></div>
        </div>

        <div style="text-align: center;">
            <button class="btn" style="background: #f85149;" onclick="resetAutoAccounts()">清空自動帳戶</button>
            <button class="btn" style="background: #1f6feb;" onclick="saveSnapshot()">暫存試算</button>
            <button class="btn" style="background: #8957e5;" onclick="restoreSnapshot()">還原試算</button>
            <a href="/stock/indicator_settings.php" class="btn" style="background: #8957e5;" target="_blank">指標參數設定</a>
            <a href="/stock/profit_history.php" class="btn" style="background: #8957e5;" target="_blank">獲利歷史</a>
            <a href="/stock/stocks.php" class="btn" style="background: #1f6feb;" target="_blank">股票清單維護</a>
            <a href="/stock/manual_trade.php" class="btn" style="background: #6e40c9;" target="_blank">手動投資</a>
            <a href="/stock/stock_data.json" class="btn" style="background: #1f6feb;" target="_blank">歷史資料</a>
        </div>

        <div id="snapshotModal" class="modal" style="display:none;">
            <div class="modal-content">
                <h2 id="modalTitle">暫存試算</h2>
                <div id="modalMessage" style="margin: 15px 0; color: #c9d1d9;"></div>
                <div id="snapshotDesc" style="margin: 15px 0;"></div>
                <div style="text-align: center; margin-top: 20px;">
                    <button class="btn" onclick="closeModal()" style="background: #30363d;">關閉</button>
                </div>
            </div>
        </div>

        <style>
        .modal { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.8); z-index: 1000; display: flex; align-items: center; justify-content: center; }
        .modal-content { background: #161b22; border-radius: 12px; padding: 30px; max-width: 500px; width: 90%; border: 1px solid #30363d; }
        .modal-content h2 { color: #58a6ff; margin-bottom: 15px; }
        .btn-primary { background: #238636; }
        .btn-danger { background: #f85149; }
        </style>
    </div>

    <script>
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        function displayTicker(symbol) {
            return String(symbol || '').replace(/\.(TW|TWO)$/, '');
        }

        function formatNumber(num) {
            return new Intl.NumberFormat('zh-TW').format(Math.round(num));
        }

        function loadData() {
            // cache:'no-store' 避免瀏覽器吃 disk cache，拿到舊的 stock_data.json
            // 舊版會造成 dashboard 計算「前一交易日」時用過時資料，漲跌數字錯。
            Promise.all([
                fetch('/stock/stock_data.json', { cache: 'no-store' }).then(r => r.json()),
                fetch('/stock/portfolio.json', { cache: 'no-store' }).then(r => r.json()),
                fetch('/stock/stocks_api.php', { cache: 'no-store' }).then(r => r.json())
            ]).then(([stockData, portfolio, stockList]) => {
                renderPortfolio(portfolio, stockData);
                renderStocks(stockData, stockList.stocks || Object.keys(stockData));
                document.getElementById('updateTime').textContent = new Date().toLocaleString('zh-TW');
            }).catch(err => {
                console.error('載入資料失敗:', err);
            });
        }

        function renderPortfolio(portfolio, stockData) {
            const strategies = {
                '策略1': { label: 'MA + RSI 混合策略' },
                '策略2': { label: 'KD 隨機指標策略' }
            };

            let html = '';
            const initialCapital = 5000000;

            for (const [name, data] of Object.entries(portfolio)) {
                const strategy = strategies[name] || { label: name };

                let currentValue = data.cash;
                for (const [stock, qty] of Object.entries(data.holdings || {})) {
                    if (qty > 0 && stockData[stock]) {
                        let price = 0;
                        for (let i = stockData[stock].length - 1; i >= 0; i--) {
                            if (stockData[stock][i].close != null) {
                                price = stockData[stock][i].close;
                                break;
                            }
                        }
                        currentValue += qty * price;
                    }
                }

                const profit = currentValue - initialCapital;
                const profitRate = (profit / initialCapital) * 100;

                // 計算累計交易成本（證交稅 + 手續費）
                let totalCost = 0;
                for (const t of (data.trades || [])) {
                    totalCost += (t.tax ?? 0) + (t.fee ?? 0);
                }

                html += `
                    <div class="bot-card">
                        <div class="bot-header">
                            <div>
                                <div class="bot-name">${escapeHtml(name)}</div>
                                <div class="bot-role">${escapeHtml(strategy.label)}</div>
                            </div>
                            <div class="profit ${profit >= 0 ? 'positive' : 'negative'}">
                                ${profit >= 0 ? '+' : ''}${profitRate.toFixed(2)}%
                            </div>
                        </div>
                        <div class="stats">
                            <div class="stat">
                                <div class="stat-label">現金</div>
                                <div class="stat-value">${formatNumber(data.cash)}</div>
                            </div>
                            <div class="stat">
                                <div class="stat-label">投資部位</div>
                                <div class="stat-value">${formatNumber(currentValue - data.cash)}</div>
                            </div>
                            <div class="stat">
                                <div class="stat-label">總資產</div>
                                <div class="stat-value">${formatNumber(currentValue)}</div>
                            </div>
                            <div class="stat" style="border: 1px solid #d29922;">
                                <div class="stat-label">累計交易成本</div>
                                <div class="stat-value" style="color: #d29922;">${formatNumber(totalCost)}</div>
                            </div>
                        </div>
                        <div class="holdings">
                            <h3>庫存</h3>
                            ${Object.entries(data.holdings || {}).filter(([_, qty]) => qty > 0).map(([stock, qty]) => `
                                <div class="holding-item">
                                    <span class="stock-symbol">${escapeHtml(displayTicker(stock))}</span>
                                    <span class="stock-qty">${qty} 股</span>
                                </div>
                            `).join('') || '<div style="color: #8b949e;">無庫存</div>'}
                        </div>
                        <div class="trades">
                            <h3>最近交易 (${data.trades?.length || 0} 筆)</h3>
                            ${(data.trades || []).slice(-10).reverse().map(t => {
                                const total = t.total ?? (t.price * t.quantity);
                                const tax = t.tax ?? 0;
                                const fee = t.fee ?? 0;
                                const hasCost = tax > 0 || fee > 0;
                                const netIncome = t.net_income ?? (total - tax - fee);
                                const totalCost = t.total_cost ?? (total + tax + fee);
                                const displayNet = t.action === 'BUY' ? totalCost : netIncome;
                                return `
                                <div class="trade-item ${t.action === 'BUY' ? 'trade-buy' : 'trade-sell'}">
                                    <div style="display: flex; justify-content: space-between;">
                                        <span class="trade-action ${t.action.toLowerCase()}">${t.action}</span>
                                        <span>${escapeHtml(displayTicker(t.stock))}</span>
                                        <span>${t.quantity} 股 @ ${Number(t.price).toFixed(2)}</span>
                                        <span>${t.date.split(' ')[0]}</span>
                                    </div>
                                    ${hasCost ? `
                                    <div class="trade-cost">
                                        成交: ${formatNumber(Math.round(total))} |
                                        <span class="tax-fee">稅: ${formatNumber(Math.round(tax))}</span> |
                                        <span class="tax-fee">費: ${formatNumber(Math.round(fee))}</span> |
                                        <span class="net">${t.action === 'BUY' ? '總成本' : '實收'}: ${formatNumber(Math.round(displayNet))}</span>
                                    </div>
                                    ` : ''}
                                </div>
                                `;
                            }).join('') || '<div style="color: #8b949e;">尚無交易</div>'}
                        </div>
                    </div>
                `;
            }

            document.getElementById('bots').innerHTML = html;
        }

        function renderStocks(stockData, stockList) {
            // 依照代號排序（去 .TW/.TWO 後比較，讓上檔股跟上市股依代號混排）
            const sortedList = [...stockList].sort((a, b) => {
                const ac = displayTicker(a), bc = displayTicker(b);
                return ac < bc ? -1 : ac > bc ? 1 : 0;
            });
            let html = '';

            for (const symbol of sortedList) {
                const prices = Array.isArray(stockData[symbol]) ? stockData[symbol] : [];
                // 找到最後一個有效價格
                let latest = null;
                for (let i = prices.length - 1; i >= 0; i--) {
                    if (prices[i].close != null) {
                        latest = prices[i];
                        break;
                    }
                }
                if (!latest) {
                    html += `
                        <a href="/stock/stock_history.php?symbol=${encodeURIComponent(symbol)}" target="_blank" class="stock-card">
                            <div class="stock-header">
                                <span class="stock-symbol-title">${escapeHtml(displayTicker(symbol))}</span>
                                <span class="stock-price">--</span>
                            </div>
                            <div class="signal-box none">尚無行情資料，請執行 autoloop 更新</div>
                        </a>
                    `;
                    continue;
                }

                // 過濾有效價格（至少需要 5 天數據）
                const validCloses = prices.map(p => p.close).filter(c => c != null);
                if (validCloses.length < 5) {
                    html += `
                        <a href="/stock/stock_history.php?symbol=${encodeURIComponent(symbol)}" target="_blank" class="stock-card">
                            <div class="stock-header">
                                <span class="stock-symbol-title">${escapeHtml(displayTicker(symbol))}</span>
                                <span class="stock-price">${Number(latest.close).toFixed(2)}</span>
                            </div>
                            <div class="signal-box none">行情資料不足，等待更多資料</div>
                        </a>
                    `;
                    continue;
                }

                const closes = validCloses;
                const ma5 = closes.slice(-5).reduce((a, b) => a + b, 0) / 5;
                const ma20 = closes.slice(-20).reduce((a, b) => a + b, 0) / 20;

                // RSI 計算
                let gains = 0, losses = 0;
                for (let i = Math.max(0, closes.length - 14); i < closes.length - 1; i++) {
                    const diff = closes[i + 1] - closes[i];
                    if (diff > 0) gains += diff;
                    else losses += Math.abs(diff);
                }
                const rsi = losses === 0 ? 100 : 100 - (100 / (1 + gains / losses));

                // KD 計算
                let k = 50, d = 50;
                if (prices.length >= 9) {
                    for (let i = 8; i < prices.length; i++) {
                        const window = prices.slice(Math.max(0, i - 8), i + 1);
                        const highs = window.map(p => p.high).filter(h => h != null);
                        const lows = window.map(p => p.low).filter(l => l != null);
                        const cw = window.map(p => p.close).filter(c => c != null);
                        if (highs.length && lows.length && cw.length) {
                            const hh = Math.max(...highs), ll = Math.min(...lows), c = cw[cw.length - 1];
                            const rsv = hh === ll ? 50 : ((c - ll) / (hh - ll)) * 100;
                            k = k * (2 / 3) + rsv * (1 / 3);
                            d = d * (2 / 3) + k * (1 / 3);
                        }
                    }
                }

                // 產生買賣訊號（檢測穿越事件）
                const signals = [];
                
                // MA 穿越：需要歷史資料檢測
                // 假設Dashboard只顯示當下狀態，改用「排列」而非「交叉」
                if (ma5 > ma20) signals.push('MA5>MA20');
                else if (ma5 < ma20) signals.push('MA5<MA20');
                
                // RSI 超買超賣
                if (rsi < 30) signals.push('RSI超賣');
                else if (rsi > 70) signals.push('RSI超買');
                
                // KD 超買超賣
                if (k < 20) signals.push('KD超賣');
                else if (k > 80) signals.push('KD超買');

                let signalClass = 'none';
                let signalText = '無明顯訊號';
                if (signals.length > 0) {
                    signalText = signals.join(' | ');
                    // 有超賣訊號或MA5>MA20視為偏多
                    if (signals.some(s => s.includes('超賣')) || signals.includes('MA5>MA20')) {
                        signalClass = 'buy';
                    } else {
                        signalClass = 'sell';
                    }
                }

                // 與前一交易日比較：嚴格只看 prices[len-2]，不跳過 null（後端已從 TWSE 自動補足）
                let changeVal = null, changePct = null, changeClass = 'flat', changeText = '--';
                if (prices.length >= 2) {
                    const prevPrice = prices[prices.length - 2];
                    if (prevPrice && prevPrice.close != null && latest.close != null && prevPrice.close !== 0) {
                        changeVal = latest.close - prevPrice.close;
                        changePct = (changeVal / prevPrice.close) * 100;
                        if (changeVal > 0) {
                            changeClass = 'up';
                            changeText = `+${changeVal.toFixed(2)} (+${changePct.toFixed(2)}%)`;
                        } else if (changeVal < 0) {
                            changeClass = 'down';
                            changeText = `${changeVal.toFixed(2)} (${changePct.toFixed(2)}%)`;
                        } else {
                            changeClass = 'flat';
                            changeText = `0.00 (0.00%)`;
                        }
                    }
                }

                html += `
                    <a href="/stock/stock_history.php?symbol=${encodeURIComponent(symbol)}" target="_blank" class="stock-card">
                        <div class="stock-header">
                            <span class="stock-symbol-title">${escapeHtml(displayTicker(symbol))}</span>
                            <div class="stock-price-block">
                                <span class="stock-price">${latest.close.toFixed(2)}</span>
                                <span class="stock-change ${changeClass}">${escapeHtml(changeText)}</span>
                            </div>
                        </div>
                        <div class="stock-indicators">
                            <div class="indicator-ma">MA5: ${ma5.toFixed(2)} / MA20: ${ma20.toFixed(2)}</div>
                            <div class="indicator">
                                <div class="indicator-label">RSI</div>
                                <div class="indicator-value" style="color: ${rsi > 70 ? '#f85149' : rsi < 30 ? '#3fb950' : '#c9d1d9'}">${rsi.toFixed(1)}</div>
                            </div>
                            <div class="indicator">
                                <div class="indicator-label">KD</div>
                                <div class="indicator-value" style="color: ${k > 80 ? '#f85149' : k < 20 ? '#3fb950' : '#c9d1d9'}">${k.toFixed(0)}/${d.toFixed(0)}</div>
                            </div>
                            <div class="indicator">
                                <div class="indicator-label">成交量</div>
                                <div class="indicator-value">${(latest.volume / 1000).toFixed(0)}K</div>
                            </div>
                        </div>
                        <div class="signal-box ${signalClass}">${escapeHtml(signalText)}</div>
                    </a>
                `;
            }

            document.getElementById('stocks').innerHTML = html || '<div style="color: #8b949e; padding: 20px;">無股票資料</div>';
        }

        // 交易由 autoloop.bat 排程執行，dashboard 不再提供手動觸發按鈕
        function resetAutoAccounts() {
            if (!confirm('確定要清空自動帳戶嗎？\n\n策略1、策略2 會重置為現金 NT$ 5,000,000，庫存與交易紀錄全部清空。\n手動帳戶不受影響。')) return;
            fetch('/stock/manual_trade.php?action=api_reset_auto')
                .then(r => r.json())
                .then(data => {
                    if (data.ok) {
                        alert(data.message);
                        loadData();
                    } else {
                        alert('重置失敗: ' + data.error);
                    }
                })
                .catch(err => alert('重置失敗: ' + err));
        }

        function showModal(title, message, descHtml = '') {
            document.getElementById('modalTitle').textContent = title;
            document.getElementById('modalMessage').textContent = message;
            document.getElementById('snapshotDesc').innerHTML = descHtml;
            document.getElementById('snapshotModal').style.display = 'flex';
        }

        function closeModal() {
            document.getElementById('snapshotModal').style.display = 'none';
        }

        function saveSnapshot() {
            const desc = prompt('請輸入快照說明（選填）：');
            if (desc === null) return;
            
            const url = '/stock/stock_trader.php?snapshot=1' + (desc ? '&desc=' + encodeURIComponent(desc) : '');
            fetch(url)
                .then(r => r.text())
                .then(text => {
                    if (text.includes('快照完成') || text.includes('快照已儲存')) {
                        showModal('暫存成功', '試算結果已暫存，可以隨時還原。');
                    } else {
                        showModal('暫存失敗', '暫存失敗：' + text);
                    }
                })
                .catch(err => {
                    showModal('暫存失敗', '網路錯誤：' + err);
                });
        }

        function restoreSnapshot() {
            fetch('/stock/data/snapshot.json')
                .then(r => r.json())
                .then(snapshot => {
                    const time = snapshot.timestamp || '未知';
                    const desc = snapshot.description || '無';
                    const portfolioCount = Object.keys(snapshot.portfolio || {}).length;
                    const analysisCount = Object.keys(snapshot.analysis || {}).length;
                    
                    const descHtml = `
                        <div style="background: #21262d; padding: 15px; border-radius: 8px; text-align: left;">
                            <div style="margin-bottom: 10px;"><strong style="color: #8b949e;">快照時間:</strong> <span>${escapeHtml(time)}</span></div>
                            <div style="margin-bottom: 10px;"><strong style="color: #8b949e;">說明:</strong> <span>${escapeHtml(desc)}</span></div>
                            <div style="margin-bottom: 10px;"><strong style="color: #8b949e;">包含:</strong> <span>${portfolioCount} 個策略投資組合、${analysisCount} 檔股票分析</span></div>
                        </div>
                        <div style="margin-top: 15px; color: #f85149; font-size: 14px;">
                            警告：還原會覆蓋目前的交易資料！
                        </div>
                        <div style="text-align: center; margin-top: 20px;">
                            <button class="btn btn-primary" onclick="confirmRestore()">確認還原</button>
                            <button class="btn" style="background: #30363d; margin-left: 10px;" onclick="closeModal()">取消</button>
                        </div>
                    `;
                    showModal('還原試算', '即將還原以下快照：', descHtml);
                    window.pendingRestore = true;
                })
                .catch(err => {
                    showModal('無法還原', '找不到已暂存的試算結果。請先執行「暫存試算」。');
                });
        }

        function confirmRestore() {
            closeModal();
            fetch('/stock/stock_trader.php?restore=1')
                .then(r => r.text())
                .then(text => {
                    if (text.includes('還原完成')) {
                        showModal('還原成功', '試算結果已還原，請重新整理頁面。');
                        loadData();
                    } else {
                        showModal('還原失敗', text);
                    }
                })
                .catch(err => {
                    showModal('還原失敗', '網路錯誤：' + err);
                });
        }

        function loadSnapshot() {
            fetch('/stock/data/snapshot.json')
                .then(r => r.json())
                .then(snapshot => {
                    // 在背景載入快照資訊，不打擾使用者
                })
                .catch(() => {
                    // 無快照，正常運作
                });
        }

        loadData();
        loadSnapshot();
        setInterval(loadData, 60000);

        /* ========== 即時股價更新 (只更新不打交易) ========== */
        const RT_KEY = 'stock_realtime_v1';
        const rtToggle = document.getElementById('realtimeToggle');
        const rtIntervalSel = document.getElementById('realtimeInterval');
        const rtStatus = document.getElementById('realtimeStatus');
        let rtTimer = null;      // setInterval handle
        let rtCountdown = null;  // 倒數 setInterval
        let rtNextAt = 0;        // 下次更新時間戳 (ms)
        let rtInFlight = false;  // 避免重複請求

        function loadRtPref() {
            try {
                const raw = localStorage.getItem(RT_KEY);
                if (!raw) return { enabled: false, interval: 300000 };
                const p = JSON.parse(raw);
                return {
                    enabled: !!p.enabled,
                    interval: [60000,180000,300000,600000,900000].includes(p.interval) ? p.interval : 300000
                };
            } catch (e) { return { enabled: false, interval: 300000 }; }
        }
        function saveRtPref() {
            localStorage.setItem(RT_KEY, JSON.stringify({
                enabled: rtToggle.checked,
                interval: parseInt(rtIntervalSel.value, 10)
            }));
        }
        function setStatus(text, cls='') {
            rtStatus.textContent = text;
            rtStatus.className = 'rt-status' + (cls ? ' ' + cls : '');
        }
        function pad2(n) { return n < 10 ? '0' + n : '' + n; }
        function fmtClock(ms) {
            const d = new Date(ms);
            return pad2(d.getHours()) + ':' + pad2(d.getMinutes()) + ':' + pad2(d.getSeconds());
        }
        function startCountdown() {
            stopCountdown();
            rtCountdown = setInterval(() => {
                if (!rtToggle.checked) return;
                const left = Math.max(0, rtNextAt - Date.now());
                const m = Math.floor(left / 60000);
                const s = Math.floor((left % 60000) / 1000);
                setStatus(`下次更新: ${fmtClock(rtNextAt)} (剩 ${pad2(m)}:${pad2(s)})`);
            }, 1000);
        }
        function stopCountdown() {
            if (rtCountdown) { clearInterval(rtCountdown); rtCountdown = null; }
        }
        async function runRealtimeUpdate() {
            if (rtInFlight) return;
            if (!navigator.onLine) {
                setStatus('離線中，下次重連時更新', 'error');
                rtNextAt = Date.now() + 60000;
                return;
            }
            rtInFlight = true;
            setStatus('正在抓取 Yahoo Finance 股價...', 'updating');
            const t0 = Date.now();
            try {
                const r = await fetch('/stock/stock_trader.php?update=1', { cache: 'no-store' });
                if (!r.ok) throw new Error('HTTP ' + r.status);
                const txt = await r.text();
                const sec = ((Date.now() - t0) / 1000).toFixed(1);
                // 從輸出抓「最新價格」數量當作成功指標
                const m = txt.match(/最新價格: ([0-9.]+)/g);
                const cnt = m ? m.length : 0;
                if (cnt === 0) {
                    setStatus('更新完成但無股票回傳，請檢查 Yahoo Finance', 'error');
                } else {
                    setStatus(`已更新 ${cnt} 檔 (${sec}s) · 下次: ${fmtClock(rtNextAt)}`, 'ok');
                }
                loadData(); // 立即重新讀本地 JSON 顯示新價
            } catch (err) {
                setStatus('更新失敗: ' + err.message + '，下次重試', 'error');
            } finally {
                rtInFlight = false;
                scheduleNext();
            }
        }
        function scheduleNext() {
            if (!rtToggle.checked) return;
            const ms = parseInt(rtIntervalSel.value, 10);
            rtNextAt = Date.now() + ms;
            startCountdown();
        }
        function startRealtime() {
            saveRtPref();
            rtIntervalSel.disabled = false;
            setStatus('啟用中...', 'updating');
            // 立即打一次 + 排程下一次
            runRealtimeUpdate();
            rescheduleTimer();
        }
        function rescheduleTimer() {
            if (rtTimer) { clearInterval(rtTimer); rtTimer = null; }
            rtTimer = setInterval(() => {
                if (!rtToggle.checked) return;
                runRealtimeUpdate();
            }, parseInt(rtIntervalSel.value, 10));
        }
        function stopRealtime() {
            saveRtPref();
            if (rtTimer) { clearInterval(rtTimer); rtTimer = null; }
            stopCountdown();
            rtIntervalSel.disabled = true;
            setStatus('已關閉');
        }
        rtToggle.addEventListener('change', () => {
            if (rtToggle.checked) startRealtime();
            else stopRealtime();
        });
        rtIntervalSel.addEventListener('change', () => {
            saveRtPref();
            if (rtToggle.checked) {
                // 變更間隔：重新排程計時器 + 重設下次更新時間
                rescheduleTimer();
                scheduleNext();
            }
        });
        // 初始狀態
        (function initRealtime() {
            const pref = loadRtPref();
            rtIntervalSel.value = String(pref.interval);
            rtIntervalSel.disabled = true;  // toggle 開啟前不能調
            rtToggle.checked = pref.enabled;
            if (pref.enabled) startRealtime();
        })();
    </script>
</body>
</html>
