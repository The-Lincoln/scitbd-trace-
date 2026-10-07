---
name: evolution-api
description: Evolution API WhatsApp messaging for Agno CEO/SCCRM agents — outreach, alerts, QR connect.
upstream: https://github.com/The-Lincoln/evolution-api.git
vendor: external/evolution-api
wrappers: [ceo/evolution_api.py, autoflows/app/services/EvolutionApi.php]
---

# Evolution API — Agno skill

See `ceo/.agents/skills/evolution-api/SKILL.md` (canonical).

Agno usage:
```python
import evolution_api as WA  # ceo/evolution_api.py
WA.status()                                  # state=open means linked
WA.send_text(lead_phone, follow_up_text)     # SCCRM outreach
WA.alert_ceo("NPS < 40 — emergency meeting") # Section 7.1 escalation
```

PHP usage (SCCRM/AutoFlows/Trace):
```php
require_once 'autoflows/app/services/EvolutionApi.php';
EvolutionApi::sendText($phone, $text);   // ['ok','http','data','ms']
EvolutionApi::alertCeo($escalationText);
```

Rules: digits-only international numbers; status before sending; log every
send; never gate CRM/CEO writes on WhatsApp availability.
