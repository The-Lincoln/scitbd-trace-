---
name: tooljet
description: >
  ToolJet low-code dashboards, internal apps and workflows for SCITBD agents:
  embed KPI/lead/trace apps, fire workflow webhooks, query ToolJet apps via REST.
  Self-hosted or Cloud. Degrades gracefully when unconfigured.
---

# ToolJet — SCITBD Skill

Upstream: https://github.com/ToolJet/ToolJet.git (vendored at
`external/tooljet`). Docs: https://docs.tooljet.com. Canonical wrappers:
`ceo/tooljet.py` (Python), `autoflows/app/services/ToolJet.php` (PHP, class
`ToolJet`), shims `sccrm/services/ToolJetService.php`, `trace/ToolJetHook.php`.

## When to use

- CEO wants a live KPI/ops dashboard instead of a chat summary → embed the
  ToolJet CEO app (`TOOLJET_APP_CEO`) or link it from the briefing.
- New hot lead / completed trace should fan out (Slack, email, sheet) →
  fire the configured Workflow webhook (`TOOLJET_WORKFLOW_LEAD/TRACE`).
- Trace/SCCRM panels need an ops view without new PHP → iframe the shared
  ToolJet app (`ToolJet::embedUrl('sccrm')`).

## Task workflow

1. Status first: `ToolJet::status()` (PHP) / `python ceo/tooljet.py status`.
   `ok=false` means instance down or token missing — link the fallback page,
   never block the agent job.
2. Embed: `ToolJet::embedUrl('ceo|sccrm|trace')` → `<iframe src="…">`.
   Empty string = slug not configured → tell the user which slug to set.
3. Webhooks: `ToolJet::triggerWorkflow($url, $payload)` — URL carries its own
   secret; POST JSON; log via `ToolJet::logRun()`.
4. REST: `ToolJet::api('GET','/api/apps')` needs `TOOLJET_API_TOKEN`
   (ToolJet Profile → API tokens). Never log the token; `status()` only
   reports `has_api_token`.

Env: `TOOLJET_HOST` (default `http://127.0.0.1`), `TOOLJET_API_TOKEN`,
`TOOLJET_WORKSPACE_ID`, `TOOLJET_APP_CEO/SCCRM/TRACE`,
`TOOLJET_WORKFLOW_LEAD/TRACE`. Self-host:
`docker run -p 80:80 tooljet/try:ee-lts-latest`.
