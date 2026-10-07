<?php
// Rating API - requires login
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json');

if (!is_logged_in()) {
    json_response(['success' => false, 'error' => 'Not authenticated'], 401);
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) $input = $_POST;

$tool_id = (int)($input['tool_id'] ?? 0);
$rating = (int)($input['rating'] ?? 0);
$review = trim($input['review'] ?? '');

if (!$tool_id || !$rating || $rating < 1 || $rating > 5) {
    json_response(['success' => false, 'error' => 'Invalid input']);
}

$pdo = db();
$user_id = (int)$_SESSION['user_id'];

// Check tool exists
$check = $pdo->prepare("SELECT id FROM tools WHERE id = ?");
$check->execute([$tool_id]);
if (!$check->fetch()) {
    json_response(['success' => false, 'error' => 'Tool not found'], 404);
}

// Upsert rating (replace existing)
$stmt = $pdo->prepare("SELECT id FROM ratings WHERE user_id = ? AND tool_id = ?");
$stmt->execute([$user_id, $tool_id]);
$existing = $stmt->fetch();

if ($existing) {
    $pdo->prepare("UPDATE ratings SET rating = ?, review = ? WHERE id = ?")
        ->execute([$rating, $review, $existing['id']]);
} else {
    $pdo->prepare("INSERT INTO ratings (user_id, tool_id, rating, review) VALUES (?, ?, ?, ?)")
        ->execute([$user_id, $tool_id, $rating, $review]);
}

recompute_rating($tool_id);

json_response(['success' => true, 'message' => 'Rating saved']);
