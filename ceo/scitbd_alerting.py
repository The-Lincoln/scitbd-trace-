# -*- coding: utf-8 -*-
"""
SCITBD AI CEO — Alerting Stack (Slack / WhatsApp / Twilio SMS)
==============================================================
Implements against scitbd_ceo.db:

  * 15-minute pre-block operational alerts (Slack)
  * $10,000+ high-value lead dual-channel escalation
  * Twilio SMS fallback when WhatsApp delivery fails
  * Persistent retry queue with exponential backoff (60s / 5m / 15m)
  * Intelligent fallback re-ordering driven by channel health scores
  * Canary probes that auto-recover channels marked DOWN

Timezone: Asia/Dhaka (BST / UTC+6).

CLI:
    python scitbd_alerting.py install       # create tables + seed block alerts
    python scitbd_alerting.py health        # channel health dashboard
    python scitbd_alerting.py precheck      # fire 15-min pre-block alert if due
    python scitbd_alerting.py retry         # process due retries (worker)
    python scitbd_alerting.py canary        # probe DOWN channels
    python scitbd_alerting.py testlead      # dispatch a fake $10k lead
    python scitbd_alerting.py demo          # dry-run full pipeline (no network)
"""
from __future__ import annotations

import datetime
import json
import os
import sqlite3
import sys
import urllib.error
import urllib.parse
import urllib.request
import zoneinfo

DB_PATH = r"D:/CRM_with_GOOGLE_Sheet/ceo/ceo - Copy/scitbd_ceo.db"
BST = zoneinfo.ZoneInfo("Asia/Dhaka")

try:
    sys.stdout.reconfigure(encoding="utf-8", errors="replace")
    sys.stderr.reconfigure(encoding="utf-8", errors="replace")
except Exception:
    pass

# ── Credentials (override via environment; never commit real values) ──
SLACK_BLOCK_WEBHOOK = os.environ.get(
    "SCITBD_SLACK_BLOCK_WEBHOOK",
    "https://hooks.slack.com/services/YOUR/WEBHOOK/URL")
SLACK_LEAD_WEBHOOK = os.environ.get(
    "SCITBD_SLACK_LEAD_WEBHOOK",
    "https://hooks.slack.com/services/YOUR/10K_LEAD/WEBHOOK")
SLACK_HEALTH_WEBHOOK = os.environ.get(
    "SCITBD_SLACK_HEALTH_WEBHOOK",
    "https://hooks.slack.com/services/YOUR/HEALTH/WEBHOOK")

ULTRAMSG_INSTANCE = os.environ.get("SCITBD_ULTRAMSG_INSTANCE", "instanceXXXXX")
ULTRAMSG_TOKEN = os.environ.get("SCITBD_ULTRAMSG_TOKEN", "YOUR_ULTRAMSG_TOKEN")

TWILIO_SID = os.environ.get("SCITBD_TWILIO_SID", "ACxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx")
TWILIO_TOKEN = os.environ.get("SCITBD_TWILIO_TOKEN", "YOUR_TWILIO_AUTH_TOKEN")
TWILIO_FROM = os.environ.get("SCITBD_TWILIO_FROM", "+15005550006")

FOUNDER_MOBILE = os.environ.get("SCITBD_FOUNDER_MOBILE", "+8801559575338")
CRM_URL = os.environ.get("SCITBD_CRM_URL", "https://scit.zya.me/")

# True = never hit the network; log payloads instead (safe default)
DRY_RUN = os.environ.get("SCITBD_ALERT_DRYRUN", "1") == "1"

HTTP_TIMEOUT = 5

# ── Exponential backoff schedule (seconds) ──
RETRY_BACKOFF = [60, 300, 900]        # 1 min, 5 min, 15 min
RETRY_MAX_ATTEMPTS = len(RETRY_BACKOFF) + 1   # 4 total tries

# ── Channel health scoring ──
HEALTH_SUCCESS_BONUS = 5.0
HEALTH_FAILURE_PENALTY = 12.0
HEALTH_SCORE_FLOOR = 0.0
HEALTH_SCORE_CEILING = 100.0
STATE_DEGRADED_THRESHOLD = 80.0
STATE_DOWN_THRESHOLD = 50.0
STATE_DOWN_CONSEC_FAIL = 3

CHANNELS = ("whatsapp", "sms")


# ══════════════════════════════════════════════════════════
# Connection helpers
# ══════════════════════════════════════════════════════════
def connect(db_path: str = DB_PATH) -> sqlite3.Connection:
    con = sqlite3.connect(db_path)
    con.row_factory = sqlite3.Row
    con.execute("PRAGMA foreign_keys=ON")
    return con


def now_bst() -> datetime.datetime:
    return datetime.datetime.now(BST)


# ══════════════════════════════════════════════════════════
# Schema
# ══════════════════════════════════════════════════════════
SCHEMA = """
CREATE TABLE IF NOT EXISTS channel_health (
    channel          TEXT PRIMARY KEY,
    successes        INTEGER NOT NULL DEFAULT 0,
    failures         INTEGER NOT NULL DEFAULT 0,
    consecutive_fail INTEGER NOT NULL DEFAULT 0,
    score            REAL    NOT NULL DEFAULT 100.0,
    state            TEXT    NOT NULL DEFAULT 'HEALTHY',
    last_success_at  TEXT,
    last_failure_at  TEXT,
    updated_at       TEXT DEFAULT (datetime('now','localtime'))
);

CREATE TABLE IF NOT EXISTS alert_retry_queue (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    lead_id       INTEGER NOT NULL,
    channel       TEXT NOT NULL,
    payload_json  TEXT NOT NULL,
    attempts      INTEGER NOT NULL DEFAULT 0,
    max_attempts  INTEGER NOT NULL DEFAULT 4,
    next_retry_at TEXT NOT NULL,
    status        TEXT NOT NULL DEFAULT 'PENDING',
    last_error    TEXT,
    created_at    TEXT DEFAULT (datetime('now','localtime')),
    updated_at    TEXT DEFAULT (datetime('now','localtime'))
);
CREATE INDEX IF NOT EXISTS idx_retry_due ON alert_retry_queue(status, next_retry_at);

CREATE TABLE IF NOT EXISTS alert_dispatch_log (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    lead_id     INTEGER,
    channel     TEXT,
    outcome     TEXT,
    detail      TEXT,
    attempt     INTEGER DEFAULT 1,
    created_at  TEXT DEFAULT (datetime('now','localtime'))
);

CREATE TABLE IF NOT EXISTS block_alert_state (
    block_id     INTEGER PRIMARY KEY,
    last_fired   TEXT,
    last_slot    TEXT
);
"""

# 15-minute pre-block alert definitions (alert HH:MM -> block meta)
BLOCK_ALERTS = {
    "05:45": {
        "block": 1, "color": "#f1c40f",
        "title": "🚨 15-MIN ALERT: Block 1 starts at 06:00 BST",
        "region": "🌏 South Asia & Domestic Market",
        "tasks": [
            "SEO & Morning Social Media Post Deployment",
            "e-GP & Regional Procurement RFP Scrapers",
            "Lead Nurturing & Pipeline CRM Updates",
            "SLA & Brand Review Queue Audits",
        ],
    },
    "11:45": {
        "block": 2, "color": "#3498db",
        "title": "🚨 15-MIN ALERT: Block 2 starts at 12:00 BST",
        "region": "🌍 Middle East (UAE/KSA) & Europe",
        "tasks": [
            "Ad Performance Audit (ROAS Check)",
            "B2B Sales Prospecting (10-12 CTOs/CIOs)",
            "EU Content & Gated Case Study Distribution",
            "Retainer & Upsell Sequence Dispatch",
        ],
    },
    "17:45": {
        "block": 3, "color": "#e74c3c",
        "title": "🚨 15-MIN ALERT: Block 3 starts at 18:00 BST",
        "region": "🌎 UK & North America Peak Launch",
        "tasks": [
            "US Market Ad Campaign Launches",
            "Sub-2h Proposal Sprint ($10k+ CEO Video Escalations)",
            "North America SEO & Short Video Deployment",
            "Support Ticket SLA & 99.9% Uptime Audit",
        ],
    },
    "23:45": {
        "block": 4, "color": "#9b59b6",
        "title": "🚨 15-MIN ALERT: Block 4 starts at 00:00 BST",
        "region": "🇦🇺 Oceania & Global System Reboot",
        "tasks": [
            "Multi-Channel Ad Campaign Budget Reallocations",
            "Oceania B2B Outreach & Tender Scans",
            "Daily CEO Intelligence Briefing Compilation",
            "System Cache Flushing & Queue Reset",
        ],
    },
}


def install() -> None:
    con = connect()
    con.executescript(SCHEMA)
    for ch in CHANNELS:
        con.execute("INSERT OR IGNORE INTO channel_health (channel) VALUES (?)", (ch,))
    con.commit()
    con.close()
    print("✔ schema installed; channel_health seeded:", ", ".join(CHANNELS))


# ══════════════════════════════════════════════════════════
# Raw transport layer
# ══════════════════════════════════════════════════════════
def _post_json(url: str, payload: dict) -> tuple[bool, str]:
    """POST JSON. Returns (ok, detail). Honours DRY_RUN."""
    if DRY_RUN:
        print(f"   [DRY-RUN] POST {url[:52]}… -> {json.dumps(payload)[:160]}")
        return True, "dry-run"
    body = json.dumps(payload).encode("utf-8")
    req = urllib.request.Request(
        url, data=body, method="POST",
        headers={"Content-Type": "application/json"})
    try:
        with urllib.request.urlopen(req, timeout=HTTP_TIMEOUT) as resp:
            return resp.status in (200, 201), f"HTTP {resp.status}"
    except urllib.error.HTTPError as e:
        return False, f"HTTP {e.code}: {e.read()[:200]!r}"
    except Exception as e:                       # noqa: BLE001
        return False, f"{type(e).__name__}: {e}"


def _post_form(url: str, fields: dict) -> tuple[bool, str]:
    """POST application/x-www-form-urlencoded. Returns (ok, detail)."""
    if DRY_RUN:
        print(f"   [DRY-RUN] POST {url[:52]}… -> {urllib.parse.urlencode(fields)[:160]}")
        return True, "dry-run"
    body = urllib.parse.urlencode(fields).encode("utf-8")
    req = urllib.request.Request(
        url, data=body, method="POST",
        headers={"Content-Type": "application/x-www-form-urlencoded"})
    try:
        with urllib.request.urlopen(req, timeout=HTTP_TIMEOUT) as resp:
            return resp.status in (200, 201), f"HTTP {resp.status}"
    except urllib.error.HTTPError as e:
        return False, f"HTTP {e.code}: {e.read()[:200]!r}"
    except Exception as e:                       # noqa: BLE001
        return False, f"{type(e).__name__}: {e}"


# ── Slack ──────────────────────────────────────────────────
def send_slack(webhook: str, text: str, color: str = "#36a64f",
               fields: dict | None = None, buttons: list | None = None) -> tuple[bool, str]:
    blocks: list = [{"type": "section",
                     "text": {"type": "mrkdwn", "text": text}}]
    if fields:
        blocks = [{"type": "section",
                   "fields": [{"type": "mrkdwn", "text": f"*{k}:*\n{v}"}
                              for k, v in fields.items()]}]
    if buttons:
        blocks.append({"type": "actions", "elements": [
            {"type": "button",
             "text": {"type": "plain_text", "text": b["label"]},
             "url": b["url"],
             **({"style": "primary"} if b.get("primary") else {})}
            for b in buttons]})
    payload = {"text": text,
               "attachments": [{"color": color, "blocks": blocks}]}
    return _post_json(webhook, payload)


# ── WhatsApp (UltraMsg) ────────────────────────────────────
def send_whatsapp(payload: dict) -> tuple[bool, str]:
    budget = f"${payload['deal_value']:,.2f} USD"
    msg = (
        "🚨 *SCITBD HIGH-VALUE LEAD ALERT (≥ $10,000 USD)*\n\n"
        f"👤 *Client:* {payload['client_name']}\n"
        f"🏢 *Company:* {payload.get('company_name') or 'N/A'}\n"
        f"📧 *Email:* {payload['email']}\n"
        f"🌍 *Market:* {payload['country']}\n"
        f"💰 *Budget:* *{budget}*\n"
        f"🛠️ *Service:* {payload['service_line']}\n"
        f"🎯 *Source:* {payload.get('campaign_source') or 'N/A'}\n\n"
        "⚡ *MANDATE:* Trigger *CEO Video Proposal* within *24 hours*!\n\n"
        f"🔗 *CRM:* {CRM_URL}?lead_id={payload['lead_id']}"
    )
    url = f"https://api.ultramsg.com/{ULTRAMSG_INSTANCE}/messages/chat"
    return _post_form(url, {"token": ULTRAMSG_TOKEN,
                            "to": FOUNDER_MOBILE, "body": msg})


# ── SMS (Twilio) ───────────────────────────────────────────
def send_sms(payload: dict) -> tuple[bool, str]:
    budget = f"${payload['deal_value']:,.2f} USD"
    msg = (
        "🚨 SCITBD $10K+ LEAD ALERT\n"
        f"Client: {payload['client_name']}\n"
        f"Budget: {budget}\n"
        f"Market: {payload['country']}\n"
        f"Service: {payload['service_line']}\n"
        "SLA: CEO Video within 24h!\n"
        f"CRM: {CRM_URL}?lead_id={payload['lead_id']}"
    )
    url = (f"https://api.twilio.com/2010-04-01/Accounts/"
           f"{TWILIO_SID}/Messages.json")
    if DRY_RUN:
        print(f"   [DRY-RUN] Twilio -> {FOUNDER_MOBILE}: {msg[:120]!r}")
        return True, "dry-run"
    # HTTP basic auth without external deps
    import base64
    token = base64.b64encode(f"{TWILIO_SID}:{TWILIO_TOKEN}".encode()).decode()
    body = urllib.parse.urlencode(
        {"To": FOUNDER_MOBILE, "From": TWILIO_FROM, "Body": msg}).encode()
    req = urllib.request.Request(url, data=body, method="POST",
                                 headers={"Content-Type":
                                          "application/x-www-form-urlencoded",
                                          "Authorization": f"Basic {token}"})
    try:
        with urllib.request.urlopen(req, timeout=HTTP_TIMEOUT) as resp:
            return resp.status in (200, 201), f"HTTP {resp.status}"
    except urllib.error.HTTPError as e:
        return False, f"HTTP {e.code}: {e.read()[:200]!r}"
    except Exception as e:                       # noqa: BLE001
        return False, f"{type(e).__name__}: {e}"


SENDERS = {"whatsapp": send_whatsapp, "sms": send_sms}


# ══════════════════════════════════════════════════════════
# Channel health scoring
# ══════════════════════════════════════════════════════════
def _clamp(v: float) -> float:
    return max(HEALTH_SCORE_FLOOR, min(HEALTH_SCORE_CEILING, v))


def _derive_state(score: float, consec: int) -> str:
    if score < STATE_DOWN_THRESHOLD or consec >= STATE_DOWN_CONSEC_FAIL:
        return "DOWN"
    if score < STATE_DEGRADED_THRESHOLD or consec >= 1:
        return "DEGRADED"
    return "HEALTHY"


def record_success(con: sqlite3.Connection, channel: str) -> None:
    row = con.execute("SELECT score FROM channel_health WHERE channel=?",
                      (channel,)).fetchone()
    score = _clamp((row["score"] if row else 100.0) + HEALTH_SUCCESS_BONUS)
    con.execute(
        """UPDATE channel_health
              SET successes = successes + 1, consecutive_fail = 0,
                  score = ?, state = ?, last_success_at = datetime('now','localtime'),
                  updated_at = datetime('now','localtime')
            WHERE channel = ?""",
        (score, _derive_state(score, 0), channel))


def record_failure(con: sqlite3.Connection, channel: str) -> None:
    row = con.execute(
        "SELECT score, consecutive_fail FROM channel_health WHERE channel=?",
        (channel,)).fetchone()
    score = _clamp((row["score"] if row else 100.0) - HEALTH_FAILURE_PENALTY)
    consec = (row["consecutive_fail"] if row else 0) + 1
    con.execute(
        """UPDATE channel_health
              SET failures = failures + 1, consecutive_fail = ?,
                  score = ?, state = ?, last_failure_at = datetime('now','localtime'),
                  updated_at = datetime('now','localtime')
            WHERE channel = ?""",
        (consec, score, _derive_state(score, consec), channel))


def get_health(con: sqlite3.Connection) -> dict:
    return {r["channel"]: dict(r) for r in con.execute(
        "SELECT * FROM channel_health ORDER BY score DESC")}


# ══════════════════════════════════════════════════════════
# Intelligent fallback re-ordering
# ══════════════════════════════════════════════════════════
def build_dispatch_order(con: sqlite3.Connection,
                         demote: str | None = None) -> list[dict]:
    """
    Rank channels best-first using live health:
      HEALTHY tier  -> tried first (score DESC)
      DEGRADED tier -> tried second
      DOWN tier     -> skipped unless everything else is DOWN (last resort)
    `demote` = channel that just failed this cycle -> pushed to the very end.
    """
    health = get_health(con)

    def tier(state: str) -> int:
        return {"HEALTHY": 0, "DEGRADED": 1}.get(state, 2)

    ranked = sorted(CHANNELS,
                    key=lambda c: (tier(health[c]["state"]),
                                   -health[c]["score"]))

    viable = [c for c in ranked if health[c]["state"] != "DOWN"]
    down = [c for c in ranked if health[c]["state"] == "DOWN"]

    order = viable if viable else down          # never leave CEO blind
    if viable and down:
        order = viable + down                   # DOWN appended as last resort

    if demote in order:
        order = [c for c in order if c != demote] + [demote]

    out = []
    for i, ch in enumerate(order):
        h = health[ch]
        if h["state"] == "DOWN":
            reason = (f"🔴 DOWN (score {h['score']:.0f}, "
                      f"{h['consecutive_fail']} consec fails)"
                      + (" — last resort" if i == 0 or not viable else ""))
        elif h["state"] == "DEGRADED":
            reason = f"🟡 DEGRADED (score {h['score']:.0f})"
        elif ch == demote:
            reason = "🟢 healthy but just failed → demoted to end"
        else:
            reason = f"🟢 HEALTHY (score {h['score']:.0f})"
        out.append({"channel": ch, "reason": reason, "rank": i + 1})
    return out


# ══════════════════════════════════════════════════════════
# Retry queue (exponential backoff)
# ══════════════════════════════════════════════════════════
def _next_retry(attempts: int) -> str:
    idx = min(attempts, len(RETRY_BACKOFF) - 1)
    delay = RETRY_BACKOFF[idx]
    t = now_bst() + datetime.timedelta(seconds=delay)
    return t.strftime("%Y-%m-%d %H:%M:%S")


def enqueue_retry(con: sqlite3.Connection, lead_id: int, channel: str,
                  payload: dict, error: str = "") -> int:
    cur = con.execute(
        """INSERT INTO alert_retry_queue
             (lead_id, channel, payload_json, attempts, max_attempts,
              next_retry_at, status, last_error)
           VALUES (?,?,?,?,?,?,'PENDING',?)""",
        (lead_id, channel, json.dumps(payload), 0, RETRY_MAX_ATTEMPTS,
         _next_retry(0), error))
    return cur.lastrowid


def reap_stale_inflight(con: sqlite3.Connection,
                        stale_minutes: int = 10) -> list[sqlite3.Row]:
    """
    Recover jobs abandoned in IN_FLIGHT.

    claim_due() flips PENDING -> IN_FLIGHT, but a job only leaves IN_FLIGHT via
    mark_delivered()/mark_failed(). If the worker crashes mid-cycle (or a prior
    bug leaves rows stranded), those rows are never claimed again because
    claim_due() only selects IN_FLIGHT rows it just set itself.

    Any IN_FLIGHT row untouched for `stale_minutes` is presumed orphaned and is
    returned to PENDING with its attempt count preserved, so backoff still
    applies and it cannot be silently lost.
    """
    stale = con.execute(
        """SELECT * FROM alert_retry_queue
            WHERE status='IN_FLIGHT'
              AND updated_at < datetime('now','localtime', ?)""",
        (f"-{stale_minutes} minutes",)).fetchall()
    if not stale:
        return []
    ids = [r["id"] for r in stale]
    con.executemany(
        """UPDATE alert_retry_queue
              SET status='PENDING',
                  last_error=COALESCE(last_error,'') || ' [reaped: stale IN_FLIGHT]',
                  updated_at=datetime('now','localtime')
            WHERE id=?""",
        [(i,) for i in ids])
    for r in stale:
        _log(con, r["lead_id"], r["channel"], "REAPED",
             f"stale IN_FLIGHT (>{stale_minutes}min) reset to PENDING; "
             f"attempts={r['attempts']} preserved", r["attempts"])
    return stale


def claim_due(con: sqlite3.Connection,
              stale_minutes: int = 10) -> list[sqlite3.Row]:
    # Reclaim orphans first so they re-enter the normal PENDING -> IN_FLIGHT flow.
    reaped = reap_stale_inflight(con, stale_minutes)
    if reaped:
        print(f"  ♻ reaped {len(reaped)} stale IN_FLIGHT job(s): "
              f"{[r['id'] for r in reaped]}")
    con.execute(
        """UPDATE alert_retry_queue
              SET status='IN_FLIGHT', updated_at=datetime('now','localtime')
            WHERE status='PENDING' AND next_retry_at <= datetime('now','localtime')""")
    con.commit()
    return con.execute(
        """SELECT * FROM alert_retry_queue WHERE status='IN_FLIGHT'
            ORDER BY next_retry_at ASC""").fetchall()


def mark_delivered(con: sqlite3.Connection, job_id: int) -> None:
    con.execute(
        """UPDATE alert_retry_queue
              SET status='DELIVERED', updated_at=datetime('now','localtime')
            WHERE id=?""", (job_id,))


def _notify_exhaustion(con: sqlite3.Connection, job: sqlite3.Row,
                       attempts: int, error: str) -> None:
    """
    CRITICAL escalation: every retry for this channel is spent.
    Pings the Slack health channel and writes an operational_logs row so the
    failure is visible even if nobody watches alert_dispatch_log.
    """
    lead_id = job["lead_id"]
    chan = job["channel"]
    text = (f"🔴 *ALERT RETRY EXHAUSTED* — Lead #{lead_id}\n"
            f"Channel `{chan}` failed {attempts}/{job['max_attempts']} times.\n"
            f"Last error: `{error}`\n"
            f"> Manual intervention required — do not rely on automated retry.")
    ok, detail = send_slack(SLACK_HEALTH_WEBHOOK, text, "#ff0055",
                            fields={"Lead": str(lead_id), "Channel": chan,
                                    "Attempts": f"{attempts}/{job['max_attempts']}",
                                    "Status": "EXHAUSTED"})
    # operational_logs.status CHECK only allows SUCCESS/PENDING/FAILED
    con.execute(
        """INSERT INTO operational_logs (block_id, block_name, action_taken, status)
           VALUES (?,?,?,?)""",
        (None, "Alerting Engine",
         f"EXHAUSTED: lead #{lead_id} channel {chan} after {attempts} attempts "
         f"(last error: {error}) — Slack ping {'ok' if ok else 'FAILED: ' + detail}",
         "FAILED" if not ok else "SUCCESS"))


def mark_failed(con: sqlite3.Connection, job: sqlite3.Row, error: str) -> str:
    attempts = job["attempts"] + 1
    if attempts >= job["max_attempts"]:
        con.execute(
            """UPDATE alert_retry_queue
                  SET attempts=?, status='EXHAUSTED', last_error=?,
                      updated_at=datetime('now','localtime')
                WHERE id=?""", (attempts, error, job["id"]))
        _log(con, job["lead_id"], job["channel"], "EXHAUSTED",
             f"Retries exhausted after {attempts} attempts: {error}", attempts)
        _notify_exhaustion(con, job, attempts, error)
        return "EXHAUSTED"
    con.execute(
        """UPDATE alert_retry_queue
              SET attempts=?, status='PENDING', next_retry_at=?, last_error=?,
                  updated_at=datetime('now','localtime')
          WHERE id=?""",
        (attempts, _next_retry(attempts), error, job["id"]))
    return "RETRY_SCHEDULED"


def _log(con: sqlite3.Connection, lead_id, channel, outcome, detail, attempt=1):
    con.execute(
        """INSERT INTO alert_dispatch_log
             (lead_id, channel, outcome, detail, attempt)
           VALUES (?,?,?,?,?)""",
        (lead_id, channel, outcome, detail, attempt))


# ══════════════════════════════════════════════════════════
# Master dispatcher
# ══════════════════════════════════════════════════════════
def dispatch_high_value_lead(payload: dict, con: sqlite3.Connection | None = None
                             ) -> dict:
    """
    Walk the intelligently-ordered channel chain until one delivery succeeds.
    Unreliable channels are automatically skipped/deprioritised.
    Failures are enqueued for exponential-backoff retry.
    """
    own = con is None
    con = con or connect()
    lead_id = payload["lead_id"]

    order = build_dispatch_order(con, demote=None)
    plan = " → ".join(f"{o['channel']}({o['reason']})" for o in order)
    print(f"  dispatch plan: {plan}")

    attempted, delivered_on, errors = [], None, {}

    for step in order:
        ch = step["channel"]
        is_last_resort = "last resort" in step["reason"]
        # Skip DOWN channels unless this is literally the only option
        if step["reason"].startswith("🔴") and not is_last_resort:
            print(f"  ⏭  {ch} DOWN → skipped")
            continue

        attempted.append(ch)
        print(f"  → {ch}: ", end="", flush=True)
        ok, detail = SENDERS[ch](payload)

        if ok:
            record_success(con, ch)
            delivered_on = ch
            print("✅")
            _log(con, lead_id, ch, "DELIVERED",
                 f"primary dispatch via {ch} ({detail})", 1)
            break
        record_failure(con, ch)
        errors[ch] = detail
        print(f"❌ {detail}")

    if delivered_on:
        # Best-effort follow-up on the non-primary channel
        for ch in CHANNELS:
            if ch != delivered_on:
                enqueue_retry(con, lead_id, ch, payload,
                              "non-primary channel — best-effort follow-up")
    else:
        for ch in attempted:
            enqueue_retry(con, lead_id, ch, payload, errors.get(ch, "failed"))
        _log(con, lead_id, None, "QUEUED_FOR_RETRY",
             f"all channels failed: {errors}", 1)
        send_slack(SLACK_HEALTH_WEBHOOK,
                   f"⚠️ Lead #{lead_id} ($10k+) failed on all channels — "
                   f"retry queue engaged.", "#ff0055")

    con.commit()
    if own:
        con.close()
    return {"lead_id": lead_id, "delivered_via": delivered_on,
            "attempted": attempted, "plan": order,
            "errors": errors}


# ══════════════════════════════════════════════════════════
# 15-minute pre-block alert
# ══════════════════════════════════════════════════════════
# Grace window for the 15-minute pre-block alert.
# The alert must fire *before* its block starts, so a trigger is considered
# "due" from its own minute up to (but not including) PREBLOCK_WINDOW_MIN
# minutes later. Beyond that the block has already begun and it is too late.
PREBLOCK_WINDOW_MIN = 15


def _to_min(hhmm: str) -> int:
    h, m = map(int, hhmm.split(":"))
    return h * 60 + m


def find_due_alert(hhmm: str) -> tuple[str | None, dict | None, int | None]:
    """
    Locate a trigger whose fire-window contains `hhmm`.

    Uses modular arithmetic so the 23:45 trigger correctly wraps past
    midnight (a run at 00:03 is 18 minutes past 23:45 -> outside window,
    while 23:59 -> 14 minutes past -> inside).

    Returns (trigger_hhmm, alert, minutes_since_trigger) or (None, None, None).
    """
    now_min = _to_min(hhmm)
    for trig, alert in BLOCK_ALERTS.items():
        elapsed = (now_min - _to_min(trig)) % (24 * 60)
        if elapsed < PREBLOCK_WINDOW_MIN:
            return trig, alert, elapsed
    return None, None, None


def precheck(dry_override: bool | None = None,
             con: sqlite3.Connection | None = None) -> str:
    """
    Fire the 15-min pre-block Slack alert if BST now falls inside a trigger window.

    Timezone independence: all comparisons use now_bst() (Asia/Dhaka via
    zoneinfo), never the OS clock, so this is correct regardless of the
    machine's configured timezone. Combined with a recurring scheduler
    (every 5 min) this guarantees the alert fires even if Task Scheduler
    jitters or the host clock is set to another zone.

    Dedupe: at most one fire per block per day via block_alert_state.
    """
    global DRY_RUN
    if dry_override is not None:
        DRY_RUN = dry_override

    own = con is None
    con = con or connect()
    ts = now_bst()
    hhmm = ts.strftime("%H:%M")
    date_str = ts.strftime("%Y-%m-%d")

    def _close() -> None:
        if own:
            con.close()

    trig, alert, elapsed = find_due_alert(hhmm)
    if alert is None:
        _close()
        return (f"no trigger due at {hhmm} BST "
                f"(windows: {', '.join(BLOCK_ALERTS)} "
                f"±{PREBLOCK_WINDOW_MIN}min)")

    # Dedupe: one fire per block per day
    row = con.execute("SELECT last_fired FROM block_alert_state WHERE block_id=?",
                      (alert["block"],)).fetchone()
    if row and row["last_fired"] == date_str:
        _close()
        return f"block {alert['block']} already alerted today"

    tasks = "\n".join(f"• {t}" for t in alert["tasks"])
    text = (f"*{alert['title']}*\n"
            f"*Target Region:* {alert['region']}\n"
            f"*System Time:* {hhmm} BST ({date_str})\n"
            f"*Trigger:* {trig} BST, fired +{elapsed}min\n\n"
            f"*Priority Tasks for this Block:*\n{tasks}")
    ok, detail = send_slack(SLACK_BLOCK_WEBHOOK, text, alert["color"],
                            buttons=[{"label": "Open CRM Dashboard",
                                      "url": CRM_URL, "primary": True}])

    con.execute(
        """INSERT INTO block_alert_state (block_id, last_fired, last_slot)
           VALUES (?,?,?)
           ON CONFLICT(block_id) DO UPDATE SET
             last_fired=excluded.last_fired, last_slot=excluded.last_slot""",
        (alert["block"], date_str, hhmm))
    con.execute(
        """INSERT INTO operational_logs (block_id, block_name, action_taken, status)
           VALUES (?,?,?,?)""",
        (alert["block"], f"Block {alert['block']}",
         f"15-min pre-block alert fired at {hhmm} BST "
         f"(trigger {trig}, +{elapsed}min) -> {detail}",
         "SUCCESS" if ok else "FAILED"))
    con.commit()
    _close()
    return f"{'✔' if ok else '✘'} block {alert['block']} alert {detail}"


# ══════════════════════════════════════════════════════════
# Retry worker
# ══════════════════════════════════════════════════════════
def retry_worker(con: sqlite3.Connection | None = None) -> str:
    own = con is None
    con = con or connect()
    jobs = claim_due(con)
    if not jobs:
        if own:
            con.close()
        return "no retries due"

    # group by lead so one lead's channels are handled in live-ranked order
    by_lead: dict[int, list] = {}
    for j in jobs:
        by_lead.setdefault(j["lead_id"], []).append(j)

    summary = []
    for lead_id, lead_jobs in by_lead.items():
        payload = json.loads(lead_jobs[0]["payload_json"])
        order = build_dispatch_order(con, demote=None)
        rank = [o["channel"] for o in order]
        lead_jobs.sort(key=lambda j: rank.index(j["channel"])
                       if j["channel"] in rank else 99)

        print(f"[lead #{lead_id}] live order: {' → '.join(rank)}")
        delivered_on = None

        for job in lead_jobs:
            ch = job["channel"]
            health = get_health(con)

            if health[ch]["state"] == "DOWN" and ch != rank[-1]:
                print(f"  ⏭  {ch} DOWN → skipped this cycle")
                con.execute(
                    """UPDATE alert_retry_queue
                          SET next_retry_at=?, updated_at=datetime('now','localtime')
                        WHERE id=?""", (_next_retry(job["attempts"]), job["id"]))
                continue

            if delivered_on:
                con.execute(
                    """UPDATE alert_retry_queue
                          SET status='PENDING', next_retry_at=?, last_error=?,
                              updated_at=datetime('now','localtime')
                        WHERE id=?""",
                    (_next_retry(job["attempts"]),
                     f"superseded — lead already reached via {delivered_on}",
                     job["id"]))
                continue

            attempt = job["attempts"] + 1
            print(f"  → {ch} attempt {attempt}/{job['max_attempts']}: ",
                  end="", flush=True)
            ok, detail = SENDERS[ch](payload)

            if ok:
                record_success(con, ch)
                mark_delivered(con, job["id"])
                _log(con, lead_id, ch, "DELIVERED",
                     f"retry success via {ch} ({detail})", attempt)
                delivered_on = ch
                print("✅")
            else:
                record_failure(con, ch)
                state = mark_failed(con, job, detail)
                _log(con, lead_id, ch, state,
                     f"retry attempt {attempt} failed: {detail}", attempt)
                print(f"❌ -> {state}")

    con.commit()
    counts = con.execute(
        """SELECT status, COUNT(*) c FROM alert_retry_queue GROUP BY status""").fetchall()
    if own:
        con.close()
    return "queue: " + ", ".join(f"{r['status']}={r['c']}" for r in counts)


# ══════════════════════════════════════════════════════════
# Canary probe — auto-recover DOWN channels
# ══════════════════════════════════════════════════════════
def canary(con: sqlite3.Connection | None = None) -> str:
    own = con is None
    con = con or connect()
    down = [r["channel"] for r in con.execute(
        "SELECT channel FROM channel_health WHERE state='DOWN'")]
    if not down:
        if own:
            con.close()
        return "all channels up"

    probe = {"lead_id": -1, "client_name": "CANARY PROBE (ignore)",
             "company_name": "SCITBD Systems", "email": "noreply@scit.bd",
             "phone": "", "country": "BD", "deal_value": 0.0,
             "service_line": "System Health Check",
             "campaign_source": "canary_cron"}

    out = []
    for ch in down:
        ok, detail = SENDERS[ch](probe)
        if ok:
            row = con.execute("SELECT score FROM channel_health WHERE channel=?",
                              (ch,)).fetchone()
            # Canary recovery: a successful probe proves the pipe works, but it is
            # a synthetic check — not a real lead delivery. So we move the channel
            # out of DOWN into a *probation* DEGRADED state (tried second) rather
            # than straight back to HEALTHY (tried first).
            #
            # Score is floored at STATE_DOWN_THRESHOLD (50) and capped just below
            # STATE_DEGRADED_THRESHOLD (80) so that state == _derive_state(score, 0)
            # stays internally consistent: score in [50,79] with consec=0 => DEGRADED.
            # The first genuine success then lifts it to HEALTHY via record_success().
            score = _clamp(max(row["score"] + 15.0, STATE_DOWN_THRESHOLD))
            if score >= STATE_DEGRADED_THRESHOLD:
                score = STATE_DEGRADED_THRESHOLD - 1.0
            state = _derive_state(score, 0)
            con.execute(
                """UPDATE channel_health
                      SET score=?, consecutive_fail=0, state=?,
                          last_success_at=datetime('now','localtime'),
                          updated_at=datetime('now','localtime')
                    WHERE channel=?""",
                (score, state, ch))
            out.append(f"🔁 {ch} canary OK → {score:.0f} "
                       f"({state}, probation — next real send confirms HEALTHY)")
        else:
            record_failure(con, ch)
            out.append(f"💀 {ch} canary failed ({detail}) → remains DOWN")
    con.commit()
    if own:
        con.close()
    return "; ".join(out)


# ══════════════════════════════════════════════════════════
# Reporting
# ══════════════════════════════════════════════════════════
def health_dashboard(con: sqlite3.Connection | None = None) -> str:
    own = con is None
    con = con or connect()
    rows = get_health(con)
    q = con.execute(
        """SELECT status, COUNT(*) c FROM alert_retry_queue GROUP BY status"""
    ).fetchall()
    if own:
        con.close()

    lines = ["=" * 74,
             "  SCITBD ALERT CHANNEL HEALTH",
             "=" * 74,
             f"  {'CHANNEL':<12}{'STATE':<12}{'SCORE':>7}{'CONSEC':>8}"
             f"{'OK':>6}{'FAIL':>6}{'RATE':>8}  PRIORITY",
             "-" * 74]
    for ch, h in rows.items():
        total = h["successes"] + h["failures"]
        rate = f"{100.0 * h['successes'] / total:.1f}%" if total else "n/a"
        if h["state"] == "DOWN":
            prio = "SKIP (canary/30m)"
        elif h["state"] == "DEGRADED":
            prio = "tries SECOND"
        else:
            prio = "tries FIRST"
        lines.append(f"  {ch:<12}{h['state']:<12}{h['score']:>7.1f}"
                     f"{h['consecutive_fail']:>8}{h['successes']:>6}"
                     f"{h['failures']:>6}{rate:>8}  {prio}")
    lines += ["-" * 74, "  RETRY QUEUE: " +
              (", ".join(f"{r['status']}={r['c']}" for r in q) or "empty"),
              "=" * 74]
    return "\n".join(lines)


def test_lead() -> dict:
    payload = {"lead_id": 9999, "client_name": "Test Enterprise Ltd",
               "company_name": "TestCo", "email": "cto@testco.example",
               "phone": "+10000000000", "country": "United States",
               "deal_value": 12500.00, "service_line": "Custom ERP + AI",
               "campaign_source": "linkedin_ads"}
    print("Dispatching test $12,500 lead (DRY_RUN=%s)…" % DRY_RUN)
    return dispatch_high_value_lead(payload)


def main() -> None:
    cmd = sys.argv[1] if len(sys.argv) > 1 else "health"
    if cmd == "install":
        install()
    elif cmd == "health":
        print(health_dashboard())
    elif cmd == "precheck":
        print(precheck())
    elif cmd == "retry":
        print(retry_worker())
    elif cmd == "canary":
        print(canary())
    elif cmd == "testlead":
        print(json.dumps(test_lead(), indent=2, default=str))
        print(health_dashboard())
    elif cmd == "demo":
        # Full pipeline with forced dry-run and injected failures
        global DRY_RUN
        DRY_RUN = True
        install()
        print("\n1) block pre-alert")
        print("  ", precheck(dry_override=True))
        print("\n2) $10k lead dispatch")
        test_lead()
        print("\n3) channel health")
        print(health_dashboard())
        print("\n4) retry worker")
        print("  ", retry_worker())
        print("\n5) canary")
        print("  ", canary())
    else:
        print(__doc__)


if __name__ == "__main__":
    main()
