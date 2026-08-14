<?php
declare(strict_types=1);
$stockDataFile = __DIR__ . '/stock_data.json';
$dataMtime = file_exists($stockDataFile) ? filemtime($stockDataFile) : 0;
$dataDateRange = '';
$availableSymbols = [];
if ($dataMtime > 0) {
    $json = @file_get_contents($stockDataFile);
    $data = json_decode($json, true);
    if (is_array($data)) {
        $availableSymbols = array_keys($data);
        sort($availableSymbols);
        $allDates = [];
        foreach ($data as $sym => $bars) {
            foreach ($bars as $b) {
                if (isset($b['date'])) {
                    $allDates[] = $b['date'];
                }
            }
        }
        if ($allDates) {
            sort($allDates);
            $dataDateRange = $allDates[0] . ' ~ ' . end($allDates);
            $minDate = $allDates[0];
            $maxDate = end($allDates);
        }
    }
}
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>股市回測</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #0d1117; color: #c9d1d9; padding: 20px; }
        .container { max-width: 1400px; margin: 0 auto; }
        h1 { color: #58a6ff; margin-bottom: 5px; }
        .subtitle { color: #8b949e; margin-bottom: 20px; font-size: 14px; }

        .topbar { display: flex; gap: 10px; margin-bottom: 20px; flex-wrap: wrap; }
        .topbar a { padding: 8px 16px; border-radius: 6px; text-decoration: none; color: #c9d1d9; background: #21262d; border: 1px solid #30363d; font-size: 13px; }
        .topbar a:hover { background: #30363d; }

        .grid { display: grid; grid-template-columns: 380px 1fr; gap: 20px; }
        @media (max-width: 900px) { .grid { grid-template-columns: 1fr; } }

        .panel { background: #161b22; border-radius: 12px; padding: 20px; border: 1px solid #30363d; }
        .panel h2 { color: #58a6ff; font-size: 16px; margin-bottom: 15px; }
        .panel h3 { color: #8b949e; font-size: 13px; margin-bottom: 10px; margin-top: 15px; }

        .field { margin-bottom: 12px; }
        .field label { display: block; color: #8b949e; font-size: 12px; margin-bottom: 5px; }
        .field input, .field select {
            width: 100%; padding: 8px 10px; background: #0d1117;
            border: 1px solid #30363d; border-radius: 6px;
            color: #c9d1d9; font-size: 14px;
        }
        .field input:focus, .field select:focus { outline: none; border-color: #58a6ff; }

        .row2 { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
        .row3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 10px; }

        .strategy-pick { display: flex; flex-direction: column; gap: 8px; }
        .strategy-pick label { display: flex; align-items: center; gap: 8px; cursor: pointer; padding: 8px 10px; background: #21262d; border-radius: 6px; border: 1px solid #30363d; }
        .strategy-pick label:hover { background: #30363d; }
        .strategy-pick input[type=radio] { accent-color: #58a6ff; }

        .freq-pick { display: flex; gap: 6px; }
        .freq-pick label { flex: 1; padding: 6px 8px; background: #21262d; border: 1px solid #30363d; border-radius: 6px; cursor: pointer; text-align: center; font-size: 13px; }
        .freq-pick input { display: none; }
        .freq-pick input:checked + span { color: #58a6ff; font-weight: 600; }
        .freq-pick label:has(input:checked) { background: #1f6feb33; border-color: #1f6feb; }

        .btn { padding: 10px 16px; border: none; border-radius: 6px; font-size: 14px; cursor: pointer; transition: 0.2s; }
        .btn-primary { background: #238636; color: white; width: 100%; }
        .btn-primary:hover { background: #2ea043; }
        .btn-primary:disabled { background: #30363d; cursor: not-allowed; }

        .kpis { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; margin-bottom: 15px; }
        .kpi { background: #21262d; padding: 12px 14px; border-radius: 8px; }
        .kpi-label { color: #8b949e; font-size: 11px; margin-bottom: 4px; }
        .kpi-value { font-size: 22px; font-weight: 600; }
        .kpi-value.positive { color: #3fb950; }
        .kpi-value.negative { color: #f85149; }
        @media (max-width: 600px) { .kpis { grid-template-columns: repeat(2, 1fr); } }

        .chart-wrap { background: #21262d; padding: 15px; border-radius: 8px; margin-bottom: 15px; }
        .chart-wrap h3 { color: #8b949e; font-size: 13px; margin-bottom: 10px; margin-top: 0; }
        .chart-wrap canvas { max-height: 360px; }

        .trades-table { background: #21262d; border-radius: 8px; max-height: 360px; overflow-y: auto; }
        table { width: 100%; border-collapse: collapse; font-size: 13px; }
        th { color: #8b949e; padding: 8px 12px; text-align: left; background: #161b22; position: sticky; top: 0; }
        td { padding: 6px 12px; border-top: 1px solid #30363d; font-family: monospace; }
        .buy-tag { color: #3fb950; font-weight: 600; }
        .sell-tag { color: #f85149; font-weight: 600; }

        .empty { color: #6e7681; padding: 40px; text-align: center; font-size: 13px; }

        .status { margin-top: 12px; padding: 10px 12px; border-radius: 6px; display: none; font-size: 13px; }
        .status.error { background: #f8514920; border: 1px solid #f85149; color: #f85149; }
        .status.running { background: #1f6feb20; border: 1px solid #1f6feb; color: #58a6ff; }

        .history-list { margin-top: 10px; max-height: 200px; overflow-y: auto; }
        .history-item { padding: 8px 10px; background: #21262d; border-radius: 4px; margin-bottom: 5px; font-size: 12px; cursor: pointer; }
        .history-item:hover { background: #30363d; }
        .history-item .meta { color: #8b949e; font-size: 11px; }
        .history-item .return.positive { color: #3fb950; }
        .history-item .return.negative { color: #f85149; }

        .data-note { font-size: 11px; color: #6e7681; margin-top: 4px; }
    </style>
</head>
<body>
    <div class="container">
        <h1>股市回測</h1>
        <p class="subtitle">用 1 年歷史股價，模擬策略績效</p>

        <div class="topbar">
            <a href="/stock/index.php">← Dashboard</a>
            <a href="/stock/indicator_settings.php">指標參數設定</a>
        </div>

        <div class="grid">
            <!-- 左：策略輸入 -->
            <div class="panel">
                <h2>策略輸入</h2>

                <div class="field">
                    <label>策略類型</label>
                    <div class="strategy-pick">
                        <label>
                            <input type="radio" name="strategy" value="0" checked>
                            <span>策略 1：MA + RSI + MACD 混合</span>
                        </label>
                        <label>
                            <input type="radio" name="strategy" value="1">
                            <span>策略 2：KD 隨機指標</span>
                        </label>
                    </div>
                </div>

                <div class="field">
                    <label>回測區間</label>
                    <div class="row2">
                        <input type="date" id="startDate" value="<?= htmlspecialchars($minDate ?? '2025-08-12') ?>" min="<?= htmlspecialchars($minDate ?? '') ?>" max="<?= htmlspecialchars($maxDate ?? '') ?>">
                        <input type="date" id="endDate" value="<?= htmlspecialchars($maxDate ?? '2026-08-12') ?>" min="<?= htmlspecialchars($minDate ?? '') ?>" max="<?= htmlspecialchars($maxDate ?? '') ?>">
                    </div>
                    <div class="data-note">資料區間：<?= htmlspecialchars($dataDateRange ?: '無') ?></div>
                </div>

                <div class="field">
                    <label>初始資金</label>
                    <input type="number" id="initialCapital" value="1000000" min="10000" step="10000">
                </div>

                <div class="field">
                    <label>交易頻率（每 N 天檢查一次訊號）</label>
                    <div class="freq-pick">
                        <label><input type="radio" name="rebalance" value="1" checked><span>每天</span></label>
                        <label><input type="radio" name="rebalance" value="5"><span>每週</span></label>
                        <label><input type="radio" name="rebalance" value="20"><span>每月</span></label>
                    </div>
                </div>

                <div class="field">
                    <label>選股範圍</label>
                    <div class="freq-pick">
                        <label><input type="radio" name="scope" value="all" checked onchange="toggleSymbolPicker()"><span>全部（自動）</span></label>
                        <label><input type="radio" name="scope" value="one" onchange="toggleSymbolPicker()"><span>指定單一股票</span></label>
                    </div>
                </div>

                <div class="field" id="symbolPicker" style="display:none;">
                    <label>股票代號（只能選 1 個）</label>
                    <select id="symbolSelect">
                        <?php foreach ($availableSymbols as $sym): ?>
                            <option value="<?= htmlspecialchars($sym) ?>"><?= htmlspecialchars($sym) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <h3>指標參數（覆寫預設）</h3>

                <div class="row2">
                    <div class="field">
                        <label>MA short</label>
                        <input type="number" id="maShort" value="5" min="2" max="60">
                    </div>
                    <div class="field">
                        <label>MA long</label>
                        <input type="number" id="maLong" value="20" min="5" max="120">
                    </div>
                </div>

                <div class="row3">
                    <div class="field">
                        <label>RSI 週期</label>
                        <input type="number" id="rsiPeriod" value="14" min="5" max="30">
                    </div>
                    <div class="field">
                        <label>RSI 超賣</label>
                        <input type="number" id="rsiOversold" value="30" min="10" max="40">
                    </div>
                    <div class="field">
                        <label>RSI 超買</label>
                        <input type="number" id="rsiOverbought" value="70" min="60" max="90">
                    </div>
                </div>

                <div class="row3">
                    <div class="field">
                        <label>KD 週期</label>
                        <input type="number" id="kdPeriod" value="9" min="3" max="30">
                    </div>
                    <div class="field">
                        <label>KD 超賣</label>
                        <input type="number" id="kdOversold" value="20" min="5" max="40">
                    </div>
                    <div class="field">
                        <label>KD 超買</label>
                        <input type="number" id="kdOverbought" value="80" min="60" max="95">
                    </div>
                </div>

                <div class="row2">
                    <div class="field">
                        <label>MACD fast</label>
                        <input type="number" id="macdFast" value="12" min="3" max="30">
                    </div>
                    <div class="field">
                        <label>MACD slow</label>
                        <input type="number" id="macdSlow" value="26" min="10" max="60">
                    </div>
                </div>

                <button class="btn btn-primary" id="runBtn" onclick="runBacktest()">開始回測</button>
                <div class="status" id="status"></div>
            </div>

            <!-- 右：結果 -->
            <div>
                <div class="panel">
                    <h2>KPI</h2>
                    <div class="kpis">
                        <div class="kpi">
                            <div class="kpi-label">總報酬</div>
                            <div class="kpi-value" id="kpi-total">—</div>
                        </div>
                        <div class="kpi">
                            <div class="kpi-label">年化報酬</div>
                            <div class="kpi-value" id="kpi-annual">—</div>
                        </div>
                        <div class="kpi">
                            <div class="kpi-label">最大回撤 (MDD)</div>
                            <div class="kpi-value" id="kpi-mdd">—</div>
                        </div>
                        <div class="kpi">
                            <div class="kpi-label">勝率</div>
                            <div class="kpi-value" id="kpi-winrate">—</div>
                        </div>
                        <div class="kpi">
                            <div class="kpi-label">交易次數</div>
                            <div class="kpi-value" id="kpi-trades">—</div>
                        </div>
                        <div class="kpi">
                            <div class="kpi-label">Buy &amp; Hold</div>
                            <div class="kpi-value" id="kpi-buyhold">—</div>
                        </div>
                        <div class="kpi">
                            <div class="kpi-label">0050 含息</div>
                            <div class="kpi-value" id="kpi-benchmark-0050">—</div>
                        </div>
                    </div>

                    <div class="chart-wrap">
                        <h3>累計淨值曲線</h3>
                        <canvas id="equityChart"></canvas>
                    </div>
                </div>

                <div class="panel" style="margin-top: 20px;">
                    <h2>交易紀錄</h2>
                    <div class="trades-table" id="tradesTable">
                        <div class="empty">點「開始回測」後顯示交易明細</div>
                    </div>
                </div>

                <div class="panel" style="margin-top: 20px;">
                    <h2>歷史回測</h2>
                    <div class="history-list" id="historyList">
                        <div class="empty">載入中...</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        let equityChart = null;

        function fmtPct(v) {
            if (v === null || v === undefined) return '—';
            const sign = v > 0 ? '+' : '';
            return sign + v.toFixed(2) + '%';
        }

        function colorClass(v) {
            if (v === null || v === undefined) return '';
            return v > 0 ? 'positive' : (v < 0 ? 'negative' : '');
        }

        function setStatus(msg, kind) {
            const el = document.getElementById('status');
            el.className = 'status ' + kind;
            el.style.display = msg ? 'block' : 'none';
            el.textContent = msg || '';
        }

        function setKpi(id, value) {
            const el = document.getElementById(id);
            el.textContent = value;
            el.className = 'kpi-value ' + colorClass(parseFloat(value));
        }

        function toggleSymbolPicker() {
            const scope = document.querySelector('input[name=scope]:checked').value;
            document.getElementById('symbolPicker').style.display = scope === 'one' ? 'block' : 'none';
        }

        async function runBacktest() {
            const btn = document.getElementById('runBtn');
            btn.disabled = true;
            btn.textContent = '回測執行中...';
            setStatus('回測執行中，請稍候（約 3-10 秒）...', 'running');

            const strategy = document.querySelector('input[name=strategy]:checked').value;
            const rebalance = document.querySelector('input[name=rebalance]:checked').value;
            const scope = document.querySelector('input[name=scope]:checked').value;
            const symbol = scope === 'one' ? document.getElementById('symbolSelect').value : null;
            const payload = {
                start_date: document.getElementById('startDate').value,
                end_date: document.getElementById('endDate').value,
                strategy: parseInt(strategy),
                rebalance_n: parseInt(rebalance),
                initial_capital: parseFloat(document.getElementById('initialCapital').value),
                symbol: symbol,
                settings: {
                    ma: { short: parseInt(document.getElementById('maShort').value), long: parseInt(document.getElementById('maLong').value), long60: 60 },
                    rsi: { period: parseInt(document.getElementById('rsiPeriod').value) },
                    kd: { period: parseInt(document.getElementById('kdPeriod').value), k_smooth: 3, d_smooth: 3 },
                    macd: { fast: parseInt(document.getElementById('macdFast').value), slow: parseInt(document.getElementById('macdSlow').value), signal: 9 },
                    thresholds: {
                        rsi_oversold: parseInt(document.getElementById('rsiOversold').value),
                        rsi_overbought: parseInt(document.getElementById('rsiOverbought').value),
                        kd_oversold: parseInt(document.getElementById('kdOversold').value),
                        kd_overbought: parseInt(document.getElementById('kdOverbought').value),
                        ma_cross: true,
                    },
                    position: {
                        buy_unit_pct: 20, sell_unit_pct: 50,
                        max_positions: 5, min_cash_reserve_pct: 10,
                        use_kd_strength: false, kd_strength_max: 30,
                    },
                },
            };

            try {
                const res = await fetch('/stock/backtest_api.php?action=run', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload),
                });
                const json = await res.json();
                if (json.error) {
                    setStatus('錯誤：' + json.error + (json.detail ? ' / ' + json.detail : ''), 'error');
                    return;
                }
                renderResult(json);
                const scopeLabel = json.request && json.request.symbol
                    ? '（單一 ' + json.request.symbol + '）'
                    : '（全部 ' + (json.run_meta && json.run_meta.n_stocks ? json.run_meta.n_stocks + ' 檔' : '') + '）';
                setStatus('回測完成，id = ' + (json.id || '') + ' ' + scopeLabel, 'running');
                loadHistory();
            } catch (e) {
                setStatus('請求失敗：' + e.message, 'error');
            } finally {
                btn.disabled = false;
                btn.textContent = '開始回測';
            }
        }

        function renderResult(r) {
            const k = r.kpi;
            setKpi('kpi-total', fmtPct(k.total_return_pct));
            setKpi('kpi-annual', fmtPct(k.annualized_return_pct));
            setKpi('kpi-mdd', fmtPct(k.mdd_pct));
            // win rate / trades / buyhold: 直接 set（不用 color class）
            document.getElementById('kpi-winrate').textContent = (k.win_rate_pct || 0).toFixed(1) + '%';
            document.getElementById('kpi-winrate').className = 'kpi-value';
            document.getElementById('kpi-trades').textContent = (k.n_trades || 0);
            document.getElementById('kpi-trades').className = 'kpi-value';
            document.getElementById('kpi-buyhold').textContent = fmtPct(k.buyhold_return_pct);
            document.getElementById('kpi-buyhold').className = 'kpi-value ' + colorClass(k.buyhold_return_pct);
            // 0050 含息
            const benchmarkEl = document.getElementById('kpi-benchmark-0050');
            if (k.benchmark_0050_return_pct === null || k.benchmark_0050_return_pct === undefined) {
                benchmarkEl.textContent = '無資料';
                benchmarkEl.className = 'kpi-value';
            } else {
                benchmarkEl.textContent = fmtPct(k.benchmark_0050_return_pct);
                benchmarkEl.className = 'kpi-value ' + colorClass(k.benchmark_0050_return_pct);
            }

            renderChart(r.equity_curve, r.buyhold_curve, r.benchmark_0050_curve || []);
            renderTrades(r.trades || []);
        }

        function renderChart(equity, buyhold, benchmark0050) {
            const ctx = document.getElementById('equityChart').getContext('2d');
            const labels = equity.map(p => p.date);
            const equityData = equity.map(p => p.value);
            const buyholdData = buyhold.map(p => p.value);

            // 0050 含息：對齊到 strategy 標籤（長度可能不同）
            const benchmarkData = labels.map(d => {
                const p = benchmark0050.find(x => x.date === d);
                return p ? p.value : null;
            });

            const datasets = [
                { label: '策略淨值', data: equityData, borderColor: '#58a6ff', backgroundColor: '#58a6ff20', borderWidth: 2, pointRadius: 0, tension: 0.1 },
                { label: 'Buy & Hold', data: buyholdData, borderColor: '#8b949e', backgroundColor: '#8b949e20', borderWidth: 1.5, borderDash: [4, 4], pointRadius: 0, tension: 0.1 },
            ];
            if (benchmark0050 && benchmark0050.length > 0) {
                datasets.push({
                    label: '0050 含息',
                    data: benchmarkData,
                    borderColor: '#f0883e',
                    backgroundColor: '#f0883e20',
                    borderWidth: 2,
                    pointRadius: 0,
                    tension: 0.1,
                    spanGaps: true,
                });
            }

            if (equityChart) equityChart.destroy();
            equityChart = new Chart(ctx, {
                type: 'line',
                data: {
                    labels: labels,
                    datasets: datasets,
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: true,
                    aspectRatio: 2.5,
                    plugins: {
                        legend: { labels: { color: '#c9d1d9' } },
                        tooltip: { mode: 'index', intersect: false },
                    },
                    scales: {
                        x: { ticks: { color: '#8b949e', maxTicksLimit: 10 }, grid: { color: '#21262d' } },
                        y: { ticks: { color: '#8b949e' }, grid: { color: '#21262d' } },
                    },
                },
            });
        }

        function renderTrades(trades) {
            const wrap = document.getElementById('tradesTable');
            if (!trades.length) {
                wrap.innerHTML = '<div class="empty">本次回測無交易（訊號未觸發或全部被風控擋下）</div>';
                return;
            }
            let html = '<table><thead><tr><th>日期</th><th>股票</th><th>動作</th><th>價格</th><th>股數</th><th>觸發</th><th>成本/淨收</th></tr></thead><tbody>';
            for (const t of trades) {
                const tag = t.action === 'BUY' ? 'buy-tag' : 'sell-tag';
                const cost = t.action === 'BUY'
                    ? '成本 ' + (t.total_cost || 0).toFixed(0)
                    : '淨收 ' + (t.net_income || 0).toFixed(0);
                html += '<tr>'
                    + '<td>' + t.date + '</td>'
                    + '<td>' + t.stock + '</td>'
                    + '<td class="' + tag + '">' + t.action + '</td>'
                    + '<td>' + (t.price || 0).toFixed(2) + '</td>'
                    + '<td>' + t.quantity + '</td>'
                    + '<td>' + (t.trigger || '') + '</td>'
                    + '<td>' + cost + '</td>'
                    + '</tr>';
            }
            html += '</tbody></table>';
            wrap.innerHTML = html;
        }

        async function loadHistory() {
            try {
                const res = await fetch('/stock/backtest_api.php?action=list');
                const list = await res.json();
                const wrap = document.getElementById('historyList');
                if (!Array.isArray(list) || !list.length) {
                    wrap.innerHTML = '<div class="empty">尚無歷史回測</div>';
                    return;
                }
                let html = '';
                for (const r of list.slice().reverse()) {
                    const ret = r.kpi ? r.kpi.total_return_pct : 0;
                    const cls = ret > 0 ? 'positive' : (ret < 0 ? 'negative' : '');
                    const scopeTag = r.symbol
                        ? '<span class="meta">[' + r.symbol + ']</span>'
                        : '<span class="meta">[全 ' + (r.n_stocks || '?') + ' 檔]</span>';
                    html += '<div class="history-item" onclick="loadHistoryItem(\'' + r.id + '\')">'
                        + '<div><strong>' + r.strategy + '</strong> ' + scopeTag + ' <span class="meta">' + r.start_date + ' ~ ' + r.end_date + ' (每 ' + r.rebalance_n + ' 天)</span></div>'
                        + '<div><span class="return ' + cls + '">' + fmtPct(ret) + '</span> <span class="meta">' + (r.timestamp || '') + '</span></div>'
                        + '</div>';
                }
                wrap.innerHTML = html;
            } catch (e) {
                document.getElementById('historyList').innerHTML = '<div class="empty">載入失敗</div>';
            }
        }

        async function loadHistoryItem(id) {
            try {
                const res = await fetch('/stock/backtest_api.php?action=get&id=' + encodeURIComponent(id));
                const json = await res.json();
                if (json.error) {
                    setStatus('載入歷史失敗：' + json.error, 'error');
                    return;
                }
                renderResult(json);
                setStatus('已載入歷史回測 ' + id, 'running');
            } catch (e) {
                setStatus('載入失敗：' + e.message, 'error');
            }
        }

        // 頁面載入時拉歷史
        loadHistory();
    </script>
</body>
</html>
