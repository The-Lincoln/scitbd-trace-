<?php
/**
 * Router for PHP's built-in server.
 * Usage:  php -S 127.0.0.1:8020 -t public public/router.php
 */
declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

if ($path !== '/' && is_file(__DIR__ . $path)) {
    return false; // static file — let the built-in server stream it
}

$_GET['r'] = $_GET['r'] ?? '';
require __DIR__ . '/index.php';
