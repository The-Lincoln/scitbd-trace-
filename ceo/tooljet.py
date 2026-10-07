# -*- coding: utf-8 -*-
"""
SCITBD CEO — tooljet bridge (Python side).

Low-code dashboards / internal apps / workflows for the CEO runtime:
  https://github.com/ToolJet/ToolJet.git
  vendored: external/tooljet (docs.tooljet.com)
  self-host: docker run -p 80:80 tooljet/try:ee-lts-latest

Companion to agent_browser.py / browserskill.py (browser) and
agent_memory.py (memory). Never raises on platform failure — local
tooljet_runs log covers downtime.

Usage:
    python tooljet.py status
    python tooljet.py trigger <webhook-url> '{"lead":"Acme"}'
    python tooljet.py health

Env: TOOLJET_HOST (default http://127.0.0.1), TOOLJET_API_TOKEN,
     TOOLJET_APP_CEO/SCCRM/TRACE, TOOLJET_WORKFLOW_LEAD/TRACE.
"""
from __future__ import annotations

import json
import os
import sqlite3
import sys
import time
import urllib.request

TRACE_ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
DB_PATH = os.environ.get(
    "SCITBD_DB_PATH",
    r"D:/CRM_with_GOOGLE_Sheet/ceo/ceo - Copy/scitbd_ceo.db",
)
TRACE_CEO_DB = os.path.join(TRACE_ROOT, "ceo", "scitbd_ceo.db")

try:
    sys.stdout.reconfigure(encoding="utf-8", errors="replace")
    sys.stderr.reconfigure(encoding="utf-8", errors="replace")
except Exception:
    pass


def cfg() -> dict:
    return {
        "host": os.environ.get("TOOLJET_HOST", "http://127.0.0.1").rstrip("/"),
        "api_token": os.environ.get("TOOLJET_API_TOKEN", ""),
        "webhook_lead": os.environ.get("TOOLJET_WORKFLOW_LEAD", ""),
        "webhook_trace": os.environ.get("TOOLJET_WORKFLOW_TRACE", ""),
    }


def _http(method: str, url: str, body=None, headers=None, timeout: int = 12) -> dict:
    t0 = time.time()
    data = None if body is None else (body if isinstance(body, bytes) else json.dumps(body).encode("utf-8"))
    try:
        req = urllib.request.Request(url, data=data, headers=headers or {}, method=method.upper())
        if data and not any(k.lower() == "content-type" for k in (headers or {})):
            req.add_header("Content-Type", "application/json")
        with urllib.request.urlopen(req, timeout=timeout) as r:
            raw = r.read().decode("utf-8", "replace")
            code = r.status
    except Exception as ex:
        return {"ok": False, "http": 0, "error": str(ex)[:300], "ms": int((time.time() - t0) * 1000)}
    ms = int((time.time() - t0) * 1000)
    ok = 200 <= code < 300
    try:
        parsed = json.loads(raw) if raw else None
    except Exception:
        parsed = None
    return {"ok": ok, "http": code, "data": parsed,
            "error": None if ok else f"HTTP {code}", "ms": ms}


def health() -> dict:
    c = cfg()
    r = _http("GET", c["host"] + "/api/health", timeout=5)
    if not r.get("ok"):
        r = _http("GET", c["host"] + "/", timeout=5)
    r["host"] = c["host"]
    return r


def status() -> dict:
    c = cfg()
    h = health()
    return {"ok": bool(h.get("ok")), "driver": "tooljet", "host": c["host"],
            "has_api_token": bool(c["api_token"]),
            "webhooks": {k: bool(c[k]) for k in ("webhook_lead", "webhook_trace")},
            "ms": h.get("ms", 0),
            "hint": "Ready." if h.get("ok") else "Self-host: docker run -p 80:80 tooljet/try:ee-lts-latest"}


def trigger(webhook_url: str, payload: dict | None = None) -> dict:
    if not webhook_url.startswith(("http://", "https://")):
        return {"ok": False, "error": "webhook URL required (Workflow → Triggers → Webhook)"}
    return _http("POST", webhook_url, payload or {}, timeout=15)


def log_run(kind: str, target: str, ok: bool, ms: int, excerpt: str = "") -> None:
    try:
        path = DB_PATH if os.path.isfile(DB_PATH) else TRACE_CEO_DB
        con = sqlite3.connect(path)
        con.execute("""CREATE TABLE IF NOT EXISTS tooljet_runs (
            id INTEGER PRIMARY KEY AUTOINCREMENT, module TEXT DEFAULT 'ceo',
            kind TEXT DEFAULT 'webhook', target TEXT DEFAULT '', ok INTEGER DEFAULT 0,
            ms INTEGER DEFAULT 0, output_excerpt TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP)""")
        con.execute("INSERT INTO tooljet_runs (module,kind,target,ok,ms,output_excerpt) VALUES (?,?,?,?,?,?)",
                    ("ceo", kind[:60], target[:500], 1 if ok else 0, ms, excerpt[:2000]))
        con.commit()
        con.close()
    except Exception:
        pass


def main() -> None:
    cmd = sys.argv[1] if len(sys.argv) > 1 else "status"
    if cmd == "status":
        print(json.dumps(status(), indent=2))
    elif cmd == "health":
        print(json.dumps(health(), indent=2))
    elif cmd == "trigger" and len(sys.argv) > 2:
        try:
            payload = json.loads(sys.argv[3]) if len(sys.argv) > 3 else {}
        except Exception:
            payload = {}
        r = trigger(sys.argv[2], payload)
        log_run("webhook", sys.argv[2], bool(r.get("ok")), r.get("ms", 0), json.dumps(r.get("data") or r.get("error"))[:500])
        print(json.dumps(r, indent=2))
    else:
        print(__doc__)


if __name__ == "__main__":
    main()
