<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';

$id = (int)($_GET['id'] ?? 0);
$tool = get_tool($id);
if (!$tool) {
    http_response_code(404);
    $PAGE_TITLE = 'Tool not found';
    include __DIR__ . '/includes/header.php';
    echo '<div class="alert alert-danger">Tool not found.</div>';
    include __DIR__ . '/includes/footer.php';
    exit;
}

// Get ratings + reviews
$pdo = db();
$rstmt = $pdo->prepare("SELECT r.*, u.username FROM ratings r JOIN users u ON u.id = r.user_id WHERE r.tool_id = ? ORDER BY r.created_at DESC LIMIT 20");
$rstmt->execute([$id]);
$ratings = $rstmt->fetchAll();

// Get current user's existing rating (if any)
$user_rating = null;
if (is_logged_in()) {
    $ustmt = $pdo->prepare("SELECT * FROM ratings WHERE user_id = ? AND tool_id = ?");
    $ustmt->execute([(int)$_SESSION['user_id'], $id]);
    $user_rating = $ustmt->fetch();
}

// Category breadcrumb
$breadcrumb = [];
$cur = $pdo->prepare("SELECT * FROM categories WHERE id = ?");
$cur_cat = $tool;
while ($cur_cat && isset($cur_cat['category_id'])) {
    $cur->execute([$cur_cat['category_id']]);
    $c = $cur->fetch();
    if (!$c) break;
    $breadcrumb[] = $c;
    $cur_cat = $c;
}
$breadcrumb = array_reverse($breadcrumb);

$PAGE_TITLE = $tool['name'];
$PAGE_DESC = $tool['description'];
include __DIR__ . '/includes/header.php';
?>

<nav aria-label="breadcrumb" class="mb-3">
    <ol class="breadcrumb">
        <li class="breadcrumb-item"><a href="<?= $BASE ?>/index.php">Home</a></li>
        <?php foreach ($breadcrumb as $b): ?>
            <li class="breadcrumb-item"><a href="<?= $BASE ?>/index.php?cat=<?= h($b['slug']) ?>"><?= h($b['name']) ?></a></li>
        <?php endforeach; ?>
        <li class="breadcrumb-item active"><?= h($tool['name']) ?></li>
    </ol>
</nav>

<div class="row g-4">
    <div class="col-lg-8">
        <div class="card">
            <div class="card-body">
                <div class="d-flex align-items-center gap-3 mb-3">
                    <img src="<?= h(favicon_url($tool)) ?>" alt="" width="48" height="48"
                         onerror="this.src='<?= $BASE ?>/assets/img/default-favicon.svg'">
                    <div>
                        <h1 class="h3 mb-0"><?= h($tool['name']) ?></h1>
                        <a href="<?= h($tool['url']) ?>" target="_blank" rel="noopener" class="text-decoration-none">
                            <i class="fas fa-external-link-alt"></i> <?= h($tool['url']) ?>
                        </a>
                    </div>
                </div>

                <p class="lead"><?= h($tool['description']) ?></p>

                <div class="tool-meta d-flex flex-wrap gap-2 mb-3">
                    <?= cost_badge($tool['cost_type']) ?>
                    <?= access_badge($tool['access_type']) ?>
                    <?php if ($tool['requires_auth']): ?>
                        <span class="badge bg-secondary"><i class="fas fa-user-lock"></i> Requires account</span>
                    <?php endif; ?>
                </div>

                <?php if (!empty($tool['tags'])): ?>
                    <div class="mb-3">
                        <strong>Tags:</strong>
                        <?php foreach (explode(',', $tool['tags']) as $tag): ?>
                            <a href="<?= $BASE ?>/index.php?q=<?= urlencode(trim($tag)) ?>" class="badge bg-light text-dark">#<?= h(trim($tag)) ?></a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <div class="d-flex gap-2">
                    <a href="<?= h($tool['url']) ?>" target="_blank" rel="noopener" class="btn btn-primary">
                        <i class="fas fa-external-link-alt"></i> Visit Tool
                    </a>
                    <?php if (is_logged_in()): ?>
                        <button class="btn btn-outline-primary btn-fav <?= is_favorited($tool['id']) ? 'active' : '' ?>"
                                data-tool-id="<?= (int)$tool['id'] ?>">
                            <i class="fas fa-bookmark"></i>
                            <span><?= is_favorited($tool['id']) ? 'Favorited' : 'Add to Favorites' ?></span>
                        </button>
                    <?php else: ?>
                        <a href="<?= $BASE ?>/auth/login.php" class="btn btn-outline-secondary">
                            <i class="fas fa-sign-in-alt"></i> Login to favorite
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Ratings & Reviews -->
        <div class="card mt-4">
            <div class="card-header">
                <i class="fas fa-star"></i> Reviews (<?= count($ratings) ?>)
            </div>
            <div class="card-body">
                <?php if (is_logged_in()): ?>
                    <form id="rating-form" class="mb-4">
                        <input type="hidden" name="tool_id" value="<?= (int)$tool['id'] ?>">
                        <div class="mb-2">
                            <label class="form-label">Your rating:</label>
                            <div class="rating-input">
                                <?php for ($i = 5; $i >= 1; $i--): ?>
                                    <input type="radio" name="rating" id="star<?= $i ?>" value="<?= $i ?>" <?= (isset($user_rating) && $user_rating['rating'] == $i) ? 'checked' : '' ?>>
                                    <label for="star<?= $i ?>"><i class="fas fa-star"></i></label>
                                <?php endfor; ?>
                            </div>
                        </div>
                        <div class="mb-2">
                            <textarea name="review" class="form-control" placeholder="Write a review (optional)..." rows="2"></textarea>
                        </div>
                        <button type="submit" class="btn btn-sm btn-primary">Submit</button>
                    </form>
                <?php else: ?>
                    <div class="alert alert-info">
                        <a href="<?= $BASE ?>/auth/login.php">Login</a> to leave a rating and review.
                    </div>
                <?php endif; ?>

                <div id="reviews-list">
                    <?php if (empty($ratings)): ?>
                        <p class="text-muted">No reviews yet. Be the first!</p>
                    <?php else: foreach ($ratings as $r): ?>
                        <div class="review-item border-bottom pb-2 mb-2">
                            <div class="d-flex justify-content-between">
                                <strong><?= h($r['username']) ?></strong>
                                <span class="text-muted small"><?= h($r['created_at']) ?></span>
                            </div>
                            <div class="mb-1"><?= render_stars($r['rating']) ?></div>
                            <?php if (!empty($r['review'])): ?>
                                <p class="mb-0"><?= nl2br(h($r['review'])) ?></p>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="card">
            <div class="card-header"><i class="fas fa-chart-bar"></i> Stats</div>
            <div class="card-body">
                <div class="d-flex justify-content-between mb-2">
                    <span>Average rating:</span>
                    <strong><?= render_stars($tool['rating_avg']) ?> <?= number_format((float)$tool['rating_avg'], 1) ?></strong>
                </div>
                <div class="d-flex justify-content-between mb-2">
                    <span>Total ratings:</span>
                    <strong><?= (int)$tool['rating_count'] ?></strong>
                </div>
                <div class="d-flex justify-content-between mb-2">
                    <span>Added:</span>
                    <strong><?= h(substr($tool['created_at'], 0, 10)) ?></strong>
                </div>
                <div class="d-flex justify-content-between">
                    <span>Updated:</span>
                    <strong><?= h(substr($tool['updated_at'], 0, 10)) ?></strong>
                </div>
            </div>
        </div>

        <?php
        // Related tools (same category)
        $related = $pdo->prepare("SELECT * FROM tools WHERE category_id = ? AND id != ? ORDER BY RANDOM() LIMIT 5");
        $related->execute([$tool['category_id'], $tool['id']]);
        $related_tools = $related->fetchAll();
        ?>
        <?php if ($related_tools): ?>
        <div class="card mt-3">
            <div class="card-header"><i class="fas fa-link"></i> Related Tools</div>
            <div class="list-group list-group-flush">
                <?php foreach ($related_tools as $rel): ?>
                    <a href="<?= $BASE ?>/tool.php?id=<?= (int)$rel['id'] ?>" class="list-group-item list-group-item-action">
                        <div class="d-flex align-items-center gap-2">
                            <img src="<?= h(favicon_url($rel)) ?>" alt="" width="16" height="16" onerror="this.src='<?= $BASE ?>/assets/img/default-favicon.svg'">
                            <span><?= h($rel['name']) ?></span>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
