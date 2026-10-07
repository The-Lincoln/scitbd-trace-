<?php
/**
 * EvolutionApi — canonical wrapper for Evolution API (WhatsApp messaging).
 *
 * Single source of truth for CEO + SCCRM + Trace + AutoFlows (whatsapp driver).
 * Companion to autoflows/app/services/ToolJet.php (apps),
 * autoflows/app/services/AgentMemory.php (memory).
 * Shims: sccrm/services/EvolutionApiService.php, ceo/evolution_api.py (Python),
 *        trace/EvolutionHook.php all delegate here.
 *
 * Upstream (vendored): external/evolution-api
 *   (https://github.com/The-Lincoln/evolution-api.git — Evolution Foundation)
 * Self-host: docker run -p 8080:8080 --env-file .env evoapicloud/evolution-api:latest
 * Docs: https://docs.evolutionfoundation.com.br · Manager UI + Swagger on the instance.
 *
 * Verified routes (src/api/routes + guards):
 *   Auth: `apikey` header = global AUTHENTICATION.API_KEY or per-instance token.
 *   GET  /instance/fetchInstances            (list, global or instance key)
 *   POST /instance/create {instanceName,…}   (global key)
 *   GET  /instance/connect/:instance         (QR / pairing payload)
 *   GET  /instance/connectionState/:instance
 *   POST /message/sendText/:instance {number, text, delay?}
 *   POST /message/sendMedia/:instance {number, mediatype, media(url|base64),…}
 *   POST /webhook/set/:instance {url, events?…} (see webhook.schema)
 *   DELETE /instance/logout|delete/:instance
 *
 * Numbers: international format, digits only (e.g. 8801XXXXXXXXX for BD).
 *
 * Design: no composer deps, PHP 8.1+, curl/streams fallback. Never throws
 * on platform failure — returns ['ok'=>false,'error'=>…] and logs to
 * `evolution_runs` so outreach is auditable.
 */
declare(strict_types=1);

final class EvolutionApi
{
    public const VENDOR = 'external/evolution-api';
    public const REPO = 'https://github.com/The-Lincoln/evolution-api.git';

    // ------------------------------------------------------------ config ---

    /** Runtime config: explicit $over > autoflows config > env > defaults. */
    public static function config(array $over = []): array
    {
        $cfg = [
            'base_url' => rtrim((string)(getenv('EVOLUTION_API_URL') ?: 'http://127.0.0.1:8080'), '/'),
            'api_key' => (string)(getenv('EVOLUTION_API_KEY') ?: ''),
            'instance' => (string)(getenv('EVOLUTION_INSTANCE') ?: 'scitbd'),
            'instance_token' => (string)(getenv('EVOLUTION_INSTANCE_TOKEN') ?: ''),
            // CEO escalation channel (digits only, e.g. 8801XXXXXXXXX).
            'ceo_number' => preg_replace('/\D+/', '', (string)(getenv('EVOLUTION_CEO_NUMBER') ?: '')),
            'timeout' => 15,
        ];
        try {
            if (function_exists('config')) {
                foreach (['base_url', 'api_key', 'instance', 'instance_token', 'ceo_number'] as $k) {
                    $v = config('evolution.' . $k, null);
                    if (is_string($v) && $v !== '') {
                        $cfg[$k] = $k === 'base_url' ? rtrim($v, '/') : ($k === 'ceo_number' ? preg_replace('/\D+/', '', $v) : $v);
                    }
                }
            }
        } catch (Throwable $e) {
        }
        foreach ($over as $k => $v) {
            if (array_key_exists($k, $cfg) && $v !== null && $v !== '') {
                $cfg[$k] = $k === 'base_url' ? rtrim((string)$v, '/') : $v;
            }
        }
        return $cfg;
    }

    /** Instance key wins (least privilege); falls back to global API key. */
    private static function keyFor(array $c): string
    {
        return $c['instance_token'] !== '' ? $c['instance_token'] : $c['api_key'];
    }

    public static function isConfigured(array $over = []): bool
    {
        $c = self::config($over);
        if (self::keyFor($c) === '') {
            return false;
        }
        $h = self::fetchInstances($over + ['timeout' => 4]);
        return !empty($h['ok']);
    }

    public static function status(array $over = []): array
    {
        $c = self::config($over);
        $inst = self::connectionState(null, $over);
        return [
            'ok' => !empty($inst['ok']) && in_array(strtolower((string)($inst['state'] ?? '')), ['open', 'connected', 'qr', 'connecting'], true),
            'driver' => 'evolution-api',
            'base_url' => $c['base_url'],
            'instance' => $c['instance'],
            'has_api_key' => $c['api_key'] !== '',
            'has_instance_token' => $c['instance_token'] !== '',
            'state' => $inst['state'] ?? ($inst['error'] ?? 'unknown'),
            'vendor' => self::VENDOR,
            'repo' => self::REPO,
            'latency_ms' => $inst['ms'] ?? 0,
            'hint' => 'Self-host: docker run -p 8080:8080 evoapicloud/evolution-api:latest · connect via Manager UI, then EVOLUTION_API_KEY + EVOLUTION_INSTANCE',
        ];
    }

    // ----------------------------------------------------------- transport ---

    /** Raw HTTP caller with `apikey` header. Never throws. */
    private static function http(string $method, string $url, $body, string $key, int $timeout = 15): array
    {
        $t0 = microtime(true);
        $payload = $body === null ? null : (is_string($body) ? $body : json_encode($body));
        $headers = ['apikey: ' . $key];
        if ($payload !== null) {
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
            } elseif (strtoupper($method) === 'DELETE') {
                $opt[CURLOPT_CUSTOMREQUEST] = 'DELETE';
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
        return ['ok' => $ok, 'http' => $http, 'data' => $data, 'error' => $ok ? null : mb_substr($err ?: ('HTTP ' . $http . ' ' . mb_substr($raw, 0, 200)), 0, 400), 'raw' => mb_substr($raw, 0, 4000), 'ms' => $ms];
    }

    /** Authenticated call against the configured instance (or explicit one). */
    public static function call(string $method, string $path, $body = null, array $over = []): array
    {
        $c = self::config($over);
        $key = self::keyFor($c);
        if ($key === '') {
            return ['ok' => false, 'http' => 0, 'data' => null, 'error' => 'EVOLUTION_API_KEY (or EVOLUTION_INSTANCE_TOKEN) missing', 'ms' => 0];
        }
        if (!str_starts_with($path, '/')) {
            $path = '/' . $path;
        }
        return self::http($method, $c['base_url'] . $path, $body, $key, (int)($over['timeout'] ?? $c['timeout']));
    }

    // ------------------------------------------------------------ instance ---

    public static function fetchInstances(array $over = []): array
    {
        return self::call('GET', '/instance/fetchInstances', null, $over);
    }

    public static function createInstance(?string $name = null, array $opts = [], array $over = []): array
    {
        $c = self::config($over);
        // Creation requires the GLOBAL key (guard rejects instance tokens here).
        if ($c['api_key'] === '') {
            return ['ok' => false, 'http' => 0, 'data' => null, 'error' => 'EVOLUTION_API_KEY (global) required to create instances', 'ms' => 0];
        }
        $body = array_merge(['instanceName' => $name ?? $c['instance'], 'qrcode' => true], $opts);
        $r = self::http('POST', $c['base_url'] . '/instance/create', $body, $c['api_key'], (int)($over['timeout'] ?? $c['timeout']));
        return $r;
    }

    /** QR / pairing payload — show in CRM so the operator can link the phone. */
    public static function connectQr(?string $instance = null, array $over = []): array
    {
        $c = self::config($over);
        return self::call('GET', '/instance/connect/' . rawurlencode($instance ?? $c['instance']), null, $over);
    }

    /** Returns ['ok','state'=>open|close|connecting|…] (ok=false when down). */
    public static function connectionState(?string $instance = null, array $over = []): array
    {
        $c = self::config($over);
        $r = self::call('GET', '/instance/connectionState/' . rawurlencode($instance ?? $c['instance']), null, $over);
        if (!empty($r['ok'])) {
            $d = $r['data'];
            $state = is_array($d) ? (string)($d['state'] ?? $d['instance']['state'] ?? json_encode($d)) : (string)$d;
            $r['state'] = $state;
        }
        return $r;
    }

    /**
     * Point the instance at our inbound receiver.
     * Schema (webhook.schema.ts): {webhook:{enabled,url,headers?,byEvents?,base64?,events?}}
     * Default events: inbound chat signal (replies land in sccrm/chat/whatsapp.php).
     */
    public static function setWebhook(string $url, array $events = [], ?string $instance = null, array $over = []): array
    {
        $c = self::config($over);
        $body = ['webhook' => array_merge(
            ['enabled' => true, 'url' => $url, 'byEvents' => false],
            $events !== [] ? ['events' => $events] : [],
            isset($over['headers']) && is_array($over['headers']) ? ['headers' => $over['headers']] : []
        )];
        return self::call('POST', '/webhook/set/' . rawurlencode($instance ?? $c['instance']), $body, $over);
    }

    public const INBOUND_EVENTS = ['MESSAGES_UPSERT', 'MESSAGES_UPDATE', 'CONNECTION_UPDATE', 'QRCODE_UPDATED'];

    /** Parse ANY Evolution inbound POST into normalized ['event','instance','messages'=>[…]]. Never throws. */
    public static function parseInbound(array $in): array
    {
        $out = ['event' => (string)($in['event'] ?? ''), 'instance' => (string)($in['instance'] ?? ''), 'messages' => [], 'state' => null];
        $data = $in['data'] ?? null;
        if ($out['event'] === 'CONNECTION_UPDATE' || $out['event'] === 'QRCODE_UPDATED') {
            $out['state'] = is_array($data) ? (string)($data['state'] ?? $data['status'] ?? json_encode($data)) : (string)$data;
            return $out;
        }
        $items = [];
        if (is_array($data)) {
            $items = array_is_list($data) ? $data : [$data];
            // byEvents=true wraps as {messages:[…]} or {message:{…}}
            if (isset($data['messages']) && is_array($data['messages'])) {
                $items = $data['messages'];
            } elseif (isset($data['message']) && is_array($data['message'])) {
                $items = [$data];
            }
        }
        foreach ($items as $m) {
            if (!is_array($m)) {
                continue;
            }
            $key = $m['key'] ?? [];
            $msg = $m['message'] ?? [];
            $text = '';
            if (isset($msg['conversation'])) {
                $text = (string)$msg['conversation'];
            } elseif (isset($msg['extendedTextMessage']['text'])) {
                $text = (string)$msg['extendedTextMessage']['text'];
            } elseif (isset($msg['imageMessage']['caption'])) {
                $text = '[image] ' . (string)$msg['imageMessage']['caption'];
            } elseif (isset($msg['videoMessage']['caption'])) {
                $text = '[video] ' . (string)$msg['videoMessage']['caption'];
            } elseif (isset($msg['audioMessage'])) {
                $text = '[audio message]';
            } elseif (isset($msg['documentMessage']['title'])) {
                $text = '[document] ' . (string)$msg['documentMessage']['title'];
            } elseif ($msg !== []) {
                $text = '[non-text message]';
            }
            $remote = (string)($key['remoteJid'] ?? '');
            $phone = preg_replace('/\D+/', '', explode('@', $remote)[0] ?? '');
            $out['messages'][] = [
                'id' => (string)($key['id'] ?? ''),
                'phone' => $phone,
                'from_me' => !empty($key['fromMe']),
                'push_name' => (string)($m['pushName'] ?? ''),
                'text' => mb_substr($text, 0, 4000),
                'timestamp' => (int)($m['messageTimestamp'] ?? time()),
            ];
        }
        return $out;
    }

    // ------------------------------------------------------------ messages ---

    public static function normalizeNumber(string $raw): string
    {
        return preg_replace('/\D+/', '', $raw);
    }

    /** Send plain-text WhatsApp message. $to = international digits. */
    public static function sendText(string $to, string $text, ?string $instance = null, array $over = []): array
    {
        $to = self::normalizeNumber($to);
        if ($to === '' || trim($text) === '') {
            return ['ok' => false, 'http' => 0, 'data' => null, 'error' => 'to + text required', 'ms' => 0];
        }
        $c = self::config($over);
        $r = self::call('POST', '/message/sendText/' . rawurlencode($instance ?? $c['instance']), [
            'number' => $to,
            'text' => $text,
        ] + (isset($over['delay']) ? ['delay' => (int)$over['delay']] : []), $over);
        return $r;
    }

    /** Send media (image/video/document/audio URL or base64 + fileName for docs). */
    public static function sendMedia(string $to, string $media, string $mediatype = 'image', array $extra = [], ?string $instance = null, array $over = []): array
    {
        $to = self::normalizeNumber($to);
        if ($to === '' || $media === '') {
            return ['ok' => false, 'http' => 0, 'data' => null, 'error' => 'to + media required', 'ms' => 0];
        }
        $c = self::config($over);
        return self::call('POST', '/message/sendMedia/' . rawurlencode($instance ?? $c['instance']), array_merge([
            'number' => $to,
            'mediatype' => $mediatype,
            'media' => $media,
        ], $extra), $over);
    }

    /** CEO escalation ping (uses EVOLUTION_CEO_NUMBER when $to omitted). */
    public static function alertCeo(string $text, ?string $to = null, array $over = []): array
    {
        $c = self::config($over);
        $to = $to ?? $c['ceo_number'];
        if ($to === '') {
            return ['ok' => false, 'http' => 0, 'data' => null, 'error' => 'EVOLUTION_CEO_NUMBER not configured', 'ms' => 0];
        }
        return self::sendText($to, $text, null, $over);
    }

    // -------------------------------------------------------------- db ---

    public const SCHEMA = <<<SQL
CREATE TABLE IF NOT EXISTS evolution_runs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    module TEXT NOT NULL DEFAULT 'shared',
    kind TEXT NOT NULL DEFAULT 'sendText',
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
            $st = $db->prepare('INSERT INTO evolution_runs (module, kind, target, ok, ms, output_excerpt) VALUES (?,?,?,?,?,?)');
            $st->execute([$module, mb_substr($kind, 0, 60), mb_substr($target, 0, 120), $ok ? 1 : 0, $ms, mb_substr($excerpt, 0, 2000)]);
        } catch (Throwable $e) {
        }
    }
}
