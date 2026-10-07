<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_admin();

$PAGE_TITLE = 'Database Utilities';
include __DIR__ . '/../includes/header.php';

$msg = '';
$msg_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $pdo = db();

    if ($action === 'reset_favicons') {
        $cacheDir = __DIR__ . '/../data/favicons';
        $count = 0;
        if (is_dir($cacheDir)) {
            foreach (glob($cacheDir . '/*') as $f) {
                if (is_file($f)) { unlink($f); $count++; }
            }
        }
        // Also reset the favicon field on tools so they re-fetch
        $pdo->exec("UPDATE tools SET favicon = ''");
        $msg = "Cleared $count cached favicons. They will be re-fetched on next view.";
        $msg_type = 'success';

    } elseif ($action === 'recompute_ratings') {
        $tools = $pdo->query("SELECT id FROM tools")->fetchAll();
        foreach ($tools as $t) recompute_rating($t['id']);
        $msg = "Recomputed ratings for " . count($tools) . " tools.";
        $msg_type = 'success';

    } elseif ($action === 'reset_db') {
        // Confirm with double checkbox
        if (empty($_POST['confirm_reset'])) {
            $msg = "You must check the confirmation box to reset the database.";
            $msg_type = 'danger';
        } else {
            // Close current connection, delete file, re-run setup
            $pdo = null;
            if (file_exists(DB_PATH)) {
                unlink(DB_PATH);
            }
            // Reload setup
            require_once __DIR__ . '/../setup.php';
            run_setup();
            $msg = "Database reset. All categories and tools re-seeded. User accounts wiped.";
            $msg_type = 'success';
        }

    } elseif ($action === 'export_json') {
        // Export all categories + tools as JSON
        $cats = $pdo->query("SELECT * FROM categories ORDER BY sort_order, name")->fetchAll();
        $tools = $pdo->query("SELECT * FROM tools ORDER BY name")->fetchAll();
        $users = $pdo->query("SELECT id, username, email, role, created_at FROM users")->fetchAll();
        $export = [
            'exported_at' => date('c'),
            'categories' => $cats,
            'tools' => $tools,
            'users' => $users,
        ];
        $json = json_encode($export, JSON_PRETTY_PRINT);
        $filename = 'osint-export-' . date('Y-m-d-His') . '.json';
        header('Content-Type: application/json');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        echo $json;
        exit;
    }
}

// Stats for display
$pdo = db();
$stats = [
    'categories' => $pdo->query("SELECT COUNT(*) FROM categories")->fetchColumn(),
    'tools' => $pdo->query("SELECT COUNT(*) FROM tools")->fetchColumn(),
    'users' => $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn(),
    'ratings' => $pdo->query("SELECT COUNT(*) FROM ratings")->fetchColumn(),
    'favorites' => $pdo->query("SELECT COUNT(*) FROM favorites")->fetchColumn(),
    'cached_favicons' => 0,
];
$cacheDir = __DIR__ . '/../data/favicons';
if (is_dir($cacheDir)) {
    $stats['cached_favicons'] = count(glob($cacheDir . '/*'));
}
$db_size = file_exists(DB_PATH) ? filesize(DB_PATH) : 0;
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h2><i class="fas fa-database"></i> Database Utilities</h2>
    <a href="<?= $BASE ?>/admin/dashboard.php" class="btn btn-sm btn-outline-secondary"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>
</div>

<?php if ($msg): ?>
    <div class="alert alert-<?= h($msg_type) ?>">
        <i class="fas fa-<?= $msg_type === 'success' ? 'check-circle' : 'exclamation-triangle' ?>"></i>
        <?= h($msg) ?>
    </div>
<?php endif; ?>

<div class="row g-3">
    <div class="col-md-6">
        <div class="card">
            <div class="card-header"><i class="fas fa-info-circle"></i> Current State</div>
            <div class="card-body">
                <table class="table table-sm mb-0">
                    <?php foreach ($stats as $name => $value): ?>
                        <tr>
                            <td class="text-capitalize"><?= h(str_replace('_', ' ', $name)) ?></td>
                            <td class="text-end"><strong><?= number_format((int)$value) ?></strong></td>
                        </tr>
                    <?php endforeach; ?>
                    <tr>
                        <td>Database file size</td>
                        <td class="text-end"><strong><?= number_format($db_size / 1024, 1) ?> KB</strong></td>
                    </tr>
                    <tr>
                        <td>DB path</td>
                        <td class="text-end"><code><?= h(DB_PATH) ?></code></td>
                    </tr>
                </table>
            </div>
        </div>
    </div>

    <div class="col-md-6">
        <div class="card">
            <div class="card-header"><i class="fas fa-tools"></i> Maintenance Actions</div>
            <div class="card-body">
                <form method="post" class="d-grid gap-2">
                    <input type="hidden" name="action" value="reset_favicons">
                    <button type="submit" class="btn btn-outline-warning text-start">
                        <i class="fas fa-eraser"></i> Clear Favicon Cache
                        <small class="d-block text-muted">Force re-fetch of all favicons</small>
                    </button>
                </form>
                <form method="post" class="d-grid gap-2 mt-2">
                    <input type="hidden" name="action" value="recompute_ratings">
                    <button type="submit" class="btn btn-outline-primary text-start">
                        <i class="fas fa-sync"></i> Recompute All Ratings
                        <small class="d-block text-muted">Recompute avg + count for every tool</small>
                    </button>
                </form>
                <form method="post" class="d-grid gap-2 mt-2">
                    <input type="hidden" name="action" value="export_json">
                    <button type="submit" class="btn btn-outline-success text-start">
                        <i class="fas fa-file-export"></i> Export Database as JSON
                        <small class="d-block text-muted">Download all data as a JSON backup</small>
                    </button>
                </form>
            </div>
        </div>

        <div class="card mt-3 border-danger">
            <div class="card-header bg-danger text-white"><i class="fas fa-exclamation-triangle"></i> Danger Zone</div>
            <div class="card-body">
                <form method="post" onsubmit="return confirm('This will DELETE all data and re-seed. Continue?')">
                    <input type="hidden" name="action" value="reset_db">
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="checkbox" name="confirm_reset" id="confirmReset" value="1" required>
                        <label class="form-check-label" for="confirmReset">
                            I understand this will <strong>delete all users, ratings, favorites, and custom tools</strong>, then re-seed defaults.
                        </label>
                    </div>
                    <button type="submit" class="btn btn-danger w-100">
                        <i class="fas fa-trash-restore"></i> Reset Database to Defaults
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
