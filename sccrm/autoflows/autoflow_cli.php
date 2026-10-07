#!/usr/bin/env php
<?php
/**
 * SCIT CRM - AutoFlows scheduler runner (CLI)
 *
 * Usage: php autoflow_cli.php [flow_id]
 *   No args  -> runs all active "schedule" flows (digest, ...)
 *   flow_id  -> runs that specific flow once
 *
 * Cron (hourly digest, Linux/Mac):
 *   0 * * * * php /path/to/sccrm/autoflows/autoflow_cli.php
 *
 * Windows Task Scheduler:
 *   php C:\path\to\sccrm\autoflows\autoflow_cli.php
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/autoflow_engine.php';
autoflowEnsureTables($db);

$start = microtime(true);
$flowId = isset($argv[1]) ? intval($argv[1]) : 0;

if ($flowId > 0) {
    $stmt = $db->prepare("SELECT * FROM autoflows WHERE id = ?");
    $stmt->execute([$flowId]);
    $flow = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$flow) { echo "Flow #$flowId not found.\n"; exit(1); }
    $res = autoflowRun($db, $flow, 'schedule', ['by' => 'cli']);
    echo "[{$res['status']}] {$flow['name']}: {$res['message']}\n";
    exit($res['status'] === 'success' ? 0 : 1);
}

$flows = $db->query("SELECT * FROM autoflows WHERE trigger = 'schedule' AND is_active = 1 ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
if (!$flows) { echo "No active schedule flows.\n"; exit(0); }
$fail = 0;
foreach ($flows as $flow) {
    $res = autoflowRun($db, $flow, 'schedule', ['by' => 'cli']);
    echo "[{$res['status']}] {$flow['name']}: {$res['message']}\n";
    if ($res['status'] !== 'success') $fail++;
}
echo 'Done in ' . round(microtime(true) - $start, 2) . "s.\n";
exit($fail ? 1 : 0);
