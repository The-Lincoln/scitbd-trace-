<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
$_sccrm_db_boot = require_once __DIR__ . '/../config/database.php';
if (($_sccrm_db_boot instanceof PDO) && (!isset($db) || !($db instanceof PDO))) { $db = $_sccrm_db_boot; }
$cat_filter = $_GET['category_id'] ?? '';
$sql = "SELECT f.*, fc.name as cat_name, s.name as service_name FROM faqs f LEFT JOIN faq_categories fc ON f.category_id = fc.id LEFT JOIN services s ON f.service_id = s.id";
$params = [];
if ($cat_filter) { $sql .= " WHERE f.category_id = ?"; $params[] = $cat_filter; }
$sql .= " ORDER BY fc.sort_order, f.sort_order";
$faqs = $db->prepare($sql); $faqs->execute($params); $faqs = $faqs->fetchAll();
$categories = $db->query("SELECT * FROM faq_categories ORDER BY sort_order")->fetchAll();
$services = $db->query("SELECT id, name FROM services WHERE is_active = 1 ORDER BY name")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_category'])) {
    $max = $db->query("SELECT MAX(sort_order) FROM faq_categories")->fetchColumn() ?: 0;
    $stmt = $db->prepare("INSERT INTO faq_categories (name, description, icon, sort_order) VALUES (?,?,?,?)");
    $stmt->execute([$_POST['name'], $_POST['description'], $_POST['icon'] ?: 'fa-folder', $max + 1]);
    $_SESSION['flash'] = ['type' => 'success', 'message' => 'Category added!']; header('Location: admin.php'); exit;
}
require_once __DIR__ . '/../includes/header.php';
?>
<div class="page-title-area">
    <div><h4>Manage FAQ</h4>
        <nav aria-label="breadcrumb"><ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="<?= $SCCRM_BASE ?>/index.php" class="text-decoration-none">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= $SCCRM_BASE ?>/faq/" class="text-decoration-none">FAQ</a></li>
            <li class="breadcrumb-item active">Manage</li>
        </ol></nav>
    </div>
    <div class="d-flex gap-2">
        <button class="btn btn-success rounded-pill px-3" data-bs-toggle="modal" data-bs-target="#catModal"><i class="fas fa-folder-plus me-1"></i> Add Category</button>
        <a href="<?= $SCCRM_BASE ?>/faq/create.php" class="btn btn-primary rounded-pill px-3"><i class="fas fa-plus me-1"></i> Add FAQ</a>
    </div>
</div>

<div class="card-crm">
    <div class="card-header">
        <form method="GET" class="d-flex gap-2">
            <select name="category_id" class="form-select form-select-sm" onchange="this.form.submit()" style="width:auto;">
                <option value="">All Categories</option>
                <?php foreach ($categories as $cat): ?>
                <option value="<?= $cat['id'] ?>" <?= $cat_filter == $cat['id'] ? 'selected' : '' ?>><?= htmlspecialchars($cat['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <?php if ($cat_filter): ?><a href="<?= $SCCRM_BASE ?>/faq/admin.php" class="btn btn-sm btn-outline-secondary">Clear</a><?php endif; ?>
        </form>
    </div>
    <div class="card-body p-0">
        <?php if (count($faqs) > 0): ?>
        <div class="table-responsive">
            <table class="table table-crm">
                <thead><tr><th>Question</th><th>Category</th><th>Service</th><th>Status</th><th>Actions</th></tr></thead>
                <tbody>
                    <?php foreach ($faqs as $f): ?>
                    <tr>
                        <td style="max-width:300px;"><?= htmlspecialchars($f['question']) ?></td>
                        <td><span class="badge bg-light text-dark"><?= htmlspecialchars($f['cat_name'] ?? '-') ?></span></td>
                        <td style="font-size:13px;"><?= htmlspecialchars($f['service_name'] ?? '-') ?></td>
                        <td><span class="badge-status <?= $f['is_published'] ? 'completed' : 'cancelled' ?>"><?= $f['is_published'] ? 'Published' : 'Hidden' ?></span></td>
                        <td>
                            <div class="d-flex gap-1">
                                <a href="<?= $SCCRM_BASE ?>/faq/edit.php?id=<?= $f['id'] ?>" class="btn btn-action btn-outline-primary" data-bs-toggle="tooltip" title="Edit"><i class="fas fa-edit"></i></a>
                                <a href="<?= $SCCRM_BASE ?>/faq/delete.php?id=<?= $f['id'] ?>" class="btn btn-action btn-outline-danger" data-confirm="Delete this FAQ?" data-bs-toggle="tooltip" title="Delete"><i class="fas fa-trash"></i></a>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <div class="empty-state"><i class="fas fa-question-circle"></i><h6>No FAQs</h6><a href="<?= $SCCRM_BASE ?>/faq/create.php" class="btn btn-primary btn-sm mt-2">Add FAQ</a></div>
        <?php endif; ?>
    </div>
</div>

<div class="modal fade" id="catModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
    <div class="modal-header"><h5 class="modal-title">Add FAQ Category</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <form method="POST"><div class="modal-body">
        <input type="hidden" name="add_category" value="1">
        <div class="mb-3"><label class="form-label">Category Name</label><input type="text" name="name" class="form-control" required></div>
        <div class="mb-3"><label class="form-label">Description</label><textarea name="description" class="form-control" rows="2"></textarea></div>
        <div class="mb-3"><label class="form-label">Icon</label><input type="text" name="icon" class="form-control" value="fa-question-circle" placeholder="fa-icon-name"></div>
    </div><div class="modal-footer">
        <button type="submit" class="btn btn-primary rounded-pill"><i class="fas fa-save me-1"></i> Save</button>
        <button type="button" class="btn btn-outline-secondary rounded-pill" data-bs-dismiss="modal">Cancel</button>
    </div></form>
</div></div></div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
