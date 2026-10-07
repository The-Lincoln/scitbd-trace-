<?php
// SCCRM > Agentic Skills — OWASP Agentic Skills Top-10 reference,
// each mapped to how this application mitigates it.
if (session_status() === PHP_SESSION_NONE) { session_start(); }
$_sccrm_db_boot = require_once __DIR__ . '/../config/database.php';
if (($_sccrm_db_boot instanceof PDO) && (!isset($db) || !($db instanceof PDO))) { $db = $_sccrm_db_boot; }
require_once __DIR__ . '/agentic_skills.php';
$skills = agenticSkills();

require_once __DIR__ . '/../includes/header.php';

function agentic_sev_badge($s) {
    $s = strtolower($s);
    $c = str_contains($s, 'critical') ? 'danger' : (str_contains($s, 'high') ? 'warning' : 'info');
    return '<span class="badge bg-' . $c . '">' . htmlspecialchars($s) . '</span>';
}
?>
<div class="page-title-area">
    <div>
        <h4><i class="fas fa-shield-virus me-2 text-warning"></i>Agentic Skills Top-10 <small class="text-muted">(<?= count($skills) ?> skills)</small></h4>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="<?= htmlspecialchars($SCCRM_BASE) ?>/index.php" class="text-decoration-none">Dashboard</a></li>
                <li class="breadcrumb-item"><a href="<?= htmlspecialchars($SCCRM_BASE) ?>/ai/" class="text-decoration-none">AI Chat</a></li>
                <li class="breadcrumb-item active">Agentic Skills</li>
            </ol>
        </nav>
    </div>
    <div class="d-flex gap-2">
        <a href="<?= htmlspecialchars($SCCRM_BASE) ?>/ai/" class="btn btn-outline-primary btn-sm rounded-pill"><i class="fas fa-robot me-1"></i> AI Chat</a>
        <a href="https://github.com/OWASP/www-project-agentic-skills-top-10" target="_blank" rel="noopener" class="btn btn-outline-secondary btn-sm rounded-pill"><i class="fab fa-github me-1"></i> Upstream</a>
    </div>
</div>

<?php if (!count($skills)): ?>
<div class="alert alert-warning">Skills clone not found at <code>external/agentic-skills-top-10</code>. Clone it: <code>git clone https://github.com/OWASP/www-project-agentic-skills-top-10.git external/agentic-skills-top-10</code></div>
<?php else: ?>
<div class="row g-3">
    <?php foreach ($skills as $s): ?>
    <div class="col-xl-6">
        <div class="card-crm h-100">
            <div class="card-body" style="font-size:13px;">
                <div class="d-flex gap-2 align-items-center mb-1">
                    <span class="badge bg-dark"><?= htmlspecialchars($s['id']) ?></span>
                    <strong><?= htmlspecialchars($s['short'] ?? $s['title']) ?></strong>
                    <?= agentic_sev_badge($s['severity']) ?>
                </div>
                <p class="text-muted"><?= htmlspecialchars($s['description']) ?></p>
                <?php if ($s['mitigation'] !== ''): ?>
                <div class="alert alert-success mb-0" style="font-size:12px;"><i class="fas fa-check me-1"></i><strong>In this app:</strong> <?= htmlspecialchars($s['mitigation']) ?></div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
