<?php
// SCCRM > ASVS Security — check any site against OWASP ASVS 5.0 controls
// (cloned standard under external/asvs) + browse the full requirement text.
if (session_status() === PHP_SESSION_NONE) { session_start(); }
$_sccrm_db_boot = require_once __DIR__ . '/../config/database.php';
if (($_sccrm_db_boot instanceof PDO) && (!isset($db) || !($db instanceof PDO))) { $db = $_sccrm_db_boot; }
require_once __DIR__ . '/asvs_lib.php';

$chapters = asvsChapters();
$version = asvsVersion();
$q = trim($_GET['q'] ?? '');
$chapter = basename($_GET['chapter'] ?? '');
$url = trim($_REQUEST['url'] ?? '');
$findings = [];
$traceTitle = '';
$traceError = null;

if ($url !== '') {
    if (!preg_match('#^https?://#i', $url)) $url = 'https://' . $url;
    try {
        $root = dirname(__DIR__, 2);
        if (file_exists($root . '/vendor/autoload.php')) require_once $root . '/vendor/autoload.php';
        if (file_exists($root . '/trace/url_tracer.php')) require_once $root . '/trace/url_tracer.php';
        if (!class_exists('OSINT\\URLTracer')) throw new Exception('Tracer engine unavailable.');
        $tracePdo = file_exists($root . '/data/osint.db') ? new PDO('sqlite:' . $root . '/data/osint.db') : null;
        $res = (new OSINT\URLTracer($tracePdo))->trace($url);
        if (!empty($res['error'])) throw new Exception($res['error']['message'] ?? 'Trace failed.');
        $findings = asvsEvaluateTrace($res);
        $traceTitle = $res['content']['title'] ?? $url;
    } catch (Throwable $e) { $traceError = $e->getMessage(); }
}

$searchHits = $q !== '' ? asvsSearch($q) : [];
$chapterReqs = [];
$chapterMeta = null;
if ($chapter !== '') {
    foreach ($chapters as $c) { if ($c['file'] === $chapter) { $chapterMeta = $c; break; } }
    if ($chapterMeta) $chapterReqs = asvsRequirements($chapter);
}

require_once __DIR__ . '/../includes/header.php';
?>
<div class="page-title-area">
    <div>
        <h4><i class="fas fa-shield-halved me-2 text-success"></i>ASVS Security <small class="text-muted">OWASP ASVS <?= htmlspecialchars($version ?? '?') ?></small></h4>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="<?= htmlspecialchars($SCCRM_BASE) ?>/index.php" class="text-decoration-none">Dashboard</a></li>
                <li class="breadcrumb-item active">ASVS Security</li>
            </ol>
        </nav>
    </div>
    <div class="d-flex gap-2">
        <a href="<?= htmlspecialchars($SCCRM_BASE) ?>/security/enum.php" class="btn btn-outline-info btn-sm rounded-pill"><i class="fas fa-radar me-1"></i> Subdomain Enum</a>
        <a href="https://github.com/OWASP/ASVS" target="_blank" rel="noopener" class="btn btn-outline-secondary btn-sm rounded-pill"><i class="fab fa-github me-1"></i> Upstream</a>
    </div>
</div>

<?php if (!$version): ?>
<div class="alert alert-warning">ASVS clone not found at <code>external/asvs</code>. Clone it: <code>git clone https://github.com/OWASP/ASVS.git external/asvs</code></div>
<?php endif; ?>

<div class="card-crm mb-3">
    <div class="card-body">
        <form method="GET" class="row g-2">
            <div class="col-md-9">
                <div class="input-group">
                    <span class="input-group-text bg-transparent"><i class="fas fa-link text-muted"></i></span>
                    <input type="url" name="url" class="form-control form-control-lg" placeholder="https://example.com — check against ASVS controls" value="<?= htmlspecialchars($_REQUEST['url'] ?? '') ?>">
                </div>
            </div>
            <div class="col-md-3"><button class="btn btn-success w-100 h-100"><i class="fas fa-shield-halved me-1"></i> Check Site</button></div>
        </form>
    </div>
</div>

<?php if ($traceError): ?>
<div class="alert alert-danger"><?= htmlspecialchars($traceError) ?></div>
<?php endif; ?>

<?php if ($findings): ?>
<?php $pass = count(array_filter($findings, function ($f) { return $f['status'] === 'pass'; })); ?>
<div class="card-crm mb-3 border-success">
    <div class="card-header">
        <h6><i class="fas fa-clipboard-check me-2 text-success"></i><?= htmlspecialchars($traceTitle) ?> — <?= $pass ?>/<?= count($findings) ?> controls pass</h6>
        <a href="<?= htmlspecialchars($SCCRM_BASE) ?>/autoflows/push_trace.php?url=<?= urlencode($url) ?>" class="btn btn-sm btn-outline-info rounded-pill">Push to Lead</a>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-crm mb-0">
                <thead><tr><th>Control</th><th>ASVS</th><th>Level</th><th>Status</th><th>Evidence</th></tr></thead>
                <tbody>
                    <?php foreach ($findings as $f): ?>
                    <tr style="font-size:13px;">
                        <td><?= htmlspecialchars($f['label']) ?></td>
                        <td><a href="?q=<?= urlencode($f['asvs']) ?>" class="text-decoration-none"><?= htmlspecialchars($f['asvs']) ?></a></td>
                        <td><span class="badge bg-secondary">L<?= (int)$f['level'] ?></span></td>
                        <td><?= $f['status'] === 'pass' ? '<span class="badge-status completed">Pass</span>' : '<span class="badge-status cancelled">Fail</span>' ?></td>
                        <td class="text-muted text-break" style="max-width:260px;"><?= htmlspecialchars($f['evidence']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="row g-3">
    <div class="col-xl-4">
        <div class="card-crm">
            <div class="card-header"><h6><i class="fas fa-book me-2"></i>Chapters (<?= count($chapters) ?>)</h6></div>
            <div class="list-group list-group-flush" style="max-height:520px;overflow-y:auto;">
                <?php foreach ($chapters as $c): ?>
                <a href="?chapter=<?= urlencode($c['file']) ?>" class="list-group-item list-group-item-action <?= $chapter === $c['file'] ? 'active' : '' ?>" style="font-size:13px;">
                    <span class="badge bg-light text-dark border me-1"><?= htmlspecialchars($c['code']) ?></span> <?= htmlspecialchars($c['title']) ?>
                </a>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <div class="col-xl-8">
        <div class="card-crm">
            <div class="card-header">
                <h6><i class="fas fa-search me-2"></i><?= $chapterMeta ? htmlspecialchars($chapterMeta['code'] . ' — ' . $chapterMeta['title']) : 'Search the standard' ?></h6>
                <form method="GET" class="d-flex gap-1">
                    <?php if ($chapterMeta): ?><input type="hidden" name="chapter" value="<?= htmlspecialchars($chapterMeta['file']) ?>"><?php endif; ?>
                    <input type="text" name="q" class="form-control form-control-sm" placeholder="e.g. 3.4.1, CSP, cookie…" value="<?= htmlspecialchars($q) ?>" style="max-width:260px;">
                    <button class="btn btn-sm btn-primary">Search</button>
                    <?php if ($q !== '' || $chapterMeta): ?><a href="?" class="btn btn-sm btn-outline-secondary">Clear</a><?php endif; ?>
                </form>
            </div>
            <div class="card-body" style="max-height:560px;overflow-y:auto;">
                <?php if ($searchHits): ?>
                <table class="table table-crm mb-0">
                    <tbody>
                        <?php foreach ($searchHits as $h): ?>
                        <tr style="font-size:13px;">
                            <td style="white-space:nowrap;"><strong><?= htmlspecialchars($h['id']) ?></strong> <span class="badge bg-secondary">L<?= (int)$h['level'] ?></span><div class="text-muted small"><?= htmlspecialchars($h['code']) ?></div></td>
                            <td><?= htmlspecialchars($h['text']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php elseif ($chapterReqs): ?>
                <table class="table table-crm mb-0">
                    <tbody>
                        <?php foreach ($chapterReqs as $r): ?>
                        <tr style="font-size:13px;">
                            <td style="white-space:nowrap;"><strong><?= htmlspecialchars($r['id']) ?></strong> <span class="badge bg-secondary">L<?= (int)$r['level'] ?></span></td>
                            <td><?= htmlspecialchars($r['text']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php else: ?>
                <div class="empty-state"><i class="fas fa-book"></i><h6>Pick a chapter or search</h6><p class="text-muted">Every ASVS 5.0 requirement, searchable offline.</p></div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
