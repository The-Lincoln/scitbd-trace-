# -*- coding: utf-8 -*-
"""
SCITBD CEO — agent-memory bridge (Python side).

Team memory hub for all agents (TencentDB Agent Memory):
  https://github.com/The-Lincoln/TencentDB-Agent-Memory.git
  vendored: external/tencentdb-agent-memory (INSTALL.md + sdk/memory-core/python v3)

Companion to agent_browser.py / browserskill.py (browser drivers).
Memory != browser: recall() before work, remember() after. Never raises —
local SQLite cache (agent_memory_cache) covers stack downtime.

Usage:
    python agent_memory.py status [module]
    python agent_memory.py recall "decision about auth module" --module ceo
    python agent_memory.py remember "Don't refactor old auth module" --session <id> --module ceo

Env: MEMORY_CORE_URL (default http://127.0.0.1:8420), MEMORY_SERVICE_ID,
     MEMORY_GATEWAY_KEY (Bearer, may be empty for local stack),
     TDAI_MEMORY_KEY (sk-mem-… user key), MEMORY_TEAM (default scitbd),
     MEMORY_USER (default scitbd-operator).
"""
from __future__ import annotations

import json
import os
import sqlite3
import sys
import urllib.request
import zoneinfo

MODULE_AGENTS = {
    "ceo": "scitbd-ceo",
    "sccrm": "scitbd-sccrm",
    "trace": "scitbd-trace",
    "autoflows": "scitbd-autoflows",
    "shared": "scitbd-shared",
}
TRACE_ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
DB_CANDIDATES = (
    os.path.join(TRACE_ROOT, "ceo", "scitbd_ceo.db"),
    os.path.join(TRACE_ROOT, "data", "osint.db"),
)

try:
    sys.stdout.reconfigure(encoding="utf-8", errors="replace")
    sys.stderr.reconfigure(encoding="utf-8", errors="replace")
except Exception:
    pass


def cfg() -> dict:
    return {
        "core": os.environ.get("MEMORY_CORE_URL", "http://127.0.0.1:8420").rstrip("/"),
        "service_id": os.environ.get("MEMORY_SERVICE_ID", ""),
        "gateway_key": os.environ.get("MEMORY_GATEWAY_KEY", ""),
        "user_key": os.environ.get("TDAI_MEMORY_KEY", ""),
        "team": os.environ.get("MEMORY_TEAM", "scitbd"),
        "user": os.environ.get("MEMORY_USER", "scitbd-operator"),
    }


def agent_for(module: str = "shared") -> str:
    return MODULE_AGENTS.get(module.lower().strip(), MODULE_AGENTS["shared"])


def _headers(c: dict) -> dict:
    h = {"Content-Type": "application/json"}
    if c["gateway_key"]:
        h["Authorization"] = "Bearer " + c["gateway_key"]
    if c["service_id"]:
        h["x-tdai-service-id"] = c["service_id"]
    if c["user_key"]:
        h["x-tdai-user-key"] = c["user_key"]
    return h


def _post(path: str, body: dict, timeout: int = 12) -> dict:
    import time
    t0 = time.time()
    c = cfg()
    try:
        req = urllib.request.Request(
            c["core"] + path,
            data=json.dumps(body).encode("utf-8"),
            headers=_headers(c),
            method="POST",
        )
        with urllib.request.urlopen(req, timeout=timeout) as r:
            raw = r.read().decode("utf-8", "replace")
            code = r.status
    except Exception as ex:
        return {"ok": False, "error": str(ex)[:300], "ms": int((time.time() - t0) * 1000)}
    try:
        env = json.loads(raw)
    except Exception:
        return {"ok": False, "error": f"HTTP {code}: non-JSON", "ms": int((time.time() - t0) * 1000)}
    if isinstance(env, dict) and "code" in env:
        ok = str(env.get("code")) in ("0",) and 200 <= code < 300
        return {"ok": ok, "data": env.get("data"),
                "error": None if ok else str(env.get("message") or f"code={env.get('code')}")[:300],
                "ms": int((time.time() - t0) * 1000)}
    return {"ok": 200 <= code < 300, "data": env, "ms": int((time.time() - t0) * 1000)}


def status(module: str = "shared") -> dict:
    c = cfg()
    h = _post("/v3/scenario/count",
              {"team_id": c["team"], "agent_id": agent_for(module), "user_id": c["user"]},
              timeout=5)
    return {"ok": bool(h.get("ok")), "driver": "tencentdb-agent-memory",
            "core": c["core"], "team": c["team"], "agent": agent_for(module),
            "user": c["user"], "has_service_id": bool(c["service_id"]),
            "has_user_key": bool(c["user_key"]),
            "ms": h.get("ms", 0),
            "hint": "Ready." if h.get("ok") else "Start stack: external/tencentdb-agent-memory/deploy/global-images/./start-all.sh"}


def _local_db():
    for p in DB_CANDIDATES:
        if os.path.isfile(p):
            try:
                con = sqlite3.connect(p)
                con.execute("""CREATE TABLE IF NOT EXISTS agent_memory_cache (
                    id INTEGER PRIMARY KEY AUTOINCREMENT, module TEXT DEFAULT 'shared',
                    session TEXT DEFAULT '', kind TEXT DEFAULT 'note',
                    content TEXT DEFAULT '', synced INTEGER DEFAULT 0,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP)""")
                con.commit()
                return con
            except Exception:
                continue
    return None


def remember(text: str, session: str = "", module: str = "ceo", role: str = "user") -> dict:
    text = (text or "").strip()
    if not text:
        return {"ok": False, "error": "empty text", "stored": "none"}
    if not session:
        con = _local_db()
        if con:
            con.execute("INSERT INTO agent_memory_cache (module,session,kind,content,synced) VALUES (?,?,?,?,?)",
                        (module, "", "remember", text[:4000], 0))
            con.commit()
            con.close()
        return {"ok": True, "stored": "local",
                "warning": "no session_id: kept locally, not sent to core"}
    c = cfg()
    r = _post("/v3/conversation/add", {
        "team_id": c["team"], "agent_id": agent_for(module), "user_id": c["user"],
        "session_id": session,
        "messages": [{"role": role, "content": text}],
    })
    if r.get("ok"):
        return {"ok": True, "stored": "core", "ms": r.get("ms", 0)}
    con = _local_db()
    if con:
        con.execute("INSERT INTO agent_memory_cache (module,session,kind,content,synced) VALUES (?,?,?,?,?)",
                    (module, session, "remember", text[:4000], 0))
        con.commit()
        con.close()
    return {"ok": False, "stored": "local", "error": r.get("error", "core down")}


def recall(query: str, session: str | None = None, module: str = "ceo", limit: int = 5) -> dict:
    c = cfg()
    body = {"team_id": c["team"], "agent_id": agent_for(module), "user_id": c["user"],
            "query": query, "limit": max(1, min(limit, 20))}
    if session:
        body["session_id"] = session
    se = _post("/v3/conversation/search", body)
    co = _post("/v3/core/read", {"team_id": c["team"], "agent_id": agent_for(module),
                                 "user_id": c["user"]})
    if se.get("ok") or co.get("ok"):
        ctx: list[str] = []
        for chunk in (co.get("data"), se.get("data")):
            s = chunk if isinstance(chunk, str) else json.dumps(chunk or "", ensure_ascii=False)
            if s and s not in ("[]", "{}", "null", ""):
                ctx.append(s[:1200])
                if sum(len(x) for x in ctx) > 3000:
                    break
        return {"ok": True, "source": "core", "context": "\n---\n".join(ctx),
                "profile": co.get("data"), "search": se.get("data")}
    return {"ok": False, "source": "none", "context": "",
            "error": se.get("error") or co.get("error") or "core down"}


def main() -> None:
    args = sys.argv[1:]
    cmd = args[0] if args else "status"
    module = "ceo"
    session: str | None = None
    for i, a in enumerate(args):
        if a == "--module" and i + 1 < len(args):
            module = args[i + 1]
        if a == "--session" and i + 1 < len(args):
            session = args[i + 1]
    if cmd == "status":
        print(json.dumps(status(module), indent=2))
    elif cmd == "recall" and len(args) > 1:
        print(json.dumps(recall(args[1], session, module), indent=2))
    elif cmd == "remember" and len(args) > 1:
        print(json.dumps(remember(args[1], session or "", module), indent=2))
    else:
        print(__doc__)


if __name__ == "__main__":
    main()
