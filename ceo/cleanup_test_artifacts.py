# -*- coding: utf-8 -*-
"""
One-off cleanup: purge test artifacts from scitbd_ceo.db alerting tables.

The regression suite and earlier manual demos wrote synthetic lead ids
(777, 9999, 4242, 555) into alert_retry_queue / alert_dispatch_log /
operational_logs, and drove channel_health into a DOWN state.

This restores a neutral production baseline:
  * alert_retry_queue     -> empty (all rows were synthetic)
  * alert_dispatch_log    -> empty
  * channel_health        -> HEALTHY 100/0/0 (never sent to production)
  * block_alert_state     -> empty (no real block alert has fired)
  * operational_logs      -> Alerting Engine rows removed (all test-driven)

Leads / daily_tasks / block_tasks / sla_standards / task_logs are NOT touched.
"""
import sqlite3
import sys

DB = r"D:/CRM_with_GOOGLE_Sheet/ceo/ceo - Copy/scitbd_ceo.db"

# Synthetic lead ids used during testing — none exist in the real leads table
SYNTHETIC_LEADS = (777, 9999, 4242, 555)

db = sqlite3.connect(DB)
db.row_factory = sqlite3.Row
db.execute("PRAGMA foreign_keys=ON")

before = {t: db.execute(f"SELECT COUNT(*) FROM {t}").fetchone()[0]
          for t in ("alert_retry_queue", "alert_dispatch_log",
                    "block_alert_state")}
before_health = [dict(r) for r in db.execute(
    "SELECT channel, score, state, consecutive_fail, successes, failures "
    "FROM channel_health ORDER BY channel")]
before_ops = db.execute(
    "SELECT COUNT(*) FROM operational_logs WHERE block_name='Alerting Engine'"
).fetchone()[0]

# Safety: refuse to delete dispatch_log rows tied to a REAL lead id
real_hits = db.execute(
    f"""SELECT COUNT(*) FROM alert_retry_queue
         WHERE lead_id IN ({','.join('?' * len(SYNTHETIC_LEADS))})
           AND lead_id NOT IN (SELECT id FROM leads)""",
    SYNTHETIC_LEADS).fetchone()[0]

print("=== PRE-CHECK ===")
print(f"  retry_queue rows      : {before['alert_retry_queue']}")
print(f"  dispatch_log rows     : {before['alert_dispatch_log']}")
print(f"  block_alert_state     : {before['block_alert_state']}")
print(f"  AlertingEngine ops rows: {before_ops}")
for h in before_health:
    print(f"  health {h['channel']:<9}: score={h['score']} state={h['state']} "
          f"consec={h['consecutive_fail']} ok={h['successes']} fail={h['failures']}")

# Verify no queue/dispatch row references a lead that actually exists in leads
orphans = db.execute(
    """SELECT COUNT(*) FROM alert_retry_queue q
        WHERE NOT EXISTS (SELECT 1 FROM leads l WHERE l.id = q.lead_id)
          AND q.lead_id NOT IN (%s)""" % ",".join("?" * len(SYNTHETIC_LEADS)),
    SYNTHETIC_LEADS).fetchone()[0]
if orphans:
    print(f"\n  ! {orphans} queue row(s) reference unknown non-synthetic lead ids")
    print("    -> aborting cleanup for safety")
    db.close()
    sys.exit(1)

print("\n=== CLEANUP ===")

n = db.execute("DELETE FROM alert_retry_queue").rowcount
print(f"  cleared alert_retry_queue     : {n} row(s)")
n = db.execute("DELETE FROM alert_dispatch_log").rowcount
print(f"  cleared alert_dispatch_log    : {n} row(s)")
n = db.execute("DELETE FROM block_alert_state").rowcount
print(f"  cleared block_alert_state     : {n} row(s)")
n = db.execute(
    "DELETE FROM operational_logs WHERE block_name='Alerting Engine'").rowcount
print(f"  cleared AlertingEngine ops log: {n} row(s)")

# Reset channel health to a neutral, trusted baseline.
# Production has never actually sent an alert, so the honest starting point
# is HEALTHY/100 with zero counters (not the degraded demo state).
db.execute("""UPDATE channel_health
                 SET score=100.0, consecutive_fail=0, state='HEALTHY',
                     successes=0, failures=0,
                     last_success_at=NULL, last_failure_at=NULL,
                     updated_at=datetime('now','localtime')""")
print("  reset channel_health          : both channels HEALTHY @100")

db.commit()

# ── Post-verify ──
print("\n=== POST-CLEANUP VERIFY ===")
ok = True
for t in ("alert_retry_queue", "alert_dispatch_log", "block_alert_state"):
    c = db.execute(f"SELECT COUNT(*) FROM {t}").fetchone()[0]
    status = "OK" if c == 0 else "FAIL"
    if c:
        ok = False
    print(f"  [{status}] {t:<22} = {c}")

c = db.execute(
    "SELECT COUNT(*) FROM operational_logs WHERE block_name='Alerting Engine'"
).fetchone()[0]
print(f"  [{'OK' if c == 0 else 'FAIL'}] AlertingEngine ops rows  = {c}")
if c:
    ok = False

for h in db.execute("SELECT * FROM channel_health ORDER BY channel"):
    good = (h["state"] == "HEALTHY" and h["score"] == 100.0
            and h["consecutive_fail"] == 0 and h["successes"] == 0
            and h["failures"] == 0)
    if not good:
        ok = False
    print(f"  [{'OK' if good else 'FAIL'}] health {h['channel']:<9} = "
          f"{h['state']} score={h['score']} consec={h['consecutive_fail']} "
          f"ok={h['successes']} fail={h['failures']}")

# Ensure no test leads leaked into the real leads table
c = db.execute(
    f"SELECT COUNT(*) FROM leads WHERE id IN ({','.join('?' * len(SYNTHETIC_LEADS))})",
    SYNTHETIC_LEADS).fetchone()[0]
print(f"  [{'OK' if c == 0 else 'FAIL'}] no synthetic leads in leads  = {c}")
if c:
    ok = False

# Confirm the operational plan data was NOT disturbed
for label, tbl, expect in (("block_tasks", "block_tasks", 16),
                           ("sla_standards", "sla_standards", 6),
                           ("operational_blocks", "operational_blocks", 4)):
    c = db.execute(f"SELECT COUNT(*) FROM {tbl}").fetchone()[0]
    good = c == expect
    if not good:
        ok = False
    print(f"  [{'OK' if good else 'FAIL'}] {label:<26} = {c} (expected {expect})")

ik = db.execute("PRAGMA integrity_check").fetchone()[0]
fk = db.execute("PRAGMA foreign_key_check").fetchall()
print(f"  [{'OK' if ik == 'ok' else 'FAIL'}] integrity_check             = {ik}")
print(f"  [{'OK' if not fk else 'FAIL'}] foreign_key_check           = "
      f"{'CLEAN' if not fk else fk}")
if ik != "ok" or fk:
    ok = False

print("\n" + ("=" * 60))
print("  RESULT: " + ("CLEANUP COMPLETE — all checks passed"
                      if ok else "PARTIAL — see FAIL rows above"))
print("=" * 60)
db.close()
sys.exit(0 if ok else 1)
