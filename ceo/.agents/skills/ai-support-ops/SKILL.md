---
name: ai-support-ops
description: >
  AI support triage + escalation (ai-support-operations pattern): classify
  tickets, confidence scoring, safety-guarded human escalation, review queue.
---

# AI Support Ops — SCITBD Skill

Pattern source: https://github.com/rivannagrale/ai-support-operations.git
(vendored at `external/ai-support-operations` — Node/Express + Gemini console:
`/api/analyze` → `{category, confidence, escalate}`, KB retrieval,
escalation workflow, analytics).

SCITBD port: `sccrm/ai/ticket_triage_agent.php`
(`ticketTriage($db, $ticketId, $force)` + `ticketTriageQueue($db)`), auto-triage
on `sccrm/chat/create.php`, badge + re-run in `sccrm/chat/view.php`, review
filter in `sccrm/chat/index.php` (`?review=1`).

## Task workflow

1. Categories: Authentication | Billing | Technical | How-to | Unknown.
2. Safety (upstream parity, enforced even over model output): billing/refunds,
   account security, data deletion, sensitive → escalate; empty KB match →
   Unknown + escalate.
3. Providers: TinyLLM structured JSON first, deterministic keyword fallback
   offline. KB context from `knowledge_articles` (title/tag overlap).
4. Storage: `ticket_triage` (UNIQUE ticket_id) — no `support_tickets` schema change.
5. Humans decide: triage routes, review queue (`?review=1`) clears. Pair with
   `ticket-ai` drafts for the actual reply.
