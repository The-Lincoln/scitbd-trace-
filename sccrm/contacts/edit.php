<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
$_sccrm_db_boot = require_once __DIR__ . '/../config/database.php';
if (($_sccrm_db_boot instanceof PDO) && (!isset($db) || !($db instanceof PDO))) { $db = $_sccrm_db_boot; }
$id = $_GET['id'] ?? 0;
$contact = $db->prepare("SELECT * FROM contacts WHERE id = ?");
$contact->execute([$id]);
$contact = $contact->fetch();
if (!$contact) { header('Location: index.php'); exit; }

$companies = $db->query("SELECT id, name FROM companies ORDER BY name")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $stmt = $db->prepare("UPDATE contacts SET company_id=?, salutation=?, first_name=?, last_name=?, email=?, phone=?, mobile=?, position=?, department=?, linkedin=?, twitter=?, notes=?, updated_at=CURRENT_TIMESTAMP WHERE id=?");
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
        $_POST['notes'],
        $id
    ]);
    $_SESSION['flash'] = ['type' => 'success', 'message' => 'Contact updated successfully!'];
    header('Location: index.php');
    exit;
}
require_once __DIR__ . '/../includes/header.php';
?>
<div class="page-title-area">
    <div>
        <h4>Edit Contact</h4>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="<?= $SCCRM_BASE ?>/index.php" class="text-decoration-none">Dashboard</a></li>
                <li class="breadcrumb-item"><a href="<?= $SCCRM_BASE ?>/contacts/" class="text-decoration-none">Contacts</a></li>
                <li class="breadcrumb-item active">Edit</li>
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
                    <?php foreach(['Mr.','Ms.','Mrs.','Dr.'] as $s): ?>
                    <option value="<?= $s ?>" <?= $contact['salutation'] === $s ? 'selected' : '' ?>><?= $s ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-5">
                <label class="form-label">First Name <span class="text-danger">*</span></label>
                <input type="text" name="first_name" class="form-control" required value="<?= htmlspecialchars($contact['first_name']) ?>">
            </div>
            <div class="col-md-5">
                <label class="form-label">Last Name <span class="text-danger">*</span></label>
                <input type="text" name="last_name" class="form-control" required value="<?= htmlspecialchars($contact['last_name']) ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label">Email</label>
                <input type="email" name="email" class="form-control" value="<?= htmlspecialchars($contact['email'] ?? '') ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label">Phone</label>
                <input type="text" name="phone" class="form-control" value="<?= htmlspecialchars($contact['phone'] ?? '') ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label">Mobile</label>
                <input type="text" name="mobile" class="form-control" value="<?= htmlspecialchars($contact['mobile'] ?? '') ?>">
            </div>
            <div class="col-12"><hr class="my-1"><h6 class="text-primary">Professional</h6></div>
            <div class="col-md-4">
                <label class="form-label">Company</label>
                <select name="company_id" class="form-select">
                    <option value="">-- Select Company --</option>
                    <?php foreach ($companies as $comp): ?>
                    <option value="<?= $comp['id'] ?>" <?= $contact['company_id'] == $comp['id'] ? 'selected' : '' ?>><?= htmlspecialchars($comp['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label">Position</label>
                <input type="text" name="position" class="form-control" value="<?= htmlspecialchars($contact['position'] ?? '') ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label">Department</label>
                <input type="text" name="department" class="form-control" value="<?= htmlspecialchars($contact['department'] ?? '') ?>">
            </div>
            <div class="col-12"><hr class="my-1"><h6 class="text-primary">Social & Notes</h6></div>
            <div class="col-md-6">
                <label class="form-label">LinkedIn</label>
                <input type="url" name="linkedin" class="form-control" value="<?= htmlspecialchars($contact['linkedin'] ?? '') ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label">Twitter/X</label>
                <input type="text" name="twitter" class="form-control" value="<?= htmlspecialchars($contact['twitter'] ?? '') ?>">
            </div>
            <div class="col-12">
                <label class="form-label">Notes</label>
                <textarea name="notes" class="form-control" rows="3"><?= htmlspecialchars($contact['notes'] ?? '') ?></textarea>
            </div>
            <div class="col-12 d-flex gap-2">
                <button type="submit" class="btn btn-primary rounded-pill px-4"><i class="fas fa-save me-1"></i> Update Contact</button>
                <a href="<?= $SCCRM_BASE ?>/contacts/" class="btn btn-outline-secondary rounded-pill px-4">Cancel</a>
            </div>
        </form>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
