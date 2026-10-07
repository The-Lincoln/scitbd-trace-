<?php
/**
 * OSINT Framework - Configuration
 * Drop into XAMPP/LAMP htdocs, run setup.php once, then visit index.php
 */

// Database path (SQLite)
define('DB_PATH', __DIR__ . '/data/osint.db');

// Site settings
define('SITE_NAME', 'OSINT Framework');
define('SITE_URL', 'http://localhost');

// Admin credentials (simple login mode)
define('ADMIN_USER', 'admin');
define('ADMIN_PASS', 'admin123'); // Change in production!

// Session
define('SESSION_NAME', 'OSINT_SESSION');

// Favicon cache (Google s2 service as fallback)
define('FAVICON_SERVICE', 'https://www.google.com/s2/favicons?domain=');
define('FAVICON_CACHE_HOURS', 168); // 1 week

// Theme defaults
define('DEFAULT_THEME', 'dark'); // 'dark' or 'light'

// Error reporting (set to 0 in production)
error_reporting(E_ALL);
ini_set('display_errors', 1);
date_default_timezone_set('UTC');

// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_name(SESSION_NAME);
    session_start();
}
