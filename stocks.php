<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>股票清單維護 - 股市模擬投資</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #0d1117; color: #c9d1d9; padding: 20px; }
        .container { max-width: 900px; margin: 0 auto; }
        h1 { color: #58a6ff; margin-bottom: 10px; }
        .subtitle { color: #8b949e; margin-bottom: 30px; }

        .back-link { display: inline-block; background: #238636; color: white; padding: 10px 20px; border-radius: 6px; text-decoration: none; margin-bottom: 20px; }
        .back-link:hover { background: #2ea043; }

        .stats-row { display: flex; gap: 20px; margin-bottom: 20px; flex-wrap: wrap; }
        .stat-card { background: #161b22; border-radius: 12px; padding: 20px; border: 1px solid #30363d; flex: 1; min-width: 200px; }
        .stat-label { color: #8b949e; font-size: 14px; margin-bottom: 10px; }
        .stat-value { font-size: 28px; font-weight: bold; color: #58a6ff; }

        .section { background: #161b22; border-radius: 12px; padding: 20px; margin-bottom: 20px; border: 1px solid #30363d; }
        .section-title { color: #58a6ff; font-size: 18px; margin-bottom: 15px; }
        .section-desc { color: #8b949e; font-size: 13px; margin-bottom: 15px; }

        .form-row { display: flex; gap: 10px; margin-bottom: 10px; align-items: center; flex-wrap: wrap; }
        .form-label { color: #8b949e; font-size: 14px; min-width: 100px; }
        .form-input { flex: 1; padding: 10px; background: #0d1117; border: 1px solid #30363d; border-radius: 6px; color: #c9d1d9; font-size: 14px; min-width: 200px; }
        .form-input:focus { outline: none; border-color: #58a6ff; }
        .form-textarea { width: 100%; padding: 10px; background: #0d1117; border: 1px solid #30363d; border-radius: 6px; color: #c9d1d9; font-size: 13px; font-family: monospace; min-height: 100px; }
        .form-textarea:focus { outline: none; border-color: #58a6ff; }
        .form-hint { color: #6e7681; font-size: 12px; margin-top: 5px; }

        .btn { background: #238636; color: white; padding: 10px 20px; border: none; border-radius: 6px; cursor: pointer; font-size: 14px; }
        .btn:hover { background: #2ea043; }
        .btn-danger { background: #f85149; }
        .btn-danger:hover { background: #ff6b63; }
        .btn-secondary { background: #21262d; color: #c9d1d9; border: 1px solid #30363d; }
        .btn-secondary:hover { background: #30363d; }
        .btn-small { padding: 5px 10px; font-size: 12px; }

        .stock-list { display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 10px; margin-top: 15px; }
        .stock-item { background: #21262d; padding: 12px; border-radius: 6px; display: flex; justify-content: space-between; align-items: center; }
        .stock-symbol { font-weight: bold; color: #58a6ff; font-family: monospace; }

        .message { padding: 12px 20px; border-radius: 6px; margin-bottom: 15px; display: none; }
        .message.success { background: #1c3a23; color: #3fb950; border: 1px solid #2ea043; }
        .message.error { background: #4d1c1c; color: #f85149; border: 1px solid #f85149; }
        .message.warning { background: #4d3c1c; color: #d29922; border: 1px solid #d29922; }
        .message pre { white-space: pre-wrap; font-family: monospace; font-size: 12px; margin-top: 8px; max-height: 200px; overflow: auto; }
    </style>
</head>
<body>
    <div class="container">
        <a href="/stock/" class="back-link">← 返回 Dashboard</a>
        <h1>股票清單維護</h1>
        <p class="subtitle">管理模擬投資的觀察清單（最多 50 隻）</p>

        <div id="message" class="message"></div>

        <div class="stats-row">
            <div class="stat-card">
                <div class="stat-label">目前清單數量</div>
                <div class="stat-value" id="statCount">-</div>
            </div>
            <div class="stat-card">
                <div class="stat-label">剩餘可新增</div>
                <div class="stat-value" id="statRemaining">-</div>
            </div>
            <div class="stat-card">
                <div class="stat-label">上限</div>
                <div class="stat-value" id="statMax">50</div>
            </div>
        </div>

        <!-- 目前清單 -->
        <div class="section">
            <div class="section-title">目前清單</div>
            <div class="stock-list" id="stockList">
                <div style="color: #8b949e;">載入中...</div>
            </div>
        </div>

        <!-- 單筆新增 -->
        <div class="section">
            <div class="section-title">新增單筆</div>
            <div class="form-row">
                <input type="text" id="addSymbol" class="form-input" placeholder="例如：0050、00631L、00981A" maxlength="10">
                <button class="btn" onclick="addStock()">新增</button>
            </div>
            <div class="form-hint">支援 bare ticker 或完整代號；bare 預設以 `.TW` 儲存，若要上櫃請手動輸入 `.TWO`</div>
        </div>

        <!-- 批次匯入 -->
        <div class="section">
            <div class="section-title">整批取代</div>
            <div class="section-desc">⚠️ 整批取代會把「不在新清單內」的股票以現價賣出（現金入帳）。</div>
            <textarea id="bulkInput" class="form-textarea" placeholder="0050,2330,00631L 或每行一個"></textarea>
            <div class="form-hint" style="margin-bottom: 10px;">支援逗號、空格、換行分隔</div>
            <button class="btn" onclick="bulkReplace()">整批取代</button>
        </div>
    </div>

    <script>
        const API_URL = '/stock/stocks_api.php';
        let currentStocks = [];

        function showMessage(type, text, detail = null) {
            const el = document.getElementById('message');
            el.className = 'message ' + type;
            el.style.display = 'block';
            el.innerHTML = text + (detail ? `<pre>${escapeHtml(JSON.stringify(detail, null, 2))}</pre>` : '');
            setTimeout(() => el.style.display = 'none', 8000);
        }

        function escapeHtml(str) {
            return String(str).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
        }

        function displayTicker(symbol) {
            return String(symbol || '').replace(/\.(TW|TWO)$/, '');
        }

        async function loadStocks() {
            try {
                const res = await fetch(API_URL, { headers: { 'Referer': location.href } });
                // fetch() 預設帶同源 Referer，但某些瀏覽器會 strip，加保險
                const data = await res.json();
                if (!data.success) throw new Error(data.error);
                currentStocks = data.stocks.map((stock, index) => ({
                    code: stock,
                    display: data.displays?.[index] || displayTicker(stock)
                }));
                renderStocks();
            } catch (e) {
                showMessage('error', '載入失敗', e.message);
            }
        }

        function renderStocks() {
            document.getElementById('statCount').textContent = currentStocks.length;
            document.getElementById('statRemaining').textContent = 50 - currentStocks.length;

            const list = document.getElementById('stockList');
            if (currentStocks.length === 0) {
                list.innerHTML = '<div style="color: #8b949e;">清單為空</div>';
                return;
            }
            list.innerHTML = currentStocks.map(s => `
                <div class="stock-item">
                    <span class="stock-symbol">${escapeHtml(s.display)}</span>
                    <button class="btn btn-danger btn-small" onclick="deleteStock('${escapeHtml(s.code)}', '${escapeHtml(s.display)}')">移除</button>
                </div>
            `).join('');
        }

        async function addStock() {
            const symbol = document.getElementById('addSymbol').value.trim();
            if (!symbol) return showMessage('error', '請輸入代號');

            try {
                const res = await fetch(API_URL, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ symbol })
                });
                const data = await res.json();
                if (!data.success) throw new Error(data.error);
                showMessage('success', `已新增 ${displayTicker(data.added || symbol)}`);
                document.getElementById('addSymbol').value = '';
                await loadStocks();
            } catch (e) {
                showMessage('error', '新增失敗', e.message);
            }
        }

        async function deleteStock(symbol, display) {
            const label = display || displayTicker(symbol);
            if (!confirm(`確定要移除 ${label}？\n模擬系統會以現價賣出該股所有持股，現金入帳。`)) return;

            try {
                const res = await fetch(`${API_URL}?symbol=${encodeURIComponent(symbol)}`, {
                    method: 'DELETE'
                });
                const data = await res.json();
                if (!data.success) throw new Error(data.error);
                let msg = `已移除 ${label}`;
                if (data.liquidation && data.liquidation.liquidated.length > 0) {
                    const total = data.liquidation.total_cash_added.toLocaleString();
                    msg += `\n自動賣出 ${data.liquidation.liquidated.length} 筆，共入帳 ${total} 元`;
                } else {
                    msg += '\n（無持股需結算）';
                }
                showMessage('success', msg, data.liquidation);
                await loadStocks();
            } catch (e) {
                showMessage('error', '刪除失敗', e.message);
            }
        }

        async function bulkReplace() {
            const raw = document.getElementById('bulkInput').value;
            const stocks = raw.split(/[,\s\n]+/).map(s => s.trim()).filter(Boolean);
            if (stocks.length === 0) return showMessage('error', '請輸入至少一個代號');
            if (!confirm(`確定要用 ${stocks.length} 隻股票整批取代現有清單？\n（不在新清單內的會以現價賣出）`)) return;

            try {
                const res = await fetch(API_URL, {
                    method: 'PUT',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ stocks })
                });
                const data = await res.json();
                if (!data.success) throw new Error(data.error);
                showMessage('success', `整批取代完成：${data.count} 隻（移除 ${data.removed.length}、新增 ${data.added.length}）`, data.liquidations);
                document.getElementById('bulkInput').value = '';
                await loadStocks();
            } catch (e) {
                showMessage('error', '整批取代失敗', e.message);
            }
        }

        loadStocks();
    </script>
</body>
</html>
