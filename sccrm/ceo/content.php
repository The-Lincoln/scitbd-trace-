<?php
// CEO Content Studio endpoint: TinyLLM draft via ContentAgent -> Knowledge Bank draft.
// POST-only with redirect; flash links straight to the draft for review.
if (session_status() === PHP_SESSION_NONE) { session_start(); }
$_sccrm_db_boot = require_once __DIR__ . '/../config/database.php';
if (($_sccrm_db_boot instanceof PDO) && (!isset($db) || !($db instanceof PDO))) { $db = $_sccrm_db_boot; }
require_once __DIR__ . '/../ai/content_agent.php';

$base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'], 2)), '/');
$back = $base . '/ceo/';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $kind = strtolower(trim($_POST['kind'] ?? 'blog'));
    $topic = trim($_POST['topic'] ?? '');
    $serviceId = intval($_POST['service_id'] ?? 0);
    if ($topic === '') {
        $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Topic is required.'];
    } else {
        $res = contentAgentRun($db, $kind, $topic, ['service_id' => $serviceId]);
        if (empty($res['ok'])) {
            $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Draft failed: ' . ($res['error'] ?? '?')];
        } else {
            $via = $res['fallback'] ? 'template fallback' : $res['model'];
            $edit = $base . '/knowledge/edit.php?id=' . (int)$res['article_id'];
            $_SESSION['flash'] = ['type' => 'success',
                'message' => 'Draft #' . (int)$res['article_id'] . ' "' . htmlspecialchars($res['title']) . '" ready via ' . htmlspecialchars($via) . '. <a href="' . $edit . '" class="alert-link">Review & publish →</a>'];
        }
    }
}
header('Location: ' . $back);
exit;
