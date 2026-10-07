-- AutoFlows — SQLite reference DDL
-- The live schema is embedded in app/core/Database.php and applied idempotently
-- on first boot. This file mirrors it for review and for seeding by hand:
--   sqlite3 storage/app.db < schema.sql

PRAGMA foreign_keys = ON;

-- ── Chat ────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS conversations (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    title         TEXT    NOT NULL DEFAULT 'New chat',
    model         TEXT,
    system_prompt TEXT,
    pinned        INTEGER DEFAULT 0,
    created_at    TEXT    NOT NULL DEFAULT (datetime('now')),
    updated_at    TEXT    NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS messages (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    conversation_id INTEGER NOT NULL REFERENCES conversations(id) ON DELETE CASCADE,
    role            TEXT    NOT NULL,               -- system|user|assistant
    content         TEXT    NOT NULL,
    model           TEXT,
    tokens          INTEGER DEFAULT 0,
    meta            TEXT,                           -- JSON: provider, fallback, generated ids
    created_at      TEXT    NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_messages_conv ON messages(conversation_id, id);

-- ── AutoFlows ───────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS flows (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    name         TEXT    NOT NULL,
    channel      TEXT    NOT NULL,                  -- social|blog|email|pack
    brief        TEXT,                              -- standing topic / angle
    platforms    TEXT,                              -- JSON array (social only)
    count        INTEGER DEFAULT 3,                 -- items per run
    tone         TEXT    DEFAULT 'warm',
    audience     TEXT,
    schedule     TEXT    DEFAULT 'daily',           -- daily|weekly|manual
    run_at       TEXT    DEFAULT '09:00',           -- local HH:MM for the cron
    weekday      INTEGER DEFAULT 1,                 -- 1..7 when schedule=weekly
    active       INTEGER DEFAULT 1,
    last_run_at  TEXT,
    run_count    INTEGER DEFAULT 0,
    created_at   TEXT    NOT NULL DEFAULT (datetime('now')),
    updated_at   TEXT    NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS runs (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    flow_id    INTEGER REFERENCES flows(id) ON DELETE SET NULL,
    trigger_by TEXT    DEFAULT 'manual',            -- manual|daily|chat|agent
    goal       TEXT,
    channel    TEXT,                                -- social|blog|email|pack|agent
    status     TEXT    DEFAULT 'running',           -- running|done|failed
    steps      INTEGER DEFAULT 0,
    outputs    INTEGER DEFAULT 0,
    provider   TEXT,
    model      TEXT,
    ms         INTEGER DEFAULT 0,
    log        TEXT,                                -- JSON array of step trace entries
    error      TEXT,
    created_at TEXT    NOT NULL DEFAULT (datetime('now')),
    finished_at TEXT
);
CREATE INDEX IF NOT EXISTS idx_runs_created ON runs(id DESC);

CREATE TABLE IF NOT EXISTS content (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    flow_id    INTEGER REFERENCES flows(id) ON DELETE SET NULL,
    run_id     INTEGER REFERENCES runs(id) ON DELETE SET NULL,
    channel    TEXT    NOT NULL,                    -- social|blog|email
    platform   TEXT,                                -- twitter|linkedin|instagram|facebook
    title      TEXT    NOT NULL DEFAULT '',
    slug       TEXT,
    excerpt    TEXT,
    body       TEXT    NOT NULL DEFAULT '',         -- markdown (social/blog) or HTML (email)
    hashtags   TEXT,
    status     TEXT    DEFAULT 'draft',             -- draft|approved|scheduled|published|archived
    score      INTEGER DEFAULT 0,                   -- 0..100 from the review step
    chars      INTEGER DEFAULT 0,
    publish_at TEXT,
    meta       TEXT,                                -- JSON: preheader, cta, tone, source…
    created_at TEXT    NOT NULL DEFAULT (datetime('now')),
    updated_at TEXT    NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_content_channel ON content(channel, status, id DESC);
CREATE INDEX IF NOT EXISTS idx_content_publish ON content(publish_at);

CREATE TABLE IF NOT EXISTS settings (
    key        TEXT PRIMARY KEY,
    value      TEXT,
    updated_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS users (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    name          TEXT    NOT NULL DEFAULT '',
    email         TEXT    NOT NULL DEFAULT '',
    avatar        TEXT,
    provider      TEXT    DEFAULT 'local',
    provider_id   TEXT,
    password_hash TEXT,
    last_login_at TEXT,
    created_at    TEXT    NOT NULL DEFAULT (datetime('now')),
    updated_at    TEXT    NOT NULL DEFAULT (datetime('now'))
);
CREATE UNIQUE INDEX IF NOT EXISTS idx_users_email ON users(email);

CREATE TABLE IF NOT EXISTS social_accounts (
    id               INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id          INTEGER REFERENCES users(id) ON DELETE CASCADE,
    provider         TEXT    NOT NULL,
    provider_user_id TEXT,
    email            TEXT,
    name             TEXT,
    avatar           TEXT,
    access_token     TEXT,
    refresh_token    TEXT,
    expires_at       TEXT,
    scopes           TEXT,
    page_id          TEXT,
    page_name        TEXT,
    page_token       TEXT,
    meta             TEXT,
    created_at       TEXT    NOT NULL DEFAULT (datetime('now')),
    updated_at       TEXT    NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_social_provider ON social_accounts(provider, provider_user_id);
CREATE INDEX IF NOT EXISTS idx_social_user ON social_accounts(user_id, provider);

CREATE TABLE IF NOT EXISTS task_logs (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    action     TEXT    NOT NULL,
    detail     TEXT,
    level      TEXT    DEFAULT 'info',
    created_at TEXT    NOT NULL DEFAULT (datetime('now'))
);
