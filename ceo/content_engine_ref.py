# -*- coding: utf-8 -*-
"""
SCITBD CEO — salehin96/content-engine reference bridge (Python side).

Reference architecture (interactive system map, not runnable code):
  https://github.com/salehin96/content-engine.git
  vendored: external/content-engine (README + index/content-engine/dependency-map.html)
  live: https://salehin96.github.io/content-engine/

Six-phase pipeline (AI judges, deterministic code enforces):
  1. Brand + rival research  -> reference files (voice/product/competitors)
  2. Topical map             -> master plan rows (URL, query, type, links), script-built
  3. Per-article briefs      -> angle/questions/facts/links, independently researched
  4. Research -> write -> adversarial review (separate agents, cold handoff,
     evidence-only draft, skeptical re-check vs top-ranking pages, rewrite for usefulness)
  5. Machine quality gate    -> rulebook-load proof + checklist + deterministic validator
     (sentence/para limits, formatting, no fabricated claims, real internal links); block save on fail
  6. Publish + self-correct  -> format convert, dedupe, reconcile live CMS/sitemap vs plan

Dependency-map node kinds: engine file (blue) / tool (amber) / external input (teal) /
gate (dashed); badges: full / part / gate.

SCITBD mapping (this application):
  pipeline  : ceo/scitbd_content_engine.py (generate/tasks/files/email) + content/<date>/
  drafting  : sccrm/ai/content_agent.php (brief->draft->review->save, TinyLLM)
  skills    : scitbd-content / scitbd-blog / scitbd-seo (canon) + skill_content/blog/seo.php
  tables    : content_items (content_date/type), daily_tasks (assignee ai_agent), task_logs
  messaging : neuron-ai RAG for evidence, contributor-gokul for assignment

Companions:
  PHP canonical : autoflows/app/services/ContentEngineRef.php
  SCCRM agent   : sccrm/ai/content_engine_ref_agent.php
  Skills        : ceo/.agents/skills/content-engine/SKILL.md (canonical)

Usage:
    python content_engine_ref.py status
    python content_engine_ref.py phases
    python content_engine_ref.py gate "draft text…"
"""
from __future__ import annotations

import json
import os
import re
import sys

TRACE_ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
VENDOR = os.path.join(TRACE_ROOT, "external", "content-engine")
REPO = "https://github.com/salehin96/content-engine.git"

PHASES = [
    {"n": 1, "name": "Brand + rival research",
     "reads": ["product website", "competitor sites", "review platforms"],
     "writes": ["reference files (voice/product/competitors)"],
     "scitbd": "contentAgentBrief() context + services table + reference files downstream"},
    {"n": 2, "name": "Topical map",
     "reads": ["reference files (part)"],
     "writes": ["master plan rows (URL/query/type/links)"],
     "scitbd": "script-built plan; rebuildable; Block 1 BD / Block 2 EU topical rows"},
    {"n": 3, "name": "Per-article briefs",
     "reads": ["topical-map row", "live search results"],
     "writes": ["brief (angle/questions/facts/links)"],
     "scitbd": "sccrm/ai/content_agent.php brief per kind (blog/social/email/video)"},
    {"n": 4, "name": "Research -> write -> adversarial review",
     "reads": ["brief", "evidence file", "top-ranking pages"],
     "writes": ["evidence file", "draft (evidence-only)", "reviewed rewrite"],
     "scitbd": "separate agents + cold handoff; reviewer skeptical, usefulness over length"},
    {"n": 5, "name": "Machine quality gate",
     "reads": ["rulebooks", "draft", "checklist"],
     "writes": ["gate proof (blocks save on fail)"],
     "scitbd": "rulebook-load proof + deterministic validator (see gate_check())"},
    {"n": 6, "name": "Publish + self-correct",
     "reads": ["validated article", "live CMS/sitemap"],
     "writes": ["published page", "reconciled plan"],
     "scitbd": "ceo/scitbd_content_engine.py files/email + content_items rows; dedupe; drift-correct"},
]

try:
    sys.stdout.reconfigure(encoding="utf-8", errors="replace")
except Exception:
    pass


def status() -> dict:
    readme = os.path.isfile(os.path.join(VENDOR, "README.md"))
    views = [f for f in ("index.html", "content-engine.html", "dependency-map.html")
             if os.path.isfile(os.path.join(VENDOR, f))]
    return {"ok": readme and len(views) == 3, "driver": "content-engine-ref",
            "repo": REPO, "vendor": "external/content-engine",
            "readme": readme, "views": views,
            "phases": len(PHASES),
            "scitbd_pipeline": "ceo/scitbd_content_engine.py",
            "scitbd_drafter": "sccrm/ai/content_agent.php",
            "hint": "Ready — map briefs to SCITBD kinds before drafting." if readme
                    else "Missing vendor checkout"}


def gate_check(text: str) -> dict:
    """Deterministic validator (mechanical rules only — AI judges meaning elsewhere)."""
    t = text or ""
    issues: list = []
    if len(re.findall(r"https?://", t)) > 0 and "scit" not in t.lower() and "http" in t.lower():
        pass  # link-reality check needs plan context; flag in agent layer
    sentences = [s for s in re.split(r"[.!?]+", t) if s.strip()]
    long_para = [p for p in t.split("\n\n") if len(p.split()) > 150]
    if long_para:
        issues.append(f"{len(long_para)} paragraph(s) >150 words — split for readability")
    words = len(t.split())
    if words < 50:
        issues.append("thin content (<50 words) — expand brief coverage or drop page")
    fab = re.search(r"\b(100%|guaranteed #1|best in the world)\b", t, re.I)
    if fab:
        issues.append(f"unverifiable claim: '{fab.group(0)}' — cite evidence or remove")
    return {"ok": not issues, "words": words, "sentences": len(sentences),
            "issues": issues,
            "note": "Blocks save on fail (reference phase 5). Re-run review on fix."}


def main() -> None:
    cmd = sys.argv[1] if len(sys.argv) > 1 else "status"
    if cmd == "status":
        print(json.dumps(status(), indent=2))
    elif cmd == "phases":
        print(json.dumps({"ok": True, "phases": PHASES}, indent=2))
    elif cmd == "gate" and len(sys.argv) > 2:
        print(json.dumps(gate_check(" ".join(sys.argv[2:])), indent=2))
    else:
        print(__doc__)


if __name__ == "__main__":
    main()
