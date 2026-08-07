#!/usr/bin/env python3
"""
股市模擬投資系統 - 使用者手動啟動版
透過 HTTP 呼叫 Docker 中的 PHP 執行交易，確保資料寫入正確位置

使用方式：
    python3 run_simulation.py        # 完整交易（含更新股價）
    python3 run_simulation.py --status  # 顯示現有狀態
"""

import argparse
import json
import subprocess
import sys
from datetime import datetime
from pathlib import Path

# 設定
STOCKS = [
    '0050.TW', '006208.TW', '0056.TW', '00919.TW',
    '2330.TW', '3711.TW', '2412.TW',
    '2881.TW', '2885.TW', '2891.TW'
]

STRATEGIES = ['策略1', '策略2']
STRATEGY_NAMES = {
    '策略1': 'MA + RSI 混合策略',
    '策略2': 'RSI 均值回歸策略'
}

INITIAL_CAPITAL = 1_000_000

# 檔案路徑
STOCK_DIR = Path('/mnt/d/docker-volumn/ubuntu-apache2/html/stock')
DATA_DIR = STOCK_DIR / 'data'


def load_json(path):
    """載入 JSON 檔案"""
    if path.exists():
        with open(path, 'r', encoding='utf-8') as f:
            return json.load(f)
    return {}


def call_php_script(mode='run'):
    """透過 curl 呼叫 PHP 腳本"""
    if mode == 'status':
        url = 'http://localhost/stock/stock_trader.php?status=1'
    elif mode == 'update':
        url = 'http://localhost/stock/stock_trader.php?update=1'
    else:
        url = 'http://localhost/stock/stock_trader.php?run=1'

    try:
        result = subprocess.run(
            ['curl', '-s', '--max-time', '90', url],
            capture_output=True,
            text=True,
            timeout=100
        )
        if result.returncode != 0:
            return f"curl 錯誤 (代碼 {result.returncode}): {result.stderr}"
        return result.stdout
    except subprocess.TimeoutExpired:
        return "執行逾時（90秒）"
    except Exception as e:
        return f"錯誤: {e}"


def calculate_portfolio_value(portfolio, prices):
    """計算投資組合價值"""
    value = portfolio.get('cash', 0)
    holdings = portfolio.get('holdings', {})

    for stock, qty in holdings.items():
        if isinstance(holdings, dict):
            for sym, q in holdings.items():
                if q > 0 and sym in prices and prices[sym] is not None:
                    value += q * prices[sym]
        elif qty > 0 and stock in prices and prices[stock] is not None:
            value += qty * prices[stock]

    return value


def show_status():
    """顯示現有狀態"""
    # 先嘗試從 PHP 取得狀態
    print("\n=== 股市模擬系統狀態 ===")
    print(f"時間: {datetime.now().strftime('%Y-%m-%d %H:%M:%S')}\n")

    # 讀取本地資料
    portfolio_file = DATA_DIR / 'portfolio.json'
    main_portfolio = STOCK_DIR / 'portfolio.json'

    portfolio = load_json(main_portfolio) if main_portfolio.exists() else load_json(portfolio_file)
    all_data_file = DATA_DIR / 'stock_data.json'
    main_data = STOCK_DIR / 'stock_data.json'
    all_data = load_json(main_data) if main_data.exists() else load_json(all_data_file)

    # 取得目前股價
    current_prices = {}
    for stock in STOCKS:
        if stock in all_data and all_data[stock]:
            for p in reversed(all_data[stock]):
                if p.get('close') is not None:
                    current_prices[stock] = p['close']
                    break

    for strategy in STRATEGIES:
        strat_portfolio = portfolio.get(strategy, {'cash': INITIAL_CAPITAL, 'holdings': {}, 'trades': []})

        # 處理 holdings 格式相容性
        holdings_data = strat_portfolio.get('holdings', {})
        holdings = holdings_data if isinstance(holdings_data, dict) else {}

        value = strat_portfolio.get('cash', INITIAL_CAPITAL)
        for sym, qty in holdings.items():
            if qty > 0 and sym in current_prices:
                value += qty * current_prices[sym]

        profit = value - INITIAL_CAPITAL
        profit_rate = (profit / INITIAL_CAPITAL) * 100

        print(f"{strategy} ({STRATEGY_NAMES[strategy]}):")
        print(f"  現金: {strat_portfolio.get('cash', 0):,.2f}")
        print(f"  總資產: {value:,.2f}")
        print(f"  獲利: {profit:+,.2f} ({profit_rate:+.2f}%)")

        if holdings and any(v > 0 for v in holdings.values()):
            print(f"  庫存:")
            for sym, qty in holdings.items():
                if qty > 0:
                    price = current_prices.get(sym)
                    price_str = f"{price:.2f}" if price else "N/A"
                    print(f"    {sym}: {qty} 股 @ {price_str}")
        else:
            print(f"  庫存: 無")

        print(f"  交易次數: {len(strat_portfolio.get('trades', []))}")
        print()


def main():
    parser = argparse.ArgumentParser(description='股市模擬投資系統')
    parser.add_argument('--update', action='store_true', help='只更新股價，不交易')
    parser.add_argument('--status', action='store_true', help='顯示現有狀態')
    args = parser.parse_args()

    if args.status:
        show_status()
        return

    if args.update:
        mode = 'update'
        mode_desc = '股價更新（只更新股價，不交易）'
    else:
        mode = 'run'
        mode_desc = '完整交易（含股價更新）'

    print("\n=== 股市模擬系統開始執行 ===")
    print(f"時間: {datetime.now().strftime('%Y-%m-%d %H:%M:%S')}")
    print(f"模式: {mode_desc}")
    print("（透過 Docker Apache 執行，確保資料寫入正確位置）\n")

    # 透過 HTTP 呼叫 PHP
    result = call_php_script(mode)

    # 顯示結果
    if result.startswith('HTTP 錯誤'):
        print(result)
        print("\n請確認 Docker Apache 服務正在執行")
        sys.exit(1)
    else:
        print(result)

    # 重新顯示狀態
    print("\n" + "="*50)
    show_status()


if __name__ == '__main__':
    main()
