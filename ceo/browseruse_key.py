# -*- coding: utf-8 -*-
"""
CEO BrowserUse key helper — layer 1 of 3 (CEO agents).
Companions: autoflows/app/services/BrowserUseKey.php (layers 2+3: SCCRM + app).

Resolution: real env -> trace/.env -> '' (never raises, never prints full key).
"""
from __future__ import annotations

import os

ENV = "BROWSER_USE_API_KEY"
TRACE_ROOT = os.path.join(os.path.dirname(os.path.abspath(__file__)), "..")


def _load_dotenv() -> None:
    if os.environ.get(ENV):
        return
    for cand in (os.path.join(TRACE_ROOT, ".env"),
                 os.path.join(TRACE_ROOT, "trace", ".env")):
        try:
            if not os.path.isfile(cand):
                continue
            with open(cand, "r", encoding="utf-8") as f:
                for line in f:
                    line = line.strip()
                    if not line or line.startswith("#") or "=" not in line:
                        continue
                    k, _, v = line.partition("=")
                    if k.strip() == ENV and v.strip().strip("\"'"):
                        os.environ[ENV] = v.strip().strip("\"'")
                        return
        except Exception:
            continue


def get() -> str:
    _load_dotenv()
    return (os.environ.get(ENV) or "").strip()


def has() -> bool:
    return get() != ""


def masked() -> str:
    k = get()
    if not k:
        return "(missing)"
    if len(k) <= 10:
        return k[:3] + "****"
    return k[:6] + "****" + k[-4:]


def headers() -> dict:
    k = get()
    return {"X-Browser-Use-API-Key": k} if k else {}
