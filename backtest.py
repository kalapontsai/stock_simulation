"""
回測引擎（隔離 in-memory，不寫入 portfolio.json / daily_analysis.json）

設計原則：
1. 重用 stock_trader.py 的指標函式（calculate_indicators / get_signals / calc_trade_cost）
2. 純函式：run_backtest() 只回傳 dict，副作用由 save_result() 處理
3. 兩段式下單：先賣後買、平均分配現金
4. 交易頻率：rebalance_n 控制（每 N 天才檢查訊號並下單）
5. Buy & Hold 對照組：day 0 等額買進、持有到期末

CLI 用法：
  python backtest.py --start 2025-08-12 --end 2026-08-12 \\
                    --strategy 0 --rebalance 5
                    [--symbol 2330.TW]

選股範圍：
  - 不傳 --symbol（或 request.body.symbol 為 null/空）：跑 stock_data.json 全部
  - 傳 --symbol 2330.TW：只跑該單一股票
  - 找不到該代號 → 回傳錯誤（不例外崩潰）
"""

import sys
import json
import math
import argparse
from pathlib import Path
from datetime import datetime, date

# 讓我們可以 import stock_trader.py
sys.path.insert(0, str(Path(__file__).parent))

from stock_trader import (
    calculate_indicators,
    get_signals,
    calc_trade_cost,
    STRATEGIES,
    DATA_DIR,
    DATA_FILE,
)

# === 常數 ===

# 結果檔（append-only）
RESULTS_FILE = DATA_DIR / 'backtest_results.json'

# 最小交易限制（與 stock_trader.py 一致）
MIN_TRADE_SHARES = 100
MIN_TRADE_AMOUNT = 10_000

# 歷史保留筆數
MAX_HISTORY = 50

# 預設稅費
DEFAULT_TAX_FEE = {
    'tax': {'buy': 0, 'sell': 0.3},
    'fee': {'rate': 0.1425, 'discount': 28, 'min': 20},
}


# === 工具函式 ===

def load_stock_data() -> dict:
    """Load OHLCV data per stock."""
    if not DATA_FILE.exists():
        return {}
    with open(DATA_FILE, 'r', encoding='utf-8') as f:
        return json.load(f)


def build_aligned_dates(stock_data: dict, start: str, end: str) -> list:
    """Build sorted list of trading dates in [start, end] that ALL stocks have.

    交集：避免單一股票缺資料造成 NaN / 空 slice 問題。
    """
    if not stock_data:
        return []
    date_sets = []
    for sym, prices in stock_data.items():
        s = set()
        for p in prices:
            d = p.get('date')
            if d and start <= d <= end:
                s.add(d)
        date_sets.append(s)
    if not date_sets:
        return []
    # 交集（所有股票都有的日期）
    aligned = date_sets[0]
    for s in date_sets[1:]:
        aligned &= s
    return sorted(aligned)


def slice_history(stock_data: dict, up_to_date: str) -> dict:
    """For each stock, return OHLCV list up to and including up_to_date."""
    result = {}
    for sym, prices in stock_data.items():
        sliced = [p for p in prices if p.get('date', '') <= up_to_date]
        if sliced:
            result[sym] = sliced
    return result


def current_prices_from(stock_data: dict, on_date: str) -> dict:
    """Get closing prices on a specific date for each stock."""
    result = {}
    for sym, prices in stock_data.items():
        for p in prices:
            if p.get('date') == on_date and p.get('close') is not None:
                result[sym] = p['close']
                break
    return result


def portfolio_value(cash: float, holdings: dict, prices: dict) -> float:
    """Total portfolio value (cash + 持倉市值)."""
    val = float(cash)
    for sym, qty in holdings.items():
        if qty > 0 and sym in prices:
            val += qty * prices[sym]
    return val


def classify_signals(strategy_idx: int, signals: list) -> tuple:
    """依策略分類買進/賣出訊號。回傳 (buy_list, sell_list)。"""
    if strategy_idx == 0:
        # 策略1：MA + RSI + MACD 混合
        buy = [s for s in signals if s in ['MA_GOLDEN', 'RSI_OVERSOLD', 'MACD_BUY']]
        sell = [s for s in signals if s in ['MA_DEAD', 'RSI_OVERBOUGHT', 'MACD_SELL']]
    else:
        # 策略2：KD 隨機指標
        buy = [s for s in signals if s == 'KD_OVERSOLD']
        sell = [s for s in signals if s == 'KD_OVERBOUGHT']
    return buy, sell


def _load_default_settings(strategy_idx: int) -> dict:
    """預設指標參數（避免讀 indicator_settings.json 的 side effect）。"""
    return {
        'ma': {'short': 5, 'long': 20, 'long60': 60},
        'rsi': {'period': 14},
        'kd': {'period': 9, 'k_smooth': 3, 'd_smooth': 3},
        'macd': {'fast': 12, 'slow': 26, 'signal': 9},
        'thresholds': {
            'rsi_oversold': 30, 'rsi_overbought': 70,
            'kd_oversold': 20, 'kd_overbought': 80,
            'ma_cross': True,
        },
        'position': {
            'buy_unit_pct': 20, 'sell_unit_pct': 50,
            'max_positions': 5, 'min_cash_reserve_pct': 10,
            'use_kd_strength': False, 'kd_strength_max': 30,
        }
    }


# === 單日模擬（純函式：回傳 pending targets，不執行交易） ===

def _collect_targets(
    on_date: str,
    sliced_data: dict,
    portfolio: dict,
    strategy_idx: int,
    settings: dict,
) -> dict:
    """
    收集當日 buy/sell targets（不實際下單）。
    Returns: {'buy': [{stock, price, trigger}], 'sell': [...]}
    """
    pending = {'buy': [], 'sell': []}
    current_prices = current_prices_from(sliced_data, on_date)
    if not current_prices:
        return pending

    for sym, prices in sliced_data.items():
        if sym not in current_prices:
            continue
        if len(prices) < 30:
            continue
        indicators = calculate_indicators(prices, settings)
        if indicators is None:
            continue
        signals = get_signals(indicators, settings)
        buy, sell = classify_signals(strategy_idx, signals)
        if not buy and not sell:
            continue

        holdings = portfolio['holdings'].get(sym, 0)
        price = current_prices[sym]

        if holdings > 0 and sell:
            pending['sell'].append({
                'stock': sym, 'price': price, 'qty': holdings, 'trigger': sell[0],
            })
        elif buy and holdings == 0:
            pending['buy'].append({
                'stock': sym, 'price': price, 'trigger': buy[0],
            })
    return pending


def _execute_buy_list(
    portfolio: dict,
    buy_targets: list,
    on_date: str,
    tax_fee: dict,
) -> list:
    """平均分配現金買進，回傳實際執行的 trades。"""
    trades = []
    if not buy_targets:
        return trades
    available = portfolio['cash']
    if available <= 0:
        return trades
    per_target = available / len(buy_targets)

    for t in buy_targets:
        sym = t['stock']
        price = t['price']
        qty = int(per_target / price)
        if qty < MIN_TRADE_SHARES:
            continue
        cost_amount = qty * price
        if cost_amount > portfolio['cash']:
            qty = int(portfolio['cash'] / price)
            if qty < MIN_TRADE_SHARES:
                continue
            cost_amount = qty * price

        fee = calc_trade_cost('BUY', cost_amount, tax_fee['tax'], tax_fee['fee'])
        total_cost = cost_amount + fee['tax'] + fee['fee']
        if total_cost > portfolio['cash']:
            continue

        portfolio['cash'] -= total_cost
        portfolio['holdings'][sym] = portfolio['holdings'].get(sym, 0) + qty
        trades.append({
            'date': on_date, 'stock': sym, 'action': 'BUY',
            'price': price, 'quantity': qty,
            'total': cost_amount, 'tax': fee['tax'], 'fee': fee['fee'],
            'total_cost': total_cost, 'trigger': t['trigger'],
        })
    return trades


def _execute_sell_list(
    portfolio: dict,
    sell_targets: list,
    on_date: str,
    tax_fee: dict,
) -> list:
    """執行賣出（不平均分配，全賣）。回傳 trades。"""
    trades = []
    for t in sell_targets:
        sym = t['stock']
        qty = t['qty']
        price = t['price']
        if qty <= 0 or qty > portfolio['holdings'].get(sym, 0):
            continue
        if qty < MIN_TRADE_SHARES:
            continue

        cost_amount = qty * price
        fee = calc_trade_cost('SELL', cost_amount, tax_fee['tax'], tax_fee['fee'])
        net_income = cost_amount - fee['tax'] - fee['fee']

        portfolio['cash'] += net_income
        portfolio['holdings'][sym] -= qty
        if portfolio['holdings'][sym] <= 0:
            del portfolio['holdings'][sym]
        trades.append({
            'date': on_date, 'stock': sym, 'action': 'SELL',
            'price': price, 'quantity': qty,
            'total': cost_amount, 'tax': fee['tax'], 'fee': fee['fee'],
            'net_income': net_income, 'trigger': t['trigger'],
        })
    return trades


# === Buy & Hold 對照組 ===

def _init_buyhold(initial_capital: float, day0_prices: dict) -> dict:
    """Day 0 等額買進（不扣稅費，簡化對照組）。"""
    portfolio = {'cash': float(initial_capital), 'holdings': {}, 'trades': []}
    if not day0_prices:
        return portfolio
    per_target = initial_capital / len(day0_prices)
    for sym, price in day0_prices.items():
        qty = int(per_target / price)
        if qty <= 0:
            continue
        if qty * price > portfolio['cash']:
            qty = int(portfolio['cash'] / price)
        if qty <= 0:
            continue
        portfolio['cash'] -= qty * price
        portfolio['holdings'][sym] = qty
    return portfolio


# === 主迴圈 ===

def run_backtest(
    start_date: str,
    end_date: str,
    strategy_idx: int,
    rebalance_n: int = 1,
    settings: dict = None,
    initial_capital: float = 1_000_000,
    tax_fee: dict = None,
    stock_data: dict = None,
    symbol: str | None = None,
) -> dict:
    """跑回測，回傳 {kpi, equity_curve, buyhold_curve, trades, run_meta}。

    選股範圍：
      - symbol=None（或空字串）：跑 stock_data.json 全部股票
      - symbol='2330.TW'：只跑該單一股票（找不到代號 → 回傳錯誤 run_meta）

    注意：純函式，不寫任何檔案（持久化由 save_result() 處理）。
    """
    if settings is None:
        settings = _load_default_settings(strategy_idx)
    if tax_fee is None:
        tax_fee = DEFAULT_TAX_FEE
    if stock_data is None:
        stock_data = load_stock_data()

    # 正規化 symbol：空白字串 → None；存在性檢查；找不到返回錯誤
    if symbol is not None:
        symbol = symbol.strip().upper()
        if not symbol:
            symbol = None
        elif symbol not in stock_data:
            return {
                'kpi': _empty_kpi(initial_capital),
                'equity_curve': [],
                'buyhold_curve': [],
                'trades': [],
                'run_meta': {
                    'error': f'找不到股票代號 "{symbol}"（資料庫無此股票）',
                    'start_date': start_date,
                    'end_date': end_date,
                    'strategy': STRATEGIES[strategy_idx],
                    'rebalance_n': rebalance_n,
                    'n_days': 0,
                    'initial_capital': initial_capital,
                    'symbol': symbol,
                    'n_stocks': 0,
                },
            }

    # 單一股票模式：過濾 stock_data
    n_stocks = len(stock_data)
    if symbol is not None:
        stock_data = {symbol: stock_data[symbol]}
        n_stocks = 1

    dates = build_aligned_dates(stock_data, start_date, end_date)
    if len(dates) < 2:
        return {
            'kpi': _empty_kpi(initial_capital),
            'equity_curve': [],
            'buyhold_curve': [],
            'trades': [],
            'run_meta': {
                'error': '資料不足 (< 2 個交易日)',
                'start_date': start_date,
                'end_date': end_date,
                'strategy': STRATEGIES[strategy_idx],
                'rebalance_n': rebalance_n,
                'n_days': len(dates),
                'initial_capital': initial_capital,
                'symbol': symbol,
                'n_stocks': n_stocks,
            },
        }

    # 隔離 portfolio（in-memory）
    portfolio = {
        'cash': float(initial_capital),
        'holdings': {},
        'trades': [],
    }

    # Buy & Hold：day 0 等額買進
    day0_prices = current_prices_from(stock_data, dates[0])
    buyhold = _init_buyhold(initial_capital, day0_prices)

    equity_curve = []
    buyhold_curve = []

    for i, on_date in enumerate(dates):
        today_prices = current_prices_from(stock_data, on_date)
        if not today_prices:
            continue

        # 是否為 rebalance 日（每 N 天檢查一次）
        is_rebalance = (i % rebalance_n == 0)

        if is_rebalance:
            sliced = slice_history(stock_data, on_date)
            pending = _collect_targets(
                on_date=on_date,
                sliced_data=sliced,
                portfolio=portfolio,
                strategy_idx=strategy_idx,
                settings=settings,
            )
            # 先賣後買（兩段式）
            sell_trades = _execute_sell_list(portfolio, pending['sell'], on_date, tax_fee)
            portfolio['trades'].extend(sell_trades)
            buy_trades = _execute_buy_list(portfolio, pending['buy'], on_date, tax_fee)
            portfolio['trades'].extend(buy_trades)

        # Mark-to-market
        val = portfolio_value(portfolio['cash'], portfolio['holdings'], today_prices)
        equity_curve.append({'date': on_date, 'value': round(val, 2)})

        bh_val = portfolio_value(buyhold['cash'], buyhold['holdings'], today_prices)
        buyhold_curve.append({'date': on_date, 'value': round(bh_val, 2)})

    # KPI
    kpi = compute_metrics(equity_curve, portfolio['trades'])
    bh_initial = buyhold_curve[0]['value'] if buyhold_curve else initial_capital
    bh_final = buyhold_curve[-1]['value'] if buyhold_curve else initial_capital
    if bh_initial > 0:
        kpi['buyhold_return_pct'] = round((bh_final - bh_initial) / bh_initial * 100, 2)
    else:
        kpi['buyhold_return_pct'] = 0.0

    return {
        'kpi': kpi,
        'equity_curve': equity_curve,
        'buyhold_curve': buyhold_curve,
        'trades': portfolio['trades'],
        'run_meta': {
            'start_date': start_date,
            'end_date': end_date,
            'strategy': STRATEGIES[strategy_idx],
            'strategy_idx': strategy_idx,
            'rebalance_n': rebalance_n,
            'n_days': len(dates),
            'initial_capital': initial_capital,
            'symbol': symbol,
            'n_stocks': n_stocks,
        },
    }


# === KPI 計算 ===

def _empty_kpi(initial_capital: float) -> dict:
    return {
        'total_return_pct': 0.0,
        'annualized_return_pct': 0.0,
        'mdd_pct': 0.0,
        'win_rate_pct': 0.0,
        'n_trades': 0,
        'buyhold_return_pct': 0.0,
        'final_value': round(initial_capital, 2),
    }


def _compute_closed_trades(trades: list) -> list:
    """FIFO 計算每筆 SELL 的已實現損益。

    Args:
        trades: 交易紀錄 list

    Returns:
        [{'stock': ..., 'pnl': ..., 'qty': ...}, ...]
    """
    buy_queues = {}  # stock -> [{'price': ..., 'qty': ...}, ...]
    closed = []
    for t in trades:
        sym = t.get('stock')
        if not sym:
            continue
        action = t.get('action')
        if action == 'BUY':
            buy_queues.setdefault(sym, []).append({
                'price': t['price'],
                'qty': t['quantity'],
            })
        elif action == 'SELL':
            qty_to_sell = t['quantity']
            sell_price = t['price']
            sell_tax = t.get('tax', 0)
            sell_fee = t.get('fee', 0)
            sell_total_tax_fee = sell_tax + sell_fee
            original_qty = qty_to_sell

            while qty_to_sell > 0 and buy_queues.get(sym):
                oldest = buy_queues[sym][0]
                matched = min(oldest['qty'], qty_to_sell)
                pnl = (sell_price - oldest['price']) * matched
                # 成本按比例分攤
                if original_qty > 0:
                    pnl -= sell_total_tax_fee * (matched / original_qty)
                closed.append({'stock': sym, 'pnl': pnl, 'qty': matched})
                oldest['qty'] -= matched
                qty_to_sell -= matched
                if oldest['qty'] <= 0:
                    buy_queues[sym].pop(0)
    return closed


def compute_metrics(equity_curve: list, trades: list) -> dict:
    """計算 KPI：總報酬、年化報酬、最大回撤、勝率、交易次數。"""
    if not equity_curve:
        return _empty_kpi(0)

    initial = equity_curve[0]['value']
    final = equity_curve[-1]['value']
    if initial <= 0:
        return _empty_kpi(0)

    total_return = (final - initial) / initial * 100

    # 年化（用實際天數）
    try:
        d0 = date.fromisoformat(equity_curve[0]['date'])
        d1 = date.fromisoformat(equity_curve[-1]['date'])
        days = max((d1 - d0).days, 1)
        annualized = ((final / initial) ** (365 / days) - 1) * 100
    except Exception:
        annualized = 0.0

    # 最大回撤 MDD
    mdd = 0.0
    peak = initial
    for p in equity_curve:
        v = p['value']
        if v > peak:
            peak = v
        if peak > 0:
            dd = (v - peak) / peak * 100
            if dd < mdd:
                mdd = dd

    # 勝率（FIFO 已實現損益）
    closed = _compute_closed_trades(trades)
    wins = sum(1 for t in closed if t['pnl'] > 0)
    win_rate = wins / len(closed) * 100 if closed else 0.0

    return {
        'total_return_pct': round(total_return, 2),
        'annualized_return_pct': round(annualized, 2),
        'mdd_pct': round(mdd, 2),
        'win_rate_pct': round(win_rate, 2),
        'n_trades': len(closed),
        'final_value': round(final, 2),
    }


# === 結果持久化 ===

def save_result(result: dict) -> str:
    """Append result to backtest_results.json，回傳 id。"""
    RESULTS_FILE.parent.mkdir(parents=True, exist_ok=True)
    if RESULTS_FILE.exists():
        try:
            with open(RESULTS_FILE, 'r', encoding='utf-8') as f:
                history = json.load(f)
        except (json.JSONDecodeError, OSError):
            history = []
    else:
        history = []

    rid = (
        f"bt_{datetime.now().strftime('%Y%m%d_%H%M%S')}_"
        f"{abs(hash(json.dumps(result.get('run_meta', {}), sort_keys=True))) % 1000000:06d}"
    )
    record = {
        'id': rid,
        'timestamp': datetime.now().isoformat(),
        **result,
    }
    history.append(record)
    if len(history) > MAX_HISTORY:
        history = history[-MAX_HISTORY:]

    with open(RESULTS_FILE, 'w', encoding='utf-8') as f:
        json.dump(history, f, ensure_ascii=False, indent=2)
    return rid


def list_results() -> list:
    """Read all backtest results (metadata only)."""
    if not RESULTS_FILE.exists():
        return []
    try:
        with open(RESULTS_FILE, 'r', encoding='utf-8') as f:
            history = json.load(f)
    except (json.JSONDecodeError, OSError):
        return []
    # 只回傳摘要，節省傳輸
    return [
        {
            'id': r.get('id'),
            'timestamp': r.get('timestamp'),
            'strategy': r.get('run_meta', {}).get('strategy'),
            'start_date': r.get('run_meta', {}).get('start_date'),
            'end_date': r.get('run_meta', {}).get('end_date'),
            'rebalance_n': r.get('run_meta', {}).get('rebalance_n'),
            'symbol': r.get('run_meta', {}).get('symbol'),
            'n_stocks': r.get('run_meta', {}).get('n_stocks'),
            'kpi': r.get('kpi'),
        }
        for r in history
    ]


def get_result(rid: str) -> dict | None:
    """Read single result by id."""
    if not RESULTS_FILE.exists():
        return None
    try:
        with open(RESULTS_FILE, 'r', encoding='utf-8') as f:
            history = json.load(f)
    except (json.JSONDecodeError, OSError):
        return None
    for r in history:
        if r.get('id') == rid:
            return r
    return None


# === CLI ===

def main():
    parser = argparse.ArgumentParser(description='股市回測引擎')
    parser.add_argument('--start', help='起始日 YYYY-MM-DD')
    parser.add_argument('--end', help='結束日 YYYY-MM-DD')
    parser.add_argument('--strategy', type=int, choices=[0, 1], default=0,
                        help='策略：0=策略1 (MA+RSI+MACD), 1=策略2 (KD)')
    parser.add_argument('--rebalance', type=int, default=1,
                        help='每 N 天檢查一次訊號（1=每天, 5=每週, 20=每月）')
    parser.add_argument('--symbol', help='指定單一股票代號（例如 2330.TW），不傳則跑全部')
    parser.add_argument('--capital', type=float, default=1_000_000,
                        help='初始資金')
    parser.add_argument('--out', choices=['json', 'summary'], default='summary',
                        help='輸出格式')
    parser.add_argument('--save', action='store_true', help='寫入 backtest_results.json')
    parser.add_argument('--request-file', help='JSON 檔含完整參數（給 API 用）')
    parser.add_argument('--from-stdin', action='store_true', help='從 stdin 讀 JSON 參數')
    args = parser.parse_args()

    # 從 request file / stdin / CLI args 取參數
    if args.request_file or args.from_stdin:
        if args.request_file:
            with open(args.request_file, 'r', encoding='utf-8') as f:
                req = json.load(f)
        else:
            req = json.load(sys.stdin)
        start = req['start_date']
        end = req['end_date']
        strat = int(req['strategy'])
        reb = int(req.get('rebalance_n', 1))
        cap = float(req.get('initial_capital', 1_000_000))
        settings = req.get('settings') or _load_default_settings(strat)
        symbol = req.get('symbol')  # str 或 None
        result = run_backtest(
            start_date=start,
            end_date=end,
            strategy_idx=strat,
            rebalance_n=reb,
            settings=settings,
            initial_capital=cap,
            symbol=symbol,
        )
        args.out = 'json'  # API 模式強制 JSON
        args.save = True   # API 模式預設存
    else:
        settings = _load_default_settings(args.strategy)
        result = run_backtest(
            start_date=args.start,
            end_date=args.end,
            strategy_idx=args.strategy,
            rebalance_n=args.rebalance,
            settings=settings,
            initial_capital=args.capital,
            symbol=args.symbol,
        )

    if args.save:
        rid = save_result(result)
        result['id'] = rid

    if args.out == 'json':
        print(json.dumps(result, ensure_ascii=False, indent=2))
    else:
        kpi = result['kpi']
        meta = result['run_meta']
        print(f"\n=== 回測結果 ===")
        print(f"策略: {meta.get('strategy', '?')}")
        scope = f"單一 {meta['symbol']}" if meta.get('symbol') else f"全部 {meta.get('n_stocks', '?')} 檔"
        print(f"選股: {scope}")
        print(f"區間: {meta.get('start_date')} ~ {meta.get('end_date')} ({meta.get('n_days')} 日)")
        print(f"頻率: 每 {meta.get('rebalance_n')} 天")
        print(f"初始資金: {meta.get('initial_capital'):,.0f}")
        if meta.get('error'):
            print(f"\n[錯誤] {meta['error']}")
        print(f"\n[KPI]")
        print(f"  總報酬:     {kpi['total_return_pct']:+.2f}%")
        print(f"  年化報酬:   {kpi['annualized_return_pct']:+.2f}%")
        print(f"  最大回撤:   {kpi['mdd_pct']:.2f}%")
        print(f"  勝率:       {kpi['win_rate_pct']:.1f}%")
        print(f"  交易次數:   {kpi['n_trades']}")
        print(f"  Buy & Hold: {kpi['buyhold_return_pct']:+.2f}%")
        print(f"  期末價值:   {kpi['final_value']:,.0f}")


if __name__ == '__main__':
    main()
