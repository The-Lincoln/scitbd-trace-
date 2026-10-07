# -*- coding: utf-8 -*-
"""
SCITBD CEO — scientific-skills bridge (Python side).

177 validated Agent Skills for research agents (K-Dense, MIT):
  https://github.com/The-Lincoln/scientific-agent-skills.git
  vendored: external/scientific-agent-skills/skills/<name>/SKILL.md

File-based library, no daemon. Catalog + search + load for prompt
injection. Read ONLY needed skills; deps install per-skill via uv.

Usage:
    python scientific_skills.py status
    python scientific_skills.py search "virtual screening"
    python scientific_skills.py get rdkit
"""
from __future__ import annotations

import json
import os
import re
import sys
import time

TRACE_ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
VENDOR = os.path.join(TRACE_ROOT, "external", "scientific-agent-skills", "skills")

try:
    sys.stdout.reconfigure(encoding="utf-8", errors="replace")
    sys.stderr.reconfigure(encoding="utf-8", errors="replace")
except Exception:
    pass


def _fm(path: str) -> dict:
    out = {"name": os.path.basename(os.path.dirname(path)), "description": "", "version": ""}
    try:
        with open(path, "r", encoding="utf-8") as f:
            raw = f.read(4000)
    except Exception:
        return out
    if not raw.startswith("---"):
        return out
    end = raw.find("\n---", 3)
    fm = raw[3:end] if end != -1 else ""
    for k in ("name", "description", "version"):
        m = re.search(rf"^{k}:\s*(.+)$", fm, re.M)
        if m:
            out[k] = m.group(1).strip().strip("\"'")
    if not out["version"]:
        m = re.search(r"^\s+version:\s*(.+)$", fm, re.M)
        if m:
            out["version"] = m.group(1).strip().strip("\"'")
    return out


def catalog() -> list:
    out = []
    try:
        names = sorted(os.listdir(VENDOR))
    except Exception:
        return out
    for name in names:
        f = os.path.join(VENDOR, name, "SKILL.md")
        if os.path.isfile(f):
            fm = _fm(f)
            out.append({"id": name, "name": fm["name"] or name,
                        "description": fm["description"][:300], "version": fm["version"]})
    return out


def search(query: str, limit: int = 10) -> dict:
    t0 = time.time()
    words = [w.lower() for w in re.split(r"\s+", query.strip()) if len(w) > 2] or [query.lower()]
    hits = []
    for s in catalog():
        hay = f"{s['id']} {s['name']} {s['description']}".lower()
        score = sum(len(w) for w in words if w in hay)
        if score:
            hits.append({"score": score, **s})
    hits.sort(key=lambda h: -h["score"])
    return {"ok": True, "hits": hits[:max(1, min(limit, 30))],
            "ms": int((time.time() - t0) * 1000)}


def get(skill_id: str) -> dict:
    skill_id = (skill_id or "").strip()
    if not skill_id or ".." in skill_id or "/" in skill_id:
        return {"ok": False, "error": "invalid skill id"}
    f = os.path.join(VENDOR, skill_id, "SKILL.md")
    if not os.path.isfile(f):
        return {"ok": False, "error": "skill not found"}
    with open(f, "r", encoding="utf-8") as fh:
        body = fh.read()
    if body.startswith("---"):
        end = body.find("\n---")
        if end != -1:
            body = body[end + 4:].lstrip()
    return {"ok": True, "id": skill_id, "content": body, "chars": len(body)}


def main() -> None:
    cmd = sys.argv[1] if len(sys.argv) > 1 else "status"
    if cmd == "status":
        n = len(catalog())
        print(json.dumps({"ok": n > 0, "driver": "scientific-skills", "skills": n,
                          "vendor": "external/scientific-agent-skills",
                          "hint": "Ready." if n else "Missing vendor checkout"}, indent=2))
    elif cmd == "search" and len(sys.argv) > 2:
        print(json.dumps(search(sys.argv[2]), indent=2))
    elif cmd == "get" and len(sys.argv) > 2:
        r = get(sys.argv[2])
        if r.get("ok") and len(sys.argv) > 3 and sys.argv[3] == "--full":
            print(json.dumps(r, indent=2))
        else:
            print(json.dumps({k: (v[:800] + "…" if k == "content" and len(v) > 800 else v)
                              for k, v in r.items()}, indent=2))
    else:
        print(__doc__)


if __name__ == "__main__":
    main()
