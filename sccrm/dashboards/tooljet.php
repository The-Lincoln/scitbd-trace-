<?php
// SCCRM > Dashboards — ToolJet embedded ops app (low-code KPI/lead/trace views).
// Companion to sccrm/trace/agent_browser.php (rendered trace panel).
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../includes/header.php';

$osintRoot = dirname(__DIR__, 2);
foreach ([
    $osintRoot . '/autoflows/app/services/ToolJet.php',
    $osintRoot . '/sccrm/services/ToolJetService.php',
] as $f) {
    if (is_file($f)) {
        require_once $f;
    }
}

$tjStatus = class_exists('ToolJet') ? ToolJet::status() : ['ok' => false, 'host' => '?', 'hint' => 'ToolJet wrapper missing'];
$embedUrl = class_exists('ToolJetService') ? ToolJetService::dashboardUrl() : '';
?>
<div class="page-title-area">
    <div>
        <h4><i class="fas fa-th-large me-2 text-info"></i>Ops Dashboard <small class="text-muted">ToolJet · low-code</small></h4>
        <nav aria-label="breadcrumb"><ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="<?= htmlspecialchars($SCCRM_BASE) ?>/index.php" class="text-decoration-none">Dashboard</a></li>
            <li class="breadcrumb-item active">ToolJet</li>
        </ol></nav>
    </div>
    <div><span class="badge <?= !empty($tjStatus['ok']) ? 'bg-success' : 'bg-warning text-dark' ?>"><?= !empty($tjStatus['ok']) ? '● tooljet ready' : '● tooljet offline' ?></span></div>
</div>

<?php if (empty($tjStatus['ok'])): ?>
<div class="alert alert-warning">
    <i class="fas fa-exclamation-triangle me-1"></i>
    ToolJet instance unreachable at <strong><?= htmlspecialchars($tjStatus['host'] ?? '?') ?></strong>.
    <?= htmlspecialchars($tjStatus['hint'] ?? '') ?>
    CRM keeps working — dashboards light up once the instance + <code>TOOLJET_APP_SCCRM</code> slug are set.
</div>
<?php endif; ?>

<?php if ($embedUrl !== ''): ?>
<div class="card-crm mb-4"><div class="card-body p-0">
    <iframe src="<?= htmlspecialchars($embedUrl) ?>" style="width:100%;min-height:78vh;border:0;border-radius:12px;" title="ToolJet ops dashboard" loading="lazy"></iframe>
</div></div>
<?php else: ?>
<div class="card-crm mb-4"><div class="card-body">
    <h6><i class="fas fa-plug me-2 text-secondary"></i>Connect a ToolJet app</h6>
    <ol class="small text-muted mb-0">
        <li>Self-host: <code>docker run -p 80:80 tooljet/try:ee-lts-latest</code> (or ToolJet Cloud).</li>
        <li>Build the SCCRM ops app (leads table, KPI charts, trace view) in the visual builder.</li>
        <li>Set <code>TOOLJET_HOST</code> + <code>TOOLJET_APP_SCCRM=&lt;slug&gt;</code> — this panel embeds it here.</li>
        <li>Optional: lead/trace Workflow webhooks (<code>TOOLJET_WORKFLOW_LEAD/TRACE</code>) for fan-out.</li>
    </ol>
</div></div>
<?php endif; ?>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
