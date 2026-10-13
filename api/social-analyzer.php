<?php
// Social Analyzer API - username → social profiles (JSON)
// GET  /api/social-analyzer.php?username=johndoe&top=100&mode=fast
// POST /api/social-analyzer.php {"username":"johndoe,janedoe","metadata":true,"top":100}
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../autoflows/app/services/SocialAnalyzer.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$in = [];
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $raw = file_get_contents('php://input');
    $j = json_decode((string)$raw, true);
    if (is_array($j)) {
        $in = $j;
    }
    foreach (['username', 'mode', 'websites', 'top', 'type', 'countries', 'filter', 'method', 'profiles', 'metadata', 'extract'] as $k) {
        if (isset($_POST[$k]) && !isset($in[$k])) {
            $in[$k] = $_POST[$k];
        }
    }
} else {
    $in = $_GET;
}

$username = trim((string)($in['username'] ?? ''));
if ($username === '') {
    // also support ?q= alias + status probe
    $username = trim((string)($in['q'] ?? ''));
}
if (isset($in['status']) || ($username === '' && ($_GET['status'] ?? '') !== '')) {
    json_response(['success' => true, 'status' => SocialAnalyzer::status()]);
}
if ($username === '') {
    json_response(['success' => false, 'error' => 'username required, e.g. ?username=johndoe'], 400);
}

$opts = [
    'mode' => (string)($in['mode'] ?? 'fast'),
    'websites' => (string)($in['websites'] ?? ''),
    'top' => isset($in['top']) ? (int)$in['top'] : 0,
    'type' => (string)($in['type'] ?? ''),
    'countries' => (string)($in['countries'] ?? ''),
    'filter' => (string)($in['filter'] ?? ''),
    'method' => (string)($in['method'] ?? ''),
    'profiles' => (string)($in['profiles'] ?? ''),
    'metadata' => !empty($in['metadata']),
    'extract' => !empty($in['extract']),
];
$opts = array_filter($opts, fn($v) => $v !== '' && $v !== 0 && $v !== false || $v === true);

$r = SocialAnalyzer::analyze($username, $opts);

// audit log (best effort)
try {
    $pdo = db();
    SocialAnalyzer::ensureTables($pdo);
    SocialAnalyzer::logRun($pdo, 'api', 'analyze-' . ($opts['mode'] ?? 'fast'), mb_substr($username, 0, 200), !empty($r['ok']), (int)($r['ms'] ?? 0), mb_substr(json_encode($r['summary'] ?? []), 0, 500));
} catch (Throwable $e) {
}

if (empty($r['ok'])) {
    json_response(['success' => false, 'username' => $username, 'error' => $r['error'] ?? 'analysis failed', 'ms' => $r['ms'] ?? 0], 502);
}
json_response(['success' => true, 'username' => $r['username'], 'summary' => $r['summary'], 'profiles' => $r['profiles'], 'ms' => $r['ms']]);
