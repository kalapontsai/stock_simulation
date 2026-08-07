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
