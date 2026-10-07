<?php
/**
 * SQLite connection, schema bootstrap and additive migrations.
 */
declare(strict_types=1);

final class Database
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            $path = (string) config('db_path');
            ensure_dir(dirname($path));
            self::$pdo = new PDO('sqlite:' . $path, null, null, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
            self::$pdo->exec('PRAGMA journal_mode = WAL');
            self::$pdo->exec('PRAGMA foreign_keys = ON');
            self::$pdo->exec('PRAGMA busy_timeout = 8000');
        }
        return self::$pdo;
    }

    /** Create storage folders and tables on first run. */
    public static function boot(): void
    {
        foreach (['log_dir', 'cache_dir', 'export_dir'] as $dir) {
            ensure_dir((string) config($dir));
        }
        self::pdo()->exec(self::schema());
        self::migrate();
        self::seed();
    }

    /** Idempotent additive migrations for databases created by older builds. */
    private static function migrate(): void
    {
        // New tables for installs that booted before auth existed.
        self::pdo()->exec(
            'CREATE TABLE IF NOT EXISTS users (
                id            INTEGER PRIMARY KEY AUTOINCREMENT,
                name          TEXT    NOT NULL DEFAULT \'\',
                email         TEXT    NOT NULL DEFAULT \'\',
                avatar        TEXT,
                provider      TEXT    DEFAULT \'local\',
                provider_id   TEXT,
                password_hash TEXT,
                last_login_at TEXT,
                created_at    TEXT    NOT NULL DEFAULT (datetime(\'now\')),
                updated_at    TEXT    NOT NULL DEFAULT (datetime(\'now\'))
            )'
        );
        self::pdo()->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_users_email ON users(email)');
        self::pdo()->exec(
            'CREATE TABLE IF NOT EXISTS social_accounts (
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
                created_at       TEXT    NOT NULL DEFAULT (datetime(\'now\')),
                updated_at       TEXT    NOT NULL DEFAULT (datetime(\'now\'))
            )'
        );
        self::pdo()->exec('CREATE INDEX IF NOT EXISTS idx_social_provider ON social_accounts(provider, provider_user_id)');
        self::pdo()->exec('CREATE INDEX IF NOT EXISTS idx_social_user ON social_accounts(user_id, provider)');

        $add = [
            'flows'   => ['count' => 'INTEGER DEFAULT 3', 'weekday' => 'INTEGER DEFAULT 1'],
            'content' => ['score' => 'INTEGER DEFAULT 0', 'chars' => 'INTEGER DEFAULT 0'],
            'runs'    => ['model' => 'TEXT', 'goal' => 'TEXT'],
            'users'   => [
                'avatar' => 'TEXT', 'provider' => "TEXT DEFAULT 'local'",
                'provider_id' => 'TEXT', 'password_hash' => 'TEXT', 'last_login_at' => 'TEXT',
            ],
            'social_accounts' => [
                'page_id' => 'TEXT', 'page_name' => 'TEXT', 'page_token' => 'TEXT',
                'scopes' => 'TEXT', 'meta' => 'TEXT',
            ],
        ];
        foreach ($add as $table => $cols) {
            $have = self::columns($table);
            if ($have === [] && in_array($table, ['users', 'social_accounts'], true)) {
                continue; // just created above; columns() on empty would retry anyway
            }
            foreach ($cols as $col => $type) {
                if (!isset($have[$col])) {
                    self::pdo()->exec("ALTER TABLE {$table} ADD COLUMN {$col} {$type}");
                }
            }
        }
    }

    /** @return array<string,string> column => type */
    private static function columns(string $table): array
    {
        $out = [];
        foreach (self::pdo()->query("PRAGMA table_info({$table})") as $row) {
            $out[$row['name']] = (string) $row['type'];
        }
        return $out;
    }

    /** A starter flow so the dashboard is never empty on first boot. */
    private static function seed(): void
    {
        $n = (int) self::pdo()->query('SELECT COUNT(*) FROM flows')->fetchColumn();
        if ($n > 0) {
            return;
        }
        $st = self::pdo()->prepare(
            'INSERT INTO flows (name, channel, brief, platforms, count, tone, audience, schedule, run_at, active)
             VALUES (?,?,?,?,?,?,?,?,?,1)'
        );
        $st->execute([
            'Daily founder pack',
            'pack',
            'One practical content-marketing lesson a small team can apply today',
            json_encode(['twitter', 'linkedin', 'instagram']),
            3,
            'friendly',
            'founders and small marketing teams',
            'daily',
            '09:00',
        ]);
        Database::log('seed.flow', 'Created starter flow "Daily founder pack"');
    }

    private static function schema(): string
    {
        return <<<'SQL'
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
    role            TEXT    NOT NULL,
    content         TEXT    NOT NULL,
    model           TEXT,
    tokens          INTEGER DEFAULT 0,
    meta            TEXT,
    created_at      TEXT    NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_messages_conv ON messages(conversation_id, id);

CREATE TABLE IF NOT EXISTS flows (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    name        TEXT    NOT NULL,
    channel     TEXT    NOT NULL,
    brief       TEXT,
    platforms   TEXT,
    count       INTEGER DEFAULT 3,
    tone        TEXT    DEFAULT 'warm',
    audience    TEXT,
    schedule    TEXT    DEFAULT 'daily',
    run_at      TEXT    DEFAULT '09:00',
    weekday     INTEGER DEFAULT 1,
    active      INTEGER DEFAULT 1,
    last_run_at TEXT,
    run_count   INTEGER DEFAULT 0,
    created_at  TEXT    NOT NULL DEFAULT (datetime('now')),
    updated_at  TEXT    NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS runs (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    flow_id     INTEGER REFERENCES flows(id) ON DELETE SET NULL,
    trigger_by  TEXT    DEFAULT 'manual',
    goal        TEXT,
    channel     TEXT,
    status      TEXT    DEFAULT 'running',
    steps       INTEGER DEFAULT 0,
    outputs     INTEGER DEFAULT 0,
    provider    TEXT,
    model       TEXT,
    ms          INTEGER DEFAULT 0,
    log         TEXT,
    error       TEXT,
    created_at  TEXT    NOT NULL DEFAULT (datetime('now')),
    finished_at TEXT
);
CREATE INDEX IF NOT EXISTS idx_runs_created ON runs(id DESC);

CREATE TABLE IF NOT EXISTS content (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    flow_id    INTEGER REFERENCES flows(id) ON DELETE SET NULL,
    run_id     INTEGER REFERENCES runs(id) ON DELETE SET NULL,
    channel    TEXT    NOT NULL,
    platform   TEXT,
    title      TEXT    NOT NULL DEFAULT '',
    slug       TEXT,
    excerpt    TEXT,
    body       TEXT    NOT NULL DEFAULT '',
    hashtags   TEXT,
    status     TEXT    DEFAULT 'draft',
    score      INTEGER DEFAULT 0,
    chars      INTEGER DEFAULT 0,
    publish_at TEXT,
    meta       TEXT,
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
SQL;
    }

    public static function log(string $action, string $detail = '', string $level = 'info'): void
    {
        try {
            $st = self::pdo()->prepare('INSERT INTO task_logs (action, detail, level) VALUES (?,?,?)');
            $st->execute([$action, mb_substr($detail, 0, 4000), $level]);
        } catch (Throwable) {
            // logging must never break a request
        }
    }
}
