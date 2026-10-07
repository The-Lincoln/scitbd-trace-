---
name: tooljet
description: ToolJet low-code dashboards, apps and workflow webhooks for Agno CEO/SCCRM agents.
upstream: https://github.com/ToolJet/ToolJet.git
vendor: external/tooljet
wrappers: [ceo/tooljet.py, autoflows/app/services/ToolJet.php]
---

# ToolJet — Agno skill

See `ceo/.agents/skills/tooljet/SKILL.md` (canonical).

Agno usage:
```python
import tooljet as TJ  # ceo/tooljet.py
st = TJ.status()                                   # instance up? token set?
TJ.trigger(TJ.cfg()['webhook_lead'], {'lead': name, 'value': amount})
```

PHP usage (SCCRM/AutoFlows/Trace):
```php
require_once 'autoflows/app/services/ToolJet.php';
$st = ToolJet::status();
ToolJet::triggerWorkflow((string)$cfg['webhook_lead'], ['lead' => $name]);
$iframe = ToolJet::embedUrl('sccrm');
```

Rules: status before acting; empty embed URL = slug missing; webhooks are
fire-and-log (never block CRM writes on ToolJet downtime).
