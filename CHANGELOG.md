# Changelog

stock 系統所有變更記錄。

格式參考 [Keep a Changelog](https://keepachangelog.com/zh-TW/1.1.0/)。

## [Unreleased] - 2026-09-07

### Fixed
- `stocks_api.php::normalize_symbol()`：bare ticker（如 `4979`、`3081`、`3363`）一律補 `.TW` 的問題。
  原行為會讓上櫃股被誤判為上市，Yahoo Finance 回 404 → 2026-09-07 股價更新靜默漏抓。
  新行為依序查 Yahoo `.TW` → `.TWO`，命中即停並寫入 `data/.symbol_market_cache.json`
  （success TTL 7 天 / fallback TTL 1 小時）；probe 故障 / 逾時不 spam log、不中斷主流程。
  - 已附後綴 → 保留（大小寫轉大寫）。
  - 兩邊都查無 → fallback `.TW`（向下相容）。
  - `validate_symbol()` 同步改為容忍前後空白與大小寫。

### Added
- `lib/symbol_resolver.py`：Python 鏡像模組（PHP `normalize_symbol()` 的演算法規格書）。
- `tests/test_normalize_symbol.py`：33 個 pytest（30 個 mock probe + 3 個真打 Yahoo）。
- `tests/run_php_tests.php`：46 個 PHP 測試（純單元，依賴注入 probe）。
- `tests/run_php_live_integration.php`：14 個 PHP 對 Yahoo 的整合測試（網路不通時 skip）。

### Verified
- 上市 `0050` → `.TW`；上櫃 `4979` / `3081` / `3363` → `.TWO`；無效 `9999` → fallback `.TW`。
- `python3 -m pytest tests/` → 52 passed, 2 skipped（既有測試未受影響）。
- `php tests/run_php_tests.php` → 46 passed。
- `php tests/run_php_live_integration.php` → 14 passed（含 Yahoo `.TWO` 真實回傳驗證）。

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
