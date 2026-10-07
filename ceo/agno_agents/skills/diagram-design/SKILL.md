---
name: diagram-design
description: 42 editorial diagram types for Agno CEO/SCCRM agents — scaffold from template, draw per reference.
upstream: https://github.com/The-Lincoln/diagram-design.git
vendor: external/diagram-design
wrappers: [ceo/diagram_design.py, autoflows/app/services/DiagramDesign.php]
---

# Diagram Design — Agno skill

See `ceo/.agents/skills/diagram-design/SKILL.md` (canonical).

Agno usage:
```python
import diagram_design as DD  # ceo/diagram_design.py
DD.types()                       # pick
DD.scaffold(title, type)         # assets/template -> data/diagrams/<slug>.html
```

PHP: `DiagramDesign::listTypes()`, `getType($id)`, `scaffold($t,$type)`, `validate($f)`.
Rules: type reference first; density 4/10; static default; brand onboarding gate.
