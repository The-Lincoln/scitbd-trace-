<?php
/**
 * Database connection (PDO + SQLite)
 */
require_once __DIR__ . '/../config.php';

function db() {
    static $pdo = null;
    if ($pdo === null) {
        if (!file_exists(DB_PATH)) {
            // Auto-initialize if missing
            require_once __DIR__ . '/../setup.php';
            run_setup();
        }
        $pdo = new PDO('sqlite:' . DB_PATH);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA foreign_keys = ON');
    }
    return $pdo;
}
