<?php
// NOTE: POST must be handled BEFORE including the layout header,
// otherwise header('Location: ...') fails with "headers already sent".
if (session_status() === PHP_SESSION_NONE) { session_start(); }
// Load DB before POST handling; require_once yields PDO only on first load,
// so preserve $db if the file was already included.
$_sccrm_db_boot = require_once __DIR__ . '/../config/database.php';
if (($_sccrm_db_boot instanceof PDO) && (!isset($db) || !($db instanceof PDO))) { $db = $_sccrm_db_boot; }
require_once __DIR__ . '/generator_functions.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate'])) {
    $count = min(max(intval($_POST['count'] ?? 5), 1), 50);
    $market_id = !empty($_POST['market_id']) ? intval($_POST['market_id']) : null;
    $service_id = !empty($_POST['service_id']) ? intval($_POST['service_id']) : null;
    $enrich = !empty($_POST['osint_enrich']);

    $result = generateLeads($db, $count, $market_id, $service_id, ['enrich' => $enrich]);

    $_SESSION['flash'] = ['type' => $result['status'] === 'success' ? 'success' : 'danger', 'message' => $result['message']];
    // Relative redirect = works at any base path (/sccrm or /sub/sccrm) + no "headers already sent".
    header('Location: generate.php');
    exit;
}

require_once __DIR__ . '/../includes/header.php';

$markets = $db->query("SELECT id, name FROM markets WHERE is_active = 1 ORDER BY name")->fetchAll();
$services = $db->query("SELECT id, name FROM services WHERE is_active = 1 ORDER BY name")->fetchAll();

$recent_logs = $db->query("SELECT * FROM lead_generation_logs ORDER BY generated_at DESC LIMIT 10")->fetchAll();

// OSINT toolkit: recommended intel tools for the selected (or first) service.
$toolkit_service_id = intval($_GET['service_id'] ?? $services[0]['id'] ?? 0);
$toolkit_service_name = '';
foreach ($services as $s) { if ((int)$s['id'] === $toolkit_service_id) { $toolkit_service_name = $s['name']; break; } }
$osint_tools = $toolkit_service_name ? osintToolsForService($toolkit_service_name, 8) : [];
?>
<div class="page-title-area">
    <div>
        <h4>Lead Generation</h4>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="<?= $SCCRM_BASE ?>/index.php" class="text-decoration-none">Dashboard</a></li>
                <li class="breadcrumb-item"><a href="<?= $SCCRM_BASE ?>/leads/" class="text-decoration-none">Leads</a></li>
                <li class="breadcrumb-item active">Generate Leads</li>
            </ol>
        </nav>
    </div>
</div>

<div class="row g-3">
    <div class="col-xl-5">
        <div class="card-crm">
            <div class="card-header"><h6><i class="fas fa-bolt me-2 text-success"></i>Generate Leads Now</h6></div>
            <div class="card-body">
                <p class="text-muted" style="font-size:14px;">Generate realistic leads for your target markets and services. Leads will be auto-scored and added to the pipeline.</p>
                <form method="POST" class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Number of Leads</label>
                        <select name="count" class="form-select">
                            <?php foreach ([3,5,10,15,20,25,50] as $n): ?>
                            <option value="<?= $n ?>" <?= $n === 5 ? 'selected' : '' ?>><?= $n ?> leads</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Target Market</label>
                        <select name="market_id" class="form-select">
                            <option value="">All Markets</option>
                            <?php foreach ($markets as $m): ?>
                            <option value="<?= $m['id'] ?>"><?= htmlspecialchars($m['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-12">
                        <label class="form-label">Service Interest</label>
                        <select name="service_id" class="form-select" id="genService" onchange="window.location='generate.php?service_id='+this.value">
                            <option value="">All Services</option>
                            <?php foreach ($services as $s): ?>
                            <option value="<?= $s['id'] ?>" <?= (int)($toolkit_service_id ?? 0) === (int)$s['id'] ? 'selected' : '' ?>><?= htmlspecialchars($s['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" name="osint_enrich" value="1" id="osintEnrich">
                            <label class="form-check-label" for="osintEnrich" style="font-size:13px;">
                                <i class="fas fa-radar me-1 text-info"></i> Enrich with OSINT
                                <small class="text-muted d-block">DNS check + URL trace per lead (tech stack, SEO/security scores, verified-site bonus). Slower but smarter.</small>
                            </label>
                        </div>
                    </div>
                    <div class="col-12">
                        <button type="submit" name="generate" class="btn btn-success w-100 rounded-pill py-2">
                            <i class="fas fa-bolt me-2"></i> Generate Leads
                        </button>
                    </div>
                </form>
            </div>
        </div>
        <div class="card-crm mt-3">
            <div class="card-header"><h6><i class="fas fa-clock me-2 text-info"></i>Auto-Schedule (Cron)</h6></div>
            <div class="card-body">
                <p style="font-size:14px;">For automatic hourly generation, add this command to your cron/scheduler:</p>
                <div class="bg-light p-3 rounded" style="font-size:13px;">
                    <code>php <?= realpath(__DIR__ . '/generator_cli.php') ?></code>
                </div>
                <hr>
                <p style="font-size:13px;" class="text-muted mb-0">
                    <i class="fas fa-info-circle me-1"></i> On Windows, use Task Scheduler. On Linux/Mac, use cron: <code>* * * * * php /path/to/generator_cli.php</code> (but set it to run every hour, not every minute)
                </p>
            </div>
        </div>
    </div>
    <div class="col-xl-7">
        <div class="card-crm">
            <div class="card-header"><h6><i class="fas fa-history me-2"></i>Generation History</h6></div>
            <div class="card-body p-0">
                <?php if (count($recent_logs) > 0): ?>
                <div class="table-responsive">
                    <table class="table table-crm">
                        <thead><tr><th>Time</th><th>Leads Generated</th><th>Source</th><th>Status</th></tr></thead>
                        <tbody>
                            <?php foreach ($recent_logs as $log): ?>
                            <tr>
                                <td style="font-size:13px;"><?= date('M j, Y g:i A', strtotime($log['generated_at'])) ?></td>
                                <td><span class="badge bg-light text-dark fs-6">+<?= $log['leads_generated'] ?></span></td>
                                <td style="font-size:13px;"><?= htmlspecialchars(ucfirst(str_replace('_',' ',$log['source']))) ?></td>
                                <td>
                                    <?php if ($log['status'] === 'success'): ?>
                                    <span class="badge-status completed">Success</span>
                                    <?php else: ?>
                                    <span class="badge-status cancelled" title="<?= htmlspecialchars($log['error_message'] ?? '') ?>">Failed</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                <div class="empty-state"><i class="fas fa-history"></i><h6>No generation history yet</h6><p class="text-muted">Generate your first batch of leads above.</p></div>
                <?php endif; ?>
            </div>
        </div>
        <div class="card-crm mt-3">
            <div class="card-header">
                <h6><i class="fas fa-radar me-2 text-info"></i>OSINT Lead-Intel Toolkit<?= $toolkit_service_name ? ' — ' . htmlspecialchars($toolkit_service_name) : '' ?></h6>
                <span><a href="<?= htmlspecialchars($SCCRM_BASE) ?>/osint/" class="btn btn-sm btn-outline-primary rounded-pill me-1">All 289 tools</a><a href="<?= htmlspecialchars($SCCRM_BASE) ?>/trace/" class="btn btn-sm btn-outline-info rounded-pill">Trace</a></span>
            </div>
            <div class="card-body">
                <?php if (count($osint_tools) > 0): ?>
                <div class="list-group list-group-flush">
                    <?php foreach ($osint_tools as $t): ?>
                    <a href="<?= htmlspecialchars($t['url'] ?? '#') ?>" target="_blank" rel="noopener" class="list-group-item list-group-item-action" style="font-size:13px;">
                        <div class="fw-semibold"><?= htmlspecialchars($t['name'] ?? 'Untitled') ?>
                            <?php if (!empty($t['category'])): ?><span class="badge bg-light text-dark border ms-1"><?= htmlspecialchars($t['category']) ?></span><?php endif; ?>
                        </div>
                        <?php if (!empty($t['description'])): ?><div class="text-muted small"><?= htmlspecialchars(mb_substr($t['description'], 0, 140)) ?></div><?php endif; ?>
                    </a>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                <p class="text-muted mb-0" style="font-size:13px;">OSINT catalog not available. Use <a href="<?= htmlspecialchars($SCCRM_BASE) ?>/trace/">URL Trace</a> or <a href="<?= htmlspecialchars($OSINT_BASE) ?>/godseye.php">Godseye</a> to research prospects manually.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
