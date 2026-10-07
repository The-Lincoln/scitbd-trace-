# -*- coding: utf-8 -*-
"""SCITBD CEO — harness-guide bridge (Python side): search both awesome-harness lists."""
from __future__ import annotations

import json
import os
import re
import sys

TRACE_ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
SOURCES = {
    "walkinglabs": os.path.join(TRACE_ROOT, "external", "awesome-harness-walkinglabs", "README.md"),
    "aiboost": os.path.join(TRACE_ROOT, "external", "awesome-harness-aiboost", "README.md"),
}

try:
    sys.stdout.reconfigure(encoding="utf-8", errors="replace")
    sys.stderr.reconfigure(encoding="utf-8", errors="replace")
except Exception:
    pass


def search(query: str, limit: int = 12) -> dict:
    words = [w.lower() for w in re.split(r"\s+", query.strip()) if len(w) > 2] or [query.lower()]
    hits = []
    for src, path in SOURCES.items():
        try:
            with open(path, encoding="utf-8") as f:
                lines = f.read().splitlines()
        except Exception:
            continue
        cur = ""
        for line in lines:
            m = re.match(r"#{1,3}\s+(.+)", line.strip())
            if m:
                cur = m.group(1).strip()
                continue
            for title, url in re.findall(r"\[([^\]]+)\]\((https?[^)]+)\)", line):
                hay = f"{cur} {title}".lower()
                score = sum(len(w) for w in words if w in hay)
                if score:
                    hits.append({"score": score, "source": src, "section": cur,
                                 "title": title, "url": url})
    hits.sort(key=lambda h: -h["score"])
    return {"ok": True, "hits": hits[:max(1, min(limit, 30))]}


def main() -> None:
    cmd = sys.argv[1] if len(sys.argv) > 1 else "status"
    if cmd == "status":
        n = sum(os.path.isfile(p) for p in SOURCES.values())
        print(json.dumps({"ok": n > 0, "driver": "harness-guide", "sources": n,
                          "hint": "Ready." if n else "Missing vendor checkouts"}, indent=2))
    elif cmd == "search" and len(sys.argv) > 2:
        print(json.dumps(search(sys.argv[2]), indent=2))
    else:
        print(__doc__)


if __name__ == "__main__":
    main()
