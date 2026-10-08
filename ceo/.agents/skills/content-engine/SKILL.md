---
name: content-engine
description: >
  AI-orchestrated SEO content engine reference for SCITBD: 6-phase pipeline
  (research, topical map, briefs, adversarial review, machine gate,
  publish+self-correct) mapped to scitbd_content_engine + content_agent.
---

# Content Engine Reference — SCITBD Skill

Upstream: https://github.com/salehin96/content-engine.git (vendored at
`external/content-engine` — architecture maps, not runnable code; static site:
`index.html`, `content-engine.html`, `dependency-map.html`).
Live: https://salehin96.github.io/content-engine/.
Canonical wrappers: `ceo/content_engine_ref.py` (Python),
`autoflows/app/services/ContentEngineRef.php` (PHP, class `ContentEngineRef`),
` sccrm/ai/content_engine_ref_agent.php` (brief/gate/review-checklist).
Drafter (unchanged): `sccrm/ai/content_agent.php` (`contentAgentBrief/Run`, TinyLLM).

## Pipeline (AI judges, code enforces)

1. Brand+rival research → reference files (SCITBD: brief context + services).
2. Topical map → plan rows URL/query/type/links, script-built (SCITBD: rebuildable rows).
3. Briefs → angle/questions/facts/links (SCITBD: `contentAgentBrief()` kinds blog/social/email/video).
4. Research → write (evidence-only) → adversarial review, cold handoff, skeptical rewrite for usefulness.
5. Machine gate → rulebook-load proof + checklist + `gateCheck()` validator (length/format/no-fabrication/real links). **Blocks save on fail.**
6. Publish → convert, dedupe, reconcile live CMS/sitemap vs plan (SCITBD: `scitbd_content_engine.py files/email` + `content_items`).

Dependency-map kinds: engine file / tool / external input / gate; badges full/part/gate.

## Task workflow

```bash
python ceo/content_engine_ref.py status
python ceo/content_engine_ref.py phases
python ceo/content_engine_ref.py gate "draft…"
```

```php
ContentEngineRef::status('sccrm'); ContentEngineRef::phases();
ContentEngineRef::gateCheck($draft);           // phase 5 mechanical
contentRefBrief($db,'blog',$topic,$service);   // phase 3 proposal
contentRefReviewChecklist($url,$claims);        // phase 4c skeptical pass
contentAgentRun($db,'blog',$topic);             // draft (evidence-only)
```

Rules: evidence-only drafts; reviewer re-checks every fact vs live sources +
top-ranking pages; no save without gate proof; BST Asia/Dhaka; log to
`content_items` + `daily_tasks`/`task_logs`. Related: `scitbd-content`,
`scitbd-blog`, `scitbd-seo`.
