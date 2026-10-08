# -*- coding: utf-8 -*-
"""
SCITBD CEO — neuron-ai bridge (Python side).

Neuron AI — PHP framework for agentic applications (event-driven Workflow
foundation, Agent as composable Workflow, providers/tools/RAG/MCP/structured
output/streaming/evals):
  https://github.com/neuron-core/neuron-ai.git
  vendored: external/neuron-ai (composer: neuron-core/neuron-ai, PHP ^8.1)
  upstream skills: external/neuron-ai/skills/<name>/SKILL.md (13 skills)

Companions:
  PHP canonical service : autoflows/app/services/NeuronAi.php
  SCCRM CEO agent       : sccrm/ai/neuron_agent.php
  SCCRM chat agents     : sccrm/ai/neuron_chat_agent.php
  Agno skills           : ceo/agno_agents/skills/neuron-ai/SKILL.md
                          ceo/.agents/skills/neuron-ai/SKILL.md (canonical)
  App skill             : skills/skills/neuron-ai/SKILL.md

Never raises on missing vendor/composer — status() reports honestly so the
CEO agent and chat agents can fall back to TinyLLM / local cache.

Usage:
    python neuron_ai.py status
    python neuron_ai.py skills
    python neuron_ai.py get neuron-agent
    python neuron_ai.py providers
"""
from __future__ import annotations

import json
import os
import re
import sys
import time

TRACE_ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
VENDOR = os.path.join(TRACE_ROOT, "external", "neuron-ai")
SKILLS_DIR = os.path.join(VENDOR, "skills")
REPO = "https://github.com/neuron-core/neuron-ai.git"

try:
    sys.stdout.reconfigure(encoding="utf-8", errors="replace")
    sys.stderr.reconfigure(encoding="utf-8", errors="replace")
except Exception:
    pass


def _fm(path: str) -> dict:
    out = {"name": os.path.basename(os.path.dirname(path)), "description": ""}
    try:
        with open(path, "r", encoding="utf-8") as f:
            raw = f.read(4000)
    except Exception:
        return out
    if not raw.startswith("---"):
        return out
    end = raw.find("\n---", 3)
    fm = raw[3:end] if end != -1 else ""
    for k in ("name", "description"):
        m = re.search(rf"^{k}:\s*(.+)$", fm, re.M)
        if m:
            out[k] = m.group(1).strip().strip("\"'")
    return out


def status() -> dict:
    t0 = time.time()
    vendor_ok = os.path.isdir(VENDOR)
    composer = os.path.isfile(os.path.join(VENDOR, "composer.json"))
    src_ok = os.path.isdir(os.path.join(VENDOR, "src", "Agent"))
    n_skills = 0
    try:
        n_skills = len([d for d in os.listdir(SKILLS_DIR)
                        if os.path.isfile(os.path.join(SKILLS_DIR, d, "SKILL.md"))])
    except Exception:
        pass
    php_bridge = os.path.isfile(os.path.join(TRACE_ROOT, "autoflows", "app", "services", "NeuronAi.php"))
    ceo_bridge = os.path.isfile(os.path.join(TRACE_ROOT, "sccrm", "ai", "neuron_agent.php"))
    chat_bridge = os.path.isfile(os.path.join(TRACE_ROOT, "sccrm", "ai", "neuron_chat_agent.php"))
    ok = vendor_ok and src_ok and n_skills > 0
    return {
        "ok": ok,
        "driver": "neuron-ai",
        "repo": REPO,
        "vendor": "external/neuron-ai",
        "vendor_present": vendor_ok,
        "composer_present": composer,
        "agent_src_present": src_ok,
        "upstream_skills": n_skills,
        "php_service": php_bridge,
        "ceo_agent_bridge": ceo_bridge,
        "chat_agent_bridge": chat_bridge,
        "ms": int((time.time() - t0) * 1000),
        "hint": "Ready — load upstream skill before writing Neuron code." if ok
                else "Clone missing: git clone https://github.com/neuron-core/neuron-ai.git external/neuron-ai",
    }


def catalog() -> list:
    out = []
    try:
        names = sorted(os.listdir(SKILLS_DIR))
    except Exception:
        return out
    for name in names:
        f = os.path.join(SKILLS_DIR, name, "SKILL.md")
        if os.path.isfile(f):
            fm = _fm(f)
            out.append({"id": name, "name": fm["name"] or name,
                        "description": fm["description"][:300]})
    return out


def get(skill_id: str) -> dict:
    skill_id = (skill_id or "").strip()
    if not skill_id or ".." in skill_id or "/" in skill_id:
        return {"ok": False, "error": "invalid skill id"}
    f = os.path.join(SKILLS_DIR, skill_id, "SKILL.md")
    if not os.path.isfile(f):
        return {"ok": False, "error": "skill not found"}
    with open(f, "r", encoding="utf-8") as fh:
        body = fh.read()
    if body.startswith("---"):
        end = body.find("\n---")
        if end != -1:
            body = body[end + 4:].lstrip()
    return {"ok": True, "id": skill_id, "content": body, "chars": len(body)}


def providers() -> dict:
    """List provider adapters shipped in the vendored checkout."""
    base = os.path.join(VENDOR, "src", "Providers")
    out: list = []
    try:
        for name in sorted(os.listdir(base)):
            p = os.path.join(base, name)
            if os.path.isdir(p) and not name.startswith("."):
                out.append(name)
    except Exception:
        pass
    return {"ok": bool(out), "providers": out,
            "note": "Configure via provider key/model env (e.g. ANTHROPIC_API_KEY). "
                    "CEO default: keep TinyLLM fallback when no key is set."}


def main() -> None:
    cmd = sys.argv[1] if len(sys.argv) > 1 else "status"
    if cmd == "status":
        print(json.dumps(status(), indent=2))
    elif cmd == "skills":
        print(json.dumps({"ok": True, "skills": catalog()}, indent=2))
    elif cmd == "get" and len(sys.argv) > 2:
        r = get(sys.argv[2])
        if r.get("ok") and not (len(sys.argv) > 3 and sys.argv[3] == "--full"):
            c = r["content"]
            r["content"] = c[:1500] + "…" if len(c) > 1500 else c
        print(json.dumps(r, indent=2))
    elif cmd == "providers":
        print(json.dumps(providers(), indent=2))
    else:
        print(__doc__)


if __name__ == "__main__":
    main()
