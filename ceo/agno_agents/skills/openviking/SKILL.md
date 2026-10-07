---
name: openviking
description: OpenViking viking:// context database for Agno CEO/SCCRM agents — browse, scoped retrieval, ingest.
upstream: https://github.com/The-Lincoln/OpenViking.git
vendor: external/openviking
wrappers: [ceo/openviking.py, autoflows/app/services/OpenViking.php]
---

# OpenViking — Agno skill

See `ceo/.agents/skills/openviking/SKILL.md` (canonical).

Agno usage:
```python
import openviking as OV  # ceo/openviking.py
OV.status()                        # server + ov CLI ready?
OV.find(query, uri)                # scoped semantic search
```

PHP usage (SCCRM/AutoFlows/Trace):
```php
require_once 'autoflows/app/services/OpenViking.php';
OpenViking::recall($query, 'viking://resources/<project>');
OpenViking::ls('viking://user/<id>/memories');
```

Rules: L0→L1→L2 reads; scope retrieval; user key (not root) for memories;
server-down ⇒ fall back to AgentMemory/local cache.
