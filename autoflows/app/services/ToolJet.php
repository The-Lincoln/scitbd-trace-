<?php
/**
 * ToolJet — canonical wrapper for ToolJet low-code platform (dashboards,
 * internal apps, workflows, ToolJet DB).
 *
 * Single source of truth for CEO + SCCRM + Trace + AutoFlows (tooljet driver).
 * Companion to autoflows/app/services/AgentBrowser.php (browser),
 * autoflows/app/services/BrowserSkill.php (bsk) and
 * autoflows/app/services/AgentMemory.php (memory).
 * Shims: sccrm/services/ToolJetService.php, ceo/tooljet.py (Python),
 *        trace/ToolJetHook.php all delegate here.
 *
 * Upstream (vendored): external/tooljet (https://github.com/ToolJet/ToolJet.git)
 * Self-host: docker run -p 80:80 tooljet/try:ee-lts-latest (docs.tooljet.com/docs/setup/)
 * Docs: https://docs.tooljet.com
 *
 * Surface used here (all optional, degrades gracefully when unconfigured):
 *  - health(): GET {host}/api/health (fallback GET /) — is the instance up?
 *  - api(): authenticated generic REST caller (Bearer TOOLJET_API_TOKEN),
 *    e.g. listApps() → GET /api/apps
 *  - triggerWorkflow(): POST JSON to a Workflow webhook trigger URL
 *    (no auth needed — the URL itself carries the secret).
 *  - embedUrl(): public/shared app URL builder for iframe panels.
 *
 * Design: no composer deps, PHP 8.1+, curl/streams fallback. Never throws
 * on platform failure — returns ['ok'=>false,'error'=>…].
 */
declare(strict_types=1);

final class ToolJet
{
    public const VENDOR = 'external/tooljet';
    public const REPO = 'https://github.com/ToolJet/ToolJet.git';

    // ------------------------------------------------------------ config ---

    /** Runtime config: explicit $over > autoflows config > env > defaults. */
    public static function config(array $over = []): array
    {
        $cfg = [
            'host' => rtrim((string)(getenv('TOOLJET_HOST') ?: 'http://127.0.0.1'), '/'),
            'api_token' => (string)(getenv('TOOLJET_API_TOKEN') ?: ''),
            'workspace' => (string)(getenv('TOOLJET_WORKSPACE_ID') ?: ''),
            // App slugs for embed panels (set once apps are built in ToolJet).
            'app_ceo' => (string)(getenv('TOOLJET_APP_CEO') ?: ''),
            'app_sccrm' => (string)(getenv('TOOLJET_APP_SCCRM') ?: ''),
            'app_trace' => (string)(getenv('TOOLJET_APP_TRACE') ?: ''),
            // Workflow webhook trigger URLs (Workflow → Triggers → Webhook).
            'webhook_lead' => (string)(getenv('TOOLJET_WORKFLOW_LEAD') ?: ''),
            'webhook_trace' => (string)(getenv('TOOLJET_WORKFLOW_TRACE') ?: ''),
            'timeout' => 12,
        ];
        try {
            if (function_exists('config')) {
                foreach (['host', 'api_token', 'workspace', 'app_ceo', 'app_sccrm', 'app_trace', 'webhook_lead', 'webhook_trace'] as $k) {
                    $v = config('tooljet.' . $k, null);
                    if (is_string($v) && $v !== '') {
                        $cfg[$k] = $k === 'host' ? rtrim($v, '/') : $v;
                    }
                }
            }
        } catch (Throwable $e) {
        }
        foreach ($over as $k => $v) {
            if (array_key_exists($k, $cfg) && $v !== null && $v !== '') {
                $cfg[$k] = $k === 'host' ? rtrim((string)$v, '/') : $v;
            }
        }
        return $cfg;
    }

    /** Configured = host reachable AND API token present. Webhooks work token-less. */
    public static function isConfigured(array $over = []): bool
    {
        $c = self::config($over);
        if ($c['api_token'] === '') {
            return false;
        }
        $h = self::health($over + ['timeout' => 3]);
        return !empty($h['ok']);
    }

    public static function status(array $over = []): array
    {
        $c = self::config($over);
        $h = self::health($over);
        $missing = [];
        if ($c['api_token'] === '') {
            $missing[] = 'TOOLJET_API_TOKEN not set (Profile → API tokens)';
        }
        if (empty($h['ok'])) {
            $missing[] = 'no instance at ' . $c['host'] . ' (docker run -p 80:80 tooljet/try:ee-lts-latest)';
        }
        return [
            'ok' => !empty($h['ok']),
            'mode' => !empty($h['ok']) ? 'server' : 'unconfigured',
            'missing' => implode('; ', $missing),
            'driver' => 'tooljet',
            'host' => $c['host'],
            'workspace' => $c['workspace'] !== '' ? $c['workspace'] : '(default)',
            'has_api_token' => $c['api_token'] !== '',
            'apps' => array_filter(['ceo' => $c['app_ceo'], 'sccrm' => $c['app_sccrm'], 'trace' => $c['app_trace']]),
            'webhooks' => array_filter(['lead' => $c['webhook_lead'] !== '', 'trace' => $c['webhook_trace'] !== '']),
            'vendor' => self::VENDOR,
            'repo' => self::REPO,
            'latency_ms' => $h['ms'] ?? 0,
            'hint' => !empty($h['ok'])
                ? 'Ready. Embed apps via embedUrl(), fire workflows via triggerWorkflow().'
                : 'Self-host: docker run -p 80:80 tooljet/try:ee-lts-latest (then Profile → API tokens → TOOLJET_API_TOKEN)',
        ];
    }

    // ----------------------------------------------------------- transport ---

    /** Raw HTTP caller. Never throws. */
    private static function http(string $method, string $url, $body = null, array $headers = [], int $timeout = 12): array
    {
        $t0 = microtime(true);
        $payload = $body === null ? null : (is_string($body) ? $body : json_encode($body));
        if ($payload !== null && !array_filter($headers, fn($h) => stripos($h, 'content-type') === 0)) {
            $headers[] = 'Content-Type: application/json';
        }
        $err = '';
        $http = 0;
        $raw = '';
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            $opt = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => min($timeout, 5), CURLOPT_HTTPHEADER => $headers];
            if (strtoupper($method) === 'POST') {
                $opt[CURLOPT_POST] = true;
                $opt[CURLOPT_POSTFIELDS] = (string)$payload;
            } elseif (strtoupper($method) !== 'GET') {
                $opt[CURLOPT_CUSTOMREQUEST] = strtoupper($method);
                if ($payload !== null) {
                    $opt[CURLOPT_POSTFIELDS] = $payload;
                }
            }
            curl_setopt_array($ch, $opt);
            $raw = (string)curl_exec($ch);
            $err = (string)curl_error($ch);
            $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
        } else {
            $ctx = stream_context_create(['http' => [
                'method' => strtoupper($method),
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
        $data = json_decode($raw, true);
        $ok = $err === '' && $http >= 200 && $http < 300;
        return ['ok' => $ok, 'http' => $http, 'data' => $data, 'error' => $ok ? null : mb_substr($err ?: ('HTTP ' . $http), 0, 300), 'raw' => mb_substr($raw, 0, 4000), 'ms' => $ms];
    }

    private static function authHeaders(array $c): array
    {
        return $c['api_token'] !== '' ? ['Authorization: Bearer ' . $c['api_token']] : [];
    }

    public static function health(array $over = []): array
    {
        $c = self::config($over);
        $timeout = (int)($over['timeout'] ?? 5);
        $r = self::http('GET', $c['host'] . '/api/health', null, [], $timeout);
        if (empty($r['ok'])) {
            $r = self::http('GET', $c['host'] . '/', null, [], $timeout);
        }
        $r['host'] = $c['host'];
        return $r;
    }

    /** Authenticated generic REST call, e.g. api('GET','/api/apps'). */
    public static function api(string $method, string $path, $body = null, array $over = []): array
    {
        $c = self::config($over);
        if ($c['api_token'] === '') {
            return ['ok' => false, 'http' => 0, 'data' => null, 'error' => 'TOOLJET_API_TOKEN missing', 'ms' => 0];
        }
        if (!str_starts_with($path, '/')) {
            $path = '/' . $path;
        }
        return self::http($method, $c['host'] . $path, $body, self::authHeaders($c), (int)($over['timeout'] ?? $c['timeout']));
    }

    public static function listApps(array $over = []): array
    {
        return self::api('GET', '/api/apps', null, $over);
    }

    /**
     * Fire a ToolJet Workflow webhook trigger. Needs no API token —
     * the webhook URL carries its own secret.
     */
    public static function triggerWorkflow(string $webhookUrl, array $payload = [], array $over = []): array
    {
        if (!preg_match('#^https?://#i', $webhookUrl)) {
            return ['ok' => false, 'http' => 0, 'data' => null, 'error' => 'webhook URL required (Workflow → Triggers → Webhook)', 'ms' => 0];
        }
        return self::http('POST', $webhookUrl, $payload, [], (int)($over['timeout'] ?? 15));
    }

    /** Shareable / embeddable app URL from a configured slug. */
    public static function embedUrl(string $which = 'sccrm', array $over = []): string
    {
        $c = self::config($over);
        $slug = (string)($c['app_' . $which] ?? '');
        if ($slug === '') {
            return '';
        }
        if (preg_match('#^https?://#i', $slug)) {
            return $slug;
        }
        return $c['host'] . '/applications/' . ltrim($slug, '/');
    }

    // -------------------------------------------------------------- db ---

    public const SCHEMA = <<<SQL
CREATE TABLE IF NOT EXISTS tooljet_runs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    module TEXT NOT NULL DEFAULT 'shared',
    kind TEXT NOT NULL DEFAULT 'webhook',
    target TEXT NOT NULL DEFAULT '',
    ok INTEGER NOT NULL DEFAULT 0,
    ms INTEGER NOT NULL DEFAULT 0,
    output_excerpt TEXT,
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

    public static function logRun(PDO $db, string $module, string $kind, string $target, bool $ok, int $ms, string $excerpt = ''): void
    {
        try {
            self::ensureTables($db);
            $st = $db->prepare('INSERT INTO tooljet_runs (module, kind, target, ok, ms, output_excerpt) VALUES (?,?,?,?,?,?)');
            $st->execute([$module, mb_substr($kind, 0, 60), mb_substr($target, 0, 500), $ok ? 1 : 0, $ms, mb_substr($excerpt, 0, 2000)]);
        } catch (Throwable $e) {
        }
    }
}
