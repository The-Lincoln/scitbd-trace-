<?php
/**
 * OpenViking — canonical wrapper for the OpenViking context database.
 *
 * Single source of truth for CEO + SCCRM + Trace + AutoFlows (context driver).
 * Companion to autoflows/app/services/AgentMemory.php (TencentDB team memory).
 * Shims: sccrm/services/OpenVikingService.php, ceo/openviking.py (Python),
 *        trace/OpenVikingHook.php all delegate here.
 *
 * Upstream (vendored): external/openviking
 *   (https://github.com/The-Lincoln/OpenViking.git — fork of volcengine/OpenViking)
 * Docs: https://docs.openviking.ai · Studio: https://openviking.ai/studio
 *
 * Model: one viking:// filesystem for resources + memories + skills.
 * Agents browse it like files (ls/tree/read) and retrieve with scope
 * (find/grep). L0 abstract → L1 overview → L2 details: read summaries
 * before sources. Auth: server URL + USER key (root keys can't read/write
 * memories). CLI: `ov status|config|add-resource|task|ls|tree|find|grep|chat|compile`.
 * Install: curl -fsSL https://openviking.ai/install | bash -s -- --yes --url <SERVER_URL>
 *
 * When to use vs AgentMemory (TencentDB): OpenViking for inspectable,
 * file-like context (browse/edit what the agent knows, scope search to a
 * project subtree, compile sessions into wiki/graphs); AgentMemory for
 * governed team assets with ACLs and loadouts.
 *
 * Design: no composer deps, PHP 8.1+. Never throws — returns
 * ['ok'=>false,'error'=>…]. Server-down ⇒ ok=false; agents fall back to
 * local cache / AgentMemory.
 */
declare(strict_types=1);

final class OpenViking
{
    public const VENDOR = 'external/openviking';
    public const REPO = 'https://github.com/The-Lincoln/OpenViking.git';

    // ------------------------------------------------------------ config ---

    /** Runtime config: explicit $over > autoflows config > env > defaults. */
    public static function config(array $over = []): array
    {
        $cfg = [
            'server_url' => rtrim((string)(getenv('OPENVIKING_URL') ?: ''), '/'),
            'api_key' => (string)(getenv('OPENVIKING_API_KEY') ?: ''),
            'timeout' => 20,
        ];
        try {
            if (function_exists('config')) {
                foreach (['server_url', 'api_key'] as $k) {
                    $v = config('openviking.' . $k, null);
                    if (is_string($v) && $v !== '') {
                        $cfg[$k] = $k === 'server_url' ? rtrim($v, '/') : $v;
                    }
                }
            }
        } catch (Throwable $e) {
        }
        foreach ($over as $k => $v) {
            if (array_key_exists($k, $cfg) && $v !== null && $v !== '') {
                $cfg[$k] = $k === 'server_url' ? rtrim((string)$v, '/') : $v;
            }
        }
        return $cfg;
    }

    /** Resolve `ov` binary (PATH + well-known spots). */
    public static function bin(): string
    {
        $env = trim((string)(getenv('OV_BIN') ?: ''));
        if ($env !== '' && is_file($env)) {
            return $env;
        }
        $isWin = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
        $cmd = $isWin ? 'where ov 2>nul' : 'command -v ov 2>/dev/null';
        $out = @shell_exec($cmd);
        if (is_string($out) && trim($out) !== '') {
            foreach (preg_split('/\R/', trim($out)) ?: [] as $line) {
                $line = trim($line);
                if ($line !== '') {
                    return $line;
                }
            }
        }
        $home = (string)(getenv('HOME') ?: getenv('USERPROFILE') ?: '');
        foreach ([$home . '/.local/bin/ov', $home . '/.openviking/bin/ov'] as $p) {
            if ($home !== '' && is_file($p)) {
                return $p;
            }
        }
        return 'ov';
    }

    private static function envFor(array $over = []): array
    {
        $c = self::config($over);
        $env = getenv() ?: [];
        if ($c['server_url'] !== '') {
            $env['OPENVIKING_URL'] = $c['server_url'];
        }
        if ($c['api_key'] !== '') {
            $env['OPENVIKING_API_KEY'] = $c['api_key'];
        }
        if (!empty($over['env']) && is_array($over['env'])) {
            foreach ($over['env'] as $k => $v) {
                $env[(string)$k] = (string)$v;
            }
        }
        return $env;
    }

    /**
     * Run one `ov` command (temp-file redirect: never pipes, never blocks).
     * @return array{ok:bool,code:int,stdout:string,stderr:string,ms:int,command:string}
     */
    public static function exec(array $argv, array $opts = []): array
    {
        $t0 = microtime(true);
        $timeout = max(5, min((int)($opts['timeout'] ?? 30), 180));
        $bin = self::bin();
        $parts = [$bin];
        foreach ($argv as $a) {
            $parts[] = escapeshellarg((string)$a);
        }
        $cmd = implode(' ', $parts);
        $tmpBase = sys_get_temp_dir() . '/ov_' . substr(md5(uniqid('', true)), 0, 10);
        $tmpOut = $tmpBase . '.out';
        $tmpErr = $tmpBase . '.err';
        @touch($tmpOut);
        @touch($tmpErr);
        $descriptors = [0 => ['pipe', 'r'], 1 => ['file', $tmpOut, 'w'], 2 => ['file', $tmpErr, 'w']];
        $proc = @proc_open($cmd, $descriptors, $pipes, null, self::envFor($opts), ['bypass_shell' => false]);
        if (!is_resource($proc)) {
            @unlink($tmpOut);
            @unlink($tmpErr);
            return ['ok' => false, 'code' => 127, 'stdout' => '', 'stderr' => 'proc_open failed', 'ms' => 0, 'command' => $cmd];
        }
        @fclose($pipes[0]);
        $status = proc_get_status($proc);
        $pid = (int)($status['pid'] ?? 0);
        $deadline = microtime(true) + $timeout;
        $exit = null;
        $timedOut = false;
        while (true) {
            $status = proc_get_status($proc);
            if (!$status['running']) {
                $exit = $status['exitcode'];
                break;
            }
            if (microtime(true) >= $deadline) {
                $timedOut = true;
                if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN' && $pid > 0) {
                    @pclose(@popen("taskkill /F /T /PID {$pid} 2>nul", 'r'));
                }
                @proc_terminate($proc, 9);
                usleep(400000);
                $status = proc_get_status($proc);
                $exit = $status['running'] ? 124 : $status['exitcode'];
                break;
            }
            usleep(50000);
        }
        @proc_close($proc);
        $stdout = is_file($tmpOut) ? (string)@file_get_contents($tmpOut) : '';
        $stderr = is_file($tmpErr) ? (string)@file_get_contents($tmpErr) : '';
        @unlink($tmpOut);
        @unlink($tmpErr);
        $ms = (int)round((microtime(true) - $t0) * 1000);
        return ['ok' => $exit === 0, 'code' => (int)$exit, 'stdout' => $stdout, 'stderr' => $stderr, 'ms' => $ms, 'command' => $cmd . ($timedOut ? ' [TIMEOUT]' : '')];
    }

    // ----------------------------------------------------------- helpers ---

    public static function isAvailable(): bool
    {
        $r = self::exec(['status'], ['timeout' => 15, 'log' => false] + []);
        return $r['ok'] || str_contains(strtolower($r['stdout'] . $r['stderr']), 'openviking');
    }

    public static function version(): string
    {
        $r = self::exec(['status'], ['timeout' => 15]);
        $t = trim($r['stdout'] . ' ' . $r['stderr']);
        foreach (preg_split('/\R/', $t) ?: [] as $line) {
            $line = trim($line);
            if ($line !== '') {
                return mb_substr($line, 0, 120);
            }
        }
        return 'unknown';
    }

    public static function status(array $over = []): array
    {
        $c = self::config($over);
        $r = self::exec(['status'], ['timeout' => (int)($over['timeout'] ?? 15)]);
        $missing = [];
        if (!$r['ok']) {
            if (strpos($r['stdout'] . $r['stderr'], 'not recognized') !== false || $r['code'] === 127) {
                $missing[] = 'ov CLI not installed';
            }
            if ($c['server_url'] === '') {
                $missing[] = 'OPENVIKING_URL not set';
            }
            if ($c['api_key'] === '') {
                $missing[] = 'user key not set (root keys cannot touch memories)';
            }
            if (empty($missing)) {
                $missing[] = trim(preg_replace('/\s+/', ' ', $r['stderr'] !== '' ? $r['stderr'] : $r['stdout'])) !== '' ? mb_substr(trim($r['stderr'] !== '' ? $r['stderr'] : $r['stdout']), 0, 160) : 'server unreachable (is it running on :1933?)';
            }
        }
        return [
            'ok' => $r['ok'],
            'mode' => $r['ok'] ? 'server' : 'unconfigured',
            'missing' => implode('; ', $missing),
            'driver' => 'openviking',
            'bin' => self::bin(),
            'server_url' => $c['server_url'] !== '' ? $c['server_url'] : '(not set)',
            'has_api_key' => $c['api_key'] !== '',
            'detail' => mb_substr(trim($r['stdout'] !== '' ? $r['stdout'] : $r['stderr']), 0, 300),
            'ms' => $r['ms'],
            'vendor' => self::VENDOR,
            'repo' => self::REPO,
            'hint' => $r['ok']
                ? 'Ready. Browse viking:// with ls/tree, retrieve with find/grep.'
                : 'Install: curl -fsSL https://openviking.ai/install | bash -s -- --yes --url <SERVER_URL> (needs server + user key)',
        ];
    }

    // ---------------------------------------------------------- context ---

    private static function shape(array $r): array
    {
        $text = trim($r['stdout'] !== '' ? $r['stdout'] : $r['stderr']);
        return ['ok' => $r['ok'], 'code' => $r['code'], 'text' => $text, 'ms' => $r['ms'], 'command' => $r['command'], 'error' => $r['ok'] ? null : mb_substr($text !== '' ? $text : ('exit ' . $r['code']), 0, 1000)];
    }

    /** Browse: `ov ls viking://…` */
    public static function ls(string $uri = 'viking://', array $opts = []): array
    {
        return self::shape(self::exec(['ls', $uri], ['timeout' => (int)($opts['timeout'] ?? 30)]));
    }

    /** Browse: `ov tree viking://… -L 2` */
    public static function tree(string $uri = 'viking://', int $depth = 2, array $opts = []): array
    {
        return self::shape(self::exec(['tree', $uri, '-L', (string)$depth], ['timeout' => (int)($opts['timeout'] ?? 30)]));
    }

    /** Scoped semantic search: `ov find "query"` (+ optional --uri scope/--limit). */
    public static function find(string $query, ?string $uri = null, array $opts = []): array
    {
        $args = ['find', $query];
        if ($uri !== null && $uri !== '') {
            $args[] = '--uri';
            $args[] = $uri;
        }
        if (!empty($opts['limit'])) {
            $args[] = '--limit';
            $args[] = (string)max(1, min((int)$opts['limit'], 20));
        }
        return self::shape(self::exec($args, ['timeout' => (int)($opts['timeout'] ?? 60)]));
    }

    /** Lexical search: `ov grep "pattern" --uri viking://…` */
    public static function grep(string $pattern, ?string $uri = null, array $opts = []): array
    {
        $args = ['grep', $pattern];
        if ($uri !== null && $uri !== '') {
            $args[] = '--uri';
            $args[] = $uri;
        }
        return self::shape(self::exec($args, ['timeout' => (int)($opts['timeout'] ?? 60)]));
    }

    /** Ingest: `ov add-resource <repo-url|path>` → returns task id to poll. */
    public static function addResource(string $source, array $opts = []): array
    {
        return self::shape(self::exec(['add-resource', $source], ['timeout' => (int)($opts['timeout'] ?? 60)]));
    }

    /** Poll ingest: `ov task status <id>` */
    public static function taskStatus(string $taskId, array $opts = []): array
    {
        return self::shape(self::exec(['task', 'status', $taskId], ['timeout' => (int)($opts['timeout'] ?? 30)]));
    }

    /** Recall helper: scoped find (L0/L1 first, drill into URIs on demand). */
    public static function recall(string $query, ?string $scope = null, array $opts = []): array
    {
        $r = self::find($query, $scope, $opts);
        $r['source'] = !empty($r['ok']) ? 'openviking' : 'none';
        return $r;
    }

    // -------------------------------------------------------------- db ---

    public const SCHEMA = <<<SQL
CREATE TABLE IF NOT EXISTS openviking_runs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    module TEXT NOT NULL DEFAULT 'shared',
    kind TEXT NOT NULL DEFAULT 'find',
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
            $st = $db->prepare('INSERT INTO openviking_runs (module, kind, target, ok, ms, output_excerpt) VALUES (?,?,?,?,?,?)');
            $st->execute([$module, mb_substr($kind, 0, 60), mb_substr($target, 0, 500), $ok ? 1 : 0, $ms, mb_substr($excerpt, 0, 2000)]);
        } catch (Throwable $e) {
        }
    }
}
