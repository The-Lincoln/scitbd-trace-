<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
$_sccrm_db_boot = require_once __DIR__ . '/../config/database.php';
if (($_sccrm_db_boot instanceof PDO) && (!isset($db) || !($db instanceof PDO))) { $db = $_sccrm_db_boot; }
$id = $_GET['id'] ?? 0;
$a = $db->prepare("SELECT a.*, s.name as service_name, s.icon as service_icon, s.category as service_category FROM knowledge_articles a LEFT JOIN services s ON a.service_id = s.id WHERE a.id = ?");
$a->execute([$id]); $a = $a->fetch();
if (!$a) { header('Location: index.php'); exit; }

$db->prepare("UPDATE knowledge_articles SET views = views + 1 WHERE id = ?")->execute([$id]);

$related = $db->prepare("SELECT id, title FROM knowledge_articles WHERE service_id = ? AND id != ? AND status='published' ORDER BY views DESC LIMIT 5");
$related->execute([$a['service_id'], $id]);
$related = $related->fetchAll();

$service_faqs = $db->prepare("SELECT f.*, fc.name as cat_name FROM faqs f LEFT JOIN faq_categories fc ON f.category_id = fc.id WHERE f.service_id = ? AND f.is_published = 1 ORDER BY f.sort_order LIMIT 5");
$service_faqs->execute([$a['service_id']]);
$service_faqs = $service_faqs->fetchAll();

$tags = $a['tags'] ? array_map('trim', explode(',', $a['tags'])) : [];

// --- Clarity prose modes (rewrite/review/lint on this article) ---
require_once __DIR__ . '/../ai/clarity_agent.php';
$clarityModes = clarityAgentModes();
$clarityResult = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['clarity_mode'])) {
    $cmode = clarityAgentMode((string)$_POST['clarity_mode']);
    if ($cmode === 'lint') {
        $lr = clarityAgentLint((string)($a['content'] ?? ''));
        $clarityResult = ['mode' => 'lint', 'ok' => !empty($lr['ok']),
            'content' => !empty($lr['ok'])
                ? "Diagnostics (read hits in context — not verdicts):\n" . implode("\n", array_map(
                    fn($k, $v) => '- ' . $k . ': ' . (is_array($v) ? json_encode($v) : $v),
                    array_keys($lr['stats']), array_values($lr['stats'])))
                : ('Lint failed: ' . ($lr['error'] ?? '?'))];
    } else {
        $rr = clarityAgentRun($db, $cmode, (string)($a['content'] ?? ''), ['register' => 'knowledge article' . ($a['service_name'] ? ' for ' . $a['service_name'] : '')]);
        $clarityResult = ['mode' => $cmode, 'ok' => !empty($rr['ok']),
            'content' => !empty($rr['ok']) ? $rr['content'] : ('Clarity failed: ' . ($rr['error'] ?? '?'))];
    }
}
$savedDraftId = 0;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['clarity_save']) && isset($_POST['clarity_content'])) {
    $nc = trim((string)$_POST['clarity_content']);
    if ($nc !== '') {
        try {
            $db->prepare("INSERT INTO knowledge_articles (service_id, title, content, excerpt, tags, status, created_by) VALUES (?,?,?,?,?,'draft','Clarity')")
                ->execute([$a['service_id'] ?: null, 'Clarity rewrite: ' . mb_substr($a['title'] ?? 'untitled', 0, 90), $nc, mb_substr(trim(preg_replace('/[#>*`]/', '', $nc)), 0, 160), trim((string)($a['tags'] ?? ''))]);
            $savedDraftId = (int)$db->lastInsertId();
        } catch (Throwable $e) {
            $clarityResult = ['mode' => 'rewrite', 'ok' => false, 'content' => 'Save failed: ' . $e->getMessage()];
        }
    }
}
require_once __DIR__ . '/../includes/header.php';
?>
<div class="page-title-area">
    <div>
        <h4><?= htmlspecialchars($a['title']) ?></h4>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="<?= $SCCRM_BASE ?>/index.php" class="text-decoration-none">Dashboard</a></li>
                <li class="breadcrumb-item"><a href="<?= $SCCRM_BASE ?>/knowledge/" class="text-decoration-none">Knowledge Bank</a></li>
                <li class="breadcrumb-item active"><?= htmlspecialchars(mb_substr($a['title'], 0, 50)) ?></li>
            </ol>
        </nav>
    </div>
    <div class="d-flex gap-2">
        <a href="<?= $SCCRM_BASE ?>/knowledge/edit.php?id=<?= $id ?>" class="btn btn-primary rounded-pill px-3"><i class="fas fa-edit me-1"></i> Edit</a>
        <a href="<?= $SCCRM_BASE ?>/knowledge/" class="btn btn-outline-secondary rounded-pill px-3"><i class="fas fa-arrow-left me-1"></i> Back</a>
    </div>
</div>

<div class="row g-3">
    <div class="col-xl-8">
        <div class="card-crm">
            <div class="card-header d-flex justify-content-between align-items-center">
                <div>
                    <?php if ($a['service_name']): ?>
                    <span class="badge bg-light text-dark me-2"><i class="fas <?= htmlspecialchars($a['service_icon'] ?? 'fa-cog') ?> me-1"></i><?= htmlspecialchars($a['service_name']) ?></span>
                    <?php endif; ?>
                    <span style="font-size:12px;color:var(--gray);"><i class="fas fa-eye me-1"></i><?= $a['views'] ?> views</span>
                    <span style="font-size:12px;color:var(--gray);" class="ms-2"><i class="fas fa-clock me-1"></i>Updated <?= timeAgo($a['updated_at'] ?? $a['created_at']) ?></span>
                </div>
            </div>
            <div class="card-body">
                <div style="font-size:15px;line-height:1.8;color:#2c3e50;">
                    <?= nl2br(htmlspecialchars($a['content'] ?? '')) ?>
                </div>
                <?php if (count($tags) > 0): ?>
                <hr>
                <div class="d-flex flex-wrap gap-1">
                    <?php foreach ($tags as $tag): ?>
                    <a href="<?= $SCCRM_BASE ?>/knowledge/?tag=<?= urlencode($tag) ?>" class="badge bg-light text-dark text-decoration-none px-3 py-2" style="font-size:12px;"><?= htmlspecialchars($tag) ?></a>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
                <hr>
                <h6><i class="fas fa-pen-nib me-1 text-primary"></i>Clarity <small class="text-muted">rewrite · review · lint (never invents facts)</small></h6>
                <form method="POST" class="d-flex flex-wrap gap-2 mb-2">
                    <?php foreach (['rewrite' => 'Rewrite', 'review' => 'Review', 'lint' => 'Lint'] as $m => $label): ?>
                    <button name="clarity_mode" value="<?= $m ?>" class="btn btn-sm btn-outline-primary rounded-pill"><?= $label ?></button>
                    <?php endforeach; ?>
                </form>
                <?php if ($savedDraftId): ?><div class="alert alert-success py-2 small">Saved as draft #<?= $savedDraftId ?>.</div><?php endif; ?>
                <?php if ($clarityResult): ?>
                <div class="alert alert-<?= $clarityResult['ok'] ? 'info' : 'danger' ?> small" style="white-space:pre-wrap;"><?= htmlspecialchars(mb_substr($clarityResult['content'] ?? '', 0, 3000)) ?></div>
                <?php if ($clarityResult['ok'] && ($clarityResult['mode'] ?? '') === 'rewrite'): ?>
                <form method="POST">
                    <input type="hidden" name="clarity_save" value="1">
                    <input type="hidden" name="clarity_content" value="<?= htmlspecialchars($clarityResult['content'] ?? '') ?>">
                    <button class="btn btn-sm btn-success rounded-pill"><i class="fas fa-save me-1"></i> Save rewrite as new draft</button>
                </form>
                <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-xl-4">
        <?php if (count($related) > 0): ?>
        <div class="card-crm mb-3">
            <div class="card-header"><h6><i class="fas fa-link me-2"></i>Related Articles</h6></div>
            <div class="card-body p-0">
                <?php foreach ($related as $r): ?>
                <a href="<?= $SCCRM_BASE ?>/knowledge/view.php?id=<?= $r['id'] ?>" class="d-block px-3 py-2 text-decoration-none border-bottom" style="font-size:13px;color:var(--dark);"><?= htmlspecialchars($r['title']) ?></a>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
        <?php if (count($service_faqs) > 0): ?>
        <div class="card-crm mb-3">
            <div class="card-header"><h6><i class="fas fa-question-circle me-2 text-info"></i>Related FAQs</h6></div>
            <div class="card-body p-0">
                <?php foreach ($service_faqs as $f): ?>
                <div class="px-3 py-2 border-bottom">
                    <div style="font-size:13px;font-weight:500;color:var(--dark);"><?= htmlspecialchars($f['question']) ?></div>
                    <div style="font-size:12px;color:#666;margin-top:2px;"><?= htmlspecialchars(mb_substr($f['answer'], 0, 100)) ?>...</div>
                </div>
                <?php endforeach; ?>
                <a href="<?= $SCCRM_BASE ?>/faq/?service_id=<?= $a['service_id'] ?>" class="d-block text-center py-2 text-decoration-none" style="font-size:13px;">View all FAQs <i class="fas fa-arrow-right ms-1"></i></a>
            </div>
        </div>
        <?php endif; ?>
        <div class="card-crm">
            <div class="card-header"><h6><i class="fas fa-headset me-2 text-success"></i>Need Help?</h6></div>
            <div class="card-body text-center">
                <p style="font-size:13px;" class="text-muted">Have questions about this topic?</p>
                <a href="<?= $SCCRM_BASE ?>/chat/create.php" class="btn btn-success btn-sm rounded-pill px-4"><i class="fas fa-ticket me-1"></i> Open Support Ticket</a>
            </div>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
