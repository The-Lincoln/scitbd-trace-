<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

if (is_logged_in()) {
    $redirect = $_GET['redirect'] ?? 'index.php';
    header('Location: ' . $redirect);
    exit;
}

$PAGE_TITLE = 'Login';
include __DIR__ . '/../includes/header.php';

$error = '';
$redirect = $_GET['redirect'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $identifier = trim($_POST['identifier'] ?? '');
    $password = $_POST['password'] ?? '';

    if (!$identifier || !$password) {
        $error = 'Please enter username/email and password.';
    } else {
        $pdo = db();
        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? OR email = ?");
        $stmt->execute([$identifier, $identifier]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password_hash'])) {
            $error = 'Invalid credentials.';
        } else {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['role'] = $user['role'];
            $redirect = $_POST['redirect'] ?? 'index.php';
            header('Location: ' . $redirect);
            exit;
        }
    }
}
?>

<div class="row justify-content-center">
    <div class="col-md-5 col-lg-4">
        <div class="card">
            <div class="card-header">
                <i class="fas fa-sign-in-alt"></i> Login
            </div>
            <div class="card-body">
                <?php if ($error): ?>
                    <div class="alert alert-danger"><?= h($error) ?></div>
                <?php endif; ?>
                <form method="post" action="">
                    <input type="hidden" name="redirect" value="<?= h($redirect) ?>">
                    <div class="mb-3">
                        <label class="form-label">Username or Email</label>
                        <input type="text" name="identifier" class="form-control" required value="<?= h($_POST['identifier'] ?? '') ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Password</label>
                        <input type="password" name="password" class="form-control" required>
                    </div>
                    <button type="submit" class="btn btn-primary w-100"><i class="fas fa-sign-in-alt"></i> Login</button>
                </form>
                <hr>
                <p class="mb-0 text-center">Don't have an account? <a href="register.php">Register</a></p>
                <p class="text-center mt-2"><small class="text-muted">Admin? <a href="admin/login.php">Admin login</a></small></p>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
