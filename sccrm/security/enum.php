<?php
// SCCRM > Subdomain Enum — OWASP Amass attack-surface enumeration
// (real `amass` binary when installed, crt.sh passive fallback otherwise).
if (session_status() === PHP_SESSION_NONE) { session_start(); }
$_sccrm_db_boot = require_once __DIR__ . '/../config/database.php';
if (($_sccrm_db_boot instanceof PDO) && (!isset($db) || !($db instanceof PDO))) { $db = $_sccrm_db_boot; }
require_once __DIR__ . '/amass_lib.php';
amassEnsureTables($db);

$domain = strtolower(trim($_REQUEST['domain'] ?? ''));
$result = null;
if ($domain !== '') {
    if (!preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/', $domain)) {
        $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Enter a bare domain, e.g. example.com'];
    } else {
        set_time_limit(180);
        $result = amassEnum($domain);
        if ($result['error'] && $result['error'] !== 'not-installed') {
            $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Enum failed: ' . $result['error']];
        } elseif (!$result['error']) {
            $db->prepare("INSERT INTO subdomain_enums (domain, source, sub_count, data_json) VALUES (?,?,?,?)")
                ->execute([$domain, $result['source'], count($result['subs']), json_encode(array_slice($result['subs'], 0, 500))]);
        }
    }
}
$history = $db->query("SELECT * FROM subdomain_enums ORDER BY created_at DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
$bin = amassBinary();
$repoVer = amassRepoVersion();

require_once __DIR__ . '/../includes/header.php';
?>
<div class="page-title-area">
    <div>
        <h4><i class="fas fa-radar me-2 text-info"></i>Subdomain Enum <small class="text-muted">Amass</small></h4>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="<?= htmlspecialchars($SCCRM_BASE) ?>/index.php" class="text-decoration-none">Dashboard</a></li>
                <li class="breadcrumb-item"><a href="<?= htmlspecialchars($SCCRM_BASE) ?>/security/" class="text-decoration-none">ASVS Security</a></li>
                <li class="breadcrumb-item active">Enum</li>
            </ol>
        </nav>
    </div>
    <div class="d-flex gap-2 align-items-center">
        <?php if ($bin): ?><span class="badge bg-success">amass binary: <?= htmlspecialchars(basename($bin)) ?></span>
        <?php else: ?><span class="badge bg-warning text-dark">amass binary missing — crt.sh fallback</span><?php endif; ?>
        <a href="https://github.com/owasp-amass/amass" target="_blank" rel="noopener" class="btn btn-outline-secondary btn-sm rounded-pill"><i class="fab fa-github me-1"></i> Upstream</a>
    </div>
</div>

<div class="card-crm mb-3">
    <div class="card-body">
        <form method="GET" class="row g-2">
            <div class="col-md-9"><input type="text" name="domain" class="form-control form-control-lg" placeholder="example.com" value="<?= htmlspecialchars($domain) ?>"></div>
            <div class="col-md-3"><button class="btn btn-info w-100 h-100"><i class="fas fa-radar me-1"></i> Enumerate</button></div>
        </form>
        <?php if (!$bin): ?>
        <div class="alert alert-info mt-3 mb-0" style="font-size:13px;">
            <strong>Full engine:</strong> install Amass for deeper passive + active enumeration —
            <code>go install github.com/owasp-amass/amass/v5/cmd/amass@latest</code> or grab a Windows release from
            <a href="https://github.com/owasp-amass/amass/releases/latest" target="_blank" rel="noopener">releases</a>,
            then re-run. Repo reference cloned at <code>external/amass<?= $repoVer ? ' (' . htmlspecialchars($repoVer) . ')' : '' ?></code>.
        </div>
        <?php endif; ?>
    </div>
</div>

<?php if ($result && !$result['error']): ?>
<div class="card-crm mb-3 border-info">
    <div class="card-header">
        <h6><i class="fas fa-list me-2"></i><?= htmlspecialchars($domain) ?> — <?= count($result['subs']) ?> subdomains <small class="text-muted">via <?= htmlspecialchars($result['source']) ?></small></h6>
        <span class="d-flex gap-1">
            <a href="<?= htmlspecialchars($SCCRM_BASE) ?>/trace/?url=<?= urlencode('https://' . $domain) ?>" class="btn btn-sm btn-outline-info rounded-pill">Trace apex</a>
            <a href="<?= htmlspecialchars($SCCRM_BASE) ?>/autoflows/push_trace.php?url=<?= urlencode('https://' . $domain) ?>" class="btn btn-sm btn-outline-success rounded-pill">Push lead</a>
        </span>
    </div>
    <div class="card-body" style="max-height:380px;overflow-y:auto;">
        <?php if (count($result['subs'])): ?>
        <div class="row g-1" style="font-size:12px;">
            <?php foreach (array_slice($result['subs'], 0, 200) as $s): ?>
            <div class="col-md-4"><div class="border rounded px-2 py-1 text-break"><?= htmlspecialchars($s) ?>
                <a href="<?= htmlspecialchars($SCCRM_BASE) ?>/trace/?url=<?= urlencode('https://' . $s) ?>" class="ms-1" title="Trace"><i class="fas fa-crosshairs"></i></a>
            </div></div>
            <?php endforeach; ?>
        </div>
        <?php if (count($result['subs']) > 200): ?><p class="text-muted mt-2" style="font-size:12px;">+<?= count($result['subs']) - 200 ?> more (full set stored in history).</p><?php endif; ?>
        <?php else: ?>
        <div class="empty-state py-3"><i class="fas fa-radar"></i><h6>No subdomains found</h6></div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<div class="card-crm">
    <div class="card-header"><h6><i class="fas fa-history me-2 text-secondary"></i>Enum history</h6></div>
    <div class="card-body p-0">
        <?php if (count($history)): ?>
        <div class="table-responsive">
            <table class="table table-crm mb-0">
                <thead><tr><th>Domain</th><th>Source</th><th>Count</th><th>When</th><th></th></tr></thead>
                <tbody>
                    <?php foreach ($history as $h): ?>
                    <tr style="font-size:13px;">
                        <td><?= htmlspecialchars($h['domain']) ?></td>
                        <td><span class="badge bg-light text-dark border"><?= htmlspecialchars($h['source']) ?></span></td>
                        <td><?= (int)$h['sub_count'] ?></td>
                        <td class="text-muted"><?= htmlspecialchars($h['created_at']) ?></td>
                        <td><a href="?domain=<?= urlencode($h['domain']) ?>" class="btn btn-sm btn-outline-secondary rounded-pill">Re-run</a></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <div class="empty-state py-3"><i class="fas fa-radar"></i><h6>No enumerations yet</h6></div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
