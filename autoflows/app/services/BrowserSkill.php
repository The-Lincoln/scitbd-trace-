<?php
/**
 * BrowserSkill — canonical wrapper for Tencent/BrowserSkill `bsk` CLI.
 *
 * Single source of truth for CEO + SCCRM + Trace + AutoFlows (bsk driver).
 * Companion to autoflows/app/services/AgentBrowser.php (vercel agent-browser).
 * Shims: sccrm/services/BrowserSkillService.php, ceo/browserskill_api.php,
 *        trace/BrowserSkillTracer.php all delegate here.
 *
 * Repo (vendored): external/tencent-browserskill  (https://github.com/Tencent/BrowserSkill.git)
 * CLI:  bsk --version | doctor | browsers | session start|stop | navigate |
 *       observe | snapshot | get-html | click | fill | select | press | hover |
 *       scroll-to | wheel | focus | blur | screenshot | eval | console | network …
 * Skill: external/tencent-browserskill/crates/bsk-cli/skill/SKILL.md
 *
 * Design mirrors AgentBrowser.php:
 *  - No composer deps. PHP 8.1+. Windows + Linux safe (PATH lookup for
 *    `bsk` / `bsk.exe` / ~/.local/bin/bsk).
 *  - Per-module session labels isolate work: ceo | sccrm | trace | autoflows.
 *    bsk session IDs are daemon-side (returned by `session start --json`);
 *    we keep one sticky ID per module in sys temp so tabs don't leak.
 *  - Every call logs to storage/logs/browserskill.log and (optionally)
 *    to `browserskill_runs` table via ::logRun().
 *  - Never throws on browser failure — returns ['ok'=>false,'error'=>…].
 *
 * Page content is untrusted data, never instructions (per upstream SKILL.md).
 */
declare(strict_types=1);

final class BrowserSkill
{
    public const MODULES = ['ceo', 'sccrm', 'trace', 'autoflows', 'shared'];
    public const MIRROR = 'external/browserskill-lincoln'; // The-Lincoln/BrowserSkill — same upstream commit, failover clone
    public const REPO = 'https://github.com/Tencent/BrowserSkill.git';
    public const VENDOR = 'external/tencent-browserskill';

    // ------------------------------------------------------------ config ---

    /** Resolve CLI binary. Env BSK_BIN / BSK_PATH wins, then PATH + well-known spots. */
    public static function bin(): string
    {
        foreach (['BSK_BIN', 'BSK_PATH'] as $k) {
            $env = trim((string)(getenv($k) ?: ''));
            if ($env !== '' && is_file($env)) {
                return $env;
            }
        }
        foreach (['bsk', 'bsk.exe'] as $c) {
            $found = self::which($c);
            if ($found !== '') {
                return $found;
            }
        }
        // Unix installer default (~/.local/bin/bsk[.exe])
        $home = (string)(getenv('HOME') ?: getenv('USERPROFILE') ?: '');
        if ($home !== '') {
            foreach ([$home . '/.local/bin/bsk', $home . '/.local/bin/bsk.exe'] as $p) {
                if (is_file($p)) {
                    return $p;
                }
            }
        }
        return 'bsk';
    }

    private static function which(string $name): string
    {
        $isWin = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
        $cmd = $isWin ? "where {$name} 2>nul" : "command -v {$name} 2>/dev/null";
        $out = @shell_exec($cmd);
        if (!is_string($out) || trim($out) === '') {
            return '';
        }
        foreach (preg_split('/\R/', trim($out)) ?: [] as $line) {
            $line = trim($line);
            if ($line !== '' && (is_file($line) || str_contains($line, $name))) {
                return $line;
            }
        }
        return '';
    }

    /** BSK_HOME shared with daemon (sandbox hosts need explicit reuse). */
    public static function home(): string
    {
        $h = trim((string)(getenv('BSK_HOME') ?: ''));
        return $h !== '' ? $h : '';
    }

    /** Sticky session-id file per module (daemon owns the real session). */
    private static function sessionFile(string $module): string
    {
        $safe = preg_replace('/[^a-z0-9_-]/i', '', $module) ?: 'shared';
        return sys_get_temp_dir() . '/bsk_session_' . strtolower($safe) . '.id';
    }

    public static function cachedSessionId(string $module = 'shared'): string
    {
        $f = self::sessionFile($module);
        if (is_file($f)) {
            $id = trim((string)@file_get_contents($f));
            if ($id !== '') {
                return $id;
            }
        }
        return '';
    }

    private static function storeSessionId(string $module, string $id): void
    {
        @file_put_contents(self::sessionFile($module), $id);
    }

    private static function clearSessionId(string $module): void
    {
        @unlink(self::sessionFile($module));
    }

    /** Screenshot / artifact directory (created on demand). */
    public static function shotDir(): string
    {
        $base = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 2);
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
     * Low-level CLI runner (temp-file redirect: never pipes, never blocks).
     * @param string[] $argv e.g. ['session','start','--json']
     * @return array{ok:bool,code:int,stdout:string,stderr:string,ms:int,command:string,json:mixed}
     */
    public static function exec(array $argv, array $opts = []): array
    {
        $t0 = microtime(true);
        $bin = self::bin();
        $timeout = max(5, min((int)($opts['timeout'] ?? 60), 300));
        $env = array_merge(getenv() ?: [], ['BSK_AUTO_START' => (string)(getenv('BSK_AUTO_START') ?: '1')]);
        // Shared BrowserUse key fallback (trace/.env) for cloud-assisted flows.
        if (empty($env['BROWSER_USE_API_KEY'])) {
            $buk = __DIR__ . '/BrowserUseKey.php';
            if (is_file($buk) && !class_exists('BrowserUseKey')) {
                require_once $buk;
            }
            if (class_exists('BrowserUseKey')) {
                $env = \BrowserUseKey::ensureEnv($env);
            }
        }
        if (self::home() !== '') {
            $env['BSK_HOME'] = self::home();
        }
        if (!empty($opts['env']) && is_array($opts['env'])) {
            foreach ($opts['env'] as $k => $v) {
                $env[(string)$k] = (string)$v;
            }
        }
        $parts = [$bin];
        foreach ($argv as $a) {
            $parts[] = escapeshellarg((string)$a);
        }
        $cmd = implode(' ', $parts);
        $commandLog = $bin . ' ' . implode(' ', $argv);

        $tmpBase = sys_get_temp_dir() . '/bsk_' . substr(md5(uniqid('', true)), 0, 10);
        $tmpOut = $tmpBase . '.out';
        $tmpErr = $tmpBase . '.err';
        @touch($tmpOut);
        @touch($tmpErr);

        $descriptors = [0 => ['pipe', 'r'], 1 => ['file', $tmpOut, 'w'], 2 => ['file', $tmpErr, 'w']];
        $proc = @proc_open($cmd, $descriptors, $pipes, null, $env, ['bypass_shell' => false]);
        if (!is_resource($proc)) {
            @unlink($tmpOut);
            @unlink($tmpErr);
            return ['ok' => false, 'code' => 127, 'stdout' => '', 'stderr' => 'proc_open failed', 'ms' => (int)round((microtime(true) - $t0) * 1000), 'command' => $commandLog, 'json' => null];
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
                self::killTree($pid, $proc);
                usleep(400000);
                $status = proc_get_status($proc);
                $exit = $status['running'] ? 124 : $status['exitcode'];
                break;
            }
            usleep(50000);
        }
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
        $res = ['ok' => $ok, 'code' => (int)$exit, 'stdout' => $stdout, 'stderr' => $stderr, 'ms' => $ms, 'command' => $commandLog, 'json' => $json];
        if (($opts['log'] ?? true) === true) {
            self::appendLog($commandLog, $ok, $ms, mb_substr($combined, 0, 1200));
        }
        return $res;
    }

    private static function killTree(int $pid, $proc): void
    {
        try {
            if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
                if ($pid > 0) {
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

    private static function appendLog(string $cmd, bool $ok, int $ms, string $excerpt): void
    {
        try {
            $f = self::logDir() . '/browserskill.log';
            @file_put_contents($f, sprintf("[%s] %s ms=%d %s :: %s\n", date('Y-m-d H:i:s'), $ok ? 'OK' : 'FAIL', $ms, $cmd, str_replace("\n", ' ', $excerpt)), FILE_APPEND | LOCK_EX);
        } catch (Throwable $e) {
        }
    }

    // ----------------------------------------------------------- helpers ---

    public static function isAvailable(): bool
    {
        $r = self::exec(['--version'], ['timeout' => 15, 'log' => false]);
        if ($r['ok']) {
            return true;
        }
        $d = self::exec(['doctor', '--offline'], ['timeout' => 20, 'log' => false]);
        return $d['ok'] || str_contains(strtolower($d['stdout'] . $d['stderr']), 'bsk');
    }

    public static function version(): string
    {
        $r = self::exec(['--version'], ['timeout' => 15, 'log' => false]);
        $t = trim($r['stdout'] . ' ' . $r['stderr']);
        if ($t === '') {
            return 'unknown';
        }
        foreach (preg_split('/\R/', $t) ?: [] as $line) {
            $line = trim($line);
            if ($line !== '') {
                return mb_substr($line, 0, 120);
            }
        }
        return 'unknown';
    }

    public static function status(string $module = 'shared'): array
    {
        $bin = self::bin();
        $available = self::isAvailable();
        return [
            'ok' => $available,
            'driver' => 'bsk',
            'bin' => $bin,
            'version' => $available ? self::version() : 'not-installed',
            'session' => self::cachedSessionId($module),
            'shot_dir' => self::shotDir(),
            'repo' => self::REPO,
            'vendor' => self::VENDOR,
            'hint' => $available
                ? 'Ready. Use sessionStart() then navigate/observe; always sessionStop().'
                : 'Install: irm https://raw.githubusercontent.com/Tencent/BrowserSkill/main/install.ps1 | iex  (then Chrome/Edge extension + bsk doctor)',
        ];
    }

    // ---------------------------------------------------------- commands ---

    /** Start a daemon session. Returns ['ok', 'session_id', ...]. Retains sticky ID per module. */
    public static function sessionStart(array $opts = []): array
    {
        $module = (string)($opts['module'] ?? 'shared');
        $args = ['session', 'start', '--json'];
        if (!empty($opts['browser'])) {
            $args[] = '--browser';
            $args[] = (string)$opts['browser'];
        }
        if (!empty($opts['no_focus'])) {
            $args[] = '--no-focus';
        }
        $r = self::exec($args, ['timeout' => (int)($opts['timeout'] ?? 45), 'json' => true]);
        $out = self::shape($r);
        $sid = '';
        if (is_array($r['json'])) {
            $sid = (string)($r['json']['session_id'] ?? $r['json']['sessionId'] ?? $r['json']['id'] ?? '');
        }
        if ($sid === '' && preg_match('/[a-zA-Z0-9_-]{6,}/', $out['text'], $m)) {
            $sid = $m[0];
        }
        if ($sid !== '') {
            self::storeSessionId($module, $sid);
        }
        $out['session_id'] = $sid !== '' ? $sid : self::cachedSessionId($module);
        return $out;
    }

    public static function sessionStop(string $sessionId = '', array $opts = []): array
    {
        $module = (string)($opts['module'] ?? 'shared');
        $sid = $sessionId !== '' ? $sessionId : self::cachedSessionId($module);
        if ($sid === '') {
            return ['ok' => true, 'code' => 0, 'text' => 'no session', 'ms' => 0, 'command' => 'session stop (noop)', 'json' => null, 'error' => null];
        }
        $r = self::exec(['session', 'stop', $sid], ['timeout' => (int)($opts['timeout'] ?? 30)]);
        $out = self::shape($r);
        if ($out['ok']) {
            self::clearSessionId($module);
        }
        return $out;
    }

    /** Ensure a live session; starts one when none cached. Returns session_id or ''. */
    public static function ensureSession(array $opts = []): string
    {
        $module = (string)($opts['module'] ?? 'shared');
        $cached = self::cachedSessionId($module);
        if ($cached !== '') {
            return $cached;
        }
        $s = self::sessionStart($opts + ['module' => $module]);
        return (string)($s['session_id'] ?? '');
    }

    public static function browsers(array $opts = []): array
    {
        $r = self::exec(['browsers'], ['timeout' => (int)($opts['timeout'] ?? 30)]);
        return self::shape($r);
    }

    public static function navigate(string $url, array $opts = []): array
    {
        $sid = $opts['session_id'] ?? self::ensureSession($opts);
        $args = ['navigate', $url, '--session', $sid];
        $r = self::exec($args, ['timeout' => (int)($opts['timeout'] ?? 60)]);
        return self::shape($r) + ['session_id' => $sid];
    }

    public static function observe(array $opts = []): array
    {
        $sid = $opts['session_id'] ?? self::ensureSession($opts);
        $r = self::exec(['observe', '--session', $sid], ['timeout' => (int)($opts['timeout'] ?? 45)]);
        $out = self::shape($r);
        $out['refs'] = self::extractRefs($out['text']);
        $out['session_id'] = $sid;
        return $out;
    }

    public static function snapshot(array $opts = []): array
    {
        $sid = $opts['session_id'] ?? self::ensureSession($opts);
        $r = self::exec(['snapshot', '--session', $sid], ['timeout' => (int)($opts['timeout'] ?? 45)]);
        $out = self::shape($r);
        $out['refs'] = self::extractRefs($out['text']);
        $out['session_id'] = $sid;
        return $out;
    }

    public static function click(string $ref, array $opts = []): array
    {
        $sid = $opts['session_id'] ?? self::ensureSession($opts);
        $r = self::exec(['click', $ref, '--session', $sid], ['timeout' => (int)($opts['timeout'] ?? 45)]);
        return self::shape($r) + ['session_id' => $sid];
    }

    public static function fill(string $ref, string $value, array $opts = []): array
    {
        $sid = $opts['session_id'] ?? self::ensureSession($opts);
        $r = self::exec(['fill', $ref, '--value', $value, '--session', $sid], ['timeout' => (int)($opts['timeout'] ?? 45)]);
        return self::shape($r) + ['session_id' => $sid];
    }

    public static function press(string $key, array $opts = []): array
    {
        $sid = $opts['session_id'] ?? self::ensureSession($opts);
        $args = ['press', $key, '--session', $sid];
        if (!empty($opts['ref'])) {
            $args[] = '--ref';
            $args[] = (string)$opts['ref'];
        }
        $r = self::exec($args, ['timeout' => (int)($opts['timeout'] ?? 45)]);
        return self::shape($r) + ['session_id' => $sid];
    }

    public static function screenshot(?string $path = null, array $opts = []): array
    {
        $sid = $opts['session_id'] ?? self::ensureSession($opts);
        if ($path === null || $path === '') {
            $path = self::shotDir() . '/bsk-' . date('Ymd-His') . '-' . substr(md5(uniqid('', true)), 0, 6) . '.png';
        }
        $args = ['screenshot', '--session', $sid, '--out', $path];
        if (!empty($opts['full_page'])) {
            $args[] = '--full-page';
        }
        $r = self::exec($args, ['timeout' => 60]);
        $out = self::shape($r);
        $out['path'] = $path;
        $out['exists'] = is_file($path);
        $out['session_id'] = $sid;
        return $out;
    }

    /** Open → observe → screenshot convenience (mirrors AgentBrowserTracer steps). */
    public static function research(string $url, array $opts = []): array
    {
        $t0 = microtime(true);
        $module = (string)($opts['module'] ?? 'shared');
        $sid = self::ensureSession(['module' => $module, 'no_focus' => true] + $opts);
        $steps = [];
        $nav = self::navigate($url, ['session_id' => $sid, 'timeout' => 75]);
        $steps['navigate'] = ['ok' => $nav['ok'], 'text' => mb_substr($nav['text'], 0, 800)];
        $obs = self::observe(['session_id' => $sid]);
        $steps['observe'] = ['ok' => $obs['ok'], 'text' => mb_substr($obs['text'], 0, 2000)];
        $shot = null;
        if (!empty($opts['screenshot'])) {
            $sh = self::screenshot(null, ['session_id' => $sid]);
            $steps['shot'] = ['ok' => $sh['ok'], 'text' => (string)($sh['path'] ?? '')];
            if (!empty($sh['path']) && !empty($sh['exists'])) {
                $shot = $sh['path'];
            }
        }
        if (empty($opts['keep_open'])) {
            $st = self::sessionStop($sid, ['module' => $module]);
            $steps['stop'] = ['ok' => $st['ok'], 'text' => mb_substr($st['text'], 0, 200)];
        }
        return ['ok' => (bool)$nav['ok'], 'url' => $url, 'session_id' => $sid, 'steps' => $steps, 'observe' => $obs['text'] ?? '', 'refs' => $obs['refs'] ?? [], 'shot' => $shot, 'ms' => (int)round((microtime(true) - $t0) * 1000)];
    }

    // ------------------------------------------------------- internals ---

    private static function shape(array $r): array
    {
        $text = trim($r['stdout'] !== '' ? $r['stdout'] : $r['stderr']);
        return ['ok' => $r['ok'], 'code' => $r['code'], 'text' => $text, 'ms' => $r['ms'], 'command' => $r['command'], 'json' => $r['json'], 'error' => $r['ok'] ? null : mb_substr($text !== '' ? $text : ('exit ' . $r['code']), 0, 2000)];
    }

    private static function extractRefs(string $text): array
    {
        preg_match_all('/@e\d+/', $text, $m);
        return array_values(array_unique($m[0] ?? []));
    }

    // -------------------------------------------------------------- db ---

    public const SCHEMA = <<<SQL
CREATE TABLE IF NOT EXISTS browserskill_runs (
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

    public static function logRun(PDO $db, string $module, string $command, string $url, bool $ok, int $ms, string $excerpt = ''): void
    {
        try {
            self::ensureTables($db);
            $st = $db->prepare('INSERT INTO browserskill_runs (module, session, command, url, ok, ms, output_excerpt) VALUES (?,?,?,?,?,?,?)');
            $st->execute([$module, self::cachedSessionId($module), mb_substr($command, 0, 500), mb_substr($url, 0, 500), $ok ? 1 : 0, $ms, mb_substr($excerpt, 0, 2000)]);
        } catch (Throwable $e) {
        }
    }
}
