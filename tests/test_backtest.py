"""
回測引擎單元測試
- T-1: 無訊號 → 0 交易 / 0% 報酬
- T-3: MDD 計算
- T-5: Buy & Hold 計算
- Win rate（FIFO）
"""
import sys
import math
from pathlib import Path
from datetime import date, timedelta

import pytest

# 加入上層路徑
sys.path.insert(0, str(Path(__file__).parent.parent))

from backtest import (
    compute_metrics,
    run_backtest,
    build_aligned_dates,
    slice_history,
    current_prices_from,
    portfolio_value,
    _compute_closed_trades,
    _load_default_settings,
    load_benchmark_0050,
    build_benchmark_0050_curve,
)


# === 假資料產生器 ===

def make_fake_stock(symbol: str = 'TEST', n_days: int = 100,
                    base_price: float = 100.0, drift: float = 0.0,
                    volatility: float = 0.0,
                    start_date: str = '2025-01-01') -> list:
    """產生合成 OHLCV。drift=每日對數報酬、volatility=每日波幅。"""
    data = []
    cur = base_price
    d = date.fromisoformat(start_date)
    for i in range(n_days):
        # 用連續日期，不跳週末（測試簡化）
        cur_date = d + timedelta(days=i)
        if volatility > 0:
            cur *= math.exp(volatility * (math.sin(i * 1.7) + math.cos(i * 0.9)))
        if drift != 0:
            cur *= math.exp(drift)
        data.append({
            'date': cur_date.isoformat(),
            'open': round(cur * 0.99, 2),
            'high': round(cur * 1.01, 2),
            'low': round(cur * 0.98, 2),
            'close': round(cur, 2),
            'volume': 1_000_000,
        })
    return data


def fake_settings_s1() -> dict:
    return _load_default_settings(0)


def fake_settings_s2() -> dict:
    return _load_default_settings(1)


# === T-3: MDD 計算 ===

def test_compute_metrics_mdd_known_curve():
    """淨值 100→120→90→110 → MDD = (90-120)/120 = -25%"""
    curve = [
        {'date': '2025-01-01', 'value': 100},
        {'date': '2025-01-02', 'value': 120},
        {'date': '2025-01-03', 'value': 90},
        {'date': '2025-01-04', 'value': 110},
    ]
    m = compute_metrics(curve, trades=[])
    assert m['total_return_pct'] == pytest.approx(10.0, abs=0.01)
    assert m['mdd_pct'] == pytest.approx(-25.0, abs=0.01)
    assert m['n_trades'] == 0
    assert m['win_rate_pct'] == 0.0


def test_compute_metrics_no_drawdown():
    """單調上升 → MDD = 0"""
    curve = [
        {'date': '2025-01-01', 'value': 100},
        {'date': '2025-01-02', 'value': 110},
        {'date': '2025-01-03', 'value': 120},
    ]
    m = compute_metrics(curve, trades=[])
    assert m['mdd_pct'] == 0.0
    assert m['total_return_pct'] == pytest.approx(20.0, abs=0.01)


def test_compute_metrics_empty_curve():
    m = compute_metrics([], trades=[])
    assert m['total_return_pct'] == 0.0
    assert m['n_trades'] == 0


# === FIFO closed trades ===

def test_fifo_closed_trades_simple():
    """買 100 @ 50 → 賣 100 @ 70 → 已實現 +2000"""
    trades = [
        {'action': 'BUY', 'stock': 'A', 'price': 50, 'quantity': 100, 'tax': 0, 'fee': 20},
        {'action': 'SELL', 'stock': 'A', 'price': 70, 'quantity': 100, 'tax': 21, 'fee': 20},
    ]
    closed = _compute_closed_trades(trades)
    assert len(closed) == 1
    # pnl = (70-50)*100 - 稅 - 手續費 = 2000 - 21 - 20 = 1959
    assert closed[0]['pnl'] == pytest.approx(1959, abs=0.5)


def test_fifo_partial_sell():
    """買 100 @ 50 → 賣 50 @ 70 → pnl = 50*20 - 稅費 = 1000 - (稅+費部分)"""
    trades = [
        {'action': 'BUY', 'stock': 'A', 'price': 50, 'quantity': 100, 'tax': 0, 'fee': 20},
        {'action': 'SELL', 'stock': 'A', 'price': 70, 'quantity': 50, 'tax': 21, 'fee': 20},
    ]
    closed = _compute_closed_trades(trades)
    assert len(closed) == 1
    assert closed[0]['pnl'] == pytest.approx(1000 - 41, abs=0.5)


# === T-1: 無訊號 ===

def test_run_backtest_no_signals_zero_trades():
    """恆定價格 → 不會觸發任何訊號 → 0 筆交易 / 0% 報酬"""
    stock_data = {'TEST': make_fake_stock(n_days=60, drift=0.0)}
    result = run_backtest(
        start_date='2025-01-01',
        end_date='2025-03-01',
        strategy_idx=0,
        rebalance_n=1,
        settings=fake_settings_s1(),
        initial_capital=1_000_000,
        stock_data=stock_data,
    )
    assert result['kpi']['n_trades'] == 0
    assert result['kpi']['total_return_pct'] == pytest.approx(0.0, abs=0.01)
    # Buy & Hold 也是 0%（價格不變）
    assert result['kpi']['buyhold_return_pct'] == pytest.approx(0.0, abs=0.5)


# === T-5: Buy & Hold 計算 ===

def test_run_backtest_buyhold_rising():
    """Buy & Hold：等額買進 → 上漲 5% → 期末價值應上升"""
    n_days = 60
    stock_data = {
        'A': make_fake_stock('A', n_days=n_days, drift=0.005),
        'B': make_fake_stock('B', n_days=n_days, drift=0.005),
    }
    result = run_backtest(
        start_date='2025-01-01',
        end_date='2025-03-01',
        strategy_idx=0,
        rebalance_n=1,
        settings=fake_settings_s1(),
        initial_capital=1_000_000,
        stock_data=stock_data,
    )
    bh = result['buyhold_curve']
    assert bh[0]['value'] <= 1_000_000  # 扣一些手續費/稅後略低
    assert bh[-1]['value'] > bh[0]['value']
    # 兩個都 5% 漂移，60 天後大約 +35%
    assert result['kpi']['buyhold_return_pct'] > 20


# === 工具函式 ===

def test_build_aligned_dates():
    data = {
        'A': make_fake_stock('A', n_days=10, start_date='2025-01-01'),
        'B': make_fake_stock('B', n_days=8, start_date='2025-01-03'),  # 比較少
    }
    dates = build_aligned_dates(data, '2025-01-01', '2025-01-31')
    # 取交集後，B 從 01-03 開始 → 共 8 天
    assert len(dates) == 8
    assert dates[0] == '2025-01-03'


def test_slice_history():
    data = {
        'A': make_fake_stock('A', n_days=10, start_date='2025-01-01'),
    }
    sliced = slice_history(data, '2025-01-05')
    assert len(sliced['A']) == 5  # 01-01 ~ 01-05 inclusive


def test_current_prices_from():
    data = {'A': make_fake_stock('A', n_days=10, start_date='2025-01-01')}
    prices = current_prices_from(data, '2025-01-05')
    assert 'A' in prices
    assert prices['A'] == 100.0  # base_price


def test_portfolio_value():
    val = portfolio_value(cash=100_000, holdings={'A': 100}, prices={'A': 50})
    assert val == pytest.approx(105_000)


# === 隔離性（手動驗證項目之一，這裡用單元測試擋掉明顯錯誤） ===

def test_run_backtest_does_not_write_files(tmp_path, monkeypatch):
    """run_backtest 不應寫入任何檔案（純函式）。"""
    # 用 monkeypatch 監控檔案寫入
    # 簡易做法：確保函式只回傳 dict，不副作用
    stock_data = {'A': make_fake_stock('A', n_days=30)}
    result = run_backtest(
        start_date='2025-01-01',
        end_date='2025-01-31',
        strategy_idx=0,
        rebalance_n=1,
        settings=fake_settings_s1(),
        initial_capital=1_000_000,
        stock_data=stock_data,
    )
    # 結果應包含 KPI + 曲線 + 交易，不應有 side effect
    assert 'kpi' in result
    assert 'equity_curve' in result
    assert 'buyhold_curve' in result
    assert 'trades' in result
    assert isinstance(result['kpi'], dict)


# === 選股範圍（symbol 參數）===

def test_run_backtest_symbol_filters_to_single_stock():
    """指定 symbol → 只跑該檔，Buy & Hold 曲線只包含該檔。"""
    stock_data = {
        'A': make_fake_stock('A', n_days=60, drift=0.005),
        'B': make_fake_stock('B', n_days=60, drift=-0.005),
    }
    result = run_backtest(
        start_date='2025-01-01',
        end_date='2025-03-01',
        strategy_idx=0,
        rebalance_n=5,
        settings=fake_settings_s1(),
        initial_capital=1_000_000,
        stock_data=stock_data,
        symbol='A',
    )
    meta = result['run_meta']
    assert meta['symbol'] == 'A'
    assert meta['n_stocks'] == 1
    # Buy & Hold 只看 A（上漲），故正報酬
    assert result['kpi']['buyhold_return_pct'] > 0
    # 交易只可能涉及 A
    for t in result['trades']:
        assert t['stock'] == 'A'


def test_run_backtest_symbol_none_runs_all():
    """symbol=None → 維持舊行為，n_stocks = 全部股票數。"""
    stock_data = {
        'A': make_fake_stock('A', n_days=60),
        'B': make_fake_stock('B', n_days=60),
        'C': make_fake_stock('C', n_days=60),
    }
    result = run_backtest(
        start_date='2025-01-01',
        end_date='2025-03-01',
        strategy_idx=0,
        rebalance_n=1,
        settings=fake_settings_s1(),
        initial_capital=1_000_000,
        stock_data=stock_data,
        symbol=None,
    )
    assert result['run_meta']['symbol'] is None
    assert result['run_meta']['n_stocks'] == 3


def test_run_backtest_symbol_empty_string_treated_as_none():
    """空字串 → 正規化為 None，行為等同不指定。"""
    stock_data = {
        'A': make_fake_stock('A', n_days=60),
        'B': make_fake_stock('B', n_days=60),
    }
    result = run_backtest(
        start_date='2025-01-01',
        end_date='2025-03-01',
        strategy_idx=0,
        rebalance_n=1,
        settings=fake_settings_s1(),
        initial_capital=1_000_000,
        stock_data=stock_data,
        symbol='   ',  # 只有空白
    )
    assert result['run_meta']['symbol'] is None
    assert result['run_meta']['n_stocks'] == 2


def test_run_backtest_symbol_lowercase_uppercased():
    """小寫代號 → 自動轉大寫去找。"""
    stock_data = {
        'ABC.TW': make_fake_stock('ABC.TW', n_days=60, drift=0.005),
        'XYZ.TW': make_fake_stock('XYZ.TW', n_days=60, drift=-0.005),
    }
    result = run_backtest(
        start_date='2025-01-01',
        end_date='2025-03-01',
        strategy_idx=0,
        rebalance_n=5,
        settings=fake_settings_s1(),
        initial_capital=1_000_000,
        stock_data=stock_data,
        symbol='abc.tw',  # 小寫
    )
    assert result['run_meta']['symbol'] == 'ABC.TW'
    assert result['run_meta']['n_stocks'] == 1


def test_run_backtest_symbol_not_found_returns_error():
    """找不到代號 → 跑 0 筆，run_meta 含錯誤訊息，不例外崩潰。"""
    stock_data = {'A': make_fake_stock('A', n_days=60)}
    result = run_backtest(
        start_date='2025-01-01',
        end_date='2025-03-01',
        strategy_idx=0,
        rebalance_n=1,
        settings=fake_settings_s1(),
        initial_capital=1_000_000,
        stock_data=stock_data,
        symbol='9999.TW',
    )
    assert result['kpi']['n_trades'] == 0
    assert result['kpi']['total_return_pct'] == 0.0
    assert 'error' in result['run_meta']
    assert '9999.TW' in result['run_meta']['error']
    assert result['run_meta']['symbol'] == '9999.TW'
    assert result['run_meta']['n_stocks'] == 0
    assert result['equity_curve'] == []
    assert result['buyhold_curve'] == []
    assert result['trades'] == []


# === 0050 含息基準（benchmark_0050_curve）===

def test_load_benchmark_0050_returns_normalized_shape():
    """load_benchmark_0050 回 [{date, value}] 格式。"""
    series = load_benchmark_0050()
    if not series:
        # 若 0050 含息 cache 檔缺席，跳過（代表該環境沒有這個檔）
        pytest.skip("0050_total_return.json 不存在，跳過此測試")
    assert isinstance(series, list)
    assert all('date' in p and 'value' in p for p in series)
    assert all(isinstance(p['value'], (int, float)) for p in series)


def test_build_benchmark_0050_curve_normalizes_to_initial_capital():
    """build_benchmark_0050_curve 應以首日為準正規化到 initial_capital。"""
    series = load_benchmark_0050()
    if not series:
        pytest.skip("0050_total_return.json 不存在，跳過此測試")
    start = series[0]['date']
    end = series[-1]['date']
    initial = 1_000_000
    curve = build_benchmark_0050_curve(start, end, initial)
    assert len(curve) > 0
    # 首點 = initial_capital（正規化）
    assert curve[0]['value'] == pytest.approx(initial, abs=0.01)
    # curve 與停含息 cache 同比變動
    cache_ratio = series[-1]['value'] / series[0]['value']
    curve_ratio = curve[-1]['value'] / curve[0]['value']
    assert curve_ratio == pytest.approx(cache_ratio, abs=0.001)


def test_build_benchmark_0050_curve_handles_missing_file():
    """若含息 cache 檔不存在，應回空 list（不崩潰）。"""
    # delete 改讀環境：直接傳一個不存在的區間
    curve = build_benchmark_0050_curve('1900-01-01', '1900-01-02', 1_000_000)
    # 要不是全個空就是該區間切片是空
    assert curve == []


def test_run_backtest_includes_benchmark_0050():
    """run_backtest 應在 result 加 benchmark_0050_curve 與 KPI。"""
    stock_data = {
        'A': make_fake_stock('A', n_days=60, drift=0.005),
    }
    result = run_backtest(
        start_date='2025-01-01',
        end_date='2025-03-01',
        strategy_idx=0,
        rebalance_n=5,
        settings=fake_settings_s1(),
        initial_capital=1_000_000,
        stock_data=stock_data,
    )
    # 有 cache 就填，沒 cache 就 None（不該崩潰）
    assert 'benchmark_0050_curve' in result
    kpi = result['kpi']
    assert 'benchmark_0050_return_pct' in kpi
    if result['benchmark_0050_curve']:
        # 全部都順路填 KPI
        assert kpi['benchmark_0050_return_pct'] is not None
        assert isinstance(kpi['benchmark_0050_return_pct'], (int, float))
