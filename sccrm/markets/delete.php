<?php
session_start();
require_once __DIR__ . '/../config/database.php';
$stmt = $db->prepare("DELETE FROM markets WHERE id = ?");
$stmt->execute([$_GET['id'] ?? 0]);
$_SESSION['flash'] = ['type' => 'success', 'message' => 'Market deleted.'];
header('Location: index.php');
exit;
