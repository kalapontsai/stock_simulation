<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>股票歷史記錄 - <span id="symbolTitle"></span></title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #0d1117; color: #c9d1d9; padding: 20px; }
        .container { max-width: 1400px; margin: 0 auto; }
        
        .header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
        h1 { color: #58a6ff; }
        .stock-info { text-align: right; }
        .stock-price { font-size: 32px; font-weight: bold; }
        .stock-change { font-size: 18px; }
        .stock-change.positive { color: #3fb950; }
        .stock-change.negative { color: #f85149; }
        
        .controls { display: flex; gap: 10px; margin-bottom: 20px; flex-wrap: wrap; }
        .btn { background: #21262d; color: #c9d1d9; border: 1px solid #30363d; padding: 8px 16px; border-radius: 6px; cursor: pointer; }
        .btn:hover { background: #30363d; }
        .btn.active { background: #238636; color: white; border-color: #238636; }
        
        .chart-section { background: #161b22; border-radius: 12px; padding: 20px; margin-bottom: 20px; border: 1px solid #30363d; }
        .chart-title { color: #58a6ff; margin-bottom: 15px; font-size: 18px; }
        .chart-container { position: relative; height: 400px; }
        
        .volume-container { height: 200px; }
        
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 15px; margin-bottom: 20px; }
        .stat-card { background: #21262d; padding: 15px; border-radius: 8px; text-align: center; }
        .stat-label { color: #8b949e; font-size: 12px; margin-bottom: 5px; }
        .stat-value { font-size: 20px; font-weight: bold; }
        
        .indicator-legend { display: flex; gap: 20px; flex-wrap: wrap; margin-top: 10px; padding-top: 10px; border-top: 1px solid #30363d; }
        .legend-item { display: flex; align-items: center; gap: 5px; font-size: 12px; }
        .legend-color { width: 20px; height: 3px; border-radius: 2px; }
        
        .loading { text-align: center; padding: 50px; color: #8b949e; }
        .error { background: #f8514920; border: 1px solid #f85149; padding: 20px; border-radius: 8px; color: #f85149; }
        
        @media (max-width: 768px) {
            .header { flex-direction: column; align-items: flex-start; gap: 10px; }
            .stock-info { text-align: left; }
            .chart-container { height: 300px; }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1><span id="symbolTitle"></span> 歷史記錄</h1>
            <div class="stock-info">
                <div class="stock-price" id="currentPrice">--</div>
                <div class="stock-change" id="priceChange">--</div>
            </div>
        </div>
        
        <div class="controls">
            <button class="btn active" onclick="setRange(30)">一個月</button>
            <button class="btn" onclick="setRange(60)">兩個月</button>
            <button class="btn" onclick="setRange(90)">三個月</button>
            <button class="btn" onclick="setRange(180)">半年</button>
            <button class="btn" onclick="setRange(365)">一年</button>
        </div>
        
        <div class="stats-grid" id="statsGrid"></div>
        
        <div class="chart-section">
            <div class="chart-title">股價走勢 + 技術指標</div>
            <div class="chart-container">
                <canvas id="priceChart"></canvas>
            </div>
            <div class="indicator-legend" id="priceLegend"></div>
        </div>
        
        <div class="chart-section">
            <div class="chart-title">成交量</div>
            <div class="chart-container volume-container">
                <canvas id="volumeChart"></canvas>
            </div>
        </div>
        
        <div class="chart-section">
            <div class="chart-title">RSI (相對強度指標)</div>
            <div class="chart-container">
                <canvas id="rsiChart"></canvas>
            </div>
        </div>
        
        <div class="chart-section">
            <div class="chart-title">KD 隨機指標</div>
            <div class="chart-container">
                <canvas id="kdChart"></canvas>
            </div>
        </div>
        
        <div class="chart-section">
            <div class="chart-title">MACD</div>
            <div class="chart-container">
                <canvas id="macdChart"></canvas>
            </div>
        </div>
        
        <div class="chart-section">
            <div class="chart-title">布林通道 (Bollinger Bands)</div>
            <div class="chart-container">
                <canvas id="bbChart"></canvas>
            </div>
        </div>
        
        <div style="text-align: center; margin-top: 20px;">
            <button class="btn" onclick="window.close()">關閉視窗</button>
        </div>
    </div>

    <script>
        const urlParams = new URLSearchParams(window.location.search);
        const symbol = urlParams.get('symbol') || '0050.TW';
        const displayTicker = (value) => String(value || '').replace(/\.(TW|TWO)$/, '');
        document.getElementById('symbolTitle').textContent = displayTicker(symbol);
        
        let allData = null;
        let currentRange = 30;
        let charts = {};
        
        const colors = {
            price: '#58a6ff',
            ma5: '#ffd700',
            ma10: '#ff7f50',
            ma20: '#32cd32',
            ma60: '#ff69b4',
            volume: '#4a9fff',
            rsi: '#9acd32',
            k: '#00bfff',
            d: '#ff6347',
            macd: '#00ced1',
            signal: '#ff4500',
            histogram: '#1e90ff',
            bbUpper: '#ff6b6b',
            bbMiddle: '#ffd700',
            bbLower: '#6bff6b'
        };
        
        async function loadData() {
            try {
                const response = await fetch(`/stock/stock_history_api.php?symbol=${symbol}`);
                const data = await response.json();
                
                if (data.error) {
                    document.body.innerHTML = `<div class="error">${data.error}</div>`;
                    return;
                }
                
                allData = data;
                renderStats();
                renderCharts();
            } catch (e) {
                document.body.innerHTML = `<div class="error">載入失敗: ${e.message}</div>`;
            }
        }
        
        function renderStats() {
            const closes = allData.price.close;
            const volumes = allData.volume;
            const ma20 = allData.indicators.ma20;
            const rsi = allData.indicators.rsi;
            const k = allData.indicators.kd_k;
            const d = allData.indicators.kd_d;
            
            const latestIdx = closes.length - 1;
            const prevIdx = closes.length - 2;
            
            const latestClose = closes[latestIdx];
            const prevClose = closes[prevIdx];
            const change = latestClose - prevClose;
            const changePercent = (change / prevClose) * 100;
            
            document.getElementById('currentPrice').textContent = latestClose.toFixed(2);
            const changeEl = document.getElementById('priceChange');
            changeEl.textContent = `${change >= 0 ? '+' : ''}${change.toFixed(2)} (${changePercent.toFixed(2)}%)`;
            changeEl.className = `stock-change ${change >= 0 ? 'positive' : 'negative'}`;
            
            // 找到最新有效值
            const lastValid = (arr) => {
                for (let i = arr.length - 1; i >= 0; i--) {
                    if (arr[i] != null) return arr[i];
                }
                return null;
            };
            
            const statsHtml = `
                <div class="stat-card">
                    <div class="stat-label">MA5</div>
                    <div class="stat-value">${lastValid(allData.indicators.ma5)?.toFixed(2) || '--'}</div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">MA20</div>
                    <div class="stat-value">${lastValid(ma20)?.toFixed(2) || '--'}</div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">MA60</div>
                    <div class="stat-value">${lastValid(allData.indicators.ma60)?.toFixed(2) || '--'}</div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">RSI(14)</div>
                    <div class="stat-value" style="color: ${lastValid(rsi) > 70 ? '#f85149' : lastValid(rsi) < 30 ? '#3fb950' : '#c9d1d9'}">
                        ${lastValid(rsi)?.toFixed(1) || '--'}
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">KD</div>
                    <div class="stat-value" style="color: ${lastValid(k) > 80 ? '#f85149' : lastValid(k) < 20 ? '#3fb950' : '#c9d1d9'}">
                        ${lastValid(k)?.toFixed(0) || '--'}/${lastValid(d)?.toFixed(0) || '--'}
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">成交量</div>
                    <div class="stat-value">${(lastValid(volumes) / 1000).toFixed(0)}K</div>
                </div>
            `;
            document.getElementById('statsGrid').innerHTML = statsHtml;
        }
        
        function setRange(days) {
            currentRange = days;
            document.querySelectorAll('.controls .btn').forEach(btn => {
                btn.classList.remove('active');
                if (btn.textContent.includes(days === 30 ? '一個月' : days === 60 ? '兩個月' : days === 90 ? '三個月' : days === 180 ? '半年' : '一年')) {
                    btn.classList.add('active');
                }
            });
            renderCharts();
        }
        
        function sliceData(data) {
            const len = data.length;
            const start = Math.max(0, len - currentRange);
            return {
                labels: allData.dates.slice(start),
                data: data.slice(start)
            };
        }
        
        function renderCharts() {
            // 銷毀舊圖表
            Object.values(charts).forEach(chart => chart.destroy());
            charts = {};
            
            const priceData = sliceData(allData.price.close);
            const volumeData = sliceData(allData.volume);
            
            // 股價 + MA 圖
            charts.price = new Chart(document.getElementById('priceChart'), {
                type: 'line',
                data: {
                    labels: priceData.labels,
                    datasets: [
                        { label: '股價', data: priceData.data, borderColor: colors.price, backgroundColor: colors.price + '20', fill: true, tension: 0.1, pointRadius: 2 },
                        { label: 'MA5', data: sliceData(allData.indicators.ma5).data, borderColor: colors.ma5, tension: 0.1, pointRadius: 0, borderWidth: 1.5 },
                        { label: 'MA10', data: sliceData(allData.indicators.ma10).data, borderColor: colors.ma10, tension: 0.1, pointRadius: 0, borderWidth: 1.5 },
                        { label: 'MA20', data: sliceData(allData.indicators.ma20).data, borderColor: colors.ma20, tension: 0.1, pointRadius: 0, borderWidth: 1.5 },
                        { label: 'MA60', data: sliceData(allData.indicators.ma60).data, borderColor: colors.ma60, tension: 0.1, pointRadius: 0, borderWidth: 1.5 }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    interaction: { mode: 'index', intersect: false },
                    plugins: { legend: { display: false } },
                    scales: {
                        x: { ticks: { color: '#8b949e', maxTicksLimit: 10 }, grid: { color: '#30363d' } },
                        y: { ticks: { color: '#8b949e' }, grid: { color: '#30363d' } }
                    }
                }
            });
            
            // 成交量圖
            charts.volume = new Chart(document.getElementById('volumeChart'), {
                type: 'bar',
                data: {
                    labels: volumeData.labels,
                    datasets: [{
                        label: '成交量',
                        data: volumeData.data,
                        backgroundColor: volumeData.data.map((v, i) => {
                            const price = priceData.data[i];
                            const prevPrice = i > 0 ? priceData.data[i - 1] : price;
                            return price >= prevPrice ? '#3fb95080' : '#f8514980';
                        }),
                        borderWidth: 0
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { display: false } },
                    scales: {
                        x: { ticks: { color: '#8b949e', maxTicksLimit: 10 }, grid: { display: false } },
                        y: { ticks: { color: '#8b949e', callback: v => (v / 1000) + 'K' }, grid: { color: '#30363d' } }
                    }
                }
            });
            
            // RSI 圖
            charts.rsi = new Chart(document.getElementById('rsiChart'), {
                type: 'line',
                data: {
                    labels: allData.dates.slice(Math.max(0, allData.dates.length - currentRange)),
                    datasets: [
                        { label: 'RSI', data: sliceData(allData.indicators.rsi).data, borderColor: colors.rsi, tension: 0.1, pointRadius: 0 },
                        { label: '70', data: Array(currentRange).fill(70), borderColor: '#f8514930', borderDash: [5, 5], pointRadius: 0 },
                        { label: '30', data: Array(currentRange).fill(30), borderColor: '#3fb95030', borderDash: [5, 5], pointRadius: 0 }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { display: false } },
                    scales: {
                        x: { ticks: { color: '#8b949e', maxTicksLimit: 10 }, grid: { color: '#30363d' } },
                        y: { min: 0, max: 100, ticks: { color: '#8b949e' }, grid: { color: '#30363d' } }
                    }
                }
            });
            
            // KD 圖
            charts.kd = new Chart(document.getElementById('kdChart'), {
                type: 'line',
                data: {
                    labels: allData.dates.slice(Math.max(0, allData.dates.length - currentRange)),
                    datasets: [
                        { label: 'K', data: sliceData(allData.indicators.kd_k).data, borderColor: colors.k, tension: 0.1, pointRadius: 0 },
                        { label: 'D', data: sliceData(allData.indicators.kd_d).data, borderColor: colors.d, tension: 0.1, pointRadius: 0 },
                        { label: '80', data: Array(currentRange).fill(80), borderColor: '#f8514930', borderDash: [5, 5], pointRadius: 0 },
                        { label: '20', data: Array(currentRange).fill(20), borderColor: '#3fb95030', borderDash: [5, 5], pointRadius: 0 }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { display: false } },
                    scales: {
                        x: { ticks: { color: '#8b949e', maxTicksLimit: 10 }, grid: { color: '#30363d' } },
                        y: { min: 0, max: 100, ticks: { color: '#8b949e' }, grid: { color: '#30363d' } }
                    }
                }
            });
            
            // MACD 圖
            const macdData = sliceData(allData.indicators.macd);
            const signalData = sliceData(allData.indicators.macd_signal);
            const histData = sliceData(allData.indicators.macd_histogram);
            
            charts.macd = new Chart(document.getElementById('macdChart'), {
                type: 'line',
                data: {
                    labels: macdData.labels,
                    datasets: [
                        { label: 'MACD', data: macdData.data, borderColor: colors.macd, tension: 0.1, pointRadius: 0 },
                        { label: 'Signal', data: signalData.data, borderColor: colors.signal, tension: 0.1, pointRadius: 0 },
                        { 
                            label: 'Histogram', 
                            data: histData.data, 
                            type: 'bar',
                            backgroundColor: histData.data.map(v => v >= 0 ? '#3fb95080' : '#f8514980'),
                            borderWidth: 0
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { display: false } },
                    scales: {
                        x: { ticks: { color: '#8b949e', maxTicksLimit: 10 }, grid: { color: '#30363d' } },
                        y: { ticks: { color: '#8b949e' }, grid: { color: '#30363d' } }
                    }
                }
            });
            
            // 布林通道圖
            charts.bb = new Chart(document.getElementById('bbChart'), {
                type: 'line',
                data: {
                    labels: priceData.labels,
                    datasets: [
                        { label: '股價', data: priceData.data, borderColor: colors.price, backgroundColor: colors.price + '20', fill: false, tension: 0.1, pointRadius: 2 },
                        { label: 'Upper', data: sliceData(allData.indicators.bb_upper).data, borderColor: colors.bbUpper, tension: 0.1, pointRadius: 0, borderWidth: 1 },
                        { label: 'Middle', data: sliceData(allData.indicators.bb_middle).data, borderColor: colors.bbMiddle, tension: 0.1, pointRadius: 0, borderDash: [5, 5] },
                        { label: 'Lower', data: sliceData(allData.indicators.bb_lower).data, borderColor: colors.bbLower, tension: 0.1, pointRadius: 0, borderWidth: 1 }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    interaction: { mode: 'index', intersect: false },
                    plugins: { legend: { display: false } },
                    scales: {
                        x: { ticks: { color: '#8b949e', maxTicksLimit: 10 }, grid: { color: '#30363d' } },
                        y: { ticks: { color: '#8b949e' }, grid: { color: '#30363d' } }
                    }
                }
            });
        }
        
        loadData();
    </script>
</body>
</html>
