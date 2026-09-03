<?php
/**
 * manual_trade.php 的頁面輸出
 * 由 manual_trade.php 在最後 include 進來
 * 可用變數：$stocks, $pageTitle
 */
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <title><?= htmlspecialchars($pageTitle) ?></title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: 'Microsoft JhengHei', 'PingFang TC', sans-serif; background: #0d1117; color: #c9d1d9; margin: 0; padding: 20px; }
        .container { max-width: 1100px; margin: 0 auto; }
        h1 { color: #58a6ff; margin-bottom: 8px; }
        .subtitle { color: #8b949e; margin-bottom: 24px; }
        .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px; }
        @media (max-width: 720px) { .grid { grid-template-columns: 1fr; } }
        .card { background: #161b22; border: 1px solid #30363d; border-radius: 8px; padding: 20px; }
        .card h2 { margin-top: 0; color: #58a6ff; font-size: 16px; border-bottom: 1px solid #30363d; padding-bottom: 8px; }
        .stat { display: flex; justify-content: space-between; padding: 6px 0; font-size: 14px; }
        .stat .label { color: #8b949e; }
        .stat .value { color: #c9d1d9; font-weight: bold; }
        .value.positive { color: #3fb950; }
        .value.negative { color: #f85149; }
        .form-row { display: flex; gap: 8px; margin-bottom: 12px; align-items: center; }
        .form-row label { min-width: 60px; color: #8b949e; font-size: 14px; }
        select, input[type=number] { flex: 1; padding: 8px 10px; background: #0d1117; border: 1px solid #30363d; color: #c9d1d9; border-radius: 4px; font-size: 14px; }
        .btn { padding: 10px 20px; border: none; border-radius: 4px; cursor: pointer; font-size: 14px; font-weight: bold; transition: all 0.2s; }
        .btn-buy { background: #238636; color: white; }
        .btn-buy:hover { background: #2ea043; }
        .btn-buy:disabled { background: #30363d; color: #6e7681; cursor: not-allowed; }
        .btn-sell { background: #da3633; color: white; }
        .btn-sell:hover { background: #f85149; }
        .btn-sell:disabled { background: #30363d; color: #6e7681; cursor: not-allowed; }
        .btn-reset { background: #6e40c9; color: white; }
        .btn-reset:hover { background: #8957e5; }
        .btn-group { display: flex; gap: 8px; margin-top: 16px; }
        .btn-group .btn { flex: 1; }
        table { width: 100%; border-collapse: collapse; font-size: 13px; }
        th, td { padding: 8px; text-align: left; border-bottom: 1px solid #21262d; }
        th { color: #8b949e; font-weight: normal; }
        td.num { text-align: right; font-variant-numeric: tabular-nums; }
        .price-hint { color: #58a6ff; font-size: 13px; padding: 4px 0; }
        .empty { color: #6e7681; text-align: center; padding: 20px; font-style: italic; }
        .toast { position: fixed; top: 20px; right: 20px; padding: 12px 20px; border-radius: 6px; color: white; font-size: 14px; opacity: 0; transition: opacity 0.3s; pointer-events: none; z-index: 999; }
        .toast.show { opacity: 1; }
        .toast.success { background: #238636; }
        .toast.error { background: #da3633; }
        .nav { margin-bottom: 20px; }
        .nav a { color: #58a6ff; text-decoration: none; margin-right: 16px; font-size: 14px; }
        .nav a:hover { text-decoration: underline; }
    </style>
</head>
<body>
    <div class="container">
        <div class="nav">
            <a href="/stock/">← 回 Dashboard</a>
            <a href="/stock/stocks.php">股票清單維護</a>
        </div>

        <h1>手動投資操作</h1>
        <p class="subtitle">獨立帳戶：現金 5,000,000 元起，不參與自動策略下單</p>

        <div class="grid">
            <div class="card">
                <h2>帳戶狀態</h2>
                <div id="account-info">
                    <div class="stat"><span class="label">現金</span><span class="value" id="cash">載入中…</span></div>
                    <div class="stat"><span class="label">總資產</span><span class="value" id="total">—</span></div>
                    <div class="stat"><span class="label">獲利</span><span class="value" id="profit">—</span></div>
                    <div class="stat"><span class="label">初始資金</span><span class="value" id="initial">—</span></div>
                    <div class="stat"><span class="label">交易次數</span><span class="value" id="trade-count">—</span></div>
                </div>
                <button class="btn btn-reset" id="btn-reset" style="width: 100%; margin-top: 16px;">一鍵歸零（重置帳戶）</button>
            </div>

            <div class="card">
                <h2>下單</h2>
                <div class="form-row">
                    <label>股票</label>
                    <select id="stock-select">
                        <?php foreach ($stocks as $s): ?>
                            <option value="<?= htmlspecialchars($s) ?>"><?= htmlspecialchars(preg_replace('/\.(TW|TWO)$/', '', $s)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="price-hint" id="price-hint">目前股價：—</div>
                <div class="form-row">
                    <label>股數</label>
                    <input type="number" id="qty" min="1" step="1" value="1000">
                </div>
                <div class="price-hint" id="cost-hint">預估成本：—</div>
                <div class="btn-group">
                    <button class="btn btn-buy" id="btn-buy">買進</button>
                    <button class="btn btn-sell" id="btn-sell">賣出</button>
                </div>
            </div>
        </div>

        <div class="card" style="margin-bottom: 20px;">
            <h2>目前庫存</h2>
            <div id="holdings-area">
                <div class="empty">載入中…</div>
            </div>
        </div>

        <div class="card">
            <h2>最近 20 筆交易</h2>
            <div id="trades-area">
                <div class="empty">載入中…</div>
            </div>
        </div>
    </div>

    <div class="toast" id="toast"></div>

    <script>
        const API = '?action=';
        let accountData = null;

        function showToast(msg, type = 'success') {
            const t = document.getElementById('toast');
            t.textContent = msg;
            t.className = 'toast show ' + type;
            setTimeout(() => t.className = 'toast ' + type, 2500);
        }

        async function fetchJSON(url) {
            const res = await fetch(url);
            return res.json();
        }

        function fmt(n, digits = 0) {
            if (n === null || n === undefined || isNaN(n)) return '—';
            return Number(n).toLocaleString('zh-TW', { minimumFractionDigits: digits, maximumFractionDigits: digits });
        }

        function fmtSigned(n, digits = 0) {
            if (n === null || n === undefined || isNaN(n)) return '—';
            const sign = n >= 0 ? '+' : '';
            return sign + fmt(n, digits);
        }

        function displayTicker(symbol) {
            return String(symbol || '').replace(/\.(TW|TWO)$/, '');
        }

        function renderAccount(data) {
            document.getElementById('cash').textContent = 'NT$ ' + fmt(data.cash, 0);
            document.getElementById('total').textContent = 'NT$ ' + fmt(data.total_value, 0);
            const profitEl = document.getElementById('profit');
            profitEl.textContent = fmtSigned(data.profit, 0) + ' (' + fmtSigned(data.profit_rate, 2) + '%)';
            profitEl.className = 'value ' + (data.profit >= 0 ? 'positive' : 'negative');
            document.getElementById('initial').textContent = 'NT$ ' + fmt(data.initial_capital, 0);
            document.getElementById('trade-count').textContent = data.recent_trades.length;
        }

        function renderHoldings(data) {
            const area = document.getElementById('holdings-area');
            if (!data.holdings.length) {
                area.innerHTML = '<div class="empty">目前無庫存</div>';
                return;
            }
            const rows = data.holdings.map(h => {
                const pl = h.unrealized_pl;
                const plClass = pl === null ? '' : (pl >= 0 ? 'positive' : 'negative');
                return `<tr>
                    <td>${displayTicker(h.stock)}</td>
                    <td class="num">${fmt(h.quantity, 0)}</td>
                    <td class="num">${fmt(h.price, 2)}</td>
                    <td class="num">${fmt(h.cost_basis, 2)}</td>
                    <td class="num">${fmt(h.market_value, 0)}</td>
                    <td class="num ${plClass}">${fmtSigned(pl, 0)}</td>
                </tr>`;
            }).join('');
            area.innerHTML = `<table>
                <thead><tr>
                    <th>股票</th><th class="num">股數</th><th class="num">現價</th>
                    <th class="num">成本</th><th class="num">市值</th><th class="num">未實現損益</th>
                </tr></thead>
                <tbody>${rows}</tbody>
            </table>`;
        }

        function renderTrades(data) {
            const area = document.getElementById('trades-area');
            if (!data.recent_trades.length) {
                area.innerHTML = '<div class="empty">尚無交易紀錄</div>';
                return;
            }
            const rows = data.recent_trades.map(t => {
                const actionClass = t.action === 'BUY' ? 'positive' : 'negative';
                const actionText = t.action === 'BUY' ? '買' : '賣';
                const amount = t.action === 'BUY' ? t.total_cost : t.net_income;
                return `<tr>
                    <td>${t.date}</td>
                    <td class="${actionClass}">${actionText}</td>
                    <td>${displayTicker(t.stock)}</td>
                    <td class="num">${fmt(t.price, 2)}</td>
                    <td class="num">${fmt(t.quantity, 0)}</td>
                    <td class="num">${fmt(amount, 0)}</td>
                </tr>`;
            }).join('');
            area.innerHTML = `<table>
                <thead><tr>
                    <th>時間</th><th>動作</th><th>股票</th>
                    <th class="num">價格</th><th class="num">股數</th><th class="num">金額</th>
                </tr></thead>
                <tbody>${rows}</tbody>
            </table>`;
        }

        function updatePriceHint() {
            const stock = document.getElementById('stock-select').value;
            if (!accountData || !accountData.prices[stock]) {
                document.getElementById('price-hint').textContent = '目前股價：—';
                document.getElementById('cost-hint').textContent = '';
                return;
            }
            const price = accountData.prices[stock];
            const qty = parseInt(document.getElementById('qty').value) || 0;
            document.getElementById('price-hint').textContent = '目前股價：NT$ ' + fmt(price, 2);
            if (qty > 0) {
                const total = price * qty;
                const fee = Math.max(20, total * 0.1425 * 28 / 10000);
                document.getElementById('cost-hint').textContent =
                    '預估買進成本：NT$ ' + fmt(total + fee, 0) + '（含手續費 ' + fmt(fee, 0) + '）';
            }
        }

        async function loadAll() {
            const data = await fetchJSON(API + 'api_get');
            if (!data.ok) { showToast('載入失敗：' + data.error, 'error'); return; }
            accountData = data;
            renderAccount(data);
            renderHoldings(data);
            renderTrades(data);
            updatePriceHint();
        }

        async function trade(action) {
            const stock = document.getElementById('stock-select').value;
            const qty = parseInt(document.getElementById('qty').value);
            if (!stock || !qty || qty <= 0) {
                showToast('請填寫股票與股數', 'error');
                return;
            }
            const btn = document.getElementById(action === 'api_buy' ? 'btn-buy' : 'btn-sell');
            btn.disabled = true;
            try {
                const r = await fetchJSON(API + action + '&stock=' + encodeURIComponent(stock) + '&qty=' + qty);
                if (r.ok) {
                    showToast(r.message, 'success');
                    await loadAll();
                } else {
                    showToast(r.error, 'error');
                }
            } finally {
                btn.disabled = false;
            }
        }

        async function reset() {
            if (!confirm('確定要歸零嗎？\n\n庫存與交易紀錄會全部清空，現金回到 500 萬。')) {
                return;
            }
            const btn = document.getElementById('btn-reset');
            btn.disabled = true;
            try {
                const r = await fetchJSON(API + 'api_reset');
                if (r.ok) {
                    showToast(r.message, 'success');
                    await loadAll();
                } else {
                    showToast('歸零失敗：' + r.error, 'error');
                }
            } finally {
                btn.disabled = false;
            }
        }

        document.getElementById('btn-buy').onclick = () => trade('api_buy');
        document.getElementById('btn-sell').onclick = () => trade('api_sell');
        document.getElementById('btn-reset').onclick = reset;
        document.getElementById('stock-select').onchange = updatePriceHint;
        document.getElementById('qty').oninput = updatePriceHint;

        loadAll();
        setInterval(loadAll, 15000);
    </script>
</body>
</html>
