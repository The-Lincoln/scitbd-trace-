---
name: scientific-skills
description: 177 validated scientific agent skills for Agno CEO/SCCRM agents — search, load-what-you-need, verify.
upstream: https://github.com/The-Lincoln/scientific-agent-skills.git
vendor: external/scientific-agent-skills
wrappers: [ceo/scientific_skills.py, autoflows/app/services/ScientificSkills.php]
---

# Scientific Skills — Agno skill

See `ceo/.agents/skills/scientific-skills/SKILL.md` (canonical).

Agno usage:
```python
import scientific_skills as SCI  # ceo/scientific_skills.py
SCI.search("virtual screening")   # pick
SCI.get("rdkit")["content"]       # inject into prompt
```

PHP usage (SCCRM/AutoFlows):
```php
require_once 'autoflows/app/services/ScientificSkills.php';
$hits = ScientificSkills::search($task, 5);
$skill = ScientificSkills::get($hits['hits'][0]['id']); // SKILL.md body
```

Rules: search-first, read SKILL.md before installing, per-skill uv envs,
drafts-not-decisions for clinical/regulatory outputs.
