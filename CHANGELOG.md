# Changelog

stock 系統所有變更記錄。

格式參考 [Keep a Changelog](https://keepachangelog.com/zh-TW/1.1.0/)。

## [Unreleased] - 2026-09-14

### Added
- `index.php` 首頁新增「即時股價更新」toggle：
  - 開啟後以可調間隔（1 / 3 / **5** / 10 / 15 分鐘，預設 5 分鐘）呼叫 `stock_trader.php?update=1`，只更新股價不交易。
  - localStorage `stock_realtime_v1` 記住偏好（重新整理不重置）。
  - 預設 OFF（避免一進站就狂打 Yahoo API；17 檔 × ~4s）。
  - 狀態列顯示「下次更新 HH:MM:SS (剩 MM:SS)」+ 完成時顯示耗時與成功檔數。
  - 變更間隔會重設 setInterval 計時器並立即生效。

### Fixed
- `index.php` dashboard 抓的 `stock_data.json` 路徑從 `data/` 子目錄改為根目錄。
  - 歷史 bug：`stock_trader.php?update=1` 只寫根目錄那份（事實來源），但 dashboard 一直讀 `data/` 子目錄那份（停在 9/11，最後一次 Python 跑完的時間）。
- 路徑統一（PHP 全部以根目錄為事實來源）：
  - `stock_trader.php` L27 `analysis_file`：`data/daily_analysis.json` → `daily_analysis.json`
  - `manual_trade.php` L47 `portfolio_file`：`data/portfolio.json` → `portfolio.json`
  - `stocks_api.php` L295-297：`portfolio.json` / `daily_analysis.json` / `profit_history.json` 三個檔從 `data/` 改為根目錄
  - 保留不動：`stock_list.json` / `indicator_settings.json` / `snapshot.json` / `trade.log`（純本地用途）
  - Python `stock_trader.py` 不動，繼續寫兩份（`data/` + 根目錄）

### Verified
- `?update=1` 跑完 17 檔 portfolio.json trades 數完全沒變（策略1/策略2/手動操作 三個帳戶都驗證）。
- 0050.TW 根目錄 vs `data/` 子目錄 收盤序列不同（根目錄 `[109.15, 107.70, 106.65]`，data/ `[109.65, 109.65, 107.70]`）。
- 兩次 update 間隔 60s：成交量 54.9M → 55.0M ✓；RSI/KD 變化取決於 close 是否變動（機制正確，盤後 Yahoo 給固定值所以數字看起來不變；盤中會跟 tick 跳動）。

## [Unreleased] - 2026-09-07

### Added
- `agent_smart_trader.py`：智能多因子交易 Bot（由大寶在 OpenClaw cron 觸發下週一~五 14:00 自動跑）。
  - 多因子評分（MA / MACD / KD / RSI / 量能）+ regime-aware 加減分
  - 風控：個股 -8% 停損 / -5% 減倉 / +20% 強制獲利了結 / +12% 調節
  - 部位限制：最多 5 檔、單檔 30% 上限、現金 20% 保留
  - 用 `agent_stock.py` 當底層 CLI 呼叫 `manual_trade.php` API
  - 輸出 Markdown 報告到 `~/.openclaw/workspace/data/stock_reports/YYYY-MM-DD_HHMM.md`
- OpenClaw automation `smart-stock-trader`（id: 9a72ba57-0769-46bc-869d-3b71b7417584）
  - schedule: cron `0 14 * * 1-5` tz=Asia/Taipei
  - payload: agentTurn 跑 `~/.openclaw/workspace/scripts/run_smart_trader.sh`，讀報告檔，用 `conversations_send` 送 Telegram conversationRef=`conv_5740d8740fec30f76b04ef408327fa0f`
  - sessionTarget=isolated, timeoutSeconds=300, delivery=none

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
