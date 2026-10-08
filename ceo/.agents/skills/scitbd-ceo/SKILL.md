---
name: scitbd-ceo
description: >
  Master CEO directive: briefing, KPIs, escalations (7.1), engines (3.2), decisions (7.2). BST Asia/Dhaka only.
---

# CEO Master Directive — SCITBD Skill

Master CEO directive: briefing, KPIs, escalations (7.1), engines (3.2), decisions (7.2). BST Asia/Dhaka only.

## Wiring (this application)

- App implementation: `ceo/scitbd_ceo_agent.py` (`briefing()`, `kpi_status()`, `escalate_scan()`, `run_engine()`) + `ceo/scitbd_block_scheduler.py` (BST blocks/slots).
- Directive backing: CEO — master directive; ceo_agent_profile + directive_* + operational_blocks (BST). DB: `D:/CRM_with_GOOGLE_Sheet/ceo/ceo - Copy/scitbd_ceo.db`
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
