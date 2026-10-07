<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';

$tree = get_category_tree();

// Active state
$active_slug = $_GET['cat'] ?? '';
$q = trim($_GET['q'] ?? '');
$view = $_GET['view'] ?? 'hybrid'; // hybrid | grid | tree

// Determine tools to display
$tools = [];
$current_category = null;
$page_title = SITE_NAME;
$page_desc = 'Open Source Intelligence Framework - Find tools for your investigation';

if ($q !== '') {
    $tools = search_tools($q);
    $page_title = "Search: $q";
} elseif ($active_slug) {
    $current_category = get_category($active_slug);
    if ($current_category) {
        $tools = get_tools_by_category($current_category['id']);
        $page_title = $current_category['name'];
        $page_desc = $current_category['description'] ?? '';
    }
}

$PAGE_TITLE = $page_title;
$PAGE_DESC = $page_desc;
include __DIR__ . '/includes/header.php';
?>

<div class="row g-3">
    <!-- Sidebar: Category Tree -->
    <aside class="col-lg-3 col-md-4 d-none d-md-block" id="sidebar-tree">
        <div class="card tree-card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-diagram-3"></i> Categories</span>
                <div class="btn-group btn-group-sm">
                    <button class="btn btn-outline-secondary btn-sm" id="expand-all" title="Expand all"><i class="fas fa-angle-double-down"></i></button>
                    <button class="btn btn-outline-secondary btn-sm" id="collapse-all" title="Collapse all"><i class="fas fa-angle-double-up"></i></button>
                </div>
            </div>
            <div class="card-body p-0">
                <ul class="tree">
                <?php
                $render_tree = function($nodes, $depth = 0) use (&$render_tree, $active_slug) {
                    foreach ($nodes as $node):
                        $has_children = !empty($node['children']);
                        $is_active = $active_slug === $node['slug'];
                        $icon = $node['icon'] ?? 'folder';
                        ?>
                        <li class="tree-node <?= $is_active ? 'active' : '' ?>" data-slug="<?= h($node['slug']) ?>">
                            <div class="tree-label" style="padding-left: <?= $depth * 14 + 8 ?>px">
                                <?php if ($has_children): ?>
                                    <i class="fas fa-chevron-right tree-toggle"></i>
                                <?php else: ?>
                                    <i class="fas fa-circle tree-bullet"></i>
                                <?php endif; ?>
                                <a href="index.php?cat=<?= h($node['slug']) ?>">
                                    <i class="bi bi-<?= h($icon) ?>"></i>
                                    <?= h($node['name']) ?>
                                    <?php if (!empty($node['tool_count'])): ?>
                                        <span class="tree-count"><?= (int)$node['tool_count'] ?></span>
                                    <?php endif; ?>
                                </a>
                            </div>
                            <?php if ($has_children): ?>
                                <ul class="tree-children" style="display: <?= $is_active ? 'block' : 'none' ?>">
                                    <?= $render_tree($node['children'], $depth + 1) ?>
                                </ul>
                            <?php endif; ?>
                        </li>
                    <?php endforeach;
                };
                $render_tree($tree);
                ?>
                </ul>
            </div>
        </div>
    </aside>

    <!-- AI Chat Panel -->
    <aside class="col-lg-3 col-md-4 d-none d-md-block" id="sidebar-chat">
        <div class="card chat-card">
            <div class="card-header bg-primary text-white">
                <span><i class="bi bi-robot"></i> AI Agent</span>
                <div class="btn-group btn-group-sm">
                    <button class="btn btn-sm btn-light" id="chat-tab-osint" title="OSINT Agent" onclick="switchChatTab('osint')">OSINT</button>
                    <button class="btn btn-sm btn-light" id="chat-tab-phpml" title="phpML Chat" onclick="switchChatTab('phpml')"><i class="fas fa-brain"></i> phpML</button>
                    <button class="btn btn-sm btn-light float-end" id="chat-toggle" title="Toggle chat">
                        <i class="fas fa-minus"></i>
                    </button>
                </div>
            </div>
            <div class="card-body p-0" id="chat-body">
                <!-- OSINT Agent Messages -->
                <div class="chat-tab-content" id="chat-tab-osint-content">
                    <div class="chat-messages" id="chat-messages">
                        <div class="chat-msg ai">
                            <div class="chat-bubble">Hello! Ask me about OSINT tools, categories, stats, or anything.</div>
                        </div>
                    </div>
                    <div class="chat-input-area">
                        <input type="text" id="chat-input" placeholder="Type a question..." class="form-control" aria-label="Chat message">
                        <button id="chat-send" class="btn btn-primary" aria-label="Send"><i class="fas fa-paper-plane"></i></button>
                    </div>
                </div>
                
                <!-- phpML Chat Messages -->
                <div class="chat-tab-content" id="chat-tab-phpml-content" style="display:none;">
                    <div class="chat-messages" id="phpml-messages">
                        <div class="chat-msg ai">
                            <div class="chat-bubble"><strong>phpML Chat Engine</strong><br>Welcome! I'm powered by PHP Machine Learning. I can:<br>• Make predictions with Linear Regression<br>• Cluster data with K-Means<br>• Analyze data statistics<br>• Preprocess data<br><br>Try: "predict 5", "analyze 10 20 30", "stats", or "help"</div>
                        </div>
                    </div>
                    <div class="chat-input-area">
                        <input type="text" id="phpml-input" placeholder="Ask phpML..." class="form-control" aria-label="phpML chat message">
                        <button id="phpml-send" class="btn btn-success" aria-label="Send phpML"><i class="fas fa-brain"></i></button>
                    </div>
                </div>
            </div>
        </div>
    </aside>

    <!-- Main content -->
    <section class="col-lg-9 col-md-8" id="main-content">
        <?php if ($q !== ''): ?>
            <div class="alert alert-info">
                <i class="fas fa-search"></i>
                Found <strong><?= count($tools) ?></strong> tool(s) matching <em>"<?= h($q) ?>"</em>
                <a href="index.php" class="btn btn-sm btn-outline-secondary float-end">Clear</a>
            </div>
        <?php elseif ($current_category): ?>
            <div class="category-header mb-3">
                <h2><i class="bi bi-<?= h($current_category['icon']) ?>"></i> <?= h($current_category['name']) ?></h2>
                <?php if (!empty($current_category['description'])): ?>
                    <p class="text-muted"><?= h($current_category['description']) ?></p>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="hero-section mb-4">
                <h1 class="display-5"><i class="bi bi-bug-fill"></i> <?= h(SITE_NAME) ?></h1>
                <p class="lead text-muted">A comprehensive directory of OSINT tools organized by category — find the right tool for your investigation.</p>
                <div class="quick-stats">
                    <span class="badge bg-primary fs-6"><?= count($tree) ?> categories</span>
                    <span class="badge bg-success fs-6"><?= array_sum(array_map(fn($c) => $c['tool_count'], $tree)) ?> tools</span>
                </div>
            </div>
            
            <!-- Godseye Intelligence Dashboard Banner -->
            <div class="mb-4">
                <a href="<?= $BASE ?>/godseye.php" class="text-decoration-none" style="color: inherit;">
                    <div class="card godseye-banner-card mb-3" style="background: linear-gradient(135deg, #0a0a1e, #1a1a3e); border-color: #00ff88;">
                        <div class="card-body p-4">
                            <div class="d-flex align-items-center justify-content-between flex-wrap gap-3">
                                <div>
                                    <h3 class="text-white mb-1"><i class="fas fa-globe text-success me-2"></i>Godseye Intelligence Dashboard</h3>
                                    <p class="text-muted mb-0" style="font-size: 0.9rem;">
                                        Full-screen 3D geospatial intelligence platform • Live aircraft tracking • Satellite orbits • CCTV feeds • Seismic activity • 27+ data layers on CesiumJS globe
                                    </p>
                                </div>
                                <div class="d-flex gap-2 flex-wrap">
                                    <span class="badge bg-success fs-6">3D Globe</span>
                                    <span class="badge bg-info fs-6">Live Tracking</span>
                                    <span class="badge bg-warning fs-6 text-dark">6 Shader Modes</span>
                                    <span class="badge bg-primary fs-6">27+ Layers</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </a>
            </div>
        <?php endif; ?>

        <!-- View toggle -->
        <div class="view-toggle mb-3 d-flex justify-content-between align-items-center">
            <div class="btn-group btn-group-sm" role="group">
                <a class="btn btn-outline-secondary <?= $view === 'hybrid' ? 'active' : '' ?>" href="index.php?<?= http_build_query(array_merge($_GET, ['view' => 'hybrid'])) ?>">
                    <i class="fas fa-columns"></i> Hybrid
                </a>
                <a class="btn btn-outline-secondary <?= $view === 'grid' ? 'active' : '' ?>" href="index.php?<?= http_build_query(array_merge($_GET, ['view' => 'grid'])) ?>">
                    <i class="fas fa-th"></i> Grid
                </a>
                <a class="btn btn-outline-secondary <?= $view === 'tree' ? 'active' : '' ?>" href="index.php?<?= http_build_query(array_merge($_GET, ['view' => 'tree'])) ?>">
                    <i class="fas fa-sitemap"></i> Tree
                </a>
            </div>
            <button class="btn btn-sm btn-outline-secondary d-md-none" id="mobile-tree-toggle">
                <i class="fas fa-bars"></i> Categories
            </button>
        </div>

        <?php if (empty($tools) && !$current_category): ?>
            <!-- Show all categories as cards when nothing selected -->
            <div class="row g-3" id="categories-grid">
                <?php foreach ($tree as $cat): ?>
                    <div class="col-md-6 col-lg-4">
                        <a class="card category-card h-100" href="index.php?cat=<?= h($cat['slug']) ?>">
                            <div class="card-body">
                                <h5 class="card-title">
                                    <i class="bi bi-<?= h($cat['icon']) ?>"></i>
                                    <?= h($cat['name']) ?>
                                </h5>
                                <p class="card-text small text-muted"><?= h($cat['description']) ?></p>
                                <div class="d-flex justify-content-between align-items-center">
                                    <span class="badge bg-secondary"><?= (int)$cat['tool_count'] ?> tools</span>
                                    <span class="text-primary small">Browse →</span>
                                </div>
                            </div>
                        </a>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php elseif (empty($tools)): ?>
            <div class="alert alert-warning">
                <i class="fas fa-info-circle"></i> No tools found in this category yet.
                <?php if (is_admin()): ?>
                    <a href="admin/tools.php?cat=<?= h($active_slug) ?>" class="btn btn-sm btn-primary float-end">Add tool</a>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="row g-3" id="tools-grid">
                <?php foreach ($tools as $tool):
                    $is_fav = is_logged_in() && is_favorited($tool['id']);
                    ?>
                    <div class="col-md-6 col-lg-4 tool-card-wrapper" data-tags="<?= h(strtolower($tool['tags'] ?? '')) ?>">
                        <div class="card tool-card h-100">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-start mb-2">
                                    <div class="tool-icon">
                                        <img src="<?= h(favicon_url($tool)) ?>" alt="" width="24" height="24"
                                             onerror="this.src='assets/img/default-favicon.svg'">
                                    </div>
                                    <div class="tool-actions">
                                        <button class="btn btn-sm btn-fav <?= $is_fav ? 'active' : '' ?>"
                                                data-tool-id="<?= (int)$tool['id'] ?>"
                                                title="<?= is_logged_in() ? 'Toggle favorite' : 'Login to favorite' ?>">
                                            <i class="fas fa-bookmark"></i>
                                        </button>
                                    </div>
                                </div>
                                <h6 class="card-title tool-name">
                                    <a href="<?= h($tool['url']) ?>" target="_blank" rel="noopener" class="stretched-link text-decoration-none">
                                        <?= h($tool['name']) ?>
                                        <i class="fas fa-external-link-alt small"></i>
                                    </a>
                                </h6>
                                <p class="card-text tool-desc small"><?= h($tool['description']) ?></p>
                                <div class="tool-meta">
                                    <?= cost_badge($tool['cost_type']) ?>
                                    <?= access_badge($tool['access_type']) ?>
                                    <?php if ($tool['requires_auth']): ?>
                                        <span class="badge bg-secondary" title="Requires account"><i class="fas fa-user-lock"></i></span>
                                    <?php endif; ?>
                                </div>
                                <div class="tool-footer mt-2 d-flex justify-content-between align-items-center">
                                    <span class="tool-rating">
                                        <?= render_stars($tool['rating_avg']) ?>
                                        <small class="text-muted">(<?= (int)$tool['rating_count'] ?>)</small>
                                    </span>
                                    <a href="tool.php?id=<?= (int)$tool['id'] ?>" class="btn btn-sm btn-outline-secondary details-btn">Details</a>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
</div>

<!-- Mobile sidebar drawer -->
<div class="modal fade" id="mobileTreeModal" tabindex="-1">
    <div class="modal-dialog modal-fullscreen-md-down">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Categories</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="mobile-tree-container"></div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
