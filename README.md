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
├── .gitignore                        # 排除真實資料、venv、快取
├── run_simulation.py                 # 回測 / 試算入口
├── stock_trader.py                   # 主交易引擎（CLI）
├── kd.py                             # KD 指標參考實作（EMA 平滑）
├── examples/
│   ├── indicator_settings.example.json   # 技術指標參數範例
│   ├── portfolio.example.json            # 帳戶結構範例（空帳戶）
│   ├── stock_data.example.json           # 股價資料格式（1 筆樣本）
│   └── profit_history.example.json       # 損益歷史格式範例
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

## 與原 PHP 系統的差異

本 repo **只搬 Python 部分**。以下項目刻意**不包含**：

| 不包含 | 原因 |
|--------|------|
| `stock_trader.php` / `stock_dashboard.php` 等 PHP 檔 | 本 repo 聚焦 Python 邏輯 |
| `portfolio.json` / `stock_data.json` 等真實資料 | 避免敏感資料外洩 |
| `.venv/` | 環境依賴各自安裝 |
| `downloads/Favorite_Remedies_for_Influenza_in_1919.pdf` | 與模擬無關 |

如需 PHP dashboard + 排程整合，請回到原路徑
`D:\docker-volumn\ubuntu-apache2\html\stock\` 操作。

---

## 開發原則

- **不 commit 真實持股資料**：所有 `*.json` 預設排除
- **回測必跑 repro script**（依工作區政策）：改 ring / state / schema 後，用
  真實形狀資料灌一次關鍵 function 才算完成
- **CSV / 報表四捨五入採 half-up**（5 永遠進位，不是 banker's rounding）

---
