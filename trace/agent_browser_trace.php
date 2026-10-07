<?php
/**
 * Trace — agent-browser JSON API + rendered comparison.
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
    require_once __DIR__ . '/AgentBrowserTracer.php';
    require_once __DIR__ . '/../config.php';
    require_once __DIR__ . '/../includes/db.php';
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => 'Bootstrap failed: ' . $e->getMessage()]);
    exit;
}

use OSINT\URLTracer;
use OSINT\AgentBrowserTracer;

$in = json_decode(file_get_contents('php://input'), true) ?: $_GET;
$url = trim((string)($in['url'] ?? ''));
$mode = strtolower(trim((string)($in['mode'] ?? 'hybrid')));
if (!in_array($mode, ['static', 'rendered', 'hybrid'], true)) {
    $mode = 'hybrid';
}
if ($url === '') {
    echo json_encode(['ok' => false, 'error' => 'url is required']);
    exit;
}
if (!preg_match('#^https?://#i', $url)) {
    $url = 'https://' . $url;
}

try {
    $pdo = db();
} catch (Throwable $e) {
    $pdo = null;
}

try {
    if ($mode === 'static') {
        $res = (new URLTracer($pdo))->trace($url);
        echo json_encode(['ok' => empty($res['error']), 'mode' => 'static', 'url' => $url, 'result' => $res]);
        exit;
    }
    $t = new AgentBrowserTracer($pdo);
    $res = $t->traceRendered($url, ['static' => ($mode === 'hybrid'), 'screenshot' => !empty($in['screenshot']) || $mode === 'hybrid']);
    echo json_encode(['ok' => $res['ok'], 'mode' => $res['mode'], 'url' => $url, 'merged' => $res['merged'], 'shot' => $res['shot'], 'ms' => $res['ms'], 'rendered' => $res['rendered'], 'static' => $res['static']]);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage(), 'mode' => $mode, 'url' => $url]);
}
