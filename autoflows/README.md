# AutoFlows

Self-hosted content studio. One brief in, three channels out: **daily social
posts**, a **blog article**, and a **marketing email** — driven by a TinyLLM
chat interface and a six-step AI agent, with everything traceable and editable
before it ships.

Built with plain **Bootstrap / HTML / CSS / JS / PHP / SQLite**. No build step,
no framework, no Composer.

---

## Why offline-first

Ollama may or may not be running. AutoFlows never blocks on it: every generator
has a deterministic template fallback, so the app is fully usable with the
network unplugged.

| Layer | When the model answers | When it doesn't |
|---|---|---|
| `TinyLLM` | streams tokens over SSE | `localReply()` canned responses |
| `ContentFactory` | parsed model output | `localDraft()` + 3-tier parse fallback |
| `Agent` | streamed step text | `localStep()` formatted answers |

The UI always shows which path ran — an `online` / `offline · template engine`
badge, never a silent downgrade.

---

## Requirements

- PHP **8.1+** with `pdo_sqlite` (tested on 8.3.30)
- Optional: a local [Ollama](https://ollama.com) server for real generations
- Nothing else

## Quick start

```bash
cd autoflows
php -S 127.0.0.1:8020 -t public public/router.php
```

Open <http://127.0.0.1:8020>.

The SQLite database at `storage/app.db` is created and migrated automatically
on first boot, and a starter flow named **Daily founder pack** is seeded.

### Apache

Point the document root at `public/`. `public/.htaccess` rewrites everything
through `index.php`; mod_rewrite and `AllowOverride` are required.

---

## What's in the box

| Route | Screen |
|---|---|
| `/` | Dashboard — stats, due-today queue, quick generate |
| `/chat` | TinyLLM chat — threads, streaming, slash commands, model controls |
| `/flows` | Scheduled flows — daily / weekly / manual |
| `/flow&id=` | Flow editor with live plan preview and save-and-run |
| `/content` | Content library — filters, bulk status, export |
| `/item&id=` | Editor with live preview, char counters, regenerate |
| `/agent` | FlowAgent console — live step trace, replay any run |
| `/settings` | TinyLLM connection + brand voice |

### Chat slash commands

| Command | Produces |
|---|---|
| `/social <topic>` | 3 platform-aware social variants |
| `/blog <topic>` | title, slug, excerpt, article, tags |
| `/email <topic>` | subject, preheader, headline, body, CTA |
| `/daily <topic>` | all three channels in one run |
| `/status` | model reachability and latency |
| `/help` | command list |

Every generated item lands in the library as an editable draft, and the reply
links back to the full agent trace.

### The FlowAgent plan

```
brief → outline → [social] [blog] [email] → review
```

Each step streams over SSE (`run_start` → `step` → `delta`* → `step_done` →
`done`), and the whole trace is persisted so any run can be replayed from the
agent console. The `review` step emits a `SCORE: n` quality gate.

---

## Configuration

`app/config.php` holds the file defaults; anything saved on the Settings page
is stored in the `settings` table and **wins** over the file.

| Key | Purpose |
|---|---|
| `chat.provider` | `ollama` or `local` (forces the template engine) |
| `chat.providers.ollama.endpoint` | default `http://127.0.0.1:11434` |
| `chat.providers.ollama.model` | e.g. `tinyllama` |
| `chat.defaults.temperature` / `num_predict` / `system` | generation defaults |
| `brand.*` | name, voice, audience, products, links, CTA, emoji, hashtags |

The brand block is injected into **every** prompt — all three generators and
all six agent steps — so one edit revoices the whole app.

After writing settings the current request calls `config_refresh()`, because
`config()` latches its merge per request.

---

## Schema

Seven tables in `schema.sql`, auto-created on boot:

`conversations` · `messages` · `flows` · `runs` · `content` · `settings` · `task_logs`

Migrations are **additive**: missing columns are added on boot, existing rows
are never dropped. `content` carries `channel`, `platform`, `status`, `chars`,
`score`, `run_id`, `hashtags`, `meta` (JSON) and `publish_at`.

Content lifecycle: `draft → approved → scheduled → published` (`archived` too).

---

## Daily automation

```bash
php tools/run_daily.php            # run everything due
php tools/run_daily.php --dry      # show what would run
php tools/run_daily.php --force    # ignore last_run_at, re-run today
php tools/run_daily.php --publish  # also move scheduled → published
```

A flow runs when it is active, not `manual`, past its `run_at`, and has not
already run today.

```bat
:: Windows Task Scheduler, every day at 09:00
schtasks /create /tn AutoFlows /tr "php E:\openaiVideo\autoflows\tools\run_daily.php" /sc daily /st 09:00
```

```cron
0 9 * * * php /path/to/autoflows/tools/run_daily.php --publish
```

---

## Testing

```bash
php tools/smoke_test.php           # full suite: lint + unit + live HTTP
php tools/smoke_test.php --fast    # skip the HTTP round-trip
```

The HTTP pass boots a real dev server and exercises every page, the JSON
endpoints, the 404 path, and all static assets — because the only reliable way
to catch a wrong `api/` prefix or a fatal inside a view is a live request.

**Always finish with a port check; it should print nothing:**

```bash
netstat -ano | grep ":8021"
```

A leaked `php -S` would otherwise serve stale content to the next run.

---

## Project layout

```
autoflows/
├── app/
│   ├── config.php            file defaults (DB settings override)
│   ├── core/                 Helpers, Database, Model, Controller, View, Router
│   ├── controllers/          Home, Chat, Flow, Content, Agent, Settings
│   ├── models/               Setting, Conversation, Message, Flow, Content, Run
│   ├── services/             TinyLLM, Sse, PromptLibrary, ContentFactory, Agent
│   └── views/                layout/ + 9 screens
├── public/
│   ├── index.php             front controller (?r= routes)
│   ├── router.php            PHP built-in server router
│   └── assets/               app.css + 9 JS files
├── tools/
│   ├── run_daily.php         cron worker
│   └── smoke_test.php        lint + unit + HTTP suite
├── schema.sql
└── storage/                  app.db, logs/, exports/ (created on boot)
```

### Design notes

- **`?r=` routing** — no rewrite dependency; `api/`-prefixed routes return
  JSON, everything else renders a view.
- **CSRF** — every state-changing call sends `X-CSRF-Token` from the
  `<meta name="csrf">` tag; `AF.api()` attaches it automatically.
- **SSE over `fetch()`** — `EventSource` cannot POST or send a CSRF header, so
  `AF.stream()` reads the response body and splits frames itself.
- **Server text is authoritative** — the client renders streamed deltas for
  feel, then replaces the bubble with the `meta.text` payload on `done`.

---

## License

MIT.
