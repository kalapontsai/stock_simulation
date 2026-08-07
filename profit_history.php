<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>獲利歷史 - 股市模擬投資</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #0d1117; color: #c9d1d9; padding: 20px; }
        .container { max-width: 1200px; margin: 0 auto; }
        h1 { text-align: center; margin-bottom: 10px; color: #58a6ff; }
        .subtitle { text-align: center; color: #8b949e; margin-bottom: 30px; }
        .chart-container { background: #161b22; border-radius: 12px; padding: 20px; border: 1px solid #30363d; margin-bottom: 20px; }
        .back-link { display: inline-block; background: #238636; color: white; padding: 10px 20px; border-radius: 6px; text-decoration: none; margin-top: 20px; }
        .back-link:hover { background: #2ea043; }
        .stats-row { display: flex; gap: 20px; margin-bottom: 20px; flex-wrap: wrap; }
        .stat-card { background: #161b22; border-radius: 12px; padding: 20px; border: 1px solid #30363d; flex: 1; min-width: 200px; }
        .stat-label { color: #8b949e; font-size: 14px; margin-bottom: 10px; }
        .stat-value { font-size: 28px; font-weight: bold; }
        .stat-value.positive { color: #3fb950; }
        .stat-value.negative { color: #f85149; }
        .query-toolbar { display: flex; gap: 15px; align-items: end; margin-bottom: 20px; flex-wrap: wrap; }
        .query-field { display: flex; flex-direction: column; gap: 5px; }
        .query-field label { color: #8b949e; font-size: 13px; }
        .query-field select, .query-field input { background: #0d1117; color: #c9d1d9; border: 1px solid #30363d; border-radius: 6px; padding: 8px 12px; font-size: 14px; min-width: 140px; }
        .query-field select:focus, .query-field input:focus { outline: none; border-color: #58a6ff; }
        .query-summary { display: grid; grid-template-columns: repeat(4, 1fr); gap: 15px; margin-bottom: 20px; padding: 15px; background: #21262d; border-radius: 8px; }
        .query-summary-item { text-align: center; }
        .query-summary-label { color: #8b949e; font-size: 12px; margin-bottom: 5px; }
        .query-summary-value { font-size: 18px; font-weight: bold; color: #c9d1d9; }
        .query-summary-value.positive { color: #3fb950; }
        .query-summary-value.negative { color: #f85149; }
        .query-summary-value.cost { color: #d29922; }
        .trade-table { width: 100%; border-collapse: collapse; }
        .trade-table th { background: #30363d; padding: 10px; text-align: left; font-size: 13px; color: #8b949e; }
        .trade-table td { padding: 8px 10px; border-bottom: 1px solid #30363d; font-size: 13px; }
        .trade-buy td { border-left: 3px solid #3fb950; }
        .trade-sell td { border-left: 3px solid #f85149; }
        .trade-table .col-action-buy { color: #3fb950; font-weight: bold; }
        .trade-table .col-action-sell { color: #f85149; font-weight: bold; }
        .trade-table .col-cost { color: #d29922; }
        .trade-table .col-net { font-weight: 600; }
        .no-result { color: #8b949e; padding: 20px; text-align: center; }
    </style>
</head>
<body>
    <div class="container">
        <h1>獲利歷史</h1>
        <p class="subtitle">最後更新: <span id="updateTime">載入中...</span></p>

        <div class="stats-row" id="stats"></div>

        <div class="chart-container">
            <canvas id="profitChart"></canvas>
        </div>

        <a href="/stock/" class="back-link">返回 Dashboard</a>
    </div>

    <div class="container" style="margin-top: 30px;">
        <h2>交易查詢</h2>
        <p class="subtitle">依策略 + 日期範圍查詢每日損益與交易明細（整合稅費）</p>

        <div class="query-toolbar">
            <div class="query-field">
                <label>策略</label>
                <select id="qStrategy">
                    <option value="策略1">策略1 (MA + RSI)</option>
                    <option value="策略2">策略2 (KD)</option>
                    <option value="__all" selected>全部</option>
                </select>
            </div>
            <div class="query-field">
                <label>起日</label>
                <input type="date" id="qStart">
            </div>
            <div class="query-field">
                <label>訖日</label>
                <input type="date" id="qEnd">
            </div>
            <div class="query-field">
                <label>&nbsp;</label>
                <button class="btn" style="background:#238636;color:white;padding:8px 16px;border:none;border-radius:6px;cursor:pointer;" onclick="runQuery()">查詢</button>
            </div>
            <div class="query-field">
                <label>&nbsp;</label>
                <button class="btn" style="background:#30363d;color:white;padding:8px 16px;border:none;border-radius:6px;cursor:pointer;" onclick="resetQuery()">重置</button>
            </div>
        </div>

        <div id="querySummary" class="query-summary"></div>
        <div id="queryResult" class="chart-container">
            <p class="no-result">請選擇條件後點查詢</p>
        </div>
    </div>

    <div class="container" style="margin-top: 30px;">
        <h2>每日交易分析</h2>
        <p class="subtitle">各股票的技術指標與策略判斷結果</p>
        <div id="analysisContent" class="chart-container">
            <p>載入中...</p>
        </div>
    </div>

    <script>
        const strategies = {
            '策略1': { label: 'MA + RSI 混合策略', color: '#58a6ff' },
            '策略2': { label: 'RSI 均值回歸策略', color: '#f778ba' }
        };

        async function loadProfitHistory() {
            try {
                const [historyRes, stockRes] = await Promise.all([
                    fetch('/stock/profit_history.json'),
                    fetch('/stock/stock_data.json')
                ]);
                
                const history = await historyRes.json();
                const stockData = await stockRes.json();
                
                // 計算目前獲利%
                const portfolios = await fetch('/stock/portfolio.json').then(r => r.json());
                
                let statsHtml = '';
                const initialCapital = 1000000;
                
                for (const [name, portfolio] of Object.entries(portfolios)) {
                    let currentValue = portfolio.cash;
                    for (const [stock, qty] of Object.entries(portfolio.holdings || {})) {
                        if (qty > 0 && stockData[stock]) {
                            const price = stockData[stock][stockData[stock].length - 1]?.close || 0;
                            currentValue += qty * price;
                        }
                    }
                    
                    const profit = currentValue - initialCapital;
                    const profitRate = (profit / initialCapital) * 100;
                    const strategyInfo = strategies[name] || { label: name, color: '#888' };
                    
                    statsHtml += `
                        <div class="stat-card">
                            <div class="stat-label">${name} (${strategyInfo.label})</div>
                            <div class="stat-value ${profitRate >= 0 ? 'positive' : 'negative'}">
                                ${profitRate >= 0 ? '+' : ''}${profitRate.toFixed(2)}%
                            </div>
                        </div>
                    `;
                }
                
                document.getElementById('stats').innerHTML = statsHtml;
                document.getElementById('updateTime').textContent = new Date().toLocaleString('zh-TW');

                // 準備圖表資料
                const labels = [];
                const data1 = [];
                const data2 = [];
                
                // 收集所有日期
                const allDates = new Set();
                for (const s of Object.values(history)) {
                    for (const d of Object.keys(s)) allDates.add(d);
                }
                
                const sortedDates = Array.from(allDates).sort();
                
                for (const date of sortedDates.slice(-30)) {
                    labels.push(date);
                    data1.push(history['策略1']?.[date] || null);
                    data2.push(history['策略2']?.[date] || null);
                }

                // 繪製圖表
                const ctx = document.getElementById('profitChart').getContext('2d');
                new Chart(ctx, {
                    type: 'line',
                    data: {
                        labels: labels,
                        datasets: [
                            {
                                label: '策略1 (MA+RSI)',
                                data: data1,
                                borderColor: strategies['策略1'].color,
                                backgroundColor: strategies['策略1'].color + '20',
                                fill: true,
                                tension: 0.3
                            },
                            {
                                label: '策略2 (RSI)',
                                data: data2,
                                borderColor: strategies['策略2'].color,
                                backgroundColor: strategies['策略2'].color + '20',
                                fill: true,
                                tension: 0.3
                            }
                        ]
                    },
                    options: {
                        responsive: true,
                        plugins: {
                            legend: { labels: { color: '#c9d1d9' } },
                            tooltip: { 
                                mode: 'index',
                                intersect: false,
                                callbacks: {
                                    label: (ctx) => `${ctx.dataset.label}: ${ctx.parsed.y?.toFixed(2) ?? '-'}%`
                                }
                            }
                        },
                        scales: {
                            x: { 
                                ticks: { color: '#8b949e' },
                                grid: { color: '#30363d' }
                            },
                            y: { 
                                ticks: { color: '#8b949e' },
                                grid: { color: '#30363d' },
                                title: { display: true, text: '獲利 %', color: '#8b949e' }
                            }
                        },
                        interaction: {
                            mode: 'nearest',
                            axis: 'x',
                            intersect: false
                        }
                    }
                });
            } catch (e) {
                console.error('載入失敗:', e);
                document.getElementById('stats').innerHTML = '<p style="color:#f85149;">無法載入資料，請先執行交易產生歷史紀錄</p>';
            }
        }

        loadProfitHistory();
        loadDailyAnalysis();
        setInterval(loadProfitHistory, 60000);

        // ============================================
        // 交易查詢（依策略 + 日期範圍，整合稅費）
        // ============================================
        let _queryCache = null;

        async function ensureQueryCache() {
            if (_queryCache) return _queryCache;
            const [ph, pf] = await Promise.all([
                fetch('/stock/profit_history.json').then(r => r.json()),
                fetch('/stock/portfolio.json').then(r => r.json())
            ]);
            _queryCache = { profitHistory: ph, portfolio: pf };
            return _queryCache;
        }

        function fmtDate(d) {
            // d 是 'YYYY-MM-DD' 或 'YYYY-MM-DD HH:MM:SS' 或 Date
            if (!d) return '';
            if (typeof d === 'string') return d.split(' ')[0];
            return d.toISOString().slice(0, 10);
        }

        async function runQuery() {
            const strategySel = document.getElementById('qStrategy').value;
            const startDate = document.getElementById('qStart').value;
            const endDate = document.getElementById('qEnd').value;

            const { profitHistory, portfolio } = await ensureQueryCache();

            // 1) 決定查詢的策略列表
            const strategyList = strategySel === '__all' ? Object.keys(profitHistory) : [strategySel];

            // 2) 收集該策略在範圍內的每日損益率
            const dailyRows = [];
            for (const strat of strategyList) {
                const hist = profitHistory[strat] || {};
                for (const [date, rate] of Object.entries(hist)) {
                    if (startDate && date < startDate) continue;
                    if (endDate && date > endDate) continue;
                    dailyRows.push({ date, strategy: strat, profitRate: rate });
                }
            }
            dailyRows.sort((a, b) => (a.date + a.strategy).localeCompare(b.date + b.strategy));

            // 3) 收集交易明細（來自 portfolio.trades，按 date 前缀匹配）
            const tradeRows = [];
            for (const strat of strategyList) {
                const pf = portfolio[strat];
                if (!pf || !Array.isArray(pf.trades)) continue;
                for (const t of pf.trades) {
                    const tradeDate = fmtDate(t.date);
                    if (startDate && tradeDate < startDate) continue;
                    if (endDate && tradeDate > endDate) continue;
                    tradeRows.push({ ...t, strategy: strat, tradeDate });
                }
            }
            tradeRows.sort((a, b) => (b.tradeDate + b.date).localeCompare(a.tradeDate + a.date));

            // 4) 計算 summary
            const days = dailyRows.length;
            const avgRate = days > 0 ? dailyRows.reduce((s, r) => s + r.profitRate, 0) / days : 0;
            const totalTrades = tradeRows.length;
            const totalCost = tradeRows.reduce((s, t) => s + ((t.tax ?? 0) + (t.fee ?? 0)), 0);

            // 5) 渲染 summary
            const avgClass = avgRate >= 0 ? 'positive' : 'negative';
            document.getElementById('querySummary').innerHTML = `
                <div class="query-summary-item">
                    <div class="query-summary-label">查詢天數</div>
                    <div class="query-summary-value">${days} 天</div>
                </div>
                <div class="query-summary-item">
                    <div class="query-summary-label">平均日損益</div>
                    <div class="query-summary-value ${avgClass}">${avgRate >= 0 ? '+' : ''}${avgRate.toFixed(2)}%</div>
                </div>
                <div class="query-summary-item">
                    <div class="query-summary-label">交易筆數</div>
                    <div class="query-summary-value">${totalTrades} 筆</div>
                </div>
                <div class="query-summary-item">
                    <div class="query-summary-label">累計成本</div>
                    <div class="query-summary-value cost">${formatNumber(Math.round(totalCost))}</div>
                </div>
            `;

            // 6) 渲染交易表格
            const resultDiv = document.getElementById('queryResult');
            if (tradeRows.length === 0 && dailyRows.length === 0) {
                resultDiv.innerHTML = '<p class="no-result">條件內查無資料</p>';
                return;
            }

            let html = '<table class="trade-table"><thead><tr>';
            html += '<th>日期</th><th>時間</th><th>策略</th><th>動作</th><th>股名</th>';
            html += '<th style="text-align:right;">數量</th><th style="text-align:right;">價格</th>';
            html += '<th style="text-align:right;">成交</th><th style="text-align:right;">稅</th><th style="text-align:right;">費</th>';
            html += '<th style="text-align:right;">淨額</th><th>備註</th>';
            html += '</tr></thead><tbody>';

            for (const t of tradeRows) {
                const total = t.total ?? (t.price * t.quantity);
                const tax = t.tax ?? 0;
                const fee = t.fee ?? 0;
                const net = t.action === 'BUY' ? (t.total_cost ?? (total + tax + fee)) : (t.net_income ?? (total - tax - fee));
                const timePart = t.date.split(' ')[1] || '';
                const cls = t.action === 'BUY' ? 'trade-buy' : 'trade-sell';
                const actionCls = t.action === 'BUY' ? 'col-action-buy' : 'col-action-sell';
                const reason = t.reason || '';
                html += `<tr class="${cls}">
                    <td>${t.tradeDate}</td>
                    <td>${timePart}</td>
                    <td>${escapeHtml(t.strategy)}</td>
                    <td class="${actionCls}">${t.action}</td>
                    <td><b>${escapeHtml(t.stock || t.symbol || '')}</b></td>
                    <td style="text-align:right;">${t.quantity}</td>
                    <td style="text-align:right;">${Number(t.price).toFixed(2)}</td>
                    <td style="text-align:right;">${formatNumber(Math.round(total))}</td>
                    <td style="text-align:right;" class="col-cost">${formatNumber(Math.round(tax))}</td>
                    <td style="text-align:right;" class="col-cost">${formatNumber(Math.round(fee))}</td>
                    <td style="text-align:right;" class="col-net">${formatNumber(Math.round(net))}</td>
                    <td>${escapeHtml(reason)}</td>
                </tr>`;
            }

            if (tradeRows.length === 0) {
                html += '<tr><td colspan="12" class="no-result">條件內沒有交易紀錄（但有 ${days} 天的損益率）</td></tr>'.replace('${days}', days);
            }
            html += '</tbody></table>';
            resultDiv.innerHTML = html;
        }

        function resetQuery() {
            document.getElementById('qStrategy').value = '__all';
            document.getElementById('qStart').value = '';
            document.getElementById('qEnd').value = '';
            document.getElementById('querySummary').innerHTML = '';
            document.getElementById('queryResult').innerHTML = '<p class="no-result">請選擇條件後點查詢</p>';
        }

        function escapeHtml(str) {
            return String(str ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
        }

        async function loadDailyAnalysis() {
            try {
                const res = await fetch('/stock/daily_analysis.json');
                if (!res.ok) throw new Error('No analysis file');
                const data = await res.json();
                
                let html = '<table style="width:100%; border-collapse:collapse;">';
                html += '<tr style="background:#30363d;"><th style="padding:10px;text-align:left;">股票</th><th>價格</th><th>MA5</th><th>MA20</th><th>RSI</th><th>訊號</th><th>策略1</th><th>策略2</th></tr>';
                
                for (const [symbol, info] of Object.entries(data)) {
                    const signals = info.signals?.join(', ') || '-';
                    const s1 = info.strategies?.['策略1'] || {};
                    const s2 = info.strategies?.['策略2'] || {};
                    
                    const s1Text = s1.action === 'NONE' ? 
                        `<span style="color:#f85149;">${s1.reason}</span>` :
                        `<span style="color:#3fb950;">${s1.action} ${s1.quantity || ''}</span> ${s1.reason}`;
                    const s2Text = s2.action === 'NONE' ?
                        `<span style="color:#f85149;">${s2.reason}</span>` :
                        `<span style="color:#3fb950;">${s2.action} ${s2.quantity || ''}</span> ${s2.reason}`;
                    
                    html += `<tr style="border-bottom:1px solid #30363d;">
                        <td style="padding:10px;"><b>${symbol}</b></td>
                        <td style="text-align:center;">${info.price?.toFixed(2) || '-'}</td>
                        <td style="text-align:center;">${info.ma5 || '-'}</td>
                        <td style="text-align:center;">${info.ma20 || '-'}</td>
                        <td style="text-align:center;">${info.rsi || '-'}</td>
                        <td style="text-align:center;color:#f0883e;">${signals}</td>
                        <td style="text-align:center;">${s1Text}</td>
                        <td style="text-align:center;">${s2Text}</td>
                    </tr>`;
                }
                
                html += '</table>';
                document.getElementById('analysisContent').innerHTML = html;
            } catch (e) {
                document.getElementById('analysisContent').innerHTML = '<p style="color:#f85149;">尚無分析資料，請先執行交易</p>';
            }
        }
    </script>
</body>
</html>
