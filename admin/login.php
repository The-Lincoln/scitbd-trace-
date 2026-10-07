<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

// Already logged in as admin? go to dashboard
if (is_admin()) {
    header('Location: ' . $BASE . '/admin/dashboard.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $u = trim($_POST['username'] ?? '');
    $p = $_POST['password'] ?? '';

    if ($u === ADMIN_USER && $p === ADMIN_PASS) {
        // Find or create admin user
        $pdo = db();
        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? OR role = 'admin' LIMIT 1");
        $stmt->execute([$u]);
        $admin = $stmt->fetch();
        if (!$admin) {
            $pdo->prepare("INSERT INTO users (username, email, password_hash, role) VALUES (?, ?, ?, 'admin')")
                ->execute([ADMIN_USER, 'admin@local', password_hash(ADMIN_PASS, PASSWORD_DEFAULT)]);
            $admin_id = $pdo->lastInsertId();
        } else {
            $admin_id = $admin['id'];
        }
        $_SESSION['user_id'] = $admin_id;
        $_SESSION['username'] = $u;
        $_SESSION['role'] = 'admin';
        header('Location: ' . $BASE . '/admin/dashboard.php');
        exit;
    } else {
        $error = 'Invalid admin credentials.';
    }
}

$PAGE_TITLE = 'Admin Login';
include __DIR__ . '/../includes/header.php';
?>

<div class="row justify-content-center mt-5">
    <div class="col-md-4">
        <div class="card">
            <div class="card-header bg-dark text-white">
                <i class="fas fa-shield-alt"></i> Admin Access
            </div>
            <div class="card-body">
                <?php if ($error): ?>
                    <div class="alert alert-danger"><?= h($error) ?></div>
                <?php endif; ?>
                <form method="post" action="">
                    <div class="mb-3">
                        <label class="form-label">Username</label>
                        <input type="text" name="username" class="form-control" required autofocus>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Password</label>
                        <input type="password" name="password" class="form-control" required>
                    </div>
                    <button type="submit" class="btn btn-primary w-100"><i class="fas fa-lock-open"></i> Login as Admin</button>
                </form>
                <hr>
                <p class="text-muted small mb-0">
                    <i class="fas fa-info-circle"></i>
                    Default credentials: <code>admin / admin123</code><br>
                    <strong>Change in <code>config.php</code> before deployment!</strong>
                </p>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
