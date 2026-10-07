<?php
// SCCRM > URL Trace — embedded OSINT URL Tracer inside the CRM layout.
@set_time_limit(120);
@ini_set('max_execution_time', '120');
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/../includes/header.php';

$traceResult = null;
$traceError = null;
$traces = [];

// Wire the shared tracer engine (composer + OSINT URLTracer class)
$tracer = null;
try {
    $osintRoot = dirname(__DIR__, 2);
    if (file_exists($osintRoot . '/vendor/autoload.php')) {
        require_once $osintRoot . '/vendor/autoload.php';
    }
    if (file_exists($osintRoot . '/trace/url_tracer.php')) {
        require_once $osintRoot . '/trace/url_tracer.php';
    }
    if (class_exists('OSINT\\URLTracer')) {
        // Reuse OSINT SQLite store so history is shared with the full Trace app
        $tracePdo = null;
        try {
            $osintDbPath = $osintRoot . '/data/osint.db';
            if (file_exists($osintDbPath)) {
                $tracePdo = new PDO('sqlite:' . $osintDbPath);
                $tracePdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            }
        } catch (Throwable $e) { $tracePdo = null; }
        $tracer = new OSINT\URLTracer($tracePdo);
        $traces = $tracer->getTraces();
    } else {
        $traceError = 'Tracer engine not found (trace/url_tracer.php missing).';
    }
} catch (Throwable $e) {
    $traceError = 'Tracer init failed: ' . $e->getMessage();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $tracer) {
    $url = trim($_POST['url'] ?? '');
    if ($url === '') {
        $traceError = 'Please enter a URL to trace.';
    } else {
        try {
            $traceResult = $tracer->trace($url);
            $traces = $tracer->getTraces();
            if (!empty($traceResult['error'])) {
                $traceError = 'Trace failed: ' . ($traceResult['error']['message'] ?? 'unknown error');
            } else {
                // AutoFlows: every completed trace fires trace_completed (interaction log, ...).
                try {
                    require_once __DIR__ . '/../autoflows/autoflow_engine.php';
                    if (function_exists('autoflowTrigger')) {
                        $techs = [];
                        foreach ((array)($traceResult['technology']['all_technologies'] ?? []) as $t) {
                            $techs[] = is_array($t) ? ($t['technology'] ?? '') : (string)$t;
                        }
                        autoflowTrigger($db, 'trace_completed', [
                            'url' => $traceResult['basic']['final_url'] ?? $url,
                            'subject' => 'URL traced: ' . ($traceResult['content']['title'] ?? $url),
                            'seo_score' => (int)($traceResult['seo']['score'] ?? 0),
                            'security_score' => (int)($traceResult['security']['score'] ?? 0),
                            'tech_stack' => implode(', ', array_slice(array_values(array_filter(array_unique($techs))), 0, 8)),
                        ]);
                    }
                } catch (Throwable $e) { /* automation never breaks tracing */ }
            }
        } catch (Throwable $e) {
            $traceError = 'Trace Error: ' . $e->getMessage();
        }
    }
}

function trace_score_class($score) {
    $score = (int)$score;
    if ($score >= 70) return 'success';
    if ($score >= 40) return 'warning';
    return 'danger';
}
?>
<div class="page-title-area">
    <div>
        <h4><i class="fas fa-crosshairs me-2 text-info"></i>URL Trace</h4>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="<?= htmlspecialchars($SCCRM_BASE) ?>/index.php" class="text-decoration-none">Dashboard</a></li>
                <li class="breadcrumb-item active">URL Trace</li>
            </ol>
        </nav>
    </div>
    <div class="d-flex gap-2">
        <a href="<?= htmlspecialchars($OSINT_BASE) ?>/trace/index.php" class="btn btn-outline-info btn-sm rounded-pill" target="_blank" rel="noopener">
            <i class="fas fa-external-link-alt me-1"></i> Full Trace App
        </a>
        <a href="<?= htmlspecialchars($OSINT_BASE) ?>/trace/url_trace.php" class="btn btn-outline-secondary btn-sm rounded-pill" target="_blank" rel="noopener">
            <i class="fas fa-play me-1"></i> Classic Results View
        </a>
    </div>
</div>

<div class="card-crm mb-4">
    <div class="card-body">
        <form method="POST" class="row g-2">
            <div class="col-md-10">
                <div class="input-group">
                    <span class="input-group-text bg-transparent"><i class="fas fa-link text-muted"></i></span>
                    <input type="url" name="url" class="form-control form-control-lg" placeholder="https://example.com" required
                           value="<?= htmlspecialchars($_POST['url'] ?? $_GET['url'] ?? '') ?>">
                </div>
                <small class="text-muted">Analyzes headers, SSL/TLS, technologies, links, forms, SEO, security &amp; performance. History is shared with the OSINT Trace app.</small>
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-info w-100 h-100"><i class="fas fa-crosshairs me-1"></i> Trace URL</button>
            </div>
        </form>
    </div>
</div>

<?php if ($traceError): ?>
<div class="alert alert-danger"><i class="fas fa-exclamation-triangle me-1"></i><?= htmlspecialchars($traceError) ?></div>
<?php endif; ?>

<?php if ($traceResult && empty($traceResult['error'])): ?>
<div class="row g-3 mb-4">
    <div class="col-xl-8">
        <div class="card-crm">
            <div class="card-header">
                <h6><i class="fas fa-info-circle me-2 text-primary"></i>Basic Information
                    <span class="badge bg-<?= ((int)($traceResult['basic']['status_code'] ?? 0) < 400) ? 'success' : 'warning' ?> ms-2"><?= htmlspecialchars((string)($traceResult['basic']['status_code'] ?? 'N/A')) ?></span>
                </h6>
            </div>
            <div class="card-body">
                <div class="row" style="font-size:13px;">
                    <div class="col-md-6 mb-2"><div class="text-muted">Final URL</div><div class="fw-semibold text-break"><?= htmlspecialchars($traceResult['basic']['final_url'] ?? $traceResult['basic']['url'] ?? '-') ?></div></div>
                    <div class="col-md-3 mb-2"><div class="text-muted">Response time</div><div class="fw-semibold"><?= htmlspecialchars((string)($traceResult['basic']['response_time_ms'] ?? 'N/A')) ?> ms</div></div>
                    <div class="col-md-3 mb-2"><div class="text-muted">Size</div><div class="fw-semibold"><?= round(((int)($traceResult['basic']['content_length'] ?? 0)) / 1024, 1) ?> KB</div></div>
                    <div class="col-md-3 mb-2"><div class="text-muted">Server</div><div><?= htmlspecialchars($traceResult['basic']['server'] ?? 'N/A') ?></div></div>
                    <div class="col-md-3 mb-2"><div class="text-muted">Server IP</div><div><?= htmlspecialchars($traceResult['basic']['server_ip'] ?? 'N/A') ?></div></div>
                    <div class="col-md-3 mb-2"><div class="text-muted">Content type</div><div class="text-break"><?= htmlspecialchars($traceResult['basic']['content_type'] ?? 'N/A') ?></div></div>
                    <div class="col-md-3 mb-2"><div class="text-muted">Redirects</div><div><?= (int)($traceResult['basic']['redirects'] ?? 0) ?></div></div>
                    <div class="col-md-6 mb-2"><div class="text-muted">Title</div><div><?= htmlspecialchars($traceResult['content']['title'] ?? 'N/A') ?></div></div>
                    <div class="col-md-6 mb-2"><div class="text-muted">IP / Location</div><div><?= htmlspecialchars(($traceResult['identity']['ip_address'] ?? '-') . ' · ' . (($traceResult['identity']['location']['city'] ?? '') ? ($traceResult['identity']['location']['city'] . ', ' . ($traceResult['identity']['location']['country'] ?? '')) : ($traceResult['identity']['location']['country'] ?? 'N/A'))) ?></div></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-xl-4">
        <div class="card-crm">
            <div class="card-header"><h6><i class="fas fa-chart-bar me-2 text-success"></i>Scores</h6></div>
            <div class="card-body">
                <?php
                $scores = [
                    'SEO' => (int)($traceResult['seo']['score'] ?? 0),
                    'Security' => (int)($traceResult['security']['score'] ?? 0),
                    'Performance' => (int)($traceResult['performance']['performance_score'] ?? 0),
                ];
                foreach ($scores as $label => $val):
                ?>
                <div class="d-flex justify-content-between align-items-center mb-1" style="font-size:13px;">
                    <span><?= $label ?></span><strong><?= $val ?>/100</strong>
                </div>
                <div class="progress mb-2" style="height:8px;">
                    <div class="progress-bar bg-<?= trace_score_class($val) ?>" style="width:<?= $val ?>%"></div>
                </div>
                <?php endforeach; ?>
                <?php if (!empty($traceResult['technology']['all_technologies'])): ?>
                <div class="mt-2" style="font-size:12px;">
                    <div class="text-muted mb-1">Technologies (<?= count($traceResult['technology']['all_technologies']) ?>)</div>
                    <?php foreach (array_slice($traceResult['technology']['all_technologies'], 0, 12) as $t): ?>
                        <span class="badge bg-light text-dark border me-1 mb-1"><?= htmlspecialchars(is_array($t) ? ($t['technology'] ?? json_encode($t)) : (string)$t) ?></span>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="card-crm">
    <div class="card-header">
        <h6><i class="fas fa-history me-2 text-secondary"></i>Recent Traces (<?= count($traces) ?>)</h6>
        <a href="<?= htmlspecialchars($OSINT_BASE) ?>/trace/url_trace.php" target="_blank" rel="noopener" class="btn btn-sm btn-outline-secondary rounded-pill">Open full history</a>
    </div>
    <div class="card-body p-0">
        <?php if (count($traces) > 0): ?>
        <div class="table-responsive">
            <table class="table table-crm mb-0">
                <thead><tr><th>URL</th><th>Status</th><th>SEO</th><th>Security</th><th>Perf</th><th>Traced</th><th></th></tr></thead>
                <tbody>
                    <?php foreach (array_slice($traces, 0, 15) as $t): ?>
                    <tr style="font-size:13px;">
                        <td class="text-break" style="max-width:280px;"><?= htmlspecialchars($t['url'] ?? '-') ?><div class="text-muted small"><?= htmlspecialchars($t['title'] ?? '') ?></div></td>
                        <td><span class="badge bg-<?= ((int)($t['status_code'] ?? 0) < 400 && (int)($t['status_code'] ?? 0) > 0) ? 'success' : 'secondary' ?>"><?= htmlspecialchars((string)($t['status_code'] ?? '-')) ?></span></td>
                        <td><?= (int)($t['seo_score'] ?? 0) ?></td>
                        <td><?= (int)($t['security_score'] ?? 0) ?></td>
                        <td><?= (int)($t['performance_score'] ?? 0) ?></td>
                        <td class="text-muted"><?= htmlspecialchars($t['created_at'] ?? '') ?></td>
                        <td><a href="<?= htmlspecialchars($SCCRM_BASE) ?>/autoflows/push_trace.php?url=<?= urlencode($t['url'] ?? '') ?>" class="btn btn-sm btn-outline-info rounded-pill" title="Create lead from this trace"><i class="fas fa-user-plus"></i></a></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <div class="empty-state"><i class="fas fa-crosshairs"></i><h6>No traces yet</h6><p class="text-muted">Trace your first URL using the form above.</p></div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
