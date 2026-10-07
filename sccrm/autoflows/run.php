<?php
// AutoFlows manual-run endpoint: runs one flow immediately, then redirects back.
if (session_status() === PHP_SESSION_NONE) { session_start(); }
$_sccrm_db_boot = require_once __DIR__ . '/../config/database.php';
if (($_sccrm_db_boot instanceof PDO) && (!isset($db) || !($db instanceof PDO))) { $db = $_sccrm_db_boot; }
require_once __DIR__ . '/autoflow_engine.php';

$base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'], 2)), '/');
$back = $base . '/autoflows/index.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = intval($_POST['id'] ?? 0);
    $stmt = $db->prepare("SELECT * FROM autoflows WHERE id = ?");
    $stmt->execute([$id]);
    $flow = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$flow) {
        $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Flow not found.'];
    } else {
        $res = autoflowRun($db, $flow, 'manual', ['by' => 'dashboard']);
        $_SESSION['flash'] = ['type' => $res['status'] === 'success' ? 'success' : 'danger',
            'message' => 'Ran "' . $flow['name'] . '": ' . $res['message']];
    }
}
header('Location: ' . $back);
exit;
