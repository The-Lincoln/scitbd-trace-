<?php
// Favorite toggle API - requires login
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json');

if (!is_logged_in()) {
    json_response(['success' => false, 'error' => 'Not authenticated'], 401);
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input || empty($input['tool_id'])) {
    $input = $_POST;
}
$tool_id = (int)($input['tool_id'] ?? 0);

if (!$tool_id) {
    json_response(['success' => false, 'error' => 'Missing tool_id']);
}

$pdo = db();
$user_id = (int)$_SESSION['user_id'];

// Check tool exists
$check = $pdo->prepare("SELECT id FROM tools WHERE id = ?");
$check->execute([$tool_id]);
if (!$check->fetch()) {
    json_response(['success' => false, 'error' => 'Tool not found'], 404);
}

// Check existing favorite
$stmt = $pdo->prepare("SELECT id FROM favorites WHERE user_id = ? AND tool_id = ?");
$stmt->execute([$user_id, $tool_id]);
$existing = $stmt->fetch();

if ($existing) {
    $pdo->prepare("DELETE FROM favorites WHERE user_id = ? AND tool_id = ?")->execute([$user_id, $tool_id]);
    json_response(['success' => true, 'favorited' => false, 'message' => 'Removed from favorites']);
} else {
    $pdo->prepare("INSERT INTO favorites (user_id, tool_id) VALUES (?, ?)")->execute([$user_id, $tool_id]);
    json_response(['success' => true, 'favorited' => true, 'message' => 'Added to favorites']);
}
