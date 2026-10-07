<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
$_sccrm_db_boot = require_once __DIR__ . '/../config/database.php';
if (($_sccrm_db_boot instanceof PDO) && (!isset($db) || !($db instanceof PDO))) { $db = $_sccrm_db_boot; }
require_once __DIR__ . '/../includes/upload_helper.php';
$id = $_GET['id'] ?? 0;
$s = $db->prepare("SELECT * FROM services WHERE id = ?");
$s->execute([$id]); $s = $s->fetch();
if (!$s) { header('Location: index.php'); exit; }
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $image = sccrm_upload_media($_FILES['image'] ?? [], 'image', $s['image'] ?? null);
    $gif   = sccrm_upload_media($_FILES['gif'] ?? [], 'gif', $s['gif'] ?? null);
    $video = sccrm_upload_media($_FILES['video'] ?? [], 'video', $s['video'] ?? null);
    $stmt = $db->prepare("UPDATE services SET name=?, category=?, description=?, icon=?, is_active=?, price_silver=?, price_platinum=?, currency=?, image=?, gif=?, video=? WHERE id=?");
    $stmt->execute([
        $_POST['name'], $_POST['category'], $_POST['description'], $_POST['icon'], $_POST['is_active'] ?? 0,
        ($_POST['price_silver'] ?? '') !== '' ? (float)$_POST['price_silver'] : null,
        ($_POST['price_platinum'] ?? '') !== '' ? (float)$_POST['price_platinum'] : null,
        $_POST['currency'] ?? 'BDT', $image, $gif, $video, $id
    ]);
    $_SESSION['flash'] = ['type' => 'success', 'message' => 'Service updated!'];
    header('Location: index.php'); exit;
}
$icons = ['fa-sitemap','fa-globe','fa-code','fa-mobile-alt','fa-graduation-cap','fa-robot','fa-shield-alt','fa-credit-card','fa-bullhorn','fa-university','fa-cloud','fa-app-store-ios','fa-cogs','fa-lock','fa-clipboard-check','fa-brain','fa-user-astronaut','fa-chart-line','fa-database','fa-server','fa-network-wired','fa-cog'];
require_once __DIR__ . '/../includes/header.php';
?>
<div class="page-title-area">
    <div>
        <h4>Edit Service</h4>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="<?= $SCCRM_BASE ?>/index.php" class="text-decoration-none">Dashboard</a></li>
                <li class="breadcrumb-item"><a href="<?= $SCCRM_BASE ?>/services/" class="text-decoration-none">Services</a></li>
                <li class="breadcrumb-item active">Edit</li>
            </ol>
        </nav>
    </div>
</div>
<div class="card-crm">
    <div class="card-body">
        <form method="POST" enctype="multipart/form-data" class="row g-3">
            <div class="col-md-8">
                <label class="form-label">Service Name <span class="text-danger">*</span></label>
                <input type="text" name="name" class="form-control" required value="<?= htmlspecialchars($s['name']) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label">Category</label>
                <select name="category" class="form-select">
                    <?php foreach (['Consulting','Development','AI','Security','Marketing','E-Commerce','Enterprise','Education','Audit'] as $cat): ?>
                    <option value="<?= $cat ?>" <?= $s['category'] === $cat ? 'selected' : '' ?>><?= $cat ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Icon</label>
                <select name="icon" class="form-select">
                    <?php foreach ($icons as $ic): ?>
                    <option value="<?= $ic ?>" <?= $s['icon'] === $ic ? 'selected' : '' ?>><?= $ic ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12">
                <label class="form-label">Description</label>
                <textarea name="description" class="form-control" rows="2"><?= htmlspecialchars($s['description'] ?? '') ?></textarea>
            </div>
            <div class="col-md-6">
                <label class="form-label">Silver Client Price (BDT)</label>
                <input type="number" step="0.01" min="0" name="price_silver" class="form-control"
                       value="<?= $s['price_silver'] !== null ? (float)$s['price_silver'] : '' ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label">Platinum Client Price (BDT)</label>
                <input type="number" step="0.01" min="0" name="price_platinum" class="form-control"
                       value="<?= $s['price_platinum'] !== null ? (float)$s['price_platinum'] : '' ?>">
            </div>
            <div class="col-md-12">
                <label class="form-label">Currency</label>
                <input type="text" name="currency" class="form-control" value="<?= htmlspecialchars($s['currency'] ?? 'BDT') ?>" maxlength="10">
            </div>
            <div class="col-md-4">
                <label class="form-label">Image (JPG/PNG/WebP, max 5 MB)</label>
                <?php if (!empty($s['image'])): ?>
                    <div class="mb-1"><img src="<?= htmlspecialchars($s['image']) ?>" style="max-height:70px;" class="border rounded"></div>
                <?php endif; ?>
                <input type="file" name="image" class="form-control" accept=".jpg,.jpeg,.png,.webp">
            </div>
            <div class="col-md-4">
                <label class="form-label">GIF (max 10 MB)</label>
                <?php if (!empty($s['gif'])): ?>
                    <div class="mb-1"><img src="<?= htmlspecialchars($s['gif']) ?>" style="max-height:70px;" class="border rounded"></div>
                <?php endif; ?>
                <input type="file" name="gif" class="form-control" accept=".gif">
            </div>
            <div class="col-md-4">
                <label class="form-label">Video (MP4/WebM/MOV, max 100 MB)</label>
                <?php if (!empty($s['video'])): ?>
                    <div class="mb-1"><a href="<?= htmlspecialchars($s['video']) ?>" target="_blank" class="small"><?= htmlspecialchars($s['video']) ?></a></div>
                <?php endif; ?>
                <input type="file" name="video" class="form-control" accept=".mp4,.webm,.mov">
            </div>
            <div class="col-md-12">
                <div class="form-check">
                    <input type="checkbox" name="is_active" class="form-check-input" value="1" id="activeService" <?= $s['is_active'] ? 'checked' : '' ?>>
                    <label class="form-check-label" for="activeService">Active</label>
                </div>
            </div>
            <div class="col-12 d-flex gap-2">
                <button type="submit" class="btn btn-primary rounded-pill px-4"><i class="fas fa-save me-1"></i> Update Service</button>
                <a href="<?= $SCCRM_BASE ?>/services/" class="btn btn-outline-secondary rounded-pill px-4">Cancel</a>
            </div>
        </form>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
