<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_admin();

$pdo = db();
$action = $_GET['action'] ?? 'list';
$id = (int)($_GET['id'] ?? 0);

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $slug = trim($_POST['slug'] ?? '');
    $icon = trim($_POST['icon'] ?? 'folder');
    $description = trim($_POST['description'] ?? '');
    $parent_id = (int)($_POST['parent_id'] ?? 0) ?: null;
    $sort_order = (int)($_POST['sort_order'] ?? 0);

    if ($name && $slug) {
        if ($action === 'create' || $id === 0) {
            $pdo->prepare("INSERT INTO categories (parent_id, name, slug, icon, description, sort_order) VALUES (?, ?, ?, ?, ?, ?)")
                ->execute([$parent_id, $name, $slug, $icon, $description, $sort_order]);
            $_SESSION['flash'] = "Category created.";
        } else {
            $pdo->prepare("UPDATE categories SET parent_id=?, name=?, slug=?, icon=?, description=?, sort_order=? WHERE id=?")
                ->execute([$parent_id, $name, $slug, $icon, $description, $sort_order, $id]);
            $_SESSION['flash'] = "Category updated.";
        }
    }
    header('Location: ' . $BASE . '/admin/categories.php');
    exit;
}

// Handle delete
if ($action === 'delete' && $id) {
    $pdo->prepare("DELETE FROM categories WHERE id = ?")->execute([$id]);
    $_SESSION['flash'] = "Category deleted.";
    header('Location: ' . $BASE . '/admin/categories.php');
    exit;
}

$PAGE_TITLE = 'Manage Categories';
include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h2><i class="fas fa-folder-tree"></i> Categories</h2>
    <a href="?action=new" class="btn btn-success"><i class="fas fa-plus"></i> New Category</a>
</div>

<?php if ($flash = $_SESSION['flash'] ?? ''): unset($_SESSION['flash']); ?>
    <div class="alert alert-success"><?= h($flash) ?></div>
<?php endif; ?>

<?php if ($action === 'new' || $action === 'edit'):
    $cat = ['id' => 0, 'parent_id' => '', 'name' => '', 'slug' => '', 'icon' => 'folder', 'description' => '', 'sort_order' => 0];
    if ($id) {
        $stmt = $pdo->prepare("SELECT * FROM categories WHERE id = ?");
        $stmt->execute([$id]);
        $cat = $stmt->fetch() ?: $cat;
    }
    $all = $pdo->query("SELECT * FROM categories ORDER BY name")->fetchAll();
?>
    <div class="card">
        <div class="card-header">
            <?= $id ? 'Edit Category' : 'New Category' ?>
        </div>
        <div class="card-body">
            <form method="post" action="?action=<?= $id ? 'edit&id=' . $id : 'create' ?>">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Name *</label>
                        <input type="text" name="name" class="form-control" required value="<?= h($cat['name']) ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Slug *</label>
                        <input type="text" name="slug" class="form-control" required value="<?= h($cat['slug']) ?>" placeholder="unique-identifier">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Parent Category</label>
                        <select name="parent_id" class="form-select">
                            <option value="">— None (root) —</option>
                            <?php foreach ($all as $c): ?>
                                <?php if ($c['id'] == $id) continue; ?>
                                <option value="<?= (int)$c['id'] ?>" <?= $cat['parent_id'] == $c['id'] ? 'selected' : '' ?>>
                                    <?= h($c['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Icon</label>
                        <input type="text" name="icon" class="form-control" value="<?= h($cat['icon']) ?>" placeholder="bi-*">
                        <small class="text-muted">Bootstrap Icons name (e.g. person, globe)</small>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Sort Order</label>
                        <input type="number" name="sort_order" class="form-control" value="<?= (int)$cat['sort_order'] ?>">
                    </div>
                    <div class="col-12">
                        <label class="form-label">Description</label>
                        <textarea name="description" class="form-control" rows="2"><?= h($cat['description']) ?></textarea>
                    </div>
                    <div class="col-12">
                        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save</button>
                        <a href="<?= $BASE ?>/admin/categories.php" class="btn btn-outline-secondary">Cancel</a>
                    </div>
                </div>
            </form>
        </div>
    </div>
<?php else: ?>
    <div class="card">
        <div class="card-body">
            <table class="table table-hover">
                <thead>
                    <tr>
                        <th>ID</th><th>Name</th><th>Slug</th><th>Parent</th><th>Tools</th><th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php
                $tree = get_category_tree(true);
                $render = function($nodes, $depth = 0) use (&$render, $pdo, $BASE) {
                    foreach ($nodes as $c):
                        $parent_name = '';
                        if ($c['parent_id']) {
                            $ps = $pdo->prepare("SELECT name FROM categories WHERE id = ?");
                            $ps->execute([$c['parent_id']]);
                            $parent_name = $ps->fetchColumn();
                        }
                    ?>
                    <tr>
                        <td><?= (int)$c['id'] ?></td>
                        <td>
                            <span style="padding-left: <?= $depth * 16 ?>px">↳</span>
                            <i class="bi bi-<?= h($c['icon']) ?>"></i>
                            <strong><?= h($c['name']) ?></strong>
                        </td>
                        <td><code><?= h($c['slug']) ?></code></td>
                        <td><?= h($parent_name) ?></td>
                        <td><span class="badge bg-secondary"><?= (int)($c['tool_count'] ?? 0) ?></span></td>
                        <td>
                            <a href="?action=edit&id=<?= (int)$c['id'] ?>" class="btn btn-sm btn-outline-primary"><i class="fas fa-edit"></i></a>
                            <a href="?action=delete&id=<?= (int)$c['id'] ?>" class="btn btn-sm btn-outline-danger"
                               onclick="return confirm('Delete this category and all its tools?')"><i class="fas fa-trash"></i></a>
                        </td>
                    </tr>
                    <?php
                    if (!empty($c['children'])) $render($c['children'], $depth + 1);
                    endforeach;
                };
                $render($tree);
                ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
