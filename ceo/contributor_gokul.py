# -*- coding: utf-8 -*-
"""
SCITBD CEO — Gokul-ram-j contributor profile bridge (Python side).

Contributor profile (GitHub profile repo, not a runnable framework):
  https://github.com/The-Lincoln/Gokul-ram-j.git
  vendored: external/Gokul-ram-j (README + metrics SVGs + profile-3d-contrib)

Stack (from README): React/Next.js/Tailwind/Three.js/Vite, Node/Express,
Spring/FastAPI/Tomcat, JS/TS/Python/Java/C, MongoDB/MySQL/Firebase/Netlify,
Docker/Git/Postman/Swagger/Figma.
Capabilities: responsive web apps, UI/UX, scalable backends, DB design,
AI-powered web apps, LLM + RAG integration, cross-platform/mobile.

Companions:
  PHP canonical : autoflows/app/services/ContributorGokul.php
  SCCRM agent   : sccrm/ai/contributor_gokul_agent.php
  Skills        : ceo/.agents/skills/contributor-gokul/SKILL.md (canonical)

Usage:
    python contributor_gokul.py status
    python contributor_gokul.py stack
    python contributor_gokul.py match "RAG lead search UI"
"""
from __future__ import annotations

import json
import os
import sys

TRACE_ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
VENDOR = os.path.join(TRACE_ROOT, "external", "Gokul-ram-j")
REPO = "https://github.com/The-Lincoln/Gokul-ram-j.git"

STACK = {
    "frontend": ["HTML5", "CSS3", "Bootstrap", "React", "Next.js", "React Native",
                 "Tailwind CSS", "Three.js", "Vite.js", "Swiper.js"],
    "backend": ["Node.js", "Express.js", "Spring", "FastAPI", "Apache Tomcat"],
    "languages": ["JavaScript", "TypeScript", "Python", "Java", "C",
                  "JSON", "YAML", "LaTeX", "Markdown"],
    "data_cloud": ["MongoDB", "MySQL", "Firebase", "Netlify"],
    "tools": ["Docker", "NPM", "Nodemon", "Git", "GitHub", "VS Code",
              "Eclipse IDE", "Expo", "Postman", "Swagger", "Figma",
              "Notion", "LeetCode"],
}
CAPABILITIES = [
    "Problem Solving & Algorithms", "Competitive Programming",
    "Open Source Contributions", "Responsive Web Applications",
    "UI/UX Web Design", "Scalable Backend Systems",
    "Database Design & Management", "AI-Powered Web Applications",
    "LLM Integration", "RAG-Based Systems",
    "Cross-Platform Applications", "Web & Mobile Applications",
]
# keyword -> stack/capability hints for task matching
MATCH_HINTS = {
    "react": ("frontend", "React"), "next": ("frontend", "Next.js"),
    "tailwind": ("frontend", "Tailwind CSS"), "three": ("frontend", "Three.js"),
    "node": ("backend", "Node.js"), "express": ("backend", "Express.js"),
    "spring": ("backend", "Spring"), "fastapi": ("backend", "FastAPI"),
    "python": ("languages", "Python"), "java": ("languages", "Java"),
    "mongo": ("data_cloud", "MongoDB"), "mysql": ("data_cloud", "MySQL"),
    "firebase": ("data_cloud", "Firebase"), "docker": ("tools", "Docker"),
    "rag": ("capability", "RAG-Based Systems"), "llm": ("capability", "LLM Integration"),
    "ai": ("capability", "AI-Powered Web Applications"),
    "ui": ("capability", "UI/UX Web Design"), "ux": ("capability", "UI/UX Web Design"),
    "mobile": ("capability", "Cross-Platform Applications"),
    "expo": ("tools", "Expo"),
}

try:
    sys.stdout.reconfigure(encoding="utf-8", errors="replace")
except Exception:
    pass


def status() -> dict:
    readme = os.path.isfile(os.path.join(VENDOR, "README.md"))
    metrics = [f for f in os.listdir(VENDOR) if f.endswith(".svg")] if os.path.isdir(VENDOR) else []
    contrib = os.path.isdir(os.path.join(VENDOR, "profile-3d-contrib"))
    ok = readme
    return {"ok": ok, "driver": "contributor-gokul", "repo": REPO,
            "vendor": "external/Gokul-ram-j", "readme": readme,
            "metrics_svgs": len(metrics), "contrib_3d": contrib,
            "stack_groups": len(STACK), "capabilities": len(CAPABILITIES),
            "hint": "Ready." if ok else "Missing vendor checkout"}


def match_task(text: str) -> dict:
    words = text.lower().split()
    hits: list = []
    seen = set()
    for w in words:
        clean = "".join(c for c in w if c.isalnum())
        for key, (group, label) in MATCH_HINTS.items():
            if key in clean and label not in seen:
                hits.append({"group": group, "label": label, "via": key})
                seen.add(label)
    fit = min(100, 40 + 15 * len(hits)) if hits else 20
    return {"ok": True, "task": text[:200], "hits": hits,
            "fit_score": fit,
            "proposal": ("Assign" if fit >= 55 else "Consider") +
                        f" — {len(hits)} stack/capability match(es). Human reviews before assign."}


def main() -> None:
    cmd = sys.argv[1] if len(sys.argv) > 1 else "status"
    if cmd == "status":
        print(json.dumps(status(), indent=2))
    elif cmd == "stack":
        print(json.dumps({"ok": True, "stack": STACK, "capabilities": CAPABILITIES}, indent=2))
    elif cmd == "match" and len(sys.argv) > 2:
        print(json.dumps(match_task(" ".join(sys.argv[2:])), indent=2))
    else:
        print(__doc__)


if __name__ == "__main__":
    main()
