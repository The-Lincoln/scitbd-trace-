# -*- coding: utf-8 -*-
"""
SCITBD CEO — browser autoflow worker.

Turns CEO daily_tasks tagged for browser work into agent-browser jobs,
then writes results back to task_logs + operational_logs.

Convention: any daily_task whose title/description contains
  [browser] / [research] / [monitor] / a http(s) URL
is picked up. Manual run:

    python browser_autoflow.py run [--limit 5] [--block 3]
    python browser_autoflow.py scan            # list candidates, no writes
"""
from __future__ import annotations

import re
import sqlite3
import sys
import zoneinfo
from datetime import datetime

import agent_browser as AB

BST = zoneinfo.ZoneInfo("Asia/Dhaka")
URL_RE = re.compile(r"https?://[^\s'\"<>]+", re.I)


def connect() -> sqlite3.Connection:
    return AB.connect()


def candidates(con: sqlite3.Connection, block: int | None = None, limit: int = 20):
    q = ("SELECT id, task_title, task_description, priority, status, bst_block_id "
         "FROM daily_tasks WHERE status IN ('pending','in_progress') ")
    params: list = []
    if block:
        q += " AND bst_block_id=? "
        params.append(block)
    q += " ORDER BY CASE priority WHEN 'critical' THEN 0 WHEN 'high' THEN 1 ELSE 2 END, id LIMIT ?"
    params.append(limit)
    rows = [dict(r) for r in con.execute(q, params).fetchall()]
    out = []
    for r in rows:
        blob = f"{r['task_title']} {r['task_description'] or ''}"
        urls = URL_RE.findall(blob)
        tagged = ("[browser]" in blob.lower() or "[research]" in blob.lower()
                  or "[monitor]" in blob.lower() or bool(urls))
        if tagged:
            r["urls"] = urls
            r["kind"] = "monitor" if "[monitor]" in blob.lower() else "research"
            out.append(r)
    return out


def run_task(con: sqlite3.Connection, task: dict) -> dict:
    url = (task["urls"] or [""])[0]
    if not url:
        return {"id": task["id"], "ok": False, "note": "no URL found"}
    kind = task.get("kind", "research")
    con.execute("UPDATE daily_tasks SET status='in_progress', updated_at=? WHERE id=?",
                (datetime.now(BST).strftime("%Y-%m-%d %H:%M:%S"), task["id"]))
    if kind == "monitor":
        res = AB.job_monitor(url)
        note = res.get("report", "")
        ok = bool(res.get("ok"))
    else:
        res = AB.job_research(url, task["task_title"])
        note = res.get("report", "")
        if res.get("shot"):
            note += f"\nShot: {res['shot']}"
        ok = bool(res.get("ok"))
    now = datetime.now(BST).strftime("%Y-%m-%d %H:%M:%S")
    if ok:
        con.execute("UPDATE daily_tasks SET status='completed', completed_at=?, updated_at=? WHERE id=?", (now, now, task["id"]))
        action = "completed"
    else:
        con.execute("UPDATE daily_tasks SET status='pending', updated_at=? WHERE id=?", (now, task["id"]))
        action = "browser-failed"
    con.execute("INSERT INTO task_logs (task_id, action, performed_by, details) VALUES (?,?,?,?)",
                (task["id"], action, "browser_autoflow", note[:500]))
    try:
        con.execute("INSERT INTO operational_logs (block_id, block_name, action_taken, status) VALUES (?,?,?,?)",
                    (task.get("bst_block_id"), f"Block {task.get('bst_block_id')}", f"Browser {kind} task #{task['id']}: {note[:300]}", "SUCCESS" if ok else "FAILED"))
    except Exception:
        pass
    con.commit()
    return {"id": task["id"], "ok": ok, "note": note[:300]}


def main() -> None:
    args = sys.argv[1:]
    cmd = args[0] if args else "scan"
    limit = 5
    block = None
    for i, a in enumerate(args):
        if a == "--limit" and i + 1 < len(args):
            limit = max(1, int(args[i + 1]))
        if a == "--block" and i + 1 < len(args):
            block = int(args[i + 1])
    con = connect()
    AB.ensure_tables(con)
    tasks = candidates(con, block, limit * 3)[:limit]
    if cmd == "scan":
        print(f"candidates: {len(tasks)}")
        for t in tasks:
            print(f"#{t['id']} [{t['kind']}/{t['priority']}/{t['status']}] {t['task_title'][:90]} :: {t['urls'][:2]}")
        return
    if cmd == "run":
        print(f"running {len(tasks)} browser task(s)")
        for t in tasks:
            print(run_task(con, t))
        return
    print(__doc__)


if __name__ == "__main__":
    main()
