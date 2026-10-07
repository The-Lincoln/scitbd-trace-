<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
$_sccrm_db_boot = require_once __DIR__ . '/../config/database.php';
if (($_sccrm_db_boot instanceof PDO) && (!isset($db) || !($db instanceof PDO))) { $db = $_sccrm_db_boot; }
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $stmt = $db->prepare("INSERT INTO companies (name, industry, website, email, phone, mobile, address, city, state, zip, country, description) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
    $stmt->execute([
        $_POST['name'], $_POST['industry'], $_POST['website'], $_POST['email'], $_POST['phone'], $_POST['mobile'],
        $_POST['address'], $_POST['city'], $_POST['state'], $_POST['zip'], $_POST['country'] ?: 'US', $_POST['description']
    ]);
    $_SESSION['flash'] = ['type' => 'success', 'message' => 'Company created successfully!'];
    header('Location: index.php');
    exit;
}
require_once __DIR__ . '/../includes/header.php';
?>
<div class="page-title-area">
    <div>
        <h4>Add Company</h4>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="<?= $SCCRM_BASE ?>/index.php" class="text-decoration-none">Dashboard</a></li>
                <li class="breadcrumb-item"><a href="<?= $SCCRM_BASE ?>/companies/" class="text-decoration-none">Companies</a></li>
                <li class="breadcrumb-item active">Add Company</li>
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
                <input type="text" name="name" class="form-control" required>
            </div>
            <div class="col-md-3">
                <label class="form-label">Industry</label>
                <input type="text" name="industry" class="form-control" placeholder="e.g. Technology">
            </div>
            <div class="col-md-3">
                <label class="form-label">Website</label>
                <input type="url" name="website" class="form-control" placeholder="https://">
            </div>
            <div class="col-md-4">
                <label class="form-label">Email</label>
                <input type="email" name="email" class="form-control">
            </div>
            <div class="col-md-4">
                <label class="form-label">Phone</label>
                <input type="text" name="phone" class="form-control">
            </div>
            <div class="col-md-4">
                <label class="form-label">Mobile</label>
                <input type="text" name="mobile" class="form-control">
            </div>
            <div class="col-12"><hr class="my-1"><h6 class="text-primary">Address</h6></div>
            <div class="col-12">
                <label class="form-label">Address</label>
                <input type="text" name="address" class="form-control">
            </div>
            <div class="col-md-4">
                <label class="form-label">City</label>
                <input type="text" name="city" class="form-control">
            </div>
            <div class="col-md-3">
                <label class="form-label">State</label>
                <input type="text" name="state" class="form-control">
            </div>
            <div class="col-md-2">
                <label class="form-label">ZIP Code</label>
                <input type="text" name="zip" class="form-control">
            </div>
            <div class="col-md-3">
                <label class="form-label">Country</label>
                <select name="country" class="form-select">
                    <option value="US">United States</option>
                    <option value="CA">Canada</option>
                    <option value="UK">United Kingdom</option>
                    <option value="DE">Germany</option>
                    <option value="FR">France</option>
                    <option value="AU">Australia</option>
                    <option value="IN">India</option>
                    <option value="JP">Japan</option>
                </select>
            </div>
            <div class="col-12">
                <label class="form-label">Description</label>
                <textarea name="description" class="form-control" rows="3" placeholder="Brief description of the company..."></textarea>
            </div>
            <div class="col-12 d-flex gap-2">
                <button type="submit" class="btn btn-primary rounded-pill px-4"><i class="fas fa-save me-1"></i> Save Company</button>
                <a href="<?= $SCCRM_BASE ?>/companies/" class="btn btn-outline-secondary rounded-pill px-4">Cancel</a>
            </div>
        </form>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
