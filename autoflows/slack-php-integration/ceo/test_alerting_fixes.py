# -*- coding: utf-8 -*-
"""
Regression tests for the three alerting-stack fixes:
  Fix 1 — canary() must move a DOWN channel into DEGRADED (not stay DOWN)
  Fix 2 — a job that burns all attempts must reach EXHAUSTED + Slack ping
          + operational_logs row
  Fix 3 — stale IN_FLIGHT rows are reaped back to PENDING by claim_due()

Run:  python test_alerting_fixes.py
All tests run against an isolated temp copy of the DB (original untouched).
"""
from __future__ import annotations

import datetime
import json
import os
import shutil
import sqlite3
import sys
import tempfile
import zoneinfo

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import scitbd_alerting as A  # noqa: E402

BST = zoneinfo.ZoneInfo("Asia/Dhaka")
RESULTS: list[tuple[str, bool, str]] = []


def check(name: str, cond: bool, detail: str = "") -> None:
    RESULTS.append((name, bool(cond), detail))
    print(f"  [{'PASS' if cond else 'FAIL'}] {name}" + (f" — {detail}" if detail else ""))


def fresh_db() -> tuple[sqlite3.Connection, str]:
    """Copy the real DB to a temp file, install schema, return connection."""
    tmp = os.path.join(tempfile.mkdtemp(prefix="scitbd_test_"), "test.db")
    shutil.copy(A.DB_PATH, tmp)
    con = sqlite3.connect(tmp)
    con.row_factory = sqlite3.Row
    con.execute("PRAGMA foreign_keys=ON")
    con.executescript(A.SCHEMA)
    for ch in A.CHANNELS:
        con.execute("INSERT OR IGNORE INTO channel_health (channel) VALUES (?)", (ch,))
    con.execute("DELETE FROM alert_retry_queue")
    con.execute("DELETE FROM alert_dispatch_log")
    con.commit()
    return con, tmp


# ──────────────────────────────────────────────────────────
# Fix 1: canary recovery
# ──────────────────────────────────────────────────────────
def test_canary_recovery() -> None:
    print("\nFix 1 — canary recovers DOWN channel to DEGRADED")
    con, tmp = fresh_db()
    try:
        # Drive both channels to DOWN with a low score
        con.execute("""UPDATE channel_health
                          SET score=25, consecutive_fail=4, state='DOWN'""")
        con.commit()
        before = {r["channel"]: dict(r) for r in con.execute("SELECT * FROM channel_health")}

        # Canary succeeds -> must leave DOWN
        real = A.SENDERS
        A.SENDERS = {c: (lambda p: (True, "canary ok")) for c in A.CHANNELS}
        try:
            msg = A.canary(con)
        finally:
            A.SENDERS = real

        after = {r["channel"]: dict(r) for r in con.execute("SELECT * FROM channel_health")}

        for ch in A.CHANNELS:
            check(f"{ch} left DOWN", after[ch]["state"] != "DOWN",
                  f"{before[ch]['state']} -> {after[ch]['state']}")
            check(f"{ch} state == DEGRADED (probation)",
                  after[ch]["state"] == "DEGRADED", after[ch]["state"])
            check(f"{ch} consecutive_fail reset to 0",
                  after[ch]["consecutive_fail"] == 0)
            check(f"{ch} score raised above DOWN threshold",
                  after[ch]["score"] >= A.STATE_DOWN_THRESHOLD,
                  f"{before[ch]['score']} -> {after[ch]['score']}")
            # score/state consistency: derive must reproduce stored state
            derived = A._derive_state(after[ch]["score"], after[ch]["consecutive_fail"])
            check(f"{ch} state consistent with score", derived == after[ch]["state"],
                  f"derive={derived} stored={after[ch]['state']}")

        # Second canary success must NOT jump to HEALTHY (still probation)
        A.SENDERS = {c: (lambda p: (True, "canary ok")) for c in A.CHANNELS}
        try:
            A.canary(con)
        finally:
            A.SENDERS = real
        second = {r["channel"]: dict(r) for r in con.execute("SELECT * FROM channel_health")}
        for ch in A.CHANNELS:
            check(f"{ch} stays DEGRADED after 2nd canary (no premature HEALTHY)",
                  second[ch]["state"] == "DEGRADED", second[ch]["state"])

        # A genuine success must eventually promote to HEALTHY.
        # Canary leaves the channel in probation (DEGRADED, score 50). Each real
        # success adds +HEALTH_SUCCESS_BONUS (5), so graduation needs several
        # sends — deliberately NOT a one-shot bypass of the health gate.
        # Assert: (a) it does not graduate on a single success,
        #         (b) it does graduate after a bounded number of successes.
        graduated = {}
        for ch in A.CHANNELS:
            A.record_success(con, ch)           # first real success
            row = con.execute("SELECT state FROM channel_health WHERE channel=?",
                              (ch,)).fetchone()
            check(f"{ch} NOT HEALTHY on 1st success (probation holds)",
                  row["state"] != "HEALTHY", row["state"])

            for _ in range(30):                 # bounded graduation loop
                if row["state"] == "HEALTHY":
                    break
                A.record_success(con, ch)
                row = con.execute("SELECT state FROM channel_health WHERE channel=?",
                                  (ch,)).fetchone()
            graduated[ch] = row["state"]
        con.commit()
        for ch in A.CHANNELS:
            check(f"{ch} -> HEALTHY after repeated real success",
                  graduated[ch] == "HEALTHY", graduated[ch])
    finally:
        con.close()
        shutil.rmtree(os.path.dirname(tmp), ignore_errors=True)


# ──────────────────────────────────────────────────────────
# Fix 2: exhaustion -> EXHAUSTED + Slack ping + ops log
# ──────────────────────────────────────────────────────────
def test_exhaustion() -> None:
    print("\nFix 2 — exhaustion escalates (EXHAUSTED + Slack + ops log)")
    con, tmp = fresh_db()
    slack_calls: list[dict] = []
    real_slack, real_senders = A.send_slack, A.SENDERS
    A.send_slack = lambda url, text, color="#36a64f", fields=None, buttons=None: (
        slack_calls.append({"url": url, "text": text, "color": color,
                            "fields": fields}), (True, "mock"))[1]
    A.SENDERS = {c: (lambda p: (False, "permanent outage")) for c in A.CHANNELS}
    try:
        # Isolate from Fix 1: reset channel health so both channels are
        # HEALTHY and thus BOTH get attempted (and enqueued) by the dispatcher.
        # Without this, whatsapp arrives still DOWN from test_canary_recovery
        # and is skipped, leaving only 1 queued job.
        con.execute("""UPDATE channel_health
                          SET score=100.0, consecutive_fail=0, state='HEALTHY',
                              successes=0, failures=0,
                              last_success_at=NULL, last_failure_at=NULL""")
        con.commit()

        payload = {"lead_id": 4242, "client_name": "Exhaust Co",
                   "company_name": "ExCo", "email": "c@ex.co", "phone": "+1",
                   "country": "USA", "deal_value": 25000.0,
                   "service_line": "AI Platform", "campaign_source": "test"}

        # Initial dispatch fails on both channels -> 2 queued jobs
        res = A.dispatch_high_value_lead(payload, con)
        check("dispatch delivered nothing", res["delivered_via"] is None)
        queued = con.execute("SELECT COUNT(*) c FROM alert_retry_queue").fetchone()["c"]
        check("both channels enqueued for retry", queued == 2, f"queued={queued}")

        # Force all jobs due now
        con.execute("""UPDATE alert_retry_queue
                          SET next_retry_at=datetime('now','localtime','-1 minute')""")
        con.commit()

        cycles = 0
        while cycles < 10:
            health = {r["channel"]: r["state"]
                      for r in con.execute("SELECT channel, state FROM channel_health")}
            # Keep channels attemptable so retries actually run
            con.execute("""UPDATE channel_health
                              SET state='DEGRADED', score=60, consecutive_fail=1""")
            con.commit()
            A.retry_worker(con)
            cycles += 1
            n = con.execute(
                "SELECT COUNT(*) c FROM alert_retry_queue WHERE status='EXHAUSTED'"
            ).fetchone()["c"]
            if n:
                break
            con.execute("""UPDATE alert_retry_queue
                              SET next_retry_at=datetime('now','localtime','-1 minute')
                            WHERE status='PENDING'""")
            con.commit()

        exh = con.execute(
            """SELECT * FROM alert_retry_queue WHERE status='EXHAUSTED'"""
        ).fetchall()
        check("at least one job reached EXHAUSTED", len(exh) >= 1,
              f"after {cycles} cycle(s), exhausted={len(exh)}")
        if exh:
            check("EXHAUSTED job hit max_attempts",
                  all(r["attempts"] >= r["max_attempts"] for r in exh),
                  ", ".join(f"{r['channel']}={r['attempts']}/{r['max_attempts']}"
                            for r in exh))
            check("EXHAUSTED job preserves last_error",
                  all(bool(r["last_error"]) for r in exh))

        # Slack health ping fired
        crit = [c for c in slack_calls if "EXHAUSTED" in c["text"]]
        check("Slack health ping sent on exhaustion", len(crit) >= 1,
              f"crit_calls={len(crit)}")
        if crit:
            check("ping uses critical color", crit[0]["color"] == "#ff0055",
                  crit[0]["color"])
            check("ping names the lead", "4242" in crit[0]["text"])

        # dispatch_log + operational_logs rows written
        dlog = con.execute(
            "SELECT COUNT(*) c FROM alert_dispatch_log WHERE outcome='EXHAUSTED'"
        ).fetchone()["c"]
        check("alert_dispatch_log has EXHAUSTED row", dlog >= 1, f"rows={dlog}")

        olog = con.execute(
            """SELECT COUNT(*) c FROM operational_logs
                WHERE block_name='Alerting Engine' AND action_taken LIKE 'EXHAUSTED%'"""
        ).fetchone()["c"]
        check("operational_logs has EXHAUSTED row", olog >= 1, f"rows={olog}")

        # No job left stranded in IN_FLIGHT
        stuck = con.execute(
            "SELECT COUNT(*) c FROM alert_retry_queue WHERE status='IN_FLIGHT'"
        ).fetchone()["c"]
        check("no job stranded IN_FLIGHT", stuck == 0, f"stuck={stuck}")
    finally:
        A.send_slack, A.SENDERS = real_slack, real_senders
        con.close()
        shutil.rmtree(os.path.dirname(tmp), ignore_errors=True)


# ──────────────────────────────────────────────────────────
# Fix 3: reaper recovers stale IN_FLIGHT
# ──────────────────────────────────────────────────────────
def test_reaper() -> None:
    print("\nFix 3 — stale IN_FLIGHT rows are reaped")
    con, tmp = fresh_db()
    try:
        payload = {"lead_id": 555, "client_name": "Stale", "company_name": "S",
                   "email": "s@s.io", "phone": "+1", "country": "USA",
                   "deal_value": 11000.0, "service_line": "ERP",
                   "campaign_source": "test"}

        # Simulate a worker crash: row flipped to IN_FLIGHT, then abandoned
        jid = A.enqueue_retry(con, 555, "whatsapp", payload, "seeded")
        con.execute("""UPDATE alert_retry_queue
                          SET status='IN_FLIGHT',
                              updated_at=datetime('now','localtime','-30 minutes')
                        WHERE id=?""", (jid,))
        # A second, healthy (recent) IN_FLIGHT row must NOT be reaped
        jid2 = A.enqueue_retry(con, 555, "sms", payload, "seeded")
        con.execute("""UPDATE alert_retry_queue
                          SET status='IN_FLIGHT',
                              updated_at=datetime('now','localtime')
                        WHERE id=?""", (jid2,))
        # A PENDING row due now should be claimable after reaping
        con.execute("""UPDATE alert_retry_queue
                          SET status='PENDING',
                              next_retry_at=datetime('now','localtime','-1 minute'),
                              updated_at=datetime('now','localtime','-5 minutes')
                        WHERE id=?""", (jid2,))
        con.commit()

        reaped = A.reap_stale_inflight(con, stale_minutes=10)
        check("reaped exactly the stale row", len(reaped) == 1,
              f"reaped={[r['id'] for r in reaped]}")
        check("reaped the correct row", bool(reaped) and reaped[0]["id"] == jid)

        row = con.execute("SELECT * FROM alert_retry_queue WHERE id=?", (jid,)).fetchone()
        check("stale row reset to PENDING", row["status"] == "PENDING", row["status"])
        check("attempt count preserved", row["attempts"] == 0, str(row["attempts"]))
        check("last_error annotated as reaped",
              "reaped: stale IN_FLIGHT" in (row["last_error"] or ""),
              row["last_error"])

        rlog = con.execute(
            "SELECT COUNT(*) c FROM alert_dispatch_log WHERE outcome='REAPED'"
        ).fetchone()["c"]
        check("REAPED audit row written", rlog == 1, f"rows={rlog}")

        # claim_due() should now reap + claim in one call
        con.execute("""UPDATE alert_retry_queue
                          SET status='IN_FLIGHT',
                              updated_at=datetime('now','localtime','-20 minutes'),
                              next_retry_at=datetime('now','localtime','-1 minute')
                        WHERE id=?""", (jid,))
        con.commit()
        jobs = A.claim_due(con, stale_minutes=10)
        ids = [j["id"] for j in jobs]
        check("claim_due recovers the stale row", jid in ids, f"claimed={ids}")

        # Fresh IN_FLIGHT (< stale threshold) must be left alone by reaper alone
        con.execute("DELETE FROM alert_retry_queue")
        jid3 = A.enqueue_retry(con, 777, "sms", payload, "fresh")
        con.execute("""UPDATE alert_retry_queue
                          SET status='IN_FLIGHT',
                              updated_at=datetime('now','localtime')
                        WHERE id=?""", (jid3,))
        con.commit()
        r2 = A.reap_stale_inflight(con, stale_minutes=10)
        check("fresh IN_FLIGHT not reaped", len(r2) == 0, f"reaped={len(r2)}")
        still = con.execute("SELECT status FROM alert_retry_queue WHERE id=?",
                            (jid3,)).fetchone()["status"]
        check("fresh row still IN_FLIGHT", still == "IN_FLIGHT", still)
    finally:
        con.close()
        shutil.rmtree(os.path.dirname(tmp), ignore_errors=True)


if __name__ == "__main__":
    A.DRY_RUN = True   # never touch the network during tests
    print("=" * 70)
    print("  SCITBD ALERTING — FIX REGRESSION SUITE")
    print("=" * 70)
    test_canary_recovery()
    test_exhaustion()
    test_reaper()

    passed = sum(1 for _, ok, _ in RESULTS if ok)
    failed = [(n, d) for n, ok, d in RESULTS if not ok]
    print("\n" + "=" * 70)
    print(f"  RESULT: {passed}/{len(RESULTS)} passed, {len(failed)} failed")
    if failed:
        print("  FAILURES:")
        for n, d in failed:
            print(f"    - {n}: {d}")
    print("=" * 70)
    sys.exit(1 if failed else 0)
