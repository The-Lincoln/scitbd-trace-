<?php
require_once __DIR__ . '/../config.php';
session_unset();
session_destroy();
header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? 'index.php'));
exit;
