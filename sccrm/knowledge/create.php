<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
$_sccrm_db_boot = require_once __DIR__ . '/../config/database.php';
if (($_sccrm_db_boot instanceof PDO) && (!isset($db) || !($db instanceof PDO))) { $db = $_sccrm_db_boot; }
$services = $db->query("SELECT id, name, icon FROM services WHERE is_active = 1 ORDER BY category, name")->fetchAll();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $stmt = $db->prepare("INSERT INTO knowledge_articles (service_id, title, content, excerpt, tags, status, created_by) VALUES (?,?,?,?,?,?,?)");
    $stmt->execute([$_POST['service_id'] ?: null, $_POST['title'], $_POST['content'], $_POST['excerpt'], $_POST['tags'], $_POST['status'] ?: 'published', $_POST['created_by'] ?: 'System']);
    $_SESSION['flash'] = ['type' => 'success', 'message' => 'Article created!'];
    header('Location: index.php'); exit;
}
require_once __DIR__ . '/../includes/header.php';
?>
<div class="page-title-area">
    <div>
        <h4>Write Article</h4>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="<?= $SCCRM_BASE ?>/index.php">Dashboard</a></li>
                <li class="breadcrumb-item"><a href="<?= $SCCRM_BASE ?>/knowledge/">Knowledge Bank</a></li>
                <li class="breadcrumb-item active">New Article</li>
            </ol>
        </nav>
    </div>
</div>
<div class="card-crm">
    <div class="card-body">
        <form method="POST" class="row g-3">
            <div class="col-md-8">
                <label class="form-label">Title <span class="text-danger">*</span></label>
                <input type="text" name="title" class="form-control" required>
            </div>
            <div class="col-md-2">
                <label class="form-label">Service</label>
                <select name="service_id" class="form-select">
                    <option value="">General</option>
                    <?php foreach ($services as $s): ?>
                    <option value="<?= $s['id'] ?>"><i class="fas <?= $s['icon'] ?>"></i> <?= htmlspecialchars($s['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Status</label>
                <select name="status" class="form-select">
                    <option value="published">Published</option>
                    <option value="draft">Draft</option>
                </select>
            </div>
            <div class="col-12">
                <label class="form-label">Excerpt / Summary</label>
                <textarea name="excerpt" class="form-control" rows="2" placeholder="Brief summary shown in listings"></textarea>
            </div>
            <div class="col-12">
                <label class="form-label">Content (Supports Markdown-style formatting)</label>
                <textarea name="content" class="form-control" rows="14" placeholder="Write your article content here..."></textarea>
            </div>
            <div class="col-md-8">
                <label class="form-label">Tags (comma-separated)</label>
                <input type="text" name="tags" class="form-control" placeholder="e.g. AI, chatbot, integration, GPT-4">
            </div>
            <div class="col-md-4">
                <label class="form-label">Author</label>
                <input type="text" name="created_by" class="form-control" value="System">
            </div>
            <div class="col-12 d-flex gap-2">
                <button type="submit" class="btn btn-primary rounded-pill px-4"><i class="fas fa-save me-1"></i> Publish Article</button>
                <a href="<?= $SCCRM_BASE ?>/knowledge/" class="btn btn-outline-secondary rounded-pill px-4">Cancel</a>
            </div>
        </form>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
