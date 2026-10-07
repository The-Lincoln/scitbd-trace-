---
name: harness-guide
description: >
  Curated harness-engineering reading (two awesome lists): search topics,
  get scoped briefs for hooks, MCP, evals, sandboxing, provider routing.
---

# Harness Guide — SCITBD Skill

Upstream (vendored reading lists — no runtime):
- `external/awesome-harness-walkinglabs` (walkinglabs/awesome-harness-engineering)
- `external/awesome-harness-aiboost` (ai-boost/awesome-harness-engineering)

Canonical wrappers: `ceo/harness_guide.py` (Python),
`autoflows/app/services/HarnessGuide.php` (PHP, class `HarnessGuide`).

## Task workflow

1. Search both lists: `HarnessGuide::search($topic)` /
   `python ceo/harness_guide.py search "…"`. Relevant when building agent
   harnesses: session isolation, child-process supervision, daemon lifetime,
   MCP skill sync, eval harnesses, sandbox hosts — the same concerns behind
   our AgentBrowser/BrowserSkill/memory-proxy integrations.
2. Read the linked source (section context included in hits), not just titles.
3. Apply to SCITBD wrappers; upstream lists are advisory, our `*_runs`
   audit tables + never-throw exec patterns stay canonical.
