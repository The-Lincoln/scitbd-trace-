---
name: ticket-ai
description: >
  AI draft replies for SCCRM support tickets (ai-response-generator pattern):
  TinyLLM local-first with optional OpenAI-compatible override, RAG context,
  template voice. Drafts only — agent reviews and sends.
---

# Ticket AI — SCITBD Skill

Pattern source: https://github.com/The-Lincoln/ai-response-generator.git
(vendored at `external/ai-response-generator` — osTicket plugin: Generate AI
Response button → OpenAI-compatible chat/completions + system prompt + RAG).

SCITBD port: `sccrm/ai/ticket_reply_agent.php`
(`ticketReplySuggest($db, $ticketId, $rag)`), wired into
`sccrm/chat/view.php` as a **Generate AI reply** button that pre-fills (never
auto-sends) the reply box.

## Task workflow

1. Context: subject + last 12 messages + contact/company auto-assembled.
2. Provider: `TICKET_AI_API_URL/KEY/MODEL` override first (plugin parity,
   15s timeout); falls back to local TinyLLM (`aiChatReply`) on failure.
3. Optional `$rag` (order #, policy note) enriches the draft.
4. Agent reviews/edits → Send Reply. Drafts are suggestions, never decisions.

Env: `TICKET_AI_API_URL` (e.g. `https://api.openai.com/v1`),
`TICKET_AI_API_KEY`, `TICKET_AI_MODEL`, `TICKET_AI_SYSTEM`.
