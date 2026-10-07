<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
$_sccrm_db_boot = require_once __DIR__ . '/../config/database.php';
if (($_sccrm_db_boot instanceof PDO) && (!isset($db) || !($db instanceof PDO))) { $db = $_sccrm_db_boot; }
require_once __DIR__ . '/../includes/upload_helper.php';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $image = sccrm_upload_media($_FILES['image'] ?? [], 'image', null);
    $gif   = sccrm_upload_media($_FILES['gif'] ?? [], 'gif', null);
    $video = sccrm_upload_media($_FILES['video'] ?? [], 'video', null);
    $stmt = $db->prepare("INSERT INTO services (name, category, description, icon, is_active, price_silver, price_platinum, currency, image, gif, video) VALUES (?,?,?,?,?,?,?,?,?,?,?)");
    $stmt->execute([
        $_POST['name'], $_POST['category'], $_POST['description'], $_POST['icon'], $_POST['is_active'] ?? 1,
        ($_POST['price_silver'] ?? '') !== '' ? (float)$_POST['price_silver'] : null,
        ($_POST['price_platinum'] ?? '') !== '' ? (float)$_POST['price_platinum'] : null,
        $_POST['currency'] ?? 'BDT', $image, $gif, $video
    ]);
    $_SESSION['flash'] = ['type' => 'success', 'message' => 'Service added!'];
    header('Location: index.php'); exit;
}
$icons = ['fa-sitemap','fa-globe','fa-code','fa-mobile-alt','fa-graduation-cap','fa-robot','fa-shield-alt','fa-credit-card','fa-bullhorn','fa-university','fa-cloud','fa-app-store-ios','fa-cogs','fa-lock','fa-clipboard-check','fa-brain','fa-user-astronaut','fa-chart-line','fa-database','fa-server','fa-network-wired','fa-cog'];
require_once __DIR__ . '/../includes/header.php';
?>
<div class="page-title-area">
    <div>
        <h4>Add Service</h4>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="<?= $SCCRM_BASE ?>/index.php" class="text-decoration-none">Dashboard</a></li>
                <li class="breadcrumb-item"><a href="<?= $SCCRM_BASE ?>/services/" class="text-decoration-none">Services</a></li>
                <li class="breadcrumb-item active">Add Service</li>
            </ol>
        </nav>
    </div>
</div>
<div class="card-crm">
    <div class="card-body">
        <form method="POST" enctype="multipart/form-data" class="row g-3">
            <div class="col-md-8">
                <label class="form-label">Service Name <span class="text-danger">*</span></label>
                <input type="text" name="name" class="form-control" required>
            </div>
            <div class="col-md-2">
                <label class="form-label">Category</label>
                <select name="category" class="form-select">
                    <?php foreach (['Consulting','Development','AI','Security','Marketing','E-Commerce','Enterprise','Education','Audit'] as $cat): ?>
                    <option value="<?= $cat ?>"><?= $cat ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Icon</label>
                <select name="icon" class="form-select">
                    <?php foreach ($icons as $ic): ?>
                    <option value="<?= $ic ?>"><i class="fas <?= $ic ?>"></i> <?= $ic ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12">
                <label class="form-label">Description</label>
                <textarea name="description" class="form-control" rows="2"></textarea>
            </div>
            <div class="col-md-6">
                <label class="form-label">Silver Client Price (BDT)</label>
                <input type="number" step="0.01" min="0" name="price_silver" class="form-control" value="10000">
            </div>
            <div class="col-md-6">
                <label class="form-label">Platinum Client Price (BDT)</label>
                <input type="number" step="0.01" min="0" name="price_platinum" class="form-control" value="20000">
            </div>
            <div class="col-md-12">
                <label class="form-label">Currency</label>
                <input type="text" name="currency" class="form-control" value="BDT" maxlength="10">
            </div>
            <div class="col-md-4">
                <label class="form-label">Image (JPG/PNG/WebP, max 5 MB)</label>
                <input type="file" name="image" class="form-control" accept=".jpg,.jpeg,.png,.webp">
            </div>
            <div class="col-md-4">
                <label class="form-label">GIF (max 10 MB)</label>
                <input type="file" name="gif" class="form-control" accept=".gif">
            </div>
            <div class="col-md-4">
                <label class="form-label">Video (MP4/WebM/MOV, max 100 MB)</label>
                <input type="file" name="video" class="form-control" accept=".mp4,.webm,.mov">
            </div>
            <div class="col-md-12">
                <div class="form-check">
                    <input type="checkbox" name="is_active" class="form-check-input" value="1" checked id="activeService">
                    <label class="form-check-label" for="activeService">Active</label>
                </div>
            </div>
            <div class="col-12 d-flex gap-2">
                <button type="submit" class="btn btn-primary rounded-pill px-4"><i class="fas fa-save me-1"></i> Save Service</button>
                <a href="<?= $SCCRM_BASE ?>/services/" class="btn btn-outline-secondary rounded-pill px-4">Cancel</a>
            </div>
        </form>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
