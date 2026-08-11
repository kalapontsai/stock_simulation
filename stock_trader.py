"""
股市模擬投資系統
由使用者手動啟動，執行兩個策略的模擬交易

使用方式：
    python stock_trader.py          # 完整交易（含更新股價）
    python stock_trader.py --update # 只更新股價（不打交易）
    python stock_trader.py --status # 顯示現有狀態
"""

import json
import math
import sys
import argparse
from datetime import datetime
from pathlib import Path

try:
    import yfinance as yf
    import pandas as pd
except ImportError:
    print("需要安裝 yfinance: pip install yfinance pandas")
    sys.exit(1)


# 設定

STRATEGIES = ['策略1', '策略2', '手動操作']
STRATEGY_NAMES = {
    '策略1': 'MA + RSI 混合策略',
    '策略2': 'KD 隨機指標策略',
    '手動操作': '使用者手動操作（不自動下單）'
}

INITIAL_CAPITAL = 5_000_000

# 最小交易限制（避免小額交易導致手續費比例過高）
# 買進：金額取 max(半倉, 10000, 100股×現價)
# 賣出：股數取 max(持倉/2, max(1, ceil(10000/現價), 100))
MIN_TRADE_AMOUNT = 10_000   # 最小交易金額（元）
MIN_TRADE_SHARES = 100      # 最小交易股數（張數）

# 檔案路徑 - Windows 環境
STOCK_DIR = Path(__file__).parent
DATA_DIR = STOCK_DIR / 'data'
DATA_FILE = DATA_DIR / 'stock_data.json'
PORTFOLIO_FILE = DATA_DIR / 'portfolio.json'
PROFIT_HISTORY_FILE = DATA_DIR / 'profit_history.json'
ANALYSIS_FILE = DATA_DIR / 'daily_analysis.json'
SETTINGS_FILE = DATA_DIR / 'indicator_settings.json'

# 建立資料目錄
DATA_DIR.mkdir(parents=True, exist_ok=True)



# Dashboard 讀取的主要檔案位置
MAIN_DIR = Path('D:/docker-volumn/ubuntu-apache2/html/stock')
MAIN_PORTFOLIO = MAIN_DIR / 'portfolio.json'
MAIN_DATA = MAIN_DIR / 'stock_data.json'
MAIN_PROFIT = MAIN_DIR / 'profit_history.json'
MAIN_ANALYSIS = MAIN_DIR / 'daily_analysis.json'
MAIN_SETTINGS = MAIN_DIR / 'data' / 'indicator_settings.json'


def load_json(path, default=None):
    """載入 JSON 檔案"""
    if path.exists():
        with open(path, 'r', encoding='utf-8') as f:
            return json.load(f)
    return default if default is not None else {}


# 股票清單（從 stock_list.json 動態讀取，維護頁面在 stocks.php）
STOCK_LIST_FILE = DATA_DIR / 'stock_list.json'

def load_stock_list() -> list:
    """讀取股票清單（從 stock_list.json）"""
    if not STOCK_LIST_FILE.exists():
        return []
    try:
        data = load_json(STOCK_LIST_FILE, {'stocks': []})
        return data.get('stocks', [])
    except Exception:
        return []

STOCKS = load_stock_list()


def load_indicator_settings(strategy_idx: int = 0) -> dict:
    """載入技術指標參數設定 (strategy_idx: 0=策略1, 1=策略2)"""
    # 嘗試讀取本地設定檔
    settings = {}
    for path in [SETTINGS_FILE, MAIN_SETTINGS]:
        data = load_json(path)
        if data:
            settings = data
            break
    
    # 取得參數值的輔助函數
    def get_val(key, is_nested=False):
        if not is_nested:
            # 頂層 key: settings[key] = [5, 5]
            if key in settings:
                val = settings[key]
                if isinstance(val, list) and len(val) > strategy_idx:
                    return val[strategy_idx]
                return val
        return None
    
    def get_nested_val(category, key):
        if category in settings:
            cat = settings[category]
            if isinstance(cat, dict) and key in cat:
                val = cat[key]
                if isinstance(val, list) and len(val) > strategy_idx:
                    return val[strategy_idx]
                return val
        return None
    
    def get_thresh_val(key):
        if 'thresholds' in settings:
            thresh = settings['thresholds']
            if key in thresh:
                val = thresh[key]
                if isinstance(val, list) and len(val) > strategy_idx:
                    return val[strategy_idx]
                return val
        return None
    
    return {
        'ma': {
            'short': get_nested_val('ma', 'short') or 5,
            'long': get_nested_val('ma', 'long') or 20,
            'long60': get_nested_val('ma', 'long60') or 60
        },
        'rsi': {
            'period': get_nested_val('rsi', 'period') or 14
        },
        'kd': {
            'period': get_nested_val('kd', 'period') or 9,
            'k_smooth': get_nested_val('kd', 'k_smooth') or 3,
            'd_smooth': get_nested_val('kd', 'd_smooth') or 3
        },
        'macd': {
            'fast': get_nested_val('macd', 'fast') or 12,
            'slow': get_nested_val('macd', 'slow') or 26,
            'signal': get_nested_val('macd', 'signal') or 9
        },
        'thresholds': {
            'rsi_oversold': get_thresh_val('rsi_oversold') or 30,
            'rsi_overbought': get_thresh_val('rsi_overbought') or 70,
            'kd_oversold': get_thresh_val('kd_oversold') or 20,
            'kd_overbought': get_thresh_val('kd_overbought') or 80,
            'ma_cross': get_thresh_val('ma_cross') if get_thresh_val('ma_cross') is not None else True
        }
    }


def get_indicator_param(settings: dict, category: str, key: str, default) -> any:
    """取得單一參數值"""
    if category in settings and key in settings[category]:
        val = settings[category][key]
        if isinstance(val, list):
            return val[0]
        return val
    return default


def save_json(path, data):
    """儲存 JSON 檔案（權限不足時警告）"""
    try:
        with open(path, 'w', encoding='utf-8') as f:
            json.dump(data, f, ensure_ascii=False, indent=2)
    except PermissionError:
        print(f"  [警告] 無法寫入 {path}（權限不足）")


def fetch_stock_data(symbol: str) -> list | None:
    """從 Yahoo Finance 取得股價資料（使用 yfinance）"""
    try:
        df = yf.download(symbol, period="1y")
        if df.empty:
            return None

        # yfinance 某些版本會回傳多層欄位，先轉成單層欄位
        if isinstance(df.columns, pd.MultiIndex):
            df.columns = df.columns.get_level_values(0)

        prices = []
        for idx, row in df.iterrows():
            prices.append({
                'date': idx.strftime('%Y-%m-%d'),
                'open': float(row['Open']),
                'high': float(row['High']),
                'low': float(row['Low']),
                'close': float(row['Close']),
                'volume': int(row['Volume'])
            })

        return prices
    except Exception as e:
        print(f"  [錯誤] 無法取得 {symbol}: {e}")
        return None


def calculate_indicators(prices: list, settings: dict = None) -> dict | None:
    """計算技術指標 (使用參數設定)"""
    if len(prices) < 20:
        return None

    # 過濾 None 值
    closes = [p['close'] for p in prices if p['close'] is not None]
    if len(closes) < 20:
        return None

    # 取得參數（從設定檔或使用預設值）
    ma_short = 5
    ma_long = 20
    ma_long60 = 60
    rsi_period = 14
    kd_period = 9
    macd_fast = 12
    macd_slow = 26
    
    if settings:
        if 'ma' in settings:
            ma_short = settings['ma'].get('short', 5)
            ma_long = settings['ma'].get('long', 20)
            ma_long60 = settings['ma'].get('long60', 60)
        if 'rsi' in settings:
            rsi_period = settings['rsi'].get('period', 14)
        if 'kd' in settings:
            kd_period = settings['kd'].get('period', 9)
        if 'macd' in settings:
            macd_fast = settings['macd'].get('fast', 12)
            macd_slow = settings['macd'].get('slow', 26)

    # MA (移動平均線) - 使用參數
    ma_short_val = sum(closes[-ma_short:]) / ma_short if len(closes) >= ma_short else sum(closes[-5:]) / 5
    ma_long_val = sum(closes[-ma_long:]) / ma_long if len(closes) >= ma_long else sum(closes[-20:]) / 20
    ma_long60_val = sum(closes[-ma_long60:]) / ma_long60 if len(closes) >= ma_long60 else ma_long_val

    # RSI
    rsi = calculate_rsi(closes, rsi_period)

    # MACD
    macd = calculate_macd(closes, macd_fast, macd_slow)

    # KD
    kd = calculate_kd(prices, kd_period)

    return {
        'price': closes[-1],
        'ma_short': ma_short_val,
        'ma_long': ma_long_val,
        'ma_long60': ma_long60_val,
        'rsi': rsi,
        'macd': macd,
        'kd': kd,
        'params': {
            'ma_short': ma_short,
            'ma_long': ma_long,
            'rsi_period': rsi_period,
            'kd_period': kd_period
        }
    }


def calculate_rsi(closes: list, period: int = 14) -> float:
    """計算 RSI"""
    if len(closes) < period + 1:
        return 50.0

    prices = closes[-(period * 2):]
    gains = []
    losses = []

    for i in range(1, len(prices)):
        diff = prices[i] - prices[i - 1]
        if diff > 0:
            gains.append(diff)
            losses.append(0)
        else:
            gains.append(0)
            losses.append(abs(diff))

    avg_gain = sum(gains[-period:]) / period
    avg_loss = sum(losses[-period:]) / period

    if avg_loss == 0:
        return 100.0

    rs = avg_gain / avg_loss
    return 100 - (100 / (1 + rs))


def calculate_macd(closes: list, fast: int = 12, slow: int = 26) -> dict:
    """計算 MACD"""
    ema_fast = calculate_ema(closes, fast)
    ema_slow = calculate_ema(closes, slow)
    macd_value = ema_fast - ema_slow

    return {
        'value': macd_value,
        'signal': 0,
        'histogram': macd_value
    }


def calculate_kd(prices: list, period: int = 9) -> dict:
    """計算 KD (Stochastic)，使用 kd.py 的 EMA 型平滑公式"""
    if len(prices) < period:
        return {'k': 50.0, 'd': 50.0}

    k = 50.0
    d = 50.0

    # 從第 period 筆開始計算 RSV 並遞迴平滑
    for i in range(period - 1, len(prices)):
        window = prices[i - period + 1:i + 1]
        highs = [p['high'] for p in window if p['high'] is not None]
        lows = [p['low'] for p in window if p['low'] is not None]
        closes = [p['close'] for p in window if p['close'] is not None]

        if not highs or not lows or not closes:
            continue

        highest_high = max(highs)
        lowest_low = min(lows)
        current_close = closes[-1]

        if highest_high == lowest_low:
            rsv = 50.0
        else:
            rsv = ((current_close - lowest_low) / (highest_high - lowest_low)) * 100

        # EMA 型平滑（與 kd.py 一致）
        k = k * (2 / 3) + rsv * (1 / 3)
        d = d * (2 / 3) + k * (1 / 3)

    return {'k': k, 'd': d}


def calculate_ema(prices: list, period: int) -> float:
    """計算 EMA"""
    if len(prices) < period:
        return prices[0] if prices else 0

    multiplier = 2 / (period + 1)
    ema = prices[0]

    for price in prices[1:]:
        ema = (price * multiplier) + (ema * (1 - multiplier))

    return ema


def get_signals(indicators: dict, settings: dict = None) -> list:
    """產生買賣訊號 (使用參數設定)"""
    signals = []

    # 取得門檻值
    rsi_oversold = 30
    rsi_overbought = 70
    kd_oversold = 20
    kd_overbought = 80
    use_ma_cross = True
    
    if settings and 'thresholds' in settings:
        t = settings['thresholds']
        rsi_oversold = t.get('rsi_oversold', 30)
        rsi_overbought = t.get('rsi_overbought', 70)
        kd_oversold = t.get('kd_oversold', 20)
        kd_overbought = t.get('kd_overbought', 80)
        use_ma_cross = t.get('ma_cross', True)

    # MA 黃金交叉/死亡交叉
    if use_ma_cross:
        if indicators['ma_short'] > indicators['ma_long']:
            signals.append('MA_GOLDEN')
        elif indicators['ma_short'] < indicators['ma_long']:
            signals.append('MA_DEAD')

    # RSI - 使用設定的門檻
    if indicators['rsi'] < rsi_oversold:
        signals.append('RSI_OVERSOLD')
    elif indicators['rsi'] > rsi_overbought:
        signals.append('RSI_OVERBOUGHT')

    # MACD
    if indicators['macd']['value'] > indicators['macd']['signal']:
        signals.append('MACD_BUY')
    elif indicators['macd']['value'] < indicators['macd']['signal']:
        signals.append('MACD_SELL')

    # KD - 使用設定的門檻
    kd = indicators.get('kd', {})
    if kd and kd.get('k', 50) < kd_oversold:
        signals.append('KD_OVERSOLD')
    elif kd and kd.get('k', 50) > kd_overbought:
        signals.append('KD_OVERBOUGHT')

    return signals


def calc_trade_cost(action: str, total: float, tax_cfg: dict, fee_cfg: dict) -> dict:
    """計算交易成本（證交稅 + 券商手續費）

    Args:
        action: 'BUY' or 'SELL'
        total: 成交金額（price × quantity）
        tax_cfg: {'sell': 0.3, 'buy': 0}  # 百分比 (e.g. 0.3 = 3/1000)
        fee_cfg: {'rate': 0.1425, 'discount': 28, 'min': 20}

    Returns:
        {'tax': 稅額, 'fee': 手續費}
    """
    # 證交稅：依買/賣決定稅率
    tax_rate = tax_cfg.get('buy' if action == 'BUY' else 'sell', 0)
    tax = total * (tax_rate / 100)

    # 券商手續費：基本費率 × 折扣，最低 min
    fee_rate = (fee_cfg.get('rate', 0) * fee_cfg.get('discount', 100) / 100)
    fee = max(fee_cfg.get('min', 0), total * (fee_rate / 100))

    return {'tax': round(tax, 2), 'fee': round(fee, 2)}


def execute_trade(portfolio: dict, stock: str, action: str, price: float, quantity: int, tax_fee: dict = None) -> bool:
    """執行交易（含證交稅 + 手續費）"""
    total = price * quantity
    holdings = portfolio.get('holdings')
    if not isinstance(holdings, dict):
        # 相容舊版/異常資料：確保 holdings 一律為 dict
        portfolio['holdings'] = {}

    # 取得稅費設定（從 indicator_settings.json 或用預設）
    if tax_fee is None:
        settings = load_indicator_settings(0)
        tax_fee = {
            'tax': settings.get('tax', {'sell': 0.3, 'buy': 0}),
            'fee': settings.get('fee', {'rate': 0.1425, 'discount': 28, 'min': 20}),
        }

    if action == 'BUY':
        cost = calc_trade_cost('BUY', total, tax_fee['tax'], tax_fee['fee'])
        total_cost = total + cost['tax'] + cost['fee']

        if portfolio['cash'] >= total_cost:
            portfolio['cash'] -= total_cost
            portfolio['holdings'][stock] = portfolio['holdings'].get(stock, 0) + quantity
            portfolio['trades'].append({
                'date': datetime.now().strftime('%Y-%m-%d %H:%M:%S'),
                'stock': stock,
                'action': 'BUY',
                'price': price,
                'quantity': quantity,
                'total': total,
                'tax': cost['tax'],
                'fee': cost['fee'],
                'total_cost': total_cost,
            })
            return True
    elif action == 'SELL':
        if portfolio['holdings'].get(stock, 0) >= quantity:
            cost = calc_trade_cost('SELL', total, tax_fee['tax'], tax_fee['fee'])
            net_income = total - cost['tax'] - cost['fee']

            portfolio['cash'] += net_income
            portfolio['holdings'][stock] -= quantity
            if portfolio['holdings'][stock] <= 0:
                del portfolio['holdings'][stock]
            portfolio['trades'].append({
                'date': datetime.now().strftime('%Y-%m-%d %H:%M:%S'),
                'stock': stock,
                'action': 'SELL',
                'price': price,
                'quantity': quantity,
                'total': total,
                'tax': cost['tax'],
                'fee': cost['fee'],
                'net_income': net_income,
            })
            return True

    return False


def calculate_portfolio_value(portfolio: dict, prices: dict) -> float:
    """計算投資組合價值"""
    value = portfolio['cash']

    holdings = portfolio.get('holdings', {})
    if isinstance(holdings, dict):
        for stock, qty in holdings.items():
            if qty > 0 and stock in prices and prices[stock] is not None:
                value += qty * prices[stock]

    return value


def has_traded_today(portfolio: dict, stock: str) -> bool:
    """檢查同一策略帳戶今日是否已交易過該股票"""
    today = datetime.now().strftime('%Y-%m-%d')
    trades = portfolio.get('trades', [])
    if not isinstance(trades, list):
        return False

    for trade in reversed(trades):
        if trade.get('stock') != stock:
            continue
        trade_date = str(trade.get('date', ''))[:10]
        if trade_date == today:
            return True
    return False


def classify_signals(strategy_name: str, signals: list) -> tuple:
    """根據策略分類買進/賣出訊號。回傳 (buy_signals, sell_signals)。"""
    if strategy_name == '策略1':
        # 策略1：MA + RSI + MACD 混合
        buy_signals = [s for s in signals if s in ['MA_GOLDEN', 'RSI_OVERSOLD', 'MACD_BUY']]
        sell_signals = [s for s in signals if s in ['MA_DEAD', 'RSI_OVERBOUGHT', 'MACD_SELL']]
    else:
        # 策略2：KD 隨機指標 (K < 20 買，K > 80 賣)
        buy_signals = [s for s in signals if s == 'KD_OVERSOLD']
        sell_signals = [s for s in signals if s == 'KD_OVERBOUGHT']
    return buy_signals, sell_signals


def calc_sell_quantity(holdings: int, current_price: float) -> tuple:
    """計算賣出股數（全賣）。回傳 (quantity, reason)。

    規則：
      - 庫存 <= 0 → (0, '無庫存可賣')
      - 量必須達最小交易金額 (1萬 / 100股取高者)，否則 (0, reason)
    """
    if holdings <= 0:
        return 0, '無庫存可賣'

    quantity = holdings  # 100% 賣出（不保留倉位）
    amount = quantity * current_price

    # 最小交易金額檢查
    min_shares_by_amount = math.ceil(MIN_TRADE_AMOUNT / current_price)
    min_sell_shares = max(1, min_shares_by_amount, MIN_TRADE_SHARES)
    if quantity < min_sell_shares:
        return 0, f'庫存不足最小交易股數 ({min_sell_shares} 股)'

    return quantity, ''


def calc_buy_quantity(target_amount: float, current_price: float, cash: float) -> tuple:
    """計算買進股數。回傳 (quantity, reason)。

    規則（兩段式平均分配）：
      - target_amount = 總現金 / 買進標的數量（已在 main() 平均分好）
      - qty = floor(target_amount / price)
      - 若 qty * price + 手續費 > cash → 跳過（現金不足）
      - 若 qty * price < 最小交易金額 (1萬 / 100股) → 跳過（量太少）

    Args:
        target_amount: 本標的被分配到的金額
        current_price: 現價
        cash: 目前帳戶現金
    """
    if cash < current_price:
        return 0, f'現金不足 ({cash:,.0f})'

    quantity = int(target_amount / current_price)
    if quantity <= 0:
        return 0, f'分配金額不足 1 股 ({int(target_amount):,} 元)'

    # 預估手續費（採最大保守值）
    estimated_fee = max(20, quantity * current_price * 0.1425 * 28 / 10000)
    if quantity * current_price + estimated_fee > cash:
        return 0, f'現金不足以支付交易金額 + 手續費 ({cash:,.0f})'

    # 最小交易金額檢查（1萬 或 100股，取高者）
    if quantity < MIN_TRADE_SHARES:
        return 0, f'股數 ({quantity}) 不足最小交易股數 ({MIN_TRADE_SHARES})'
    if quantity * current_price < MIN_TRADE_AMOUNT:
        return 0, f'金額 ({quantity * current_price:,.0f}) 不足最小交易金額 ({MIN_TRADE_AMOUNT:,} 元)'

    return quantity, ''


def execute_strategy_action(strategy_name: str, portfolio: dict, stock: str,
                             action: str, current_price: float,
                             target_amount: float = 0) -> dict:
    """執行單一交易動作（BUY 或 SELL）。

    Args:
        strategy_name: 策略名稱
        portfolio: 帳戶 dict（會被 in-place 修改）
        stock: 股票代號
        action: 'BUY' or 'SELL'
        current_price: 現價
        target_amount: 買進金額（僅 BUY 用，賣出忽略）
    """
    # has_traded_today 只擋買進（同一支股票當天只能買一次）
    # 賣出不受限，避免同日買進後賣出被擋
    if action == 'BUY' and has_traded_today(portfolio, stock):
        return {'action': 'NONE', 'reason': '今日已交易過此股票'}

    cash = portfolio['cash']
    holdings = portfolio['holdings'].get(stock, 0)

    if action == 'BUY':
        quantity, reason = calc_buy_quantity(target_amount, current_price, cash)
        if quantity <= 0:
            return {'action': 'NONE', 'reason': reason}
    elif action == 'SELL':
        quantity, reason = calc_sell_quantity(holdings, current_price)
        if quantity <= 0:
            return {'action': 'NONE', 'reason': reason}
    else:
        return {'action': 'NONE', 'reason': f'未知動作: {action}'}

    if execute_trade(portfolio, stock, action, current_price, quantity):
        return {
            'action': action,
            'quantity': quantity,
            'price': current_price,
            'reason': f'成交 ({quantity} 股 @ {current_price})',
        }
    return {'action': 'NONE', 'reason': '執行失敗'}


# 訊號代碼 → 中文（profit_history.php 顯示用）
SIGNAL_LABELS = {
    'MA_GOLDEN': 'MA黃金交叉',
    'MA_DEAD': 'MA死亡交叉',
    'RSI_OVERSOLD': 'RSI超賣',
    'RSI_OVERBOUGHT': 'RSI超買',
    'MACD_BUY': 'MACD買進',
    'MACD_SELL': 'MACD賣出',
    'KD_OVERSOLD': 'KD超賣',
    'KD_OVERBOUGHT': 'KD超買',
}


def _record_strategy_result(daily_analysis, strategy_name, stock, result, trigger):
    """記錄單一交易的最終結果到 daily_analysis。

    result 是 execute_strategy_action() 回傳的 dict。
    把 PENDING 狀態寫入「人類可讀的最終狀態」。
    """
    signals_zh = SIGNAL_LABELS.get(trigger, trigger)
    if result['action'] in ('BUY', 'SELL'):
        qty = result['quantity']
        price = result['price']
        daily_analysis[stock]['strategies'][strategy_name] = {
            'action': result['action'],
            'quantity': qty,
            'price': price,
            'signal': trigger,
            'signal_zh': signals_zh,
            'reason': f'成交 {qty} 股 @ {price:.2f} ({signals_zh})',
        }
    else:
        reason = result['reason']
        daily_analysis[stock]['strategies'][strategy_name] = {
            'action': 'NONE',
            'signal': trigger,
            'signal_zh': signals_zh,
            'reason': f'跳過 ({signals_zh} 觸發)：{reason}',
        }


def _record_strategy_result_none(daily_analysis, strategy_name, stock):
    """記錄「階段 1 該策略對此標的沒被加入 targets」的結果。"""
    info = daily_analysis[stock]['strategies'].get(strategy_name, {})
    if not info.get('_pending'):
        return  # 已經記錄過（BUY/SELL/SKIP），略過
    signals = info.get('signals', [])
    had_holdings = info.get('had_holdings', False)
    buy_sigs = info.get('buy_signals', [])
    sell_sigs = info.get('sell_signals', [])

    # 決定 reason
    if signals:
        sig_text = '、'.join(SIGNAL_LABELS.get(s, s) for s in signals)
    else:
        sig_text = '無訊號'

    if buy_sigs:
        reason = f'已有庫存，跳過買進訊號'
    elif sell_sigs:
        reason = f'無庫存可賣 ({sig_text})'
    elif not signals:
        reason = '無買賣訊號'
    else:
        reason = f'未達下單條件 ({sig_text})'

    daily_analysis[stock]['strategies'][strategy_name] = {
        'action': 'NONE',
        'reason': reason,
        'signals': signals,
    }


def show_status():
    """顯示現有狀態"""
    portfolio = load_json(PORTFOLIO_FILE, {})
    profit_history = load_json(PROFIT_HISTORY_FILE, {})
    all_data = load_json(DATA_FILE, {})

    today = datetime.now().strftime('%Y-%m-%d')

    print("\n=== 股市模擬系統狀態 ===")
    print(f"時間: {datetime.now().strftime('%Y-%m-%d %H:%M:%S')}")
    print(f"資料更新: {today}\n")

    for strategy in STRATEGIES:
        strat_portfolio = portfolio.get(strategy, {'cash': INITIAL_CAPITAL, 'holdings': {}, 'trades': []})

        # 取得目前股價（找到最後一個有效價格）
        current_prices = {}
        for stock in STOCKS:
            if stock in all_data and all_data[stock]:
                # 找到最後一個有效收盤價
                for p in reversed(all_data[stock]):
                    if p['close'] is not None:
                        current_prices[stock] = p['close']
                        break

        value = calculate_portfolio_value(strat_portfolio, current_prices)
        profit = value - INITIAL_CAPITAL
        profit_rate = (profit / INITIAL_CAPITAL) * 100

        print(f"{strategy} ({STRATEGY_NAMES[strategy]}):")
        print(f"  現金: {strat_portfolio['cash']:,.2f}")
        print(f"  總資產: {value:,.2f}")
        print(f"  獲利: {profit:,.2f} ({profit_rate:+.2f}%)")

        holdings = strat_portfolio.get('holdings', {})
        if holdings and isinstance(holdings, dict) and any(v > 0 for v in holdings.values()):
            print(f"  庫存:")
            for stock, qty in holdings.items():
                if qty > 0:
                    price = current_prices.get(stock)
                    price_str = f"{price:.2f}" if price else "N/A"
                    print(f"    {stock}: {qty} 股 @ {price_str}")
        else:
            print(f"  庫存: 無")

        print(f"  交易次數: {len(strat_portfolio['trades'])}")

        # 當日績效
        if strategy in profit_history and today in profit_history[strategy]:
            print(f"  今日績效: {profit_history[strategy][today]:+.2f}%")

        print()


def main():
    parser = argparse.ArgumentParser(description='股市模擬投資系統')
    parser.add_argument('--update', action='store_true', help='只更新股價，不交易')
    parser.add_argument('--status', action='store_true', help='顯示現有狀態')
    args = parser.parse_args()

    if args.status:
        show_status()
        return

    is_update_only = args.update

    print("\n=== 股市模擬系統開始執行 ===")
    print(f"時間: {datetime.now().strftime('%Y-%m-%d %H:%M:%S')}")
    print(f"模式: {'股價更新(只更新股價,不交易)' if is_update_only else '完整交易(含股價更新)'}\n")

    # 載入投資組合與獲利歷史（不改變 stock_data.json）
    all_portfolios = load_json(PORTFOLIO_FILE, {})
    all_data = {}

    # 正規化舊資料格式，避免 holdings/trades 型別錯誤
    for strategy_name, portfolio_data in list(all_portfolios.items()):
        if not isinstance(portfolio_data, dict):
            all_portfolios[strategy_name] = {
                'cash': float(INITIAL_CAPITAL),
                'holdings': {},
                'trades': []
            }
            continue

        if not isinstance(portfolio_data.get('holdings'), dict):
            portfolio_data['holdings'] = {}
        if not isinstance(portfolio_data.get('trades'), list):
            portfolio_data['trades'] = []
        if not isinstance(portfolio_data.get('cash'), (int, float)):
            portfolio_data['cash'] = float(INITIAL_CAPITAL)

    # 確保兩個策略帳戶都存在
    for s in STRATEGIES:
        if s not in all_portfolios:
            all_portfolios[s] = {
                'cash': float(INITIAL_CAPITAL),
                'holdings': {},
                'trades': []
            }

    current_prices = {}
    daily_analysis = {}

    # 兩段式下單：階段 1 收集 buy/sell targets，階段 2 依序執行
    pending_targets = {s: {'buy': [], 'sell': []} for s in STRATEGIES if s != '手動操作'}

    # 取得所有股票資料
    for symbol in STOCKS:
        print(f"抓取 {symbol} ...")
        prices = fetch_stock_data(symbol)

        if prices:
            last_close = prices[-1]['close']
            if last_close is None:
                # 找到最後一個有效價格
                for p in reversed(prices):
                    if p['close'] is not None:
                        last_close = p['close']
                        break
                else:
                    last_close = 0
            current_prices[symbol] = last_close
            all_data[symbol] = prices
            print(f"  最新價格: {current_prices[symbol]:.2f}")

            # 完整交易模式：兩段式下單
            #   階段 1（下方 for-stock-loop）：抓資料、計算訊號、但不下單
            #   階段 2（下方 for-strategy-loop）：先賣後買、平均分配 cash
            # 不再即時下單，避免半倉遞減導致前面買太重、後面無錢
            if not is_update_only:
                daily_analysis[symbol] = {'strategies': {}}

                # 階段 1：每個策略只看一次訊號（供階段 2 使用）
                # 結構: strategy_signals[strategy_name] = {'buy': [...], 'sell': [...], 'price': float, 'signals': [...]}
                strategy_signals = {}

                for idx, strategy_name in enumerate(STRATEGIES):
                    if strategy_name == '手動操作':
                        continue
                    settings = load_indicator_settings(idx)
                    indicators = calculate_indicators(prices, settings)
                    signals = get_signals(indicators, settings)

                    # 策略1的技術指標作為每日分析展示
                    if idx == 0:
                        params = indicators.get('params', {})
                        print(f"  技術指標: MA{params.get('ma_short',5)}={indicators['ma_short']:.2f}, MA{params.get('ma_long',20)}={indicators['ma_long']:.2f}, RSI={indicators['rsi']:.1f}, KD={indicators['kd']['k']:.1f}/{indicators['kd']['d']:.1f}")
                        print(f"  買賣訊號: {', '.join(signals) if signals else '無'}")
                        daily_analysis[symbol].update({
                            'price': current_prices[symbol],
                            'ma_short': round(indicators['ma_short'], 2),
                            'ma_long': round(indicators['ma_long'], 2),
                            'rsi': round(indicators['rsi'], 1),
                            'macd': indicators['macd']['value'],
                            'kd_k': round(indicators['kd']['k'], 1),
                            'kd_d': round(indicators['kd']['d'], 1),
                            'signals': signals,
                            'params': params,
                            'thresholds': settings.get('thresholds', {})
                        })

                    buy_sig, sell_sig = classify_signals(strategy_name, signals)
                    strategy_signals[strategy_name] = {
                        'buy': buy_sig,
                        'sell': sell_sig,
                        'price': current_prices[symbol],
                        'signals': signals,
                    }

                # 存到外層 dict，供階段 2 使用
                for strategy_name, info in strategy_signals.items():
                    if strategy_name not in pending_targets:
                        pending_targets[strategy_name] = {'buy': [], 'sell': []}
                    # 有庫存 + 賣出訊號 → 加入賣出清單
                    portfolio = all_portfolios[strategy_name]
                    holdings_data = portfolio.get('holdings', {})
                    if not isinstance(holdings_data, dict):
                        holdings_data = {}
                    holdings = holdings_data.get(symbol, 0)

                    if holdings > 0 and info['sell']:
                        pending_targets[strategy_name]['sell'].append({
                            'stock': symbol,
                            'price': info['price'],
                            'qty': holdings,
                            'trigger': info['sell'][0],
                        })
                    elif info['buy'] and holdings == 0:
                        pending_targets[strategy_name]['buy'].append({
                            'stock': symbol,
                            'price': info['price'],
                            'trigger': info['buy'][0],
                        })
                    # daily_analysis 暫存階段 1 訊號，階段 2 跑完才寫最終結果
                    # 避免 PENDING 這種中間狀態被誤讀
                    daily_analysis[symbol]['strategies'][strategy_name] = {
                        '_pending': True,    # 標記，階段 2 結束後寫入
                        'signals': info['signals'],
                        'buy_signals': info['buy'],
                        'sell_signals': info['sell'],
                        'had_holdings': holdings > 0,
                    }
        else:
            # 無法取得資料時顯示錯誤
            print(f"  [錯誤] 無法取得 {symbol} 資料")

        print()

    # ============================================================
    # 階段 2：兩段式下單 — 先賣後買、平均分配
    # ============================================================
    if not is_update_only:
        has_targets = any(pending_targets[s]['buy'] or pending_targets[s]['sell'] for s in pending_targets)
        if has_targets:
            print("\n=== 階段 2：兩段式下單 ===")
        for strategy_name in STRATEGIES:
            if strategy_name == '手動操作':
                continue
            targets = pending_targets.get(strategy_name, {'buy': [], 'sell': []})
            portfolio = all_portfolios[strategy_name]

            # 2a. 先賣（賣出回收現金是好事）
            if targets['sell']:
                print(f"\n[{strategy_name}] 賣出 ({len(targets['sell'])} 個標的):")
            for t in targets['sell']:
                result = execute_strategy_action(
                    strategy_name, portfolio, t['stock'], 'SELL', t['price']
                )
                # 記錄結果到 daily_analysis
                _record_strategy_result(daily_analysis, strategy_name, t['stock'], result, t['trigger'])
                if result['action'] == 'SELL':
                    print(f"  ✅ SELL {t['stock']} {result['quantity']} 股 @ {result['price']:.2f} (觸發: {t['trigger']})")
                else:
                    print(f"  ⏭️  SELL {t['stock']} 跳過: {result['reason']}")

            # 2b. 平均分配 cash 給 buy 清單
            buy_list = targets['buy']
            if not buy_list:
                # 即使沒買進訊號，也要記錄 NONE 給所有「階段 1 沒被選中」的標的
                for symbol in daily_analysis:
                    strat_info = daily_analysis[symbol].get('strategies', {}).get(strategy_name)
                    if strat_info and strat_info.get('_pending'):
                        _record_strategy_result_none(daily_analysis, strategy_name, symbol)
                continue

            # cash 已扣除賣出回收，重新讀現值
            available_cash = portfolio['cash']
            per_target = available_cash / len(buy_list)
            print(f"\n[{strategy_name}] 買進 ({len(buy_list)} 個標的), 現金 {available_cash:,.0f} 平均分配 {per_target:,.0f}/標的:")

            for t in buy_list:
                result = execute_strategy_action(
                    strategy_name, portfolio, t['stock'], 'BUY', t['price'], per_target
                )
                _record_strategy_result(daily_analysis, strategy_name, t['stock'], result, t['trigger'])
                if result['action'] == 'BUY':
                    print(f"  ✅ BUY {t['stock']} {result['quantity']} 股 @ {result['price']:.2f} (觸發: {t['trigger']})")
                else:
                    print(f"  ⏭️  BUY {t['stock']} 跳過: {result['reason']}")

            # 把「階段 1 沒被加入 targets 的標的」補上 NONE（例：沒有買賣訊號的）
            for symbol in daily_analysis:
                strat_info = daily_analysis[symbol].get('strategies', {}).get(strategy_name)
                if strat_info and strat_info.get('_pending'):
                    _record_strategy_result_none(daily_analysis, strategy_name, symbol)

    # 完整交易模式：計算績效
    if not is_update_only:
        print("\n=== 投資績效 ===")
        today = datetime.now().strftime('%Y-%m-%d')
        profit_history = load_json(PROFIT_HISTORY_FILE, {})

        for strategy in STRATEGIES:
            portfolio = all_portfolios[strategy]
            current_value = calculate_portfolio_value(portfolio, current_prices)
            profit = current_value - INITIAL_CAPITAL
            profit_rate = (profit / INITIAL_CAPITAL) * 100

            print(f"{strategy} ({STRATEGY_NAMES[strategy]}):")
            print(f"  初始資金: {INITIAL_CAPITAL:,.0f}")
            print(f"  目前價值: {current_value:,.2f}")
            print(f"  獲利: {profit:+,.2f} ({profit_rate:+.2f}%)")
            print(f"  庫存: {json.dumps(portfolio['holdings'], ensure_ascii=False)}")
            print(f"  交易次數: {len(portfolio['trades'])}")
            print()

            # 記錄每日獲利
            if strategy not in profit_history:
                profit_history[strategy] = {}
            profit_history[strategy][today] = round(profit_rate, 2)

        # 儲存每日分析
        save_json(ANALYSIS_FILE, daily_analysis)
        save_json(MAIN_ANALYSIS, daily_analysis)

        # 儲存獲利歷史
        save_json(PROFIT_HISTORY_FILE, profit_history)
        save_json(MAIN_PROFIT, profit_history)

        # 儲存投資組合
        save_json(PORTFOLIO_FILE, all_portfolios)
        save_json(MAIN_PORTFOLIO, all_portfolios)

    # 儲存股價資料（供 Dashboard 顯示）
    save_json(DATA_FILE, all_data)
    save_json(MAIN_DATA, all_data)

    print(f"{'股價更新' if is_update_only else '執行'}完成!\n")


if __name__ == '__main__':
    main()
