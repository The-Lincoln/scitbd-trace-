# -*- coding: utf-8 -*-
"""
SCITBD AI CEO — Operational Block Scheduler & Query Helpers
===========================================================
Item 3: sync_block_status()  — flips operational_blocks.status to match BST clock
                              and auto-generates today's daily_tasks from block_tasks.
Item 4: get_current_block() / get_live_slot() — on-demand active block + live slot.

Timezone: Asia/Dhaka (BST / UTC+6), no DST — fixed offset.

Usage (CLI):
    python scitbd_block_scheduler.py status
    python scitbd_block_scheduler.py sync
    python scitbd_block_scheduler.py today
    python scitbd_block_scheduler.py run          # sync + print status

Usage (import):
    from scitbd_block_scheduler import get_current_block, get_live_slot, sync_block_status
"""
from __future__ import annotations

import datetime
import sqlite3
import sys
import zoneinfo

DB_PATH = r"D:/CRM_with_GOOGLE_Sheet/ceo/ceo - Copy/scitbd_ceo.db"
BST = zoneinfo.ZoneInfo("Asia/Dhaka")

# Windows console defaults to cp1252 — force UTF-8 so box-drawing /
# en-dash characters render instead of raising UnicodeEncodeError.
try:
    sys.stdout.reconfigure(encoding="utf-8", errors="replace")
    sys.stderr.reconfigure(encoding="utf-8", errors="replace")
except Exception:
    pass

# Slot states used when materialising daily_tasks
SLOT_STATE = {"PENDING", "IN_PROGRESS", "COMPLETED", "BLOCKED", "CANCELLED"}


# ──────────────────────────────────────────────────────────
# Connection helper
# ──────────────────────────────────────────────────────────
def connect(db_path: str = DB_PATH) -> sqlite3.Connection:
    con = sqlite3.connect(db_path)
    con.row_factory = sqlite3.Row
    con.execute("PRAGMA foreign_keys=ON")
    return con


def _now(now: datetime.datetime | None = None) -> datetime.datetime:
    return (now or datetime.datetime.now(BST)).astimezone(BST)


# ──────────────────────────────────────────────────────────
# Item 4a: which block owns this BST time?
# ──────────────────────────────────────────────────────────
def resolve_block_id(hhmm: str) -> int:
    """Map a 'HH:MM' BST string to its operational block id (1-4)."""
    if hhmm < "06:00":
        return 4          # 00:00-05:59  Block 4
    if hhmm < "12:00":
        return 1          # 06:00-11:59  Block 1
    if hhmm < "18:00":
        return 2          # 12:00-17:59  Block 2
    return 3              # 18:00-23:59  Block 3


def get_current_block(con: sqlite3.Connection | None = None,
                      now: datetime.datetime | None = None) -> dict | None:
    """
    Return the ACTIVE operational block for the current BST moment.
    Falls back to resolving by clock if no row is flagged ACTIVE.
    """
    own = con is None
    con = con or connect()
    ts = _now(now)
    hhmm = ts.strftime("%H:%M")
    bid = resolve_block_id(hhmm)

    row = con.execute(
        "SELECT * FROM operational_blocks WHERE id=?", (bid,)
    ).fetchone()

    if own:
        con.close()
    if not row:
        return None

    blk = dict(row)
    blk.update({
        "now_bst": ts.strftime("%Y-%m-%d %H:%M:%S"),
        "resolved_by": "clock",
        "is_flagged_active": blk.get("status") == "ACTIVE",
        "block_number": blk["id"],
        "hours_remaining": _hours_remaining(hhmm, blk["bst_end"]),
    })
    return blk


def _hours_remaining(hhmm: str, end: str) -> float:
    def m(s: str) -> int:
        h, mi = s.split(":")
        return int(h) * 60 + int(mi)
    cur = m(hhmm)
    e = m(end)
    delta = e - cur
    if delta <= 0:
        delta += 24 * 60          # block rolls past midnight
    return round(delta / 60.0, 2)


# ──────────────────────────────────────────────────────────
# Item 4b: which execution slot is live right now?
# ──────────────────────────────────────────────────────────
def get_live_slot(con: sqlite3.Connection | None = None,
                  now: datetime.datetime | None = None) -> dict | None:
    """Return the block_tasks row whose BST window contains now, else None."""
    own = con is None
    con = con or connect()
    ts = _now(now)
    hhmm = ts.strftime("%H:%M")
    bid = resolve_block_id(hhmm)

    slots = con.execute(
        "SELECT * FROM block_tasks WHERE block_id=? ORDER BY slot_start", (bid,)
    ).fetchall()

    hit = None
    for s in slots:
        ss, se = s["slot_start"], s["slot_end"]
        if se <= ss:
            # window crosses midnight (e.g. 23:30 -> 00:00)
            if hhmm >= ss or hhmm < se:
                hit = s
                break
        elif ss <= hhmm < se:
            hit = s
            break

    if own:
        con.close()

    if not hit:
        return None

    slot = dict(hit)
    slot.update({
        "now_bst": ts.strftime("%Y-%m-%d %H:%M:%S"),
        "block_id": bid,
        "block_number": bid,
        "minutes_remaining": _minutes_remaining(hhmm, slot["slot_end"]),
        "is_live": True,
    })
    return slot


def _minutes_remaining(hhmm: str, end: str) -> int:
    def m(s: str) -> int:
        h, mi = s.split(":")
        return int(h) * 60 + int(mi)
    d = m(end) - m(hhmm)
    if d <= 0:
        d += 24 * 60
    return d


def get_next_slot(con: sqlite3.Connection | None = None,
                  now: datetime.datetime | None = None) -> dict | None:
    """Return the next slot to start (rolls to tomorrow's block if needed)."""
    own = con is None
    con = con or connect()
    ts = _now(now)
    hhmm = ts.strftime("%H:%M")
    bid = resolve_block_id(hhmm)

    upcoming = con.execute(
        "SELECT * FROM block_tasks WHERE block_id=? AND slot_start>? ORDER BY slot_start",
        (bid, hhmm),
    ).fetchall()

    if not upcoming:
        nxt = con.execute(
            "SELECT * FROM block_tasks WHERE block_id=? ORDER BY slot_start",
            ((bid % 4) + 1,),
        ).fetchone()
    else:
        nxt = upcoming[0]

    if own:
        con.close()
    if not nxt:
        return None
    d = dict(nxt)
    d["block_number"] = d["block_id"]
    d["rolls_to_tomorrow"] = d["slot_start"] < hhmm
    return d


# ──────────────────────────────────────────────────────────
# Item 3: sync block status + materialise today's tasks
# ──────────────────────────────────────────────────────────
def sync_block_status(con: sqlite3.Connection | None = None,
                      now: datetime.datetime | None = None,
                      create_tasks: bool = True,
                      verbose: bool = False) -> dict:
    """
    1. Set exactly one operational_blocks row to ACTIVE (the one owning now).
       Previous ACTIVE row becomes COMPLETED; other rows become INACTIVE/UPCOMING.
    2. Optionally create today's daily_tasks from block_tasks (idempotent —
       skips slots already materialised for today's date).
    """
    own = con is None
    con = con or connect()
    ts = _now(now)
    hhmm = ts.strftime("%H:%M")
    date_str = ts.strftime("%Y-%m-%d")
    bid = resolve_block_id(hhmm)

    cur = con.cursor()
    result = {"active_block": bid, "now_bst": ts.strftime("%Y-%m-%d %H:%M:%S"),
              "created_tasks": [], "skipped_tasks": [], "status_changes": []}

    # --- 1. block status flips -----------------------------------
    for row in cur.execute("SELECT id, status FROM operational_blocks").fetchall():
        rid, status = row["id"], row["status"]
        if rid == bid and status != "ACTIVE":
            cur.execute("UPDATE operational_blocks SET status='ACTIVE' WHERE id=?", (rid,))
            result["status_changes"].append(f"block {rid}: {status} -> ACTIVE")
        elif rid != bid and status == "ACTIVE":
            new = "COMPLETED"
            cur.execute("UPDATE operational_blocks SET status=? WHERE id=?", (new, rid))
            result["status_changes"].append(f"block {rid}: ACTIVE -> {new}")
        elif rid != bid and status not in ("UPCOMING", "INACTIVE"):
            cur.execute("UPDATE operational_blocks SET status='INACTIVE' WHERE id=?", (rid,))
            result["status_changes"].append(f"block {rid}: {status} -> INACTIVE")

    # Flag the chronologically-next block as UPCOMING
    next_bid = (bid % 4) + 1
    cur.execute("UPDATE operational_blocks SET status='UPCOMING' WHERE id=?", (next_bid,))

    # --- 2. materialise today's daily_tasks ----------------------
    if create_tasks:
        slots = cur.execute(
            "SELECT * FROM block_tasks WHERE block_id=? ORDER BY slot_start", (bid,)
        ).fetchall()
        for s in slots:
            due = f"{date_str} {s['slot_start']}"
            exists = cur.execute(
                """SELECT id FROM daily_tasks
                    WHERE task_title=? AND due_date=? AND bst_block_id=?""",
                (s["slot_name"], due, bid),
            ).fetchone()
            if exists:
                result["skipped_tasks"].append({"slot": s["slot_name"], "task_id": exists["id"]})
                continue

            cur.execute(
                """INSERT INTO daily_tasks
                   (task_title, task_description, priority, status, category,
                    assignee, due_date, estimated_hours, bst_block_id, created_by)
                   VALUES (?,?,?,?,?,?,?,?,?,?)""",
                (
                    s["slot_name"],
                    _compose_description(s),
                    s["priority"] or "medium",
                    "pending",
                    s["category"] or "operations",
                    "ceo",
                    due,
                    _estimate_hours(s["slot_start"], s["slot_end"]),
                    bid,
                    "ai_agent",
                ),
            )
            tid = cur.lastrowid
            result["created_tasks"].append({"slot": s["slot_name"], "task_id": tid})

            cur.execute(
                """INSERT INTO task_logs (task_id, action, performed_by, details)
                   VALUES (?,?,?,?)""",
                (tid, "created", "ai_agent",
                 f"Auto-generated from block_tasks slot #{s['id']} for {date_str} (Block {bid})"),
            )

    # --- 3. audit log -------------------------------------------
    cur.execute(
        """INSERT INTO operational_logs (block_id, block_name, action_taken, status)
           VALUES (?,?,?,'SUCCESS')""",
        (bid, f"Block {bid}",
         f"sync_block_status @ {ts.strftime('%H:%M')} BST -> ACTIVE block {bid}; "
         f"{len(result['created_tasks'])} tasks created, "
         f"{len(result['skipped_tasks'])} skipped, "
         f"{len(result['status_changes'])} status flips."),
    )

    con.commit()
    if own:
        con.close()
    return result


def _compose_description(s: sqlite3.Row) -> str:
    parts = [
        f"BLOCK WINDOW: {s['slot_start']} - {s['slot_end']} BST",
        f"SLOT: {s['slot_name']}",
        "",
        "DETAILED TASKS:",
        s["detailed_tasks"] or "",
    ]
    if s["expected_outcome"]:
        parts += ["", "EXPECTED OUTCOME:", s["expected_outcome"]]
    if s["escalation_protocol"]:
        parts += ["", "ESCALATION PROTOCOL:", s["escalation_protocol"]]
    parts += ["", f"PRIORITY: {(s['priority'] or 'medium').upper()}",
              "RECURRING: daily (BST)"]
    return "\n".join(parts)


def _estimate_hours(start: str, end: str) -> float:
    def m(x: str) -> int:
        h, mi = x.split(":")
        return int(h) * 60 + int(mi)
    d = m(end) - m(start)
    if d <= 0:
        d += 24 * 60
    return round(d / 60.0, 2)


# ──────────────────────────────────────────────────────────
# Convenience: full status report
# ──────────────────────────────────────────────────────────
def status_report(con: sqlite3.Connection | None = None,
                  now: datetime.datetime | None = None) -> dict:
    own = con is None
    con = con or connect()
    blk = get_current_block(con, now)
    live = get_live_slot(con, now)
    nxt = get_next_slot(con, now)
    flags = [dict(r) for r in con.execute(
        "SELECT id, block_name, status FROM operational_blocks ORDER BY id")]
    if own:
        con.close()
    return {"current_block": blk, "live_slot": live, "next_slot": nxt,
            "block_flags": flags}


# ──────────────────────────────────────────────────────────
# CLI entry point
# ──────────────────────────────────────────────────────────
def _print_status() -> None:
    st = status_report()
    b, l, n = st["current_block"], st["live_slot"], st["next_slot"]
    print("=" * 68)
    print(f"  SCITBD OPERATIONAL STATUS — {b['now_bst']} BST")
    print("=" * 68)
    print(f"  ACTIVE BLOCK : #{b['id']}  {b['block_name']}")
    print(f"  Region       : {b['regional_focus']}")
    print(f"  Focus        : {b['core_execution_focus']}")
    print(f"  Window       : {b['bst_start']}-{b['bst_end']} BST "
          f"({b['utc_start']}-{b['utc_end']} UTC)")
    print(f"  Remaining    : {b['hours_remaining']}h")
    print("-" * 68)
    if l:
        print(f"  LIVE SLOT    : {l['slot_start']}-{l['slot_end']}  {l['slot_name']}")
        print(f"  Priority     : {l['priority'].upper()}   Category: {l['category']}")
        print(f"  Time left    : {l['minutes_remaining']} min")
        print(f"  Task         : {(l['detailed_tasks'] or '')[:90]}...")
        if l["escalation_protocol"]:
            print(f"  Escalation   : {l['escalation_protocol'][:88]}")
    else:
        print("  LIVE SLOT    : none (between slots)")
    print("-" * 68)
    if n:
        print(f"  NEXT SLOT    : {n['slot_start']}-{n['slot_end']}  {n['slot_name']}")
    print("-" * 68)
    for f in st["block_flags"]:
        mark = "*" if f["status"] == "ACTIVE" else ("+" if f["status"] == "UPCOMING" else " ")
        print(f"  [{mark}] #{f['id']} {f['status']:<10} {f['block_name']}")
    print("=" * 68)


def _print_today() -> None:
    con = connect()
    rows = con.execute(
        """SELECT bt.slot_start, bt.slot_end, bt.slot_name, bt.priority, bt.category,
                  ob.id AS block_id
             FROM block_tasks bt JOIN operational_blocks ob ON ob.id=bt.block_id
            ORDER BY CASE ob.id WHEN 1 THEN 1 WHEN 2 THEN 2 WHEN 3 THEN 3 ELSE 4 END,
                     bt.slot_start"""
    ).fetchall()
    con.close()
    print("=" * 68)
    print("  SCITBD 24-HOUR OPERATIONAL SCHEDULE (Asia/Dhaka BST)")
    print("=" * 68)
    cur_block = None
    for r in rows:
        if r["block_id"] != cur_block:
            cur_block = r["block_id"]
            print(f"\n ── BLOCK {cur_block} " + "─" * 50)
        print(f"  {r['slot_start']}-{r['slot_end']}  {r['priority']:<8} "
              f"{r['category']:<11} {r['slot_name']}")
    print("\n" + "=" * 68)


if __name__ == "__main__":
    cmd = sys.argv[1] if len(sys.argv) > 1 else "run"
    if cmd == "status":
        _print_status()
    elif cmd == "today":
        _print_today()
    elif cmd == "sync":
        res = sync_block_status(verbose=True)
        print(f"Active block : {res['active_block']}")
        print(f"Status flips : {res['status_changes'] or 'none'}")
        print(f"Created      : {len(res['created_tasks'])} task(s)")
        for t in res["created_tasks"]:
            print(f"   + #{t['task_id']} {t['slot']}")
        print(f"Skipped      : {len(res['skipped_tasks'])} (already exists)")
    elif cmd == "run":
        res = sync_block_status()
        print(f"[sync] active={res['active_block']} "
              f"created={len(res['created_tasks'])} "
              f"skipped={len(res['skipped_tasks'])} "
              f"flips={res['status_changes']}")
        _print_status()
    else:
        print(__doc__)
        sys.exit(1)
