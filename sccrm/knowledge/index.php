<?php
session_start();
require_once __DIR__ . '/../includes/header.php';
$search = $_GET['search'] ?? '';
$service_filter = $_GET['service_id'] ?? '';
$tag_filter = $_GET['tag'] ?? '';

$sql = "SELECT a.*, s.name as service_name, s.icon as service_icon,
        (SELECT COUNT(*) FROM faqs WHERE service_id = a.service_id) as faq_count
        FROM knowledge_articles a
        LEFT JOIN services s ON a.service_id = s.id
        WHERE a.status = 'published'";
$params = [];
if ($search) { $sql .= " AND (a.title LIKE ? OR a.content LIKE ? OR a.tags LIKE ?)"; $params = array_fill(0,3,"%$search%"); }
if ($service_filter) { $sql .= " AND a.service_id = ?"; $params[] = $service_filter; }
if ($tag_filter) { $sql .= " AND a.tags LIKE ?"; $params[] = "%$tag_filter%"; }
$sql .= " ORDER BY a.views DESC, a.created_at DESC";
$articles = $db->prepare($sql);
$articles->execute($params);
$articles = $articles->fetchAll();

$services = $db->query("SELECT id, name FROM services WHERE is_active = 1 ORDER BY name")->fetchAll();
$popular_tags = $db->query("SELECT tags FROM knowledge_articles WHERE status='published' AND tags IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN);
$all_tags = [];
foreach ($popular_tags as $t) { $tags_arr = array_map('trim', explode(',', $t)); $all_tags = array_merge($all_tags, $tags_arr); }
$all_tags = array_unique($all_tags);
?>
<div class="page-title-area">
    <div>
        <h4><i class="fas fa-book me-2 text-primary"></i>Knowledge Bank</h4>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="<?= $SCCRM_BASE ?>/index.php" class="text-decoration-none">Dashboard</a></li>
                <li class="breadcrumb-item active">Knowledge Bank</li>
            </ol>
        </nav>
    </div>
    <div class="d-flex gap-2">
        <a href="<?= $SCCRM_BASE ?>/knowledge/create.php" class="btn btn-primary rounded-pill px-3"><i class="fas fa-plus me-1"></i> New Article</a>
        <a href="<?= $SCCRM_BASE ?>/faq/" class="btn btn-outline-info rounded-pill px-3"><i class="fas fa-question-circle me-1"></i> FAQ</a>
    </div>
</div>

<div class="row g-3">
    <div class="col-xl-8">
        <div class="card-crm mb-3">
            <div class="card-header">
                <form method="GET" class="d-flex gap-2 flex-wrap">
                    <div class="input-group input-group-sm" style="width:250px;">
                        <span class="input-group-text bg-transparent"><i class="fas fa-search text-muted"></i></span>
                        <input type="text" name="search" class="form-control" placeholder="Search knowledge base..." value="<?= htmlspecialchars($search) ?>">
                    </div>
                    <select name="service_id" class="form-select form-select-sm" onchange="this.form.submit()" style="width:auto;">
                        <option value="">All Services</option>
                        <?php foreach ($services as $s): ?>
                        <option value="<?= $s['id'] ?>" <?= $service_filter == $s['id'] ? 'selected' : '' ?>><?= htmlspecialchars($s['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php if ($search || $service_filter): ?>
                    <a href="<?= $SCCRM_BASE ?>/knowledge/" class="btn btn-sm btn-outline-secondary">Clear</a>
                    <?php endif; ?>
                </form>
            </div>
            <div class="card-body p-0">
                <?php if (count($articles) > 0): ?>
                    <?php foreach ($articles as $a): ?>
                    <div class="p-3 border-bottom" style="transition:background 0.2s;">
                        <div class="d-flex align-items-start gap-3">
                            <div class="avatar-circle avatar-sm" style="background:<?= avatarColor($a['id']) ?>"><i class="fas fa-book-open"></i></div>
                            <div class="flex-grow-1">
                                <a href="<?= $SCCRM_BASE ?>/knowledge/view.php?id=<?= $a['id'] ?>" class="fw-semibold text-decoration-none" style="font-size:15px;"><?= htmlspecialchars($a['title']) ?></a>
                                <?php if ($a['service_name']): ?>
                                <span class="badge bg-light text-dark ms-2" style="font-size:11px;"><i class="fas <?= htmlspecialchars($a['service_icon'] ?? 'fa-cog') ?> me-1"></i><?= htmlspecialchars($a['service_name']) ?></span>
                                <?php endif; ?>
                                <p class="text-muted mb-1 mt-1" style="font-size:13px;"><?= htmlspecialchars($a['excerpt'] ?? mb_substr($a['content'] ?? '', 0, 150)) ?></p>
                                <div class="d-flex gap-3" style="font-size:12px;color:var(--gray);">
                                    <span><i class="fas fa-eye me-1"></i><?= $a['views'] ?> views</span>
                                    <span><i class="fas fa-clock me-1"></i><?= timeAgo($a['updated_at'] ?? $a['created_at']) ?></span>
                                    <?php if ($a['tags']): $first_tags = array_slice(explode(',', $a['tags']), 0, 3); ?>
                                    <span>
                                        <?php foreach ($first_tags as $t): ?>
                                        <a href="<?= $SCCRM_BASE ?>/knowledge/?tag=<?= urlencode(trim($t)) ?>" class="text-decoration-none badge bg-light text-dark me-1" style="font-size:10px;"><?= htmlspecialchars(trim($t)) ?></a>
                                        <?php endforeach; ?>
                                    </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="d-flex gap-1">
                                <a href="<?= $SCCRM_BASE ?>/knowledge/edit.php?id=<?= $a['id'] ?>" class="btn btn-action btn-outline-primary btn-sm" data-bs-toggle="tooltip" title="Edit"><i class="fas fa-edit"></i></a>
                                <a href="<?= $SCCRM_BASE ?>/knowledge/delete.php?id=<?= $a['id'] ?>" class="btn btn-action btn-outline-danger btn-sm" data-confirm="Delete this article?" data-bs-toggle="tooltip" title="Delete"><i class="fas fa-trash"></i></a>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-book"></i><h6>No articles found</h6>
                    <p class="text-muted"><?= $search ? 'Try a different search.' : 'Create your first knowledge base article.' ?></p>
                    <a href="<?= $SCCRM_BASE ?>/knowledge/create.php" class="btn btn-primary btn-sm mt-2">Write Article</a>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-xl-4">
        <div class="card-crm mb-3">
            <div class="card-header"><h6><i class="fas fa-tags me-2"></i>Popular Topics</h6></div>
            <div class="card-body">
                <?php if (count($all_tags) > 0): ?>
                <div class="d-flex flex-wrap gap-2">
                    <?php foreach (array_slice($all_tags, 0, 20) as $tag): ?>
                    <a href="<?= $SCCRM_BASE ?>/knowledge/?tag=<?= urlencode($tag) ?>" class="badge bg-light text-dark text-decoration-none px-3 py-2 <?= $tag_filter === $tag ? 'border border-primary' : '' ?>" style="font-size:12px;"><?= htmlspecialchars(trim($tag)) ?></a>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                <p class="text-muted mb-0" style="font-size:13px;">No tags yet.</p>
                <?php endif; ?>
            </div>
        </div>
        <div class="card-crm">
            <div class="card-header"><h6><i class="fas fa-layer-group me-2"></i>By Service</h6></div>
            <div class="card-body p-0">
                <?php
                $svc_counts = $db->query("SELECT s.id, s.name, s.icon, COUNT(a.id) as cnt FROM services s LEFT JOIN knowledge_articles a ON a.service_id = s.id AND a.status='published' WHERE s.is_active=1 GROUP BY s.id ORDER BY cnt DESC")->fetchAll();
                ?>
                <?php if (count($svc_counts) > 0): ?>
                    <?php foreach ($svc_counts as $sc): ?>
                    <a href="<?= $SCCRM_BASE ?>/knowledge/?service_id=<?= $sc['id'] ?>" class="d-flex align-items-center justify-content-between px-3 py-2 text-decoration-none border-bottom" style="font-size:13px;color:var(--dark);transition:background 0.2s;" onmouseover="this.style.background='#f8f9fa'" onmouseout="this.style.background=''">
                        <span><i class="fas <?= htmlspecialchars($sc['icon'] ?? 'fa-cog') ?> text-primary me-2" style="width:18px;"></i><?= htmlspecialchars($sc['name']) ?></span>
                        <span class="badge bg-light text-dark"><?= $sc['cnt'] ?></span>
                    </a>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
