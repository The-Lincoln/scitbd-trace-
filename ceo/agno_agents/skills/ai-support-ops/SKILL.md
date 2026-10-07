---
name: ai-support-ops
description: AI support triage + escalation for Agno SCCRM agents — classify, guard, queue for humans.
upstream: https://github.com/rivannagrale/ai-support-operations.git
vendor: external/ai-support-operations
wrappers: [sccrm/ai/ticket_triage_agent.php]
---

# AI Support Ops — Agno skill

See `ceo/.agents/skills/ai-support-ops/SKILL.md` (canonical).

Agno usage: `ticketTriage($db, $ticketId)` on create/view;
`ticketTriageQueue($db)` for the human review list. Safety guards always win.
