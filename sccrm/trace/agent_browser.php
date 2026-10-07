<?php
// SCCRM > Trace — agent-browser rendered panel (embedded in CRM layout).
// Complements index.php (static Guzzle trace) with JS-rendered DOM evidence.
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../includes/header.php';

$osintRoot = dirname(__DIR__, 2);
foreach ([
    $osintRoot . '/autoflows/app/services/AgentBrowser.php',
    $osintRoot . '/trace/AgentBrowserTracer.php',
    $osintRoot . '/vendor/autoload.php',
] as $f) {
    if (is_file($f)) {
        require_once $f;
    }
}

$rendered = null;
$renderError = null;
$available = class_exists('OSINT\\AgentBrowserTracer') ? OSINT\AgentBrowserTracer::isAvailable() : false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['url'])) {
    $url = trim($_POST['url']);
    try {
        $tracePdo = null;
        $osintDb = $osintRoot . '/data/osint.db';
        if (is_file($osintDb)) {
            $tracePdo = new PDO('sqlite:' . $osintDb);
        }
        $t = new OSINT\AgentBrowserTracer($tracePdo, 'sccrm');
        $rendered = $t->traceRendered($url, ['static' => isset($_POST['hybrid']), 'screenshot' => true]);
        // Mirror into CRM interactions via autoflow.
        try {
            require_once __DIR__ . '/../autoflows/autoflow_engine.php';
            if (function_exists('autoflowTrigger')) {
                autoflowTrigger($db, 'trace_completed', [
                    'url' => $url,
                    'subject' => 'Rendered trace: ' . mb_substr($rendered['merged']['title_rendered'] ?? $rendered['merged']['title'] ?? $url, 0, 120),
                    'seo_score' => (int)($rendered['merged']['static_scores']['seo'] ?? 0),
                    'security_score' => (int)($rendered['merged']['static_scores']['security'] ?? 0),
                    'tech_stack' => implode(', ', array_slice(array_merge($rendered['merged']['spa_signals'] ?? [], ['rendered-dom']), 0, 8)),
                    'source' => 'sccrm_rendered_trace',
                ]);
            }
        } catch (Throwable $e) {
        }
    } catch (Throwable $e) {
        $renderError = $e->getMessage();
    }
}
?>
<div class="page-title-area">
    <div>
        <h4><i class="fas fa-globe me-2 text-success"></i>Rendered Trace <small class="text-muted">agent-browser · Chromium</small></h4>
        <nav aria-label="breadcrumb"><ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="<?= htmlspecialchars($SCCRM_BASE) ?>/index.php" class="text-decoration-none">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= htmlspecialchars($SCCRM_BASE) ?>/trace/index.php" class="text-decoration-none">URL Trace</a></li>
            <li class="breadcrumb-item active">Rendered</li>
        </ol></nav>
    </div>
    <div><span class="badge <?= $available ? 'bg-success' : 'bg-warning text-dark' ?>"><?= $available ? '● browser ready' : '● browser missing' ?></span></div>
</div>

<div class="card-crm mb-4"><div class="card-body">
    <form method="POST" class="row g-2">
        <div class="col-md-8"><div class="input-group">
            <span class="input-group-text bg-transparent"><i class="fas fa-link text-muted"></i></span>
            <input type="url" name="url" class="form-control form-control-lg" placeholder="https://spa-example.com" required value="<?= htmlspecialchars($_POST['url'] ?? $_GET['url'] ?? '') ?>">
        </div><small class="text-muted">For JS-heavy SPA pages the static tracer misses. This drives real Chromium: open → read → snapshot → JS extract → screenshot.</small></div>
        <div class="col-md-2 d-flex align-items-center"><div class="form-check"><input class="form-check-input" type="checkbox" name="hybrid" value="1" id="hy" <?= isset($_POST['hybrid']) || !isset($_POST['url']) ? 'checked' : '' ?>><label class="form-check-label" for="hy">+ static scores</label></div></div>
        <div class="col-md-2"><button class="btn btn-success w-100 h-100"><i class="fas fa-play me-1"></i> Render</button></div>
    </form>
</div></div>

<?php if ($renderError): ?><div class="alert alert-danger"><?= htmlspecialchars($renderError) ?></div><?php endif; ?>
<?php if ($rendered): ?>
<div class="row g-3 mb-4">
    <div class="col-xl-7"><div class="card-crm"><div class="card-header"><h6><i class="fas fa-microchip me-2 text-primary"></i>Merged intel (<?= $rendered['ms'] ?>ms)</h6></div>
        <div class="card-body" style="font-size:13px;">
            <div class="mb-1 text-muted">Title</div><div class="fw-semibold mb-2"><?= htmlspecialchars($rendered['merged']['title_rendered'] ?? $rendered['merged']['title'] ?? '-') ?></div>
            <div class="mb-1 text-muted">Description</div><div class="mb-2"><?= htmlspecialchars($rendered['merged']['description_rendered'] ?? $rendered['merged']['description'] ?? '-') ?></div>
            <?php if (!empty($rendered['merged']['spa_signals'])): ?><div class="mb-1 text-muted">SPA signals</div><div class="mb-2"><?php foreach ($rendered['merged']['spa_signals'] as $s): ?><span class="badge bg-light text-dark border me-1"><?= htmlspecialchars($s) ?></span><?php endforeach; ?></div><?php endif; ?>
            <div class="row"><div class="col-4"><div class="text-muted">Read chars</div><strong><?= (int)($rendered['merged']['read_chars'] ?? 0) ?></strong></div>
            <div class="col-4"><div class="text-muted">Refs</div><strong><?= (int)($rendered['merged']['ref_count'] ?? 0) ?></strong></div>
            <div class="col-4"><div class="text-muted">Shot</div><div class="text-break small"><?= htmlspecialchars($rendered['shot'] ?? '-') ?></div></div></div>
        </div></div></div>
    <div class="col-xl-5"><div class="card-crm"><div class="card-header"><h6><i class="fas fa-terminal me-2 text-secondary"></i>Steps</h6></div>
        <div class="card-body p-0"><table class="table table-crm mb-0"><tbody>
        <?php foreach (($rendered['rendered']['steps'] ?? []) as $k => $s): ?>
        <tr style="font-size:12px;"><td><?= htmlspecialchars($k) ?></td><td><?= !empty($s['ok']) ? '<span class="badge-status completed">OK</span>' : '<span class="badge-status cancelled">FAIL</span>' ?></td><td class="text-muted text-break"><?= htmlspecialchars(mb_substr($s['text'] ?? '', 0, 160)) ?></td></tr>
        <?php endforeach; ?>
        </tbody></table></div></div></div>
</div>
<?php endif; ?>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
