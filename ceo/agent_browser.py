# -*- coding: utf-8 -*-
"""
SCITBD CEO — agent-browser bridge (Python side).

Wraps the vercel-labs/agent-browser CLI for the CEO runtime:
  https://github.com/vercel-labs/agent-browser.git

Sessions isolate tabs per module: scitbd-ceo | scitbd-sccrm | scitbd-trace | scitbd-autoflows

Usage:
    python agent_browser.py status
    python agent_browser.py open https://example.com
    python agent_browser.py snapshot
    python agent_browser.py read https://example.com/article
    python agent_browser.py shot
    python agent_browser.py research https://example.com "goal for report"
    python agent_browser.py monitor https://example.com
    python agent_browser.py act https://example.com click @e2
    python agent_browser.py close

Imported by scitbd_ceo_agent.py (browser_research/browser_monitor ops)
and browser_autoflow.py (CEO task -> browser job -> task_logs).
"""
from __future__ import annotations

import json
import os
import shlex
import shutil
import sqlite3
import subprocess
import sys
import zoneinfo
from datetime import datetime

DB_PATH = os.environ.get(
    "SCITBD_DB_PATH",
    r"D:/CRM_with_GOOGLE_Sheet/ceo/ceo - Copy/scitbd_ceo.db",
)
# Trace-repo copy (this integration) — falls back automatically.
TRACE_CEO_DB = os.path.join(os.path.dirname(os.path.abspath(__file__)), "scitbd_ceo.db")
BST = zoneinfo.ZoneInfo("Asia/Dhaka")
SESSION = os.environ.get("AGENT_BROWSER_SESSION", "scitbd-ceo")
BIN = os.environ.get("AGENT_BROWSER_BIN", "agent-browser")

try:
    sys.stdout.reconfigure(encoding="utf-8", errors="replace")
    sys.stderr.reconfigure(encoding="utf-8", errors="replace")
except Exception:
    pass


def _bin() -> str:
    if BIN and shutil.which(BIN):
        return BIN
    for cand in ("agent-browser", "agent-browser.cmd"):
        found = shutil.which(cand)
        if found:
            return found
    return "agent-browser"


def run_cli(*argv: str, timeout: int = 60) -> dict:
    """Run one CLI command. Never raises — returns {ok,code,text,ms,command}."""
    import time
    t0 = time.time()
    args = list(argv)
    # v0.27.x session isolation: explicit --session flag (env alone is unreliable).
    if args and args[0] not in ("--session", "--session-name") and "--session" not in args:
        args = ["--session", SESSION, *args]
    cmd = [_bin(), *args]
    env = dict(os.environ)
    env["AGENT_BROWSER_SESSION"] = SESSION
    # Layer 1 (CEO): BROWSER_USE_API_KEY for -p browseruse / cloud sessions.
    try:
        if not env.get("BROWSER_USE_API_KEY"):
            import browseruse_key as _BUK
            _k = _BUK.get()
            if _k:
                env["BROWSER_USE_API_KEY"] = _k
    except Exception:
        pass
    try:
        p = subprocess.run(cmd, capture_output=True, text=True, timeout=timeout, env=env)
        text = (p.stdout.strip() or p.stderr.strip())
        return {"ok": p.returncode == 0, "code": p.returncode,
                "text": text, "ms": int((time.time() - t0) * 1000),
                "command": " ".join(cmd)}
    except FileNotFoundError:
        return {"ok": False, "code": 127, "text": "agent-browser not installed (npm i -g agent-browser && agent-browser install)",
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
    r2 = run_cli("doctor", "--offline", "--quick", timeout=20)
    return r2["ok"] or ("agent-browser" in r2["text"].lower())


def status() -> dict:
    return {"ok": is_available(), "bin": _bin(), "session": SESSION,
            "db": DB_PATH if os.path.isfile(DB_PATH) else TRACE_CEO_DB,
            "repo": "https://github.com/vercel-labs/agent-browser.git"}


def connect() -> sqlite3.Connection:
    path = DB_PATH if os.path.isfile(DB_PATH) else TRACE_CEO_DB
    con = sqlite3.connect(path)
    con.row_factory = sqlite3.Row
    con.execute("PRAGMA foreign_keys=ON")
    return con


def ensure_tables(con: sqlite3.Connection) -> None:
    con.execute("""CREATE TABLE IF NOT EXISTS agent_browser_runs (
        id INTEGER PRIMARY KEY AUTOINCREMENT, module TEXT DEFAULT 'ceo',
        session TEXT DEFAULT '', command TEXT DEFAULT '', url TEXT,
        ok INTEGER DEFAULT 0, ms INTEGER DEFAULT 0, output_excerpt TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP)""")
    con.commit()


def log_run(url: str, command: str, ok: bool, ms: int, excerpt: str = "") -> None:
    try:
        con = connect()
        ensure_tables(con)
        con.execute("INSERT INTO agent_browser_runs (module,session,command,url,ok,ms,output_excerpt) VALUES (?,?,?,?,?,?,?)",
                    ("ceo", SESSION, command[:500], url[:500], 1 if ok else 0, ms, excerpt[:2000]))
        con.commit()
        con.close()
    except Exception:
        pass


def ops_log(action: str) -> None:
    try:
        con = connect()
        con.execute("INSERT INTO operational_logs (block_name,action_taken,status) VALUES (?,?,?)",
                    ("CEO BrowserAgent", action[:500], "SUCCESS"))
        con.commit()
        con.close()
    except Exception:
        pass


# ------------------------------------------------------------------ jobs ---

def job_research(url: str, goal: str = "") -> dict:
    """Open -> read -> snapshot -> screenshot -> template report. Returns {ok,report,shot,steps}."""
    steps = []
    r = run_cli("open", url, timeout=75)
    steps.append(("open", r["ok"]))
    log_run(url, "open", r["ok"], r["ms"], r["text"][:800])
    if not r["ok"]:
        return {"ok": False, "report": f"Open failed: {r['text'][:500]}", "shot": None, "steps": steps}
    # Rendered text via eval (v0.27.x has no `read`; native read on latest main).
    js_read = ("(() => { try { const el = document.body || document.documentElement; "
               "const t = (el.innerText || el.textContent || '').replace(/\\s+/g,' ').trim(); "
               "return t.slice(0,12000); } catch(e){ return 'READ-ERROR:'+String(e); } })()")
    rd = run_cli("eval", js_read, timeout=60)
    steps.append(("read", rd["ok"]))
    sn = run_cli("snapshot", "--json", timeout=45)
    steps.append(("snapshot", sn["ok"]))
    shot_path = os.path.join(os.path.dirname(os.path.abspath(__file__)), f"shot-{datetime.now(BST).strftime('%Y%m%d-%H%M%S')}.png")
    sh = run_cli("screenshot", shot_path, timeout=60)
    steps.append(("shot", sh["ok"]))
    shot = shot_path if sh["ok"] and os.path.isfile(shot_path) else None
    read_txt = rd["text"] if rd["ok"] else ""
    first = "\n".join([l.strip() for l in read_txt.splitlines() if l.strip()][:6])[:900]
    ok_n = sum(1 for _, o in steps if o)
    report = (f"**CEO Browser research — {url}**\n\n"
              f"- Steps: {ok_n}/{len(steps)} ok\n"
              f"- Goal: {goal or 'CEO intel for pipeline/briefing'}\n\n"
              + (f"**Lead text:**\n> {first.replace(chr(10), chr(10)+'> ')}\n\n" if first else "")
              + "**Next:** attach report + screenshot to daily_task / briefing.\n")
    log_run(url, "browser:research", True, r["ms"] + rd["ms"], report[:1000])
    ops_log(f"Browser research {url} ({ok_n}/{len(steps)} steps)")
    return {"ok": True, "report": report, "shot": shot, "steps": steps, "read_chars": len(read_txt)}


def job_monitor(url: str) -> dict:
    r = run_cli("open", url, timeout=75)
    log_run(url, "open", r["ok"], r["ms"], r["text"][:500])
    if not r["ok"]:
        return {"ok": False, "report": f"Monitor FAIL — open failed: {r['text'][:300]}", "ms": r["ms"]}
    t = run_cli("get", "title", timeout=30)
    u = run_cli("get", "url", timeout=30)
    title = t["text"].strip().splitlines()[0][:140] if t["ok"] else "?"
    report = f"Monitor OK — {url} → {u['text'].strip()[:160] if u['ok'] else url} | title: {title} | open {r['ms']}ms"
    log_run(url, "browser:monitor", True, r["ms"], report)
    return {"ok": True, "report": report, "ms": r["ms"]}


def main() -> None:
    cmd = sys.argv[1] if len(sys.argv) > 1 else "status"
    if cmd == "status":
        print(json.dumps(status(), indent=2))
    elif cmd == "open" and len(sys.argv) > 2:
        print(json.dumps(run_cli("open", sys.argv[2], timeout=75), indent=2))
    elif cmd == "snapshot":
        print(json.dumps(run_cli("snapshot", "--json", timeout=45), indent=2))
    elif cmd == "read":
        arg = sys.argv[2] if len(sys.argv) > 2 else None
        # Native `read` exists on latest main; v0.27.x falls back to eval.
        probe = run_cli("read", arg, timeout=60) if arg else run_cli("read", timeout=60)
        if probe["ok"] or "unknown command" not in probe["text"].lower():
            print(json.dumps(probe, indent=2))
        else:
            if arg:
                print(json.dumps(run_cli("open", arg, timeout=75), indent=2))
            js = ("(() => { try { const el = document.body || document.documentElement; "
                  "const t = (el.innerText || el.textContent || '').replace(/\\s+/g,' ').trim(); "
                  "return t.slice(0,12000); } catch(e){ return 'READ-ERROR:'+String(e); } })()")
            print(json.dumps(run_cli("eval", js, timeout=60), indent=2))
    elif cmd == "shot":
        path = sys.argv[2] if len(sys.argv) > 2 else os.path.join(os.path.dirname(os.path.abspath(__file__)), "shot-ceo.png")
        print(json.dumps(run_cli("screenshot", path, timeout=60), indent=2))
    elif cmd == "research" and len(sys.argv) > 2:
        print(json.dumps(job_research(sys.argv[2], sys.argv[3] if len(sys.argv) > 3 else ""), indent=2))
    elif cmd == "monitor" and len(sys.argv) > 2:
        print(json.dumps(job_monitor(sys.argv[2]), indent=2))
    elif cmd == "act" and len(sys.argv) > 4:
        # act <url> <click|fill|…> <sel> [text]
        url, do, sel = sys.argv[2], sys.argv[3], sys.argv[4]
        extra = sys.argv[5] if len(sys.argv) > 5 else ""
        print(json.dumps(run_cli("open", url, timeout=60), indent=2))
        if do == "click":
            print(json.dumps(run_cli("click", sel, timeout=45), indent=2))
        elif do in ("fill", "type"):
            print(json.dumps(run_cli(do, sel, extra, timeout=45), indent=2))
        else:
            print(json.dumps(run_cli(do, sel, timeout=45), indent=2))
    elif cmd == "close":
        print(json.dumps(run_cli("close", timeout=30), indent=2))
    elif cmd == "skills":
        name = sys.argv[2] if len(sys.argv) > 2 else "core"
        print(json.dumps(run_cli("skills", "get", name, timeout=20), indent=2))
    else:
        print(__doc__)


if __name__ == "__main__":
    main()
