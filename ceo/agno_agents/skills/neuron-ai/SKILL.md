---
name: neuron-ai
description: Neuron AI agents for Agno CEO/SCCRM — Workflow-based PHP agents, chat drafts/triage, RAG/tools/structured output.
upstream: https://github.com/neuron-core/neuron-ai.git
vendor: external/neuron-ai
wrappers: [ceo/neuron_ai.py, autoflows/app/services/NeuronAi.php, sccrm/ai/neuron_agent.php, sccrm/ai/neuron_chat_agent.php]
---

# Neuron AI — Agno skill

See `ceo/.agents/skills/neuron-ai/SKILL.md` (canonical).

Agno usage:
```python
import neuron_ai as N  # ceo/neuron_ai.py
N.status()             # vendor + 13 upstream skills ready?
N.catalog()            # neuron-agent/rag/tool/workflow/…
N.get('neuron-agent')  # load before writing Neuron code
N.providers()          # Anthropic/OpenAI/Gemini/Ollama/…
```

PHP usage (CEO + SCCRM + chat):
```php
require_once 'sccrm/ai/neuron_agent.php';
neuronAgentAsk($db, 'Summarise Block 2 risks');

require_once 'sccrm/ai/neuron_chat_agent.php';
neuronChatDraft($db, $ticketId, $rag);      // drafts only, agent sends
neuronChatTriage($db, $subject, $message);  // dept/priority/escalate
neuronChatWhatsapp($db, $incoming, $leadName);
```

Rules: load upstream SKILL.md first (memory may be stale); tools need
approval (human-in-the-loop); no keys => TinyLLM fallback; drafts never
auto-send.
