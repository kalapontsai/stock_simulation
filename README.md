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
├── indicator_settings.php            # 參數設定頁
│
├── stock_trader.php                  # 交易引擎（PHP 版，含網頁觸發 + CLI）
├── stock_history_api.php             # 個股歷史 API
├── indicator_settings_api.php        # 參數設定 API
│
├── stock_trader.py                   # 交易引擎（Python 版，CLI / Windows 排程）
├── run_simulation.py                 # 回測 / 試算入口
├── kd.py                             # KD 指標參考實作（EMA 平滑）
│
├── examples/
│   ├── indicator_settings.example.json   # 技術指標參數範例
│   ├── portfolio.example.json            # 帳戶結構範例（空帳戶）
│   ├── stock_data.example.json           # 股價資料格式（1 筆樣本）
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

# 跑回測
python run_simulation.py
```

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
- Python CLI 交易引擎 + 回測
- 兩個策略帳戶的快照暫存 / 還原

### 部署到新機器

1. **PHP 部分**：把 `*.php` 部署到 Apache docroot 下任一目錄，瀏覽器開啟 `index.php` 即為 Dashboard。
2. **資料檔**：到部署目錄建立（或從本機實例複製）：
   - `portfolio.json`（帳戶資料）
   - `stock_data.json`（歷史股價，首次執行會自動從 Yahoo Finance 抓）
   - `profit_history.json`（損益歷史）
   - `daily_analysis.json`（每日分析）
   - `data/indicator_settings.json`（參數設定）
3. **Python 部分**：`pip install yfinance pandas` 後用 `stock_trader.py` / `run_simulation.py`。

---

## 開發原則

- **不 commit 真實持股資料**：所有 `*.json` 預設排除
- **回測必跑 repro script**（依工作區政策）：改 ring / state / schema 後，用
  真實形狀資料灌一次關鍵 function 才算完成
- **CSV / 報表四捨五入採 half-up**（5 永遠進位，不是 banker's rounding）
- **Dashboard 入口 = `index.php`**：對應 Apache `DirectoryIndex`，部署到 `/stock/`
  目錄時直接用 `/stock/` 即可，不需要 `/stock/index.php`

---
