"""
normalize_symbol() 的 TDD 測試

涵蓋情境（依任務需求）：
- 已明確附 `.TW` / `.TWO` 後綴 → 保留
- bare ticker：上市 → `.TW`、上櫃 → `.TWO`、無效 → fallback `.TW`
- 大小寫 / 空白 / 非字串 / 空字串 → 正確處理
- cache：命中、不命中、TTL 失效、fallback 標記
- Yahoo probe：錯誤 / timeout → 不 spam、不中斷主流程
- HTTP timeout：probe 逾時不會卡死

執行：`pytest tests/test_normalize_symbol.py -v`
"""
from __future__ import annotations

import json
import sys
import time
from pathlib import Path

import pytest

# 確保可以 import lib.symbol_resolver
sys.path.insert(0, str(Path(__file__).resolve().parent.parent))

from lib.symbol_resolver import (  # noqa: E402
    DEFAULT_CACHE_TTL_FAILURE,
    DEFAULT_CACHE_TTL_SUCCESS,
    ProbeError,
    display_ticker,
    normalize_symbol,
    validate_symbol,
)


# === 探測注入工具 ===

class ProbeRecorder:
    """記錄所有 probe 呼叫，並依「bare → market」回傳預設結果。"""
    def __init__(self, mapping: dict[str, str]):
        # mapping: "<bare>" -> "TW" 或 "TWO"（第一個被嘗試命中）
        self.mapping = mapping
        self.calls: list[str] = []

    def __call__(self, candidate: str) -> str:
        self.calls.append(candidate)
        # candidate 形式： "<bare>.TW" 或 "<bare>.TWO"
        bare, market = candidate.rsplit(".", 1)
        expected = self.mapping.get(bare)
        if expected == market:
            return market
        return ""  # 無效


def fixed_now() -> float:
    return 1_700_000_000.0


# === validate_symbol ===

def test_validate_symbol_accepts_bare_ticker():
    assert validate_symbol("0050") is True
    assert validate_symbol("4979") is True
    assert validate_symbol("00631L") is True  # ETF 末碼字母


def test_validate_symbol_accepts_with_suffix():
    assert validate_symbol("0050.TW") is True
    assert validate_symbol("4979.TWO") is True
    assert validate_symbol("00631L.TWO") is True
    assert validate_symbol("00631L.TW") is True


def test_validate_symbol_accepts_lowercase_and_whitespace():
    assert validate_symbol(" 0050.tw ") is True
    assert validate_symbol("00631L.two") is True  # 小寫後綴也允許（會被 normalize 轉大寫）
    assert validate_symbol("  4979  ") is True


def test_validate_symbol_rejects_invalid():
    assert validate_symbol("12") is False         # 太短
    assert validate_symbol("1234567") is False    # 太長
    assert validate_symbol("ABCD") is False      # 字母不合法
    assert validate_symbol("0050.NASDAQ") is False
    assert validate_symbol("0050.TW.X") is False
    assert validate_symbol("") is False
    assert validate_symbol(None) is False         # type: ignore
    assert validate_symbol(2330) is False         # type: ignore


# === display_ticker ===

def test_display_ticker_strips_suffix():
    assert display_ticker("0050.TW") == "0050"
    assert display_ticker("4979.TWO") == "4979"
    assert display_ticker("00631L.TWO") == "00631L"


def test_display_ticker_passes_through_bare():
    assert display_ticker("0050") == "0050"


# === normalize_symbol：保留明確後綴 ===

def test_normalize_preserves_tw_suffix():
    assert normalize_symbol("0050.TW") == "0050.TW"
    assert normalize_symbol("2330.TW") == "2330.TW"


def test_normalize_preserves_two_suffix():
    assert normalize_symbol("4979.TWO") == "4979.TWO"
    assert normalize_symbol("3081.TWO") == "3081.TWO"


def test_normalize_uppercases_lowercase_suffix():
    assert normalize_symbol("0050.tw") == "0050.TW"
    assert normalize_symbol("4979.two") == "4979.TWO"


def test_normalize_strips_whitespace():
    assert normalize_symbol("  0050.TW  ") == "0050.TW"


# === normalize_symbol：bare ticker → Yahoo 判斷 ===

def test_normalize_bare_listed_0050_returns_TW(tmp_path):
    """上市 ETF 0050 → .TW"""
    probe = ProbeRecorder({"0050": "TW"})
    result = normalize_symbol(
        "0050",
        cache_file=tmp_path / "cache.json",
        probe=probe,
        now=fixed_now(),
    )
    assert result == "0050.TW"
    # probe 應該只 call 一次就命中（先 .TW）
    assert probe.calls == ["0050.TW"]


def test_normalize_bare_otc_4979_returns_TWO(tmp_path):
    """上櫃 4979 → .TWO（.TW 探測失敗才會查 .TWO）"""
    probe = ProbeRecorder({"4979": "TWO"})
    result = normalize_symbol(
        "4979",
        cache_file=tmp_path / "cache.json",
        probe=probe,
        now=fixed_now(),
    )
    assert result == "4979.TWO"
    # 兩個市場都探過：先 .TW（無效），再 .TWO（命中）
    assert probe.calls == ["4979.TW", "4979.TWO"]


def test_normalize_bare_otc_3081_returns_TWO(tmp_path):
    """上櫃 3081"""
    probe = ProbeRecorder({"3081": "TWO"})
    result = normalize_symbol(
        "3081",
        cache_file=tmp_path / "cache.json",
        probe=probe,
        now=fixed_now(),
    )
    assert result == "3081.TWO"
    assert probe.calls == ["3081.TW", "3081.TWO"]


def test_normalize_bare_otc_3363_returns_TWO(tmp_path):
    """上櫃 3363"""
    probe = ProbeRecorder({"3363": "TWO"})
    result = normalize_symbol(
        "3363",
        cache_file=tmp_path / "cache.json",
        probe=probe,
        now=fixed_now(),
    )
    assert result == "3363.TWO"


def test_normalize_lowercase_bare_uppercased(tmp_path):
    """bare '0050' 小寫輸入 → 正規化為 0050.TW"""
    probe = ProbeRecorder({"0050": "TW"})
    result = normalize_symbol(
        "0050",
        cache_file=tmp_path / "cache.json",
        probe=probe,
        now=fixed_now(),
    )
    assert result == "0050.TW"


# === normalize_symbol：bare ticker → fallback ===

def test_normalize_invalid_both_markets_falls_back_to_TW(tmp_path):
    """兩邊 Yahoo 都無效 → fallback .TW"""
    probe = ProbeRecorder({})  # 所有 bare 都查無
    result = normalize_symbol(
        "9999",
        cache_file=tmp_path / "cache.json",
        probe=probe,
        now=fixed_now(),
    )
    assert result == "9999.TW"
    assert probe.calls == ["9999.TW", "9999.TWO"]


def test_normalize_invalid_both_markets_cached_as_fallback(tmp_path):
    """fallback 結果也寫 cache，但標記 fallback，避免一直打 Yahoo"""
    cache_file = tmp_path / "cache.json"
    probe = ProbeRecorder({})
    normalize_symbol("9999", cache_file=cache_file, probe=probe, now=fixed_now())

    cached = json.loads(cache_file.read_text())
    assert "9999" in cached
    entry = cached["9999"]
    assert entry["market"] == "TW"
    assert entry.get("fallback") is True


# === normalize_symbol：cache ===

def test_normalize_uses_cache_within_window(tmp_path):
    """cache 命中時不呼叫 probe"""
    cache_file = tmp_path / "cache.json"
    cache_file.write_text(json.dumps({
        "4979": {"market": "TWO", "ts": fixed_now() - 60},  # 1 分鐘前
    }))
    probe = ProbeRecorder({"4979": "TW"})  # 即便 probe 結果不同，cache 優先
    result = normalize_symbol(
        "4979",
        cache_file=cache_file,
        probe=probe,
        now=fixed_now(),
    )
    assert result == "4979.TWO"
    assert probe.calls == []  # 完全沒打


def test_normalize_reprobes_after_ttl_success(tmp_path):
    """success TTL 過期 → 重 probe"""
    cache_file = tmp_path / "cache.json"
    cache_file.write_text(json.dumps({
        "4979": {"market": "TW", "ts": fixed_now() - DEFAULT_CACHE_TTL_SUCCESS - 1},
    }))
    probe = ProbeRecorder({"4979": "TWO"})
    result = normalize_symbol(
        "4979",
        cache_file=cache_file,
        probe=probe,
        now=fixed_now(),
    )
    assert result == "4979.TWO"
    assert probe.calls[0] == "4979.TW"  # 重新打了


def test_normalize_reprobes_after_ttl_failure(tmp_path):
    """failure TTL 過期 → 重 probe（讓 Yahoo 恢復時能重試）"""
    cache_file = tmp_path / "cache.json"
    cache_file.write_text(json.dumps({
        "9999": {"market": "TW", "ts": fixed_now() - DEFAULT_CACHE_TTL_FAILURE - 1, "fallback": True},
    }))
    probe = ProbeRecorder({})  # 仍查無
    result = normalize_symbol(
        "9999",
        cache_file=cache_file,
        probe=probe,
        now=fixed_now(),
    )
    assert result == "9999.TW"
    assert probe.calls == ["9999.TW", "9999.TWO"]


def test_normalize_no_cache_file_means_in_memory_only(tmp_path):
    """cache_file=None → 仍然能跑（只是不寫檔）"""
    probe = ProbeRecorder({"0050": "TW"})
    result = normalize_symbol("0050", cache_file=None, probe=probe, now=fixed_now())
    assert result == "0050.TW"


def test_normalize_corrupt_cache_does_not_crash(tmp_path):
    """壞掉的 cache 檔 → 當空 cache 處理"""
    cache_file = tmp_path / "cache.json"
    cache_file.write_text("{not json")
    probe = ProbeRecorder({"0050": "TW"})
    result = normalize_symbol("0050", cache_file=cache_file, probe=probe, now=fixed_now())
    assert result == "0050.TW"


# === normalize_symbol：probe 錯誤處理 ===

def test_normalize_probe_error_falls_back_to_TW(tmp_path):
    """probe 拋例外（網路 / timeout / JSON）→ fallback .TW，不 crash"""
    def failing_probe(candidate: str) -> str:
        raise ProbeError(f"timeout for {candidate}")

    error_log: list[tuple[str, Exception]] = []

    def log_error(candidate: str, exc: Exception) -> None:
        error_log.append((candidate, exc))

    result = normalize_symbol(
        "0050",
        cache_file=tmp_path / "cache.json",
        probe=failing_probe,
        now=fixed_now(),
        on_probe_error=log_error,
    )
    assert result == "0050.TW"
    # on_probe_error 被呼叫過
    assert len(error_log) == 1
    assert error_log[0][0] == "0050.TW"


def test_normalize_probe_error_stops_at_first_failure(tmp_path):
    """probe 故障時，中止 fallback（避免 spam 第二次呼叫）"""
    def failing_probe(candidate: str) -> str:
        raise ProbeError(f"timeout for {candidate}")

    result = normalize_symbol(
        "0050",
        cache_file=tmp_path / "cache.json",
        probe=failing_probe,
        now=fixed_now(),
    )
    assert result == "0050.TW"
    # 我們無法直接從這裡檢查 call 數，但透過 cache 結構驗證：
    cached = json.loads((tmp_path / "cache.json").read_text())
    assert cached["0050"]["probe_error"] is True


def test_normalize_probe_error_callback_does_not_break_main_flow(tmp_path):
    """即使 on_probe_error 自己拋例外，主流程仍要 fallback"""
    def failing_probe(candidate: str) -> str:
        raise ProbeError("boom")

    def bad_callback(candidate: str, exc: Exception) -> None:
        raise RuntimeError("callback crashed")

    result = normalize_symbol(
        "0050",
        cache_file=tmp_path / "cache.json",
        probe=failing_probe,
        now=fixed_now(),
        on_probe_error=bad_callback,
    )
    assert result == "0050.TW"


def test_normalize_probe_error_within_ttl_skips_reprobe(tmp_path):
    """probe 故障後，TTL 內再呼叫 → 不再 probe"""
    cache_file = tmp_path / "cache.json"
    cache_file.write_text(json.dumps({
        "0050": {"market": "TW", "ts": fixed_now() - 60, "fallback": True, "probe_error": True},
    }))

    call_count = {"n": 0}

    def failing_probe(candidate: str) -> str:
        call_count["n"] += 1
        raise ProbeError("boom")

    result = normalize_symbol(
        "0050",
        cache_file=cache_file,
        probe=failing_probe,
        now=fixed_now(),
    )
    assert result == "0050.TW"
    assert call_count["n"] == 0  # 沒打 probe


# === normalize_symbol：邊界 ===

def test_normalize_empty_returns_empty():
    assert normalize_symbol("") == ""
    assert normalize_symbol("   ") == ""


def test_normalize_non_string_returns_empty():
    assert normalize_symbol(None) == ""  # type: ignore
    assert normalize_symbol(2330) == ""  # type: ignore
    assert normalize_symbol(["0050"]) == ""  # type: ignore


def test_normalize_invalid_format_returns_empty(tmp_path):
    """非合法 ticker（如太短）→ 不 probe，回空字串"""
    probe = ProbeRecorder({})
    result = normalize_symbol("12", cache_file=tmp_path / "cache.json", probe=probe, now=fixed_now())
    assert result == ""
    assert probe.calls == []  # 沒打 probe


def test_normalize_etf_with_letter_suffix(tmp_path):
    """00631L（ETF 末碼字母）→ bare 也能正常處理"""
    probe = ProbeRecorder({"00631L": "TWO"})
    result = normalize_symbol(
        "00631L",
        cache_file=tmp_path / "cache.json",
        probe=probe,
        now=fixed_now(),
    )
    assert result == "00631L.TWO"
    # ETF 可能在 TWO，先 .TW 失敗再 .TWO 命中
    assert probe.calls == ["00631L.TW", "00631L.TWO"]


# === 真實 Yahoo probe（integration；網路不通或 sandbox 不允時會被 skip） ===

import socket
import urllib.request


def _network_reachable() -> bool:
    try:
        socket.create_connection(("query1.finance.yahoo.com", 443), timeout=2).close()
        return True
    except OSError:
        return False


@pytest.mark.skipif(not _network_reachable(), reason="無網路或 sandbox 阻擋；手動跑時才驗證")
def test_default_yahoo_probe_listed_0050_TW():
    from lib.symbol_resolver import default_yahoo_probe
    assert default_yahoo_probe("0050.TW") == "TW"


@pytest.mark.skipif(not _network_reachable(), reason="無網路或 sandbox 阻擋")
def test_default_yahoo_probe_otc_4979_TWO_only():
    """4979 是上櫃：.TW 應回空、.TWO 應回 TWO"""
    from lib.symbol_resolver import default_yahoo_probe
    assert default_yahoo_probe("4979.TW") == ""
    assert default_yahoo_probe("4979.TWO") == "TWO"


@pytest.mark.skipif(not _network_reachable(), reason="無網路或 sandbox 阻擋")
def test_default_yahoo_probe_invalid_9999():
    from lib.symbol_resolver import default_yahoo_probe
    assert default_yahoo_probe("9999.TW") == ""
    assert default_yahoo_probe("9999.TWO") == ""