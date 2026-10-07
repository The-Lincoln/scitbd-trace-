<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
$_sccrm_db_boot = require_once __DIR__ . '/../config/database.php';
if (($_sccrm_db_boot instanceof PDO) && (!isset($db) || !($db instanceof PDO))) { $db = $_sccrm_db_boot; }
$companies = $db->query("SELECT id, name FROM companies ORDER BY name")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $stmt = $db->prepare("INSERT INTO contacts (company_id, salutation, first_name, last_name, email, phone, mobile, position, department, linkedin, twitter, notes) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
    $stmt->execute([
        $_POST['company_id'] ?: null,
        $_POST['salutation'],
        $_POST['first_name'],
        $_POST['last_name'],
        $_POST['email'],
        $_POST['phone'],
        $_POST['mobile'],
        $_POST['position'],
        $_POST['department'],
        $_POST['linkedin'],
        $_POST['twitter'],
        $_POST['notes']
    ]);
    $_SESSION['flash'] = ['type' => 'success', 'message' => 'Contact created successfully!'];
    header('Location: index.php');
    exit;
}
require_once __DIR__ . '/../includes/header.php';
?>
<div class="page-title-area">
    <div>
        <h4>Add Contact</h4>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="<?= $SCCRM_BASE ?>/index.php" class="text-decoration-none">Dashboard</a></li>
                <li class="breadcrumb-item"><a href="<?= $SCCRM_BASE ?>/contacts/" class="text-decoration-none">Contacts</a></li>
                <li class="breadcrumb-item active">Add Contact</li>
            </ol>
        </nav>
    </div>
</div>

<div class="card-crm">
    <div class="card-body">
        <form method="POST" class="row g-3">
            <div class="col-12"><hr class="my-1"><h6 class="text-primary">Basic Information</h6></div>
            <div class="col-md-2">
                <label class="form-label">Salutation</label>
                <select name="salutation" class="form-select">
                    <option value="">--</option>
                    <option value="Mr.">Mr.</option>
                    <option value="Ms.">Ms.</option>
                    <option value="Mrs.">Mrs.</option>
                    <option value="Dr.">Dr.</option>
                </select>
            </div>
            <div class="col-md-5">
                <label class="form-label">First Name <span class="text-danger">*</span></label>
                <input type="text" name="first_name" class="form-control" required>
            </div>
            <div class="col-md-5">
                <label class="form-label">Last Name <span class="text-danger">*</span></label>
                <input type="text" name="last_name" class="form-control" required>
            </div>
            <div class="col-md-6">
                <label class="form-label">Email</label>
                <input type="email" name="email" class="form-control">
            </div>
            <div class="col-md-3">
                <label class="form-label">Phone</label>
                <input type="text" name="phone" class="form-control">
            </div>
            <div class="col-md-3">
                <label class="form-label">Mobile</label>
                <input type="text" name="mobile" class="form-control">
            </div>
            <div class="col-12"><hr class="my-1"><h6 class="text-primary">Professional</h6></div>
            <div class="col-md-4">
                <label class="form-label">Company</label>
                <select name="company_id" class="form-select">
                    <option value="">-- Select Company --</option>
                    <?php foreach ($companies as $comp): ?>
                    <option value="<?= $comp['id'] ?>"><?= htmlspecialchars($comp['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label">Position</label>
                <input type="text" name="position" class="form-control" placeholder="e.g. CEO, Manager">
            </div>
            <div class="col-md-4">
                <label class="form-label">Department</label>
                <input type="text" name="department" class="form-control" placeholder="e.g. Engineering">
            </div>
            <div class="col-12"><hr class="my-1"><h6 class="text-primary">Social & Notes</h6></div>
            <div class="col-md-6">
                <label class="form-label">LinkedIn</label>
                <input type="url" name="linkedin" class="form-control" placeholder="https://linkedin.com/in/...">
            </div>
            <div class="col-md-6">
                <label class="form-label">Twitter/X</label>
                <input type="text" name="twitter" class="form-control" placeholder="@username">
            </div>
            <div class="col-12">
                <label class="form-label">Notes</label>
                <textarea name="notes" class="form-control" rows="3"></textarea>
            </div>
            <div class="col-12 d-flex gap-2">
                <button type="submit" class="btn btn-primary rounded-pill px-4"><i class="fas fa-save me-1"></i> Save Contact</button>
                <a href="<?= $SCCRM_BASE ?>/contacts/" class="btn btn-outline-secondary rounded-pill px-4">Cancel</a>
            </div>
        </form>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
