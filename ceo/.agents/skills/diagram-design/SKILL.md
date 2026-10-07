---
name: diagram-design
description: >
  42 editorial diagram types as self-contained HTML+SVG for CEO reports,
  proposals and trace intel cards. Pick type, scaffold, draw per reference.
---

# Diagram Design — SCITBD Skill

Upstream: https://github.com/The-Lincoln/diagram-design.git (vendored at
`external/diagram-design`). Skill: `skills/diagram-design/` (SKILL.md,
`references/type-*.md`, `assets/template*.html`, gallery `assets/index.html`).

Canonical wrappers: `ceo/diagram_design.py` (Python),
`autoflows/app/services/DiagramDesign.php` (PHP, class `DiagramDesign`),
shim `sccrm/services/DiagramDesignService.php`.

## Task workflow

1. Types: `DiagramDesign::listTypes()` (44 incl. Sankey, Wardley, kanban,
   journey, db-schema, architecture-delta…) / `python ceo/diagram_design.py types`.
2. Read `getType($id)` reference BEFORE drawing (grammar, budget, anti-patterns).
3. Scaffold: `scaffold($title, $type)` copies `template.html` →
   `data/diagrams/<slug>.html` + `.spec.json` + audit row.
4. Draw: density 4/10, accent on 1–2 focal nodes, no shadows, static default.
   Brand via `references/style-guide.md` onboarding (first-run gate asks).
5. Validate: `validate($file)` (has SVG, no Mermaid, no remote assets,
   accessible `role="img"`). Export SVG/PNG per `references/export.md`.
