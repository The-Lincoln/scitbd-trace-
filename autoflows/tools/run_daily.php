<?php
/**
 * AutoFlows — daily cron worker.
 *
 * Picks up every ACTIVE flow whose schedule says "run today" and whose time
 * has passed, but which has not already run today, then executes it through
 * the same FlowAgent the UI uses.
 *
 *   php tools/run_daily.php            # run everything due
 *   php tools/run_daily.php --dry      # show what would run
 *   php tools/run_daily.php --force    # ignore last_run_at (re-run today)
 *   php tools/run_daily.php --publish  # also move scheduled -> published
 *   php tools/run_daily.php --hygiene  # memory-hygiene report (EvolveMemory pending/FTS)
 *   php tools/run_daily.php --mine     # skill-miner report (pending memories as proposals)
 *   php tools/run_daily.php --require-approval  # hold Block 1 until Discord/Slack approval file exists
 *
 * Windows Task Scheduler / cron example (09:00 daily):
 *   schtasks /create /tn AutoFlows /tr "php E:\openaiVideo\autoflows\tools\run_daily.php" /sc daily /st 09:00
 */
declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));

require BASE_PATH . '/app/config.php';
require BASE_PATH . '/app/core/Helpers.php';
require BASE_PATH . '/app/core/Database.php';
require BASE_PATH . '/app/core/Model.php';
require BASE_PATH . '/app/core/Controller.php';
require BASE_PATH . '/app/core/View.php';
require BASE_PATH . '/app/core/Router.php';

foreach (['Setting', 'User', 'SocialAccount', 'Conversation', 'Message', 'Flow', 'Content', 'Run'] as $m) {
    require BASE_PATH . '/app/models/' . $m . '.php';
}
foreach (['TinyLLM', 'Sse', 'PromptLibrary', 'ContentFactory', 'Agent', 'SocialAuth', 'Publisher', 'HermesSkills', 'EvolveMemory'] as $s) {
    $f = BASE_PATH . '/app/services/' . $s . '.php';
    if (is_file($f)) {
        require $f;
    }
}

session_start();
Database::boot();

$dry    = in_array('--dry', $argv, true);
$force  = in_array('--force', $argv, true);
$publish = in_array('--publish', $argv, true);

$out = fn (string $s): mixed => print($s . PHP_EOL);

$out('AutoFlows daily run — ' . date('Y-m-d H:i:s'));

// Approval gate: Discord/Slack bots write storage/approval-YYYY-MM-DD.json
// on Approve (md.s.lincoln@gmail.com only). With --require-approval the
// cron holds Block 1 instead of executing flows.
if (in_array('--require-approval', $argv, true)) {
    $apFile = BASE_PATH . '/storage/approval-' . date('Y-m-d') . '.json';
    $ap = is_file($apFile) ? json_decode((string) file_get_contents($apFile), true) : null;
    if (!is_array($ap) || empty($ap['approved'])) {
        $out('  awaiting approval — no approval file for today; Block 1 held.');
        Database::log('cron.awaiting_approval', 'held: approval missing');
        exit(0);
    }
    $out('  approval: ' . ($ap['approved_by'] ?? '?') . ' via ' . ($ap['channel'] ?? '?') . ' at ' . ($ap['approved_at'] ?? '?'));
}
$llm = TinyLLM::status(3);
$out('  model: ' . $llm['model'] . ' — ' . ($llm['ok'] ? 'reachable (' . $llm['latency_ms'] . 'ms)' : 'unreachable, template engine will be used'));

// ------------------------------------------------------------ scheduled ---
$due = [];
foreach (Flow::dueToday() as $f) {
    if ($f['schedule'] === 'manual') {
        continue;
    }
    if (!$force && $f['last_run_at'] !== null && substr((string) $f['last_run_at'], 0, 10) === date('Y-m-d')) {
        continue;
    }
    if (!$force && date('H:i') < (string) $f['run_at']) {
        continue;
    }
    $due[] = $f;
}

if ($due === []) {
    $out('  no flows due.');
} else {
    $out('  ' . count($due) . ' flow(s) due: ' . implode(', ', array_column($due, 'name')));
}

$ran = 0;
foreach ($due as $f) {
    if ($dry) {
        $out('  [dry] would run #' . $f['id'] . ' ' . $f['name'] . ' (' . $f['channel'] . ')');
        continue;
    }

    $out('');
    $out('> ' . $f['name'] . ' [' . $f['channel'] . ']');

    try {
        // Agent writes nothing to the wire — capture a local trace instead.
        $events = [];
        $result = Agent::run([
            'goal'       => trim((string) $f['brief']) ?: (string) $f['name'],
            'channels'   => Flow::channelsFor($f),
            'flow_id'    => (int) $f['id'],
            'tone'       => (string) $f['tone'],
            'audience'   => (string) $f['audience'],
            'platforms'  => ContentFactory::context(['platforms' => $f['platforms']])['platforms'],
            'count'      => (int) $f['count'],
            'trigger_by' => 'daily',
        ], function (array $p) use (&$events): void {
            $events[] = $p;
        });

        $ran++;
        $out('  run #' . $result['run_id'] . ' — ' . $result['outputs'] . ' output(s), score ' . $result['score'] . ', provider ' . $result['provider']);
        foreach ($result['content_ids'] as $id) {
            $c = Content::find($id);
            if ($c !== null) {
                $out('    - [' . $c['id'] . '] ' . $c['channel'] . ($c['platform'] ? '/' . $c['platform'] : '') . ' — ' . excerpt((string) $c['title'], 60));
            }
        }
    } catch (Throwable $e) {
        $out('  FAILED: ' . $e->getMessage());
        Database::log('cron.flow_failed', $f['name'] . ': ' . $e->getMessage(), 'error');
    }
}

// ------------------------------------------------------------ publish -----
// Delivers due items through connected Gmail / Facebook channels.
// Without a connection each item is recorded as a simulated publish so
// the autoflow still completes end-to-end.
if ($publish && !$dry) {
    $dueP = Content::dueForPublish();
    foreach ($dueP as $c) {
        $id = (int) $c['id'];
        if (class_exists('Publisher')) {
            $res = Publisher::publishContent($id, 0);
            $flag = !empty($res['simulated']) ? ' (simulated)' : '';
            $dest = $res['post_id'] ?? $res['message_id'] ?? '';
            $out('  published #' . $id . $flag . ' — ' . excerpt((string) $c['title'], 50) . ($dest !== '' ? ' [' . $dest . ']' : ''));
            if (empty($res['ok'])) {
                $out('    ! ' . ($res['error'] ?? 'publish failed'));
            }
        } else {
            Content::setStatus($id, 'published');
            $out('  published #' . $id . ' — ' . excerpt((string) $c['title'], 50));
        }
    }
    if ($dueP !== []) {
        $out('  ' . count($dueP) . ' scheduled item(s) published.');
    }
} elseif ($publish) {
    $out('  [dry] would publish ' . count(Content::dueForPublish()) . ' item(s).');
}

// ------------------------------------------------------------ hygiene -----
if (in_array('--hygiene', $argv, true) && class_exists('EvolveMemory')) {
    EvolveMemory::ensureTable(Database::pdo());
    $st = EvolveMemory::stats(Database::pdo());
    $out('  [hygiene] memories total=' . $st['total'] . ' confirmed=' . $st['confirmed'] . ' pending=' . $st['pending'] . ' pruned=' . $st['pruned'] . ' fts=' . ($st['fts'] ? 'on' : 'off'));
    foreach (EvolveMemory::list(Database::pdo(), 'pending', 5) as $m) {
        $out('    - pending #' . $m['id'] . ' [' . $m['kind'] . '/i' . $m['importance'] . '] ' . excerpt((string) $m['text'], 70));
    }
    if (!$dry) {
        Database::log('cron.hygiene', 'pending=' . $st['pending'] . ' fts=' . ($st['fts'] ? 'on' : 'off'));
    }
}

// --------------------------------------------------------------- mine -----
if (in_array('--mine', $argv, true)) {
    $skills = class_exists('HermesSkills') ? count(HermesSkills::list()) : 0;
    $out('  [mine] skills=' . $skills . ' (storage/skills)');
    if (class_exists('EvolveMemory')) {
        $pend = EvolveMemory::list(Database::pdo(), 'pending', 5);
        $out('  [mine] ' . count($pend) . ' pending memor' . (count($pend) === 1 ? 'y' : 'ies') . ' as skill proposals');
        foreach ($pend as $m) {
            $out('    - proposal candidate #' . $m['id'] . ' [' . $m['kind'] . '] ' . excerpt((string) $m['text'], 70));
        }
    }
    if (!$dry) {
        Database::log('cron.mine', 'skills=' . $skills);
    }
}

Database::log('cron.run', sprintf('%s ran=%d due=%d', $dry ? 'dry' : 'live', $ran, count($due)));
$out('');
$out('Done — ' . ($dry ? 'dry run, ' : '') . $ran . ' flow(s) executed.');
