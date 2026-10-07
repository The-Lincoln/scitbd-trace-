<?php
/**
 * Trace — BrowserSkill (Tencent bsk) JSON API + rendered comparison.
 * Companion to trace/agent_browser_trace.php (vercel driver).
 *
 * POST JSON {url, mode: static|rendered|hybrid, screenshot?:bool}
 * GET  ?url=…&mode=hybrid  (quick check)
 * Always JSON. Never breaks the static tracer.
 */
header('Content-Type: application/json; charset=utf-8');
@set_time_limit(180);
@ini_set('max_execution_time', '180');

try {
    require_once __DIR__ . '/../vendor/autoload.php';
    require_once __DIR__ . '/url_tracer.php';
    require_once __DIR__ . '/BrowserSkillTracer.php';
    require_once __DIR__ . '/../config.php';
    require_once __DIR__ . '/../autoflows/app/services/BrowserSkill.php';

    $in = [];
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $raw = file_get_contents('php://input');
        $j = json_decode($raw ?: '', true);
        $in = is_array($j) ? $j : $_POST;
    } else {
        $in = $_GET;
    }
    $url = trim((string)($in['url'] ?? ''));
    $mode = strtolower(trim((string)($in['mode'] ?? 'hybrid')));
    $shot = array_key_exists('screenshot', $in) ? (bool)$in['screenshot'] : true;

    if ($url === '') {
        echo json_encode(['ok' => false, 'driver' => 'bsk', 'error' => 'url required', 'usage' => 'POST {url, mode: static|rendered|hybrid}']);
        exit;
    }

    $pdo = null;
    try {
        $pdo = db();
    } catch (Throwable $e) {
        $pdo = null;
    }

    if ($mode === 'static') {
        $res = (new OSINT\URLTracer($pdo))->trace($url);
        echo json_encode(['ok' => empty($res['error']), 'driver' => 'bsk-static', 'mode' => 'static', 'result' => $res]);
        exit;
    }
    $t = new OSINT\BrowserSkillTracer($pdo, 'trace');
    $res = $t->traceRendered($url, ['static' => ($mode !== 'rendered'), 'screenshot' => $shot]);
    echo json_encode(['ok' => !empty($res['ok']), 'driver' => 'bsk'] + $res);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'driver' => 'bsk', 'error' => $e->getMessage()]);
}
