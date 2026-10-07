---
name: harness-guide
description: Curated harness-engineering reading for Agno agents — search both awesome lists.
upstream: [walkinglabs/awesome-harness-engineering, ai-boost/awesome-harness-engineering]
vendor: [external/awesome-harness-walkinglabs, external/awesome-harness-aiboost]
wrappers: [ceo/harness_guide.py, autoflows/app/services/HarnessGuide.php]
---

# Harness Guide — Agno skill

See `ceo/.agents/skills/harness-guide/SKILL.md` (canonical).

Agno usage:
```python
import harness_guide as HG  # ceo/harness_guide.py
HG.search("sandboxed daemon startup")
```

PHP: `HarnessGuide::search($topic)`, `HarnessGuide::catalog()`.
