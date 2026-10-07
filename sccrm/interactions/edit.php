<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
$_sccrm_db_boot = require_once __DIR__ . '/../config/database.php';
if (($_sccrm_db_boot instanceof PDO) && (!isset($db) || !($db instanceof PDO))) { $db = $_sccrm_db_boot; }
$id = $_GET['id'] ?? 0;
$interaction = $db->prepare("SELECT * FROM interactions WHERE id = ?");
$interaction->execute([$id]);
$interaction = $interaction->fetch();
if (!$interaction) { header('Location: index.php'); exit; }

$contacts = $db->query("SELECT id, first_name, last_name FROM contacts ORDER BY first_name")->fetchAll();
$companies = $db->query("SELECT id, name FROM companies ORDER BY name")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $stmt = $db->prepare("UPDATE interactions SET contact_id=?, company_id=?, type=?, subject=?, content=?, date=?, follow_up_date=?, created_by=? WHERE id=?");
    $stmt->execute([
        $_POST['contact_id'] ?: null,
        $_POST['company_id'] ?: null,
        $_POST['type'], $_POST['subject'], $_POST['content'],
        $_POST['date'], $_POST['follow_up_date'] ?: null, $_POST['created_by'] ?: 'System', $id
    ]);
    $_SESSION['flash'] = ['type' => 'success', 'message' => 'Interaction updated successfully!'];
    header('Location: index.php');
    exit;
}
require_once __DIR__ . '/../includes/header.php';
?>
<div class="page-title-area">
    <div>
        <h4>Edit Interaction</h4>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="<?= $SCCRM_BASE ?>/index.php" class="text-decoration-none">Dashboard</a></li>
                <li class="breadcrumb-item"><a href="<?= $SCCRM_BASE ?>/interactions/" class="text-decoration-none">Interactions</a></li>
                <li class="breadcrumb-item active">Edit</li>
            </ol>
        </nav>
    </div>
</div>
<div class="card-crm">
    <div class="card-body">
        <form method="POST" class="row g-3">
            <div class="col-12"><hr class="my-1"><h6 class="text-primary">Interaction Details</h6></div>
            <div class="col-md-3">
                <label class="form-label">Type <span class="text-danger">*</span></label>
                <select name="type" class="form-select" required>
                    <?php foreach (['call'=>'Call','email'=>'Email','meeting'=>'Meeting','note'=>'Note','social'=>'Social Media','other'=>'Other'] as $val=>$label): ?>
                    <option value="<?= $val ?>" <?= $interaction['type'] === $val ? 'selected' : '' ?>><?= $label ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-5">
                <label class="form-label">Subject <span class="text-danger">*</span></label>
                <input type="text" name="subject" class="form-control" required value="<?= htmlspecialchars($interaction['subject']) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label">Date <span class="text-danger">*</span></label>
                <input type="date" name="date" class="form-control" required value="<?= $interaction['date'] ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label">Follow-up Date</label>
                <input type="date" name="follow_up_date" class="form-control" value="<?= $interaction['follow_up_date'] ?? '' ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label">Contact</label>
                <select name="contact_id" class="form-select">
                    <option value="">-- Select Contact --</option>
                    <?php foreach ($contacts as $c): ?>
                    <option value="<?= $c['id'] ?>" <?= $interaction['contact_id'] == $c['id'] ? 'selected' : '' ?>><?= htmlspecialchars($c['first_name'].' '.$c['last_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label">Company</label>
                <select name="company_id" class="form-select">
                    <option value="">-- Select Company --</option>
                    <?php foreach ($companies as $comp): ?>
                    <option value="<?= $comp['id'] ?>" <?= $interaction['company_id'] == $comp['id'] ? 'selected' : '' ?>><?= htmlspecialchars($comp['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label">Logged By</label>
                <input type="text" name="created_by" class="form-control" value="<?= htmlspecialchars($interaction['created_by'] ?? 'System') ?>">
            </div>
            <div class="col-12">
                <label class="form-label">Content / Notes</label>
                <textarea name="content" class="form-control" rows="4"><?= htmlspecialchars($interaction['content'] ?? '') ?></textarea>
            </div>
            <div class="col-12 d-flex gap-2">
                <button type="submit" class="btn btn-primary rounded-pill px-4"><i class="fas fa-save me-1"></i> Update Interaction</button>
                <a href="<?= $SCCRM_BASE ?>/interactions/" class="btn btn-outline-secondary rounded-pill px-4">Cancel</a>
            </div>
        </form>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
