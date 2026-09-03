# Changelog

stock 系統所有變更記錄。

格式參考 [Keep a Changelog](https://keepachangelog.com/zh-TW/1.1.0/)。

## [Unreleased] - 2026-09-03

### Fixed
- `profit_history.php`：initialCapital 寫死 1,000,000 → 5,000,000，修正獲利率顯示 >400% 的 bug。
- `stocks_api.php` / `manual_trade.php`：`stock_data.json` 路徑修正（從 `data/stock_data.json` 改為根目錄 `stock_data.json`）。
- `index.php`：首頁不再僅依賴 `stock_data.json`，改用 `stocks_api.php` 的清單建立卡片，新增代號即使尚無行情資料也會顯示提示卡。
- 三個對外節點載入故障（`http://10.35.32.11/stock/` 下 `profit_history.php` / `stocks.php` / `manual_trade.php`）恢復 200。

### Added
- `stocks_api.php`：validate regex 放寬為 `/^[0-9]{4,6}[A-Z]?(\.(TW|TWO))?$/`，支援 bare ticker 與 ETF 末碼英文字母（如 `00631L`、`00981A`）。
- `stocks_api.php`：新增 `display_ticker($symbol)` helper（`0050.TW → 0050`、`00631L.TWO → 00631L`）。
- `stocks_api.php`：GET 多回 `displays` 陣列（裸號），與既有 `stocks` 帶後綴並存，向下相容。
- `stocks_api.php`：bare 輸入自動補 `.TW` 後綴儲存（內部仍維持帶後綴，TWSE / TPEx 可區分）；`.TWO` 需手動輸入完整。
- 6 個前端頁面改用 `displays` 顯示裸號：`stocks.php` / `manual_trade.php` / `manual_trade_view.php` / `profit_history.php` / `index.php` / `stock_history.php`。

### Changed
- ETF 命名展示方式：頁面上 ticker 一律顯示為 bare（`0050` / `00631L` / `00981A`），不再顯示 `.TW` / `.TWO` 後綴。
