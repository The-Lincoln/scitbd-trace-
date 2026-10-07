<?php
// SCITBD CEO — BrowserSkill (Tencent bsk) JSON API.
// GET  ?op=status
// POST {op: status|research|monitor|observe|shot|stop, url?, goal?}
// Companion to ceo/agent_browser_api.php (vercel driver). Creates daily_tasks
// + task_logs for research/monitor when requested.
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$ROOT = dirname(__DIR__);
require_once $ROOT . '/autoflows/app/services/BrowserSkill.php';

try {
    $db = new PDO('sqlite:' . __DIR__ . '/scitbd_ceo.db');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    BrowserSkill::ensureTables($db);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'driver' => 'bsk', 'error' => 'CEO DB unavailable']);
    exit;
}

$in = json_decode(file_get_contents('php://input'), true) ?: $_GET;
$op = strtolower(trim((string)($in['op'] ?? 'status')));
$url = trim((string)($in['url'] ?? ''));
if ($url !== '' && !preg_match('#^https?://#i', $url)) {
    $url = 'https://' . $url;
}
$MOD = 'ceo';

function bskOut($d)
{
    echo json_encode($d);
    exit;
}
function bskTask($db, $title, $desc, $block = 3)
{
    $now = date('Y-m-d H:i:s');
    $st = $db->prepare("INSERT INTO daily_tasks (task_title, task_description, priority, status, category, assignee, due_date, estimated_hours, bst_block_id, created_by, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
    $st->execute([mb_substr($title, 0, 200), $desc, 'high', 'pending', 'operations', 'ai_agent', date('Y-m-d 23:30:00'), 1.0, $block, 'browserskill_agent', $now, $now]);
    $id = (int)$db->lastInsertId();
    $db->prepare("INSERT INTO task_logs (task_id, action, performed_by, details) VALUES (?,?,?,?)")->execute([$id, 'assigned', 'browserskill_agent', mb_substr($desc, 0, 500)]);
    return $id;
}

try {
    if ($op === 'status') {
        bskOut(['ok' => true, 'driver' => 'bsk', 'browser' => BrowserSkill::status('ceo')]);
    }
    if ($op === 'research' || $op === 'monitor' || $op === 'observe') {
        if ($url === '' && $op !== 'observe') {
            bskOut(['ok' => false, 'driver' => 'bsk', 'error' => 'url required']);
        }
        $res = BrowserSkill::research($url !== '' ? $url : 'https://example.com', ['module' => $MOD, 'screenshot' => ($op !== 'observe'), 'no_focus' => true]);
        BrowserSkill::logRun($db, 'bsk:' . $op, $url, !empty($res['ok']), (int)($res['ms'] ?? 0), mb_substr($res['observe'] ?? '', 0, 1000));
        $taskId = null;
        if (!empty($in['create_task']) && !empty($res['ok'])) {
            $taskId = bskTask($db, 'BrowserSkill ' . $op . ': ' . mb_substr($url, 0, 120), mb_substr(($res['observe'] ?? '') . ($res['shot'] ? "\nShot: " . $res['shot'] : ''), 0, 2000), (int)($in['bst_block_id'] ?? 3));
        }
        bskOut(['ok' => !empty($res['ok']), 'driver' => 'bsk', 'op' => $op] + $res + ['task_id' => $taskId]);
    }
    if ($op === 'shot') {
        $res = BrowserSkill::screenshot(null, ['module' => $MOD, 'full_page' => !empty($in['full_page'])]);
        BrowserSkill::logRun($db, 'screenshot', $url, $res['ok'], $res['ms'], (string)($res['path'] ?? ''));
        bskOut(['ok' => $res['ok'], 'driver' => 'bsk', 'result' => $res]);
    }
    if ($op === 'stop') {
        $res = BrowserSkill::sessionStop('', ['module' => $MOD]);
        bskOut(['ok' => $res['ok'], 'driver' => 'bsk', 'result' => $res]);
    }
    bskOut(['ok' => false, 'driver' => 'bsk', 'error' => 'Unknown op. Use status|research|monitor|observe|shot|stop']);
} catch (Throwable $e) {
    bskOut(['ok' => false, 'driver' => 'bsk', 'error' => $e->getMessage()]);
}
