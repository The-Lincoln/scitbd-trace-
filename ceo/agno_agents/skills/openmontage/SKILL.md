---
name: openmontage
description: OpenMontage agentic video production for Agno CEO/SCCRM agents — pipelines, briefs, gates, budgets.
upstream: https://github.com/calesthio/OpenMontage.git
vendor: external/openmontage
wrappers: [ceo/openmontage.py, autoflows/app/services/OpenMontage.php]
---

# OpenMontage — Agno skill

See `ceo/.agents/skills/openmontage/SKILL.md` (canonical).

Agno usage:
```python
import openmontage as OM  # ceo/openmontage.py
OM.pipelines()                                   # pick first, then read manifest
OM.request(title, brief, pipeline, module='ceo') # scaffold + audit row
```

PHP usage (SCCRM/AutoFlows):
```php
require_once 'autoflows/app/services/OpenMontage.php';
OpenMontage::requestProduction($title, $brief, 'animated-explainer', 'sccrm');
OpenMontage::listProductions();
```

Rules: pipeline manifest + director skills, never improvised orchestration;
research before scripting; gates + budget caps; zero-key default.
