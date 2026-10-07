<?php
// Favicon scraper / proxy - fetches and caches favicons locally
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

$tool_id = (int)($_GET['id'] ?? 0);
if (!$tool_id) {
    http_response_code(400);
    echo 'Missing id';
    exit;
}

$pdo = db();
$stmt = $pdo->prepare("SELECT * FROM tools WHERE id = ?");
$stmt->execute([$tool_id]);
$tool = $stmt->fetch();

if (!$tool) {
    http_response_code(404);
    echo 'Tool not found';
    exit;
}

// Check local cache
$cacheDir = __DIR__ . '/../data/favicons';
if (!is_dir($cacheDir)) mkdir($cacheDir, 0755, true);
$cacheFile = $cacheDir . '/tool_' . $tool_id . '.png';
$maxAge = FAVICON_CACHE_HOURS * 3600;

if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < $maxAge) {
    header('Content-Type: image/png');
    header('Cache-Control: public, max-age=86400');
    readfile($cacheFile);
    exit;
}

// Fetch favicon via Google s2 service
$host = parse_url($tool['url'], PHP_URL_HOST);
if (!$host) {
    readfile(__DIR__ . '/../assets/img/default-favicon.svg');
    exit;
}

$fetchUrl = FAVICON_SERVICE . $host;
$ctx = stream_context_create(['http' => [
    'timeout' => 5,
    'user_agent' => 'OSINT-Framework/1.0',
]]);
$data = @file_get_contents($fetchUrl, false, $ctx);

if ($data === false || strlen($data) < 50) {
    // Try direct /favicon.ico
    $altUrl = (parse_url($tool['url'], PHP_URL_SCHEME) ?: 'https') . '://' . $host . '/favicon.ico';
    $data = @file_get_contents($altUrl, false, $ctx);
}

if ($data === false || strlen($data) < 50) {
    readfile(__DIR__ . '/../assets/img/default-favicon.svg');
    exit;
}

// Save to cache
file_put_contents($cacheFile, $data);

// Update tool record with favicon URL
$pdo->prepare("UPDATE tools SET favicon = ? WHERE id = ?")
    ->execute([FAVICON_SERVICE . $host, $tool_id]);

header('Content-Type: image/png');
header('Cache-Control: public, max-age=86400');
echo $data;
