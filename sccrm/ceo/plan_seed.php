<?php
// Installs today's 24h BST operational plan into CEO daily_tasks (idempotent).
// POST-only with redirect; DailyTask::create logs every insert to task_logs.
if (session_status() === PHP_SESSION_NONE) { session_start(); }
$_sccrm_db_boot = require_once __DIR__ . '/../config/database.php';
if (($_sccrm_db_boot instanceof PDO) && (!isset($db) || !($db instanceof PDO))) { $db = $_sccrm_db_boot; }

$ceoRoot = dirname(__DIR__, 2) . '/autoflows/app';
require_once $ceoRoot . '/services/ScitbdCeo.php';
require_once $ceoRoot . '/models/DailyTask.php';
require_once __DIR__ . '/operational_plan.php';

$base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'], 2)), '/');
$back = $base . '/ceo/';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $due = (new DateTime('now', new DateTimeZone('Asia/Dhaka')))->format('Y-m-d') . ' 23:30:00';
        $created = 0; $skipped = 0;
        foreach (ceoOperationalPlan() as $slot) {
            $title = ceoPlanTaskTitle($slot);
            $exists = ScitbdCeo::db()->prepare("SELECT COUNT(*) FROM daily_tasks WHERE task_title = ? AND DATE(created_at) = DATE('now','+6 hours')");
            // Idempotency: same slot title already queued in the last 24h (BST day edge safe).
            $exists->execute([$title]);
            if ((int)$exists->fetchColumn() > 0) { $skipped++; continue; }
            DailyTask::create([
                'task_title' => $title,
                'task_description' => $slot['detail'] . "\nRegion: " . $slot['region'] . "\nExpected: " . $slot['outcome'],
                'priority' => $slot['priority'],
                'category' => $slot['category'],
                'assignee' => 'ceo',
                'bst_block_id' => $slot['block'],
                'due_date' => $due,
                'estimated_hours' => 1.5,
                'created_by' => 'ops_plan',
            ]);
            $created++;
        }
        $_SESSION['flash'] = ['type' => 'success', 'message' => "Operational plan installed: $created queued, $skipped already present."];
    } catch (Throwable $e) {
        $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Plan install failed: ' . $e->getMessage()];
    }
}
header('Location: ' . $back);
exit;
