<?php
// Tools list API - filter by category
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

$category_slug = $_GET['cat'] ?? '';
$cost = $_GET['cost'] ?? '';
$access = $_GET['access'] ?? '';

if (!$category_slug) {
    json_response(['success' => false, 'error' => 'Missing cat param'], 400);
}

$cat = get_category($category_slug);
if (!$cat) {
    json_response(['success' => false, 'error' => 'Category not found'], 404);
}

$pdo = db();

// Gather all category IDs (recursive)
$ids = [(int)$cat['id']];
$stack = [(int)$cat['id']];
while ($stack) {
    $cur = array_shift($stack);
    $stmt = $pdo->prepare("SELECT id FROM categories WHERE parent_id = ?");
    $stmt->execute([$cur]);
    foreach ($stmt->fetchAll() as $row) {
        $ids[] = (int)$row['id'];
        $stack[] = (int)$row['id'];
    }
}

$where = "category_id IN (" . implode(',', array_fill(0, count($ids), '?')) . ")";
$params = $ids;
if ($cost) { $where .= " AND cost_type = ?"; $params[] = $cost; }
if ($access) { $where .= " AND access_type = ?"; $params[] = $access; }

$stmt = $pdo->prepare("SELECT * FROM tools WHERE $where ORDER BY name");
$stmt->execute($params);

json_response(['success' => true, 'tools' => $stmt->fetchAll()]);
