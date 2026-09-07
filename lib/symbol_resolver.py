"""
股票代號市場別判定（Python 鏡像模組）

這個模組的存在目的是：
1. 在 dev sandbox（沒有 PHP CLI 的環境）也能用 pytest TDD 演算法
2. 充當 PHP `stocks_api.php::normalize_symbol()` 的演算法規格書
3. 兩邊實作必須保持行為一致；演算法異動時必須兩邊一起改

對應 PHP 端：`stocks_api.php` 的 `normalize_symbol()` / `display_ticker()` /
`validate_symbol()` / `yahoo_probe_market()`（若有）。

設計目標：
- 明確 `.TW` / `.TWO` 後綴 → 保留（大小寫正規化為大寫）
- bare ticker → 依序查 Yahoo `.TW` → `.TWO`，命中即停
- 兩邊都無效 → fallback 為 `.TW`（向下相容舊行為）
- 結果以 cache 寫入檔案，避免重複請求
- HTTP 有 timeout、不在錯誤時 spam log（cache TTL 內不再 probe）
"""
from __future__ import annotations

import json
import os
import re
import socket
import time
import urllib.error
import urllib.request
from pathlib import Path
from typing import Callable, Optional

# --- 常數 ---

VALID_RE = re.compile(r"^[0-9]{4,6}[A-Z]?(\.(TW|TWO))?$")
SUFFIX_RE = re.compile(r"\.(TW|TWO)$")

DEFAULT_CACHE_TTL_SUCCESS = 7 * 24 * 3600   # 命中市場別 → 7 天（市場別不會變）
DEFAULT_CACHE_TTL_FAILURE = 3600            # 兩邊都無效 → 1 小時（讓 Yahoo 恢復時能重試）
DEFAULT_HTTP_TIMEOUT = 3                    # 單次 HTTP 探測逾時秒數

# Yahoo Finance chart endpoint（公開、不需 API key）
YAHOO_CHART_URL = "https://query1.finance.yahoo.com/v8/finance/chart/{symbol}"

USER_AGENT = "Mozilla/5.0 (compatible; stock_simulation/1.0)"


# --- 純函式（無副作用） ---

def validate_symbol(symbol) -> bool:
    """`0050` / `0050.TW` / `00631L.TWO` 等形式都收，其它回 False。"""
    if not isinstance(symbol, str):
        return False
    return bool(VALID_RE.match(symbol.strip().upper()))


def display_ticker(symbol: str) -> str:
    """`0050.TW` → `0050`；已 bare 直接回傳。"""
    if not isinstance(symbol, str):
        return ""
    return SUFFIX_RE.sub("", symbol.strip().upper())


# --- Yahoo probe（含 timeout） ---

def default_yahoo_probe(candidate: str, *, timeout: float = DEFAULT_HTTP_TIMEOUT) -> str:
    """向 Yahoo Finance 查詢 candidate 是否存在。

    回傳值：
    - "TW" 或 "TWO"：候選代號有效，順帶回傳它屬於哪個市場別
    - ""：候選代號無效（chart.error / result 為空）
    - 拋例外：網路 / timeout / JSON 失敗 → 交給 caller 決定 fallback

    注意事項：
    - Yahoo 對無效代號回 HTTP 404 + body 含 `chart.error`，要當「無效」而非「錯誤」
    - HTTP 5xx / 連線拒絕 / timeout / 非 JSON → 拋例外（caller 視為 probe 故障）
    """
    if not candidate.endswith(".TW") and not candidate.endswith(".TWO"):
        raise ValueError(f"candidate 必須帶 .TW/.TWO 後綴：{candidate!r}")

    market = "TWO" if candidate.endswith(".TWO") else "TW"
    url = YAHOO_CHART_URL.format(symbol=candidate)
    req = urllib.request.Request(url, headers={"User-Agent": USER_AGENT})

    try:
        resp_ctx = urllib.request.urlopen(req, timeout=timeout)
    except urllib.error.HTTPError as e:
        # Yahoo 對無效 ticker 回 HTTP 404 + JSON body，這是「無效」而非網路錯誤
        if e.code == 404:
            body = e.read().decode("utf-8", errors="replace") if hasattr(e, "read") else ""
            try:
                data = json.loads(body) if body else {}
            except json.JSONDecodeError as exc:
                raise ProbeError(f"Yahoo 404 但 body 非 JSON：{body[:120]!r}") from exc
            chart = data.get("chart") or {}
            if chart.get("error"):
                return ""
            # 404 但沒有 chart.error → 當作無效
            return ""
        # 5xx / 429 等 → 視為 probe 故障
        raise ProbeError(f"Yahoo HTTP {e.code}: {e.reason}") from e
    except urllib.error.URLError as e:
        raise ProbeError(f"Yahoo URL 錯誤：{e.reason}") from e
    except (TimeoutError, socket.timeout) as e:
        raise ProbeError(f"Yahoo timeout") from e
    except OSError as e:
        raise ProbeError(f"Yahoo 連線失敗：{e}") from e

    try:
        with resp_ctx as resp:
            body = resp.read().decode("utf-8", errors="replace")
    except (OSError, TimeoutError) as e:
        raise ProbeError(f"Yahoo 讀取失敗：{e}") from e

    try:
        data = json.loads(body)
    except json.JSONDecodeError as e:
        raise ProbeError(f"Yahoo body 非 JSON：{body[:120]!r}") from e

    chart = data.get("chart") or {}
    if chart.get("error"):
        return ""
    result = chart.get("result")
    if isinstance(result, list) and len(result) > 0:
        return market
    return ""


# --- Cache I/O（靜默失敗，不影響主流程） ---

def _read_cache(cache_file: Path) -> dict:
    if not cache_file.exists():
        return {}
    try:
        raw = json.loads(cache_file.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError):
        return {}
    if not isinstance(raw, dict):
        return {}
    return raw


def _write_cache(cache_file: Path, cache: dict) -> None:
    try:
        cache_file.parent.mkdir(parents=True, exist_ok=True)
        cache_file.write_text(json.dumps(cache, ensure_ascii=False), encoding="utf-8")
    except OSError:
        # 寫 cache 失敗不應影響主流程
        pass


# --- 主流程 ---

# 自訂錯誤以便測試與上層 logging 區分
class ProbeError(RuntimeError):
    """Yahoo probe 發生網路 / timeout / JSON 錯誤（≠ 代號無效）。"""


def normalize_symbol(
    symbol,
    *,
    cache_file: Optional[Path] = None,
    probe: Optional[Callable[[str], str]] = None,
    now: Optional[float] = None,
    ttl_success: int = DEFAULT_CACHE_TTL_SUCCESS,
    ttl_failure: int = DEFAULT_CACHE_TTL_FAILURE,
    on_probe_error: Optional[Callable[[str, Exception], None]] = None,
) -> str:
    """把輸入代號正規化為 `<bare>.<TW|TWO>` 形式。

    Args:
        symbol: 任意使用者輸入（None / int / str 皆可）
        cache_file: 跨請求共用 cache（檔案式）。None 則完全不寫。
        probe: 注入探測函式，預設 = default_yahoo_probe。
               簽名：candidate -> "TW" / "TWO" / ""，錯誤請 raise ProbeError。
        now: 注入時間（測試用）；None = time.time()
        ttl_success / ttl_failure: cache 存活秒數
        on_probe_error: 收到 ProbeError 時呼叫，回傳 None 即可；
                         主要給 PHP 端寫 error_log 用，Python 端預設不動作
                         （避免 spam）。

    Returns:
        正規化後代號；空輸入 / 非法格式 → 空字串
    """
    if not isinstance(symbol, str):
        return ""
    symbol = symbol.strip().upper()
    if not symbol:
        return ""

    # 已明確附後綴 → 保留
    if SUFFIX_RE.search(symbol):
        return symbol

    # bare ticker 形式驗證
    if not validate_symbol(symbol):
        return ""

    bare = symbol
    cache = _read_cache(cache_file) if cache_file else {}
    ts_now = now if now is not None else time.time()

    # Cache hit
    if bare in cache:
        entry = cache[bare]
        if isinstance(entry, dict):
            ts = entry.get("ts", 0)
            market = entry.get("market")
            # 區分 success / fallback 的 TTL：fallback 較短（讓 Yahoo 恢復時能重試）
            ttl = ttl_failure if entry.get("fallback") else ttl_success
            if market in ("TW", "TWO") and ts_now - ts < ttl:
                return f"{bare}.{market}"

    # Cache miss → 依序查 `.TW` → `.TWO`
    if probe is None:
        probe = default_yahoo_probe

    resolved: Optional[str] = None
    saw_probe_error = False
    for market in ("TW", "TWO"):
        candidate = f"{bare}.{market}"
        try:
            result = probe(candidate)
        except Exception as exc:  # ProbeError 或網路錯誤
            saw_probe_error = True
            if on_probe_error is not None:
                try:
                    on_probe_error(candidate, exc)
                except Exception:
                    pass  # callback 不可炸主流程
            break  # probe 故障 → 中止 fallback，避免 spam
        if result == market:
            resolved = market
            break
        # result == "" 表示這市場無效，繼續查下一個

    if resolved is not None:
        if cache_file:
            cache[bare] = {"market": resolved, "ts": ts_now}
            _write_cache(cache_file, cache)
        return f"{bare}.{resolved}"

    # 兩邊都沒命中（無效或 probe 故障）→ fallback 為 .TW
    fallback_market = "TW"
    if cache_file:
        cache[bare] = {
            "market": fallback_market,
            "ts": ts_now,
            "fallback": True,
            "probe_error": saw_probe_error,
        }
        _write_cache(cache_file, cache)
    return f"{bare}.{fallback_market}"


__all__ = [
    "validate_symbol",
    "display_ticker",
    "normalize_symbol",
    "default_yahoo_probe",
    "ProbeError",
    "DEFAULT_CACHE_TTL_SUCCESS",
    "DEFAULT_CACHE_TTL_FAILURE",
    "DEFAULT_HTTP_TIMEOUT",
    "YAHOO_CHART_URL",
]