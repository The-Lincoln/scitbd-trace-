#!/usr/bin/env php
<?php
/**
 * SCIT CRM - Hourly Lead Generator (CLI)
 * 
 * Usage: php generator_cli.php [count=5] [market_id] [service_id]
 * 
 * For hourly automation, add to crontab:
 *   0 * * * * php /path/to/generator_cli.php 5
 * 
 * On Windows Task Scheduler:
 *   php C:\xampp\htdocs\SCcrm\leads\generator_cli.php 5
 */

$db_path = __DIR__ . '/../db/scit_crm.db';
if (!file_exists($db_path)) {
    $db_path = __DIR__ . '/../config/database.php';
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/generator_functions.php';

$count = isset($argv[1]) ? intval($argv[1]) : 5;
$market_id = isset($argv[2]) ? intval($argv[2]) : null;
$service_id = isset($argv[3]) ? intval($argv[3]) : null;

$count = min(max($count, 1), 50);

$start = microtime(true);
$result = generateLeads($db, $count, $market_id, $service_id);
$elapsed = round(microtime(true) - $start, 2);

$timestamp = date('Y-m-d H:i:s');
$line = "[$timestamp] {$result['status']}: {$result['message']} (took {$elapsed}s)";
echo $line . "\n";

if ($result['status'] === 'success') {
    exit(0);
} else {
    exit(1);
}
