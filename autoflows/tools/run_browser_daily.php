<?php
/**
 * AutoFlows cron worker — scheduled browser monitors.
 *
 *   php tools/run_browser_daily.php            # run monitors due (hourly/daily)
 *   php tools/run_browser_daily.php --dry      # show what would run
 *   php tools/run_browser_daily.php --force    # ignore last_run_at, run all active
 *
 * Reads `browser_monitors` from storage/app.db (created by AgentBrowser::ensureTables).
 * Seed example:
 *   INSERT INTO browser_monitors (name, url, frequency) VALUES ('Homepage','https://example.com','daily');
 */
declare(strict_types=1);

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}
require BASE_PATH . '/app/config.php';
require BASE_PATH . '/app/core/Helpers.php';
require BASE_PATH . '/app/core/Database.php';
foreach (['AgentBrowser', 'BrowserAgent'] as $s) {
    $f = BASE_PATH . '/app/services/' . $s . '.php';
    if (is_file($f)) {
        require $f;
    }
}

$dry = in_array('--dry', $argv ?? [], true);
$force = in_array('--force', $argv ?? [], true);

Database::boot();
$pdo = Database::pdo();
AgentBrowser::ensureTables($pdo);

$mons = $pdo->query("SELECT * FROM browser_monitors WHERE is_active = 1 ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
if ($mons === []) {
    echo "No active browser_monitors. Seed one:\n";
    echo "  INSERT INTO browser_monitors (name, url, frequency) VALUES ('Homepage','https://example.com','daily');\n";
    exit(0);
}

$ran = 0;
foreach ($mons as $m) {
    $freq = strtolower((string)($m['frequency'] ?? 'daily'));
    $last = (string)($m['last_run_at'] ?? '');
    $due = $force || $last === '';
    if (!$due) {
        $ageH = (time() - strtotime($last)) / 3600;
        $due = ($freq === 'hourly' && $ageH >= 1) || ($freq === 'daily' && $ageH >= 20) || ($freq === 'manual' && false) || ($freq !== 'hourly' && $freq !== 'daily' && $freq !== 'manual' && $ageH >= 20);
    }
    if (!$due) {
        echo "[skip] #{$m['id']} {$m['name']} (last {$last})\n";
        continue;
    }
    echo "[run] #{$m['id']} {$m['name']} <{$m['url']}>\n";
    if ($dry) {
        continue;
    }
    $t0 = microtime(true);
    try {
        $res = BrowserAgent::run(['plan' => 'monitor', 'url' => (string)$m['url'], 'goal' => 'Scheduled monitor: ' . $m['name'], 'module' => 'autoflows']);
        $ok = !empty($res['ok']);
        $ms = (int)round((microtime(true) - $t0) * 1000);
        $note = mb_substr($res['report'] ?? '', 0, 500);
    } catch (Throwable $e) {
        $ok = false;
        $ms = (int)round((microtime(true) - $t0) * 1000);
        $note = 'Exception: ' . $e->getMessage();
    }
    $pdo->prepare("UPDATE browser_monitors SET last_status=?, last_ms=?, last_note=?, run_count=run_count+1, last_run_at=datetime('now') WHERE id=?")
        ->execute([$ok ? 200 : 0, $ms, $note, $m['id']]);
    AgentBrowser::logRun($pdo, 'autoflows', 'browser:monitor:cron', (string)$m['url'], $ok, $ms, $note);
    Database::log('browser.monitor', "#{$m['id']} " . ($ok ? 'OK' : 'FAIL') . " {$ms}ms");
    echo '  -> ' . ($ok ? 'OK' : 'FAIL') . " {$ms}ms\n";
    $ran++;
}
echo "Done. Ran {$ran}.\n";
