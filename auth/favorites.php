<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

require_login();

$pdo = db();
$favorites = get_user_favorites($_SESSION['user_id']);
$user = current_user();

$PAGE_TITLE = 'My Favorites';
include __DIR__ . '/../includes/header.php';
?>

<div class="row">
    <div class="col-md-12 mb-4">
        <h2><i class="fas fa-bookmark"></i> My Favorites</h2>
        <p class="text-muted">Welcome back, <strong><?= h($user['username']) ?></strong>. You have <?= count($favorites) ?> favorited tool(s).</p>
    </div>
</div>

<?php if (empty($favorites)): ?>
    <div class="empty-state">
        <i class="fas fa-bookmark"></i>
        <h4>No favorites yet</h4>
        <p>Browse the <a href="<?= $BASE ?>/index.php">tools directory</a> and click the bookmark icon to save tools here.</p>
    </div>
<?php else: ?>
    <div class="row g-3">
        <?php foreach ($favorites as $tool):
            $is_fav = true;
            ?>
            <div class="col-md-6 col-lg-4">
                <div class="card tool-card h-100">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start mb-2">
                            <div class="tool-icon">
                                <img src="<?= h(favicon_url($tool)) ?>" alt="" width="24" height="24"
                                     onerror="this.src='<?= $BASE ?>/assets/img/default-favicon.svg'">
                            </div>
                            <div class="tool-actions">
                                <button class="btn btn-sm btn-fav active" data-tool-id="<?= (int)$tool['id'] ?>">
                                    <i class="fas fa-bookmark"></i>
                                </button>
                            </div>
                        </div>
                        <h6 class="card-title tool-name">
                            <a href="<?= h($tool['url']) ?>" target="_blank" rel="noopener" class="text-decoration-none">
                                <?= h($tool['name']) ?> <i class="fas fa-external-link-alt small"></i>
                            </a>
                        </h6>
                        <p class="card-text tool-desc small"><?= h($tool['description']) ?></p>
                        <div class="tool-meta">
                            <?= cost_badge($tool['cost_type']) ?>
                            <?= access_badge($tool['access_type']) ?>
                        </div>
                        <div class="tool-footer mt-2 d-flex justify-content-between align-items-center">
                            <span class="tool-rating">
                                <?= render_stars($tool['rating_avg']) ?>
                                <small class="text-muted">(<?= (int)$tool['rating_count'] ?>)</small>
                            </span>
                            <a href="<?= $BASE ?>/tool.php?id=<?= (int)$tool['id'] ?>" class="btn btn-sm btn-outline-secondary">Details</a>
                        </div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
