<?php
/**
 * AgentMemory — canonical wrapper for TencentDB Agent Memory (team memory hub).
 *
 * Single source of truth for CEO + SCCRM + Trace + AutoFlows (memory driver).
 * Companion to autoflows/app/services/AgentBrowser.php (browser) and
 * autoflows/app/services/BrowserSkill.php (bsk).
 * Shims: sccrm/services/AgentMemoryService.php, ceo/agent_memory.py (Python),
 *        trace/AgentMemoryHook.php all delegate here.
 *
 * Upstream (vendored): external/tencentdb-agent-memory
 *   (https://github.com/The-Lincoln/TencentDB-Agent-Memory.git, feat/server_team)
 * Docs: INSTALL.md (stack), MemoryCore/v3-api-memorycore-doc.md,
 *   MemoryKnowledge/openapi.yaml, sdk/memory-core/python (v3 MemoryClient).
 *
 * Services (deploy/global-images/.env.example):
 *   Memory Core :8420  memory read/write, auth, skill/RAG data plane (/v3/…)
 *   Panel UI    :8125  team memory control panel
 *   Knowledge   :8424  wiki / code-graph (/v3/tools/list, /v3/tools/call)
 *   Proxy       :8096  LLM proxy per agent route (/claude-code/, /codex/, …)
 *
 * Auth: Authorization: Bearer <gateway api_key>
 *       x-tdai-service-id: <service_id>  +  x-tdai-user-key: <sk-mem-…> (optional)
 * Isolation: team_id + agent_id + user_id required; session_id required on
 * L0 writes, optional on reads (cross-session aggregate when absent).
 *
 * SCITBD mapping: team=scitbd, agents=scitbd-ceo|scitbd-sccrm|scitbd-trace|
 * scitbd-autoflows|scitbd-shared, user=scitbd-operator, session=<task/trace id>.
 *
 * Design: no composer deps, PHP 8.1+, curl or streams fallback. Never throws
 * on memory failure — returns ['ok'=>false,'error'=>…] and mirrors writes to
 * local `agent_memory_cache` so agents keep working while the stack is down.
 */
declare(strict_types=1);

final class AgentMemory
{
    public const TEAM = 'scitbd';
    public const MODULE_AGENTS = [
        'ceo' => 'scitbd-ceo',
        'sccrm' => 'scitbd-sccrm',
        'trace' => 'scitbd-trace',
        'autoflows' => 'scitbd-autoflows',
        'shared' => 'scitbd-shared',
    ];
    public const VENDOR = 'external/tencentdb-agent-memory';
    public const REPO = 'https://github.com/The-Lincoln/TencentDB-Agent-Memory.git';

    // ------------------------------------------------------------ config ---

    /** Runtime config: explicit $over > autoflows config > env > defaults. */
    public static function config(array $over = []): array
    {
        $cfg = [
            'core_url' => rtrim((string)(getenv('MEMORY_CORE_URL') ?: 'http://127.0.0.1:8420'), '/'),
            'knowledge_url' => rtrim((string)(getenv('MEMORY_KNOWLEDGE_URL') ?: 'http://127.0.0.1:8424/v3'), '/'),
            'proxy_url' => rtrim((string)(getenv('MEMORY_PROXY_URL') ?: 'http://127.0.0.1:8096'), '/'),
            'panel_url' => rtrim((string)(getenv('MEMORY_PANEL_URL') ?: 'http://127.0.0.1:8125'), '/'),
            'service_id' => (string)(getenv('MEMORY_SERVICE_ID') ?: ''),
            'gateway_key' => (string)(getenv('MEMORY_GATEWAY_KEY') ?: ''),
            'user_key' => (string)(getenv('TDAI_MEMORY_KEY') ?: ''),
            'team' => (string)(getenv('MEMORY_TEAM') ?: self::TEAM),
            'user' => (string)(getenv('MEMORY_USER') ?: 'scitbd-operator'),
            'timeout' => 12,
        ];
        // Same keys via autoflows/app/config.php 'memory' section when booted there.
        try {
            if (function_exists('config')) {
                foreach (['core_url', 'knowledge_url', 'proxy_url', 'panel_url', 'service_id', 'gateway_key', 'user_key', 'team', 'user'] as $k) {
                    $v = config('memory.' . $k, null);
                    if (is_string($v) && $v !== '') {
                        $cfg[$k] = $k === 'core_url' || $k === 'knowledge_url' || $k === 'proxy_url' || $k === 'panel_url' ? rtrim($v, '/') : $v;
                    }
                }
            }
        } catch (Throwable $e) {
        }
        foreach ($over as $k => $v) {
            if (array_key_exists($k, $cfg) && $v !== null && $v !== '') {
                $cfg[$k] = $v;
            }
        }
        return $cfg;
    }

    public static function agentFor(string $module = 'shared'): string
    {
        $module = strtolower(trim($module));
        return self::MODULE_AGENTS[$module] ?? self::MODULE_AGENTS['shared'];
    }

    /** Configured = stack reachable (fast probe) AND identity present. */
    public static function isConfigured(array $over = []): bool
    {
        $c = self::config($over);
        if ($c['team'] === '' || $c['service_id'] === '') {
            return false;
        }
        $h = self::health($over + ['timeout' => 3, 'quiet' => true]);
        return !empty($h['ok']);
    }

    public static function status(string $module = 'shared', array $over = []): array
    {
        $c = self::config($over);
        $h = self::health($over);
        $core = !empty($h['ok']);
        return [
            'ok' => $core,
            // Local cache is fully functional offline: recall()/remember() work
            // without the stack. mode=local means "working, core unreachable".
            'mode' => $core ? 'core' : 'local',
            'local_notes' => self::localCount(),
            'driver' => 'tencentdb-agent-memory',
            'core_url' => $c['core_url'],
            'knowledge_url' => $c['knowledge_url'],
            'proxy_url' => $c['proxy_url'],
            'team' => $c['team'],
            'agent' => self::agentFor($module),
            'user' => $c['user'],
            'has_service_id' => $c['service_id'] !== '',
            'has_user_key' => $c['user_key'] !== '',
            'vendor' => self::VENDOR,
            'repo' => self::REPO,
            'latency_ms' => $h['ms'] ?? 0,
            'hint' => !empty($h['ok'])
                ? 'Ready. recall() before work, remember() after.'
                : 'Core unreachable — local cache mode (' . self::localCount() . ' notes). recall()/remember() still work. For team sync: external/tencentdb-agent-memory/deploy/global-images/./start-all.sh',
        ];
    }

    private static function localCount(): int
    {
        try {
            $db = self::localDb();
            return $db ? (int)$db->query("SELECT COUNT(*) FROM agent_memory_cache")->fetchColumn() : 0;
        } catch (Throwable $e) {
            return 0;
        }
    }

    // ----------------------------------------------------------- transport ---

    private static function headers(array $c): array
    {
        $h = ['Content-Type: application/json'];
        if ($c['gateway_key'] !== '') {
            $h[] = 'Authorization: Bearer ' . $c['gateway_key'];
        }
        if ($c['service_id'] !== '') {
            $h[] = 'x-tdai-service-id: ' . $c['service_id'];
        }
        if ($c['user_key'] !== '') {
            $h[] = 'x-tdai-user-key: ' . $c['user_key'];
        }
        return $h;
    }

    /** POST JSON to Memory Core / Knowledge. Never throws. */
    private static function post(string $url, array $body, array $c, int $timeout = 12): array
    {
        $t0 = microtime(true);
        $payload = json_encode($body);
        $headers = self::headers($c);
        $err = '';
        $http = 0;
        $raw = '';
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $payload,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_CONNECTTIMEOUT => min($timeout, 5),
            ]);
            $raw = (string)curl_exec($ch);
            $err = (string)curl_error($ch);
            $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
        } else {
            $ctx = stream_context_create(['http' => [
                'method' => 'POST',
                'header' => implode("\r\n", $headers),
                'content' => $payload,
                'timeout' => $timeout,
                'ignore_errors' => true,
            ]]);
            $raw = (string)@file_get_contents($url, false, $ctx);
            if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) {
                $http = (int)$m[1];
            }
        }
        $ms = (int)round((microtime(true) - $t0) * 1000);
        if ($raw === '' && $err === '') {
            $err = 'empty response (stack down?)';
        }
        $data = json_decode($raw, true);
        // Envelope {code, data, message} → unwrap; plain JSON passes through.
        if (is_array($data) && array_key_exists('code', $data)) {
            $ok = ((int)$data['code'] === 0 || $data['code'] === '0') && ($http === 0 || ($http >= 200 && $http < 300));
            return ['ok' => $ok, 'http' => $http, 'data' => $data['data'] ?? null, 'error' => $ok ? null : mb_substr((string)($data['message'] ?? $err ?: 'core error'), 0, 500), 'raw' => mb_substr($raw, 0, 4000), 'ms' => $ms];
        }
        $ok = $err === '' && ($http === 0 || ($http >= 200 && $http < 300));
        return ['ok' => $ok, 'http' => $http, 'data' => $data, 'error' => $ok ? null : mb_substr($err ?: ('HTTP ' . $http), 0, 500), 'raw' => mb_substr($raw, 0, 4000), 'ms' => $ms];
    }

    public static function health(array $over = []): array
    {
        $c = self::config($over);
        $timeout = (int)($over['timeout'] ?? 5);
        // Lightest read: scenario count (no session needed, team+agent scoped server-side by body below).
        $r = self::post($c['core_url'] . '/v3/scenario/count', [
            'team_id' => $c['team'],
            'agent_id' => self::agentFor((string)($over['module'] ?? 'shared')),
            'user_id' => $c['user'],
        ], $c, $timeout);
        $r['core_url'] = $c['core_url'];
        return $r;
    }

    // ------------------------------------------------------------- memory ---

    private static function base(array $c, string $module, ?string $session = null, ?string $task = null): array
    {
        $b = ['team_id' => $c['team'], 'agent_id' => self::agentFor($module), 'user_id' => $c['user']];
        if ($session !== null && $session !== '') {
            $b['session_id'] = $session;
        }
        if ($task !== null && $task !== '') {
            $b['task_id'] = $task;
        }
        return $b;
    }

    /**
     * Write one L0 conversation turn (session REQUIRED — server merges
     * session-less writes into a default bucket shared with other callers).
     * Falls back to local cache when the stack is down.
     */
    public static function remember(string $text, array $opts = []): array
    {
        $c = self::config($opts);
        $module = (string)($opts['module'] ?? 'shared');
        $session = (string)($opts['session'] ?? '');
        $role = (string)($opts['role'] ?? 'user');
        if (trim($text) === '') {
            return ['ok' => false, 'error' => 'empty text', 'stored' => 'none'];
        }
        if ($session === '') {
            // Local-only: refuse to risk cross-caller merge on the server.
            $lid = self::logLocal($module, 'remember', $text, false, 0, 'no session — cached locally only');
            return ['ok' => true, 'stored' => 'local', 'local_id' => $lid, 'warning' => 'no session_id: kept in local cache, not sent to core'];
        }
        $r = self::post($c['core_url'] . '/v3/conversation/add', self::base($c, $module, $session, $opts['task'] ?? null) + [
            'messages' => [['role' => $role, 'content' => $text]],
        ], $c, (int)($opts['timeout'] ?? $c['timeout']));
        if (!empty($r['ok'])) {
            self::logLocal($module, 'remember', $text, true, $r['ms'], '');
            return ['ok' => true, 'stored' => 'core', 'ms' => $r['ms'], 'data' => $r['data']];
        }
        $lid = self::logLocal($module, 'remember', $text, false, $r['ms'] ?? 0, (string)($r['error'] ?? 'core down'));
        // Durably stored locally: ok=true so agents keep working offline.
        // 'stored' tells where ('core'|'local'); core-sync needs stored==='core'.
        return ['ok' => true, 'stored' => 'local', 'local_id' => $lid, 'warning' => 'core unreachable — kept in local cache (' . ($r['error'] ?? 'core down') . ')'];
    }

    /**
     * Recall: L1/L0 search (session optional → cross-session aggregate when
     * absent) + L3 core profile. Returns condensed context string + raw parts.
     */
    public static function recall(string $query, array $opts = []): array
    {
        $c = self::config($opts);
        $module = (string)($opts['module'] ?? 'shared');
        $session = array_key_exists('session', $opts) ? (string)$opts['session'] : null;
        $limit = max(1, min((int)($opts['limit'] ?? 5), 20));
        $out = ['ok' => false, 'context' => '', 'parts' => [], 'source' => 'none'];
        $se = self::post($c['core_url'] . '/v3/conversation/search', self::base($c, $module, $session, $opts['task'] ?? null) + [
            'query' => $query,
            'limit' => $limit,
        ], $c, (int)($opts['timeout'] ?? $c['timeout']));
        if (!empty($se['ok'])) {
            $out['ok'] = true;
            $out['source'] = 'core';
            $out['parts']['search'] = $se['data'];
        }
        $co = self::post($c['core_url'] . '/v3/core/read', self::base($c, $module), $c, (int)($opts['timeout'] ?? $c['timeout']));
        if (!empty($co['ok'])) {
            $out['ok'] = true;
            $out['source'] = $out['source'] === 'core' ? 'core' : 'core-profile';
            $out['parts']['profile'] = $co['data'];
        }
        if (empty($out['ok'])) {
            $out['error'] = $se['error'] ?? $co['error'] ?? 'core down';
            $out['context'] = self::localContext($module, $query, $limit);
            $out['source'] = $out['context'] !== '' ? 'local' : 'none';
            $out['ok'] = $out['context'] !== '';
        } else {
            $out['context'] = self::condense($out['parts'], 3000);
        }
        return $out;
    }

    /** L2 scenario read (team+agent profile aggregate, no session). */
    public static function readWiki(string $path, array $opts = []): array
    {
        $c = self::config($opts);
        $module = (string)($opts['module'] ?? 'shared');
        return self::post($c['core_url'] . '/v3/scenario/read', self::base($c, $module) + ['path' => $path], $c, (int)($opts['timeout'] ?? $c['timeout']));
    }

    /** Knowledge tools (Wiki/CodeGraph on-demand): list then call. */
    public static function toolsList(array $opts = []): array
    {
        $c = self::config($opts);
        return self::post($c['knowledge_url'] . '/tools/list', [], $c, (int)($opts['timeout'] ?? $c['timeout']));
    }

    public static function toolCall(string $name, array $args = [], array $opts = []): array
    {
        $c = self::config($opts);
        return self::post($c['knowledge_url'] . '/tools/call', ['name' => $name, 'arguments' => $args], $c, (int)($opts['timeout'] ?? 30));
    }

    // -------------------------------------------------------- local cache ---

    public const SCHEMA = <<<SQL
CREATE TABLE IF NOT EXISTS agent_memory_cache (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    module TEXT NOT NULL DEFAULT 'shared',
    session TEXT NOT NULL DEFAULT '',
    kind TEXT NOT NULL DEFAULT 'note',
    content TEXT NOT NULL DEFAULT '',
    synced INTEGER NOT NULL DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);
SQL;

    public static function ensureTables(PDO $db): void
    {
        foreach (explode(';', self::SCHEMA) as $stmt) {
            $stmt = trim($stmt);
            if ($stmt !== '') {
                $db->exec($stmt);
            }
        }
    }

    private static function localDb(): ?PDO
    {
        $cands = [
            defined('BASE_PATH') ? BASE_PATH . '/storage/app.db' : null,
            dirname(__DIR__, 2) . '/storage/app.db',
            dirname(__DIR__, 3) . '/data/osint.db',
            dirname(__DIR__, 3) . '/sccrm/db/scit_crm.db',
            dirname(__DIR__, 3) . '/ceo/scitbd_ceo.db',
        ];
        foreach ($cands as $p) {
            if ($p && is_file($p)) {
                try {
                    $db = new PDO('sqlite:' . $p);
                    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                    self::ensureTables($db);
                    return $db;
                } catch (Throwable $e) {
                    continue;
                }
            }
        }
        return null;
    }

    private static function logLocal(string $module, string $kind, string $text, bool $synced, int $ms, string $note): ?int
    {
        try {
            $db = self::localDb();
            if (!$db) {
                return null;
            }
            $st = $db->prepare("INSERT INTO agent_memory_cache (module, session, kind, content, synced) VALUES (?,?,?,?,?)");
            $st->execute([$module, '', $kind, mb_substr($text . ($note !== '' ? "\n[" . $note . "]" : ''), 0, 4000), $synced ? 1 : 0]);
            return (int)$db->lastInsertId();
        } catch (Throwable $e) {
            return null;
        }
    }

    private static function localContext(string $module, string $query, int $limit): string
    {
        try {
            $db = self::localDb();
            if (!$db) {
                return '';
            }
            $words = array_values(array_filter(preg_split('/\s+/', mb_strtolower($query)) ?: [], fn($w) => mb_strlen($w) > 2));
            $st = $db->prepare("SELECT content FROM agent_memory_cache WHERE (module = :m OR module = 'shared') ORDER BY id DESC LIMIT 50");
            $st->execute(['m' => $module]);
            $rows = $st->fetchAll(PDO::FETCH_COLUMN);
            $hits = [];
            foreach ($rows as $r) {
                $low = mb_strtolower((string)$r);
                foreach ($words as $w) {
                    if (str_contains($low, $w)) {
                        $hits[] = (string)$r;
                        break;
                    }
                }
                if (count($hits) >= $limit) {
                    break;
                }
            }
            if (empty($hits) && !empty($rows)) {
                $hits = array_slice($rows, 0, min(2, count($rows)));
            }
            return implode("\n---\n", array_map(fn($h) => mb_substr($h, 0, 800), $hits));
        } catch (Throwable $e) {
            return '';
        }
    }

    private static function condense(array $parts, int $budget): string
    {
        $chunks = [];
        $push = function ($v) use (&$chunks) {
            if (is_string($v) && trim($v) !== '') {
                $chunks[] = $v;
            } elseif (is_array($v)) {
                $j = json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                if (is_string($j) && $j !== '' && $j !== '[]' && $j !== '{}' && $j !== 'null') {
                    $chunks[] = $j;
                }
            }
        };
        if (isset($parts['profile'])) {
            $push($parts['profile']);
        }
        $d = $parts['search']['items'] ?? $parts['search']['results'] ?? $parts['search'];
        if (is_array($d)) {
            foreach (array_slice(array_values($d), 0, 8) as $it) {
                $push(is_array($it) ? ($it['content'] ?? $it['text'] ?? $it) : $it);
            }
        } else {
            $push($d);
        }
        $out = '';
        foreach ($chunks as $ch) {
            $ch = is_string($ch) ? $ch : json_encode($ch);
            if (mb_strlen($out . "\n" . $ch) > $budget) {
                break;
            }
            $out .= ($out === '' ? '' : "\n---\n") . mb_substr((string)$ch, 0, 1000);
        }
        return $out;
    }
}
