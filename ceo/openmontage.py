# -*- coding: utf-8 -*-
"""
SCITBD CEO — openmontage bridge (Python side).

Agentic video production for CEO/content agents:
  https://github.com/calesthio/OpenMontage.git
  vendored: external/openmontage (AGENT_GUIDE.md -> PROJECT_CONTEXT.md ->
  pipeline_defs/*.yaml -> skills/pipelines/* -> tools/*)

Model: agents own orchestration; this bridge handles discovery (pipelines),
intake (brief scaffold under data/video-projects/), status and audit.
Renders run in the vendored checkout (ffmpeg + python + node; optional
provider keys in external/openmontage/.env).

Usage:
    python openmontage.py status
    python openmontage.py pipelines
    python openmontage.py models
    python openmontage.py preflight
    python openmontage.py request "SCITBD intro" "60s animated explainer…" --pipeline animated-explainer --budget 5 --model tts=elevenlabs/eleven-v3
    python openmontage.py projects
"""
from __future__ import annotations

import json
import os
import re
import shutil
import sqlite3
import subprocess
import sys
from datetime import datetime

TRACE_ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
VENDOR = os.path.join(TRACE_ROOT, "external", "openmontage")
PROJECTS = os.path.join(TRACE_ROOT, "data", "video-projects")

DEFAULT_MODELS = {
    "video_generation": {"provider": "fal/seedance", "model": "seedance-2-0", "key": "FAL_KEY"},
    "image_generation": {"provider": "fal/flux", "model": "flux-pro", "key": "FAL_KEY"},
    "tts": {"provider": "elevenlabs", "model": "eleven-multilingual-v2", "key": "ELEVENLABS_API_KEY"},
    "stt": {"provider": "whisper-local", "model": "faster-whisper", "key": ""},
    "music_generation": {"provider": "suno", "model": "suno-v4", "key": "SUNO_API_KEY"},
    "avatar": {"provider": "heygen", "model": "avatar-v", "key": "HEYGEN_API_KEY"},
    "3d_asset_generation": {"provider": "atlas_3d", "model": "atlas-3d", "key": ""},
}

PIPELINE_DESCRIPTIONS = {
    "animated-explainer": "AI explainer with research, narration, visuals, music",
    "animation": "Motion graphics, kinetic typography, animated sequences",
    "avatar-spokesperson": "Avatar-driven presenter videos",
    "cinematic": "Trailers, teasers, mood-driven edits",
    "clip-factory": "Ranked short-form clips from one long source",
    "documentary-montage": "Real-footage montage from free/open archives",
    "hybrid": "Source footage + AI-generated support visuals",
    "localization-dub": "Subtitle, dub and translate existing video",
    "podcast-repurpose": "Podcast highlights to video",
    "screen-demo": "Software walkthroughs and tutorials",
    "talking-head": "Footage-led speaker videos",
    "character-animation": "SVG/GSAP rigged character acting",
    "framework-smoke": "Minimal 2-stage smoke test (test only)",
}


def budget_cap() -> float:
    try:
        return float(os.environ.get("OPENMONTAGE_BUDGET_CAP", "10"))
    except ValueError:
        return 10.0

try:
    sys.stdout.reconfigure(encoding="utf-8", errors="replace")
    sys.stderr.reconfigure(encoding="utf-8", errors="replace")
except Exception:
    pass


def _bin(name: str) -> str:
    return shutil.which(name) or ""


def _ver(binary: str) -> str:
    if not binary:
        return "missing"
    flag = "-version" if os.path.basename(binary).lower().startswith("ffmpeg") else "--version"
    try:
        p = subprocess.run([binary, flag], capture_output=True, text=True, timeout=10)
        line = (p.stdout.strip() or p.stderr.strip()).splitlines()
        return (line[0][:100] if line else "?")
    except Exception:
        return "?"


def pipelines() -> dict:
    d = os.path.join(VENDOR, "pipeline_defs")
    out = {}
    try:
        for f in sorted(os.listdir(d)):
            if f.endswith(".yaml"):
                pid = f[:-5]
                out[pid] = PIPELINE_DESCRIPTIONS.get(pid, pid)
    except Exception:
        pass
    return out


def preflight(timeout: int = 120) -> dict:
    """Vendor registry capability menu (AGENT_GUIDE.md mandatory preflight)."""
    code = ("from tools.tool_registry import registry;"
            " import json; registry.discover();"
            " print(json.dumps(registry.provider_menu_summary()))")
    try:
        env = dict(os.environ, PYTHONUTF8="1", PYTHONIOENCODING="utf-8")
        p = subprocess.run([sys.executable, "-c", code], capture_output=True,
                           text=True, timeout=timeout, cwd=VENDOR, env=env)
        menu = json.loads((p.stdout.strip() or "{}"))
        if not isinstance(menu, dict) or "capabilities" not in menu:
            return {"ok": False, "error": (p.stderr.strip() or p.stdout.strip())[:300] or "empty probe"}
        return {"ok": True, "composition_runtimes": menu.get("composition_runtimes", {}),
                "capabilities": menu.get("capabilities", []),
                "setup_offers": menu.get("setup_offers", []),
                "runtime_warnings": menu.get("runtime_warnings", [])}
    except Exception as ex:
        return {"ok": False, "error": str(ex)[:300]}


def status() -> dict:
    ff = _bin("ffmpeg")
    py = _bin("python") or _bin("py")
    node = _bin("node")
    pipes = pipelines()
    ok = bool(pipes) and bool(ff) and bool(py)
    return {"ok": ok, "driver": "openmontage", "vendor": "external/openmontage",
            "ffmpeg": _ver(ff) if ff else "missing",
            "python": _ver(py) if py else "missing",
            "node": _ver(node) if node else "missing",
            "pipelines": len(pipes),
            "hint": "Ready." if ok else "Need: vendored checkout + ffmpeg + python (+ node for Remotion)"}


def _slug(title: str) -> str:
    s = re.sub(r"[^a-z0-9]+", "-", title.lower()).strip("-")
    return s or ("production-" + datetime.now().strftime("%Y%m%d-%H%M%S"))


def _db():
    for p in (os.path.join(TRACE_ROOT, "autoflows", "storage", "app.db"),
              os.path.join(TRACE_ROOT, "sccrm", "db", "scit_crm.db"),
              os.path.join(TRACE_ROOT, "ceo", "scitbd_ceo.db"),
              os.path.join(TRACE_ROOT, "data", "osint.db")):
        if os.path.isfile(p):
            try:
                con = sqlite3.connect(p)
                con.execute("""CREATE TABLE IF NOT EXISTS openmontage_runs (
                    id INTEGER PRIMARY KEY AUTOINCREMENT, module TEXT DEFAULT 'ceo',
                    pipeline TEXT DEFAULT 'animated-explainer', slug TEXT DEFAULT '',
                    title TEXT DEFAULT '', status TEXT DEFAULT 'requested',
                    cost_usd REAL DEFAULT 0, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)""")
                con.commit()
                return con
            except Exception:
                continue
    return None


def request(title: str, brief: str, pipeline: str = "animated-explainer",
            module: str = "ceo", model_over: dict | None = None,
            budget_usd: float | None = None) -> dict:
    pipes = pipelines()
    if pipeline not in pipes:
        return {"ok": False, "error": f"Unknown pipeline. Available: {', '.join(sorted(pipes)) or 'none'}"}
    cap = budget_cap()
    budget = float(budget_usd) if budget_usd is not None else cap
    if budget > cap:
        return {"ok": False, "error": f"budget_usd {budget} exceeds cap {cap} (OPENMONTAGE_BUDGET_CAP)"}
    models = {c: ((model_over or {}).get(c) or f"{d['provider']}/{d['model']}")
              for c, d in DEFAULT_MODELS.items()}
    slug, i = _slug(title), 2
    while os.path.exists(os.path.join(PROJECTS, slug)) and i < 1000:
        slug, i = f"{_slug(title)}-{i}", i + 1
    d = os.path.join(PROJECTS, slug)
    try:
        os.makedirs(d, exist_ok=True)
        model_lines = "".join(f"- {c}: {m}\n" for c, m in models.items())
        with open(os.path.join(d, "brief.md"), "w", encoding="utf-8") as f:
            f.write(f"# {title}\n\n- Pipeline: {pipeline}\n- Module: {module}\n"
                    f"- Requested: {datetime.now().strftime('%Y-%m-%d %H:%M:%S')}\n"
                    f"- Budget USD: {budget} (cap {cap})\n\n## Brief\n\n{brief}\n\n## Models\n\n{model_lines}\n")
        with open(os.path.join(d, "models.json"), "w", encoding="utf-8") as f:
            json.dump({"pipeline": pipeline, "budget_usd": budget, "models": models}, f, indent=2)
    except Exception as ex:
        return {"ok": False, "error": str(ex)[:200]}
    con = _db()
    if con:
        try:
            con.execute("INSERT INTO openmontage_runs (module,pipeline,slug,title,status) VALUES (?,?,?,?,?)",
                        (module, pipeline, slug, title[:200], "requested"))
            con.commit()
            con.close()
        except Exception:
            pass
    return {"ok": True, "slug": slug, "pipeline": pipeline, "dir": d,
            "budget_usd": budget, "models": models,
            "next": f"Agent runs pipeline_defs/{pipeline}.yaml in external/openmontage"}


def projects(limit: int = 20) -> list:
    con = _db()
    if not con:
        return []
    try:
        rows = con.execute("SELECT * FROM openmontage_runs ORDER BY id DESC LIMIT ?",
                           (limit,)).fetchall()
        cols = [c[0] for c in con.execute("SELECT * FROM openmontage_runs LIMIT 0").description or []]
        con.close()
        return [dict(zip(cols, r)) for r in rows]
    except Exception:
        return []


def main() -> None:
    args = sys.argv[1:]
    cmd = args[0] if args else "status"
    if cmd == "status":
        print(json.dumps(status(), indent=2))
    elif cmd == "pipelines":
        print(json.dumps(pipelines(), indent=2))
    elif cmd == "models":
        print(json.dumps(DEFAULT_MODELS, indent=2))
    elif cmd == "preflight":
        print(json.dumps(preflight(), indent=2))
    elif cmd == "request" and len(args) > 2:
        pipe, module, budget, model_over = "animated-explainer", "ceo", None, {}
        for i, a in enumerate(args):
            if a == "--pipeline" and i + 1 < len(args):
                pipe = args[i + 1]
            if a == "--module" and i + 1 < len(args):
                module = args[i + 1]
            if a == "--budget" and i + 1 < len(args):
                budget = float(args[i + 1])
            if a == "--model" and i + 1 < len(args):
                k, _, v = args[i + 1].partition("=")
                if k.strip() and v.strip():
                    model_over[k.strip()] = v.strip()
        print(json.dumps(request(args[1], args[2], pipe, module, model_over, budget), indent=2))
    elif cmd == "projects":
        print(json.dumps(projects(), indent=2))
    else:
        print(__doc__)


if __name__ == "__main__":
    main()
