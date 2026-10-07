<?php
/**
 * AutoFlows — AgencyOS approval logger bridge (log_approval.php).
 *
 * Implements the AgencyOS `api.php?action=add_activity` POST contract against
 * the CEO database (SCITBD_DB_PATH or the scitbd_ceo.db copy): secret-guarded
 * insert into `daily_activities`, returns {status, message, activity_id}.
 * Called by the Slack/Discord bots after email-verified approval.
 * The table is created idempotently on first hit (same pattern as Database::boot).
 *
 * Usage (local):
 *   php -S 127.0.0.1:8020 -t public public/router.php  # then POST to /tools/log_approval.php?
 *   No — run standalone: php -S 127.0.0.1:8099 tools/log_approval.php
 *   curl -X POST http://127.0.0.1:8099/log_approval.php -d "secret=...&approver=md.s.lincoln@gmail.com"
 *
 * POST/GET params: secret (required), approver, title, status, outcome.
 * Env: SCITBD_DB_PATH, AGENCYOS_CRON_SECRET (compiled-in fallback is a rotated random value;
 * prefer setting the env var; never commit real secrets).
 */
declare(strict_types=1);

header('Content-Type: application/json');

$fail = static function (string $msg, int $code = 200): never {
    if ($code !== 200) {
        http_response_code($code);
    }
    echo json_encode(['status' => 'error', 'message' => $msg]);
    exit;
};

// Security API Key Validation (fail closed, timing-safe compare)
$allowedKey = (string) (getenv('AGENCYOS_CRON_SECRET') ?: '6f54c683b5e04d99478171e6e8e5e16bfde05ed82f9eac7d87c88ad642e9ef96');
$providedKey = (string) (($_POST['secret'] ?? $_GET['secret'] ?? ''));
if ($providedKey === '' || !hash_equals($allowedKey, $providedKey)) {
    $fail('Unauthorized access.', 403);
}

// Database Connection (CEO DB reconciled path)
$dbPath = (string) (getenv('SCITBD_DB_PATH') ?: 'D:/CRM_with_GOOGLE_Sheet/ceo/ceo - Copy/scitbd_ceo.db');
try {
    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    $fail('Database connection failed: ' . $e->getMessage());
}

// AgencyOS daily_activities Table Schema (idempotent)
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS daily_activities (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        title VARCHAR(255) NOT NULL,
        type VARCHAR(50) DEFAULT 'General',
        agent VARCHAR(100) DEFAULT 'AI CEO',
        description TEXT,
        duration INTEGER DEFAULT 0,
        status VARCHAR(50) DEFAULT 'Completed',
        activity_date DATE DEFAULT (DATE('now')),
        outcome TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )"
);

// Receive Data from Slack Webhook / Node.js Engine
$approver = trim((string) ($_POST['approver'] ?? $_GET['approver'] ?? 'md.s.lincoln@gmail.com'));
$title = trim((string) ($_POST['title'] ?? $_GET['title'] ?? 'Slack Daily Task Approval'));
$status = trim((string) ($_POST['status'] ?? $_GET['status'] ?? 'Completed'));
$outcome = trim((string) ($_POST['outcome'] ?? $_GET['outcome'] ?? 'Approved daily tasks schedule via Slack Block Kit UI'));
$type = 'Slack Approval';
$agent = 'AI CEO';
$duration = 5; // Default logged duration in minutes

if ($approver === '' || $title === '') {
    $fail('approver and title are required.');
}

// Insert into daily_activities table
try {
    $stmt = $pdo->prepare(
        'INSERT INTO daily_activities (title, type, agent, description, duration, status, activity_date, outcome)
         VALUES (:title, :type, :agent, :description, :duration, :status, DATE(\'now\'), :outcome)'
    );
    $stmt->execute([
        ':title' => mb_substr($title, 0, 255),
        ':type' => $type,
        ':agent' => $agent,
        ':description' => 'Approved by ' . $approver . ' via Slack interactive button.',
        ':duration' => $duration,
        ':status' => $status,
        ':outcome' => $outcome,
    ]);

    echo json_encode([
        'status' => 'success',
        'message' => 'Slack approval logged in daily_activities',
        'activity_id' => (int) $pdo->lastInsertId(),
    ]);
} catch (PDOException $e) {
    $fail('Failed to log activity: ' . $e->getMessage());
}
