# AutoFlows Content Studio — User Manual
**Version 1.0.0 | Last updated: Oct 05, 2026**

> One brief in, three channels out: daily social posts, a blog article, and a marketing email.

AutoFlows is a self-hosted content studio built with **PHP + SQLite + Bootstrap + Vanilla JS**. No build step, no Composer, no framework. It works **offline-first**: if Ollama / TinyLLM is unreachable, a built-in template engine takes over and the UI tells you with an `offline · template engine` badge.

---

## Table of Contents
1. [What AutoFlows Does](#1-what-autoflows-does)
2. [Requirements & First Launch](#2-requirements--first-launch)
3. [App Map — Where Everything Lives](#3-app-map--where-everything-lives)
4. [Dashboard — Your Daily Start](#4-dashboard--your-daily-start)
5. [Chat (TinyLLM) — Conversational Creation](#5-chat-tinyllm--conversational-creation)
6. [Flows — Repeatable Recipes](#6-flows--repeatable-recipes)
7. [FlowAgent — 6-Step AI Console](#7-flowagent--6-step-ai-console)
8. [Content Library — Review & Manage](#8-content-library--review--manage)
9. [Editor — Polish Before You Publish](#9-editor--polish-before-you-publish)
10. [Publishing — Gmail + Facebook](#10-publishing--gmail--facebook)
11. [Settings — Model + Brand Voice](#11-settings--model--brand-voice)
12. [Daily Automation (Cron / Task Scheduler)](#12-daily-automation-cron--task-scheduler)
13. [Offline Mode & Badges](#13-offline-mode--badges)
14. [Worked Examples End-to-End](#14-worked-examples-end-to-end)
15. [Troubleshooting & FAQ](#15-troubleshooting--faq)
16. [Content Lifecycle & Keyboard Tips](#16-content-lifecycle--keyboard-tips)

---

## 1. What AutoFlows Does

| Input | Output |
|-------|--------|
| A single **brief / goal** like “why small teams should publish weekly” | **3x Social posts** (X, LinkedIn, Instagram, Facebook, Threads, TikTok) + **1x Blog** (title, slug, excerpt, body, tags) + **1x Email** (subject, preheader, headline, body, CTA) |

Everything is:
- **Traceable**: every run saves `brief → outline → [social] [blog] [email] → review` with a quality `SCORE: n`
- **Editable**: every item lands as `draft` in the Content Library
- **Schedulable**: `draft → approved → scheduled → published` (+ `archived`)

## 2. Requirements & First Launch

**You need:**
- PHP 8.1+ with `pdo_sqlite` (tested on 8.3.30)
- Optional: [Ollama](https://ollama.com) running locally for real AI generations

**Step-by-step to start:**

```bash
# 1. Open terminal in project folder
cd E:\openaiVideo\autoflows

# 2. Start PHP dev server
php -S 127.0.0.1:8020 -t public public/router.php

# 3. Open in browser
http://127.0.0.1:8020
```

What happens on first boot:
1. `storage/app.db` is created + migrated automatically
2. A starter flow called **Daily founder pack** is seeded
3. Dashboard shows `offline · template engine` until Ollama connects

**For Apache (production):**
1. Point DocumentRoot at `public/`
2. Enable `mod_rewrite` + `AllowOverride All`
3. `public/.htaccess` rewrites everything through `index.php`

**For Ollama (optional but recommended):**
```bash
ollama serve
ollama pull tinyllama
# or stronger writing:
ollama pull qwen2.5:1.5b
```
Then go to `Settings → TinyLLM connection → Test connection`.

## 3. App Map — Where Everything Lives

| URL (`?r=`) | Screen | Use it to… |
|-------------|--------|------------|
| `/` or `?r=home` | **Dashboard** | See stats, due-today queue, Quick Generate |
| `?r=chat` | **TinyLLM Chat** | Talk + use `/social /blog /email /daily` commands |
| `?r=flows` | **Flows** | List daily / weekly / manual recipes |
| `?r=flow&id=1` | **Flow Editor** | Create / edit a recipe + Save & Run |
| `?r=content` | **Content Library** | Filter, bulk approve, export |
| `?r=item&id=12` | **Editor** | Edit with live preview, regenerate, publish |
| `?r=agent` | **FlowAgent Console** | Run 6-step agent, watch live trace, replay runs |
| `?r=settings` | **Settings** | Model, brand voice, OAuth, database info |
| `?r=login` | **Login** | Continue with Google / Facebook or Dev login |

Top nav always shows: LLM status badge (`online` green / `offline` yellow), user avatar, Channels status.

## 4. Dashboard — Your Daily Start

Open `http://127.0.0.1:8020/`

You see:
- **6 stat cards**: Today, Queue, Drafts, Published, Flows, Items
- **Quick Generate** (left): one brief, one click
- **Schedule / Today's Queue** (right): what's due
- **Activity feed**: recent runs + publishes

### Example: Quick Generate in 30 seconds
1. Stay on Dashboard
2. Click tab: `Social posts` | `Blog post` | `Marketing email`
3. In **Brief** type:
   > why small teams should publish weekly instead of chasing virality
4. Set **Tone**: `Warm`, **Variants**: `3`
5. Click **Generate**
6. Watch progress bar → “Done — 3 drafts → View in Content”
7. Click link → Content Library shows 3 new `draft` items

> Tip: Quick Generate always creates `draft`s. Nothing auto-publishes.

## 5. Chat (TinyLLM) — Conversational Creation

Go to `Chat` in nav. Layout:
- **Left rail**: Threads ( + to create new, click to switch, pin/active highlight)
- **Center**: Streaming thread with Copy / Trace / Delete per message
- **Right / Below**: Model controls (temperature, tokens, system)

### 5.1 Basic chat
1. Click **+** for New chat
2. Type in box: `Give me 5 hooks for a post about content loops`
3. Press **Enter** (Shift+Enter for newline)
4. Watch streaming → final bubble replaces deltas when `done` arrives

Buttons in header:
- `Export` (Markdown download), `Eraser` (clear thread), `Trash` (delete thread)

### 5.2 Slash commands — the real power
Type these in chat input and hit Enter:

| Command | What to type | What you get |
|---------|--------------|--------------|
| `/social` | `/social why small teams should publish weekly` | 3 platform-aware variants (e.g. X 280 chars, LinkedIn 3000) |
| `/blog` | `/blog building a repeatable content loop` | title + slug + excerpt + 400-600w article + tags |
| `/email` | `/email re-engaging dormant trial users` | subject + preheader + headline + body + CTA |
| `/daily` | `/daily content marketing without a big team` | all three channels in one run |
| `/status` | `/status` | model reachability + latency ms |
| `/help` | `/help` | command list |

**Example session:**
```
You: /daily launch checklist for solo founders
AutoFlows: [streams brief → outline → social → blog → email → review SCORE: 82]
           Created 5 items: #12 #13 #14 #15 #16 [View library] [Trace #45]
```

Every generated item links back to **Trace** (`?r=agent&run=45`) for full replay.

### 5.3 Model controls
- **Temperature** 0-2 (default 0.75): lower = safer, higher = creative
- **Max tokens** 64-4096 (default 768)
- **System prompt**: defaults from Settings, editable per thread

## 6. Flows — Repeatable Recipes

Go to `Flows`. Each flow = reusable prompt + schedule.

Fields:
- **Name*** e.g. `Weekly founder pack`
- **Channel***: Social | Blog | Email | Daily pack (all three)
- **Standing brief***: stable theme prepended to every run
- **Variants**: 1-10 (how many social variants per run)
- **Tone**: warm / direct / playful / authoritative / friendly / contrarian
- **Audience**: e.g. `founders, small marketing teams`
- **Platforms** (social only): checkboxes X, LinkedIn, Instagram, Facebook, Threads, TikTok with char limits shown
- **Schedule**: Every day / Once a week / Manual only
- **Weekday** (if weekly): Monday-Sunday
- **Run at**: HH:MM e.g. `09:00`
- **Active** toggle

### Example: Create a Daily Pack (step-by-step)
1. `Flows → New flow` (`?r=flow` or `Flows #new`)
2. **Name**: `Daily founder pack`
3. **Channel**: `Daily pack (all three)`
4. **Standing brief**:
   > practical content-marketing lessons for small teams, no hype, short sentences
5. **Variants**: `3`, **Tone**: `warm`, **Audience**: `founders`
6. **Schedule**: `Every day`, **Run at**: `09:00`, **Active**: ON
7. Watch **Live plan preview** on right update: `brief → outline → [social][blog][email] → review`
8. Click **Save** → then **Save & Run** to test now
9. You’re redirected to Agent trace; outputs appear in Content as drafts

### Run / Pause / Delete
- **Run now** button on row (manual trigger, logs `trigger_by=manual`)
- **Toggle** switch = active/inactive (inactive never runs in cron)
- **Delete** asks confirm, keeps content (flow_id set NULL)

## 7. FlowAgent — 6-Step AI Console

Go to `Run FlowAgent` / `?r=agent`. This is the full pipeline with live streaming.

Plan always:
```
brief → outline → [social] [blog] [email] → review (SCORE: n)
```

Events over SSE: `run_start → step → delta* → step_done → done`

### Example: Full Agent Run
1. Open `Agent`
2. **Goal**:
   > why small teams should publish weekly instead of chasing virality
3. **Channels**: check all three (uncheck Email if you only want Social+Blog)
4. **Social platforms**: check `X / Twitter`, `LinkedIn`, `Facebook` (first 3 default)
5. **Tone**: `Brand default` (or override), **Social variants**: `3`
6. **Audience override**: leave blank (uses brand voice) or type `indie hackers`
7. Click **Run agent**
8. Watch **Plan** list light up: `brief… ✓ → outline… ✓ → social streaming…`
9. **Trace** panel shows live markdown per step
10. On `done`: banner `Run #46 done · 5 outputs · SCORE 84 (threshold 70)`
11. **Outputs** cards appear below with Edit / Approve buttons
12. **Run history** (right/bottom): click any past run → replay full trace (`?r=agent&run=46`)

> If score < 70, review step flags it yellow — edit manually in Content.

## 8. Content Library — Review & Manage

Go to `Content`. You see filters + grid/table of items.

Filters:
- **Channel**: All / Social / Blog / Email
- **Status**: draft / approved / scheduled / published / archived
- **Search**: title/body/hashtags
- **Sort**: Newest / Score / Chars

Bulk actions:
1. Tick checkboxes (or Select all)
2. Choose: `Approve`, `Schedule`, `Publish`, `Archive`, `Delete`
3. Click Apply → toast confirms

Export:
- `Export CSV` / `Export JSON` → saved to `storage/exports/` + download link

**Example workflow:**
1. Filter `Status=draft`
2. Open newest pack items
3. Tick 3 social → `Approve`
4. Set `Publish at` for tomorrow 09:00 → `Schedule`
5. Tomorrow cron publishes or you click Publish now

## 9. Editor — Polish Before You Publish

Open any item (`?r=item&id=12`). Split view: **Form left, Live preview right**.

### For Social:
- **Body** (Markdown) + char counter `182 / 280` (red if over)
- **Hashtags** field e.g. `#contentmarketing #smallteams`
- **Platform** badge shows limit: X 280, Threads 500, LinkedIn 3000, etc.

### For Blog:
- **Title**, **Slug** (auto from title, editable: `repeatable-content-loop`), **Excerpt** `0 / 160`, **Body** Markdown, **Tags**

### For Email:
- **Subject line** `0 / 45` counter, **Preheader**, **CTA button**, **In-email headline**, **Body** (plain text → rendered HTML preview)

Common right-side:
- **Status** dropdown, **Publish at** datetime-local, **Score** badge
- **Trace** button (if from run), **Regenerate** button
- **Publish box**: `To` (for Gmail) + `Publish` button + last `publish_result` JSON
- **Save** (Ctrl+S), **Preview** auto-updates on typing

### Example: Edit + Approve + Schedule
1. Open draft #12 (X post, 312/280 red)
2. Trim body to 240 chars → counter turns dark
3. Add hashtags: `#marketing #founders`
4. Change **Status** to `approved`, set **Publish at** to `2026-10-06 09:00`
5. Click **Save** → toast `Saved`
6. Click **Schedule** → moves to Queue on Dashboard
7. Optional: click **Regenerate** → choose `shorter` / `punchier` → new variant replaces body (old kept in history)

## 10. Publishing — Gmail + Facebook

Without setup, publishing is **simulated** (recorded in `content.meta.publish_result = {ok, simulated:true, ...}`).

### 10.1 One-click dev (try offline)
1. Go to `?r=login`
2. Click **Gmail dev** or **FB dev**
3. You’re logged in as `dev@example.com` with simulated channel
4. Now Publish buttons work end-to-end, marked `simulated`

### 10.2 Real Gmail send
1. `Settings → Channels` → copy redirect URI:
   ```
   http://127.0.0.1:8020/index.php?r=auth/google/callback
   ```
2. Google Cloud Console → New project → APIs & Services → Credentials → Create OAuth Client ID (Web app) → paste URI
3. Enable **Gmail API**, add test user, scopes: `openid email profile + gmail.send`
4. Paste **Client ID + Secret** into Settings → Save
5. Click **Connect Gmail** → approve → token stored obfuscated
6. To publish email: open email item → enter recipient `To: founder@example.com` → **Publish via Gmail** → real `message_id` saved

### 10.3 Real Facebook Page post
1. developers.facebook.com → Create App (Business) → Add **Facebook Login** → Valid OAuth Redirect:
   ```
   http://127.0.0.1:8020/index.php?r=auth/facebook/callback
   ```
2. Scopes: `public_profile email pages_manage_posts pages_read_engagement`
3. Make login account admin of Page → AutoFlows picks first manageable Page via `/me/accounts`
4. Paste **App ID + Secret** → Save → **Connect Facebook**
5. To publish social: open social item (platform=facebook or blank) → **Post to Facebook** → real `post_id` saved
6. Other platforms (X, LinkedIn…): recorded as simulated cross-post copy

Every result visible on editor card + `task_logs` (`publish.*`).

## 11. Settings — Model + Brand Voice

Go to `?r=settings`. Everything saves to `settings` table (wins over `app/config.php`).

**Left: TinyLLM connection**
- Provider: `Ollama` or `Template engine — force offline`
- Endpoint: `http://127.0.0.1:11434` (hint: `ollama serve`)
- Model dropdown: `tinyllama`, `gemma3:1b`, `qwen2.5:1.5b` (auto-lists from Ollama `/api/tags`)
- Temperature slider, Max tokens slider, System prompt textarea
- **Test connection** → shows `reachable · 42 ms` or `unreachable — …`
- Database card: file name + row counts per table

**Right: Brand voice** (injected into EVERY prompt)
- Name: `Acme Studio`, Voice: `friendly, practical…`, Audience, Products, Links, CTA: `Start your free week`, Emoji ON/OFF, Hashtags ON/OFF

**Example: Revoice whole app**
1. Change **Voice** to: `direct, contrarian, no fluff, 8-word sentences max`
2. Change **CTA** to: `Get the checklist`
3. Click **Save settings** → toast + `config_refresh()`
4. Re-run any flow → all 3 channels use new voice

**Channels section** (OAuth keys, seal key, default Gmail To) + **Reset all** + **Save**.

## 12. Daily Automation (Cron / Task Scheduler)

A flow runs when: `active=1 AND schedule != manual AND now >= run_at AND not already run today`.

```bash
php tools/run_daily.php            # run everything due
php tools/run_daily.php --dry      # show what would run
php tools/run_daily.php --force    # ignore last_run_at, re-run today
php tools/run_daily.php --publish  # also move scheduled → published
```

**Windows (Task Scheduler, daily 09:00):**
```bat
schtasks /create /tn AutoFlows /tr "php E:\openaiVideo\autoflows\tools\run_daily.php" /sc daily /st 09:00
```

**Linux cron:**
```cron
0 9 * * * php /path/to/autoflows/tools/run_daily.php --publish
```

## 13. Offline Mode & Badges

| Badge | Meaning | What to do |
|-------|---------|------------|
| `tinyllama online` green | Ollama reachable | Real generations, streaming tokens |
| `offline · template engine` yellow | Ollama down or provider=local | Deterministic templates, fully usable, `localReply()` / `localDraft()` / `localStep()` |
| `score 84` green / yellow | Review quality gate (threshold 70) | <70 = edit manually |
| `simulated` in publish_result | No real token | Connect OAuth or keep testing |

Never a silent downgrade — UI always shows path.

## 14. Worked Examples End-to-End

### Example A: Solo founder, 10-min daily loop
1. Dashboard → Quick Generate → Channel `Social` → Brief `3 lessons from shipping daily for 30 days` → Generate
2. Content → open #1 → trim for X (280), approve → Schedule tomorrow 09:00
3. Chat → `/blog 3 lessons from shipping daily for 30 days` → open blog draft → fix title, excerpt 150 chars → Approve
4. Chat → `/email re-engaging trial users who went quiet` → open email → set Subject `We saved your progress` (28/45) → Publish via Gmail dev
5. Dashboard Queue shows 2 scheduled, Activity shows publish log

### Example B: Weekly pack via FlowAgent
Goal: `content marketing without a big team`
1. Agent → check all channels, platforms X+LinkedIn+Facebook, Tone Warm, Variants 3 → Run
2. Trace: `brief (goal clarified) → outline (3 angles) → social (3 posts) → blog (650w) → email → review SCORE: 81`
3. Outputs → Bulk Approve in Content → set Publish at staggered Mon/Wed/Fri → Schedule
4. Enable cron `--publish` → auto-publishes when due

### Example C: Connect + Real publish
1. Settings → Channels → paste Google Client ID/Secret → Save → Connect Gmail
2. Content → Email item → To: `customer@example.com` → Publish → `message_id: 18d...` green
3. Settings → paste Facebook App ID/Secret → Connect Facebook (as Page admin)
4. Content → Social/Facebook item → Post → `post_id: 123_456` green

## 15. Troubleshooting & FAQ

| Symptom | Fix |
|---------|-----|
| `offline` badge always | `ollama serve` running? Endpoint correct? `Settings → Test connection`. Or intentionally use template engine. |
| Blank page / 500 | Check `storage/logs/`, PHP version 8.1+, `pdo_sqlite` enabled: `php -m \| findstr sqlite` |
| DB locked | Stop duplicate `php -S`, delete `-journal`, ensure one writer |
| Flow never runs in cron | Check Active ON, schedule != manual, `run_at` past, not already run today; try `--dry` then `--force` |
| Publish says simulated | No OAuth token → Connect Gmail/FB or use dev login; this is expected offline |
| Facebook permission error | App in Dev Mode only works for admins/testers; add tester or submit `pages_manage_posts` for review |
| Google redirect_mismatch | Redirect URI must match exactly incl. `http://` vs `https://` + path `?r=auth/google/callback` |
| Chars over limit red | Trim body or split into thread; limit includes hashtags |
| Port in use `:8020` | `netstat -ano \| findstr :8020` → kill PID or use `:8021` |
| Settings not applying | Click Save (calls `config_refresh()`); file defaults only fallback |

**Smoke test before reporting bug:**
```bash
php tools/smoke_test.php        # lint + unit + live HTTP
php tools/smoke_test.php --fast # skip HTTP
netstat -ano | findstr :8021    # should print nothing (no leak)
```

## 16. Content Lifecycle & Keyboard Tips

```
draft → approved → scheduled → published
  ↘ archived (any stage)
```

- `Ctrl+S` in Editor = Save
- `Enter` in Chat = Send, `Shift+Enter` = newline
- Click `chip-prompt` (`/social…`) to autofill input
- CSRF auto via `X-CSRF-Token` header (`AF.api()`), SSE via `fetch()` + `AF.stream()` (EventSource can't POST)
- All times local; `publish_at` uses `datetime-local`
- Exports in `storage/exports/`, DB in `storage/app.db` — back up that file

---
**Need help?** Open `README.md` (dev overview), `OAUTH_SETUP.md` (Gmail/FB deep dive), `schema.sql` (tables). Happy shipping!
