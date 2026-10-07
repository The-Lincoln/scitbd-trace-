<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_admin();

$pdo = db();
$action = $_GET['action'] ?? 'list';
$id = (int)($_GET['id'] ?? 0);
$default_cat = $_GET['cat'] ?? '';

// Handle POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $url = trim($_POST['url'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $category_id = (int)($_POST['category_id'] ?? 0);
    $cost_type = $_POST['cost_type'] ?? 'free';
    $access_type = $_POST['access_type'] ?? 'web';
    $requires_auth = isset($_POST['requires_auth']) ? 1 : 0;
    $tags = trim($_POST['tags'] ?? '');

    if ($name && $url && $category_id) {
        $host = parse_url($url, PHP_URL_HOST) ?: '';
        $favicon = $host ? FAVICON_SERVICE . $host : '';

        if ($id) {
            $pdo->prepare("UPDATE tools SET category_id=?, name=?, url=?, description=?, cost_type=?, access_type=?, requires_auth=?, tags=?, favicon=?, updated_at=datetime('now') WHERE id=?")
                ->execute([$category_id, $name, $url, $description, $cost_type, $access_type, $requires_auth, $tags, $favicon, $id]);
            $_SESSION['flash'] = "Tool updated.";
        } else {
            $pdo->prepare("INSERT INTO tools (category_id, name, url, description, cost_type, access_type, requires_auth, tags, favicon) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)")
                ->execute([$category_id, $name, $url, $description, $cost_type, $access_type, $requires_auth, $tags, $favicon]);
            $_SESSION['flash'] = "Tool added.";
        }
        header('Location: ' . $BASE . '/admin/tools.php');
        exit;
    } else {
        $_SESSION['flash_error'] = "Name, URL, and Category are required.";
    }
}

// Handle delete
if ($action === 'delete' && $id) {
    $pdo->prepare("DELETE FROM tools WHERE id = ?")->execute([$id]);
    $_SESSION['flash'] = "Tool deleted.";
    header('Location: ' . $BASE . '/admin/tools.php');
    exit;
}

// Search/filter
$search = trim($_GET['q'] ?? '');
$cat_filter = $_GET['cat_filter'] ?? '';

$PAGE_TITLE = 'Manage Tools';
include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h2><i class="fas fa-wrench"></i> Tools</h2>
    <a href="?action=new" class="btn btn-success"><i class="fas fa-plus"></i> Add Tool</a>
</div>

<?php if ($flash = $_SESSION['flash'] ?? ''): unset($_SESSION['flash']); ?>
    <div class="alert alert-success"><?= h($flash) ?></div>
<?php endif; ?>
<?php if ($flash_err = $_SESSION['flash_error'] ?? ''): unset($_SESSION['flash_error']); ?>
    <div class="alert alert-danger"><?= h($flash_err) ?></div>
<?php endif; ?>

<?php if ($action === 'new' || $action === 'edit'):
    $tool = ['id' => 0, 'category_id' => 0, 'name' => '', 'url' => '', 'description' => '',
             'cost_type' => 'free', 'access_type' => 'web', 'requires_auth' => 0, 'tags' => ''];
    if ($id) {
        $stmt = $pdo->prepare("SELECT * FROM tools WHERE id = ?");
        $stmt->execute([$id]);
        $tool = $stmt->fetch() ?: $tool;
    } elseif ($default_cat) {
        $cat = get_category($default_cat);
        if ($cat) $tool['category_id'] = $cat['id'];
    }
    $cats = $pdo->query("SELECT c.id, c.name, p.name AS parent_name FROM categories c LEFT JOIN categories p ON p.id = c.parent_id ORDER BY c.name")->fetchAll();
?>
    <div class="card">
        <div class="card-header"><?= $id ? 'Edit Tool' : 'Add New Tool' ?></div>
        <div class="card-body">
            <form method="post" action="?action=<?= $id ? 'edit&id=' . $id : 'create' ?>">
                <div class="row g-3">
                    <div class="col-md-8">
                        <label class="form-label">Name *</label>
                        <input type="text" name="name" class="form-control" required value="<?= h($tool['name']) ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Category *</label>
                        <select name="category_id" class="form-select" required>
                            <option value="">Select...</option>
                            <?php foreach ($cats as $c): ?>
                                <option value="<?= (int)$c['id'] ?>" <?= $tool['category_id'] == $c['id'] ? 'selected' : '' ?>>
                                    <?= h($c['parent_name'] ? $c['parent_name'] . ' › ' : '') . h($c['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-12">
                        <label class="form-label">URL *</label>
                        <input type="url" name="url" class="form-control" required value="<?= h($tool['url']) ?>" placeholder="https://...">
                    </div>
                    <div class="col-md-12">
                        <label class="form-label">Description</label>
                        <textarea name="description" class="form-control" rows="2"><?= h($tool['description']) ?></textarea>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Cost Type</label>
                        <select name="cost_type" class="form-select">
                            <option value="free" <?= $tool['cost_type'] === 'free' ? 'selected' : '' ?>>Free</option>
                            <option value="freemium" <?= $tool['cost_type'] === 'freemium' ? 'selected' : '' ?>>Freemium</option>
                            <option value="paid" <?= $tool['cost_type'] === 'paid' ? 'selected' : '' ?>>Paid</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Access Type</label>
                        <select name="access_type" class="form-select">
                            <option value="web" <?= $tool['access_type'] === 'web' ? 'selected' : '' ?>>Web</option>
                            <option value="api" <?= $tool['access_type'] === 'api' ? 'selected' : '' ?>>API</option>
                            <option value="software" <?= $tool['access_type'] === 'software' ? 'selected' : '' ?>>Software</option>
                            <option value="browser-ext" <?= $tool['access_type'] === 'browser-ext' ? 'selected' : '' ?>>Browser Extension</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Tags</label>
                        <input type="text" name="tags" class="form-control" value="<?= h($tool['tags']) ?>" placeholder="comma,separated">
                    </div>
                    <div class="col-md-3 d-flex align-items-end">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" name="requires_auth" id="reqAuth" <?= $tool['requires_auth'] ? 'checked' : '' ?>>
                            <label class="form-check-label" for="reqAuth">Requires account</label>
                        </div>
                    </div>
                    <div class="col-12">
                        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Tool</button>
                        <a href="<?= $BASE ?>/admin/tools.php" class="btn btn-outline-secondary">Cancel</a>
                    </div>
                </div>
            </form>
        </div>
    </div>
<?php else: ?>

    <div class="card mb-3">
        <div class="card-body">
            <form method="get" class="row g-2">
                <div class="col-md-6">
                    <input type="text" name="q" class="form-control" placeholder="Search tools..." value="<?= h($search) ?>">
                </div>
                <div class="col-md-4">
                    <select name="cat_filter" class="form-select">
                        <option value="">All categories</option>
                        <?php
                        $all = $pdo->query("SELECT * FROM categories ORDER BY name")->fetchAll();
                        foreach ($all as $c):
                        ?>
                            <option value="<?= h($c['slug']) ?>" <?= $cat_filter === $c['slug'] ? 'selected' : '' ?>><?= h($c['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary w-100"><i class="fas fa-filter"></i> Filter</button>
                </div>
            </form>
        </div>
    </div>

    <?php
    $sql = "SELECT t.*, c.name AS cat_name FROM tools t JOIN categories c ON c.id = t.category_id WHERE 1=1";
    $params = [];
    if ($search) { $sql .= " AND (t.name LIKE ? OR t.description LIKE ? OR t.tags LIKE ?)"; $p = "%$search%"; $params[] = $p; $params[] = $p; $params[] = $p; }
    if ($cat_filter) {
        $cat = get_category($cat_filter);
        if ($cat) {
            $sql .= " AND t.category_id = ?";
            $params[] = $cat['id'];
        }
    }
    $sql .= " ORDER BY t.name LIMIT 500";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $tools_list = $stmt->fetchAll();
    ?>

    <div class="card">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>ID</th><th>Name</th><th>Category</th><th>Cost</th><th>Access</th><th>Rating</th><th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($tools_list as $t): ?>
                            <tr>
                                <td><?= (int)$t['id'] ?></td>
                                <td>
                                    <img src="<?= h(favicon_url($t)) ?>" alt="" width="16" height="16" onerror="this.src='<?= $BASE ?>/assets/img/default-favicon.svg'">
                                    <a href="<?= $BASE ?>/tool.php?id=<?= (int)$t['id'] ?>" target="_blank"><?= h($t['name']) ?></a>
                                </td>
                                <td><small><?= h($t['cat_name']) ?></small></td>
                                <td><?= cost_badge($t['cost_type']) ?></td>
                                <td><?= access_badge($t['access_type']) ?></td>
                                <td>
                                    <?= render_stars($t['rating_avg']) ?>
                                    <small>(<?= (int)$t['rating_count'] ?>)</small>
                                </td>
                                <td>
                                    <a href="?action=edit&id=<?= (int)$t['id'] ?>" class="btn btn-sm btn-outline-primary"><i class="fas fa-edit"></i></a>
                                    <a href="?action=delete&id=<?= (int)$t['id'] ?>" class="btn btn-sm btn-outline-danger"
                                       onclick="return confirm('Delete this tool?')"><i class="fas fa-trash"></i></a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
