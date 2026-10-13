<?php
/**
 * SocialAnalyzer — canonical wrapper for Social Analyzer (username → 1000+ sites).
 *
 * Single source of truth for CEO + SCCRM + Trace + AutoFlows.
 * Companion to autoflows/app/services/EvolutionApi.php, ToolJet.php, OpenViking.php.
 * Shims: sccrm/services/SocialAnalyzerService.php, ceo/social_analyzer.py,
 *        trace/SocialAnalyzerHook.php, api/social-analyzer.php all delegate here.
 *
 * Upstream (vendored): external/social-analyzer
 *   (https://github.com/The-Lincoln/social-analyzer.git — fork of qeeqbox/social-analyzer)
 *   API, CLI & Web App for analyzing & finding a person's profile across 1000+ sites.
 *
 * Surfaces used here:
 *  - Node WebApp (preferred when running): http://127.0.0.1:9005/app.html
 *    npm start  (needs firefox-esr, tesseract-ocr, nodejs, npm)
 *  - Python CLI:  python3 app.py --username "johndoe" --output json [--metadata] [--top 100]
 *  - Node CLI:    nodejs app.js --username "johndoe"
 *  - Python pkg:  pip3 install social-analyzer && python3 -m social-analyzer
 *
 * Design: no composer deps, PHP 8.1+, proc_open/shell fallback. Never throws
 * on platform failure — returns ['ok'=>false,'error'=>…] and logs to
 * `socialanalyzer_runs` so investigations are auditable.
 */
declare(strict_types=1);

final class SocialAnalyzer
{
    public const VENDOR = 'external/social-analyzer';
    public const REPO = 'https://github.com/The-Lincoln/social-analyzer.git';
    public const UPSTREAM = 'https://github.com/qeeqbox/social-analyzer.git';
    public const DEFAULT_PORT = 9005;

    // ------------------------------------------------------------ config ---

    /** Runtime config: explicit $over > env > defaults. */
    public static function config(array $over = []): array
    {
        $root = dirname(dirname(dirname(__DIR__)));
        // dirname(__DIR__) = autoflows/app/services → root is 3 up? autoflows/app/services → trace root.
        // Robust fallback: locate vendor dir by walking up.
        if (!is_dir($root . '/' . self::VENDOR)) {
            $root = dirname(__DIR__, 3);
            if (!is_dir($root . '/' . self::VENDOR)) {
                $root = dirname(__DIR__);
                // last resort: current working structure
                $cand = realpath(__DIR__ . '/../../../');
                if ($cand && is_dir($cand . '/' . self::VENDOR)) {
                    $root = $cand;
                }
            }
        }
        $cfg = [
            'root' => $root,
            'vendor' => $root . '/' . self::VENDOR,
            'base_url' => rtrim((string)(getenv('SOCIAL_ANALYZER_URL') ?: 'http://127.0.0.1:' . self::DEFAULT_PORT), '/'),
            'python' => (string)(getenv('SOCIAL_ANALYZER_PYTHON') ?: 'python3'),
            'node' => (string)(getenv('SOCIAL_ANALYZER_NODE') ?: 'node'),
            'mode' => (string)(getenv('SOCIAL_ANALYZER_MODE') ?: 'fast'), // fast|slow|special
            'timeout' => (int)(getenv('SOCIAL_ANALYZER_TIMEOUT') ?: 120),
            'workers' => (string)(getenv('SOCIAL_ANALYZER_WORKERS') ?: ''),
            'logs_dir' => (string)(getenv('SOCIAL_ANALYZER_LOGS') ?: ''),
            'timeout_http' => 8,
        ];
        foreach ($over as $k => $v) {
            if (array_key_exists($k, $cfg) && $v !== null && $v !== '') {
                $cfg[$k] = $k === 'base_url' ? rtrim((string)$v, '/') : $v;
            }
        }
        return $cfg;
    }

    public static function vendorDir(array $over = []): string
    {
        return self::config($over)['vendor'];
    }

    // ------------------------------------------------------------ status ---

    public static function status(array $over = []): array
    {
        $c = self::config($over);
        $v = $c['vendor'];
        $files = [
            'app.py' => is_file($v . '/app.py'),
            'app.js' => is_file($v . '/app.js'),
            'package.json' => is_file($v . '/package.json'),
            'requirements.txt' => is_file($v . '/requirements.txt'),
            'data/' => is_dir($v . '/data'),
            'modules/' => is_dir($v . '/modules'),
            'public/app.html' => is_file($v . '/public/app.html'),
        ];
        $present = count(array_filter($files));
        $python = self::which($c['python']);
        $node = self::which($c['node']);
        $web = self::health($over);
        $ok = $present >= 4; // vendored correctly; runtime optional
        $missing = [];
        if ($present < 7) {
            foreach ($files as $f => $has) {
                if (!$has) {
                    $missing[] = self::VENDOR . '/' . $f . ' missing';
                }
            }
        }
        if ($python === '') {
            $missing[] = 'python3 not on PATH (pip3 install -r requirements.txt needs it)';
        }
        if (empty($web['ok'])) {
            $missing[] = 'webapp not running at ' . $c['base_url'] . ' (cd external/social-analyzer && npm start)';
        }
        return [
            'ok' => $ok,
            'mode' => !empty($web['ok']) ? 'webapp' : ($python !== '' ? 'cli' : 'vendored'),
            'driver' => 'social-analyzer',
            'vendor' => self::VENDOR,
            'repo' => self::REPO,
            'files' => $files,
            'files_present' => $present . '/' . count($files),
            'python' => $python !== '' ? $python : '(missing)',
            'node' => $node !== '' ? $node : '(missing)',
            'base_url' => $c['base_url'],
            'webapp_up' => !empty($web['ok']),
            'webapp_ms' => $web['ms'] ?? 0,
            'missing' => implode('; ', $missing),
            'hint' => $ok
                ? 'Ready. CLI: python3 app.py --username "johndoe" --output json · WebApp: npm start → ' . $c['base_url'] . '/app.html'
                : 'Clone: git clone ' . self::REPO . ' ' . self::VENDOR,
        ];
    }

    public static function isConfigured(array $over = []): bool
    {
        $st = self::status($over);
        return !empty($st['ok']) && ($st['webapp_up'] || stripos($st['python'], 'missing') === false);
    }

    // ----------------------------------------------------------- transport ---

    private static function which(string $bin): string
    {
        try {
            $out = [];
            $code = 1;
            @exec('where ' . escapeshellarg($bin) . ' 2>NUL', $out, $code);
            if ($code === 0 && !empty($out)) {
                return trim((string)$out[0]);
            }
            $out = [];
            @exec('which ' . escapeshellarg($bin) . ' 2>/dev/null', $out, $code);
            if ($code === 0 && !empty($out)) {
                return trim((string)$out[0]);
            }
            // try python / nodejs alternates
            if ($bin === 'python3') {
                $out = [];
                @exec('where python 2>NUL', $out, $code);
                if ($code === 0 && !empty($out)) {
                    return trim((string)$out[0]);
                }
            }
        } catch (Throwable $e) {
        }
        return '';
    }

    /** Raw HTTP caller. Never throws. */
    private static function http(string $method, string $url, $body = null, int $timeout = 8): array
    {
        $t0 = microtime(true);
        $payload = $body === null ? null : (is_string($body) ? $body : json_encode($body));
        $headers = $payload !== null ? ['Content-Type: application/json'] : [];
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
        $ok = $err === '' && $http >= 200 && $http < 300;
        return ['ok' => $ok, 'http' => $http, 'raw' => mb_substr($raw, 0, 8000), 'error' => $ok ? null : mb_substr($err ?: ('HTTP ' . $http), 0, 300), 'ms' => $ms];
    }

    public static function health(array $over = []): array
    {
        $c = self::config($over);
        $timeout = (int)($over['timeout_http'] ?? $c['timeout_http']);
        // app.html is the canonical webapp entry
        $r = self::http('GET', $c['base_url'] . '/app.html', null, $timeout);
        if (empty($r['ok'])) {
            $r = self::http('GET', $c['base_url'] . '/', null, $timeout);
        }
        $r['base_url'] = $c['base_url'];
        return $r;
    }

    // ------------------------------------------------------------ analyze ---

    /**
     * Analyze a username (or comma-separated list) across social sites.
     * Options: mode fast|slow|special, top int, websites string, type string,
     *          countries string, metadata bool, extract bool, filter good|maybe|bad|all,
     *          method find|get|all, output json|pretty (json forced for parse), timeout int.
     * Never throws — returns ['ok','username','profiles'=>[…],'summary'=>[…],'raw'=>…].
     */
    public static function analyze(string $username, array $opts = [], array $over = []): array
    {
        $t0 = microtime(true);
        $username = trim($username);
        if ($username === '' || !preg_match('/^[A-Za-z0-9_.,@ -]{1,200}$/', $username)) {
            return ['ok' => false, 'error' => 'username required (letters, numbers, _, ., ,, @ — max 200 chars)', 'ms' => 0];
        }
        $c = self::config($over);
        $v = $c['vendor'];
        if (!is_file($v . '/app.py')) {
            return ['ok' => false, 'error' => 'vendor missing: ' . self::VENDOR . '/app.py (git clone ' . self::REPO . ')', 'ms' => 0];
        }
        $mode = strtolower((string)($opts['mode'] ?? $c['mode']));
        $modeFlag = $mode === 'slow' ? 'slow' : ($mode === 'special' ? 'special' : 'fast');
        // Map fast→FindUserProfilesFast etc. via --mode slow/fast/special
        $args = ['--username', $username, '--mode', $modeFlag, '--output', 'json'];
        if (!empty($opts['websites'])) {
            $args[] = '--websites';
            $args[] = (string)$opts['websites'];
        }
        if (!empty($opts['top'])) {
            $args[] = '--top';
            $args[] = (string)(int)$opts['top'];
        }
        if (!empty($opts['type'])) {
            $args[] = '--type';
            $args[] = (string)$opts['type'];
        }
        if (!empty($opts['countries'])) {
            $args[] = '--countries';
            $args[] = (string)$opts['countries'];
        }
        if (!empty($opts['filter'])) {
            $args[] = '--filter';
            $args[] = (string)$opts['filter'];
        }
        if (!empty($opts['method'])) {
            $args[] = '--method';
            $args[] = (string)$opts['method'];
        }
        if (!empty($opts['profiles'])) {
            $args[] = '--profiles';
            $args[] = (string)$opts['profiles'];
        }
        if (!empty($opts['options'])) {
            $args[] = '--options';
            $args[] = (string)$opts['options'];
        }
        if (!empty($opts['metadata'])) {
            $args[] = '--metadata';
        }
        if (!empty($opts['extract'])) {
            $args[] = '--extract';
        }
        if (!empty($opts['trim'])) {
            $args[] = '--trim';
        }
        if (isset($opts['timeout'])) {
            $args[] = '--timeout';
            $args[] = (string)(int)$opts['timeout'];
        }
        $timeout = (int)($opts['timeout_exec'] ?? $over['timeout'] ?? $c['timeout']);
        $timeout = max(15, min(600, $timeout));

        $py = $c['python'] !== '' ? $c['python'] : 'python3';
        $cmd = escapeshellarg($py) . ' ' . escapeshellarg($v . '/app.py');
        foreach ($args as $a) {
            // flags (start with -- and no value follows) vs values: we built pairs; flags are lone --metadata etc.
            if (in_array($a, ['--metadata', '--extract', '--trim'], true)) {
                $cmd .= ' ' . $a;
            } elseif (str_starts_with($a, '--')) {
                $cmd .= ' ' . $a;
            } else {
                $cmd .= ' ' . escapeshellarg($a);
            }
        }

        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = @proc_open($cmd, $descriptors, $pipes, $v);
        if (!is_resource($proc)) {
            // fallback to exec
            $out = [];
            $code = 1;
            @exec('cd ' . escapeshellarg($v) . ' && ' . $cmd . ' 2>&1', $out, $code);
            $raw = implode("\n", $out);
            return self::parseCliOutput($username, $raw, (int)round((microtime(true) - $t0) * 1000), $cmd);
        }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $stdout = '';
        $stderr = '';
        $start = time();
        $running = true;
        while ($running) {
            $status = proc_get_status($proc);
            $running = !empty($status['running']);
            $chunk = @stream_get_contents($pipes[1]);
            if ($chunk !== false) {
                $stdout .= $chunk;
            }
            $echunk = @stream_get_contents($pipes[2]);
            if ($echunk !== false) {
                $stderr .= $echunk;
            }
            if (!$running) {
                break;
            }
            if ((time() - $start) >= $timeout) {
                @proc_terminate($proc, 9);
                $chunk = @stream_get_contents($pipes[1]);
                if ($chunk !== false) {
                    $stdout .= $chunk;
                }
                break;
            }
            usleep(100000);
        }
        @fclose($pipes[1]);
        @fclose($pipes[2]);
        @proc_close($proc);
        $raw = trim($stdout) !== '' ? $stdout : $stderr;
        return self::parseCliOutput($username, $raw, (int)round((microtime(true) - $t0) * 1000), $cmd);
    }

    /** Parse `app.py --output json` stdout into normalized profiles. Never throws. */
    private static function parseCliOutput(string $username, string $raw, int $ms, string $cmd = ''): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return ['ok' => false, 'username' => $username, 'profiles' => [], 'summary' => ['detected' => 0, 'total' => 0], 'error' => 'empty output from social-analyzer (is python + deps installed? pip3 install -r requirements.txt)', 'ms' => $ms, 'cmd' => $cmd];
        }
        // app.py may print log lines before JSON — extract the largest {...} or [...] block
        $jsonText = $raw;
        if (str_starts_with(ltrim($raw), '{') === false && str_starts_with(ltrim($raw), '[') === false) {
            $s = strpos($raw, '{');
            $e = strrpos($raw, '}');
            if ($s !== false && $e !== false && $e > $s) {
                $jsonText = substr($raw, $s, $e - $s + 1);
            }
        }
        $data = json_decode($jsonText, true);
        if (!is_array($data)) {
            // pretty output fallback: keep raw, still ok=false but preserve excerpt
            return ['ok' => false, 'username' => $username, 'profiles' => [], 'summary' => ['detected' => 0, 'total' => 0], 'error' => 'could not parse JSON output (use --output json). Excerpt: ' . mb_substr($raw, 0, 400), 'raw_excerpt' => mb_substr($raw, 0, 4000), 'ms' => $ms, 'cmd' => $cmd];
        }
        $profiles = [];
        // Normalize: social-analyzer JSON is typically {site: {link, rate, title, ...}} or list
        if (array_is_list($data)) {
            foreach ($data as $row) {
                if (is_array($row)) {
                    $profiles[] = $row;
                }
            }
        } else {
            foreach ($data as $site => $info) {
                if (!is_array($info)) {
                    continue;
                }
                $profiles[] = array_merge(['site' => (string)$site], $info);
            }
        }
        $detected = 0;
        foreach ($profiles as $p) {
            $rate = (int)($p['rate'] ?? $p['score'] ?? 0);
            $status = strtolower((string)($p['status'] ?? ($rate >= 70 ? 'good' : ($rate >= 30 ? 'maybe' : 'bad'))));
            if ($status === 'good' || $status === 'detected' || $rate >= 70) {
                $detected++;
            }
        }
        return [
            'ok' => true,
            'username' => $username,
            'profiles' => $profiles,
            'summary' => ['detected' => $detected, 'total' => count($profiles)],
            'raw_excerpt' => mb_substr($raw, 0, 4000),
            'ms' => $ms,
        ];
    }

    /** List known sites (--list). Never throws. */
    public static function listSites(array $over = []): array
    {
        $c = self::config($over);
        $v = $c['vendor'];
        if (!is_file($v . '/app.py')) {
            return ['ok' => false, 'error' => 'vendor missing'];
        }
        $py = $c['python'] !== '' ? $c['python'] : 'python3';
        $out = [];
        $code = 1;
        @exec('cd ' . escapeshellarg($v) . ' && ' . escapeshellarg($py) . ' ' . escapeshellarg($v . '/app.py') . ' --list 2>&1', $out, $code);
        $raw = implode("\n", $out);
        return ['ok' => $code === 0, 'raw' => mb_substr($raw, 0, 8000), 'lines' => count($out)];
    }

    // -------------------------------------------------------------- db ---

    public const SCHEMA = <<<SQL
CREATE TABLE IF NOT EXISTS socialanalyzer_runs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    module TEXT NOT NULL DEFAULT 'shared',
    kind TEXT NOT NULL DEFAULT 'analyze',
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
            $st = $db->prepare('INSERT INTO socialanalyzer_runs (module, kind, target, ok, ms, output_excerpt) VALUES (?,?,?,?,?,?)');
            $st->execute([$module, mb_substr($kind, 0, 60), mb_substr($target, 0, 200), $ok ? 1 : 0, $ms, mb_substr($excerpt, 0, 2000)]);
        } catch (Throwable $e) {
        }
    }
}
