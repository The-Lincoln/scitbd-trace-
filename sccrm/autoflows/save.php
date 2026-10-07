<?php
// AutoFlows save endpoint: create / toggle / delete. POST-only, redirects (no output before header).
if (session_status() === PHP_SESSION_NONE) { session_start(); }
$_sccrm_db_boot = require_once __DIR__ . '/../config/database.php';
if (($_sccrm_db_boot instanceof PDO) && (!isset($db) || !($db instanceof PDO))) { $db = $_sccrm_db_boot; }
require_once __DIR__ . '/autoflow_engine.php';
autoflowEnsureTables($db);

$base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'], 2)), '/'); // .../sccrm
$back = $base . '/autoflows/index.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $op = $_POST['op'] ?? 'create';
    try {
        if ($op === 'create') {
            $name = trim($_POST['name'] ?? '');
            if ($name === '') throw new Exception('Flow name is required.');
            $trigger = $_POST['trigger'] ?? 'manual';
            $action = $_POST['action'] ?? 'digest';
            if (!array_key_exists($trigger, autoflowTriggers())) throw new Exception('Invalid trigger.');
            if (!array_key_exists($action, autoflowActions())) throw new Exception('Invalid action.');
            $config = [];
            if ($action === 'create_task') {
                $config['min_score'] = max(0, min(100, intval($_POST['min_score'] ?? 70)));
                $config['due_in_days'] = max(0, min(30, intval($_POST['due_in_days'] ?? 2)));
            }
            if ($action === 'webhook') { $config['url'] = trim($_POST['url'] ?? ''); }
            // agent-browser actions: url + goal (+ actions JSON for browser_act).
            if (in_array($action, ['browser_research', 'browser_monitor', 'browser_screenshot', 'browser_act', 'browser_extract'], true)) {
                $config['url'] = trim($_POST['url'] ?? $_POST['browser_url'] ?? '');
                $config['goal'] = trim($_POST['goal'] ?? $_POST['browser_goal'] ?? '');
                if ($action === 'browser_act') {
                    $rawActs = trim($_POST['browser_actions'] ?? '');
                    $decoded = $rawActs !== '' ? json_decode($rawActs, true) : null;
                    $config['actions'] = is_array($decoded) ? array_slice($decoded, 0, 12) : [];
                }
                if (!empty($_POST['create_task_on_fail'])) {
                    $config['create_task_on_fail'] = 1;
                }
            }
            $db->prepare("INSERT INTO autoflows (name, description, trigger, action, config, is_active) VALUES (?,?,?,?,?,1)")
                ->execute([$name, trim($_POST['description'] ?? ''), $trigger, $action, json_encode($config)]);
            $_SESSION['flash'] = ['type' => 'success', 'message' => 'AutoFlow created!'];
        } elseif ($op === 'toggle') {
            $id = intval($_POST['id'] ?? 0);
            $db->prepare("UPDATE autoflows SET is_active = 1 - is_active, updated_at = datetime('now') WHERE id = ?")->execute([$id]);
            $_SESSION['flash'] = ['type' => 'success', 'message' => 'Flow status updated.'];
        } elseif ($op === 'delete') {
            $id = intval($_POST['id'] ?? 0);
            $db->prepare("DELETE FROM autoflows WHERE id = ?")->execute([$id]);
            $_SESSION['flash'] = ['type' => 'success', 'message' => 'Flow deleted.'];
        }
    } catch (Throwable $e) {
        $_SESSION['flash'] = ['type' => 'danger', 'message' => $e->getMessage()];
    }
}
header('Location: ' . $back);
exit;
