---
name: scitbd-report
description: >
  Daily/weekly reports, KPI tables, escalation watch.
---

# Report Engine — SCITBD Skill

Daily/weekly reports, KPI tables, escalation watch.

## Wiring (this application)

- App implementation: `skills/skills/skill_report.php` (`skill_report_system()`). Agno mirror: none (CEO-directive tier — add pointer only if agents need it).
- Directive backing: CEO/FGI — briefing + operational_logs. DB: `D:/CRM_with_GOOGLE_Sheet/ceo/ceo - Copy/scitbd_ceo.db`
  (`directive_tasks/divisions/engines/kpis`, `daily_tasks`, `task_logs`, `operational_blocks`, `operational_logs`).
- BST rule: all times Asia/Dhaka (UTC+6). Block map: <06:00→4, <12:00→1, <18:00→2, else 3.
 Scheduler: `python ceo/scitbd_block_scheduler.py status|sync`.

## Task workflow

1. Resolve active block/slot (`get_current_block()`, `get_live_slot()`).
2. Load directive rows for this skill's division (tasks + KPIs + engines).
3. Execute via the app implementation above; write results to `daily_tasks` + `task_logs`
   (assignee `ai_agent`, `bst_block_id` = active block, proper priority/category/due).
4. Safety: confirm destructive ops; drafts/proposals only where customer-facing
   (never auto-send); log every mutation; escalate per Section 7.1 (lead ≥$10k→24h video,
   NPS<40→48h meeting, press→1h, overrun>5d→24h comms, neg-ROAS 3d→pause).
