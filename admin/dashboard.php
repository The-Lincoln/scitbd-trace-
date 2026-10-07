<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_admin();

$pdo = db();

// Stats
$stats = [
    'categories' => $pdo->query("SELECT COUNT(*) FROM categories")->fetchColumn(),
    'tools' => $pdo->query("SELECT COUNT(*) FROM tools")->fetchColumn(),
    'users' => $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn(),
    'ratings' => $pdo->query("SELECT COUNT(*) FROM ratings")->fetchColumn(),
    'favorites' => $pdo->query("SELECT COUNT(*) FROM favorites")->fetchColumn(),
];

// Recent additions
$recent = $pdo->query("SELECT t.*, c.name AS cat_name FROM tools t JOIN categories c ON c.id = t.category_id ORDER BY t.created_at DESC LIMIT 10")->fetchAll();

// Top rated
$top = $pdo->query("SELECT * FROM tools WHERE rating_count >= 1 ORDER BY rating_avg DESC, rating_count DESC LIMIT 10")->fetchAll();

$PAGE_TITLE = 'Admin Dashboard';
include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="fas fa-tachometer-alt"></i> Admin Dashboard</h2>
    <a href="<?= $BASE ?>/index.php" class="btn btn-sm btn-outline-secondary"><i class="fas fa-external-link-alt"></i> View Site</a>
</div>

<div class="row g-3 mb-4">
    <?php foreach ($stats as $name => $count): ?>
        <div class="col-md-4 col-lg-2">
            <div class="card stat-card text-center">
                <div class="card-body">
                    <div class="stat-icon mb-2">
                        <i class="fas fa-<?= [
                            'categories' => 'folder', 'tools' => 'wrench', 'users' => 'users',
                            'ratings' => 'star', 'favorites' => 'bookmark'
                        ][$name] ?? 'circle' ?>"></i>
                    </div>
                    <div class="stat-num"><?= number_format((int)$count) ?></div>
                    <div class="stat-label text-muted text-capitalize"><?= h($name) ?></div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<div class="row g-3">
    <div class="col-md-6">
        <div class="card">
            <div class="card-header d-flex justify-content-between">
                <span><i class="fas fa-clock"></i> Recently Added Tools</span>
                <a href="<?= $BASE ?>/admin/tools.php" class="btn btn-sm btn-outline-primary">Manage →</a>
            </div>
            <div class="list-group list-group-flush">
                <?php if (empty($recent)): ?>
                    <div class="list-group-item text-muted">No tools yet</div>
                <?php else: foreach ($recent as $t): ?>
                    <a href="<?= $BASE ?>/admin/tools.php?action=edit&id=<?= (int)$t['id'] ?>" class="list-group-item list-group-item-action">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <strong><?= h($t['name']) ?></strong>
                                <small class="text-muted d-block"><?= h($t['cat_name']) ?></small>
                            </div>
                            <small class="text-muted"><?= h(substr($t['created_at'], 0, 10)) ?></small>
                        </div>
                    </a>
                <?php endforeach; endif; ?>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card">
            <div class="card-header d-flex justify-content-between">
                <span><i class="fas fa-star"></i> Top Rated</span>
                <a href="<?= $BASE ?>/admin/tools.php" class="btn btn-sm btn-outline-primary">Manage →</a>
            </div>
            <div class="list-group list-group-flush">
                <?php if (empty($top)): ?>
                    <div class="list-group-item text-muted">No ratings yet</div>
                <?php else: foreach ($top as $t): ?>
                    <a href="<?= $BASE ?>/tool.php?id=<?= (int)$t['id'] ?>" class="list-group-item list-group-item-action">
                        <div class="d-flex justify-content-between align-items-center">
                            <strong><?= h($t['name']) ?></strong>
                            <span class="badge bg-warning text-dark">
                                <?= render_stars($t['rating_avg']) ?> <?= number_format((float)$t['rating_avg'], 1) ?>
                            </span>
                        </div>
                    </a>
                <?php endforeach; endif; ?>
            </div>
        </div>
    </div>
</div>

<div class="row g-3 mt-1">
    <div class="col-md-12">
        <div class="card">
            <div class="card-header"><i class="fas fa-cogs"></i> Quick Actions</div>
            <div class="card-body d-flex gap-2 flex-wrap">
                <a href="<?= $BASE ?>/admin/tools.php?action=new" class="btn btn-success"><i class="fas fa-plus"></i> Add Tool</a>
                <a href="<?= $BASE ?>/admin/categories.php?action=new" class="btn btn-primary"><i class="fas fa-folder-plus"></i> Add Category</a>
                <a href="<?= $BASE ?>/admin/tools.php" class="btn btn-outline-secondary"><i class="fas fa-list"></i> All Tools</a>
                <a href="<?= $BASE ?>/admin/categories.php" class="btn btn-outline-secondary"><i class="fas fa-folder-tree"></i> All Categories</a>
                <a href="<?= $BASE ?>/admin/db-utils.php" class="btn btn-outline-warning"><i class="fas fa-database"></i> DB Utils</a>
                <a href="<?= $BASE ?>/api-docs.php" class="btn btn-outline-info"><i class="fas fa-code"></i> API Docs</a>
                <a href="<?= $BASE ?>/auth/logout.php" class="btn btn-outline-danger ms-auto"><i class="fas fa-sign-out-alt"></i> Logout</a>
            </div>
        </div>
    </div>
</div>

<style>
.stat-card .stat-icon { font-size: 1.8rem; color: var(--accent); }
.stat-card .stat-num { font-size: 1.8rem; font-weight: 700; }
.stat-card .stat-label { font-size: 0.85rem; }
</style>

<?php include __DIR__ . '/../includes/footer.php'; ?>
