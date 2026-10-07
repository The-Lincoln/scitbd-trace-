<?php
// CEO Office save endpoint: create / status / assign / delete on the CEO database.
// POST-only with redirect (no output before header); every mutation is logged to task_logs.
if (session_status() === PHP_SESSION_NONE) { session_start(); }
$_sccrm_db_boot = require_once __DIR__ . '/../config/database.php';
if (($_sccrm_db_boot instanceof PDO) && (!isset($db) || !($db instanceof PDO))) { $db = $_sccrm_db_boot; }

$ceoRoot = dirname(__DIR__, 2) . '/autoflows/app';
require_once $ceoRoot . '/services/ScitbdCeo.php';
require_once $ceoRoot . '/models/DailyTask.php';

$base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'], 2)), '/'); // .../sccrm
$back = $base . '/ceo/';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $op = $_POST['op'] ?? '';
    try {
        if ($op === 'create') {
            $id = DailyTask::create([
                'task_title' => $_POST['task_title'] ?? '',
                'task_description' => $_POST['task_description'] ?? '',
                'priority' => $_POST['priority'] ?? 'medium',
                'category' => $_POST['category'] ?? 'operations',
                'assignee' => $_POST['assignee'] ?? 'ceo',
                'bst_block_id' => intval($_POST['bst_block_id'] ?? 3),
                'due_date' => date('Y-m-d 23:30:00'),
                'created_by' => 'sccrm_ceo_office',
            ]);
            $_SESSION['flash'] = ['type' => 'success', 'message' => "CEO task #$id created."];
        } elseif ($op === 'status') {
            $ok = DailyTask::setStatus(intval($_POST['id'] ?? 0), (string)($_POST['status'] ?? ''));
            $_SESSION['flash'] = $ok ? ['type' => 'success', 'message' => 'Task status updated.']
                                     : ['type' => 'danger', 'message' => 'Status update failed.'];
        } elseif ($op === 'assign') {
            $ok = DailyTask::assign(intval($_POST['id'] ?? 0), (string)($_POST['assignee'] ?? 'ceo'));
            $_SESSION['flash'] = $ok ? ['type' => 'success', 'message' => 'Task reassigned.']
                                     : ['type' => 'danger', 'message' => 'Reassign failed.'];
        } elseif ($op === 'delete') {
            $ok = DailyTask::destroy(intval($_POST['id'] ?? 0));
            $_SESSION['flash'] = $ok ? ['type' => 'success', 'message' => 'Task deleted.']
                                      : ['type' => 'danger', 'message' => 'Delete failed.'];
        }
    } catch (Throwable $e) {
        $_SESSION['flash'] = ['type' => 'danger', 'message' => 'CEO error: ' . $e->getMessage()];
    }
}
header('Location: ' . $back);
exit;
