#!/usr/bin/env python3
"""
agent_stock.py - 手動操作帳戶交易 CLI（給 agent / 腳本用）

呼叫 manual_trade.php 的 API 進行：
  - 查詢帳戶狀態（get）
  - 列出可下單股票（stocks）
  - 查詢單股資訊（stock）
  - 買進（buy）：可用股數或金額
  - 賣出（sell）：可用股數或金額
  - 歸零手動帳戶（reset，要 --yes 才執行）

URL 設定（優先順序）：
  1. CLI 參數 --url http://...
  2. 環境變數 STOCK_API_URL
  3. 預設 http://localhost/stock/manual_trade.php

使用範例：
  python agent_stock.py get
  python agent_stock.py stocks
  python agent_stock.py stock 2330.TW
  python agent_stock.py buy 2330.TW 1000
  python agent_stock.py buy 2330.TW --amount 50000
  python agent_stock.py sell 2330.TW 500
  python agent_stock.py sell 2330.TW --amount 30000
  python agent_stock.py reset --yes
  python agent_stock.py --url http://10.35.32.11/stock/manual_trade.py get

無需外部套件（純 stdlib）。
"""

from __future__ import annotations

import argparse
import json
import os
import sys
from typing import Any, Optional
from urllib.error import HTTPError, URLError
from urllib.parse import urlencode
from urllib.request import Request, urlopen


DEFAULT_URL = "http://localhost/stock/manual_trade.php"


# ============================================================
# API Client
# ============================================================

class StockAPIError(Exception):
    """API 回 ok=false 或連線失敗時丟出"""

    def __init__(self, message: str, payload: Optional[dict] = None):
        super().__init__(message)
        self.payload = payload or {}


def call_api(
    url: str,
    action: str,
    *,
    method: str = "GET",
    params: Optional[dict] = None,
    json_body: Optional[dict] = None,
    timeout: float = 15.0,
) -> dict:
    """
    呼叫 manual_trade.php 的 API。

    參數：
      url      - 完整 URL（含 manual_trade.php）
      action   - action 名稱（api_get / api_buy / ...）
      method   - 'GET' 或 'POST'
      params   - GET query string 參數（會合併 action）
      json_body - POST JSON body（會自動加 Content-Type）

    回傳：dict（API 回傳的 JSON）
    失敗：拋 StockAPIError
    """
    params = dict(params or {})
    params["action"] = action

    headers = {}
    data = None

    if method.upper() == "POST":
        body = dict(json_body or {})
        body["action"] = action
        data = json.dumps(body, ensure_ascii=False).encode("utf-8")
        headers["Content-Type"] = "application/json; charset=utf-8"
        full_url = url
    else:
        full_url = f"{url}?{urlencode(params)}"

    req = Request(full_url, data=data, headers=headers, method=method.upper())

    try:
        with urlopen(req, timeout=timeout) as resp:
            raw = resp.read().decode("utf-8")
    except HTTPError as e:
        # 4xx / 5xx 還是要讀 body（API 會回 JSON 錯誤訊息）
        try:
            raw = e.read().decode("utf-8")
        except Exception:
            raw = ""
        try:
            payload = json.loads(raw) if raw else {"ok": False, "error": f"HTTP {e.code}"}
        except json.JSONDecodeError:
            payload = {"ok": False, "error": f"HTTP {e.code}: {raw[:200]}"}
        if not payload.get("ok"):
            err = payload.get("error", f"HTTP {e.code}")
            raise StockAPIError(err, payload)
        return payload
    except URLError as e:
        raise StockAPIError(f"連線失敗：{e.reason}")

    try:
        payload = json.loads(raw)
    except json.JSONDecodeError as e:
        raise StockAPIError(f"回應不是合法 JSON：{e.msg}（前 200 字：{raw[:200]}）")

    if not isinstance(payload, dict):
        raise StockAPIError(f"回應格式錯誤（非 dict）：{type(payload).__name__}")

    if not payload.get("ok"):
        err = payload.get("error", "未知錯誤")
        raise StockAPIError(err, payload)

    return payload


# ============================================================
# Pretty Printers
# ============================================================

def fmt_money(n) -> str:
    """NT$ 1,234,567"""
    try:
        return f"NT$ {int(round(float(n))):,}"
    except (TypeError, ValueError):
        return str(n)


def fmt_qty(n) -> str:
    try:
        return f"{int(n):,}"
    except (TypeError, ValueError):
        return str(n)


def fmt_rate(n) -> str:
    try:
        return f"{float(n):.2f}%"
    except (TypeError, ValueError):
        return str(n)


def print_account(payload: dict, *, quiet: bool = False) -> None:
    """api_get 的回傳"""
    if quiet:
        # 一行摘要
        print(
            f"帳戶={payload.get('account')} "
            f"現金={fmt_money(payload.get('cash'))} "
            f"市值={fmt_money(payload.get('total_value'))} "
            f"損益={fmt_money(payload.get('profit'))} "
            f"({fmt_rate(payload.get('profit_rate'))}) "
            f"持股數={len(payload.get('holdings', []))}"
        )
        return

    print(f"=== {payload.get('account')} ===")
    print(f"初始資金     {fmt_money(payload.get('initial_capital'))}")
    print(f"可用現金     {fmt_money(payload.get('cash'))}")
    print(f"持倉市值     {fmt_money(payload.get('total_value'))}")
    profit = payload.get("profit", 0)
    sign = "+" if profit >= 0 else ""
    print(f"總損益       {sign}{fmt_money(profit)}  ({fmt_rate(payload.get('profit_rate'))})")
    print()
    print("--- 持股 ---")
    holdings = payload.get("holdings", [])
    if not holdings:
        print("（無）")
    else:
        for h in holdings:
            line = f"{h['stock']:10s}  {fmt_qty(h['quantity'])} 股"
            if h.get("price") is not None:
                line += f"  現價 {fmt_money(h['price'])}"
            if h.get("cost_basis") is not None:
                line += f"  成本 {fmt_money(h['cost_basis'])}"
            if h.get("unrealized_pl") is not None:
                sign = "+" if h["unrealized_pl"] >= 0 else ""
                line += f"  損益 {sign}{fmt_money(h['unrealized_pl'])}"
            print(line)
    print()
    print("--- 最近 20 筆交易 ---")
    for t in payload.get("recent_trades", []):
        print(
            f"{t.get('date', '')}  {t.get('stock', ''):10s}  "
            f"{t.get('action', ''):4s}  {fmt_qty(t.get('quantity', 0))} 股 @ "
            f"{fmt_money(t.get('price', 0))}"
        )


def print_stocks(payload: dict, *, quiet: bool = False) -> None:
    """api_list_stocks 的回傳"""
    items = payload.get("stocks", [])
    if quiet:
        print(" ".join(f"{x['code']}@{x.get('price')}" for x in items))
        return
    print("--- 可下單股票 ---")
    for x in items:
        price = fmt_money(x["price"]) if x.get("price") is not None else "（無報價）"
        print(f"  {x['code']:12s}  {price}")


def print_stock_info(payload: dict, *, quiet: bool = False) -> None:
    """api_stock_info 的回傳"""
    if quiet:
        print(
            f"{payload.get('stock')} "
            f"價={fmt_money(payload.get('price'))} "
            f"持倉={fmt_qty(payload.get('holding'))} "
            f"成本={fmt_money(payload.get('cost_basis'))} "
            f"損益={fmt_money(payload.get('unrealized_pl'))} "
            f"現金={fmt_money(payload.get('cash'))} "
            f"可買={fmt_qty(payload.get('can_buy_qty'))}股"
        )
        return
    print(f"=== {payload.get('stock')} ===")
    print(f"現價         {fmt_money(payload.get('price'))}")
    print(f"持倉         {fmt_qty(payload.get('holding'))} 股")
    if payload.get("cost_basis") is not None:
        print(f"平均成本     {fmt_money(payload['cost_basis'])}")
        print(f"累計成本     {fmt_money(payload.get('total_cost'))}")
    if payload.get("unrealized_pl") is not None:
        sign = "+" if payload["unrealized_pl"] >= 0 else ""
        print(f"未實現損益   {sign}{fmt_money(payload['unrealized_pl'])} ({fmt_rate(payload.get('unrealized_rate'))})")
    print(f"可用現金     {fmt_money(payload.get('cash'))}")
    print(f"可買股數     {fmt_qty(payload.get('can_buy_qty'))} 股")
    print(f"可買金額     {fmt_money(payload.get('can_buy_amount'))}")


def print_trade_result(payload: dict, *, quiet: bool = False) -> None:
    """api_buy / api_sell / api_reset 的回傳"""
    if quiet:
        if payload.get("action"):
            print(
                f"OK {payload.get('action')} {payload.get('stock')} "
                f"{fmt_qty(payload.get('quantity'))}股 @ {fmt_money(payload.get('price'))} "
                f"現金={fmt_money(payload.get('cash'))}"
            )
        else:
            print(f"OK {payload.get('message', '')}")
        return
    print("✅ " + payload.get("message", "完成"))
    if "stock" in payload:
        print(f"   動作   {payload.get('action')} {payload.get('stock')}")
        print(f"   股數   {fmt_qty(payload.get('quantity'))} 股")
        print(f"   價格   {fmt_money(payload.get('price'))}")
    if "cash" in payload:
        print(f"   剩餘現金  {fmt_money(payload['cash'])}")


# ============================================================
# CLI
# ============================================================

def build_parser() -> argparse.ArgumentParser:
    p = argparse.ArgumentParser(
        prog="agent_stock.py",
        description="手動操作帳戶交易 CLI（呼叫 manual_trade.php API）",
        formatter_class=argparse.RawDescriptionHelpFormatter,
        epilog=__doc__,
    )
    p.add_argument(
        "--url",
        default=os.environ.get("STOCK_API_URL", DEFAULT_URL),
        help=f"API URL（預設 {DEFAULT_URL}，可用環境變數 STOCK_API_URL）",
    )
    p.add_argument(
        "--json",
        action="store_true",
        help="輸出完整 JSON 回應（debug / 程式串接用）",
    )
    p.add_argument(
        "--quiet",
        "-q",
        action="store_true",
        help="安靜模式（一行摘要）",
    )

    sub = p.add_subparsers(dest="cmd", required=True, metavar="COMMAND")

    sub.add_parser("get", help="查詢手動操作帳戶完整狀態")
    sub.add_parser("stocks", help="列出所有可下單股票 + 現價")

    p_stock = sub.add_parser("stock", help="查詢單股現在價、持倉、成本、損益、可買股數")
    p_stock.add_argument("code", help="股票代號（例 2330.TW）")

    p_buy = sub.add_parser("buy", help="買進（可用股數或金額）")
    p_buy.add_argument("code", help="股票代號")
    p_buy.add_argument("qty", nargs="?", type=int, default=0, help="買入股數")
    p_buy.add_argument("--amount", type=float, default=0, help="買進金額（自動算股數）")

    p_sell = sub.add_parser("sell", help="賣出（可用股數或金額）")
    p_sell.add_argument("code", help="股票代號")
    p_sell.add_argument("qty", nargs="?", type=int, default=0, help="賣出股數")
    p_sell.add_argument("--amount", type=float, default=0, help="賣出金額（自動算股數）")

    p_reset = sub.add_parser("reset", help="歸零手動操作帳戶（會清空庫存、現金回 500 萬、交易紀錄清空）")
    p_reset.add_argument("--yes", "-y", action="store_true", help="確認執行（不問確認）")

    return p


def main(argv=None) -> int:
    args = build_parser().parse_args(argv)

    try:
        if args.cmd == "get":
            data = call_api(args.url, "api_get")
            if args.json:
                print(json.dumps(data, ensure_ascii=False, indent=2))
            else:
                print_account(data, quiet=args.quiet)

        elif args.cmd == "stocks":
            data = call_api(args.url, "api_list_stocks")
            if args.json:
                print(json.dumps(data, ensure_ascii=False, indent=2))
            else:
                print_stocks(data, quiet=args.quiet)

        elif args.cmd == "stock":
            data = call_api(args.url, "api_stock_info", params={"stock": args.code})
            if args.json:
                print(json.dumps(data, ensure_ascii=False, indent=2))
            else:
                print_stock_info(data, quiet=args.quiet)

        elif args.cmd in ("buy", "sell"):
            if args.qty <= 0 and args.amount <= 0:
                print("錯誤：qty 與 --amount 至少需填一個（且 > 0）", file=sys.stderr)
                return 2
            payload = {"stock": args.code}
            if args.qty > 0:
                payload["qty"] = args.qty
            if args.amount > 0:
                payload["amount"] = args.amount
            data = call_api(args.url, f"api_{args.cmd}", method="POST", json_body=payload)
            if args.json:
                print(json.dumps(data, ensure_ascii=False, indent=2))
            else:
                print_trade_result(data, quiet=args.quiet)

        elif args.cmd == "reset":
            if not args.yes:
                print("⚠️  即將歸零『手動操作』帳戶：庫存與交易紀錄會全部清空、現金回到 500 萬。")
                print("   如確認執行，請加 --yes 或 -y。")
                return 1
            data = call_api(args.url, "api_reset")
            if args.json:
                print(json.dumps(data, ensure_ascii=False, indent=2))
            else:
                print_trade_result(data, quiet=args.quiet)

    except StockAPIError as e:
        print(f"❌ API 錯誤：{e}", file=sys.stderr)
        if e.payload and e.payload.get("error"):
            pass  # 已印
        return 1
    except KeyboardInterrupt:
        print("\n（使用者中斷）", file=sys.stderr)
        return 130

    return 0


if __name__ == "__main__":
    sys.exit(main())
