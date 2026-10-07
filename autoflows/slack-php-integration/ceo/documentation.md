# SCITBD CEO Daily Task Management — Complete Documentation

> **Version:** 2.0 · **Author:** SCITBD / Md Shoeb Lincoln · **Timezone:** Asia/Dhaka (BST, UTC+6, no DST)
> **Database:** `scitbd_ceo.db` (SQLite) · **Runtime:** Python 3 + SQLite + Windows Task Scheduler
> **Last verified:** 2026-10-05 21:44 BST · **Active Block:** Block 3 (18:00–00:00 BST)

---

## Table of Contents

1. [Overview](#1-overview)
2. [Current Operational State](#2-current-operational-state)
3. [24-Hour BST Operational Blocks](#3-24-hour-bst-operational-blocks)
4. [System Architecture](#4-system-architecture)
5. [Database Reference](#5-database-reference)
6. [Core Modules](#6-core-modules)
7. [Daily Task Lifecycle](#7-daily-task-lifecycle)
8. [CEO Agent Workflow (6 Steps)](#8-ceo-agent-workflow-6-steps)
9. [Master AI Directive](#9-master-ai-directive)
10. [Content Engine](#10-content-engine)
11. [Alerting Stack](#11-alerting-stack)
12. [Scheduler & Automation (.bat + ICS)](#12-scheduler--automation-bat--ics)
13. [CLI Reference](#13-cli-reference)
14. [Dashboard & CRM Frontend](#14-dashboard--crm-frontend)
15. [SLA & Escalation Reference](#15-sla--escalation-reference)
16. [KPI Reference](#16-kpi-reference)
17. [Setup & Install Guide](#17-setup--install-guide)
18. [Extending the System](#18-extending-the-system)
19. [Troubleshooting](#19-troubleshooting)
20. [FAQ](#20-faq)

---

## 1. Overview

The **SCITBD Daily Task Management AI Agent** is the executable operating system for SCITBD (Social Communication IT Bangladesh) — a 24/7 autonomous CEO layer that:

- Resolves the current **BST operational block** from the Asia/Dhaka clock (never OS local time).
- Materialises **today's `daily_tasks`** from 16 recurring `block_tasks` slots + 22 Master Directive tasks + 5 Content Engine tasks.
- Tracks every mutation in **`task_logs`** + **`operational_logs`**.
- Generates **CEO briefings, KPI snapshots, escalation scans, content bundles, email digests, and Slack/WhatsApp/SMS alerts**.
- Runs headless via Windows Task Scheduler (`.bat` triggers) and serves a live HTML dashboard + PHP CRM.

**Design principles:**

```
BST is truth (Asia/Dhaka, UTC+6, fixed offset)
Idempotent seeds (re-run safe, ON CONFLICT upserts)
Every mutation is logged (task_logs / operational_logs / alert_dispatch_log)
No silent passes (missing feeds reported as no-data, not OK)
```

**Folder (canonical):**

```
D:/CRM_with_GOOGLE_Sheet/ceo/ceo - Copy/
  scitbd_ceo.db              # live SQLite database (32 tables)
  schema.sql                 # base tables: leads, operational_blocks, operational_logs, tickets
  scitbd_ceo_directive.py    # Master AI Directive installer (Sections 1-8)
  scitbd_ceo_agent.py        # CEO runtime: briefing, KPIs, escalations, assign_task, engines
  scitbd_block_scheduler.py  # Block clock: sync_block_status, get_current_block, get_live_slot
  seed_operational_plan.py   # 16 execution slots + 6 SLA standards seeder
  scitbd_content_engine.py   # Daily content/ads/email-digest engine
  scitbd_alerting.py         # Slack/WhatsApp/SMS alerts, retry queue, canary
  export_schedule_ics.py     # ICS export (scitbd_schedule.ics)
  dashboard.html / index.php # Human UI
  content/<date>/            # Generated bundles (json/md)
  emails/ logs/ data/        # Digests, logs, artefacts
  run_*.bat / register_*.bat # Scheduler entry points
  scitbd_schedule.ics        # Calendar import
```

---

## 2. Current Operational State

> Auto-verified at build time — `2026-10-05 21:44:18 BST (Monday)`.

| Signal | Value |
|---|---|
| **Now (BST)** | 2026-10-05 21:44 BST, Asia/Dhaka |
| **Active Block** | **Block 3: 18:00 – 00:00 BST \| UK / US East Coast — ACTIVE** |
| **Region / Focus** | UK / US East Coast / North America Peak Launch & High-Value Deals |
| **UTC window** | 12:00 – 18:00 UTC |
| **Next Block** | Block 4 (00:00–06:00 BST) — UPCOMING |
| **daily_tasks** | 105 total · **0 pending** (all caught up) |
| **task_logs** | 243 entries |
| **Tables** | 32 tables in `scitbd_ceo.db` |

### Pending Tasks — Current Block

| # | Task | Priority | Category | Status |
|---|---|---|---|---|
| — | _No pending tasks — queue is clear for Block 3_ | — | — | — |

> When pending > 0, this table is populated from:
> ```sql
> SELECT id, task_title, priority, category, status
> FROM daily_tasks WHERE status='pending'
> ORDER BY CASE priority WHEN 'critical' THEN 0 WHEN 'high' THEN 1 ELSE 2 END, id LIMIT 15;
> ```

---

## 3. 24-Hour BST Operational Blocks

All time is **Asia/Dhaka (BST = UTC+6)**. Mapping rule (`resolve_block_id` / `_active_block`):

```
HH:MM < 06:00 → Block 4
HH:MM < 12:00 → Block 1
HH:MM < 18:00 → Block 2
else          → Block 3
```

| ID | BST Window | UTC Window | Regional Focus | Core Execution Focus | Status Model |
|---|---|---|---|---|---|
| **1** | 06:00 – 12:00 | 00:00 – 06:00 | BD / South Asia | SEO, Local Tenders & BD Pipeline | ACTIVE / COMPLETED / UPCOMING / INACTIVE |
| **2** | 12:00 – 18:00 | 06:00 – 12:00 | Middle East / EU | ME & EU Sales Outreach & Ad Audits | same |
| **3** | 18:00 – 00:00 | 12:00 – 18:00 | UK / US East Coast | North America Peak Launch & High-Value Deals | same |
| **4** | 00:00 – 06:00 | 18:00 – 24:00 | US West / Oceania | Night Analytics, Oceania Launch & Reboot | same |

`sync_block_status()` guarantees **exactly one ACTIVE**, one UPCOMING (next chronological), previous ACTIVE → COMPLETED.

### 16 Execution Slots (from `seed_operational_plan.py`)

**Block 1 — South Asia & Domestic:**

| Slot (BST) | Slot Name | Priority | Category |
|---|---|---|---|
| 06:00–07:00 | SEO & Local Content Deploy | high | marketing |
| 07:00–09:00 | Domestic & Regional RFP Scrapers | critical | sales |
| 09:00–11:00 | BD Lead Nurturing & Pipeline | medium | sales |
| 11:00–12:00 | Brand SLA & Review Audit | critical | client |

**Block 2 — Middle East / EU:**

| Slot (BST) | Slot Name | Priority | Category |
|---|---|---|---|
| 12:00–13:30 | Ad Engine Performance Audit | high | marketing |
| 13:30–16:00 | Middle East & European B2B Prospecting | high | sales |
| 16:00–17:30 | European Content Engine Execution | medium | marketing |
| 17:30–18:00 | Retainer & Upsell Automations | medium | sales |

**Block 3 — UK / North America:**

| Slot (BST) | Slot Name | Priority | Category |
|---|---|---|---|
| 18:00–19:30 | US Market Campaign Launch | high | marketing |
| 19:30–22:00 | High-Value Proposals & Direct Pipeline | critical | sales |
| 22:00–23:30 | US Content & Video Engine | high | marketing |
| 23:30–00:00 | Client Support & System Health Audit | critical | operations |

**Block 4 — Oceania & Global Reboot:**

| Slot (BST) | Slot Name | Priority | Category |
|---|---|---|---|
| 00:00–02:00 | Daily Campaign Optimization | high | marketing |
| 02:00–04:00 | Oceania Market Operations | high | sales |
| 04:00–05:30 | Daily CEO Intelligence Summary | critical | admin |
| 05:30–06:00 | System Flush & Reset | high | operations |

Each slot carries `detailed_tasks`, `expected_outcome`, `escalation_protocol`, `is_recurring=1`.

Pre-block Slack alerts fire at **05:45, 11:45, 17:45, 23:45 BST** (15 min before each block).

---

## 4. System Architecture

```
                ┌─────────────────────────────┐
                │  Windows Task Scheduler     │
                │  run_block_sync / preblock  │
                │  retry / canary (.bat)      │
                └──────────────┬──────────────┘
                               ▼
Browser (dashboard.html/index.php) ◄──► Python CEO Runtime
  human review / select links            scitbd_ceo_agent.py
        │                               scitbd_block_scheduler.py
        │                               scitbd_ceo_directive.py
        ▼                               scitbd_content_engine.py
  scitbd_ceo.db (SQLite, 32 tables) ◄── scitbd_alerting.py
        │                               seed_operational_plan.py
        ├── daily_tasks + task_logs (audit)
        ├── operational_blocks + block_tasks + operational_logs
        ├── directive_* (CEO knowledge base)
        ├── content_items + ad_models + ad_features
        ├── channel_health + alert_retry_queue + alert_dispatch_log
        └── leads / tickets / kpi_measurements
                               ▼
              Slack / WhatsApp (UltraMsg) / Twilio SMS
              Email digest (HTML) + content/<date>/ files
              ICS calendar (scitbd_schedule.ics)
```

---

## 5. Database Reference

**File:** `D:/CRM_with_GOOGLE_Sheet/ceo/ceo - Copy/scitbd_ceo.db`

### 5.1 Task & Operations Core

**`daily_tasks`** (105 rows) — the live work queue:

| Column | Type | Notes |
|---|---|---|
| `id` | INTEGER PK | autoincrement |
| `task_title` | TEXT NOT NULL | e.g. `[Directive #6] International Client Acquisition` or slot name |
| `task_description` | TEXT | block window + detailed tasks + outcome + escalation |
| `priority` | TEXT | `critical` / `high` / `medium` / `low` |
| `status` | TEXT | `pending` / `in_progress` / `done` (+ legacy PENDING/COMPLETED variants) |
| `category` | TEXT | `sales` / `marketing` / `operations` / `client` / `admin` / `ai` / `general` |
| `assignee` | TEXT | `ceo` (block slots) / `ai_agent` (directive + content tasks) |
| `due_date` | TEXT | `YYYY-MM-DD HH:MM` (BST) |
| `estimated_hours` / `actual_hours` | REAL | slot duration derived from slot window |
| `bst_block_id` | INTEGER | FK → operational_blocks.id (1–4) |
| `related_lead_id` / `related_ticket_id` | INTEGER | optional CRM links |
| `created_by` | TEXT | `ai_agent` / `ceo_ai_agent` |
| `created_at` / `updated_at` / `completed_at` | DATETIME | lifecycle timestamps |

**`task_logs`** (243 rows) — every mutation:

```
id | task_id FK | action (assigned/created/completed/operational_plan_seeded/...)
   | performed_by | details | created_at
```

**`operational_blocks`** (4 rows) — see Section 3.

**`block_tasks`** (16 rows) — recurring slots:

```
id | block_id FK | slot_start | slot_end | slot_name | detailed_tasks
   | expected_outcome | priority | category | escalation_protocol | is_recurring
```

**`operational_logs`** — block syncs + engine dispatches + alert fires (`SUCCESS`/`PENDING`/`FAILED`).

**`sla_standards`** (6 rows) — Section 15.

### 5.2 Master Directive Tables (from `scitbd_ceo_directive.py`)

| Table | Rows | Purpose |
|---|---|---|
| `ceo_agent_profile` | 1 | identity + `master_system_prompt` + `persona_rules` + `closing_directive` |
| `directive_services` | 17 | service portfolio knowledge |
| `directive_markets` | 7 | Public Sector, Education, Healthcare, Commerce/Retail, Non-Profits, Enterprises, SaaS Startups |
| `directive_utc_blocks` | 4 | UTC windows → BST windows + mandates |
| `directive_engines` | 6 | CONTENT, LEAD, AD, EMAIL, REVIEW, ANALYTICS |
| `directive_divisions` | 5 | MKT, SBD, AIT, CSR, FGI |
| `directive_tasks` | 22 | CEO growth tasks with KPI + deadline + `daily_task_id` bridge |
| `directive_kpis` | 10 | non-negotiable KPIs |
| `kpi_measurements` | — | latest measured values per KPI (feeds progress %) |
| `directive_prompt_lib` | 7 | ready-to-execute prompts (marketing / business_development / operations_strategy) |
| `directive_escalations` | 5 | Section 7.1 triggers + SLA |
| `directive_decisions` | 7 | Section 7.2 authority matrix |
| `directive_section8` | 1 | closing directive text |

### 5.3 Content & Ads

| Table | Rows | Purpose |
|---|---|---|
| `content_items` | 28+ | per-day `social`/`blog`/`email` rows (UNIQUE date+type+platform+seq), SEO score, file_path |
| `ad_models` | 8 | CPC, CPM, CPA, CPV, AFFILIATE, SPONSORED, PROGRAMMATIC (earning) + PPC (buying) |
| `ad_features` | 14 | display + earning placements per platform |

### 5.4 Alerting

| Table | Purpose |
|---|---|
| `channel_health` | `whatsapp` / `sms` scores, successes/failures, state HEALTHY/DEGRADED/DOWN |
| `alert_retry_queue` | exponential-backoff jobs (60s/5m/15m, 4 attempts), PENDING/IN_FLIGHT/DELIVERED/EXHAUSTED |
| `alert_dispatch_log` | every send/queue/exhaust event |
| `block_alert_state` | one fire per block per day (dedupe) |

### 5.5 CRM Mini Tables

| Table | Purpose |
|---|---|
| `leads` | `client_name, email, country, deal_value, service_line, status (new/proposal_sent/won/lost)` |
| `tickets` | `client_name, issue_description, nps_score, sla_status (WITHIN_SLA/ESCALATED_TO_CEO)` |
| `daily_activities`, `daily_summaries`, `task_dependencies` | supporting workflow tables |

---

## 6. Core Modules

### 6.1 `scitbd_ceo_agent.py` — CEO Runtime

| Function | What it does |
|---|---|
| `system_prompt(live_context)` | verbatim master prompt + auto-appended live BST block, UTC window, mandate, persona |
| `briefing()` | 7-section markdown briefing: directive tasks, AI work queue, content digest, KPI snapshot, escalations, engines, decision matrix |
| `kpi_status()` | latest measurement per KPI vs 6m/12m + progress % |
| `set_kpi(metric, value)` | record measurement |
| `escalate_scan()` | evaluate Section 7.1 triggers; missing feeds → `no-data` (never silent pass) |
| `assign_task(title, division, kpi, deadline, priority)` | CEO-authorised creation → `daily_tasks` + `task_logs`, deduped on (title, due) |
| `run_engine(code)` | dispatch CONTENT/LEAD/AD/EMAIL/REVIEW/ANALYTICS; CONTENT+EMAIL wired, ANALYTICS returns KPIs, others `not-wired` |
| `crm_summary()` | counts for leads/tickets/tasks |
| `briefing/kpis/escalate/engines/crm/assign/run` | CLI verbs (see Section 13) |

> Time rule: `now_bst()` = `datetime.now(Asia/Dhaka)` — never OS clock.

### 6.2 `scitbd_block_scheduler.py` — Block Clock

| Function | What it does |
|---|---|
| `resolve_block_id(hhmm)` | clock → block id (06:00 rule) |
| `get_current_block()` | active block + `hours_remaining`, `resolved_by=clock`, `is_flagged_active` |
| `get_live_slot()` | `block_tasks` row whose window contains now (midnight-cross aware) + `minutes_remaining` |
| `get_next_slot()` | next slot (rolls to tomorrow's block) |
| `sync_block_status(create_tasks=True)` | flip ACTIVE/UPCOMING/COMPLETED + idempotently materialise today's `daily_tasks` from slots + write both logs |
| `status_report()` | current + live + next + flags |

### 6.3 `scitbd_ceo_directive.py` — Directive Installer

Seeds Sections 1–8 idempotently (`ON CONFLICT` upserts). Key entry points: `install()`, `show_sections()`, `task_list()`, `kpi_table()`, `prompt_library()`, `escalations_and_decisions()`, `engines_and_blocks()`, `push_to_daily()` (bridge 22 tasks → `daily_tasks` as `[Directive #N]`).

### 6.4 `seed_operational_plan.py` — Slot Seeder

Creates `block_tasks` + `sla_standards`, refreshes 4 block rows, syncs ACTIVE to current BST, writes `operational_logs` + mirrored `task_logs` entry.

### 6.5 `scitbd_content_engine.py` — See Section 10.

### 6.6 `scitbd_alerting.py` — See Section 11.

---

## 7. Daily Task Lifecycle

```
directive_tasks (22, static knowledge)
   │ push_to_daily() / assign_task()
   ▼
block_tasks (16 recurring slots)
   │ sync_block_status() daily
   ▼
daily_tasks (pending → in_progress → done)
   │ every INSERT/UPDATE writes task_logs
   ▼
operational_logs (block-level audit)
```

- **Priorities:** `critical` > `high` > `medium` > `low`.
- **Categories:** `sales`, `marketing`, `development`, `operations`, `client`, `admin`, `ai`, `general`.
- **Assignees:** `ceo` (block execution), `ai_agent` (directive + content review).
- **Division → category map:** MKT→marketing, SBD→sales, AIT→ai, CSR→client, FGI→operations.
- **Dedup keys:** `(task_title, due_date)` and `(task_title, due_date, bst_block_id)` — re-runs skip, never duplicate.
- **Completion:** set `status='done'`, `completed_at=NOW`, log `completed`; recurring slots regenerate next day via sync.

**Status update pattern:**

```sql
UPDATE daily_tasks SET status='in_progress', updated_at=CURRENT_TIMESTAMP WHERE id=?;
INSERT INTO task_logs (task_id, action, performed_by, details) VALUES (?, 'started', 'ai_agent', ?);

UPDATE daily_tasks SET status='done', completed_at=CURRENT_TIMESTAMP WHERE id=?;
INSERT INTO task_logs (task_id, action, performed_by, details) VALUES (?, 'completed', 'ai_agent', ?);
```

---

## 8. CEO Agent Workflow (6 Steps)

> The agent MUST follow this order every operational turn.

### Step 1 — Determine the current BST operational block

```python
from scitbd_block_scheduler import get_current_block, get_live_slot
blk = get_current_block()   # includes now_bst, hours_remaining
slot = get_live_slot()      # live execution slot + minutes_remaining
```

or CLI: `python scitbd_block_scheduler.py status`.

### Step 2 — Check pending tasks in the current block

```sql
SELECT id, task_title, priority, category, status, due_date
FROM daily_tasks
WHERE bst_block_id = :active_block AND status = 'pending'
ORDER BY CASE priority WHEN 'critical' THEN 0 WHEN 'high' THEN 1 ELSE 2 END, id;
```

Structured markdown output (required):

| # | Task | Priority | Category | Status |
|---|---|---|---|---|
| … | … | … | … | … |

### Step 3 — Create new tasks (proper priority / category / due date)

```python
from scitbd_ceo_agent import assign_task
assign_task("Global Branding Overhaul", division="MKT", kpi="Unified identity", deadline="ROLLING MONTHLY", priority="critical")
```

Division must be one of `MKT|SBD|AIT|CSR|FGI`. Due date is `YYYY-MM-DD HH:MM` BST. Every creation writes `task_logs(action='assigned')`.

### Step 4 — Update task status when instructed

Only on explicit instruction (user / escalation / engine). Always log. See SQL pattern in Section 7.

### Step 5 — Generate summaries and reports

- `python scitbd_ceo_agent.py briefing` — full CEO daily briefing (markdown, 7 sections).
- `python scitbd_ceo_agent.py kpis` — KPI table with progress %.
- `python scitbd_ceo_agent.py escalate` — Section 7.1 scan.
- `python scitbd_ceo_agent.py crm` — pipeline counts.
- Content digest: `content/<date>/*.md` + `content_bundle.json`.

### Step 6 — Log all task actions in `task_logs`

```sql
INSERT INTO task_logs (task_id, action, performed_by, details) VALUES (?, ?, ?, ?);
```

Never mutate `daily_tasks` without a companion `task_logs` row.

---

## 9. Master AI Directive

**Source of truth:** DB tables (installed by `scitbd_ceo_directive.py install`), loaded at runtime — code and data can never drift.

- **S1 Identity:** SCITBD, HQ Bangladesh, 30+ countries, 500+ projects, USD/BDT/EUR, EN/BN/AR/FR/HI, GDPR + ISO 27001, INTERNAL STRATEGIC, issued 2026.
- **S2 Portfolio (17):** ICT Consultancy, Web Dev, Custom Software, Mobile Apps, E-Learning/LMS, AI & Data, Cybersecurity, Commerce/Payments, Marketing/Branding, Gov/Enterprise, Cloud Strategy, Native/Cross-platform, Intelligent Automation, Compliance, Digital Audits, ML, AI Agent.
- **Markets (7):** Public Sector, Education, Healthcare, Commerce/Retail, Non-Profits, Enterprises, SaaS Startups.
- **S3.1 UTC cycle (4):** 00–06 UTC→Block 1 (SEO/social/nurture), 06–12 UTC→Block 2 (ads audit/EU outreach), 12–18 UTC→Block 3 (US ads/proposals/CRM), 18–24 UTC→Block 4 (metrics/reports/AU content).
- **S3.2 Engines (6):** CONTENT (3 blogs/day, 5 posts/platform, 2 case studies/week, 1 whitepaper/mo), LEAD (continuous RFP scan), AD (continuous A/B, auto-pause/scale), EMAIL (7-touch sequences), REVIEW (continuous, <1h flag), ANALYTICS (weekly/monthly/quarterly).
- **S4 Divisions + 22 tasks:** MKT 1–5, SBD 6–10, AIT 11–14, CSR 15–18, FGI 19–22 (see CLI `tasks` for deliverable/KPI/deadline detail).
- **S5 KPIs (10):** Section 16.
- **S6 Prompt library (7):** 3 marketing, 2 business_development, 2 operations_strategy.
- **S7 Escalations (5) + Decisions (7):** Section 15.
- **S8 Closing:** *"SCITBD does not wait. SCITBD does not slow down…"* — premium, global, trustworthy; data-led; no vague outputs.

---

## 10. Content Engine

**File:** `scitbd_content_engine.py` · **Output:** `content/<date>/` + `emails/` + `daily_tasks` + `content_items`.

Per calendar day (BST) generates **deterministically (idempotent)**:

- 5 social posts × 3 platforms (facebook, youtube, linkedin) = 15 social rows.
- 5 SEO blog titles (keyword + meta 120–160 chars + slug + H2 outline + 1800–2200 words + 6 internal links).
- 5 SEO sales emails (subject + preview + body + CTA per service/audience).
- Ad catalogue: 8 earning/buying models + 14 display/earning placements.
- SEO score 0–100, calibrated **per channel** (`blog` vs `social` vs `email` norms for title/snippet length).

**Files per day:**

```
content/YYYY-MM-DD/content_bundle.json  # machine-readable (items + ads)
content/YYYY-MM-DD/social_posts.md
content/YYYY-MM-DD/blog_titles.md
content/YYYY-MM-DD/sales_emails.md
content/YYYY-MM-DD/online_ads.md
```

**AI-agent review tasks (5, `assignee='ai_agent'`):** social select, blog select, email select, ads display setup, ads earning optimisation.

**Email digest:** dark-themed HTML with `?action=select_content&kind=&id=` and `?action=accept_task&id=` deep links so the CEO picks today's task from the inbox. T-30 triggers at 05:30/11:30/17:30/23:30 BST.

---

## 11. Alerting Stack

**File:** `scitbd_alerting.py` · **Default:** `DRY_RUN=1` (log, no network). Set `SCITBD_ALERT_DRYRUN=0` + real env credentials to go live.

**Channels:** Slack webhooks (block / lead / health) → WhatsApp via UltraMsg → SMS via Twilio (fallback). Founder mobile default `+8801559575338`, CRM `https://scit.zya.me/`.

| Feature | Behaviour |
|---|---|
| Pre-block alerts | 15-min windows at 05:45/11:45/17:45/23:45, modular-midnight aware, one fire per block per day |
| $10k+ lead dispatch | health-ranked chain; DOWN skipped unless last resort; best-effort follow-up on non-primary |
| Retry queue | 60s / 5m / 15m backoff, 4 attempts, stale IN_FLIGHT reaped after 10 min |
| Health scoring | +5 success / −12 failure, clamp 0–100; HEALTHY ≥80, DEGRADED 50–79, DOWN <50 or 3 consec fails |
| Canary | probes DOWN channels; recovery → DEGRADED probation (50–79), next real send → HEALTHY |
| Exhaustion | Slack health ping + `operational_logs(FAILED)` + `EXHAUSTED` state — never silent |

---

## 12. Scheduler & Automation (.bat + ICS)

| File | Cadence | Command |
|---|---|---|
| `run_block_sync.bat` | every 30–60 min | `scitbd_block_scheduler.py run` (sync + status) |
| `run_preblock_alert.bat` / `register_block_sync.bat` / `reregister_preblock.bat` | every 5 min | `scitbd_alerting.py precheck` |
| `run_alert_retry.bat` | every 2–5 min | `scitbd_alerting.py retry` |
| `run_canary.bat` | every 30 min | `scitbd_alerting.py canary` |
| `register_scitbd_tasks.bat` | daily 00:05 BST | directive `autoflow` + content `all` + scheduler `sync` |
| `export_schedule_ics.py` | on demand | regenerates `scitbd_schedule.ics` |

ICS (`scitbd_schedule.ics`) imports all 16 slots into Google/Outlook Calendar for human visibility.

---

## 13. CLI Reference

```bash
# --- Block clock ---
python scitbd_block_scheduler.py status   # active block + live + next slot
python scitbd_block_scheduler.py sync     # flip ACTIVE + materialise today's tasks
python scitbd_block_scheduler.py today    # 24h schedule table
python scitbd_block_scheduler.py run      # sync + status

# --- CEO agent ---
python scitbd_ceo_agent.py briefing       # 7-section markdown briefing
python scitbd_ceo_agent.py kpis           # KPI progress table
python scitbd_ceo_agent.py escalate       # Section 7.1 scan
python scitbd_ceo_agent.py engines        # 6 engines + cadence
python scitbd_ceo_agent.py crm            # leads/tickets/tasks counts
python scitbd_ceo_agent.py system [brief] # master prompt (+ live context)
python scitbd_ceo_agent.py assign "Title" MKT "kpi" "deadline" [priority]
python scitbd_ceo_agent.py run CONTENT|LEAD|AD|EMAIL|REVIEW|ANALYTICS

# --- Directive ---
python scitbd_ceo_directive.py install|show|prompt|tasks|kpis|library [cat]|section7|section3|autoflow

# --- Operational plan ---
python seed_operational_plan.py           # seed 16 slots + 6 SLAs + sync

# --- Content ---
python scitbd_content_engine.py install|generate|tasks|files|email|all|preview|tminus

# --- Alerts ---
python scitbd_alerting.py install|health|precheck|retry|canary|testlead|demo
```

---

## 14. Dashboard & CRM Frontend

- **`dashboard.html`** — live operational view (blocks, slots, KPIs, content counts). Open directly or serve statically.
- **`index.php`** — PHP CRM (leads, tickets, tasks, `?action=select_content` / `accept_task` / `task_detail` deep links used by email digest).
- **`chatbot/`** — assistant UI wiring to `scitbd_ceo_agent.system_prompt()` + `askAI` path.
- **`scitbd_schedule.ics`** — calendar mirror of the 24h plan.

---

## 15. SLA & Escalation Reference

### SLA Standards (`sla_standards`, 6)

| Trigger | Target | Escalation | Severity |
|---|---|---|---|
| Client Support Ticket | < 2 hours (120 min) | Auto-route to technical team | critical |
| Inbound Lead RFP | Proposal in < 2 hours (120 min) | Proposal Factory automation | critical |
| Lead Value ≥ $10,000 | Action within 24h (1440 min) | Personalised CEO video message | critical |
| Negative Brand Review | Action within < 1h (60 min) | CEO review queue notification | high |
| NPS Score < 40 | Action within < 48h (2880 min) | Emergency review meeting | high |
| System Hosted Uptime | 99.9% guarantee | Instant ops engineering alert | critical |

### Escalation Triggers (Section 7.1, 5)

1. Lead ≥ $10k → CEO video within 24h (critical).
2. NPS < 40 → emergency meeting within 48h (critical if firing).
3. Negative ROAS 3 consecutive days → pause + escalate (no-data until Ad feed wired).
4. Negative press / controversy → escalate within 1h + draft response (no-data until Review feed wired).
5. Deadline overrun > 5 days → CEO client comms within 24h (high if firing).

### Decision Authority (Section 7.2, 7)

| Decision | Authority | Action |
|---|---|---|
| Contracts < $5,000 | Division Lead | Approve + execute within 4h |
| Contracts $5k–$50k | CEO Approval | Review, sign, assign within 24h |
| Contracts > $50k | CEO + Board Notify | Due diligence, custom contract, senior team |
| Partnership Agreements | CEO Approval | Legal review + sign within 72h |
| Budget Reallocation > $2k | CEO Approval | Data justification, document within 48h |
| New Market Entry | CEO + Strategy Team | Research brief required |
| Hiring Senior Roles | CEO Final Approval | 2-round interviews, CEO final mandatory |

---

## 16. KPI Reference

| # | Metric | Baseline | 6-Month | 12-Month |
|---|---|---|---|---|
| 1 | Monthly Revenue (USD) | Baseline | $200K | $500K |
| 2 | New Leads / Month | Baseline | 200 | 500+ |
| 3 | Website Organic Traffic / Month | Baseline | 25,000 | 100,000 |
| 4 | LinkedIn Followers | Baseline | 5,000 | 25,000 |
| 5 | Active Retainer Clients | Baseline | 20 | 60 |
| 6 | Countries with Active Projects | 30+ | 40+ | 60+ |
| 7 | Client NPS Score | Baseline | 65+ | 75+ |
| 8 | Google Ad ROAS | Baseline | 4x | 8x |
| 9 | Proposal-to-Win Rate | Baseline | 30% | 45% |
| 10 | Employee Headcount | Baseline | +15 Hires | +40 Hires |

Progress = `current / target_6m × 100` (parsed: `$500K→500000`, `45%→45`, `8x→8`). Update via `set_kpi()` / `kpi_measurements`.

Revenue architecture: Q1 $250K → Q2 $400K → year-end $1.5M; LTV:CAC ≥ 5:1.

---

## 17. Setup & Install Guide

### Prerequisites

- Python 3.10+ · Windows with Task Scheduler · SQLite3 (bundled) · 500 MB disk.
- Optional live alerts: Slack webhooks, UltraMsg instance/token, Twilio SID/token, `SCITBD_CRM_URL`, `SCITBD_CEO_EMAIL`.

### Fresh install (idempotent — safe to re-run)

```bash
cd "D:/CRM_with_GOOGLE_Sheet/ceo/ceo - Copy"
python scitbd_ceo_directive.py install   # directive knowledge base
python seed_operational_plan.py          # 16 slots + 6 SLAs + ACTIVE sync
python scitbd_content_engine.py install  # content/ads schema + seeds
python scitbd_alerting.py install        # channel_health + retry tables
python scitbd_block_scheduler.py run     # verify Block + live slot
python scitbd_ceo_agent.py briefing      # verify 7-section briefing renders
python scitbd_content_engine.py all      # generate today's bundle + tasks + digest
python scitbd_alerting.py demo           # dry-run full alert pipeline
```

### Register automation

```bash
register_scitbd_tasks.bat
register_block_sync.bat
reregister_preblock.bat
```

Verify: `health` shows HEALTHY, `precheck` reports window status, `retry` reports `no retries due`, ICS imports cleanly.

---

## 18. Extending the System

- **New slot:** insert into `block_tasks` with `(block_id, slot_start, slot_name)` unique; next `sync` materialises it.
- **New KPI:** insert into `directive_kpis`, record via `set_kpi()`; briefing picks it up automatically.
- **New engine:** add row to `directive_engines` + branch in `run_engine()` + log via `_ops_log()`.
- **New alert channel:** extend `CHANNELS`, add `SENDERS[ch]`, seed `channel_health`, health-ranked dispatch applies instantly.
- **Webhook:** call external APIs with `urllib.request` from any engine (same pattern as Slack sender); log to `operational_logs`.

---

## 19. Troubleshooting

| Symptom | Cause | Fix |
|---|---|---|
| Wrong ACTIVE block | OS clock in wrong zone | All decisions use `Asia/Dhaka` via `zoneinfo`; set scheduler host to BST or rely on `now_bst()`, re-run `sync` |
| Tasks not created | Already materialised (idempotent skip) | Check `skipped_tasks`; delete stale `daily_tasks` for date or change slot name to force |
| `leads` value column missing in scan | Schema variant without value col | Add `estimated_value`/`deal_value` to `leads`; scan auto-detects `estimated_value/budget/value/lead_value` |
| Alerts never fire | Outside 15-min window or already fired today | `precheck` every 5 min; check `block_alert_state.last_fired`; window is `±15 min` modular |
| Retry stuck IN_FLIGHT | Worker crash mid-cycle | `claim_due()` auto-reaps stale >10 min → PENDING; run `retry` manually |
| Channel stuck DOWN | 3 consec fails or score <50 | Run `canary`; success → DEGRADED probation; next real success → HEALTHY |
| Exhausted retries, no visibility | Nobody watches queue | `_notify_exhaustion` pings Slack health + `operational_logs(FAILED)` — check both |
| Content scores low | Old blog rubric applied to social/email | Fixed: per-channel norms in `_seo_score(kind=blog/social/email)` |
| `database is locked` | Concurrent writers | Keep transactions short; `PRAGMA foreign_keys=ON`; retry; never hold connection across network sends |
| Changes not showing in dashboard | Stale HTML / cache | Regenerate via `files` + `email`; hard-refresh; re-export ICS |

---

## 20. FAQ

**Q: What timezone does the system use?**
A: Exclusively Asia/Dhaka (BST, UTC+6, no DST). Every module calls `now_bst()` — host OS zone is irrelevant.

**Q: How many tasks should exist per day?**
A: 16 slot tasks (4/block) + up to 22 directive pushes + 5 content review tasks ≈ 43/day when fully materialised. Current DB holds 105 cumulative rows.

**Q: Are seeds destructive?**
A: No. All installers use `ON CONFLICT DO UPDATE` / `INSERT OR IGNORE` — re-running updates in place, never duplicates.

**Q: Does the AI write to production data?**
A: Only via `assign_task()` / `push_to_daily()` / engine dispatch — each writes `task_logs` + `operational_logs`. The chat assistant itself reads summaries by default.

**Q: Where do I approve today's content?**
A: Email digest (`emails/`) — click ✅ Select / 👉 Select this task deep links, or open `content/<date>/*.md` directly.

**Q: How do I go live with alerts?**
A: Set `SCITBD_ALERT_DRYRUN=0` + real `SLACK_*`, `ULTRAMSG_*`, `TWILIO_*` env vars, then `testlead` in dry-run, then live with a $0 canary.

**Q: How do I back up?**
A: Copy `scitbd_ceo.db` + `content/` + `emails/` daily (Drive version history or scheduled `xcopy`). The DB is a single portable file.

**Q: Can it run on Linux?**
A: Yes — Python + SQLite are portable; replace `.bat` with cron/systemd timers calling the same CLI verbs.

---

*Documentation generated by SCITBD Daily Task Management AI Agent · Block 3 active (18:00–00:00 BST) · 105 daily tasks (0 pending) · 243 task_logs · All mutations logged.*
