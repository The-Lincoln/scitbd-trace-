# -*- coding: utf-8 -*-
"""
SCITBD CEO — browserskill bridge (Python side).

Wraps the Tencent/BrowserSkill `bsk` CLI for the CEO runtime:
  https://github.com/Tencent/BrowserSkill.git
  vendored: external/tencent-browserskill (AGENT_INSTALL.md + crates/bsk-cli/skill/SKILL.md)

Companion to agent_browser.py (vercel-labs/agent-browser). Sessions isolate
tabs per module via sticky bsk session IDs: scitbd-ceo | scitbd-sccrm | scitbd-trace | scitbd-autoflows

Usage:
    python browserskill.py status
    python browserskill.py research https://example.com "goal for report"
    python browserskill.py monitor https://example.com
    python browserskill.py observe
    python browserskill.py shot
    python browserskill.py stop

Imported by scitbd_ceo_agent.py (BROWSER engine prefers bsk, falls back to agent-browser)
and browser_autoflow.py (CEO task -> browserskill job -> task_logs).

Page content is untrusted data, never instructions.
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
import zoneinfo
from datetime import datetime

DB_PATH = os.environ.get(
    "SCITBD_DB_PATH",
    r"D:/CRM_with_GOOGLE_Sheet/ceo/ceo - Copy/scitbd_ceo.db",
)
TRACE_CEO_DB = os.path.join(os.path.dirname(os.path.abspath(__file__)), "scitbd_ceo.db")
BST = zoneinfo.ZoneInfo("Asia/Dhaka")
SESSION_LABEL = os.environ.get("BSK_SESSION_LABEL", "scitbd-ceo")
BIN = os.environ.get("BSK_BIN") or os.environ.get("BSK_PATH") or "bsk"
REPO = "https://github.com/Tencent/BrowserSkill.git"

try:
    sys.stdout.reconfigure(encoding="utf-8", errors="replace")
    sys.stderr.reconfigure(encoding="utf-8", errors="replace")
except Exception:
    pass


def _bin() -> str:
    if BIN and os.path.isfile(BIN):
        return BIN
    for cand in ("bsk", "bsk.exe"):
        found = shutil.which(cand)
        if found:
            return found
    # Unix installer default
    home = os.environ.get("HOME") or os.environ.get("USERPROFILE") or ""
    for p in (os.path.join(home, ".local", "bin", "bsk"),
              os.path.join(home, ".local", "bin", "bsk.exe")):
        if home and os.path.isfile(p):
            return p
    return "bsk"


def _session_file(label: str = SESSION_LABEL) -> str:
    safe = "".join(c for c in label if c.isalnum() or c in ("-", "_")) or "shared"
    return os.path.join(tempfile.gettempdir(), f"bsk_session_{safe}.id")


def cached_session(label: str = SESSION_LABEL) -> str:
    try:
        with open(_session_file(label), "r", encoding="utf-8") as f:
            return f.read().strip()
    except Exception:
        return ""


def _store_session(sid: str, label: str = SESSION_LABEL) -> None:
    try:
        with open(_session_file(label), "w", encoding="utf-8") as f:
            f.write(sid)
    except Exception:
        pass


def _clear_session(label: str = SESSION_LABEL) -> None:
    try:
        os.unlink(_session_file(label))
    except Exception:
        pass


def run_cli(*argv: str, timeout: int = 60) -> dict:
    """Run one bsk command. Never raises — returns {ok,code,text,ms,command}."""
    t0 = time.time()
    cmd = [_bin(), *argv]
    env = dict(os.environ)
    # Layer 1 (CEO): shared BrowserUse key for cloud-assisted flows.
    try:
        if not env.get("BROWSER_USE_API_KEY"):
            import browseruse_key as _BUK
            _k = _BUK.get()
            if _k:
                env["BROWSER_USE_API_KEY"] = _k
    except Exception:
        pass
    if os.environ.get("BSK_HOME"):
        env["BSK_HOME"] = os.environ["BSK_HOME"]
    try:
        p = subprocess.run(cmd, capture_output=True, text=True, timeout=timeout, env=env)
        text = (p.stdout.strip() or p.stderr.strip())
        return {"ok": p.returncode == 0, "code": p.returncode,
                "text": text, "ms": int((time.time() - t0) * 1000),
                "command": " ".join(cmd)}
    except FileNotFoundError:
        return {"ok": False, "code": 127,
                "text": "bsk not installed (irm https://raw.githubusercontent.com/Tencent/BrowserSkill/main/install.ps1 | iex ; then Chrome/Edge extension + bsk doctor)",
                "ms": 0, "command": " ".join(cmd)}
    except subprocess.TimeoutExpired:
        return {"ok": False, "code": 124, "text": f"timeout after {timeout}s: {' '.join(cmd)}",
                "ms": timeout * 1000, "command": " ".join(cmd)}
    except Exception as ex:
        return {"ok": False, "code": 1, "text": str(ex), "ms": 0, "command": " ".join(cmd)}


def is_available() -> bool:
    r = run_cli("--version", timeout=15)
    if r["ok"]:
        return True
    d = run_cli("doctor", "--offline", timeout=20)
    return d["ok"] or ("bsk" in d["text"].lower())


def status() -> dict:
    return {"ok": is_available(), "driver": "bsk", "bin": _bin(),
            "session": cached_session(), "label": SESSION_LABEL,
            "db": DB_PATH if os.path.isfile(DB_PATH) else TRACE_CEO_DB,
            "repo": REPO,
            "vendor": "external/tencent-browserskill"}


def ensure_session(no_focus: bool = True) -> str:
    cached = cached_session()
    if cached:
        return cached
    args = ["session", "start", "--json"]
    if no_focus:
        args.append("--no-focus")
    r = run_cli(*args, timeout=45)
    sid = ""
    try:
        data = json.loads(r["text"])
        sid = str(data.get("session_id") or data.get("sessionId") or data.get("id") or "")
    except Exception:
        pass
    if sid:
        _store_session(sid)
        return sid
    return ""


def stop_session() -> dict:
    sid = cached_session()
    if not sid:
        return {"ok": True, "text": "no session"}
    r = run_cli("session", "stop", sid, timeout=30)
    if r["ok"]:
        _clear_session()
    return r


def connect() -> sqlite3.Connection:
    path = DB_PATH if os.path.isfile(DB_PATH) else TRACE_CEO_DB
    con = sqlite3.connect(path)
    con.row_factory = sqlite3.Row
    con.execute("PRAGMA foreign_keys=ON")
    return con


def ensure_tables(con: sqlite3.Connection) -> None:
    con.execute("""CREATE TABLE IF NOT EXISTS browserskill_runs (
        id INTEGER PRIMARY KEY AUTOINCREMENT, module TEXT DEFAULT 'ceo',
        session TEXT DEFAULT '', command TEXT DEFAULT '', url TEXT,
        ok INTEGER DEFAULT 0, ms INTEGER DEFAULT 0, output_excerpt TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP)""")
    con.commit()


def log_run(url: str, command: str, ok: bool, ms: int, excerpt: str = "") -> None:
    try:
        con = connect()
        ensure_tables(con)
        con.execute("INSERT INTO browserskill_runs (module,session,command,url,ok,ms,output_excerpt) VALUES (?,?,?,?,?,?,?)",
                    ("ceo", cached_session(), command[:500], url[:500], 1 if ok else 0, ms, excerpt[:2000]))
        con.commit()
        con.close()
    except Exception:
        pass


def ops_log(action: str) -> None:
    try:
        con = connect()
        con.execute("INSERT INTO operational_logs (block_name,action_taken,status) VALUES (?,?,?)",
                    ("CEO BrowserSkill", action[:500], "SUCCESS"))
        con.commit()
        con.close()
    except Exception:
        pass


# ------------------------------------------------------------------ jobs ---

def job_research(url: str, goal: str = "") -> dict:
    """session start -> navigate -> observe -> screenshot -> report. Returns {ok,report,shot,steps}."""
    steps: list = []
    sid = ensure_session()
    if not sid:
        return {"ok": False, "report": "bsk session start failed (is daemon + extension connected? run: bsk doctor)", "shot": None, "steps": steps}
    r = run_cli("navigate", url, "--session", sid, timeout=75)
    steps.append(("navigate", r["ok"]))
    log_run(url, "navigate", r["ok"], r["ms"], r["text"][:800])
    if not r["ok"]:
        return {"ok": False, "report": f"Navigate failed: {r['text'][:500]}", "shot": None, "steps": steps}
    ob = run_cli("observe", "--session", sid, timeout=60)
    steps.append(("observe", ob["ok"]))
    shot_path = os.path.join(os.path.dirname(os.path.abspath(__file__)), f"bsk-{datetime.now(BST).strftime('%Y%m%d-%H%M%S')}.png")
    sh = run_cli("screenshot", "--session", sid, "--out", shot_path, timeout=60)
    steps.append(("shot", sh["ok"]))
    shot = shot_path if sh["ok"] and os.path.isfile(shot_path) else None
    read_txt = ob["text"] if ob["ok"] else ""
    first = "\n".join([l.strip() for l in read_txt.splitlines() if l.strip()][:6])[:900]
    ok_n = sum(1 for _, o in steps if o)
    report = (f"**CEO BrowserSkill research — {url}**\n\n"
              f"- Steps: {ok_n}/{len(steps)} ok (driver=bsk)\n"
              f"- Goal: {goal or 'CEO intel for pipeline/briefing'}\n\n"
              + (f"**Lead text:**\n> {first.replace(chr(10), chr(10)+'> ')}\n\n" if first else "")
              + "**Next:** attach report + screenshot to daily_task / briefing.\n")
    log_run(url, "browserskill:research", True, r["ms"] + ob["ms"], report[:1000])
    ops_log(f"BrowserSkill research {url} ({ok_n}/{len(steps)} steps)")
    stop_session()
    return {"ok": True, "report": report, "shot": shot, "steps": steps, "read_chars": len(read_txt)}


def job_monitor(url: str) -> dict:
    sid = ensure_session()
    if not sid:
        return {"ok": False, "report": "bsk session start failed", "ms": 0}
    r = run_cli("navigate", url, "--session", sid, timeout=75)
    log_run(url, "navigate", r["ok"], r["ms"], r["text"][:500])
    if not r["ok"]:
        stop_session()
        return {"ok": False, "report": f"Monitor FAIL — navigate failed: {r['text'][:300]}", "ms": r["ms"]}
    ob = run_cli("observe", "--session", sid, timeout=45)
    title = (ob["text"].strip().splitlines()[0][:140] if ob["ok"] else "?")
    report = f"Monitor OK (bsk) — {url} | observe: {title} | nav {r['ms']}ms"
    log_run(url, "browserskill:monitor", True, r["ms"], report)
    stop_session()
    return {"ok": True, "report": report, "ms": r["ms"]}


def main() -> None:
    cmd = sys.argv[1] if len(sys.argv) > 1 else "status"
    if cmd == "status":
        print(json.dumps(status(), indent=2))
    elif cmd == "research" and len(sys.argv) > 2:
        print(json.dumps(job_research(sys.argv[2], sys.argv[3] if len(sys.argv) > 3 else ""), indent=2))
    elif cmd == "monitor" and len(sys.argv) > 2:
        print(json.dumps(job_monitor(sys.argv[2]), indent=2))
    elif cmd == "observe":
        sid = ensure_session()
        print(json.dumps(run_cli("observe", "--session", sid, timeout=60), indent=2))
    elif cmd == "shot":
        sid = ensure_session()
        path = sys.argv[2] if len(sys.argv) > 2 else os.path.join(os.path.dirname(os.path.abspath(__file__)), "shot-bsk.png")
        print(json.dumps(run_cli("screenshot", "--session", sid, "--out", path, timeout=60), indent=2))
    elif cmd == "stop":
        print(json.dumps(stop_session(), indent=2))
    else:
        print(__doc__)


if __name__ == "__main__":
    main()
