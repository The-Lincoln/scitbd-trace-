<?php
session_start();
require_once __DIR__ . '/../includes/header.php';
$cat_filter = $_GET['category_id'] ?? '';
$service_filter = $_GET['service_id'] ?? '';
$search = $_GET['search'] ?? '';

$categories = $db->query("SELECT fc.*, (SELECT COUNT(*) FROM faqs WHERE category_id = fc.id AND is_published=1) as cnt FROM faq_categories fc ORDER BY fc.sort_order")->fetchAll();
$services = $db->query("SELECT id, name FROM services WHERE is_active = 1 ORDER BY name")->fetchAll();

$sql = "SELECT f.*, fc.name as cat_name, fc.icon as cat_icon, s.name as service_name FROM faqs f LEFT JOIN faq_categories fc ON f.category_id = fc.id LEFT JOIN services s ON f.service_id = s.id WHERE f.is_published = 1";
$params = [];
if ($cat_filter) { $sql .= " AND f.category_id = ?"; $params[] = $cat_filter; }
if ($service_filter) { $sql .= " AND f.service_id = ?"; $params[] = $service_filter; }
if ($search) { $sql .= " AND (f.question LIKE ? OR f.answer LIKE ?)"; $params = array_merge($params, ["%$search%","%$search%"]); }
$sql .= " ORDER BY fc.sort_order, f.sort_order";
$faqs = $db->prepare($sql);
$faqs->execute($params);
$faqs = $faqs->fetchAll();

$selected_cat = null;
if ($cat_filter) { $sc = $db->prepare("SELECT * FROM faq_categories WHERE id = ?"); $sc->execute([$cat_filter]); $selected_cat = $sc->fetch(); }
?>
<div class="page-title-area">
    <div>
        <h4><i class="fas fa-question-circle me-2 text-info"></i>Frequently Asked Questions</h4>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="<?= $SCCRM_BASE ?>/index.php" class="text-decoration-none">Dashboard</a></li>
                <li class="breadcrumb-item active">FAQ</li>
            </ol>
        </nav>
    </div>
    <div class="d-flex gap-2">
        <a href="<?= $SCCRM_BASE ?>/faq/admin.php" class="btn btn-primary rounded-pill px-3"><i class="fas fa-cog me-1"></i> Manage FAQ</a>
        <a href="<?= $SCCRM_BASE ?>/knowledge/" class="btn btn-outline-secondary rounded-pill px-3"><i class="fas fa-book me-1"></i> Knowledge Bank</a>
    </div>
</div>

<div class="row g-3">
    <div class="col-xl-3">
        <div class="card-crm">
            <div class="card-header"><h6>Categories</h6></div>
            <div class="card-body p-0">
                <a href="<?= $SCCRM_BASE ?>/faq/" class="d-flex align-items-center justify-content-between px-3 py-2 text-decoration-none border-bottom <?= !$cat_filter && !$service_filter ? 'fw-semibold text-primary' : '' ?>" style="font-size:13px;color:var(--dark);">
                    <span><i class="fas fa-list me-2"></i>All Questions</span>
                    <span class="badge bg-light text-dark"><?= array_sum(array_column($categories, 'cnt')) ?></span>
                </a>
                <?php foreach ($categories as $cat): ?>
                <a href="<?= $SCCRM_BASE ?>/faq/?category_id=<?= $cat['id'] ?>" class="d-flex align-items-center justify-content-between px-3 py-2 text-decoration-none border-bottom <?= $cat_filter == $cat['id'] ? 'fw-semibold text-primary' : '' ?>" style="font-size:13px;color:var(--dark);">
                    <span><i class="fas <?= htmlspecialchars($cat['icon'] ?? 'fa-folder') ?> me-2 text-muted"></i><?= htmlspecialchars($cat['name']) ?></span>
                    <span class="badge bg-light text-dark"><?= $cat['cnt'] ?></span>
                </a>
                <?php endforeach; ?>
                <hr class="my-1">
                <div class="px-3 py-2" style="font-size:12px;color:var(--gray);font-weight:600;text-transform:uppercase;letter-spacing:0.5px;">Filter by Service</div>
                <?php foreach ($services as $s): ?>
                <a href="<?= $SCCRM_BASE ?>/faq/?service_id=<?= $s['id'] ?>" class="d-block px-3 py-2 text-decoration-none border-bottom <?= $service_filter == $s['id'] ? 'fw-semibold text-primary' : '' ?>" style="font-size:13px;color:var(--dark);">
                    <i class="fas fa-angle-right me-2 text-muted"></i><?= htmlspecialchars($s['name']) ?>
                </a>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <div class="col-xl-9">
        <div class="card-crm">
            <div class="card-header">
                <form method="GET" class="d-flex gap-2">
                    <div class="input-group input-group-sm flex-grow-1">
                        <span class="input-group-text bg-transparent"><i class="fas fa-search text-muted"></i></span>
                        <input type="text" name="search" class="form-control" placeholder="Search FAQ..." value="<?= htmlspecialchars($search) ?>">
                    </div>
                    <?php if ($search || $cat_filter || $service_filter): ?>
                    <a href="<?= $SCCRM_BASE ?>/faq/" class="btn btn-sm btn-outline-secondary">Clear</a>
                    <?php endif; ?>
                </form>
            </div>
            <div class="card-body p-0">
                <?php if (count($faqs) > 0): ?>
                    <div class="accordion accordion-flush" id="faqAccordion">
                        <?php foreach ($faqs as $i => $f): ?>
                        <div class="accordion-item">
                            <h2 class="accordion-header">
                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq<?= $f['id'] ?>">
                                    <div>
                                        <span class="fw-semibold" style="font-size:14px;"><?= htmlspecialchars($f['question']) ?></span>
                                        <?php if ($f['cat_name']): ?>
                                        <small class="text-muted ms-2"><i class="fas <?= htmlspecialchars($f['cat_icon'] ?? 'fa-folder') ?> me-1"></i><?= htmlspecialchars($f['cat_name']) ?></small>
                                        <?php endif; ?>
                                    </div>
                                </button>
                            </h2>
                            <div id="faq<?= $f['id'] ?>" class="accordion-collapse collapse" data-bs-parent="#faqAccordion">
                                <div class="accordion-body" style="font-size:14px;line-height:1.7;color:#444;">
                                    <?= nl2br(htmlspecialchars($f['answer'])) ?>
                                    <?php if ($f['service_name']): ?>
                                    <hr><small class="text-muted"><i class="fas fa-tag me-1"></i>Related service: <?= htmlspecialchars($f['service_name']) ?></small>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                <div class="empty-state"><i class="fas fa-question-circle"></i><h6>No FAQs found</h6></div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
