# Stock Simulation
## 功能

- 10 檔台股模擬：0050 / 006208 / 0056 / 00919 / 2330 / 3711 / 2412 / 2881 / 2885 / 2891
- 兩套獨立策略帳戶（策略1 = MA+RSI+MACD 混合 / 策略2 = KD 隨機指標）
- 技術指標：**MA** / **RSI** / **MACD** / **KD**（EMA 型平滑）
- 從 Yahoo Finance 即時抓股價（不依賴本地快取）
- 回測、快照暫存 / 還原、每日分析輸出

---

## 目錄結構

```
stock_simulation/
├── README.md                         # 本檔
├── LICENSE                           # MIT License
├── .gitignore                        # 排除真實資料、venv、快取
│
├── index.php                         # Dashboard（前端入口）
├── profit_history.php                # 獲利曲線
├── stock_history.php                 # 個股歷史圖
├── stocks.php                        # 股票清單維護頁
├── indicator_settings.php            # 參數設定頁
│
├── stock_trader.php                  # 交易引擎（PHP 版，含網頁觸發 + CLI）
├── stocks_api.php                    # 股票清單 CRUD API（含自動結算）
├── stock_history_api.php             # 個股歷史 API
├── indicator_settings_api.php        # 參數設定 API
│
├── stock_trader.py                   # 交易引擎（Python 版，CLI / Windows 排程）
├── kd.py                             # KD 指標參考實作（EMA 平滑）
│
├── examples/
│   ├── indicator_settings.example.json   # 技術指標參數範例
│   ├── portfolio.example.json            # 帳戶結構範例（空帳戶）
│   ├── stock_data.example.json           # 股價資料格式（1 筆樣本）
│   ├── stock_list.example.json           # 股票清單範例（可交易 10 支）
│   └── profit_history.example.json       # 損益歷史格式範例
│
└── scripts/                          # 預留：輔助腳本（批次、報表）
```

---

## 快速開始

### 1. 建立虛擬環境

```bash
cd stock_simulation
python3 -m venv .venv
source .venv/bin/activate   # Windows: .venv\Scripts\activate
pip install yfinance pandas
```

### 2. 準備資料檔

把 `examples/` 內的 `.example.json` 複製成實際檔名，並依需求修改：

```bash
cp examples/indicator_settings.example.json indicator_settings.json
cp examples/portfolio.example.json          portfolio.json
```

> `stock_data.json` / `profit_history.json` 不需要手動準備 — 程式會自動從
> Yahoo Finance 抓取 / 初始化。

### 3. 執行

```bash
# 完整交易（含股價更新）
python stock_trader.py

# 只更新股價
python stock_trader.py --update

# 顯示現有狀態
python stock_trader.py --status
```

> ⚠️ `run_simulation.py` 已於 2026-08-07 廢除 — 它的功能（執行交易 + 顯示狀態）
> 全部由 `stock_trader.py` 接手，不需要兩條入口。

---

## 模擬交易規則

| 項目 | 設定 |
|------|------|
| 初始資金 | 每個策略 1,000,000 元 |
| 最小交易單位 | 1 股 |
| 交易費用 | 暫不計算（純模擬） |
| 每次買入金額 | 約占可用現金的 50% |
| 帳戶餘額不足 | 無法買入 |
| 庫存不足 | 無法賣出 |
| 可交易股票 | **動態**清單（最多 50 隻，從 `data/stock_list.json` 讀取） |
| 歷史資料長度 | **1 年**（從 Yahoo Finance 抓 `range=1y`） |
| 移除股票時 | 系統以「本地最後收盤價」自動賣出持股，現金入帳 |

---

## 兩套策略

| 策略 | 訊號邏輯 |
|------|---------|
| 策略1 | MA_GOLDEN / RSI_OVERSOLD / MACD_BUY 混合 |
| 策略2 | K < 超賣門檻 買、K > 超買門檻 賣 |

每個指標參數都以 `[策略1值, 策略2值]` 陣列儲存，方便兩策略獨立調整。

---

## 技術指標公式

### KD（EMA 型平滑，與 kd.py 一致）

```
RSV = (收盤價 - N日最低) / (N日最高 - N日最低) × 100
K   = K × (2/3) + RSV × (1/3)
D   = D × (2/3) + K   × (1/3)
```

週期 N = 9（可在 `indicator_settings.json` 調整）。

### 其他指標

- **MA**：短均 > 長均 黃金交叉（買）；短均 < 長均 死亡交叉（賣）
- **RSI**：< 30 超賣、> 70 超買
- **MACD**：線在信號線上方 = 多頭（買）

---

## 與本地實例的差異

本 repo **包含完整的 Python + PHP 程式碼**，可單獨 clone 部署。差異：

| 項目 | 本 repo | 本地實例（`/stock/`） |
|------|--------|---------------------|
| 程式碼 | ✅ 全套 | ✅ 全套 |
| 真實持股資料 | ❌ 不含（`.gitignore` 排除） | ✅ 含 |
| 歷史股價 | ❌ 不含 | ✅ 含 |
| Dashboard 入口檔名 | `index.php` | `index.php`（從 `stock_dashboard.php` 改名） |

### 本地實例對照

> 本地實例路徑為個人環境，本 README 不寫死。詳見工作區 memory。

**功能完全一致**，包含：
- Dashboard / 個股歷史圖 / 獲利曲線 / 參數設定頁
- 7 個 PHP 端點（4 個頁面 + 3 個 API）
- Python CLI 交易引擎（`stock_trader.py`）
- 兩個策略帳戶的快照暫存 / 還原

### 交易入口（2026-08-07 更新）

| 場景 | 入口 | 引擎 |
|------|------|------|
| Windows 手動跑 | `python stock_trader.py` | Python |
| Windows 排程 | `.venv\Scripts\python.exe stock_trader.py` | Python |
| Dashboard 網頁按鈕 | 點 `index.php` 上的按鈕 | PHP（呼叫 `stock_trader.php?run=1`） |
| 純查狀態 | `python stock_trader.py --status` | Python |

> `run_simulation.py`（HTTP 殼）已廢除。所有 Python 邏輯統一在 `stock_trader.py`。

### 部署到新機器

1. **PHP 部分**：把 `*.php` 部署到 Apache docroot 下任一目錄，瀏覽器開啟 `index.php` 即為 Dashboard。
2. **資料檔**：到部署目錄建立（或從本機實例複製）：
   - `portfolio.json`（帳戶資料）
   - `stock_data.json`（歷史股價，首次執行會自動從 Yahoo Finance 抓）
   - `profit_history.json`（損益歷史）
   - `daily_analysis.json`（每日分析）
   - `data/indicator_settings.json`（參數設定）
3. **Python 部分**：`pip install yfinance pandas` 後用 `stock_trader.py`。

---

## 開發原則

- **不 commit 真實持股資料**：所有 `*.json` 預設排除
- **回測必跑 repro script**（依工作區政策）：改 ring / state / schema 後，用
  真實形狀資料灌一次關鍵 function 才算完成
- **CSV / 報表四捨五入採 half-up**（5 永遠進位，不是 banker's rounding）
- **Dashboard 入口 = `index.php`**：對應 Apache `DirectoryIndex`，部署到 `/stock/`
  目錄時直接用 `/stock/` 即可，不需要 `/stock/index.php`
- **PHP / Python 兩套實作獨立維護**：兩個引擎的策略邏輯可能漂移，修 bug 兩邊都要改
- **任何 *.php 改完必跑所有 endpoint**（`?run=1` / `?update=1` / `?snapshot=1` /
  `?restore=1`）確認 200，且 Dashboard 按鈕仍能觸發

---

## 已知 bug 修復紀錄

### 2026-08-07：stock_trader.php 三個 bug

| Bug | 位置 | 影響 | 修復 |
|-----|------|------|------|
| `is_array($val)` 缺 `)` | line 103 | 全檔 parse error，HTTP 500 | 補 `)` |
| 檔案結尾缺 `}` | line 579 | 修上一個之後才浮現 | 加 `}` |
| `getSignal()` 用 `ma5/ma20` 但指標產出 `ma_short/ma_long` | line 274-276 | MA 訊號永遠不觸發，PHP Warning | 改用正確 key |

> 觸發場景：Dashboard 按鈕「手動執行交易」→ `fetch('stock_trader.php?run=1')` 一直 500。

### 2026-08-07：股票清單動態化 + 歷史拉長至 1 年

**新增**：
- `stocks.php` — 股票清單維護頁（單筆新增 / 單筆移除 / 整批取代）
- `stocks_api.php` — CRUD API（GET / POST / DELETE / PUT），上限 50 隻
- `data/stock_list.json` — 動態清單（`examples/stock_list.example.json` 為範例）

**改寫**：
- `stock_trader.php` — 從 `stock_list.json` 動態讀取（不再寫死 10 隻）
- `stock_trader.py` — 同上，**注意**：因 module load 順序，`load_stock_list()` 必須在 `load_json()` 定義之後
- 兩個引擎的 `getStockData` / `fetch_stock_data`：`range=30d` / `period=6mo` → **`range=1y` / `period=1y`**

**移除股票時自動結算**：
- 從清單移除某股票 → 系統從 `stock_data.json` 取「該股最後一筆收盤價」
- 對**所有策略帳戶**的該股持股執行「以現價賣出」
- 現金入帳、移除 holdings、記一筆 SELL 交易
- 整批取代時也會逐筆結算被移除的股票

**部署注意事項**：
- `data/stock_list.json` 預設包含原 10 支股票（`gitignore` 排除）
- `examples/stock_list.example.json` 提供完整 schema
- `stock_data.json` 體積從 ~240KB → ~535KB（10 隻 × 244 筆 1 年資料）
- 50 隻 × 244 筆 ≈ 2.5 MB，注意磁碟空間

**測試驗證**：
- 維護頁新增 `9999.TW`（測試用）後手動移除
- 整批取代：把清單縮成 5 隻，被移除的 5 隻自動結算
- 所有 endpoint（`?run=1` / `?update=1` / `?snapshot=1`）仍 200

---
