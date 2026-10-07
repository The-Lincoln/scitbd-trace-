# -*- coding: utf-8 -*-
"""
SCITBD CEO — diagram-design bridge (Python side).

Editorial diagrams for CEO reports/proposals (42 types, HTML+SVG):
  https://github.com/The-Lincoln/diagram-design.git
  vendored: external/diagram-design/skills/diagram-design/
  (SKILL.md -> references/type-*.md -> assets/template*.html)

Usage:
    python diagram_design.py status
    python diagram_design.py types
    python diagram_design.py scaffold "Q3 revenue waterfall" waterfall
"""
from __future__ import annotations

import json
import os
import re
import sys

TRACE_ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
SKILL = os.path.join(TRACE_ROOT, "external", "diagram-design", "skills", "diagram-design")
OUT = os.path.join(TRACE_ROOT, "data", "diagrams")

try:
    sys.stdout.reconfigure(encoding="utf-8", errors="replace")
    sys.stderr.reconfigure(encoding="utf-8", errors="replace")
except Exception:
    pass


def types() -> dict:
    out = {}
    try:
        for f in sorted(os.listdir(os.path.join(SKILL, "references"))):
            if f.startswith("type-") and f.endswith(".md"):
                tid = f[5:-3]
                title = tid
                try:
                    with open(os.path.join(SKILL, "references", f), encoding="utf-8") as fh:
                        for line in fh:
                            m = re.match(r"#\s+(.+)", line.strip())
                            if m:
                                title = m.group(1).strip()
                                break
                except Exception:
                    pass
                out[tid] = title
    except Exception:
        pass
    return out


def scaffold(title: str, dtype: str, variant: str = "template") -> dict:
    if dtype not in types():
        return {"ok": False, "error": f"Unknown type '{dtype}' ({len(types())} available)"}
    tpl = os.path.join(SKILL, "assets", f"{variant}.html")
    if not os.path.isfile(tpl):
        tpl = os.path.join(SKILL, "assets", "template.html")
    slug = re.sub(r"[^a-z0-9]+", "-", title.lower()).strip("-") or "diagram"
    try:
        os.makedirs(OUT, exist_ok=True)
        with open(tpl, "rb") as s, open(os.path.join(OUT, slug + ".html"), "wb") as d:
            d.write(s.read())
    except Exception as ex:
        return {"ok": False, "error": str(ex)[:200]}
    return {"ok": True, "slug": slug, "type": dtype,
            "file": os.path.join(OUT, slug + ".html"),
            "reference": f"references/type-{dtype}.md"}


def main() -> None:
    args = sys.argv[1:]
    cmd = args[0] if args else "status"
    if cmd == "status":
        n = len(types())
        print(json.dumps({"ok": n > 0, "driver": "diagram-design", "types": n,
                          "vendor": "external/diagram-design",
                          "hint": "Ready." if n else "Missing vendor checkout"}, indent=2))
    elif cmd == "types":
        print(json.dumps(types(), indent=2))
    elif cmd == "scaffold" and len(args) > 2:
        print(json.dumps(scaffold(args[1], args[2], args[3] if len(args) > 3 else "template"), indent=2))
    else:
        print(__doc__)


if __name__ == "__main__":
    main()
