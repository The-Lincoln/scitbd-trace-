---
name: content-engine
description: SEO content-engine reference for Agno agents — 6-phase pipeline, machine gate, SCITBD mapping.
upstream: https://github.com/salehin96/content-engine.git
vendor: external/content-engine
wrappers: [ceo/content_engine_ref.py, autoflows/app/services/ContentEngineRef.php, sccrm/ai/content_engine_ref_agent.php]
---

# Content Engine — Agno skill

See `ceo/.agents/skills/content-engine/SKILL.md` (canonical).

Agno usage:
```python
import content_engine_ref as C  # ceo/content_engine_ref.py
C.status()            # reference vendored + SCITBD mapping?
C.phases()            # 6 phases with SCITBD mapping
C.gate_check(draft)   # phase-5 mechanical validator
```

PHP: `ContentEngineRef::gateCheck($draft)`, `contentRefBrief($db,'blog',$topic)`,
`contentRefReviewChecklist($url,$claims)`. Evidence-only; gate blocks save on fail.
