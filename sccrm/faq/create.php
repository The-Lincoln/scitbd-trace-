<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
$_sccrm_db_boot = require_once __DIR__ . '/../config/database.php';
if (($_sccrm_db_boot instanceof PDO) && (!isset($db) || !($db instanceof PDO))) { $db = $_sccrm_db_boot; }
$categories = $db->query("SELECT * FROM faq_categories ORDER BY sort_order")->fetchAll();
$services = $db->query("SELECT id, name FROM services WHERE is_active = 1 ORDER BY name")->fetchAll();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $stmt = $db->prepare("SELECT COALESCE(MAX(sort_order),0) + 1 FROM faqs WHERE category_id = ?");
    $stmt->execute([$_POST['category_id'] ?: 0]);
    $max = $stmt->fetchColumn();
    $stmt = $db->prepare("INSERT INTO faqs (category_id, service_id, question, answer, sort_order, is_published) VALUES (?,?,?,?,?,?)");
    $stmt->execute([$_POST['category_id'] ?: null, $_POST['service_id'] ?: null, $_POST['question'], $_POST['answer'], $max ?: 0, $_POST['is_published'] ?? 1]);
    $_SESSION['flash'] = ['type' => 'success', 'message' => 'FAQ added!']; header('Location: admin.php'); exit;
}
require_once __DIR__ . '/../includes/header.php';
?>
<div class="page-title-area">
    <div><h4>Add FAQ</h4>
        <nav aria-label="breadcrumb"><ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="<?= $SCCRM_BASE ?>/index.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= $SCCRM_BASE ?>/faq/">FAQ</a></li>
            <li class="breadcrumb-item"><a href="<?= $SCCRM_BASE ?>/faq/admin.php">Manage</a></li>
            <li class="breadcrumb-item active">Add FAQ</li>
        </ol></nav>
    </div>
</div>
<div class="card-crm"><div class="card-body">
    <form method="POST" class="row g-3">
        <div class="col-12"><label class="form-label">Question <span class="text-danger">*</span></label><input type="text" name="question" class="form-control" required></div>
        <div class="col-12"><label class="form-label">Answer <span class="text-danger">*</span></label><textarea name="answer" class="form-control" rows="5" required></textarea></div>
        <div class="col-md-4"><label class="form-label">Category</label><select name="category_id" class="form-select"><option value="">--</option>
            <?php foreach ($categories as $cat): ?><option value="<?= $cat['id'] ?>"><?= htmlspecialchars($cat['name']) ?></option><?php endforeach; ?>
        </select></div>
        <div class="col-md-4"><label class="form-label">Service</label><select name="service_id" class="form-select"><option value="">--</option>
            <?php foreach ($services as $s): ?><option value="<?= $s['id'] ?>"><?= htmlspecialchars($s['name']) ?></option><?php endforeach; ?>
        </select></div>
        <div class="col-md-4"><div class="form-check mt-4"><input type="checkbox" name="is_published" class="form-check-input" value="1" checked id="pubFaq"><label class="form-check-label" for="pubFaq">Published</label></div></div>
        <div class="col-12 d-flex gap-2"><button type="submit" class="btn btn-primary rounded-pill px-4"><i class="fas fa-save me-1"></i> Save FAQ</button><a href="<?= $SCCRM_BASE ?>/faq/admin.php" class="btn btn-outline-secondary rounded-pill px-4">Cancel</a></div>
    </form>
</div></div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
