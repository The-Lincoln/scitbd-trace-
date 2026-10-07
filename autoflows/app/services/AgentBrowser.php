<?php
/**
 * AgentBrowser — canonical wrapper for vercel-labs/agent-browser CLI.
 *
 * Single source of truth for CEO + SCCRM + Trace + AutoFlows.
 * Shims: sccrm/services/AgentBrowserService.php, ceo/agent_browser_api.php,
 *        trace/AgentBrowserTracer.php all delegate here.
 *
 * Repo: https://github.com/vercel-labs/agent-browser.git
 * CLI:  agent-browser open|snapshot|click|fill|type|press|get|eval|
 *       screenshot|pdf|read|wait|find|scroll|cookies|storage|network|
 *       tab|batch|skills|mcp|doctor|close …
 *
 * Design:
 *  - No composer deps. PHP 8.1+. Windows + Linux safe (PATH lookup for
 *    `agent-browser` / `agent-browser.cmd`).
 *  - Per-module sessions isolate tabs: ceo | sccrm | trace | autoflows.
 *    Override with AGENT_BROWSER_SESSION env or $opts['session'].
 *  - Every call logs to storage/logs/agent_browser.log and (optionally)
 *    to `agent_browser_runs` table via ::logRun().
 *  - Never throws on browser failure — returns ['ok'=>false,'error'=>…].
 *    Only mis-configuration throws.
 */
declare(strict_types=1);

final class AgentBrowser
{
    public const MODULES = ['ceo', 'sccrm', 'trace', 'autoflows', 'shared'];

    // ------------------------------------------------------------ config ---

    /** Resolve CLI binary. Env AGENT_BROWSER_BIN wins, then PATH probe. */
    public static function bin(): string
    {
        $env = trim((string)(getenv('AGENT_BROWSER_BIN') ?: ''));
        if ($env !== '' && is_file($env)) {
            return $env;
        }
        // Windows npm global shim lives in %APPDATA%\npm\agent-browser.cmd
        $candidates = ['agent-browser', 'agent-browser.cmd'];
        foreach ($candidates as $c) {
            $found = self::which($c);
            if ($found !== '') {
                return $found;
            }
        }
        // Fall back to bare name and let the shell resolve it (better error).
        return 'agent-browser';
    }

    private static function which(string $name): string
    {
        $isWin = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
        $cmd = $isWin ? "where {$name} 2>nul" : "command -v {$name} 2>/dev/null";
        $out = @shell_exec($cmd);
        if (!is_string($out) || trim($out) === '') {
            return '';
        }
        // `where` may return multiple lines — take the first that exists.
        foreach (preg_split('/\R/', trim($out)) ?: [] as $line) {
            $line = trim($line);
            if ($line !== '' && (is_file($line) || str_contains($line, $name))) {
                return $line;
            }
        }
        return '';
    }

    /** Default session key per module. */
    public static function sessionFor(string $module = 'shared'): string
    {
        $module = strtolower(trim($module));
        if (!in_array($module, self::MODULES, true)) {
            $module = 'shared';
        }
        $env = trim((string)(getenv('AGENT_BROWSER_SESSION') ?: ''));
        if ($env !== '') {
            return $env . '-' . $module;
        }
        return 'scitbd-' . $module;
    }

    /** Screenshot / artifact directory (created on demand). */
    public static function shotDir(): string
    {
        $base = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 2);
        // AutoFlows storage vs OSINT data vs CEO dir — pick first writable.
        $cands = [
            $base . '/storage/shots',
            $base . '/storage/exports/shots',
            dirname($base) . '/data/shots',
            sys_get_temp_dir() . '/scitbd-shots',
        ];
        foreach ($cands as $d) {
            try {
                if (!is_dir($d)) {
                    @mkdir($d, 0775, true);
                }
                if (is_dir($d) && is_writable($d)) {
                    return $d;
                }
            } catch (Throwable $e) {
                continue;
            }
        }
        return sys_get_temp_dir();
    }

    /** Log directory. */
    public static function logDir(): string
    {
        $base = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 2);
        $cands = [$base . '/storage/logs', dirname($base) . '/data', sys_get_temp_dir()];
        foreach ($cands as $d) {
            if (!is_dir($d)) {
                @mkdir($d, 0775, true);
            }
            if (is_dir($d) && is_writable($d)) {
                return $d;
            }
        }
        return sys_get_temp_dir();
    }

    // -------------------------------------------------------------- core ---

    /**
     * Low-level CLI runner.
     *
     * @param string[] $argv  e.g. ['open','https://example.com','--json']
     * @param array    $opts  session|timeout|env|json|log
     * @return array{ok:bool,code:int,stdout:string,stderr:string,ms:int,command:string,json:mixed}
     */
    public static function exec(array $argv, array $opts = []): array
    {
        $t0 = microtime(true);
        $bin = self::bin();
        $session = (string)($opts['session'] ?? self::sessionFor('shared'));
        $timeout = (int)($opts['timeout'] ?? 60);
        $timeout = max(5, min($timeout, 300));

        // Session isolation: explicit --session flag (v0.27.x) + env fallback.
        // NOTE: argv[0] is the subcommand (open/snapshot/…) — the flag must
        // precede it: `agent-browser --session X open …`.
        $env = array_merge(getenv() ?: [], [
            'AGENT_BROWSER_SESSION' => $session,
            'AGENT_BROWSER_SESSION_NAME' => $session,
        ]);
        // Layer 3 (app) + Layer 2 (sccrm): BROWSER_USE_API_KEY for -p browseruse.
        // Real env wins; trace/.env fallback via BrowserUseKey (never throws).
        if (empty($env['BROWSER_USE_API_KEY'])) {
            $buk = __DIR__ . '/BrowserUseKey.php';
            if (is_file($buk) && !class_exists('BrowserUseKey')) {
                require_once $buk;
            }
            if (class_exists('BrowserUseKey')) {
                $env = \BrowserUseKey::ensureEnv($env);
            }
        }
        if (!empty($opts['allowed_domains']) && is_array($opts['allowed_domains'])) {
            $env['AGENT_BROWSER_ALLOWED_DOMAINS'] = implode(',', $opts['allowed_domains']);
        }
        if (!empty($opts['env']) && is_array($opts['env'])) {
            foreach ($opts['env'] as $k => $v) {
                $env[(string)$k] = (string)$v;
            }
        }

        $withSession = $argv;
        // Don't double-inject when caller already passed --session.
        $hasSession = in_array('--session', $argv, true) || in_array('--session-name', $argv, true);
        if (!$hasSession && $session !== '') {
            array_unshift($withSession, '--session', $session);
        }
        $parts = [$bin];
        foreach ($withSession as $a) {
            $parts[] = escapeshellarg((string)$a);
        }
        // No 2>&1 suffix: output goes to temp files (never pipes, never blocks).
        $cmd = implode(' ', $parts);
        $commandLog = $bin . ' ' . implode(' ', $withSession);

        // Temp-file redirect: Windows PHP pipes (stream_get_contents/proc_close)
        // block forever when the daemon spawns surviving children — files can't.
        $tmpBase = sys_get_temp_dir() . '/ab_' . substr(md5(uniqid('', true)), 0, 10);
        $tmpOut = $tmpBase . '.out';
        $tmpErr = $tmpBase . '.err';
        @touch($tmpOut);
        @touch($tmpErr);

        $descriptors = [0 => ['pipe', 'r'], 1 => ['file', $tmpOut, 'w'], 2 => ['file', $tmpErr, 'w']];
        $proc = @proc_open($cmd, $descriptors, $pipes, null, $env, ['bypass_shell' => false]);
        if (!is_resource($proc)) {
            @unlink($tmpOut);
            @unlink($tmpErr);
            return [
                'ok' => false, 'code' => 127, 'stdout' => '', 'stderr' => 'proc_open failed',
                'ms' => (int)round((microtime(true) - $t0) * 1000),
                'command' => $commandLog, 'json' => null,
            ];
        }
        @fclose($pipes[0]);
        $status = proc_get_status($proc);
        $pid = (int)($status['pid'] ?? 0);

        // Poll (no pipe reads — output goes to temp files, so nothing can block).
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
                self::killTree($pid, $proc);
                usleep(400000);
                $status = proc_get_status($proc);
                $exit = $status['running'] ? 124 : $status['exitcode'];
                break;
            }
            usleep(50000);
        }
        // proc_close() can block on Windows when children survive — guard it.
        if (isset($pipes[0]) && is_resource($pipes[0])) {
            @fclose($pipes[0]);
        }
        @proc_close($proc);

        $stdout = is_file($tmpOut) ? (string)@file_get_contents($tmpOut) : '';
        $stderr = is_file($tmpErr) ? (string)@file_get_contents($tmpErr) : '';
        @unlink($tmpOut);
        @unlink($tmpErr);
        if ($timedOut) {
            $exit = 124;
            $stderr .= "\n[TIMEOUT after {$timeout}s — process tree killed]";
        }

        // CLI merges stderr into stdout via 2>&1 on Windows `where` path;
        // when using pipes both are captured separately — fine either way.
        $combined = trim($stdout . "\n" . $stderr);
        $ok = ($exit === 0);
        $json = null;
        if (!empty($opts['json'])) {
            $decoded = json_decode(trim($stdout) !== '' ? $stdout : $combined, true);
            if (is_array($decoded)) {
                $json = $decoded;
            }
        }

        $ms = (int)round((microtime(true) - $t0) * 1000);
        $res = [
            'ok' => $ok, 'code' => (int)$exit, 'stdout' => $stdout,
            'stderr' => $stderr, 'ms' => $ms, 'command' => $commandLog, 'json' => $json,
        ];

        if (($opts['log'] ?? true) === true) {
            self::appendLog($session, $commandLog, $ok, $ms, mb_substr($combined, 0, 1200));
        }
        return $res;
    }

    /** Kill a hung CLI tree. Never throws, never blocks. */
    private static function killTree(int $pid, $proc): void
    {
        try {
            if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
                if ($pid > 0) {
                    // /T kills the whole tree (daemon + chrome children).
                    @pclose(@popen("taskkill /F /T /PID {$pid} 2>nul", 'r'));
                }
                @proc_terminate($proc, 9);
            } else {
                @proc_terminate($proc, 9);
                if ($pid > 0) {
                    @posix_kill($pid, 9);
                }
            }
        } catch (Throwable $e) {
        }
    }

    private static function appendLog(string $session, string $cmd, bool $ok, int $ms, string $excerpt): void
    {
        try {
            $f = self::logDir() . '/agent_browser.log';
            $line = sprintf(
                "[%s] session=%s %s ms=%d %s :: %s\n",
                date('Y-m-d H:i:s'), $session, $ok ? 'OK' : 'FAIL', $ms, $cmd,
                str_replace("\n", ' ', $excerpt)
            );
            @file_put_contents($f, $line, FILE_APPEND | LOCK_EX);
        } catch (Throwable $e) {
            /* logging never breaks automation */
        }
    }

    // ----------------------------------------------------------- helpers ---

    /** Quick availability probe (no browser launch). */
    public static function isAvailable(): bool
    {
        $r = self::exec(['--version'], ['timeout' => 15, 'log' => false]);
        if ($r['ok']) {
            return true;
        }
        // Older builds lack --version; `doctor --offline --quick` also proves presence.
        $r2 = self::exec(['doctor', '--offline', '--quick'], ['timeout' => 20, 'log' => false]);
        return $r2['ok'] || str_contains($r2['stdout'] . $r2['stderr'], 'agent-browser');
    }

    public static function version(): string
    {
        $r = self::exec(['--version'], ['timeout' => 15, 'log' => false]);
        $t = trim($r['stdout'] . ' ' . $r['stderr']);
        if ($t === '') {
            return 'unknown';
        }
        // First non-empty line, capped.
        foreach (preg_split('/\R/', $t) ?: [] as $line) {
            $line = trim($line);
            if ($line !== '') {
                return mb_substr($line, 0, 120);
            }
        }
        return 'unknown';
    }

    /** Full status: binary, version, doctor summary, session. */
    public static function status(string $module = 'shared'): array
    {
        $bin = self::bin();
        $available = self::isAvailable();
        $ver = $available ? self::version() : 'not-installed';
        $session = self::sessionFor($module);
        return [
            'ok' => $available,
            'bin' => $bin,
            'version' => $ver,
            'session' => $session,
            'shot_dir' => self::shotDir(),
            'repo' => 'https://github.com/vercel-labs/agent-browser.git',
            'hint' => $available
                ? 'Ready. Sessions isolate tabs per module.'
                : 'Install: npm install -g agent-browser && agent-browser install',
        ];
    }

    // ---------------------------------------------------------- commands ---

    /** Launch / navigate. v0.27.x flags: --headed, --profile, --headers, --enable, --init-script. */
    public static function open(string $url = '', array $opts = []): array
    {
        $module = (string)($opts['module'] ?? 'shared');
        $session = $opts['session'] ?? self::sessionFor($module);
        $args = ['open'];
        if ($url !== '') {
            $args[] = $url;
        }
        if (!empty($opts['headed'])) {
            $args[] = '--headed';
        }
        if (!empty($opts['profile'])) {
            $args[] = '--profile';
            $args[] = (string)$opts['profile'];
        }
        if (!empty($opts['headers'])) {
            $args[] = '--headers';
            $args[] = is_string($opts['headers']) ? $opts['headers'] : json_encode($opts['headers']);
        }
        // allowed_domains travels via env (AGENT_BROWSER_ALLOWED_DOMAINS) in exec().
        $r = self::exec($args, [
            'session' => $session,
            'timeout' => (int)($opts['timeout'] ?? 75),
            'allowed_domains' => $opts['allowed_domains'] ?? null,
        ]);
        $out = self::shape($r);
        // Optional viewport: separate `set viewport W H` step (not an open flag).
        if ($out['ok'] && !empty($opts['viewport'])) {
            if (preg_match('/^(\d+)\s*[x, ]\s*(\d+)$/', (string)$opts['viewport'], $m)) {
                self::exec(['set', 'viewport', $m[1], $m[2]], ['session' => $session, 'timeout' => 30]);
            }
        }
        return $out;
    }

    /**
     * Agent-readable text (version-compatible).
     * - Newer CLIs: native `read [url]`.
     * - v0.27.x (no `read`): open URL if given, then `eval` rendered innerText.
     * Null URL = active tab rendered DOM.
     */
    public static function read(?string $url = null, array $opts = []): array
    {
        $module = (string)($opts['module'] ?? 'shared');
        $session = $opts['session'] ?? self::sessionFor($module);
        $timeout = (int)($opts['timeout'] ?? 60);
        // 1) Try native read (future-proof; works on latest main).
        $probe = self::exec(
            array_filter(['read', $url !== null && $url !== '' ? $url : null, '--json']),
            ['session' => $session, 'timeout' => $timeout, 'log' => false]
        );
        $probeText = trim($probe['stdout'] . ' ' . $probe['stderr']);
        if ($probe['ok'] || !str_contains(strtolower($probeText), 'unknown command')) {
            $probe['command'] = str_replace(' --json', '', $probe['command']);
            return self::shape($probe);
        }
        // 2) v0.27.x fallback: navigate when a URL was given, then eval text.
        if ($url !== null && $url !== '') {
            $o = self::open($url, ['module' => $module, 'session' => $session, 'timeout' => 75]);
            if (!$o['ok']) {
                return $o;
            }
            // Let SPA settle briefly (best-effort, never fatal).
            self::exec(['wait', '--load', 'domcontentloaded'], ['session' => $session, 'timeout' => 25, 'log' => false]);
        }
        $js = "(() => { try { const el = document.body || document.documentElement; const t = (el.innerText || el.textContent || '').replace(/\\s+/g,' ').trim(); return t.slice(0,12000); } catch(e){ return 'READ-ERROR:'+String(e); } })()";
        $r = self::exec(['eval', $js], ['session' => $session, 'timeout' => $timeout]);
        $out = self::shape($r);
        // eval wraps strings in quotes — unwrap for chat display.
        $out['via'] = 'eval-fallback';
        return $out;
    }

    /** Browser settings: viewport <w> <h> | device | geo | offline | headers | media … */
    public static function set(string $setting, array $values = [], array $opts = []): array
    {
        $module = (string)($opts['module'] ?? 'shared');
        $r = self::exec(array_merge(['set', $setting], array_map('strval', $values)), [
            'session' => $opts['session'] ?? self::sessionFor($module),
            'timeout' => (int)($opts['timeout'] ?? 30),
        ]);
        return self::shape($r);
    }

    /** Accessibility tree with refs (best input for AI). */
    public static function snapshot(array $opts = []): array
    {
        $module = (string)($opts['module'] ?? 'shared');
        $args = ['snapshot'];
        if (!empty($opts['compact'])) {
            $args[] = '--compact';
        }
        if (!empty($opts['selector'])) {
            $args[] = '--selector';
            $args[] = (string)$opts['selector'];
        }
        // --json gives stable machine output on recent builds.
        $args[] = '--json';
        $r = self::exec($args, [
            'session' => $opts['session'] ?? self::sessionFor($module),
            'timeout' => (int)($opts['timeout'] ?? 45),
            'json' => true,
        ]);
        $out = self::shape($r);
        $out['refs'] = self::extractRefs($r['stdout'], $r['json']);
        return $out;
    }

    public static function click(string $sel, array $opts = []): array
    {
        return self::simple('click', [$sel], $opts);
    }

    public static function fill(string $sel, string $text, array $opts = []): array
    {
        return self::simple('fill', [$sel, $text], $opts);
    }

    public static function type(string $sel, string $text, array $opts = []): array
    {
        return self::simple('type', [$sel, $text], $opts);
    }

    public static function press(string $key, array $opts = []): array
    {
        return self::simple('press', [$key], $opts);
    }

    public static function hover(string $sel, array $opts = []): array
    {
        return self::simple('hover', [$sel], $opts);
    }

    public static function scroll(string $dir = 'down', ?int $px = null, array $opts = []): array
    {
        $a = [$dir];
        if ($px !== null) {
            $a[] = (string)$px;
        }
        if (!empty($opts['selector'])) {
            $a[] = '--selector';
            $a[] = (string)$opts['selector'];
        }
        return self::simple('scroll', $a, $opts);
    }

    /** get text|html|value|title|url|count|box|styles|attr … */
    public static function get(string $what, string $sel = '', string $attr = '', array $opts = []): array
    {
        $a = [$what];
        if ($sel !== '') {
            $a[] = $sel;
        }
        if ($attr !== '') {
            $a[] = $attr;
        }
        return self::simple('get', $a, $opts);
    }

    public static function eval(string $js, array $opts = []): array
    {
        $module = (string)($opts['module'] ?? 'shared');
        // Base64 (-b) avoids all shell-quoting breakage (nested quotes in JS
        // break Windows command lines when passed raw via escapeshellarg).
        $args = ['eval', '-b', base64_encode($js)];
        $r = self::exec($args, ['session' => $opts['session'] ?? self::sessionFor($module), 'timeout' => (int)($opts['timeout'] ?? 45)]);
        return self::shape($r);
    }

    public static function wait(string $target, array $opts = []): array
    {
        return self::simple('wait', [$target], $opts, 90);
    }

    /** find role|text|label|placeholder|… */
    public static function find(string $locator, string $value, string $action, string $text = '', array $opts = []): array
    {
        $a = [$locator, $value, $action];
        if ($text !== '') {
            $a[] = $text;
        }
        if (!empty($opts['name'])) {
            $a[] = '--name';
            $a[] = (string)$opts['name'];
        }
        return self::simple('find', $a, $opts);
    }

    public static function screenshot(?string $path = null, array $opts = []): array
    {
        $module = (string)($opts['module'] ?? 'shared');
        if ($path === null || $path === '') {
            $path = self::shotDir() . '/shot-' . date('Ymd-His') . '-' . substr(md5(uniqid('', true)), 0, 6) . '.png';
        }
        $args = ['screenshot', $path];
        if (!empty($opts['full'])) {
            $args[] = '--full';
        }
        if (!empty($opts['annotate'])) {
            $args[] = '--annotate';
        }
        $r = self::exec($args, ['session' => $opts['session'] ?? self::sessionFor($module), 'timeout' => 60]);
        $out = self::shape($r);
        $out['path'] = $path;
        $out['exists'] = is_file($path);
        return $out;
    }

    public static function pdf(string $path, array $opts = []): array
    {
        $out = self::simple('pdf', [$path], $opts, 90);
        $out['path'] = $path;
        $out['exists'] = is_file($path);
        return $out;
    }

    public static function cookies(string $sub = '', array $args = [], array $opts = []): array
    {
        $a = array_merge([$sub !== '' ? $sub : ''], $args);
        $a = array_values(array_filter($a, fn($v) => $v !== ''));
        array_unshift($a, 'cookies');
        return self::simpleRaw($a, $opts);
    }

    public static function har(string $action, string $path = '', array $opts = []): array
    {
        $a = [$action];
        if ($path !== '') {
            $a[] = $path;
        }
        return self::simple('network', array_merge(['har'], $a), $opts);
    }

    public static function tab(string $arg = '', array $opts = []): array
    {
        $a = $arg !== '' ? [$arg] : [];
        return self::simple('tab', $a, $opts);
    }

    public static function close(bool $all = false, array $opts = []): array
    {
        $a = $all ? ['--all'] : [];
        return self::simple('close', $a, $opts, 30);
    }

    /**
     * Batch: run N commands in ONE daemon round-trip.
     * @param array<int,string|array> $commands  each: 'open https://x' | ['open','https://x']
     */
    public static function batch(array $commands, array $opts = []): array
    {
        $module = (string)($opts['module'] ?? 'shared');
        $norm = [];
        foreach ($commands as $c) {
            if (is_array($c)) {
                $norm[] = implode(' ', array_map('strval', $c));
            } else {
                $norm[] = (string)$c;
            }
        }
        $args = ['batch'];
        if (!empty($opts['bail'])) {
            $args[] = '--bail';
        }
        foreach ($norm as $n) {
            $args[] = $n;
        }
        $r = self::exec($args, ['session' => $opts['session'] ?? self::sessionFor($module), 'timeout' => (int)($opts['timeout'] ?? 120)]);
        return self::shape($r);
    }

    /** Bundled skill text (always version-matched to installed CLI). */
    public static function skills(string $name = 'core', bool $full = false): array
    {
        $a = ['skills', 'get', $name];
        if ($full) {
            $a[] = '--full';
        }
        $r = self::exec($a, ['timeout' => 20, 'log' => false]);
        return self::shape($r);
    }

    // ------------------------------------------------------- internals ---

    private static function simple(string $cmd, array $args, array $opts = [], int $defaultTimeout = 60): array
    {
        $module = (string)($opts['module'] ?? 'shared');
        $r = self::exec(array_merge([$cmd], $args), [
            'session' => $opts['session'] ?? self::sessionFor($module),
            'timeout' => (int)($opts['timeout'] ?? $defaultTimeout),
        ]);
        return self::shape($r);
    }

    private static function simpleRaw(array $argv, array $opts = [], int $defaultTimeout = 60): array
    {
        $module = (string)($opts['module'] ?? 'shared');
        $r = self::exec($argv, [
            'session' => $opts['session'] ?? self::sessionFor($module),
            'timeout' => (int)($opts['timeout'] ?? $defaultTimeout),
        ]);
        return self::shape($r);
    }

    /** Normalised public shape. */
    private static function shape(array $r): array
    {
        $text = trim($r['stdout'] !== '' ? $r['stdout'] : $r['stderr']);
        return [
            'ok' => $r['ok'],
            'code' => $r['code'],
            'text' => $text,
            'ms' => $r['ms'],
            'command' => $r['command'],
            'json' => $r['json'],
            'error' => $r['ok'] ? null : mb_substr($text !== '' ? $text : ('exit ' . $r['code']), 0, 2000),
        ];
    }

    /** Pull @e1-style refs out of snapshot text (or --json data.refs) for next-step UX. */
    private static function extractRefs(string $text, $json = null): array
    {
        preg_match_all('/@e\d+/', $text, $m);
        $refs = array_values(array_unique($m[0] ?? []));
        // --json shape: {"data":{"refs":{"e1":{…}}}} → map to @eN.
        try {
            $data = is_array($json) ? ($json['data']['refs'] ?? null) : null;
            if (is_array($data)) {
                foreach (array_keys($data) as $k) {
                    if (preg_match('/^e\d+$/', (string)$k)) {
                        $refs[] = '@' . $k;
                    }
                }
                $refs = array_values(array_unique($refs));
            }
        } catch (Throwable $e) {
        }
        return $refs;
    }

    // -------------------------------------------------------------- db ---

    public const SCHEMA = <<<SQL
CREATE TABLE IF NOT EXISTS agent_browser_runs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    module TEXT NOT NULL DEFAULT 'shared',
    session TEXT NOT NULL DEFAULT '',
    command TEXT NOT NULL DEFAULT '',
    url TEXT,
    ok INTEGER NOT NULL DEFAULT 0,
    ms INTEGER NOT NULL DEFAULT 0,
    output_excerpt TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS browser_monitors (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    url TEXT NOT NULL,
    module TEXT NOT NULL DEFAULT 'sccrm',
    frequency TEXT NOT NULL DEFAULT 'manual',
    last_status INTEGER,
    last_ms INTEGER,
    last_seo INTEGER DEFAULT -1,
    last_note TEXT,
    is_active INTEGER DEFAULT 1,
    run_count INTEGER DEFAULT 0,
    last_run_at DATETIME,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);
SQL;

    /** Idempotent migration for ANY sqlite PDO (CEO/SCCRM/OSINT/AutoFlows). */
    public static function ensureTables(PDO $db): void
    {
        foreach (explode(';', self::SCHEMA) as $stmt) {
            $stmt = trim($stmt);
            if ($stmt !== '') {
                $db->exec($stmt);
            }
        }
    }

    /** Persist one run row. Never throws. */
    public static function logRun(PDO $db, string $module, string $command, string $url, bool $ok, int $ms, string $excerpt = ''): void
    {
        try {
            self::ensureTables($db);
            $st = $db->prepare('INSERT INTO agent_browser_runs (module, session, command, url, ok, ms, output_excerpt) VALUES (?,?,?,?,?,?,?)');
            $st->execute([$module, self::sessionFor($module), mb_substr($command, 0, 500), mb_substr($url, 0, 500), $ok ? 1 : 0, $ms, mb_substr($excerpt, 0, 2000)]);
        } catch (Throwable $e) {
            /* never break the caller */
        }
    }
}
