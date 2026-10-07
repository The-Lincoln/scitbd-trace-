<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
$_sccrm_db_boot = require_once __DIR__ . '/../config/database.php';
if (($_sccrm_db_boot instanceof PDO) && (!isset($db) || !($db instanceof PDO))) { $db = $_sccrm_db_boot; }
$id = $_GET['id'] ?? 0;
$m = $db->prepare("SELECT * FROM markets WHERE id = ?");
$m->execute([$id]); $m = $m->fetch();
if (!$m) { header('Location: index.php'); exit; }
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $stmt = $db->prepare("UPDATE markets SET name=?, country_code=?, region=?, timezone=?, currency=?, language=?, is_active=? WHERE id=?");
    $stmt->execute([$_POST['name'], $_POST['country_code'], $_POST['region'], $_POST['timezone'], $_POST['currency'], $_POST['language'], $_POST['is_active'] ?? 0, $id]);
    $_SESSION['flash'] = ['type' => 'success', 'message' => 'Market updated!'];
    header('Location: index.php'); exit;
}
require_once __DIR__ . '/../includes/header.php';
?>
<div class="page-title-area">
    <div>
        <h4>Edit Market</h4>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="<?= $SCCRM_BASE ?>/index.php" class="text-decoration-none">Dashboard</a></li>
                <li class="breadcrumb-item"><a href="<?= $SCCRM_BASE ?>/markets/" class="text-decoration-none">Markets</a></li>
                <li class="breadcrumb-item active">Edit</li>
            </ol>
        </nav>
    </div>
</div>
<div class="card-crm">
    <div class="card-body">
        <form method="POST" class="row g-3">
            <div class="col-md-6">
                <label class="form-label">Market Name <span class="text-danger">*</span></label>
                <input type="text" name="name" class="form-control" required value="<?= htmlspecialchars($m['name']) ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label">Country Code</label>
                <input type="text" name="country_code" class="form-control" value="<?= htmlspecialchars($m['country_code'] ?? '') ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label">Region</label>
                <input type="text" name="region" class="form-control" value="<?= htmlspecialchars($m['region'] ?? '') ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label">Timezone</label>
                <input type="text" name="timezone" class="form-control" value="<?= htmlspecialchars($m['timezone'] ?? '') ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label">Currency</label>
                <input type="text" name="currency" class="form-control" value="<?= htmlspecialchars($m['currency'] ?? '') ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label">Language</label>
                <input type="text" name="language" class="form-control" value="<?= htmlspecialchars($m['language'] ?? '') ?>">
            </div>
            <div class="col-md-12">
                <div class="form-check">
                    <input type="checkbox" name="is_active" class="form-check-input" value="1" id="activeMarket" <?= $m['is_active'] ? 'checked' : '' ?>>
                    <label class="form-check-label" for="activeMarket">Active</label>
                </div>
            </div>
            <div class="col-12 d-flex gap-2">
                <button type="submit" class="btn btn-primary rounded-pill px-4"><i class="fas fa-save me-1"></i> Update Market</button>
                <a href="<?= $SCCRM_BASE ?>/markets/" class="btn btn-outline-secondary rounded-pill px-4">Cancel</a>
            </div>
        </form>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
