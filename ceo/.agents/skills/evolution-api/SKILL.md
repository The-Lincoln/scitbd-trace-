---
name: evolution-api
description: >
  Evolution API WhatsApp messaging for SCITBD agents: lead outreach, CEO
  escalation alerts, trace summaries via WhatsApp. Instance QR connect,
  sendText/sendMedia, webhooks. Degrades gracefully when unconfigured.
---

# Evolution API — SCITBD Skill

Upstream: https://github.com/The-Lincoln/evolution-api.git (vendored at
`external/evolution-api` — Evolution Foundation). Docs:
https://docs.evolutionfoundation.com.br. Canonical wrappers:
`ceo/evolution_api.py` (Python), `autoflows/app/services/EvolutionApi.php`
(PHP, class `EvolutionApi`), shims `sccrm/services/EvolutionApiService.php`,
`trace/EvolutionHook.php`.

## When to use

- Hot lead (85+) or $10k+ deal needs instant outreach → SCCRM sends the
  templated WhatsApp follow-up via the `scitbd` instance.
- CEO escalation triggers (Section 7.1: NPS<40, negative press, deadline
  overrun) → `alertCeo()` pings `EVOLUTION_CEO_NUMBER`.
- Completed trace intel should reach the owner on mobile → `EvolutionHook`.

## Task workflow

1. Status first: `EvolutionApi::status()` / `python ceo/evolution_api.py status`.
   `state=open` = linked. Otherwise open Manager UI → instance → QR scan.
2. Send: `EvolutionApi::sendText($to, $text)` (`$to` = international digits,
   e.g. `8801XXXXXXXXX`). Media: `sendMedia($to, $urlOrBase64, $type)`.
3. CEO: `EvolutionApi::alertCeo($text)` (uses `EVOLUTION_CEO_NUMBER`).
   Python: `python ceo/evolution_api.py alert-ceo "…"`.
4. Never block CRM writes on WhatsApp downtime — send, `logRun()`, continue.
   Inbound replies arrive via instance webhook (`/webhook/set/:instance`).

Auth: `apikey` header = global `EVOLUTION_API_KEY` or per-instance
`EVOLUTION_INSTANCE_TOKEN` (least privilege wins). Self-host:
`docker run -p 8080:8080 evoapicloud/evolution-api:latest`.
Env: `EVOLUTION_API_URL` (default `http://127.0.0.1:8080`),
`EVOLUTION_API_KEY`, `EVOLUTION_INSTANCE` (default `scitbd`),
`EVOLUTION_INSTANCE_TOKEN`, `EVOLUTION_CEO_NUMBER`.

## Inbound webhooks (WhatsApp → CRM)

- Receiver: `sccrm/chat/evolution_webhook.php` (secret-guarded, no session,
  always 200; stores `MESSAGES_UPSERT` into `whatsapp_messages`).
- Register: `php tools/install_evolution_api.php --register https://<host>`
  or `EvolutionApiService::registerInbound($base)` (events:
  `MESSAGES_UPSERT`, `MESSAGES_UPDATE`, `CONNECTION_UPDATE`, `QRCODE_UPDATED`).
- Inbox + replies: `sccrm/chat/whatsapp.php` (status badge, QR connect,
  latest 50, reply box). Guard secret: `EVOLUTION_WEBHOOK_SECRET`.
