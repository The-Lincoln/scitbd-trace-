---
name: neuron-ai
description: >
  Neuron AI agentic PHP framework for SCITBD agents: composable Workflow
  foundation, Agent class, providers/tools/RAG/MCP/structured/streaming/evals.
  CEO reasoning + SCCRM chat drafts/triage/WhatsApp with TinyLLM fallback.
---

# Neuron AI — SCITBD Skill

Upstream: https://github.com/neuron-core/neuron-ai.git (vendored at
`external/neuron-ai` — MIT, PHP ^8.1, composer `neuron-core/neuron-ai`,
psr-4 `NeuronAI\` => `src/`). Docs: https://docs.neuron-ai.dev.
Canonical wrappers: `ceo/neuron_ai.py` (Python),
`autoflows/app/services/NeuronAi.php` (PHP, class `NeuronAi`),
shims `sccrm/services/NeuronAiService.php`,
` sccrm/ai/neuron_agent.php` (CEO), `sccrm/ai/neuron_chat_agent.php` (chat).

## Mental model (upstream AGENTS.md — internalize before coding)

1. **Workflow is the foundation.** Everything else is built on it — event-driven
   graph of nodes you compose freely for any agentic behaviour.
2. **Catalog of components, wired by interfaces.** Providers, messaging, chat
   history, tools & toolkits, structured output, RAG/vector stores, loaders,
   MCP, observability — each standalone, swappable.
3. **`Agent` is an inspiration, not a cage.** `Agent` itself is a Workflow
   (chat/stream/structured nodes + tools + history + middleware). Extend it
   when defaults fit; swap nodes/add middleware for targeted changes; compose
   your own Workflow when control flow differs. `Agent` and `RAG` work as
   nodes inside larger workflows.

Default path: `AgentStartEvent → AgentStartNode → inference ⇄ tools`
`final response → AgentOutputEvent → AgentEndNode → StopEvent`.
Tool approval: `Agent::submitApprovalDecisions($decisions)`; deferred (frontend)
tools: `Agent::submitToolResults($results)`; then `run()` (AgentState) or
`events()` (stream). `AgentState::$request` holds instructions/messages/options.

## Setup (upstream 3-step)

1. `composer require neuron-core/neuron-ai` (this repo vendors source at
   `external/neuron-ai` for inspection; composer install makes it autoloadable).
2. `npx skills add ./vendor/neuron-core/neuron-ai/skills -y` — symlinked, stays
   current on composer update. **Load the relevant upstream skill before writing
   Neuron code** (memory may describe an older version).
3. Laravel => `neuron-laravel-integration`, Symfony => `neuron-symfony-integration`.
   This app is custom PHP — use the SCITBD wrappers, no framework skill needed.

Code standards: `declare(strict_types=1)`, PSR-12, PHPStan level 5, 100% type
coverage, protected (never private) members, PHP 8.1+ (enums, promotion).

## Task workflow

### A. CEO AI agent (`sccrm/ai/neuron_agent.php`)

```php
require_once 'sccrm/ai/neuron_agent.php';
$st  = neuronAgentStatus();              // vendor + composer readiness
$res = neuronAgentAsk($db, 'Summarise Block 2 risks');
$ex  = neuronAgentExtract($db, $notes, ['company'=>'','value'=>0,'next_step'=>'']);
```

- Native Neuron path only when `NeuronAI\Agent\Agent` autoloads AND a provider
  key exists (`ANTHROPIC_API_KEY` / `OPENAI_API_KEY`); concrete class
  `ScitbdNeuronCeoAgent extends NeuronAI\Agent\Agent` implements
  `provider()` + `instructions()` (see upstream `skills/neuron-agent/SKILL.md`).
- Otherwise TinyLLM fallback (`aiChatReply`) — CEO workflows never break offline.
- Human-in-the-loop: tool calls surface as proposals; never auto-execute CRM
  side effects. BST-aware (Asia/Dhaka) system prompt.

### B. SCCRM chat agents (`sccrm/ai/neuron_chat_agent.php`)

```php
require_once 'sccrm/ai/neuron_chat_agent.php';
$d = neuronChatDraft($db, $ticketId, $rag);      // ticket reply draft
$t = neuronChatTriage($db, $subject, $message);  // dept/priority/escalate
$w = neuronChatWhatsapp($db, $incoming, $leadName);
```

- Drafts only — agent reviews and sends (mirrors ticket-ai safety).
- RAG = subject + last 12 messages + contact/company + optional `$rag`.
- Triage falls back to heuristics offline; WhatsApp replies ≤ 3 sentences.
- Wiring: `sccrm/chat/view.php` Generate-AI-reply button,
  `sccrm/chat/whatsapp.php` Evolution inbox.

### C. Status / catalog (either layer)

```bash
python ceo/neuron_ai.py status       # vendor + 13 upstream skills + bridges
python ceo/neuron_ai.py skills       # list neuron-agent/rag/tool/workflow/…
python ceo/neuron_ai.py get neuron-agent --full
python ceo/neuron_ai.py providers    # AWS/Alibaba/Anthropic/Cohere/Deepseek/…/XAI
```

```php
NeuronAi::status('sccrm'); NeuronAi::catalog(); NeuronAi::search('rag');
NeuronAi::get('neuron-rag'); NeuronAi::providers();
```

## SCITBD fast paths

- `neuron-agent` — CEO + chat agents (default entry point).
- `neuron-rag` — knowledge retrieval for ticket/lead RAG.
- `neuron-tool` — lead actions as tools with approval.
- `neuron-workflow` — triage → draft → review → send orchestration.
- `neuron-structured-output` — lead/ticket JSON extraction.
- `neuron-streaming` — AG-UI / Vercel AI SDK chat streaming.
- `neuron-evaluation` — reply-quality evals (multi-turn included).

Read ONLY the skills you need (upstream security guidance: never bulk-install).
Env: `ANTHROPIC_API_KEY/MODEL`, `OPENAI_API_KEY/MODEL`, `NEURON_API_KEY`.
No keys => TinyLLM/heuristic fallback (honest `driver` in every return).
