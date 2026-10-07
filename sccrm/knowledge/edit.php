<?php
// POST + redirects BEFORE layout output (avoids "headers already sent").
if (session_status() === PHP_SESSION_NONE) { session_start(); }
$_sccrm_db_boot = require_once __DIR__ . '/../config/database.php';
if (($_sccrm_db_boot instanceof PDO) && (!isset($db) || !($db instanceof PDO))) { $db = $_sccrm_db_boot; }

$id = $_GET['id'] ?? 0;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $stmt = $db->prepare("UPDATE knowledge_articles SET service_id=?, title=?, content=?, excerpt=?, tags=?, status=?, updated_at=CURRENT_TIMESTAMP WHERE id=?");
    $stmt->execute([$_POST['service_id'] ?: null, $_POST['title'], $_POST['content'], $_POST['excerpt'], $_POST['tags'], $_POST['status'] ?: 'published', $id]);
    $_SESSION['flash'] = ['type' => 'success', 'message' => 'Article updated!'];
    header('Location: index.php'); exit;
}

require_once __DIR__ . '/../includes/header.php';
$a = $db->prepare("SELECT * FROM knowledge_articles WHERE id = ?");
$a->execute([$id]); $a = $a->fetch();
if (!$a) { echo '<div class="alert alert-warning">Article not found. <a href="index.php">Back to Knowledge Bank</a></div>'; require_once __DIR__ . '/../includes/footer.php'; exit; }
$services = $db->query("SELECT id, name, icon FROM services WHERE is_active = 1 ORDER BY category, name")->fetchAll();
?>
<div class="page-title-area">
    <div>
        <h4>Edit Article</h4>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="<?= $SCCRM_BASE ?>/index.php">Dashboard</a></li>
                <li class="breadcrumb-item"><a href="<?= $SCCRM_BASE ?>/knowledge/">Knowledge Bank</a></li>
                <li class="breadcrumb-item active">Edit</li>
            </ol>
        </nav>
    </div>
</div>
<div class="card-crm">
    <div class="card-body">
        <form method="POST" class="row g-3">
            <div class="col-md-8">
                <label class="form-label">Title <span class="text-danger">*</span></label>
                <input type="text" name="title" class="form-control" required value="<?= htmlspecialchars($a['title']) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label">Service</label>
                <select name="service_id" class="form-select">
                    <option value="">General</option>
                    <?php foreach ($services as $s): ?>
                    <option value="<?= $s['id'] ?>" <?= $a['service_id'] == $s['id'] ? 'selected' : '' ?>><?= htmlspecialchars($s['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Status</label>
                <select name="status" class="form-select">
                    <?php foreach (['published','draft','archived'] as $st): ?>
                    <option value="<?= $st ?>" <?= $a['status'] === $st ? 'selected' : '' ?>><?= ucfirst($st) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12">
                <label class="form-label">Excerpt</label>
                <textarea name="excerpt" class="form-control" rows="2"><?= htmlspecialchars($a['excerpt'] ?? '') ?></textarea>
            </div>
            <div class="col-12">
                <label class="form-label">Content</label>
                <textarea name="content" class="form-control" rows="14"><?= htmlspecialchars($a['content'] ?? '') ?></textarea>
            </div>
            <div class="col-md-8">
                <label class="form-label">Tags</label>
                <input type="text" name="tags" class="form-control" value="<?= htmlspecialchars($a['tags'] ?? '') ?>">
            </div>
            <div class="col-12 d-flex gap-2">
                <button type="submit" class="btn btn-primary rounded-pill px-4"><i class="fas fa-save me-1"></i> Update Article</button>
                <a href="<?= $SCCRM_BASE ?>/knowledge/" class="btn btn-outline-secondary rounded-pill px-4">Cancel</a>
            </div>
        </form>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
