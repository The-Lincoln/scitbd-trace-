---
name: ticket-ai
description: AI draft replies for Agno SCCRM agents — suggest via TinyLLM or OpenAI-compatible API, agent sends.
upstream: https://github.com/The-Lincoln/ai-response-generator.git
vendor: external/ai-response-generator
wrappers: [sccrm/ai/ticket_reply_agent.php]
---

# Ticket AI — Agno skill

See `ceo/.agents/skills/ticket-ai/SKILL.md` (canonical).

Agno usage: draft via `ticketReplySuggest($db, $ticketId, $rag)` equivalent —
subject + history + RAG → provider (override first, TinyLLM fallback) →
review → send. Never auto-send.
