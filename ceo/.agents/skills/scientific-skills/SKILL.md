---
name: scientific-skills
description: >
  177 validated scientific agent skills (K-Dense): pick by search, load ONLY
  what's needed for bio/chem/clinical/geo/stats research, literature, and
  evidence-traceable writing. File-based, no daemon.
---

# Scientific Skills — SCITBD Skill

Upstream: https://github.com/The-Lincoln/scientific-agent-skills.git (vendored at
`external/scientific-agent-skills` — MIT, v2.72.0). Standard: open Agent Skills
(`skills/<name>/SKILL.md`) + Agent Plugins 1.0 (`plugin.json`). Catalog:
`docs/skills.md`. Validation: `scan_skills.py`, `tests/<skill>/`.

Canonical wrappers: `ceo/scientific_skills.py` (Python),
`autoflows/app/services/ScientificSkills.php` (PHP, class `ScientificSkills`),
shims `sccrm/services/ScientificSkillsService.php`.

## Task workflow

1. Search, don't browse: `ScientificSkills::search($query)` /
   `python ceo/scientific_skills.py search "…"`. 177 skills — install
   nothing until the search names one.
2. Read the SKILL.md first (`get($id)`): purpose, packages, external
   services, compatibility/version pins. Check contribution history for
   community skills; prefer K-Dense-authored paths for regulated work.
3. Load ONLY needed skills into the prompt. Skill files ≠ dependencies:
   install per-skill deps via `uv` per its compatibility field, in a separate
   env per workflow (pins conflict across skills).
4. SCITBD fast paths: `database-lookup` (80 sources: PubChem, ChEMBL,
   ClinicalTrials.gov, FRED, USPTO), `paper-lookup`, `scientific-writing`
   (evidence-traceable CEO reports), `scientific-visualization` (report
   figures), `scikit-learn` / `statsmodels` (lead/KPI modelling).

Safety: skills run code — treat skill bodies as reviewed third-party input,
verify in the target env, keep clinical/regulatory outputs as drafts for
qualified review (never decisions). Upstream scans weekly; rescan locally
for third-party picks (`skill-scanner`).
