"""CEO shim for Social Analyzer — delegates to canonical PHP service + vendor CLI."""
import json
import os
import subprocess

VENDOR = os.path.join(os.path.dirname(os.path.dirname(os.path.abspath(__file__))), "external", "social-analyzer")
REPO = "https://github.com/The-Lincoln/social-analyzer.git"


def status():
    app_py = os.path.join(VENDOR, "app.py")
    return {
        "ok": os.path.isfile(app_py),
        "driver": "social-analyzer",
        "vendor": "external/social-analyzer",
        "repo": REPO,
        "base_url": os.environ.get("SOCIAL_ANALYZER_URL", "http://127.0.0.1:9005"),
        "python": os.environ.get("SOCIAL_ANALYZER_PYTHON", "python3"),
    }


def analyze(username, mode="fast", top=0, metadata=False, timeout=120):
    """Run vendored app.py --output json. Never raises — returns dict."""
    import time

    t0 = time.time()
    username = (username or "").strip()
    if not username:
        return {"ok": False, "error": "username required", "ms": 0}
    app_py = os.path.join(VENDOR, "app.py")
    if not os.path.isfile(app_py):
        return {"ok": False, "error": f"vendor missing: {app_py} (git clone {REPO})", "ms": 0}
    py = os.environ.get("SOCIAL_ANALYZER_PYTHON", "python3")
    cmd = [py, app_py, "--username", username, "--mode", mode, "--output", "json"]
    if top:
        cmd += ["--top", str(int(top))]
    if metadata:
        cmd += ["--metadata"]
    try:
        p = subprocess.run(cmd, cwd=VENDOR, capture_output=True, text=True, timeout=max(15, min(600, timeout)))
        raw = (p.stdout or "").strip() or (p.stderr or "").strip()
        try:
            data = json.loads(raw[raw.index("{"): raw.rindex("}") + 1] if not raw.lstrip().startswith(("{", "[")) else raw)
        except Exception as e:  # noqa: BLE001
            return {"ok": False, "error": f"could not parse JSON output: {e}", "raw_excerpt": raw[:2000], "ms": int((time.time() - t0) * 1000)}
        profiles = []
        if isinstance(data, list):
            profiles = [r for r in data if isinstance(r, dict)]
        elif isinstance(data, dict):
            profiles = [{"site": k, **(v if isinstance(v, dict) else {"value": v})} for k, v in data.items()]
        return {"ok": True, "username": username, "profiles": profiles,
                "summary": {"total": len(profiles)}, "ms": int((time.time() - t0) * 1000)}
    except subprocess.TimeoutExpired:
        return {"ok": False, "error": f"timeout after {timeout}s", "ms": int((time.time() - t0) * 1000)}
    except Exception as e:  # noqa: BLE001
        return {"ok": False, "error": str(e), "ms": int((time.time() - t0) * 1000)}


if __name__ == "__main__":
    import sys

    u = sys.argv[1] if len(sys.argv) > 1 else ""
    print(json.dumps(analyze(u) if u else status(), indent=2))
