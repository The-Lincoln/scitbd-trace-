# -*- coding: utf-8 -*-
"""
SCITBD CEO — openviking bridge (Python side).

viking:// context database for CEO agents (memory + knowledge + skills):
  https://github.com/The-Lincoln/OpenViking.git
  vendored: external/openviking (docs.openviking.ai)
  install: curl -fsSL https://openviking.ai/install | bash -s -- --yes --url <SERVER_URL>

Companion to agent_memory.py (TencentDB team memory): OpenViking for
inspectable file-like context; AgentMemory for governed team assets.
Never raises on CLI failure — local fallback keeps agents working.

Usage:
    python openviking.py status
    python openviking.py find "auth module decision"
    python openviking.py ls viking://user/scbd/memories
    python openviking.py add-resource https://github.com/org/repo

Env: OPENVIKING_URL, OPENVIKING_API_KEY (user key — root keys can't
read/write memories), OPENVIKING_ACCOUNT/USER, OV_BIN.
"""
from __future__ import annotations

import json
import os
import shutil
import sqlite3
import subprocess
import sys
import tempfile
import time

TRACE_ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))

try:
    sys.stdout.reconfigure(encoding="utf-8", errors="replace")
    sys.stderr.reconfigure(encoding="utf-8", errors="replace")
except Exception:
    pass


def _bin() -> str:
    env = os.environ.get("OV_BIN", "")
    if env and os.path.isfile(env):
        return env
    for cand in ("ov", "ov.exe"):
        found = shutil.which(cand)
        if found:
            return found
    home = os.environ.get("HOME") or os.environ.get("USERPROFILE") or ""
    for p in (os.path.join(home, ".local", "bin", "ov"),
              os.path.join(home, ".openviking", "bin", "ov")):
        if home and os.path.isfile(p):
            return p
    return "ov"


def run_cli(*argv: str, timeout: int = 30) -> dict:
    """Run one ov command. Never raises — returns {ok,code,text,ms,command}."""
    t0 = time.time()
    cmd = [_bin(), *argv]
    try:
        p = subprocess.run(cmd, capture_output=True, text=True, timeout=timeout)
        text = (p.stdout.strip() or p.stderr.strip())
        return {"ok": p.returncode == 0, "code": p.returncode,
                "text": text, "ms": int((time.time() - t0) * 1000),
                "command": " ".join(cmd)}
    except FileNotFoundError:
        return {"ok": False, "code": 127,
                "text": "ov not installed (curl -fsSL https://openviking.ai/install | bash -s -- --yes --url <SERVER_URL>)",
                "ms": 0, "command": " ".join(cmd)}
    except subprocess.TimeoutExpired:
        return {"ok": False, "code": 124, "text": f"timeout after {timeout}s",
                "ms": timeout * 1000, "command": " ".join(cmd)}
    except Exception as ex:
        return {"ok": False, "code": 1, "text": str(ex), "ms": 0, "command": " ".join(cmd)}


def status() -> dict:
    r = run_cli("status", timeout=15)
    c = {"url": os.environ.get("OPENVIKING_URL", ""),
         "has_key": bool(os.environ.get("OPENVIKING_API_KEY", ""))}
    return {"ok": r["ok"], "driver": "openviking", "bin": _bin(),
            "server_url": c["url"] or "(not set)", "has_api_key": c["has_key"],
            "detail": r["text"][:300], "ms": r["ms"],
            "hint": "Ready." if r["ok"] else "Install ov + start server, then set OPENVIKING_URL (+ user key)"}


def find(query: str, uri: str = "", limit: int = 5) -> dict:
    args = ["find", query]
    if uri:
        args += ["--uri", uri]
    if limit:
        args += ["--limit", str(max(1, min(limit, 20)))]
    r = run_cli(*args, timeout=60)
    r["source"] = "openviking" if r["ok"] else "none"
    return r


def log_run(kind: str, target: str, ok: bool, ms: int, excerpt: str = "") -> None:
    try:
        for name in ("scitbd_ceo.db",):
            path = os.path.join(TRACE_ROOT, "ceo", name)
            if not os.path.isfile(path):
                continue
            con = sqlite3.connect(path)
            con.execute("""CREATE TABLE IF NOT EXISTS openviking_runs (
                id INTEGER PRIMARY KEY AUTOINCREMENT, module TEXT DEFAULT 'ceo',
                kind TEXT DEFAULT 'find', target TEXT DEFAULT '', ok INTEGER DEFAULT 0,
                ms INTEGER DEFAULT 0, output_excerpt TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP)""")
            con.execute("INSERT INTO openviking_runs (module,kind,target,ok,ms,output_excerpt) VALUES (?,?,?,?,?,?)",
                        ("ceo", kind[:60], target[:500], 1 if ok else 0, ms, excerpt[:2000]))
            con.commit()
            con.close()
            return
    except Exception:
        pass


def main() -> None:
    cmd = sys.argv[1] if len(sys.argv) > 1 else "status"
    if cmd == "status":
        print(json.dumps(status(), indent=2))
    elif cmd == "find" and len(sys.argv) > 2:
        uri = sys.argv[3] if len(sys.argv) > 3 else ""
        r = find(sys.argv[2], uri)
        log_run("find", sys.argv[2], r["ok"], r["ms"], r["text"][:500])
        print(json.dumps(r, indent=2))
    elif cmd == "ls":
        print(json.dumps(run_cli("ls", sys.argv[2] if len(sys.argv) > 2 else "viking://", timeout=30), indent=2))
    elif cmd == "add-resource" and len(sys.argv) > 2:
        print(json.dumps(run_cli("add-resource", sys.argv[2], timeout=60), indent=2))
    else:
        print(__doc__)


if __name__ == "__main__":
    main()
