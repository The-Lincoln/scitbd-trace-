<?php
session_start();
require_once __DIR__ . '/../config/database.php';
$id = $_GET['id'] ?? 0;
$stmt = $db->prepare("DELETE FROM tasks WHERE id = ?");
$stmt->execute([$id]);
$_SESSION['flash'] = ['type' => 'success', 'message' => 'Task deleted successfully.'];
header('Location: index.php');
exit;
