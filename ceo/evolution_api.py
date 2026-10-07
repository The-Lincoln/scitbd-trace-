# -*- coding: utf-8 -*-
"""
SCITBD CEO — evolution-api bridge (Python side).

WhatsApp messaging for CEO alerts + lead outreach:
  https://github.com/The-Lincoln/evolution-api.git
  vendored: external/evolution-api (docs.evolutionfoundation.com.br)
  self-host: docker run -p 8080:8080 evoapicloud/evolution-api:latest

Auth: `apikey` header = global key or per-instance token.
Verified routes: GET /instance/fetchInstances|connect|connectionState,
POST /instance/create, POST /message/sendText|sendMedia, POST /webhook/set.

Usage:
    python evolution_api.py status
    python evolution_api.py send 8801XXXXXXXXX "NPS dropped below 40 — emergency meeting?"
    python evolution_api.py alert-ceo "Lead >= $10,000 needs video message"

Env: EVOLUTION_API_URL (default http://127.0.0.1:8080), EVOLUTION_API_KEY,
     EVOLUTION_INSTANCE (default scitbd), EVOLUTION_INSTANCE_TOKEN,
     EVOLUTION_CEO_NUMBER (digits, e.g. 8801XXXXXXXXX).
"""
from __future__ import annotations

import json
import os
import re
import sqlite3
import sys
import time
import urllib.request

DB_PATH = os.environ.get(
    "SCITBD_DB_PATH",
    r"D:/CRM_with_GOOGLE_Sheet/ceo/ceo - Copy/scitbd_ceo.db",
)
TRACE_ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
TRACE_CEO_DB = os.path.join(TRACE_ROOT, "ceo", "scitbd_ceo.db")

try:
    sys.stdout.reconfigure(encoding="utf-8", errors="replace")
    sys.stderr.reconfigure(encoding="utf-8", errors="replace")
except Exception:
    pass


def cfg() -> dict:
    return {
        "base": os.environ.get("EVOLUTION_API_URL", "http://127.0.0.1:8080").rstrip("/"),
        "api_key": os.environ.get("EVOLUTION_API_KEY", ""),
        "instance": os.environ.get("EVOLUTION_INSTANCE", "scitbd"),
        "instance_token": os.environ.get("EVOLUTION_INSTANCE_TOKEN", ""),
        "ceo_number": re.sub(r"\D+", "", os.environ.get("EVOLUTION_CEO_NUMBER", "")),
    }


def _key(c: dict) -> str:
    return c["instance_token"] or c["api_key"]


def _http(method: str, path: str, body=None, timeout: int = 15) -> dict:
    t0 = time.time()
    c = cfg()
    key = _key(c)
    if not key:
        return {"ok": False, "http": 0, "error": "EVOLUTION_API_KEY missing", "ms": 0}
    data = None if body is None else json.dumps(body).encode("utf-8")
    try:
        req = urllib.request.Request(c["base"] + path, data=data, method=method.upper(),
                                     headers={"apikey": key, "Content-Type": "application/json"})
        with urllib.request.urlopen(req, timeout=timeout) as r:
            raw = r.read().decode("utf-8", "replace")
            code = r.status
    except Exception as ex:
        return {"ok": False, "http": 0, "error": str(ex)[:300], "ms": int((time.time() - t0) * 1000)}
    ms = int((time.time() - t0) * 1000)
    try:
        parsed = json.loads(raw) if raw else None
    except Exception:
        parsed = None
    ok = 200 <= code < 300
    return {"ok": ok, "http": code, "data": parsed,
            "error": None if ok else f"HTTP {code}", "ms": ms}


def status() -> dict:
    c = cfg()
    st = _http("GET", f"/instance/connectionState/{c['instance']}", timeout=8)
    state = "unknown"
    if st.get("ok"):
        d = st.get("data")
        state = str(d.get("state") if isinstance(d, dict) else d)
    return {"ok": st.get("ok", False) and state.lower() in ("open", "connected", "qr", "connecting"),
            "driver": "evolution-api", "base": c["base"], "instance": c["instance"],
            "has_api_key": bool(c["api_key"]), "has_instance_token": bool(c["instance_token"]),
            "state": state, "ms": st.get("ms", 0),
            "hint": "Ready." if st.get("ok") else "Self-host + connect via Manager UI, then EVOLUTION_API_KEY"}


def send_text(to: str, text: str) -> dict:
    to = re.sub(r"\D+", "", to or "")
    if not to or not (text or "").strip():
        return {"ok": False, "error": "to + text required"}
    c = cfg()
    return _http("POST", f"/message/sendText/{c['instance']}",
                 {"number": to, "text": text}, timeout=15)


def alert_ceo(text: str, to: str = "") -> dict:
    c = cfg()
    to = re.sub(r"\D+", "", to or c["ceo_number"])
    if not to:
        return {"ok": False, "error": "EVOLUTION_CEO_NUMBER not configured"}
    return send_text(to, text)


def log_run(kind: str, target: str, ok: bool, ms: int, excerpt: str = "") -> None:
    try:
        path = DB_PATH if os.path.isfile(DB_PATH) else TRACE_CEO_DB
        con = sqlite3.connect(path)
        con.execute("""CREATE TABLE IF NOT EXISTS evolution_runs (
            id INTEGER PRIMARY KEY AUTOINCREMENT, module TEXT DEFAULT 'ceo',
            kind TEXT DEFAULT 'sendText', target TEXT DEFAULT '', ok INTEGER DEFAULT 0,
            ms INTEGER DEFAULT 0, output_excerpt TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP)""")
        con.execute("INSERT INTO evolution_runs (module,kind,target,ok,ms,output_excerpt) VALUES (?,?,?,?,?,?)",
                    ("ceo", kind[:60], target[:120], 1 if ok else 0, ms, excerpt[:2000]))
        con.commit()
        con.close()
    except Exception:
        pass


def main() -> None:
    cmd = sys.argv[1] if len(sys.argv) > 1 else "status"
    if cmd == "status":
        print(json.dumps(status(), indent=2))
    elif cmd == "send" and len(sys.argv) > 3:
        r = send_text(sys.argv[2], sys.argv[3])
        log_run("sendText", sys.argv[2], bool(r.get("ok")), r.get("ms", 0), json.dumps(r.get("data") or r.get("error"))[:500])
        print(json.dumps(r, indent=2))
    elif cmd == "alert-ceo" and len(sys.argv) > 2:
        r = alert_ceo(sys.argv[2], sys.argv[3] if len(sys.argv) > 3 else "")
        log_run("alert-ceo", "ceo", bool(r.get("ok")), r.get("ms", 0), sys.argv[2][:500])
        print(json.dumps(r, indent=2))
    else:
        print(__doc__)


if __name__ == "__main__":
    main()
