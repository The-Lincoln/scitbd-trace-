<?php
/**
 * AutoFlows — smoke test.
 *
 *   php tools/smoke_test.php           full suite (lint + unit + HTTP routes)
 *   php tools/smoke_test.php --fast    skip the HTTP server round-trip
 *   php tools/smoke_test.php --no-net  skip nothing extra, alias of --fast
 *
 * Why HTTP is tested for real: the sibling project shipped five broken calls
 * because JS hit `media/upload` instead of `api/media/upload`. Only a live
 * request catches a wrong route or a PHP fatal inside a view.
 */
declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));

$FAST = in_array('--fast', $argv, true) || in_array('--no-net', $argv, true);
$PORT = 8021;

$pass = 0;
$fail = 0;
$warnings = [];

function ok(string $label, bool $cond, string $detail = ''): bool
{
    global $pass, $fail, $warnings;
    if ($cond) {
        $pass++;
        echo "  ✓ {$label}" . ($detail !== '' ? " — {$detail}" : '') . PHP_EOL;
    } else {
        $fail++;
        echo "  ✗ {$label}" . ($detail !== '' ? " — {$detail}" : '') . PHP_EOL;
    }
    return $cond;
}

function section(string $t): void
{
    echo PHP_EOL . strtoupper($t) . PHP_EOL;
}

// ── bootstrap (unit-level only; the HTTP pass boots its own process) ───────
require BASE_PATH . '/app/config.php';
require BASE_PATH . '/app/core/Helpers.php';
require BASE_PATH . '/app/core/Database.php';
require BASE_PATH . '/app/core/Model.php';
require BASE_PATH . '/app/core/Controller.php';
require BASE_PATH . '/app/core/View.php';
require BASE_PATH . '/app/core/Router.php';

foreach (['Setting', 'User', 'SocialAccount', 'Conversation', 'Message', 'Flow', 'Content', 'Run', 'DailyTask'] as $m) {
    require BASE_PATH . '/app/models/' . $m . '.php';
}
foreach (['TinyLLM', 'Sse', 'PromptLibrary', 'ContentFactory', 'Agent', 'SocialAuth', 'Publisher', 'SlackApp', 'FinanceTube', 'HermesSkills', 'EvolveMemory', 'ScitbdCeo'] as $s) {
    $f = BASE_PATH . '/app/services/' . $s . '.php';
    if (is_file($f)) {
        require $f;
    }
}
foreach (['Home', 'Chat', 'Flow', 'Content', 'Agent', 'Settings', 'Auth', 'Slack', 'Tasks'] as $c) {
    require BASE_PATH . '/app/controllers/' . $c . 'Controller.php';
}

echo "AutoFlows smoke test" . PHP_EOL;
echo '  PHP ' . PHP_VERSION . ' · ' . BASE_PATH . PHP_EOL;

// ────────────────────────────────────────────────────────── 1. lint ─────────
section('PHP lint');

$rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(BASE_PATH, FilesystemIterator::SKIP_DOTS));
$phpFiles = [];
foreach ($rii as $file) {
    if ($file->getExtension() !== 'php') {
        continue;
    }
    $path = $file->getPathname();
    if (str_contains($path, DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR)) {
        continue;
    }
    $phpFiles[] = $path;
}
sort($phpFiles);

$lintErrors = [];
foreach ($phpFiles as $f) {
    $out = [];
    $code = 0;
    exec('php -l ' . escapeshellarg($f) . ' 2>&1', $out, $code);
    if ($code !== 0) {
        $lintErrors[] = basename($f) . ': ' . implode(' ', $out);
    }
}
ok('all files lint clean', $lintErrors === [], count($phpFiles) . ' files');
foreach ($lintErrors as $e) {
    echo "      {$e}" . PHP_EOL;
}

// ───────────────────────────────────────────────────────── 2. database ─────
section('Database');

try {
    Database::boot();
    ok('boot()', true, basename((string) config('db_path')));
} catch (Throwable $e) {
    ok('boot()', false, $e->getMessage());
    exit(1);
}

$tables = [];
foreach (Database::pdo()->query("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name") as $r) {
    $tables[] = $r['name'];
}
$want = ['conversations', 'messages', 'flows', 'runs', 'content', 'settings', 'task_logs'];
$missing = array_diff($want, $tables);
ok('schema tables present', $missing === [], implode(', ', $want));

$fk = (int) Database::pdo()->query('PRAGMA foreign_keys')->fetchColumn();
ok('foreign_keys ON', $fk === 1);

$seed = Flow::all();
ok('starter flow seeded', count($seed) >= 1, ($seed[0]['name'] ?? '—'));

// ─────────────────────────────────────────────────────── 3. router map ─────
section('Router');

$map = (new ReflectionClass('Router'))->getConstant('MAP');
ok('route table non-trivial', count($map) >= 30, count($map) . ' routes');

$bad = [];
foreach ($map as $route => [$ctrl, $action]) {
    if (!class_exists($ctrl)) {
        $bad[] = "{$route} → missing {$ctrl}";
        continue;
    }
    if (!method_exists($ctrl, $action)) {
        $bad[] = "{$route} → {$ctrl}::{$action}()";
    }
}
ok('every route resolves to a real method', $bad === []);
foreach ($bad as $b) {
    echo "      {$b}" . PHP_EOL;
}

// ───────────────────────────────────────────────── 4. offline generation ───
section('Offline generation (template engine)');

$ctx = ContentFactory::context([
    'brief'     => 'why small teams should publish weekly',
    'tone'      => 'friendly',
    'audience'  => 'founders',
    'platforms' => ['twitter', 'linkedin', 'instagram'],
    'count'     => 3,
]);

foreach (['social', 'blog', 'email'] as $channel) {
    $draft = ContentFactory::localDraft($channel, $ctx);
    $rows  = ContentFactory::parse($channel, $draft, $ctx);

    ok("{$channel}: draft parses", count($rows) >= 1, count($rows) . ' row(s), ' . mb_strlen($draft) . ' chars');

    $r = $rows[0];
    ok("{$channel}: body non-empty", trim((string) $r['body']) !== '');
    ok("{$channel}: title present", trim((string) $r['title']) !== '', excerpt((string) $r['title'], 44));
    ok("{$channel}: chars tracked", ($r['chars'] ?? 0) > 0, (string) ($r['chars'] ?? 0));

    if ($channel === 'blog') {
        ok('blog: slug generated', trim((string) ($r['slug'] ?? '')) !== '', (string) ($r['slug'] ?? ''));
        ok('blog: excerpt set', trim((string) ($r['excerpt'] ?? '')) !== '');
    }
    if ($channel === 'email') {
        $meta = $r['meta'] ?? [];
        ok('email: CTA captured', trim((string) ($meta['cta'] ?? '')) !== '', (string) ($meta['cta'] ?? ''));
    }
    if ($channel === 'social') {
        $limits = (array) config('channels.social.platforms');
        foreach ($rows as $row) {
            $lim  = (int) ($limits[$row['platform']]['limit'] ?? 0);
            $over = $lim > 0 && $row['chars'] > $lim;
            if ($over) {
                $warnings[] = "social/{$row['platform']} over limit: {$row['chars']} > {$lim}";
            }
        }
        ok('social: within platform limits', $warnings === [], count($rows) . ' post(s) checked');
    }
}

// Malformed input must still yield something usable.
$fallback = ContentFactory::parse('blog', 'just some free text with no markers at all', $ctx);
ok('parse tolerates unstructured text', count($fallback) === 1 && trim($fallback[0]['body']) !== '');

// ────────────────────────────────────────────────────────── 5. agent ───────
section('FlowAgent');

$plan = Agent::plan(['social', 'blog', 'email']);
ok('full plan', $plan === ['brief', 'outline', 'social', 'blog', 'email', 'review'], implode(' → ', $plan));

$plan2 = Agent::plan(['blog']);
ok('partial plan still bracketed by brief/review', $plan2 === ['brief', 'outline', 'blog', 'review'], implode(' → ', $plan2));

$plan3 = Agent::plan(['nope']);
ok('unknown channels fall back to all', count($plan3) === 6);

ok('offline step answers formatted', str_contains(Agent::localStep('review', $ctx), 'SCORE:'));
ok('brief step formatted', str_contains(Agent::localStep('brief', $ctx), '**Topic**'));

// Real run against the local engine (no network needed).
$events = [];
$result = Agent::run([
    'goal'       => 'a repeatable content loop for a two-person team',
    'channels'   => ['blog'],
    'count'      => 1,
    'trigger_by' => 'manual',
], function (array $p) use (&$events): void {
    $events[] = $p;
});

ok('run completes', ($result['status'] ?? '') === 'done', 'run #' . $result['run_id']);

// The run above requested only `blog`, so the plan is brief → outline → blog →
// review. Assert against the plan we actually asked for, never a hard-coded 6.
$expectedPlan = Agent::plan(['blog']);
$stepEvents   = array_filter($events, fn ($e) => $e['type'] === 'step');
ok('every planned step emitted', count($stepEvents) === count($expectedPlan),
   count($stepEvents) . '/' . count($expectedPlan) . ': ' . implode(' → ', $expectedPlan));
ok('terminal done emitted', end($events)['type'] === 'done');

$run = Run::find($result['run_id']);
ok('run persisted with trace', $run !== null && count(Run::trace($run)) === count($expectedPlan),
   $run['status'] . ' / ' . $run['steps'] . ' steps');

ok('content persisted', count($result['content_ids']) >= 1, count($result['content_ids']) . ' item(s)');
$one = Content::find((int) ($result['content_ids'][0] ?? 0));
ok('artifact round-trips', $one !== null && trim((string) $one['body']) !== '', $one ? excerpt((string) $one['title'], 40) : '');
ok('artifact linked to run', $one !== null && (int) $one['run_id'] === (int) $result['run_id']);

// Chat slash command parsing must recognise the documented commands.
$cmdRe = '/^\/([a-z]+)\s*(.*)$/is';
foreach (['/social topic', '/blog big idea', '/email launch', '/daily everything', '/help'] as $c) {
    preg_match($cmdRe, $c, $m);
    ok("command parses: {$c}", !empty($m[1]));
}

// ────────────────────────────────────────────────────── 6. content CRUD ────
section('Content lifecycle');

$id = Content::create(['channel' => 'social', 'title' => 'Lifecycle probe', 'body' => 'probe body', 'status' => 'draft']);
ok('create', $id > 0);

Content::setStatus($id, 'approved');
ok('approve', Content::find($id)['status'] === 'approved');

Content::update($id, ['status' => 'scheduled', 'publish_at' => '2030-01-01 09:00:00']);
$row = Content::find($id);
ok('schedule', $row['status'] === 'scheduled' && $row['publish_at'] !== null);
ok('char count recomputed on update', (int) $row['chars'] === mb_strlen((string) $row['body']), (string) $row['chars']);

ok('not yet due', Content::dueForPublish() === [] || !in_array($id, array_column(Content::dueForPublish(), 'id'), true));

$stats = Content::stats();
ok('stats coherent', $stats['total'] === array_sum($stats['by_channel']) && $stats['total'] === $stats['social'] + $stats['blog'] + $stats['email'] + ($stats['youtube'] ?? 0));

Content::destroy($id);
ok('delete', Content::find($id) === null);

// ─────────────────────────────────────────────────────── 7. settings ───────
section('Settings override');

$before = (string) config('brand.cta');
Setting::put('brand_cta', 'Probe CTA');
config_refresh();                 // same dance SettingsController::save() does
$redefined = (string) config('brand.cta');
ok('DB setting overrides file config', $redefined === 'Probe CTA', $redefined);
ok('override actually differs from the file default', $redefined !== $before, $before);

// Setting::write() is a protected Model helper — go straight to PDO instead.
Database::pdo()->prepare('DELETE FROM settings WHERE key = ?')->execute(['brand_cta']);
config_refresh();
ok('cleanup restores the file default', (string) config('brand.cta') === $before, (string) config('brand.cta'));

// ───────────────────────────────────────────────────────── 8. HTTP ─────────
if (!$FAST) {
    section('HTTP routes');

    $logOut = tempnam(sys_get_temp_dir(), 'af_out');
    $logErr = tempnam(sys_get_temp_dir(), 'af_err');
    @unlink($logOut);
    @unlink($logErr);

    $spec = [
        0 => ['file', 'NUL', 'r'],
        1 => ['file', $logOut, 'w'],
        2 => ['file', $logErr, 'w'],
    ];
    $cmd = sprintf('php -S 127.0.0.1:%d -t %s %s', $PORT, escapeshellarg(BASE_PATH . '/public'), escapeshellarg(BASE_PATH . '/public/router.php'));
    $proc = proc_open($cmd, $spec, $pipes, BASE_PATH);

    if (!is_resource($proc)) {
        ok('start dev server', false, 'proc_open failed');
    } else {
        $status = proc_get_status($proc);
        $pid = (int) $status['pid'];
        $GLOBALS['AF_SERVER'] = ['proc' => $proc, 'pid' => $pid];

        // A fatal anywhere below must not orphan the server: the normal
        // taskkill path never runs when PHP dies, and a leaked instance then
        // makes the NEXT run's fsockopen connect to stale content.
        register_shutdown_function('stop_dev_server');

        // Wait for the port to answer.
        $up = false;
        for ($i = 0; $i < 60; $i++) {
            $conn = @fsockopen('127.0.0.1', $PORT, $errno, $errstr, 0.3);
            if ($conn) { fclose($conn); $up = true; break; }
            usleep(200000);
        }
        ok('dev server up', $up, '127.0.0.1:' . $PORT);

        if ($up) {
            $pages = ['home', 'chat', 'flows', 'flow', 'content', 'agent', 'settings', 'nonsense/route'];
            foreach ($pages as $r) {
                $body = http_get('http://127.0.0.1:' . $PORT . '/index.php?r=' . urlencode($r));
                if ($r === 'nonsense/route') {
                    ok("route 404s: {$r}", $body !== null && str_contains($body, 'not found'), $body === null ? 'no response' : strlen($body) . ' bytes');
                } else {
                    ok("page renders: {$r}", $body !== null && !str_contains($body, 'View not found') && !str_contains($body, 'Fatal error'),
                        $body === null ? 'no response' : strlen($body) . ' bytes');
                }
            }

            // Editing pages need an id that exists.
            // Model::scalar() is protected — query PDO directly instead.
            $probeStmt = Database::pdo()->query('SELECT id FROM content ORDER BY id DESC LIMIT 1');
            $probeId = (int) ($probeStmt ? $probeStmt->fetchColumn() : 0);
            if ($probeId > 0) {
                $body = http_get('http://127.0.0.1:' . $PORT . '/index.php?r=item&id=' . $probeId);
                ok('content editor renders', $body !== null && !str_contains($body, 'Fatal error'));
            } else {
                ok('content editor renders', true, 'skipped — no content rows');
            }

            // JSON endpoints (GET-only ones; POST ones are covered by unit tests).
            foreach (['api/stats', 'api/agent/runs', 'api/chat/status', 'api/chat/conversations'] as $api) {
                $raw = http_get('http://127.0.0.1:' . $PORT . '/index.php?r=' . $api, true);
                $j = json_decode((string) $raw, true);
                ok("api responds: {$api}", is_array($j) && ($j['ok'] ?? false) === true, $raw === null ? 'no response' : substr((string) $raw, 0, 60) . '…');
            }

            // Static assets must actually exist (the CDN/asset path trap).
            // get_headers() in assoc mode puts the STATUS LINE at index 0 —
            // "(int) 'HTTP/1.1 200 OK'" is 0, so extract the code with a regex.
            foreach (['assets/css/app.css', 'assets/js/app.js', 'assets/js/chat.js', 'assets/js/agent.js',
                      'assets/js/content.js', 'assets/js/content-edit.js', 'assets/js/dashboard.js',
                      'assets/js/flow-edit.js', 'assets/js/flows.js', 'assets/js/settings.js'] as $asset) {
                $h = @get_headers('http://127.0.0.1:' . $PORT . '/' . $asset, true);
                $code = 0;
                if (is_array($h)) {
                    $status = is_array($h[0]) ? $h[0][0] : $h[0];
                    if (preg_match('#\s(\d{3})\s#', (string) $status, $m)) {
                        $code = (int) $m[1];
                    }
                }
                $len = is_array($h) && isset($h['Content-Length']) ? (int) $h['Content-Length'] : 0;
                ok("asset 200: {$asset}", $code === 200 && $len > 0,
                    'HTTP ' . $code . ' · ' . $len . ' bytes');
            }
        }

        // Kill the server — never wait on it (Windows proc_close blocks).
        // Also runs from the shutdown handler if anything fatals above.
        stop_dev_server();

        $err = is_file($logErr) ? trim((string) file_get_contents($logErr)) : '';
        $fatal = '';
        foreach (explode("\n", $err) as $line) {
            if (str_contains($line, 'PHP Fatal') || str_contains($line, 'PHP Parse')) {
                $fatal .= $line . ' | ';
            }
        }
        ok('server log free of fatals', $fatal === '', $fatal !== '' ? rtrim($fatal, ' | ') : 'clean');
        @unlink($logOut);
        @unlink($logErr);
    }
} else {
    section('HTTP routes');
    echo "  (skipped — --fast)\n";
}

// ─────────────────────────────────────────────────────────── summary ───────
echo PHP_EOL . str_repeat('─', 58) . PHP_EOL;
echo "  pass: {$pass}   fail: {$fail}" . PHP_EOL;
foreach ($warnings as $w) {
    echo "  ⚠ {$w}" . PHP_EOL;
}
echo str_repeat('─', 58) . PHP_EOL;
echo $fail === 0 ? "  ✅ ALL GREEN\n" : "  ❌ {$fail} FAILURE(S)\n";

exit($fail === 0 ? 0 : 1);

// ───────────────────────────────────────────────────────────── helpers ─────
/**
 * Tear down the dev server. Idempotent, and registered as a shutdown
 * handler so a fatal in the test body cannot leave a listener behind
 * (that silently poisons the next run's port probe).
 *
 * Never blocks: on Windows proc_close() waits on the child and hangs.
 */
function stop_dev_server(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $srv = $GLOBALS['AF_SERVER'] ?? null;
    if (!$srv || !is_resource($srv['proc'])) {
        return;
    }
    $done = true;

    // taskkill FIRST. proc_terminate() only kills PHP's direct child, which
    // orphans the real listener — the follow-up taskkill then reports
    // "process not found" (rc=128) and the server leaks. A/B verified:
    // terminate-then-taskkill leaks, taskkill-then-terminate does not.
    if (DIRECTORY_SEPARATOR === '\\') {
        @exec('taskkill /F /T /PID ' . (int) $srv['pid'] . ' 2>&1');
    }
    @proc_terminate($srv['proc']);
    usleep(300000);
    @proc_close($srv['proc']);
    unset($GLOBALS['AF_SERVER']);
}

function http_get(string $url, bool $json = false): ?string
{
    $ctx = stream_context_create([
        'http' => [
            'timeout'         => 12,
            'ignore_errors'   => true,
            'header'          => $json ? "Accept: application/json\r\nX-Requested-With: XMLHttpRequest\r\n" : "Accept: text/html\r\n",
            'follow_location' => 0,
        ],
    ]);
    $body = @file_get_contents($url, false, $ctx);
    return $body === false ? null : $body;
}
