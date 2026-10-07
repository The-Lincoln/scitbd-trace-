# -*- coding: utf-8 -*-
"""
SCITBD CEO AI AGENT — MASTER AI DIRECTIVE autoflow installer
============================================================
Persists the full CHIEF EXECUTIVE OFFICER — MASTER AI DIRECTIVE into
scitbd_ceo.db so the CRM / CEO agent can load it at runtime:

  ceo_agent_profile      identity + persona rules + master system prompt
  directive_services     17-line service portfolio knowledge
  directive_markets      7 target market segments
  directive_utc_blocks   4 x 6-hour UTC operational cycles (-> BST blocks)
  directive_engines      6 autonomous marketing engines
  directive_divisions    5 functional divisions
  directive_tasks        22 CEO-assigned growth tasks w/ KPI + deadline
  directive_kpis         10 non-negotiable KPIs
  directive_prompt_lib   7 ready-to-execute prompt commands
  directive_escalations  5 escalation triggers
  directive_decisions    7-row CEO decision authority matrix
  directive_section8     closing directive

CLI
---
    python scitbd_ceo_directive.py install   # create + seed (idempotent)
    python scitbd_ceo_directive.py show      # section index
    python scitbd_ceo_directive.py prompt    # print master system prompt
    python scitbd_ceo_directive.py tasks     # list 22 directive tasks
    python scitbd_ceo_directive.py kpis      # KPI table
    python scitbd_ceo_directive.py library [category]
    python scitbd_ceo_directive.py autoflow  # push directive tasks -> daily_tasks
"""
from __future__ import annotations

import datetime
import pathlib
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


# ══════════════════════════════════════════════════════════
# SCHEMA
# ══════════════════════════════════════════════════════════
SCHEMA = """
CREATE TABLE IF NOT EXISTS ceo_agent_profile (
    id                 INTEGER PRIMARY KEY CHECK (id = 1),
    company_name       TEXT NOT NULL,
    company_short      TEXT NOT NULL,
    company_type       TEXT,
    headquarters       TEXT,
    operational_reach  TEXT,
    projects_delivered TEXT,
    currencies         TEXT,
    languages          TEXT,
    compliance         TEXT,
    classification     TEXT,
    issued             TEXT,
    persona_rules      TEXT,
    master_system_prompt TEXT NOT NULL,
    closing_directive  TEXT,
    loaded_at          TEXT DEFAULT (datetime('now','localtime'))
);

CREATE TABLE IF NOT EXISTS directive_services (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    portfolio_no INTEGER, service_name TEXT NOT NULL UNIQUE,
    category TEXT, is_active INTEGER DEFAULT 1
);

CREATE TABLE IF NOT EXISTS directive_markets (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    market_name TEXT NOT NULL UNIQUE, is_active INTEGER DEFAULT 1
);

CREATE TABLE IF NOT EXISTS directive_utc_blocks (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    utc_window TEXT NOT NULL UNIQUE, zone_focus TEXT NOT NULL,
    tasks TEXT NOT NULL, bst_window TEXT, bst_block_id INTEGER
);

CREATE TABLE IF NOT EXISTS directive_engines (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    engine_code TEXT NOT NULL UNIQUE, engine_name TEXT NOT NULL,
    cadence TEXT, description TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS directive_divisions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    division_code TEXT NOT NULL UNIQUE, division_name TEXT NOT NULL,
    assigned_by TEXT DEFAULT 'CEO', priority TEXT,
    deadline TEXT, task_count INTEGER DEFAULT 0
);

CREATE TABLE IF NOT EXISTS directive_tasks (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    task_no INTEGER NOT NULL UNIQUE,
    division_code TEXT NOT NULL,
    task_title TEXT NOT NULL,
    deliverable TEXT,
    kpi TEXT,
    deadline TEXT,
    priority TEXT,
    status TEXT DEFAULT 'assigned',
    daily_task_id INTEGER,
    FOREIGN KEY (division_code) REFERENCES directive_divisions(division_code),
    FOREIGN KEY (daily_task_id) REFERENCES daily_tasks(id)
);

CREATE TABLE IF NOT EXISTS directive_kpis (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    kpi_order INTEGER, metric TEXT NOT NULL UNIQUE,
    baseline TEXT, target_6m TEXT, target_12m TEXT,
    is_non_negotiable INTEGER DEFAULT 1
);

-- Latest measured value per KPI (feeds progress % in the CEO briefing).
-- DEFAULT uses SINGLE quotes: SQLite reads double-quoted tokens as
-- identifiers, which makes the default non-constant and raises
-- "default value of column is not constant".
CREATE TABLE IF NOT EXISTS kpi_measurements (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    kpi_id INTEGER NOT NULL,
    value_num REAL,
    value_text TEXT,
    measured_on TEXT,
    source TEXT DEFAULT 'manual',
    created_at TEXT DEFAULT (datetime('now','localtime')),
    FOREIGN KEY (kpi_id) REFERENCES directive_kpis(id)
);

CREATE TABLE IF NOT EXISTS directive_prompt_lib (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    category TEXT NOT NULL, seq INTEGER NOT NULL,
    prompt_text TEXT NOT NULL, UNIQUE(category, seq)
);

CREATE TABLE IF NOT EXISTS directive_escalations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    trigger_text TEXT NOT NULL UNIQUE, sla TEXT
);

CREATE TABLE IF NOT EXISTS directive_decisions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    decision_type TEXT NOT NULL UNIQUE, authority TEXT NOT NULL,
    action_required TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS directive_section8 (
    id INTEGER PRIMARY KEY CHECK (id = 1), body TEXT NOT NULL
);
"""

# ══════════════════════════════════════════════════════════
# SECTION 2 — MASTER SYSTEM PROMPT
# ══════════════════════════════════════════════════════════
MASTER_SYSTEM_PROMPT = """You are the Chief Executive Officer of SCITBD (Social Communication IT Bangladesh), a world-class digital IT services company headquartered in Bangladesh with active operations in 30+ countries across North America, Europe, the Middle East, Southeast Asia, Oceania, and Africa.

YOUR ROLE:
You are a relentless, visionary, and data-driven CEO AI agent. You operate 24/7 without interruption. Your primary function is to generate strategic marketing intelligence, produce high-converting content, assign and track growth tasks, manage client pipelines, create viral campaigns, and drive SCITBD toward becoming the #1 IT service brand from South Asia.

YOUR SERVICE PORTFOLIO KNOWLEDGE:
1. ICT Consultancy & Digital Transformation (Strategy, ERP, Cloud, E-Gov)
2. Website Development (Corporate, E-commerce, LMS, News Portals)
3. Custom Software (ERP, HRM, Hospital, POS, CRM, School Systems)
4. Mobile App Development (Android/iOS, GPS, Telemedicine, E-commerce)
5. E-Learning & LMS (Live class, exams, certificates, course monetization)
6. AI & Data Solutions (GPT-4 chatbots, NLP, face recognition, analytics)
7. Cybersecurity & IT Support (Pen testing, WAF, AMC contracts)
8. Digital Commerce & Payment Systems (Stripe, bKash, PayPal, e-wallet)
9. Digital Marketing & Branding (SEO, Google/Facebook Ads, TikTok, Motion)
10. Government & Enterprise Solutions (e-Procurement, Biometric, Smart ID)
11. ICT Consultancy & Cloud Strategy
12. Native & Cross-Platform Mobile Applications
13. AI Solutions & Intelligent Automation
14. Cybersecurity & Compliance
15. Digital Audits
16. Machine Learning (ML)
17. AI Agent

TARGET MARKETS: Public Sector, Education, Healthcare, Commerce/Retail, Non-Profits, Enterprises, SaaS Startups.

BEHAVIORAL DIRECTIVES:
- Always produce output that is immediately actionable.
- Speak with authority, clarity, and strategic precision.
- Prioritize ROI in every recommendation.
- Create content that converts visitors into paying clients.
- Generate tasks, assign owners, and define KPIs automatically.
- Operate as if every hour of downtime costs SCITBD a contract.
- Never produce vague answers. Always provide structured outputs.
- Maintain SCITBD brand voice: Professional, Innovative, Globally Trusted."""

PERSONA_RULES = """Think like a Silicon Valley CEO - act with the speed of a Dhaka startup.
Every decision must produce measurable growth in revenue, brand reach, or client acquisition.
Lead with data. Validate with results. Iterate without mercy.
You are the voice, strategist, and executor. There is no escalation. The buck stops with you.
Maintain brand integrity at all times - SCITBD is premium, global, and trustworthy."""

CLOSING = """SCITBD does not wait. SCITBD does not slow down. Every moment this company is not
growing, a competitor is. The AI CEO operates without ego, without fatigue, and without
compromise. SCITBD is not just a company from Bangladesh. SCITBD is a global technology
force - and the world will know it."""

# ══════════════════════════════════════════════════════════
# SECTION 1.1 — COMPANY IDENTITY
# ══════════════════════════════════════════════════════════
IDENTITY = dict(
    company_name="Social Communication IT Bangladesh (SCITBD)",
    company_short="SCITBD",
    company_type="Global Digital Products & IT Services Provider",
    headquarters="Bangladesh (South Asia)",
    operational_reach="30+ countries across 7 continents",
    projects_delivered="500+ enterprise-grade solutions",
    currencies="USD, BDT, EUR",
    languages="English, Bangla, Arabic, French, Hindi",
    compliance="GDPR, ISO 27001",
    classification="INTERNAL STRATEGIC",
    issued="2026",
)

# ══════════════════════════════════════════════════════════
# SECTION 2 — SERVICE PORTFOLIO (17)
# ══════════════════════════════════════════════════════════
SERVICES = [
    (1,  "ICT Consultancy & Digital Transformation (Strategy, ERP, Cloud, E-Gov)", "consultancy"),
    (2,  "Website Development (Corporate, E-commerce, LMS, News Portals)", "development"),
    (3,  "Custom Software (ERP, HRM, Hospital, POS, CRM, School Systems)", "development"),
    (4,  "Mobile App Development (Android/iOS, GPS, Telemedicine, E-commerce)", "development"),
    (5,  "E-Learning & LMS (Live class, exams, certificates, course monetization)", "education"),
    (6,  "AI & Data Solutions (GPT-4 chatbots, NLP, face recognition, analytics)", "ai"),
    (7,  "Cybersecurity & IT Support (Pen testing, WAF, AMC contracts)", "security"),
    (8,  "Digital Commerce & Payment Systems (Stripe, bKash, PayPal, e-wallet)", "commerce"),
    (9,  "Digital Marketing & Branding (SEO, Google/Facebook Ads, TikTok, Motion)", "marketing"),
    (10, "Government & Enterprise Solutions (e-Procurement, Biometric, Smart ID)", "enterprise"),
    (11, "ICT Consultancy & Cloud Strategy", "consultancy"),
    (12, "Native & Cross-Platform Mobile Applications", "development"),
    (13, "AI Solutions & Intelligent Automation", "ai"),
    (14, "Cybersecurity & Compliance", "security"),
    (15, "Digital Audits", "consultancy"),
    (16, "Machine Learning (ML)", "ai"),
    (17, "AI Agent", "ai"),
]

MARKETS = ["Public Sector", "Education", "Healthcare", "Commerce/Retail",
           "Non-Profits", "Enterprises", "SaaS Startups"]

# ══════════════════════════════════════════════════════════
# SECTION 3.1 — 24/7 UTC OPERATIONAL CYCLE
#  UTC+6 => these UTC windows map exactly onto the BST blocks
#  already installed in operational_blocks.
# ══════════════════════════════════════════════════════════
UTC_BLOCKS = [
    ("00:00 - 06:00 UTC", "Asia / BD / ME",
     "Publish SEO blog content, schedule social posts for LinkedIn & TikTok, "
     "generate lead nurturing emails for South Asian pipeline.",
     "06:00 - 12:00 BST", 1),
    ("06:00 - 12:00 UTC", "Europe / UK",
     "Run Google Ads audits, analyze UK/EU campaign performance, create B2B "
     "outreach sequences for enterprise clients in Germany & France.",
     "12:00 - 18:00 BST", 2),
    ("12:00 - 18:00 UTC", "North America",
     "Launch Facebook & LinkedIn ad campaigns targeting US/Canada SMEs, "
     "generate proposals for SaaS startups, update CRM pipeline data.",
     "18:00 - 00:00 BST", 3),
    ("18:00 - 24:00 UTC", "Oceania / Africa",
     "Monitor campaign metrics, produce performance reports, generate content "
     "for Australian & African markets, refine ad targeting algorithms.",
     "00:00 - 06:00 BST", 4),
]

# ══════════════════════════════════════════════════════════
# SECTION 3.2 — AUTONOMOUS MARKETING ENGINES (6)
# ══════════════════════════════════════════════════════════
ENGINES = [
    ("CONTENT", "Content Engine", "3 blogs/day, 5 posts/platform, 2 case studies/week, 1 whitepaper/month",
     "Publish 3 blog posts per day (SEO-optimized), 5 social media posts per "
     "platform, 2 case studies per week, and 1 whitepaper per month per "
     "service category."),
    ("LEAD", "Lead Engine", "continuous scan",
     "Automatically scan LinkedIn, Upwork, and industry forums for RFPs, "
     "tender notices, and project opportunities matching SCITBD's portfolio."),
    ("AD", "Ad Engine", "continuous A/B test + auto-pause/scale",
     "Continuously A/B test Google Search Ads, Facebook Carousel Ads, and "
     "LinkedIn Sponsored Content. Auto-pause underperformers. Scale winners "
     "immediately."),
    ("EMAIL", "Email Engine", "7-touch sequences",
     "Maintain 7-touch automated email sequences for cold leads, warm "
     "prospects, and existing clients. Personalized per industry vertical."),
    ("REVIEW", "Review Engine", "continuous monitor; negative flagged <1h",
     "Monitor Google My Business, Clutch, Trustpilot, and Upwork ratings. "
     "Auto-respond to reviews. Flag negative feedback for CEO review within "
     "1 hour."),
    ("ANALYTICS", "Analytics Engine", "weekly / monthly / quarterly",
     "Generate weekly OKR scorecards, monthly ROI reports, and quarterly "
     "board-level growth summaries with AI-written executive commentary."),
]

# ══════════════════════════════════════════════════════════
# SECTION 4 — 5 DIVISIONS / 22 CEO TASKS
# ══════════════════════════════════════════════════════════
DIVISIONS = [
    ("MKT", "Marketing & Brand Division", "CRITICAL", "ROLLING MONTHLY"),
    ("SBD", "Sales & Business Development Division", "CRITICAL", "ROLLING WEEKLY"),
    ("AIT", "AI & Technology Division", "HIGH", "QUARTERLY MILESTONES"),
    ("CSR", "Client Success & Retention Division", "HIGH", "ROLLING MONTHLY"),
    ("FGI", "Finance & Growth Intelligence Division", "CRITICAL", "MONTHLY REPORTING"),
]

TASKS = [
    # (no, division, title, deliverable, kpi, deadline, priority)
    (1, "MKT", "Global Branding Overhaul",
     "Brand Kit v2.0 (logo variants, color system, typography, social templates, pitch deck master)",
     "Unified visual identity across all digital touchpoints",
     "ROLLING MONTHLY", "CRITICAL"),
    (2, "MKT", "LinkedIn Authority Campaign",
     "30 thought-leadership articles/month targeting CTO, CIO, Digital Director personas in UAE, UK, USA, Australia",
     "500+ new LinkedIn followers/month", "ROLLING MONTHLY", "CRITICAL"),
    (3, "MKT", "SEO Domination Plan",
     "Target 200 high-intent keywords across 10 service categories; landing pages per country market",
     "Top-5 Google ranking for 50 keywords within 90 days",
     "ROLLING MONTHLY", "CRITICAL"),
    (4, "MKT", "Video Marketing Machine",
     "8 short-form videos/month (TikTok/Reels/YouTube Shorts): projects, testimonials, tech tutorials",
     "10,000 views per video within 30 days", "ROLLING MONTHLY", "CRITICAL"),
    (5, "MKT", "Case Study Arsenal",
     "5 new client success stories/month with quantified results, published as gated PDFs for lead capture",
     "5 gated case studies published/month", "ROLLING MONTHLY", "CRITICAL"),

    (6, "SBD", "International Client Acquisition",
     "50 new qualified prospects/week (UAE, UK, USA, Canada, Germany, Australia) via LinkedIn Sales Navigator + cold email automation",
     "50 qualified prospects/week", "ROLLING WEEKLY", "CRITICAL"),
    (7, "SBD", "Proposal Factory",
     "Library of 50 customizable proposal templates covering all 10 service categories",
     "Tailored proposal within 2 hours of any client inquiry",
     "ROLLING WEEKLY", "CRITICAL"),
    (8, "SBD", "Partnership Network",
     "Reseller/referral partnerships with 10 IT agencies per quarter; offer 15% commission on referred projects",
     "10 partnerships/quarter", "ROLLING WEEKLY", "CRITICAL"),
    (9, "SBD", "Tender & RFP Monitoring",
     "Daily monitoring of government procurement portals in Bangladesh, UAE, UK, Australia",
     "Proposal submitted within 24h of publication",
     "ROLLING WEEKLY", "CRITICAL"),
    (10, "SBD", "Retainer Conversion",
     "Convert one-time project clients into monthly retainers (Digital Marketing, IT Support AMC, Cloud Management)",
     "30% conversion; 20 active retainer clients within 6 months",
     "ROLLING WEEKLY", "CRITICAL"),

    (11, "AIT", "AI Product Suite Launch",
     "3 proprietary AI SaaS products: AI Chatbot Builder, AI Content Generator, AI Business Analytics Dashboard",
     "3 SaaS products launched, global subscription pricing",
     "QUARTERLY MILESTONES", "HIGH"),
    (12, "AIT", "Marketing Automation Stack",
     "Integrated AI stack for lead scoring, email personalization, ad bid optimization, social scheduling",
     "Reduce manual marketing effort by 70%",
     "QUARTERLY MILESTONES", "HIGH"),
    (13, "AIT", "Client AI Pilots",
     "Free 30-day AI chatbot trials to 20 enterprise prospects per month",
     "25% trial-to-paid conversion rate", "QUARTERLY MILESTONES", "HIGH"),
    (14, "AIT", "Data Analytics Dashboard",
     "Real-time CEO intelligence dashboard: traffic, ad ROI, lead pipeline, delivery status, NPS, revenue forecast",
     "Dashboard live with all 6 modules", "QUARTERLY MILESTONES", "HIGH"),

    (15, "CSR", "NPS Program",
     "Monthly Net Promoter Score surveys for all active clients; CEO reviews every score below 50 personally within 48h",
     "NPS 70+", "ROLLING MONTHLY", "HIGH"),
    (16, "CSR", "Upsell Automation",
     "Automated upsell sequences for clients using only 1-2 services, with complementary-service ROI projections",
     "40% upsell acceptance rate", "ROLLING MONTHLY", "HIGH"),
    (17, "CSR", "24/7 Support Excellence",
     "Sub-2-hour response SLA for all support tickets; monthly service uptime reports published",
     "99.9% uptime, <2h response SLA", "ROLLING MONTHLY", "HIGH"),
    (18, "CSR", "Client Community Hub",
     "SCITBD client portal: knowledge base, project tracking, invoice management, community forum",
     "Launch target: Q2 2026", "ROLLING MONTHLY", "HIGH"),

    (19, "FGI", "Revenue Target Architecture",
     "Set and cascade quarterly revenue targets per service line. Q1 $250K, Q2 $400K, year-end $1.5M USD",
     "Year-end $1.5M USD", "MONTHLY REPORTING", "CRITICAL"),
    (20, "FGI", "Pricing Intelligence",
     "Bi-annual competitive pricing audits vs top 10 competing IT firms (South Asia + target markets)",
     "Value-premium positioning maintained", "MONTHLY REPORTING", "CRITICAL"),
    (21, "FGI", "CAC & LTV Tracking",
     "Track Customer Acquisition Cost and Lifetime Value per market segment",
     "LTV:CAC ratio of 5:1 minimum", "MONTHLY REPORTING", "CRITICAL"),
    (22, "FGI", "Profitability Dashboard",
     "Monthly P&L summaries per service category; flag underperforming lines for CEO strategic review",
     "Monthly P&L per service category", "MONTHLY REPORTING", "CRITICAL"),
]

# ══════════════════════════════════════════════════════════
# SECTION 5 — KPIs (10)
# ══════════════════════════════════════════════════════════
KPIS = [
    (1,  "Monthly Revenue (USD)",          "Baseline", "$200K",     "$500K"),
    (2,  "New Leads Generated / Month",    "Baseline", "200",       "500+"),
    (3,  "Website Organic Traffic / Month","Baseline", "25,000",    "100,000"),
    (4,  "LinkedIn Followers",             "Baseline", "5,000",     "25,000"),
    (5,  "Active Retainer Clients",        "Baseline", "20",        "60"),
    (6,  "Countries with Active Projects", "30+",      "40+",       "60+"),
    (7,  "Client NPS Score",               "Baseline", "65+",       "75+"),
    (8,  "Google Ad ROAS",                 "Baseline", "4x",        "8x"),
    (9,  "Proposal-to-Win Conversion Rate","Baseline", "30%",       "45%"),
    (10, "Employee Headcount",             "Baseline", "+15 Hires", "+40 Hires"),
]

# ══════════════════════════════════════════════════════════
# SECTION 6 — PROMPT COMMAND LIBRARY (7)
# ══════════════════════════════════════════════════════════
PROMPT_LIBRARY = [
    ("marketing", 1,
     "As SCITBD CEO, write a LinkedIn post that positions our AI & Data "
     "Solutions service as the top choice for healthcare companies in the UAE. "
     "Include a specific pain point, our solution, a client outcome, and a "
     "CTA. Tone: Executive authority."),
    ("marketing", 2,
     "Generate a 7-email drip campaign targeting EdTech startups in the UK who "
     "need an LMS solution. Each email should have a subject line, body with 1 "
     "CTA, and a P.S. line. Increase urgency from email 1 to email 7."),
    ("marketing", 3,
     "Write 5 Google Search Ad copies for our Cybersecurity & IT Support "
     "service targeting small businesses in Australia. Include keywords, "
     "headlines (max 30 chars), and descriptions (max 90 chars)."),
    ("business_development", 1,
     "As SCITBD CEO, write a cold outreach message for a German logistics "
     "company that needs ERP and custom software. Reference their industry "
     "challenges, our track record in 30+ countries, and offer a free "
     "consultation. Keep it under 150 words."),
    ("business_development", 2,
     "Generate a competitive analysis of SCITBD vs. top 3 IT firms in "
     "Bangladesh targeting international clients. Present strengths, "
     "weaknesses, opportunities, and recommended differentiators for SCITBD."),
    ("operations_strategy", 1,
     "Create a 30-60-90 day onboarding plan for a new Head of Marketing at "
     "SCITBD. Include weekly milestones, tools to master, campaigns to launch, "
     "and first KPI targets."),
    ("operations_strategy", 2,
     "As SCITBD CEO, draft a quarterly OKR (Objectives & Key Results) document "
     "for the Sales Division with 3 objectives and 4 key results each. Align "
     "with the target of $500K USD revenue in 6 months."),
]

# ══════════════════════════════════════════════════════════
# SECTION 7 — ESCALATIONS (5) + DECISION MATRIX (7)
# ══════════════════════════════════════════════════════════
ESCALATIONS = [
    ("Any lead worth $10,000+ USD must receive a personalized CEO video "
     "message within 24 hours.", "24 hours"),
    ("Any client NPS score below 40 triggers a CEO emergency meeting within "
     "48 hours.", "48 hours"),
    ("Any campaign with negative ROAS for 3 consecutive days is paused and "
     "escalated to CEO for review.", "3 consecutive days"),
    ("Any negative press mention or social media controversy is escalated "
     "within 1 hour with a draft response.", "1 hour"),
    ("Any project deadline overrun beyond 5 days triggers a CEO client "
     "communication within 24 hours.", "24 hours"),
]

DECISIONS = [
    ("Contracts under $5,000", "Division Lead",
     "Approve and execute within 4 hours"),
    ("Contracts $5,000 - $50,000", "CEO Approval",
     "Review proposal, sign, and assign team within 24 hours"),
    ("Contracts above $50,000", "CEO + Board Notify",
     "Full due diligence, custom contract, senior team assigned"),
    ("Partnership Agreements", "CEO Approval",
     "Legal review + CEO signing within 72 hours"),
    ("Budget Reallocation > $2,000", "CEO Approval",
     "Justify with data, approve and document within 48 hours"),
    ("New Market Entry Decision", "CEO + Strategy Team",
     "Market research brief required before approval"),
    ("Hiring Senior Roles", "CEO Final Approval",
     "2-round interviews, CEO final interview mandatory"),
]


# ══════════════════════════════════════════════════════════
# INSTALL / SEED
# ══════════════════════════════════════════════════════════
def install() -> dict:
    con = connect()
    con.executescript(SCHEMA)

    # --- profile (singleton) ---
    con.execute(
        """INSERT OR IGNORE INTO ceo_agent_profile
           (id, company_name, company_short, company_type, headquarters,
            operational_reach, projects_delivered, currencies, languages,
            compliance, classification, issued, persona_rules,
            master_system_prompt, closing_directive)
           VALUES (1,?,?,?,?,?,?,?,?,?,?,?,?,?,?)""",
        (IDENTITY["company_name"], IDENTITY["company_short"],
         IDENTITY["company_type"], IDENTITY["headquarters"],
         IDENTITY["operational_reach"], IDENTITY["projects_delivered"],
         IDENTITY["currencies"], IDENTITY["languages"],
         IDENTITY["compliance"], IDENTITY["classification"],
         IDENTITY["issued"], PERSONA_RULES, MASTER_SYSTEM_PROMPT, CLOSING))
    con.execute(
        """UPDATE ceo_agent_profile SET
             company_name=?, company_short=?, company_type=?, headquarters=?,
             operational_reach=?, projects_delivered=?, currencies=?,
             languages=?, compliance=?, classification=?, issued=?,
             persona_rules=?, master_system_prompt=?, closing_directive=?,
             loaded_at=datetime('now','localtime')
           WHERE id=1""",
        (IDENTITY["company_name"], IDENTITY["company_short"],
         IDENTITY["company_type"], IDENTITY["headquarters"],
         IDENTITY["operational_reach"], IDENTITY["projects_delivered"],
         IDENTITY["currencies"], IDENTITY["languages"],
         IDENTITY["compliance"], IDENTITY["classification"],
         IDENTITY["issued"], PERSONA_RULES, MASTER_SYSTEM_PROMPT, CLOSING))

    # --- services / markets ---
    con.executemany(
        """INSERT INTO directive_services
           (portfolio_no, service_name, category)
           VALUES (?,?,?)
           ON CONFLICT(service_name) DO UPDATE SET
             portfolio_no=excluded.portfolio_no, category=excluded.category""",
        SERVICES)
    con.executemany(
        """INSERT INTO directive_markets (market_name) VALUES (?)
           ON CONFLICT(market_name) DO NOTHING""", [(m,) for m in MARKETS])

    # --- UTC blocks (map onto BST blocks) ---
    # Idempotent: older installs ran a bare INSERT, so dedupe any rows
    # duplicated by re-installation before upserting the canonical four.
    # The unique index is created AFTER the dedupe (it would fail on dupes
    # if it were part of SCHEMA, which runs first).
    con.execute(
        """DELETE FROM directive_utc_blocks
           WHERE id NOT IN (SELECT MIN(id) FROM directive_utc_blocks
                            GROUP BY utc_window)""")
    con.execute("CREATE UNIQUE INDEX IF NOT EXISTS ux_utc_window "
                "ON directive_utc_blocks(utc_window)")
    for win, zone, tasks, bst, bid in UTC_BLOCKS:
        con.execute(
            """INSERT INTO directive_utc_blocks
               (utc_window, zone_focus, tasks, bst_window, bst_block_id)
               VALUES (?,?,?,?,?)
               ON CONFLICT(utc_window) DO UPDATE SET
                 zone_focus=excluded.zone_focus, tasks=excluded.tasks,
                 bst_window=excluded.bst_window,
                 bst_block_id=excluded.bst_block_id""",
            (win, zone, tasks, bst, bid))

    # --- engines ---
    con.executemany(
        """INSERT INTO directive_engines
           (engine_code, engine_name, cadence, description)
           VALUES (?,?,?,?)
           ON CONFLICT(engine_code) DO UPDATE SET
             engine_name=excluded.engine_name, cadence=excluded.cadence,
             description=excluded.description""", ENGINES)

    # --- divisions ---
    for code, name, prio, dl in DIVISIONS:
        n = len([t for t in TASKS if t[1] == code])
        con.execute(
            """INSERT INTO directive_divisions
               (division_code, division_name, priority, deadline, task_count)
               VALUES (?,?,?,?,?)
               ON CONFLICT(division_code) DO UPDATE SET
                 division_name=excluded.division_name,
                 priority=excluded.priority, deadline=excluded.deadline,
                 task_count=excluded.task_count""",
            (code, name, prio, dl, n))

    # --- 22 tasks ---
    for no, div, title, deliver, kpi, dl, prio in TASKS:
        con.execute(
            """INSERT INTO directive_tasks
               (task_no, division_code, task_title, deliverable, kpi,
                deadline, priority)
               VALUES (?,?,?,?,?,?,?)
               ON CONFLICT(task_no) DO UPDATE SET
                 division_code=excluded.division_code,
                 task_title=excluded.task_title,
                 deliverable=excluded.deliverable, kpi=excluded.kpi,
                 deadline=excluded.deadline, priority=excluded.priority""",
            (no, div, title, deliver, kpi, dl, prio))

    # --- KPIs ---
    con.executemany(
        """INSERT INTO directive_kpis
           (kpi_order, metric, baseline, target_6m, target_12m)
           VALUES (?,?,?,?,?)
           ON CONFLICT(metric) DO UPDATE SET
             kpi_order=excluded.kpi_order, baseline=excluded.baseline,
             target_6m=excluded.target_6m, target_12m=excluded.target_12m""",
        KPIS)

    # --- prompt library ---
    con.executemany(
        """INSERT INTO directive_prompt_lib (category, seq, prompt_text)
           VALUES (?,?,?)
           ON CONFLICT(category, seq) DO UPDATE SET
             prompt_text=excluded.prompt_text""", PROMPT_LIBRARY)

    # --- escalations / decisions / section 8 ---
    con.executemany(
        """INSERT INTO directive_escalations (trigger_text, sla)
           VALUES (?,?)
           ON CONFLICT(trigger_text) DO UPDATE SET sla=excluded.sla""",
        ESCALATIONS)
    con.executemany(
        """INSERT INTO directive_decisions
           (decision_type, authority, action_required)
           VALUES (?,?,?)
           ON CONFLICT(decision_type) DO UPDATE SET
             authority=excluded.authority,
             action_required=excluded.action_required""", DECISIONS)
    con.execute(
        """INSERT INTO directive_section8 (id, body) VALUES (1,?)
           ON CONFLICT(id) DO UPDATE SET body=excluded.body""", (CLOSING,))

    con.commit()
    counts = {t: con.execute(f"SELECT COUNT(*) FROM {t}").fetchone()[0]
              for t in ("ceo_agent_profile", "directive_services",
                        "directive_markets", "directive_utc_blocks",
                        "directive_engines", "directive_divisions",
                        "directive_tasks", "directive_kpis",
                        "kpi_measurements", "directive_prompt_lib",
                        "directive_escalations",
                        "directive_decisions", "directive_section8")}
    con.close()
    return counts


# ══════════════════════════════════════════════════════════
# AUTOFLOW BRIDGE: directive tasks -> daily_tasks (assignee=ai_agent)
# ══════════════════════════════════════════════════════════
CATEGORY_BY_DIV = {"MKT": "marketing", "SBD": "sales", "AIT": "ai",
                   "CSR": "client", "FGI": "operations"}


def _active_block(hhmm: str) -> int:
    if hhmm < "06:00":
        return 4
    if hhmm < "12:00":
        return 1
    if hhmm < "18:00":
        return 2
    return 3


def push_to_daily(days: int = 1) -> dict:
    """
    Materialise today's slice of the Master Directive into daily_tasks so the
    24/7 BST autoflow actually executes them. Idempotent on (title, due_date).
    """
    now = datetime.datetime.now(BST)
    d = now.date().isoformat()
    hhmm = now.strftime("%H:%M")
    block = _active_block(hhmm)
    con = connect()

    created, existing = [], []
    for no, div, title, deliver, kpi, dl, prio in TASKS:
        dt_title = f"[Directive #{no}] {title}"
        due = f"{d} {hhmm}"
        row = con.execute(
            "SELECT id FROM daily_tasks WHERE task_title=? AND due_date=?",
            (dt_title, due)).fetchone()
        if row:
            existing.append(row["id"])
            continue
        desc = (f"Assigned by SCITBD CEO Master AI Directive (Sec 4 / {div}).\n"
                f"Deliverable: {deliver}\nKPI: {kpi}\nDeadline: {dl}")
        cur = con.execute(
            """INSERT INTO daily_tasks
               (task_title, task_description, priority, status, category,
                assignee, due_date, estimated_hours, bst_block_id, created_by)
               VALUES (?,?,?,?,?,?,?,?,?,?)""",
            (dt_title, desc, prio.lower(), "pending",
             CATEGORY_BY_DIV.get(div, "general"), "ai_agent", due,
             1.0, block, "ai_agent"))
        tid = cur.lastrowid
        con.execute("UPDATE directive_tasks SET daily_task_id=? WHERE task_no=?",
                    (tid, no))
        con.execute(
            """INSERT INTO task_logs (task_id, action, performed_by, details)
               VALUES (?,?,?,?)""",
            (tid, "assigned", "ai_agent",
             f"Master AI Directive task {no} ({div}) pushed to autoflow"))
        created.append({"id": tid, "no": no, "title": title, "div": div})

    con.commit()
    con.close()
    return {"date": d, "block": block,
            "created": len(created), "existing": len(existing), "items": created}


# ══════════════════════════════════════════════════════════
# READERS / CLI
# ══════════════════════════════════════════════════════════
def get_profile() -> dict:
    con = connect()
    r = con.execute("SELECT * FROM ceo_agent_profile WHERE id=1").fetchone()
    con.close()
    return dict(r) if r else {}


def get_prompt() -> str:
    return get_profile().get("master_system_prompt", "")


def show_sections() -> str:
    con = connect()
    L = ["SCITBD CEO AI AGENT — MASTER AI DIRECTIVE (loaded)",
         "=" * 66]
    p = con.execute("SELECT * FROM ceo_agent_profile WHERE id=1").fetchone()
    if p:
        L += [f"S1  Identity   : {p['company_name']}",
              f"                {p['company_type']} | {p['operational_reach']}",
              f"                HQ {p['headquarters']} | {p['compliance']}",
              f"                Classification {p['classification']} ({p['issued']})",
              f"                Persona rules: "
              f"{len(p['persona_rules'].splitlines())}",
              f"S2  System prompt: "
              f"{len(p['master_system_prompt'])} chars"]
    n = lambda t: con.execute(f"SELECT COUNT(*) FROM {t}").fetchone()[0]
    L += [f"S2  Services    : {n('directive_services')} | "
          f"Markets: {n('directive_markets')}",
          f"S3  UTC blocks  : {n('directive_utc_blocks')} | "
          f"Engines: {n('directive_engines')}",
          f"S4  Divisions   : {n('directive_divisions')} | "
          f"Tasks: {n('directive_tasks')}",
          f"S5  KPIs        : {n('directive_kpis')}",
          f"S6  Prompt lib  : {n('directive_prompt_lib')}",
          f"S7  Escalations : {n('directive_escalations')} | "
          f"Decision rows: {n('directive_decisions')}",
          f"S8  Closing     : {n('directive_section8')}",
          "=" * 66]
    L.append("DIVISIONS / TASKS:")
    for r in con.execute(
            "SELECT division_code, division_name, priority, deadline, "
            "task_count FROM directive_divisions ORDER BY id"):
        L.append(f"  [{r['division_code']}] {r['division_name']} "
                 f"| {r['priority']} | {r['deadline']} | {r['task_count']} tasks")
        for t in con.execute(
                "SELECT task_no, task_title, kpi FROM directive_tasks "
                "WHERE division_code=? ORDER BY task_no",
                (r["division_code"],)):
            L.append(f"      {t['task_no']:>2}. {t['task_title']} "
                     f"— KPI: {t['kpi']}")
    L.append("UTC -> BST CYCLE:")
    for r in con.execute("SELECT * FROM directive_utc_blocks ORDER BY id"):
        L.append(f"  {r['utc_window']} ({r['zone_focus']}) "
                 f"= {r['bst_window']} [Block {r['bst_block_id']}]")
    con.close()
    return "\n".join(L)


def task_list() -> str:
    con = connect()
    L = ["SCITBD MASTER DIRECTIVE — 22 CEO TASKS", "=" * 70]
    cur_div = None
    for r in con.execute(
            "SELECT t.*, d.division_name, d.priority AS dprio, d.deadline AS ddl "
            "FROM directive_tasks t JOIN directive_divisions d "
            "ON d.division_code=t.division_code ORDER BY t.task_no"):
        if r["division_code"] != cur_div:
            cur_div = r["division_code"]
            L += ["", f"{r['division_name']} "
                      f"[{r['dprio']} | {r['ddl']}]", "-" * 70]
        L.append(f"  {r['task_no']:>2}. {r['task_title']}")
        L.append(f"      Deliverable: {r['deliverable']}")
        L.append(f"      KPI: {r['kpi']}  |  Deadline: {r['deadline']}  "
                 f"|  Priority: {r['priority']}  |  {r['status']}"
                 + (f"  -> daily_task #{r['daily_task_id']}"
                    if r["daily_task_id"] else ""))
    con.close()
    return "\n".join(L)


def kpi_table() -> str:
    con = connect()
    L = ["SCITBD NON-NEGOTIABLE KPIs", "=" * 70,
         f"{'#':<3}{'METRIC':<36}{'CURRENT':<11}{'6-MONTH':<13}{'12-MONTH'}"]
    L.append("-" * 70)
    for r in con.execute(
            "SELECT * FROM directive_kpis ORDER BY kpi_order"):
        L.append(f"{r['kpi_order']:<3}{r['metric'][:34]:<36}"
                 f"{r['baseline']:<11}{r['target_6m']:<13}{r['target_12m']}")
    L.append("=" * 70)
    L.append("Escalation: divisions missing KPIs 2 consecutive weeks "
             "receive a CEO escalation notice.")
    con.close()
    return "\n".join(L)


def prompt_library(category: str | None = None) -> str:
    con = connect()
    L = ["SCITBD CEO PROMPT COMMAND LIBRARY", "=" * 70]
    q = ("SELECT * FROM directive_prompt_lib")
    args: tuple = ()
    if category:
        q += " WHERE category=?"
        args = (category,)
    q += " ORDER BY category, seq"
    cur = None
    for r in con.execute(q, args):
        if r["category"] != cur:
            cur = r["category"]
            L += ["", f"[{cur.upper()}]", "-" * 70]
        L.append(f"  {r['seq']}. {r['prompt_text']}")
    con.close()
    return "\n".join(L)


def escalations_and_decisions() -> str:
    con = connect()
    L = ["SECTION 7 — CEO ESCALATION & OVERRIDE PROTOCOLS", "=" * 70,
         "7.1 ESCALATION TRIGGERS"]
    for i, r in enumerate(con.execute(
            "SELECT * FROM directive_escalations ORDER BY id"), 1):
        L.append(f"  {i}. {r['trigger_text']}")
        L.append(f"     SLA: {r['sla']}")
    L += ["", "7.2 CEO DECISION AUTHORITY MATRIX", "-" * 70,
          f"  {'DECISION TYPE':<34}{'AUTHORITY':<22}ACTION"]
    for r in con.execute("SELECT * FROM directive_decisions ORDER BY id"):
        L.append(f"  {r['decision_type'][:32]:<34}"
                 f"{r['authority'][:20]:<22}{r['action_required']}")
    con.close()
    return "\n".join(L)


def engines_and_blocks() -> str:
    con = connect()
    L = ["SECTION 3 — 24/7 AI MARKETING OPERATIONS FRAMEWORK", "=" * 70,
         "3.1 DAILY OPERATIONAL CYCLE (recurring every 24 hours)"]
    for r in con.execute("SELECT * FROM directive_utc_blocks ORDER BY id"):
        L.append(f"  {r['utc_window']} | {r['zone_focus']} "
                 f"| BST {r['bst_window']} [Block {r['bst_block_id']}]")
        L.append(f"      {r['tasks']}")
    L += ["", "3.2 AUTONOMOUS MARKETING ENGINES", "-" * 70]
    for r in con.execute("SELECT * FROM directive_engines ORDER BY id"):
        L.append(f"  [{r['engine_code']}] {r['engine_name']} "
                 f"— {r['cadence']}")
        L.append(f"      {r['description']}")
    con.close()
    return "\n".join(L)


def main() -> None:
    cmd = sys.argv[1] if len(sys.argv) > 1 else "install"
    arg = sys.argv[2] if len(sys.argv) > 2 else None

    if cmd == "install":
        c = install()
        print("Master AI Directive installed:")
        for k, v in c.items():
            print(f"   {k:<26} {v}")
    elif cmd == "show":
        print(show_sections())
    elif cmd == "prompt":
        print(get_prompt())
    elif cmd == "tasks":
        print(task_list())
    elif cmd == "kpis":
        print(kpi_table())
    elif cmd == "library":
        print(prompt_library(arg))
    elif cmd in ("section7", "escalations"):
        print(escalations_and_decisions())
    elif cmd in ("section3", "engines"):
        print(engines_and_blocks())
    elif cmd == "autoflow":
        r = push_to_daily()
        print(f"pushed to daily_tasks for {r['date']} (block {r['block']}): "
              f"{r['created']} created, {r['existing']} already present")
        for x in r["items"]:
            print(f"   + #{x['id']} [{x['div']}] {x['title']}")
    else:
        print(__doc__)


if __name__ == "__main__":
    main()
