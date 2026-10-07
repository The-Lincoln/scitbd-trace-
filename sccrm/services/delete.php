<?php
session_start();
require_once __DIR__ . '/../config/database.php';
$stmt = $db->prepare("DELETE FROM services WHERE id = ?");
$stmt->execute([$_GET['id'] ?? 0]);
$_SESSION['flash'] = ['type' => 'success', 'message' => 'Service deleted.'];
header('Location: index.php');
exit;
