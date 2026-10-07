<?php
// SCITBD CEO — agent-browser JSON API.
// GET  ?op=status
// POST {op: open|snapshot|read|research|monitor|act|shot|close, url?, goal?, sel?, text?, do?}
// Creates daily_tasks + task_logs for research/monitor when requested.
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$ROOT = dirname(__DIR__);
require_once __DIR__ . '/../autoflows/app/services/AgentBrowser.php';
if (is_file(__DIR__ . '/../autoflows/app/services/BrowserAgent.php')) {
    require_once __DIR__ . '/../autoflows/app/services/BrowserAgent.php';
}

try {
    $db = new PDO('sqlite:' . __DIR__ . '/scitbd_ceo.db');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    AgentBrowser::ensureTables($db);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => 'CEO DB unavailable']);
    exit;
}

$in = json_decode(file_get_contents('php://input'), true) ?: $_GET;
$op = strtolower(trim((string)($in['op'] ?? 'status')));
$url = trim((string)($in['url'] ?? ''));
if ($url !== '' && !preg_match('#^https?://#i', $url)) {
    $url = 'https://' . $url;
}
$MOD = 'ceo';

function ceoOut($d)
{
    echo json_encode($d);
    exit;
}
function ceoLogRun($db, $cmd, $url, $ok, $ms, $ex = '')
{
    AgentBrowser::logRun($db, 'ceo', $cmd, $url, (bool)$ok, (int)$ms, (string)$ex);
}
function ceoTask($db, $title, $desc, $block = 3)
{
    $now = date('Y-m-d H:i:s');
    $st = $db->prepare("INSERT INTO daily_tasks (task_title, task_description, priority, status, category, assignee, due_date, estimated_hours, bst_block_id, created_by, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
    $st->execute([mb_substr($title, 0, 200), $desc, 'high', 'pending', 'operations', 'ai_agent', date('Y-m-d 23:30:00'), 1.0, $block, 'browser_agent', $now, $now]);
    $id = (int)$db->lastInsertId();
    $db->prepare("INSERT INTO task_logs (task_id, action, performed_by, details) VALUES (?,?,?,?)")->execute([$id, 'assigned', 'browser_agent', mb_substr($desc, 0, 500)]);
    return $id;
}

try {
    if ($op === 'status') {
        ceoOut(['ok' => true, 'browser' => AgentBrowser::status('ceo')]);
    }
    if ($op === 'open') {
        if ($url === '') {
            ceoOut(['ok' => false, 'error' => 'url required']);
        }
        $r = AgentBrowser::open($url, ['module' => $MOD, 'timeout' => 75]);
        ceoLogRun($db, 'open', $url, $r['ok'], $r['ms'], mb_substr($r['text'], 0, 800));
        ceoOut(['ok' => $r['ok'], 'result' => $r]);
    }
    if ($op === 'snapshot') {
        $r = AgentBrowser::snapshot(['module' => $MOD]);
        ceoOut(['ok' => $r['ok'], 'result' => $r]);
    }
    if ($op === 'read') {
        $r = AgentBrowser::read($url !== '' ? $url : null, ['module' => $MOD]);
        ceoOut(['ok' => $r['ok'], 'result' => $r]);
    }
    if ($op === 'shot') {
        if ($url !== '') {
            AgentBrowser::open($url, ['module' => $MOD]);
        }
        $r = AgentBrowser::screenshot(null, ['module' => $MOD]);
        ceoLogRun($db, 'screenshot', $url, $r['ok'], $r['ms'], (string)($r['path'] ?? ''));
        ceoOut(['ok' => $r['ok'], 'result' => $r]);
    }
    if ($op === 'research' || $op === 'monitor' || $op === 'extract') {
        if ($url === '') {
            ceoOut(['ok' => false, 'error' => 'url required']);
        }
        $plan = $op === 'monitor' ? 'monitor' : ($op === 'extract' ? 'extract' : 'research');
        $res = class_exists('BrowserAgent')
            ? BrowserAgent::run(['plan' => $plan, 'url' => $url, 'goal' => (string)($in['goal'] ?? 'CEO intel for briefing/pipeline.'), 'module' => $MOD])
            : ['ok' => false, 'report' => 'BrowserAgent unavailable', 'steps' => [], 'shot' => null, 'ms' => 0];
        ceoLogRun($db, 'browser:' . $plan, $url, !empty($res['ok']), (int)($res['ms'] ?? 0), mb_substr($res['report'] ?? '', 0, 1000));
        $taskId = null;
        if (!empty($in['create_task'])) {
            $taskId = ceoTask($db, 'Browser ' . $plan . ': ' . mb_substr($url, 0, 120), mb_substr(($res['report'] ?? '') . ($res['shot'] ? "\nShot: " . $res['shot'] : ''), 0, 2000), (int)($in['bst_block_id'] ?? 3));
        }
        ceoOut(['ok' => !empty($res['ok']), 'plan' => $plan, 'report' => $res['report'] ?? '', 'shot' => $res['shot'] ?? null, 'steps' => $res['steps'] ?? [], 'ms' => $res['ms'] ?? 0, 'task_id' => $taskId]);
    }
    if ($op === 'act') {
        if ($url === '') {
            ceoOut(['ok' => false, 'error' => 'url required']);
        }
        AgentBrowser::open($url, ['module' => $MOD]);
        $action = $in['action'] ?? ['do' => ($in['do'] ?? 'click'), 'sel' => ($in['sel'] ?? ''), 'text' => ($in['text'] ?? ''), 'key' => ($in['text'] ?? ''), 'target' => ($in['sel'] ?? ''), 'what' => 'text'];
        $r = class_exists('BrowserAgent')
            ? BrowserAgent::runAction((array)$action, ['module' => $MOD, 'session' => AgentBrowser::sessionFor($MOD)])
            : AgentBrowser::click((string)($action['sel'] ?? ''), ['module' => $MOD]);
        ceoLogRun($db, 'browser:act', $url, $r['ok'], $r['ms'], mb_substr($r['text'] ?? '', 0, 800));
        ceoOut(['ok' => $r['ok'], 'result' => $r]);
    }
    if ($op === 'close') {
        $r = AgentBrowser::close(false, ['module' => $MOD]);
        ceoOut(['ok' => $r['ok'], 'result' => $r]);
    }
    ceoOut(['ok' => false, 'error' => 'Unknown op. Use status|open|snapshot|read|research|monitor|extract|act|shot|close']);
} catch (Throwable $e) {
    ceoOut(['ok' => false, 'error' => $e->getMessage()]);
}
