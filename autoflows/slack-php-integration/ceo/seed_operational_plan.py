# -*- coding: utf-8 -*-
"""
SCITBD AI CEO — Daily Operational Plan seeder
Creates block_tasks + sla_standards, seeds the 16 execution slots
and 6 SLA standards, syncs the active block, and writes audit logs.
"""
import sqlite3, datetime, zoneinfo, sys

DB = r"D:/CRM_with_GOOGLE_Sheet/ceo/ceo - Copy/scitbd_ceo.db"
BST = zoneinfo.ZoneInfo("Asia/Dhaka")
now = datetime.datetime.now(BST)

db = sqlite3.connect(DB)
db.row_factory = sqlite3.Row
db.execute("PRAGMA foreign_keys=ON")

# ─────────────────────────────────────────────────────────
# 1) SCHEMA
# ─────────────────────────────────────────────────────────
db.executescript("""
CREATE TABLE IF NOT EXISTS block_tasks (
    id                INTEGER PRIMARY KEY AUTOINCREMENT,
    block_id          INTEGER NOT NULL,
    slot_start        TEXT NOT NULL,
    slot_end          TEXT NOT NULL,
    slot_name         TEXT NOT NULL,
    detailed_tasks    TEXT NOT NULL,
    expected_outcome  TEXT,
    priority          TEXT CHECK(priority IN ('low','medium','high','critical')) DEFAULT 'high',
    category          TEXT CHECK(category IN ('sales','marketing','development','operations','client','admin','ai','general')) DEFAULT 'operations',
    escalation_protocol TEXT,
    is_recurring      INTEGER NOT NULL DEFAULT 1,
    UNIQUE(block_id, slot_start, slot_name),
    FOREIGN KEY (block_id) REFERENCES operational_blocks(id)
);

CREATE TABLE IF NOT EXISTS sla_standards (
    id                 INTEGER PRIMARY KEY AUTOINCREMENT,
    trigger_task       TEXT NOT NULL UNIQUE,
    sla_target         TEXT NOT NULL,
    sla_target_minutes INTEGER,
    escalation_protocol TEXT NOT NULL,
    severity           TEXT CHECK(severity IN ('low','medium','high','critical')) DEFAULT 'high',
    is_active          INTEGER NOT NULL DEFAULT 1
);
""")

# ─────────────────────────────────────────────────────────
# 2) THE 16 EXECUTION SLOTS (4 per operational block)
# ─────────────────────────────────────────────────────────
SLOTS = [
    # ---------- BLOCK 1 : South Asia & Domestic ----------
    (1, "06:00", "07:00", "SEO & Local Content Deploy",
     "Publish 1 SEO-optimized blog post targeting keywords across SCITBD's 17 strategic "
     "capabilities. Post morning updates across LinkedIn, Facebook, and TikTok tailored for "
     "South Asian audiences.",
     "Drives organic search indexing, captures early morning regional web traffic, and "
     "increases brand visibility across Asian social platforms.",
     "high", "marketing", None),

    (1, "07:00", "09:00", "Domestic & Regional RFP Scrapers",
     "Run automated scrapers on government and enterprise procurement portals in Bangladesh "
     "(e.g., e-GP system) and South Asia. Flag matching RFPs and initiate automated generation "
     "via the Proposal Factory (Task 7).",
     "Identifies new tender opportunities immediately upon release and drafts custom bid "
     "proposals for fast submission.",
     "critical", "sales", None),

    (1, "09:00", "11:00", "BD Lead Nurturing & Pipeline",
     "Deploy automated lead-nurturing email workflows (Touchpoints 1-3) and update CRM records "
     "for active South Asian accounts.",
     "Moves warm regional prospects through the sales funnel and maintains active client contact.",
     "medium", "sales", None),

    (1, "11:00", "12:00", "Brand SLA & Review Audit",
     "Monitor Trustpilot, Google My Business, and local directory listings. Auto-reply to "
     "positive feedback and escalate any rating under 40 or negative mention to the CEO queue "
     "within 1 hour.",
     "Protects corporate reputation and ensures rapid resolution of client dissatisfaction.",
     "critical", "client", "Negative review -> CEO queue within 1 hour; NPS < 40 -> emergency review meeting within 48 hours."),

    # ---------- BLOCK 2 : Middle East & Europe ----------
    (2, "12:00", "13:30", "Ad Engine Performance Audit",
     "Audit active Google Search Ads and LinkedIn Sponsored Content running in the UAE, Saudi "
     "Arabia, UK, Germany, and France. Auto-pause any campaign showing negative ROAS for 3 "
     "consecutive days.",
     "Eliminates wasted ad spend and reallocates capital to high-converting ad sets.",
     "high", "marketing", "Negative ROAS for 3 consecutive days -> auto-pause campaign."),

    (2, "13:30", "16:00", "Middle East & European B2B Prospecting",
     "Identify and initiate contact with 10-12 CTOs/CIOs daily via LinkedIn Sales Navigator and "
     "cold email automation, focusing on Custom ERP, Cloud Transformation, and AI Solutions.",
     "Builds a predictable, high-value pipeline of European and Middle Eastern enterprise leads.",
     "high", "sales", None),

    (2, "16:00", "17:30", "European Content Engine Execution",
     "Publish Blog Post #2 optimized for UK/EU search queries. Distribute gated case studies and "
     "whitepapers for lead capture.",
     "Expands European organic search footprint and generates qualified lead downloads.",
     "medium", "marketing", None),

    (2, "17:30", "18:00", "Retainer & Upsell Automations",
     "Dispatch automated upsell sequences to existing European clients currently utilizing only "
     "1-2 service lines.",
     "Maximizes Client Lifetime Value (LTV) and converts single-project clients into monthly "
     "retainers.",
     "medium", "sales", None),

    # ---------- BLOCK 3 : UK & North America ----------
    (3, "18:00", "19:30", "US Market Campaign Launch",
     "Launch refreshed Facebook Carousel and LinkedIn ad sets targeting US/Canadian SMEs and run "
     "A/B tests on SCITBD's AI Product Suite.",
     "Engages North American buyers at the start of their business day with high-converting "
     "trial offers.",
     "high", "marketing", None),

    (3, "19:30", "22:00", "High-Value Proposals & Direct Pipeline",
     "Process incoming web inquiries from North America and the UK. Generate custom technical "
     "proposals within the sub-2-hour SLA limit.",
     "Achieves high conversion rates on high-ticket enterprise contracts.",
     "critical", "sales",
     "ESCALATION: Lead value >= $10,000 USD -> personalized CEO Video Proposal dispatched within 24 hours. "
     "All inbound RFPs -> proposal returned in under 2 hours."),

    (3, "22:00", "23:30", "US Content & Video Engine",
     "Publish Blog Post #3 for North American search traffic and distribute short-form video "
     "assets across TikTok, Reels, and YouTube Shorts.",
     "Captures high-intent US search traffic and drives video view velocity toward the 10,000 "
     "views/video milestone.",
     "high", "marketing", None),

    (3, "23:30", "00:00", "Client Support & System Health Audit",
     "Verify technical support response times to uphold the sub-2-hour SLA and audit live hosting "
     "setups for 99.9% uptime compliance.",
     "Maintains service reliability and prevents client churn.",
     "critical", "operations",
     "Support ticket -> under 2 hours. Uptime drop below 99.9% -> instant operations engineering alert."),

    # ---------- BLOCK 4 : Oceania & Global Reboot ----------
    (4, "00:00", "02:00", "Daily Campaign Optimization",
     "Analyze multi-channel marketing performance across Google, Meta, LinkedIn, and TikTok. "
     "Reallocate ad budgets dynamically to top performers.",
     "Maximizes overall marketing ROAS across global channels.",
     "high", "marketing", None),

    (4, "02:00", "04:00", "Oceania Market Operations",
     "Trigger B2B outreach sequences targeting Australian SMEs as their business day opens and "
     "scan Australian tender databases.",
     "Establishes early-mover advantage in the Australian and New Zealand commercial sectors.",
     "high", "sales", None),

    (4, "04:00", "05:30", "Daily CEO Intelligence Summary",
     "Compile the Daily CEO Intelligence Briefing covering: (1) Daily Revenue Generated vs. "
     "Target ($1.5M Year-End Goal); (2) New Leads Captured & Proposals Delivered; "
     "(3) Ad ROAS, Organic Traffic, and System Uptime Metrics.",
     "Gives executive leadership real-time visibility into financial and operational progress.",
     "critical", "admin", None),

    (4, "05:30", "06:00", "System Flush & Reset",
     "Clear system caches, refresh automation queues, and synchronize workflows for the next "
     "06:00 BST loop.",
     "Ensures zero system latency and continuous 24/7 autonomous execution.",
     "high", "operations", None),
]

# ─────────────────────────────────────────────────────────
# 3) NON-NEGOTIABLE SLA STANDARDS
# ─────────────────────────────────────────────────────────
SLAS = [
    ("Client Support Ticket", "< 2 Hours", 120,
     "Automatic routing to technical team", "critical"),
    ("Inbound Lead RFP", "Proposal in < 2 Hours", 120,
     "Proposal Factory automation triggered", "critical"),
    ("Lead Value >= $10,000 USD", "Action within 24 Hours", 1440,
     "Personalized CEO Video Message sent", "critical"),
    ("Negative Brand Review", "Action within < 1 Hour", 60,
     "CEO Review Queue notification", "high"),
    ("NPS Score < 40", "Action within < 48 Hours", 2880,
     "Emergency Review Meeting triggered", "high"),
    ("System Hosted Uptime", "99.9% Guarantee", None,
     "Instant operations engineering alert", "critical"),
]

# ─────────────────────────────────────────────────────────
# 4) SEED (idempotent)
# ─────────────────────────────────────────────────────────
cur = db.cursor()

# Refresh the 4 core block rows so regional focus matches the plan
BLOCKS = [
    (1, "Block 1: 06:00 \u2013 12:00 BST | South Asia & Domestic", "06:00", "12:00",
     "00:00", "06:00", "BD / South Asia",
     "SEO, Local Tenders & BD Pipeline"),
    (2, "Block 2: 12:00 \u2013 18:00 BST | Middle East / EU", "12:00", "18:00",
     "06:00", "12:00", "Middle East / EU",
     "ME & EU Sales Outreach & Ad Audits"),
    (3, "Block 3: 18:00 \u2013 00:00 BST | UK / US East Coast", "18:00", "00:00",
     "12:00", "18:00", "UK / US East Coast",
     "North America Peak Launch & High-Value Deals"),
    (4, "Block 4: 00:00 \u2013 06:00 BST | US West / Oceania", "00:00", "06:00",
     "18:00", "24:00", "US West / Oceania",
     "Night Analytics, Oceania Launch & Reboot"),
]
for bid, name, bs, be, us, ue, reg, focus in BLOCKS:
    cur.execute("""
        INSERT INTO operational_blocks
            (id, block_name, bst_start, bst_end, utc_start, utc_end,
             regional_focus, core_execution_focus, status)
        VALUES (?,?,?,?,?,?,?,?,'INACTIVE')
        ON CONFLICT(id) DO UPDATE SET
            block_name=excluded.block_name,
            bst_start=excluded.bst_start, bst_end=excluded.bst_end,
            utc_start=excluded.utc_start, utc_end=excluded.utc_end,
            regional_focus=excluded.regional_focus,
            core_execution_focus=excluded.core_execution_focus
    """, (bid, name, bs, be, us, ue, reg, focus))

slot_count = 0
for (bid, ss, se, sname, detail, outcome, prio, cat, esc) in SLOTS:
    cur.execute("""
        INSERT INTO block_tasks
            (block_id, slot_start, slot_end, slot_name, detailed_tasks,
             expected_outcome, priority, category, escalation_protocol)
        VALUES (?,?,?,?,?,?,?,?,?)
        ON CONFLICT(block_id, slot_start, slot_name) DO UPDATE SET
            slot_end=excluded.slot_end,
            detailed_tasks=excluded.detailed_tasks,
            expected_outcome=excluded.expected_outcome,
            priority=excluded.priority,
            category=excluded.category,
            escalation_protocol=excluded.escalation_protocol
    """, (bid, ss, se, sname, detail, outcome, prio, cat, esc))
    slot_count += 1

sla_count = 0
for (trig, tgt, mins, esc, sev) in SLAS:
    cur.execute("""
        INSERT INTO sla_standards
            (trigger_task, sla_target, sla_target_minutes, escalation_protocol, severity)
        VALUES (?,?,?,?,?)
        ON CONFLICT(trigger_task) DO UPDATE SET
            sla_target=excluded.sla_target,
            sla_target_minutes=excluded.sla_target_minutes,
            escalation_protocol=excluded.escalation_protocol,
            severity=excluded.severity,
            is_active=1
    """, (trig, tgt, mins, esc, sev))
    sla_count += 1

# ─────────────────────────────────────────────────────────
# 5) SYNC ACTIVE BLOCK TO CURRENT BST TIME
# ─────────────────────────────────────────────────────────
hhmm = now.strftime("%H:%M")
cur.execute("UPDATE operational_blocks SET status='INACTIVE'")
if hhmm < "06:00":
    active_id = 4
elif hhmm < "12:00":
    active_id = 1
elif hhmm < "18:00":
    active_id = 2
else:
    active_id = 3
cur.execute("UPDATE operational_blocks SET status='ACTIVE' WHERE id=?", (active_id,))

# Resolve which execution slot is live right now
live_slot = None
for (bid, ss, se, sname, *_rest) in SLOTS:
    if bid != active_id:
        continue
    if ss <= hhmm < se or (se == "00:00" and hhmm >= ss):
        live_slot = (bid, ss, se, sname)
        break

# ─────────────────────────────────────────────────────────
# 6) AUDIT LOGS
# ─────────────────────────────────────────────────────────
cur.execute("""
    INSERT INTO operational_logs (block_id, block_name, action_taken, status)
    VALUES (?,?,?,'SUCCESS')
""", (active_id, f"Block {active_id}",
      f"SCITBD AI Operational Plan installed: {slot_count} execution slots + "
      f"{sla_count} SLA standards seeded. Active block synced to {hhmm} BST."))

# Mirror into task_logs (requires a task_id, so log against the newest task)
last_task = cur.execute("SELECT id FROM daily_tasks ORDER BY id DESC LIMIT 1").fetchone()
if last_task:
    cur.execute("""
        INSERT INTO task_logs (task_id, action, performed_by, details)
        VALUES (?, 'operational_plan_seeded', 'ai_agent', ?)
    """, (last_task[0],
          f"Seeded {slot_count} recurring execution slots across 4 BST blocks and "
          f"{sla_count} non-negotiable SLA standards. Active block = {active_id} "
          f"(live slot: {live_slot[3] if live_slot else 'none'})."))

db.commit()

print("=== SEED COMPLETE ===")
print(f"block_tasks   : {cur.execute('SELECT COUNT(*) FROM block_tasks').fetchone()[0]} rows")
print(f"sla_standards : {cur.execute('SELECT COUNT(*) FROM sla_standards').fetchone()[0]} rows")
print(f"ops blocks    : {cur.execute('SELECT COUNT(*) FROM operational_blocks').fetchone()[0]} rows")
print(f"active block  : #{active_id} at {hhmm} BST")
print(f"live slot     : {live_slot[3] if live_slot else 'none'}")
print(f"operational_logs written: {cur.execute('SELECT COUNT(*) FROM operational_logs').fetchone()[0]}")
print(f"task_logs written: {cur.execute('SELECT COUNT(*) FROM task_logs').fetchone()[0]}")
db.close()
