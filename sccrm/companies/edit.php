<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
$_sccrm_db_boot = require_once __DIR__ . '/../config/database.php';
if (($_sccrm_db_boot instanceof PDO) && (!isset($db) || !($db instanceof PDO))) { $db = $_sccrm_db_boot; }
$id = $_GET['id'] ?? 0;
$company = $db->prepare("SELECT * FROM companies WHERE id = ?");
$company->execute([$id]);
$company = $company->fetch();
if (!$company) { header('Location: index.php'); exit; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $stmt = $db->prepare("UPDATE companies SET name=?, industry=?, website=?, email=?, phone=?, mobile=?, address=?, city=?, state=?, zip=?, country=?, description=?, updated_at=CURRENT_TIMESTAMP WHERE id=?");
    $stmt->execute([
        $_POST['name'], $_POST['industry'], $_POST['website'], $_POST['email'], $_POST['phone'], $_POST['mobile'],
        $_POST['address'], $_POST['city'], $_POST['state'], $_POST['zip'], $_POST['country'] ?: 'US', $_POST['description'], $id
    ]);
    $_SESSION['flash'] = ['type' => 'success', 'message' => 'Company updated successfully!'];
    header('Location: index.php');
    exit;
}
require_once __DIR__ . '/../includes/header.php';
?>
<div class="page-title-area">
    <div>
        <h4>Edit Company</h4>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="<?= $SCCRM_BASE ?>/index.php" class="text-decoration-none">Dashboard</a></li>
                <li class="breadcrumb-item"><a href="<?= $SCCRM_BASE ?>/companies/" class="text-decoration-none">Companies</a></li>
                <li class="breadcrumb-item active">Edit</li>
            </ol>
        </nav>
    </div>
</div>
<div class="card-crm">
    <div class="card-body">
        <form method="POST" class="row g-3">
            <div class="col-12"><hr class="my-1"><h6 class="text-primary">Company Information</h6></div>
            <div class="col-md-6">
                <label class="form-label">Company Name <span class="text-danger">*</span></label>
                <input type="text" name="name" class="form-control" required value="<?= htmlspecialchars($company['name']) ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label">Industry</label>
                <input type="text" name="industry" class="form-control" value="<?= htmlspecialchars($company['industry'] ?? '') ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label">Website</label>
                <input type="url" name="website" class="form-control" value="<?= htmlspecialchars($company['website'] ?? '') ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label">Email</label>
                <input type="email" name="email" class="form-control" value="<?= htmlspecialchars($company['email'] ?? '') ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label">Phone</label>
                <input type="text" name="phone" class="form-control" value="<?= htmlspecialchars($company['phone'] ?? '') ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label">Mobile</label>
                <input type="text" name="mobile" class="form-control" value="<?= htmlspecialchars($company['mobile'] ?? '') ?>">
            </div>
            <div class="col-12"><hr class="my-1"><h6 class="text-primary">Address</h6></div>
            <div class="col-12">
                <label class="form-label">Address</label>
                <input type="text" name="address" class="form-control" value="<?= htmlspecialchars($company['address'] ?? '') ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label">City</label>
                <input type="text" name="city" class="form-control" value="<?= htmlspecialchars($company['city'] ?? '') ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label">State</label>
                <input type="text" name="state" class="form-control" value="<?= htmlspecialchars($company['state'] ?? '') ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label">ZIP Code</label>
                <input type="text" name="zip" class="form-control" value="<?= htmlspecialchars($company['zip'] ?? '') ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label">Country</label>
                <select name="country" class="form-select">
                    <?php foreach(['US'=>'United States','CA'=>'Canada','UK'=>'United Kingdom','DE'=>'Germany','FR'=>'France','AU'=>'Australia','IN'=>'India','JP'=>'Japan'] as $code=>$name): ?>
                    <option value="<?= $code ?>" <?= $company['country'] === $code ? 'selected' : '' ?>><?= $name ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12">
                <label class="form-label">Description</label>
                <textarea name="description" class="form-control" rows="3"><?= htmlspecialchars($company['description'] ?? '') ?></textarea>
            </div>
            <div class="col-12 d-flex gap-2">
                <button type="submit" class="btn btn-primary rounded-pill px-4"><i class="fas fa-save me-1"></i> Update Company</button>
                <a href="<?= $SCCRM_BASE ?>/companies/" class="btn btn-outline-secondary rounded-pill px-4">Cancel</a>
            </div>
        </form>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
