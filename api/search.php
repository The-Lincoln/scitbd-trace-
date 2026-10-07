<?php
// Search API - returns JSON
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

$q = trim($_GET['q'] ?? '');
if ($q === '') {
    json_response(['success' => true, 'results' => [], 'count' => 0]);
}

// Resolve BASE path (similar to header.php logic)
$dir = dirname($_SERVER['SCRIPT_FILENAME']);
$BASE = '';
for ($i = 0; $i < 4; $i++) {
    if (file_exists($dir . '/index.php') && file_exists($dir . '/config.php')) {
        $BASE = str_replace('\\', '/', str_replace($_SERVER['DOCUMENT_ROOT'], '', $dir));
        break;
    }
    $dir = dirname($dir);
}
$BASE = rtrim($BASE, '/');

$results = search_tools($q, 50);
$out = array_map(function($t) use ($BASE) {
    return [
        'id' => (int)$t['id'],
        'name' => $t['name'],
        'url' => $t['url'],
        'description' => $t['description'],
        'cost_type' => $t['cost_type'],
        'access_type' => $t['access_type'],
        'category' => $t['category_name'],
        'category_slug' => $t['category_slug'],
        'rating_avg' => (float)$t['rating_avg'],
        'rating_count' => (int)$t['rating_count'],
        'favicon' => favicon_url($t),
        'detail_url' => 'tool.php?id=' . $t['id'],
    ];
}, $results);

json_response(['success' => true, 'results' => $out, 'count' => count($out)]);
