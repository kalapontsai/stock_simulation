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
        .subtitle { text-align: center; color: #8b949e; margin-bottom: 30px; }

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

        <div class="bots" id="bots"></div>

        <div class="market">
            <h2>市場概況</h2>
            <div class="stock-grid" id="stocks"></div>
        </div>

        <div style="text-align: center;">
            <button class="btn" onclick="runTrader()">手動執行交易</button>
            <button class="btn" style="background: #1f6feb;" onclick="saveSnapshot()">暫存試算</button>
            <button class="btn" style="background: #8957e5;" onclick="restoreSnapshot()">還原試算</button>
            <a href="/stock/indicator_settings.php" class="btn" style="background: #8957e5;" target="_blank">指標參數設定</a>
            <a href="/stock/profit_history.php" class="btn" style="background: #8957e5;" target="_blank">獲利歷史</a>
            <a href="/stock/stocks.php" class="btn" style="background: #1f6feb;" target="_blank">股票清單維護</a>
            <a href="/stock/data/stock_data.json" class="btn" style="background: #1f6feb;" target="_blank">歷史資料</a>
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

        function formatNumber(num) {
            return new Intl.NumberFormat('zh-TW').format(Math.round(num));
        }

        function loadData() {
            Promise.all([
                fetch('/stock/data/stock_data.json').then(r => r.json()),
                fetch('/stock/data/portfolio.json').then(r => r.json())
            ]).then(([stockData, portfolio]) => {
                renderPortfolio(portfolio, stockData);
                renderStocks(stockData);
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
            const initialCapital = 1000000;

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
                                    <span class="stock-symbol">${escapeHtml(stock)}</span>
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
                                        <span>${escapeHtml(t.stock)}</span>
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

        function renderStocks(stockData) {
            let html = '';

            for (const [symbol, prices] of Object.entries(stockData)) {
                // 找到最後一個有效價格
                let latest = null;
                for (let i = prices.length - 1; i >= 0; i--) {
                    if (prices[i].close != null) {
                        latest = prices[i];
                        break;
                    }
                }
                if (!latest) continue;

                // 過濾有效價格（至少需要 5 天數據）
                const validCloses = prices.map(p => p.close).filter(c => c != null);
                if (validCloses.length < 5) continue;

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

                html += `
                    <a href="/stock/stock_history.php?symbol=${encodeURIComponent(symbol)}" target="_blank" class="stock-card">
                        <div class="stock-header">
                            <span class="stock-symbol-title">${escapeHtml(symbol)}</span>
                            <span class="stock-price">${latest.close.toFixed(2)}</span>
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

        function runTrader() {
            fetch('/stock/stock_trader.php?run=1')
                .then(r => r.text())
                .then(text => {
                    alert('交易執行完成！請重新整理頁面查看結果。');
                    loadData();
                })
                .catch(err => {
                    alert('執行失敗: ' + err);
                });
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
    </script>
</body>
</html>