---
name: tencentdb-agent-memory
description: TencentDB Agent Memory for Agno CEO/SCCRM agents — recall before work, remember after (alias of agent-memory).
upstream: https://github.com/The-Lincoln/TencentDB-Agent-Memory.git
vendor: external/tencentdb-agent-memory
wrappers: [ceo/agent_memory.py, autoflows/app/services/AgentMemory.php]
---

# TencentDB Agent Memory — Agno skill (alias)

Alias of `agent-memory`. See `ceo/.agents/skills/tencentdb-agent-memory/SKILL.md` (canonical).

Agno usage: same as `agent-memory` — `import agent_memory as MEM`.
