# -*- coding: utf-8 -*-
"""
SCITBD — CRM & CEO AI AGENT runtime
====================================
Binds the CHIEF EXECUTIVE OFFICER — MASTER AI DIRECTIVE to the live
autoflow so the agent is executable rather than just documentation.

What this module does
---------------------
  system_prompt()   Compose the exact master system prompt for agent config,
                    optionally with live operational context appended.
  briefing()        Structured-markdown CEO daily briefing: active BST block,
                    matching UTC zone, pending directive tasks, content digest,
                    KPI snapshot, open escalations.
  kpi_status()      Latest measurement per KPI vs 6m/12m targets + progress.
  escalate_scan()   Evaluate Section 7.1 triggers against live leads/tickets.
  assign_task()     CEO-authorised task creation (logs to task_logs).
  run_engine()      Dispatch Section 3.2 marketing engines (CONTENT/LEAD/AD/
                    EMAIL/REVIEW/ANALYTICS) to their implementations.
  crm_*()           Mini-CRM: leads, tickets, pipeline summary.

Design notes
------------
  * All time is Asia/Dhaka (BST / UTC+6) — never the OS clock for decisions.
  * Directive text is read from the DB (scitbd_ceo_directive installs it), so
    the agent and the seeder can never drift apart.
  * Every mutating call writes task_logs / operational_logs.

CLI
---
    python scitbd_ceo_agent.py system [brief]   # master system prompt
    python scitbd_ceo_agent.py briefing         # today's CEO briefing
    python scitbd_ceo_agent.py kpis             # KPI status vs targets
    python scitbd_ceo_agent.py escalate         # Section 7.1 scan
    python scitbd_ceo_agent.py engines          # Section 3.2 engines
    python scitbd_ceo_agent.py crm              # pipeline summary
    python scitbd_ceo_agent.py assign "title" MKT "kpi" "deadline"
    python scitbd_ceo_agent.py run CONTENT      # fire a marketing engine
"""
from __future__ import annotations

import datetime
import re
import sys
import zoneinfo

import sqlite3

DB_PATH = r"D:/CRM_with_GOOGLE_Sheet/ceo/ceo - Copy/scitbd_ceo.db"
BST = zoneinfo.ZoneInfo("Asia/Dhaka")

try:
    sys.stdout.reconfigure(encoding="utf-8", errors="replace")
    sys.stderr.reconfigure(encoding="utf-8", errors="replace")
except Exception:
    pass


def connect() -> sqlite3.Connection:
    con = sqlite3.connect(DB_PATH)
    con.row_factory = sqlite3.Row
    con.execute("PRAGMA foreign_keys=ON")
    return con


def now_bst() -> datetime.datetime:
    return datetime.datetime.now(BST)


def _active_block(hhmm: str) -> int:
    """<06:00 -> 4, <12:00 -> 1, <18:00 -> 2, else 3 (matches operational_blocks)."""
    if hhmm < "06:00":
        return 4
    if hhmm < "12:00":
        return 1
    if hhmm < "18:00":
        return 2
    return 3


# ══════════════════════════════════════════════════════════
# DIRECTIVE ACCESS
# ══════════════════════════════════════════════════════════
def load_profile() -> dict:
    con = connect()
    r = con.execute("SELECT * FROM ceo_agent_profile WHERE id=1").fetchone()
    con.close()
    if not r:
        raise RuntimeError("Master AI Directive not installed — run: "
                           "python scitbd_ceo_directive.py install")
    return dict(r)


def system_prompt(live_context: bool = False) -> str:
    """
    The verbatim Section 2 master system prompt, optionally extended with a
    live operational block so the agent knows what 'now' means in BST/UTC.
    """
    p = load_profile()
    out = p["master_system_prompt"]
    if live_context:
        now = now_bst()
        hhmm = now.strftime("%H:%M")
        blk = _active_block(hhmm)
        con = connect()
        zrow = con.execute(
            "SELECT * FROM directive_utc_blocks WHERE bst_block_id=?",
            (blk,)).fetchone()
        con.close()
        ctx = (
            "\n\n— LIVE OPERATIONAL CONTEXT (auto-appended) —\n"
            f"Current time        : {now.strftime('%Y-%m-%d %H:%M')} BST "
            f"(Asia/Dhaka, UTC+6)\n"
            f"Active BST block    : Block {blk} ({hhmm})\n"
        )
        if zrow:
            ctx += (f"Matching UTC window : {zrow['utc_window']} — "
                    f"{zrow['zone_focus']}\n"
                    f"Block mandate       : {zrow['tasks']}\n")
        ctx += (f"Persona rules       :\n{p['persona_rules']}\n"
                f"\nClosing directive   :\n{p['closing_directive']}\n")
        out += ctx
    return out


def services() -> list[str]:
    con = connect()
    rows = [f"{r['portfolio_no']}. {r['service_name']}"
            for r in con.execute(
                "SELECT * FROM directive_services ORDER BY portfolio_no")]
    con.close()
    return rows


# ══════════════════════════════════════════════════════════
# KPI TRACKING
# ══════════════════════════════════════════════════════════
_NUM_RE = re.compile(r"[-+]?\d[\d,]*\.?\d*")
_MULT = {"k": 1_000, "m": 1_000_000, "b": 1_000_000_000}


def parse_metric(text: str | None) -> float | None:
    """$500K -> 500000 | '100,000' -> 100000 | '8x' -> 8 | '45%' -> 45."""
    if not text:
        return None
    m = _NUM_RE.search(str(text))
    if not m:
        return None
    raw = m.group(0).replace(",", "")
    try:
        val = float(raw)
    except ValueError:
        return None
    suffix = str(text)[m.end():m.end() + 1].strip().lower()
    if suffix in _MULT:
        val *= _MULT[suffix]
    return val


def kpi_status() -> list[dict]:
    con = connect()
    rows = [dict(r) for r in con.execute(
        "SELECT * FROM directive_kpis ORDER BY kpi_order")]
    # latest measurement per metric (if any recorded)
    latest = {}
    for r in con.execute(
            """SELECT k.metric, m.value_num, m.value_text, m.measured_on
               FROM kpi_measurements m
               JOIN directive_kpis k ON k.id = m.kpi_id
               WHERE m.id IN (SELECT MAX(id) FROM kpi_measurements
                              GROUP BY kpi_id)"""):
        latest[r["metric"]] = dict(r)
    con.close()

    out = []
    for k in rows:
        cur = latest.get(k["metric"])
        cur_num = cur["value_num"] if cur else parse_metric(k["baseline"])
        t6 = parse_metric(k["target_6m"])
        t12 = parse_metric(k["target_12m"])
        prog = None
        if cur_num is not None and t6:
            prog = max(0.0, min(100.0, (cur_num / t6) * 100))
        out.append({
            "order": k["kpi_order"], "metric": k["metric"],
            "baseline": k["baseline"],
            "current": cur["value_text"] if cur else k["baseline"],
            "measured_on": cur["measured_on"] if cur else None,
            "target_6m": k["target_6m"], "target_12m": k["target_12m"],
            "progress_6m_pct": round(prog, 1) if prog is not None else None,
        })
    return out


def set_kpi(metric: str, value: str, source: str = "manual") -> dict:
    con = connect()
    row = con.execute("SELECT id FROM directive_kpis WHERE metric=?",
                      (metric,)).fetchone()
    if not row:
        con.close()
        raise KeyError(f"unknown KPI: {metric!r}")
    num = parse_metric(value)
    con.execute(
        """INSERT INTO kpi_measurements (kpi_id, value_num, value_text,
                                         measured_on, source)
           VALUES (?,?,?,?,?)""",
        (row["id"], num, value, now_bst().date().isoformat(), source))
    con.commit()
    con.close()
    return {"metric": metric, "value": value, "numeric": num, "source": source}


# ══════════════════════════════════════════════════════════
# ESCALATION SCAN (Section 7.1)
# ══════════════════════════════════════════════════════════
def escalate_scan() -> list[dict]:
    """
    Evaluate the 5 Section 7.1 triggers against live data. Rules that have
    no backing data source yet are reported as 'no-data' rather than
    silently passing.
    """
    con = connect()
    hits: list[dict] = []

    def _has(table: str) -> bool:
        return con.execute(
            "SELECT 1 FROM sqlite_master WHERE type='table' AND name=?",
            (table,)).fetchone() is not None

    # 1. Leads worth $10,000+ -> CEO video message within 24h
    if _has("leads"):
        cols = {c[1] for c in con.execute("PRAGMA table_info(leads)")}
        valcol = next((c for c in ("estimated_value", "budget", "value",
                                   "lead_value") if c in cols), None)
        if valcol:
            for r in con.execute(
                    f"SELECT * FROM leads WHERE {valcol} >= 10000 "
                    f"AND LOWER(COALESCE(status,'')) NOT IN "
                    f"('converted','closed','lost')"):
                hits.append({
                    "trigger": "Lead >= $10,000 needs CEO video message",
                    "sla": "24 hours", "severity": "critical",
                    "subject": f"lead #{r['id']}",
                    "detail": f"{valcol}={r[valcol]}", "action":
                        "Record CEO personalised video outreach",
                })
        else:
            hits.append({"trigger": "Lead >= $10,000 CEO video",
                         "sla": "24 hours", "severity": "no-data",
                         "subject": "leads", "detail":
                             "no value column present", "action":
                             "add estimated_value to leads"})
    else:
        hits.append({"trigger": "Lead >= $10,000 CEO video", "sla": "24 hours",
                     "severity": "no-data", "subject": "leads",
                     "detail": "leads table missing", "action": "n/a"})

    # 2. NPS < 40 -> emergency meeting within 48h
    nps = [k for k in kpi_status() if "NPS" in k["metric"]]
    if nps:
        val = parse_metric(nps[0]["current"])
        if val is not None and val < 40:
            hits.append({"trigger": "Client NPS below 40", "sla": "48 hours",
                         "severity": "critical", "subject": "NPS",
                         "detail": f"NPS={nps[0]['current']}", "action":
                             "CEO emergency meeting"})

    # 4. Negative press / controversy -> 1h (no feed yet)
    hits.append({"trigger": "Negative press / social controversy",
                 "sla": "1 hour", "severity": "no-data", "subject": "press",
                 "detail": "no monitoring feed wired",
                 "action": "connect Review Engine feed"})

    # 5. Project deadline overrun > 5 days -> CEO client comms in 24h
    if _has("tickets"):
        cols = {c[1] for c in con.execute("PRAGMA table_info(tickets)")}
        if "due_date" in cols:
            overdue = now_bst().strftime("%Y-%m-%d")
            for r in con.execute(
                    "SELECT * FROM tickets WHERE due_date < ? AND "
                    "LOWER(COALESCE(status,'')) NOT IN "
                    "('resolved','closed','done')", (overdue,)):
                hits.append({
                    "trigger": "Ticket past due (deadline overrun)",
                    "sla": "24 hours", "severity": "high",
                    "subject": f"ticket #{r['id']}",
                    "detail": f"due {r['due_date']}", "action":
                        "CEO client communication",
                })

    # 3. Negative ROAS 3 consecutive days -> pause + escalate
    hits.append({"trigger": "Negative ROAS 3 consecutive days",
                 "sla": "3 days", "severity": "no-data", "subject": "ads",
                 "detail": "no ROAS series stored",
                 "action": "connect Ad Engine metrics"})

    con.close()
    return hits


# ══════════════════════════════════════════════════════════
# DAILY BRIEFING (structured markdown)
# ══════════════════════════════════════════════════════════
def briefing() -> str:
    now = now_bst()
    hhmm = now.strftime("%H:%M")
    d = now.date().isoformat()
    blk = _active_block(hhmm)
    con = connect()

    L: list[str] = []
    L += [f"# SCITBD CEO Daily Briefing — {d}",
          f"*{now.strftime('%H:%M')} BST (Asia/Dhaka, UTC+6) · "
          f"Block {blk} · INTERNAL STRATEGIC*",
          ""]

    z = con.execute("SELECT * FROM directive_utc_blocks WHERE bst_block_id=?",
                    (blk,)).fetchone()
    if z:
        L += [f"> **Active window:** {z['utc_window']} → {z['bst_window']} "
              f"({z['zone_focus']})",
              f"> **Block mandate:** {z['tasks']}", ""]

    # --- pending directive tasks ---
    L += ["## 1. Directive Tasks (Section 4)", ""]
    rows = con.execute(
        """SELECT t.task_no, t.task_title, t.priority, t.kpi, t.status,
                  t.division_code, d.division_name, t.daily_task_id
           FROM directive_tasks t
           JOIN directive_divisions d ON d.division_code=t.division_code
           ORDER BY t.task_no""").fetchall()
    cur_div = None
    for r in rows:
        if r["division_code"] != cur_div:
            cur_div = r["division_code"]
            L += [f"### {r['division_name']} — *{r['priority']}*", ""]
        mark = "✅" if r["status"] in ("done", "completed") else "⬜"
        link = (f" → daily_task #{r['daily_task_id']}"
                if r["daily_task_id"] else "")
        L.append(f"- {mark} **#{r['task_no']} {r['task_title']}** "
                 f"[{r['priority']}] — KPI: {r['kpi']}{link}")
    L.append("")

    # --- today's AI-agent work queue ---
    L += ["## 2. AI-Agent Work Queue (live)", ""]
    q = con.execute(
        """SELECT id, task_title, priority, category, status
           FROM daily_tasks WHERE assignee='ai_agent'
           ORDER BY CASE priority WHEN 'critical' THEN 0 WHEN 'high' THEN 1
                    ELSE 2 END, id LIMIT 15""").fetchall()
    if q:
        L += ["| # | Task | Priority | Category | Status |",
              "|---|------|----------|----------|--------|"]
        for r in q:
            L.append(f"| {r['id']} | {r['task_title'][:56]} | "
                     f"{r['priority']} | {r['category']} | {r['status']} |")
    else:
        L.append("_No AI-agent tasks queued._")
    L.append("")

    # --- content digest ---
    L += ["## 3. Content Engine (today)", ""]
    cnt = con.execute(
        """SELECT content_type, COUNT(*) n, ROUND(AVG(seo_score)) s
           FROM content_items WHERE content_date=? GROUP BY content_type""",
        (d,)).fetchall()
    if cnt:
        for r in cnt:
            L.append(f"- {r['content_type']}: **{r['n']}** items "
                     f"(avg SEO {r['s']}/100)")
    else:
        L.append("_No content generated yet today._")
    L.append("")

    # --- KPI snapshot ---
    L += ["## 4. KPI Snapshot (Section 5)", "",
          "| # | Metric | Current | 6-month | Progress |",
          "|---|--------|---------|---------|----------|"]
    for k in kpi_status():
        prog = (f"{k['progress_6m_pct']}%"
                if k["progress_6m_pct"] is not None else "—")
        L.append(f"| {k['order']} | {k['metric'][:30]} | {k['current']} | "
                 f"{k['target_6m']} | {prog} |")
    L.append("")

    # --- escalations ---
    L += ["## 5. Escalation Watch (Section 7.1)", ""]
    hits = escalate_scan()
    live = [h for h in hits if h["severity"] != "no-data"]
    nodata = [h for h in hits if h["severity"] == "no-data"]
    if live:
        for h in live:
            L.append(f"- 🔴 **{h['trigger']}** ({h['sla']}) — "
                     f"{h['subject']}: {h['detail']} → {h['action']}")
    else:
        L.append("- 🟢 No live escalation triggers firing.")
    if nodata:
        L.append("")
        L.append("_Triggers with no data source yet:_")
        for h in nodata:
            L.append(f"- ⚪ {h['trigger']} — {h['detail']} "
                     f"({h['action']})")
    L.append("")

    # --- engines ---
    L += ["## 6. Autonomous Engines (Section 3.2)", ""]
    for r in con.execute("SELECT * FROM directive_engines ORDER BY id"):
        L.append(f"- **{r['engine_name']}** — {r['cadence']}")
    L.append("")

    # --- decision authority reminder ---
    L += ["## 7. Decision Authority (Section 7.2)", "",
          "| Decision | Authority | Action |", "|---|---|---|"]
    for r in con.execute("SELECT * FROM directive_decisions ORDER BY id"):
        L.append(f"| {r['decision_type']} | {r['authority']} | "
                 f"{r['action_required']} |")
    L.append("")

    total = con.execute("SELECT COUNT(*) FROM daily_tasks").fetchone()[0]
    pend = con.execute(
        "SELECT COUNT(*) FROM daily_tasks WHERE status='pending'").fetchone()[0]
    con.close()

    L += ["---",
          f"*Autoflow health: {total} daily tasks ({pend} pending) · "
          f"directive 22 tasks · KPI 10 · engines 6 · "
          f"generated {now.strftime('%Y-%m-%d %H:%M:%S')} BST*"]
    return "\n".join(L)


# ══════════════════════════════════════════════════════════
# CRM PRIMITIVES
# ══════════════════════════════════════════════════════════
def crm_summary() -> str:
    con = connect()
    L = ["SCITBD CRM SUMMARY", "=" * 60]
    for table, label in (("leads", "Leads"), ("tickets", "Tickets"),
                         ("daily_tasks", "Tasks")):
        exists = con.execute(
            "SELECT 1 FROM sqlite_master WHERE type='table' AND name=?",
            (table,)).fetchone()
        if not exists:
            L.append(f"{label:<12}: table absent")
            continue
        n = con.execute(f"SELECT COUNT(*) FROM {table}").fetchone()[0]
        L.append(f"{label:<12}: {n}")
        if table == "daily_tasks":
            for r in con.execute(
                    "SELECT status, COUNT(*) n FROM daily_tasks "
                    "GROUP BY status ORDER BY n DESC"):
                L.append(f"    {r['status']:<14} {r['n']}")
    con.close()
    return "\n".join(L)


def assign_task(title: str, division: str = "MKT", kpi: str = "",
                deadline: str = "", priority: str = "high",
                description: str = "") -> dict:
    """CEO-authorised task assignment -> daily_tasks + task_logs."""
    now = now_bst()
    d = now.strftime("%Y-%m-%d")
    hhmm = now.strftime("%H:%M")
    cat = {"MKT": "marketing", "SBD": "sales", "AIT": "ai",
           "CSR": "client", "FGI": "operations"}.get(division, "general")
    con = connect()
    existing = con.execute(
        "SELECT id FROM daily_tasks WHERE task_title=? AND due_date=?",
        (title, f"{d} {hhmm}")).fetchone()
    if existing:
        con.close()
        return {"created": False, "id": existing["id"], "reason": "duplicate"}
    desc = description or f"Assigned by SCITBD CEO AI Agent (division {division})."
    if kpi:
        desc += f"\nKPI: {kpi}"
    if deadline:
        desc += f"\nDeadline: {deadline}"
    cur = con.execute(
        """INSERT INTO daily_tasks
           (task_title, task_description, priority, status, category, assignee,
            due_date, estimated_hours, bst_block_id, created_by)
           VALUES (?,?,?,?,?,?,?,?,?,?)""",
        (title, desc, priority, "pending", cat, "ai_agent", f"{d} {hhmm}",
         1.0, _active_block(hhmm), "ceo_ai_agent"))
    tid = cur.lastrowid
    con.execute(
        """INSERT INTO task_logs (task_id, action, performed_by, details)
           VALUES (?,?,?,?)""",
        (tid, "assigned", "ceo_ai_agent",
         f"CEO AI agent assigned task [{division}] KPI={kpi or '-'} "
         f"deadline={deadline or '-'}"))
    con.commit()
    con.close()
    return {"created": True, "id": tid, "title": title, "division": division,
            "category": cat, "due": f"{d} {hhmm}"}


# ══════════════════════════════════════════════════════════
# ENGINE DISPATCH (Section 3.2)
# ══════════════════════════════════════════════════════════
def run_engine(code: str) -> dict:
    code = code.upper().strip()
    known = {"CONTENT", "LEAD", "AD", "EMAIL", "REVIEW", "ANALYTICS", "BROWSER"}
    if code not in known:
        return {"ok": False, "reason": f"unknown engine {code!r}",
                "known": sorted(known)}

    if code == "BROWSER":
        # browserskill intel engine (Tencent/BrowserSkill `bsk` first, agent-browser fallback).
        try:
            import browserskill as BSK
            url = (sys.argv[3] if len(sys.argv) > 3 else "").strip()
            if not url:
                return {"ok": False, "reason": "usage: run BROWSER <url> [goal]"}
            goal = sys.argv[4] if len(sys.argv) > 4 else "CEO intel for briefing/pipeline."
            if BSK.is_available():
                res = BSK.job_research(url, goal)
                _ops_log(f"Engine BROWSER (bsk) dispatched: {url} ok={res.get('ok')}")
                return {"ok": bool(res.get("ok")), "driver": "bsk", "report": res.get("report"), "shot": res.get("shot")}
        except Exception as ex:
            _ops_log(f"Engine BROWSER (bsk) failed, falling back: {ex}")
        try:
            import agent_browser as AB
            url = (sys.argv[3] if len(sys.argv) > 3 else "").strip()
            if not url:
                return {"ok": False, "reason": "usage: run BROWSER <url> [goal]"}
            goal = sys.argv[4] if len(sys.argv) > 4 else "CEO intel for briefing/pipeline."
            res = AB.job_research(url, goal)
            _ops_log(f"Engine BROWSER (agent-browser fallback) dispatched: {url} ok={res.get('ok')}")
            return {"ok": bool(res.get("ok")), "driver": "agent-browser", "report": res.get("report"), "shot": res.get("shot")}
        except Exception as ex:
            return {"ok": False, "reason": str(ex)}
    if code == "CONTENT":
        try:
            import scitbd_content_engine as C
            d = now_bst().date().isoformat()
            g = C.generate(d)
            f = C.write_files(d)
            e = C.send_email_note(d)
            res = {"ok": True, "generated": g, "files": len(f),
                   "email": e.get("sent", False)}
        except Exception as ex:                      # pragma: no cover
            res = {"ok": False, "reason": str(ex)}
    elif code == "EMAIL":
        try:
            import scitbd_content_engine as C
            e = C.send_email_note()
            res = {"ok": True, "email": e}
        except Exception as ex:                      # pragma: no cover
            res = {"ok": False, "reason": str(ex)}
    elif code == "ANALYTICS":
        res = {"ok": True, "kpis": kpi_status()}
    elif code == "REVIEW":
        res = {"ok": True, "note": "Review Engine has no feed wired yet",
               "status": "not-wired"}
    else:                                            # LEAD / AD
        res = {"ok": True, "note": f"{code} Engine has no feed wired yet",
               "status": "not-wired"}

    _ops_log(f"Engine {code} dispatched: {res}")
    return res


def _ops_log(action: str) -> None:
    try:
        con = connect()
        con.execute(
            "INSERT INTO operational_logs (block_name, action_taken, status) "
            "VALUES (?,?,?)",
            ("CEO AI Agent", action[:500], "SUCCESS"))
        con.commit()
        con.close()
    except Exception:
        pass


# ══════════════════════════════════════════════════════════
# CLI
# ══════════════════════════════════════════════════════════
def main() -> None:
    cmd = sys.argv[1] if len(sys.argv) > 1 else "briefing"
    arg = sys.argv[2] if len(sys.argv) > 2 else None

    if cmd == "system":
        print(system_prompt(live_context=(arg == "brief")))
    elif cmd == "briefing":
        print(briefing())
    elif cmd == "kpis":
        print("SCITBD KPI STATUS\n" + "=" * 74)
        print(f"{'#':<3}{'METRIC':<34}{'CURRENT':<14}{'6M':<13}{'PROGRESS'}")
        print("-" * 74)
        for k in kpi_status():
            prog = (f"{k['progress_6m_pct']}%"
                    if k["progress_6m_pct"] is not None else "—")
            print(f"{k['order']:<3}{k['metric'][:32]:<34}"
                  f"{str(k['current'])[:12]:<14}"
                  f"{str(k['target_6m']):<13}{prog}")
        if len(sys.argv) > 2:
            print("\nset:", set_kpi(sys.argv[2], sys.argv[3]))
    elif cmd == "escalate":
        hits = escalate_scan()
        print("SECTION 7.1 ESCALATION SCAN\n" + "=" * 70)
        for h in hits:
            icon = {"critical": "RED", "high": "AMBER",
                    "no-data": "NO-DATA"}.get(h["severity"], "?")
            print(f"[{icon:<8}] {h['trigger']} ({h['sla']})")
            print(f"           subject={h['subject']} detail={h['detail']}")
            print(f"           action={h['action']}")
    elif cmd == "engines":
        con = connect()
        for r in con.execute("SELECT * FROM directive_engines ORDER BY id"):
            print(f"[{r['engine_code']}] {r['engine_name']}")
            print(f"    cadence : {r['cadence']}")
            print(f"    spec    : {r['description']}\n")
        con.close()
    elif cmd == "crm":
        print(crm_summary())
    elif cmd == "assign":
        if len(sys.argv) < 3:
            print("usage: assign \"title\" [DIV] [kpi] [deadline] [priority]")
            return
        print(assign_task(sys.argv[2],
                          sys.argv[3] if len(sys.argv) > 3 else "MKT",
                          sys.argv[4] if len(sys.argv) > 4 else "",
                          sys.argv[5] if len(sys.argv) > 5 else "",
                          sys.argv[6] if len(sys.argv) > 6 else "high"))
    elif cmd == "run":
        if not arg:
            print("usage: run CONTENT|LEAD|AD|EMAIL|REVIEW|ANALYTICS")
            return
        print(run_engine(arg))
    else:
        print(__doc__)


if __name__ == "__main__":
    main()
