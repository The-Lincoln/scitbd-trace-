<?php
// SCCRM > CEO Office — command dashboard over the SCITBD CEO database,
// aligned with SCCRM pipeline data + OSINT trace intel.
if (session_status() === PHP_SESSION_NONE) { session_start(); }
$_sccrm_db_boot = require_once __DIR__ . '/../config/database.php';
if (($_sccrm_db_boot instanceof PDO) && (!isset($db) || !($db instanceof PDO))) { $db = $_sccrm_db_boot; }

$ceoRoot = dirname(__DIR__, 2) . '/autoflows/app';
require_once $ceoRoot . '/services/ScitbdCeo.php';
require_once $ceoRoot . '/models/DailyTask.php';

$ceoError = null;
try {
    $block = ScitbdCeo::currentBlock();
    $briefing = ScitbdCeo::briefing();
    $queue = ScitbdCeo::pendingForBlock(null, 10);
    $summary = ScitbdCeo::summary();
    $escalations = ScitbdCeo::checkEscalations();
    $profile = ScitbdCeo::profile();
} catch (Throwable $e) {
    $ceoError = 'CEO database unavailable: ' . $e->getMessage();
    $block = ['id' => '?', 'block_name' => 'Unknown'];
    $briefing = ['company' => 'SCITBD', 'pending_tasks' => 0, 'escalations' => 0, 'leads' => 0, 'tickets' => 0];
    $queue = []; $summary = ['date' => date('Y-m-d'), 'total_tasks' => 0, 'completed_tasks' => 0, 'in_progress_tasks' => 0, 'pending_tasks' => 0, 'blocked_tasks' => 0, 'total_hours_worked' => 0, 'progress_percent' => 0];
    $escalations = []; $profile = [];
}
$statusFilter = $_GET['status'] ?? '';
$blockFilter = isset($_GET['block']) && $_GET['block'] !== '' ? intval($_GET['block']) : 0;
$searchQ = trim($_GET['q'] ?? '');
try {
    $tasks = ($statusFilter !== '' || $blockFilter > 0 || $searchQ !== '') && !$ceoError
        ? DailyTask::all($statusFilter, $blockFilter, $searchQ) : [];
} catch (Throwable $e) { $tasks = []; }

// --- SCCRM alignment snapshot (local CRM db) ---
$crm = ['leads' => 0, 'open_tasks' => 0, 'open_tickets' => 0, 'traces' => 0, 'flows' => 0];
try {
    $crm['leads'] = (int)$db->query("SELECT COUNT(*) FROM leads WHERE status = 'new'")->fetchColumn();
    $crm['open_tasks'] = (int)$db->query("SELECT COUNT(*) FROM tasks WHERE status != 'completed' AND status != 'cancelled'")->fetchColumn();
    $crm['open_tickets'] = (int)$db->query("SELECT COUNT(*) FROM support_tickets WHERE status NOT IN ('resolved','closed')")->fetchColumn();
    $crm['flows'] = (int)$db->query("SELECT COUNT(*) FROM autoflows WHERE is_active = 1")->fetchColumn();
} catch (Throwable $e) { /* ignore */ }
// --- Trace alignment: latest OSINT intel ---
$recentTraces = [];
try {
    $osintDbPath = dirname(__DIR__, 2) . '/data/osint.db';
    if (file_exists($osintDbPath)) {
        $opdo = new PDO('sqlite:' . $osintDbPath);
        $recentTraces = $opdo->query("SELECT url, title, status_code, seo_score, security_score, created_at FROM url_traces ORDER BY created_at DESC LIMIT 6")->fetchAll(PDO::FETCH_ASSOC);
        $c = $opdo->query("SELECT COUNT(*) FROM url_traces WHERE created_at >= datetime('now','-1 day')")->fetchColumn();
        $crm['traces'] = (int)$c;
    }
} catch (Throwable $e) { /* ignore */ }

$dhakaNow = (new DateTime('now', new DateTimeZone('Asia/Dhaka')))->format('Y-m-d H:i:s');
$ceoDbPath = method_exists('ScitbdCeo', 'dbPath') ? ScitbdCeo::dbPath() : '';
require_once __DIR__ . '/operational_plan.php';
$planSlots = ceoOperationalPlan();
$slaRows = ceoSlaStandards();
// Which plan slots are already queued (last 24h, BST-safe)?
$queuedTitles = [];
try {
    foreach (ScitbdCeo::db()->query("SELECT task_title FROM daily_tasks WHERE created_at >= datetime('now','-1 day')") as $r) {
        $queuedTitles[$r['task_title']] = true;
    }
} catch (Throwable $e) { /* ignore */ }
$planQueued = 0;
foreach ($planSlots as $s) { if (isset($queuedTitles[ceoPlanTaskTitle($s)])) $planQueued++; }
// Live SLA signals from the CRM side.
$slaLive = ['tickets' => (int)$crm['open_tasks'] * 0, 'big_leads' => 0, 'new_leads' => 0];
try {
    $slaLive['tickets'] = (int)$db->query("SELECT COUNT(*) FROM support_tickets WHERE status NOT IN ('resolved','closed')")->fetchColumn();
    $slaLive['new_leads'] = (int)$db->query("SELECT COUNT(*) FROM leads WHERE status = 'new'")->fetchColumn();
    $slaLive['big_leads'] = (int)$db->query("SELECT COUNT(*) FROM leads WHERE budget >= 10000 AND status NOT IN ('won','lost')")->fetchColumn();
} catch (Throwable $e) { /* ignore */ }

// --- AI Content Studio data: services + recent TinyLLM drafts ---
$studioServices = [];
$studioDrafts = [];
try {
    $studioServices = $db->query("SELECT id, name FROM services WHERE is_active = 1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
    $studioDrafts = $db->query("SELECT id, title, tags, created_at FROM knowledge_articles WHERE status = 'draft' ORDER BY id DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { /* ignore */ }

require_once __DIR__ . '/../includes/header.php';

function ceo_priority_badge($p) {
    $map = ['critical' => 'danger', 'high' => 'danger', 'medium' => 'warning', 'low' => 'success'];
    return '<span class="badge bg-' . ($map[strtolower($p)] ?? 'secondary') . '">' . htmlspecialchars(ucfirst($p)) . '</span>';
}
function ceo_status_badge($s) {
    $map = ['pending' => 'warning', 'in_progress' => 'info', 'completed' => 'success', 'done' => 'success', 'blocked' => 'danger', 'cancelled' => 'secondary'];
    return '<span class="badge-status ' . ($s === 'completed' || $s === 'done' ? 'completed' : ($s === 'in_progress' ? 'in_progress' : ($s === 'blocked' || $s === 'cancelled' ? 'cancelled' : 'pending'))) . '">' . htmlspecialchars(str_replace('_', ' ', ucfirst($s))) . '</span>';
}
?>
<div class="page-title-area">
    <div>
        <h4><i class="fas fa-crown me-2 text-warning"></i>CEO Office — <?= htmlspecialchars($briefing['company'] ?? 'SCITBD') ?></h4>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="<?= htmlspecialchars($SCCRM_BASE) ?>/index.php" class="text-decoration-none">Dashboard</a></li>
                <li class="breadcrumb-item active">CEO Office</li>
            </ol>
        </nav>
    </div>
    <div class="d-flex gap-2 align-items-center">
        <span class="badge bg-dark fs-6"><i class="fas fa-clock me-1"></i><?= htmlspecialchars($block['block_name'] ?? ('Block ' . ($block['id'] ?? '?'))) ?> · <?= htmlspecialchars($dhakaNow) ?> BST</span>
        <button class="btn btn-primary btn-sm rounded-pill" data-bs-toggle="modal" data-bs-target="#ceoTaskModal"><i class="fas fa-plus me-1"></i> New CEO Task</button>
    </div>
</div>
<?php if ($ceoDbPath !== ''): ?>
<div class="alert alert-light border mb-4" style="font-size:12px;">
    <i class="fas fa-database me-1 text-secondary"></i> Live CEO store: <code><?= htmlspecialchars($ceoDbPath) ?></code>
    <span class="text-muted">— shared with Tasks UI, Slack bridge &amp; chatops (same daily_tasks / task_logs).</span>
</div>
<?php endif; ?>

<?php if ($ceoError): ?>
<div class="alert alert-danger"><i class="fas fa-exclamation-triangle me-1"></i><?= htmlspecialchars($ceoError) ?></div>
<?php endif; ?>

<div class="row g-3 mb-4">
    <div class="col-xl-2 col-lg-4 col-md-6"><div class="stats-card"><div class="icon orange"><i class="fas fa-list-check"></i></div><div class="number"><?= (int)$briefing['pending_tasks'] ?></div><div class="label">CEO Tasks Open</div></div></div>
    <div class="col-xl-2 col-lg-4 col-md-6"><div class="stats-card"><div class="icon red"><i class="fas fa-bell"></i></div><div class="number"><?= count($escalations) ?></div><div class="label">Escalations</div></div></div>
    <div class="col-xl-2 col-lg-4 col-md-6"><div class="stats-card"><div class="icon blue"><i class="fas fa-flag-checkered"></i></div><div class="number"><?= (int)$crm['leads'] ?></div><div class="label">New CRM Leads</div></div></div>
    <div class="col-xl-2 col-lg-4 col-md-6"><div class="stats-card"><div class="icon purple"><i class="fas fa-tasks"></i></div><div class="number"><?= (int)$crm['open_tasks'] ?></div><div class="label">CRM Tasks Open</div></div></div>
    <div class="col-xl-2 col-lg-4 col-md-6"><div class="stats-card"><div class="icon green"><i class="fas fa-crosshairs"></i></div><div class="number"><?= (int)$crm['traces'] ?></div><div class="label">Traces (24h)</div></div></div>
    <div class="col-xl-2 col-lg-4 col-md-6"><div class="stats-card"><div class="icon blue"><i class="fas fa-chart-line"></i></div><div class="number"><?= (float)$summary['progress_percent'] ?>%</div><div class="label">Today Progress</div></div></div>
</div>

<div class="row g-3 mb-4">
    <div class="col-xl-8">
        <div class="card-crm">
            <div class="card-header">
                <h6><i class="fas fa-globe me-2 text-primary"></i>24-Hour Operational Cycle <small class="text-muted">(<?= $planQueued ?>/16 slots queued)</small></h6>
                <span class="d-flex gap-1">
                    <form method="POST" action="<?= htmlspecialchars($SCCRM_BASE) ?>/ceo/plan_seed.php" class="d-inline">
                        <button class="btn btn-sm btn-success rounded-pill"><i class="fas fa-download me-1"></i> Install Today's Plan</button>
                    </form>
                    <a href="<?= htmlspecialchars($SCCRM_BASE) ?>/ceo/plan.ics.php" class="btn btn-sm btn-outline-secondary rounded-pill"><i class="fas fa-calendar-arrow-down me-1"></i> .ics for Google/Outlook</a>
                </span>
            </div>
            <div class="card-body">
                <div class="accordion" id="planAccordion">
                    <?php
                    $blockNames = [1 => 'Block 1 · 06–12 BST · South Asia', 2 => 'Block 2 · 12–18 BST · Middle East / EU', 3 => 'Block 3 · 18–00 BST · UK / North America', 4 => 'Block 4 · 00–06 BST · Oceania / Reboot'];
                    foreach ([1, 2, 3, 4] as $b):
                        $isNow = ((int)($block['id'] ?? 0)) === $b;
                    ?>
                    <div class="accordion-item">
                        <h2 class="accordion-header">
                            <button class="accordion-button <?= $isNow ? '' : 'collapsed' ?>" type="button" data-bs-toggle="collapse" data-bs-target="#planB<?= $b ?>">
                                <?= htmlspecialchars($blockNames[$b]) ?> <?= $isNow ? '<span class="badge bg-success ms-2">NOW</span>' : '' ?>
                            </button>
                        </h2>
                        <div id="planB<?= $b ?>" class="accordion-collapse collapse <?= $isNow ? 'show' : '' ?>" data-bs-parent="#planAccordion">
                            <div class="accordion-body p-0">
                                <table class="table table-crm mb-0">
                                    <tbody>
                                        <?php foreach ($planSlots as $s): if ((int)$s['block'] !== $b) continue;
                                            $done = isset($queuedTitles[ceoPlanTaskTitle($s)]); ?>
                                        <tr style="font-size:13px;">
                                            <td style="white-space:nowrap;" class="text-muted"><?= htmlspecialchars($s['start'] . '–' . $s['end']) ?></td>
                                            <td><div class="fw-semibold"><?= htmlspecialchars($s['title']) ?> <?= $done ? '<i class="fas fa-check-circle text-success" title="Queued"></i>' : '' ?></div>
                                                <div class="text-muted small"><?= htmlspecialchars($s['detail']) ?></div></td>
                                            <td><?= ceo_priority_badge($s['priority']) ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
    <div class="col-xl-4">
        <div class="card-crm border-warning">
            <div class="card-header"><h6><i class="fas fa-gauge-high me-2 text-warning"></i>SLA Standards</h6></div>
            <div class="card-body p-0">
                <table class="table table-crm mb-0">
                    <tbody>
                        <?php foreach ($slaRows as $sla): ?>
                        <tr style="font-size:12px;">
                            <td><?= htmlspecialchars($sla['trigger']) ?><div class="text-muted"><?= htmlspecialchars($sla['protocol']) ?></div></td>
                            <td style="white-space:nowrap;"><span class="badge bg-dark"><?= htmlspecialchars($sla['target']) ?></span></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <div class="p-3 d-flex gap-2 flex-wrap" style="font-size:12px;">
                    <span class="badge bg-light text-dark border">🎫 <?= (int)$slaLive['tickets'] ?> open tickets</span>
                    <span class="badge bg-light text-dark border">📥 <?= (int)$slaLive['new_leads'] ?> new leads</span>
                    <span class="badge bg-light text-dark border">💰 <?= (int)$slaLive['big_leads'] ?> ≥ $10k pipeline</span>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-xl-8">
        <div class="card-crm">
            <div class="card-header">
                <h6><i class="fas fa-list me-2 text-primary"></i>Current Block Queue — <?= htmlspecialchars($block['block_name'] ?? '') ?>
                    <small class="text-muted">(<?= htmlspecialchars(($block['bst_start'] ?? '') . '–' . ($block['bst_end'] ?? '')) ?> BST)</small>
                </h6>
                <form method="GET" class="d-flex gap-1">
                    <input type="text" name="q" class="form-control form-control-sm" placeholder="Filter tasks..." value="<?= htmlspecialchars($searchQ) ?>" style="max-width:160px;">
                    <select name="status" class="form-select form-select-sm" style="max-width:130px;" onchange="this.form.submit()">
                        <option value="">All statuses</option>
                        <?php foreach (['pending','in_progress','blocked','completed','cancelled'] as $st): ?>
                        <option value="<?= $st ?>" <?= $statusFilter === $st ? 'selected' : '' ?>><?= ucfirst($st) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php if ($statusFilter !== '' || $searchQ !== ''): ?><a href="<?= htmlspecialchars($SCCRM_BASE) ?>/ceo/" class="btn btn-sm btn-outline-secondary">Clear</a><?php endif; ?>
                </form>
            </div>
            <div class="card-body p-0">
                <?php $list = ($statusFilter !== '' || $searchQ !== '') ? $tasks : $queue; ?>
                <?php if (count($list)): ?>
                <div class="table-responsive">
                    <table class="table table-crm mb-0">
                        <thead><tr><th>#</th><th>Task</th><th>Priority</th><th>Status</th><th>Due</th><th style="width:150px;">Set status</th></tr></thead>
                        <tbody>
                            <?php foreach ($list as $t): ?>
                            <tr style="font-size:13px;">
                                <td class="text-muted"><?= (int)$t['id'] ?></td>
                                <td><div class="fw-semibold"><?= htmlspecialchars($t['task_title']) ?></div><div class="text-muted small"><?= htmlspecialchars($t['category'] ?? '') ?> · <?= htmlspecialchars($t['assignee'] ?? '') ?></div></td>
                                <td><?= ceo_priority_badge($t['priority'] ?? 'medium') ?></td>
                                <td><?= ceo_status_badge($t['status'] ?? 'pending') ?></td>
                                <td class="text-muted"><?= htmlspecialchars($t['due_date'] ?? '-') ?></td>
                                <td>
                                    <form method="POST" action="<?= htmlspecialchars($SCCRM_BASE) ?>/ceo/save.php" class="d-flex gap-1">
                                        <input type="hidden" name="op" value="status"><input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
                                        <button name="status" value="in_progress" class="btn btn-sm btn-outline-info" title="Start"><i class="fas fa-play"></i></button>
                                        <button name="status" value="completed" class="btn btn-sm btn-outline-success" title="Done"><i class="fas fa-check"></i></button>
                                        <button name="status" value="blocked" class="btn btn-sm btn-outline-danger" title="Blocked"><i class="fas fa-ban"></i></button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                <div class="empty-state"><i class="fas fa-check-circle"></i><h6>Queue clear ✅</h6><p class="text-muted">No pending tasks in this block.</p></div>
                <?php endif; ?>
            </div>
        </div>
        <?php if (count($escalations)): ?>
        <div class="card-crm mt-3 border-danger">
            <div class="card-header"><h6 class="text-danger"><i class="fas fa-bell me-2"></i>Escalations (<?= count($escalations) ?>)</h6></div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-crm mb-0">
                        <thead><tr><th>Trigger</th><th>SLA</th><th>Detail</th></tr></thead>
                        <tbody>
                            <?php foreach (array_slice($escalations, 0, 8) as $e): ?>
                            <tr style="font-size:13px;"><td><?= htmlspecialchars($e['trigger']) ?></td><td><span class="badge bg-danger"><?= htmlspecialchars($e['sla']) ?></span></td><td class="text-muted"><?= htmlspecialchars($e['detail']) ?></td></tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
    <div class="col-xl-4">
        <div class="card-crm mb-3">
            <div class="card-header"><h6><i class="fas fa-chart-pie me-2 text-success"></i>Today — <?= htmlspecialchars($summary['date']) ?></h6></div>
            <div class="card-body" style="font-size:13px;">
                <div class="d-flex justify-content-between mb-1"><span>Total</span><strong><?= (int)$summary['total_tasks'] ?></strong></div>
                <div class="progress mb-2" style="height:10px;"><div class="progress-bar bg-success" style="width:<?= (float)$summary['progress_percent'] ?>%"></div></div>
                <div class="d-flex justify-content-between"><span>✅ Completed</span><strong><?= (int)$summary['completed_tasks'] ?></strong></div>
                <div class="d-flex justify-content-between"><span>🔄 In progress</span><strong><?= (int)$summary['in_progress_tasks'] ?></strong></div>
                <div class="d-flex justify-content-between"><span>⏳ Pending</span><strong><?= (int)$summary['pending_tasks'] ?></strong></div>
                <div class="d-flex justify-content-between"><span>🚫 Blocked</span><strong><?= (int)$summary['blocked_tasks'] ?></strong></div>
                <div class="d-flex justify-content-between"><span>🕐 Hours</span><strong><?= (float)$summary['total_hours_worked'] ?>h</strong></div>
            </div>
        </div>
        <div class="card-crm mb-3">
            <div class="card-header"><h6><i class="fas fa-crosshairs me-2 text-info"></i>Latest Trace Intel</h6><a href="<?= htmlspecialchars($SCCRM_BASE) ?>/trace/" class="btn btn-sm btn-outline-info rounded-pill">Trace</a></div>
            <div class="card-body p-0">
                <?php if (count($recentTraces)): ?>
                <div class="list-group list-group-flush">
                    <?php foreach ($recentTraces as $tr): ?>
                    <div class="list-group-item" style="font-size:12px;">
                        <div class="fw-semibold text-break"><?= htmlspecialchars($tr['title'] ?: $tr['url']) ?></div>
                        <div class="text-muted text-break"><?= htmlspecialchars($tr['url']) ?></div>
                        <div class="mt-1 d-flex gap-1 align-items-center">
                            <span class="badge bg-<?= ((int)$tr['status_code'] < 400 && (int)$tr['status_code'] > 0) ? 'success' : 'secondary' ?>"><?= htmlspecialchars((string)($tr['status_code'] ?? '-')) ?></span>
                            <span class="badge bg-light text-dark border">SEO <?= (int)$tr['seo_score'] ?></span>
                            <span class="badge bg-light text-dark border">SEC <?= (int)$tr['security_score'] ?></span>
                            <a href="<?= htmlspecialchars($SCCRM_BASE) ?>/autoflows/push_trace.php?url=<?= urlencode($tr['url']) ?>" class="btn btn-sm btn-outline-info rounded-pill ms-auto" title="Create lead"><i class="fas fa-user-plus"></i></a>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                <div class="empty-state py-3"><i class="fas fa-crosshairs"></i><h6>No traces yet</h6></div>
                <?php endif; ?>
            </div>
        </div>
        <div class="card-crm mb-3 border-primary">
            <div class="card-header"><h6><i class="fas fa-pen-nib me-2 text-primary"></i>AI Content Studio <small class="text-muted">TinyLLM</small></h6></div>
            <div class="card-body">
                <form method="POST" action="<?= htmlspecialchars($SCCRM_BASE) ?>/ceo/content.php" class="row g-2">
                    <div class="col-12"><input type="text" name="topic" class="form-control" placeholder="Topic — e.g. AI chatbots for banks" required maxlength="200"></div>
                    <div class="col-md-6">
                        <select name="kind" class="form-select">
                            <option value="blog">Blog article</option>
                            <option value="social">Social pack</option>
                            <option value="email">Nurture email</option>
                            <option value="video">Video script</option>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <select name="service_id" class="form-select">
                            <option value="0">No service angle</option>
                            <?php foreach ($studioServices as $sv): ?><option value="<?= (int)$sv['id'] ?>"><?= htmlspecialchars($sv['name']) ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12"><button class="btn btn-primary w-100 rounded-pill"><i class="fas fa-wand-magic-sparkles me-1"></i> Generate Draft</button></div>
                </form>
                <?php if (count($studioDrafts)): ?>
                <hr><div class="fw-semibold mb-1" style="font-size:12px;">Recent AI drafts</div>
                <div class="list-group list-group-flush">
                    <?php foreach ($studioDrafts as $d): ?>
                    <a href="<?= htmlspecialchars($SCCRM_BASE) ?>/knowledge/edit.php?id=<?= (int)$d['id'] ?>" class="list-group-item list-group-item-action" style="font-size:12px;">
                        <span class="fw-semibold">#<?= (int)$d['id'] ?> <?= htmlspecialchars(mb_substr($d['title'] ?? '', 0, 60)) ?></span>
                        <span class="text-muted d-block"><?= htmlspecialchars($d['created_at'] ?? '') ?></span>
                    </a>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <div class="card-crm">
            <div class="card-header"><h6><i class="fas fa-link me-2 text-secondary"></i>Aligned Systems</h6></div>
            <div class="card-body" style="font-size:13px;">
                <form method="GET" action="<?= htmlspecialchars($SCCRM_BASE) ?>/ai/" class="input-group mb-2">
                    <span class="input-group-text bg-transparent"><i class="fas fa-robot text-primary"></i></span>
                    <input type="text" name="q" class="form-control" placeholder="Ask TinyLLM anything…" aria-label="Ask TinyLLM">
                    <button class="btn btn-primary">Ask</button>
                </form>
                <div class="d-grid gap-2">
                <a href="<?= htmlspecialchars($SCCRM_BASE) ?>/ai/" class="btn btn-outline-primary btn-sm rounded-pill"><i class="fas fa-robot me-1"></i> Open AI Chat</a>
                <a href="<?= htmlspecialchars($SCCRM_BASE) ?>/leads/" class="btn btn-outline-primary btn-sm rounded-pill"><i class="fas fa-flag-checkered me-1"></i> CRM Leads (<?= (int)$crm['leads'] ?> new)</a>
                <a href="<?= htmlspecialchars($SCCRM_BASE) ?>/tasks/" class="btn btn-outline-secondary btn-sm rounded-pill"><i class="fas fa-tasks me-1"></i> CRM Tasks (<?= (int)$crm['open_tasks'] ?> open)</a>
                <a href="<?= htmlspecialchars($SCCRM_BASE) ?>/autoflows/" class="btn btn-outline-warning btn-sm rounded-pill"><i class="fas fa-wand-magic-sparkles me-1"></i> AutoFlows (<?= (int)$crm['flows'] ?> active)</a>
                <a href="<?= htmlspecialchars($OSINT_BASE) ?>/trace/url_trace.php" target="_blank" rel="noopener" class="btn btn-outline-info btn-sm rounded-pill"><i class="fas fa-crosshairs me-1"></i> Trace App</a>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- New CEO task modal -->
<div class="modal fade" id="ceoTaskModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" action="<?= htmlspecialchars($SCCRM_BASE) ?>/ceo/save.php" class="modal-content">
            <div class="modal-header"><h5 class="modal-title">New CEO Task</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body row g-2">
                <input type="hidden" name="op" value="create">
                <div class="col-12"><label class="form-label">Title</label><input name="task_title" class="form-control" required maxlength="200"></div>
                <div class="col-12"><label class="form-label">Description</label><textarea name="task_description" class="form-control" rows="2"></textarea></div>
                <div class="col-md-6"><label class="form-label">Priority</label>
                    <select name="priority" class="form-select"><option>low</option><option selected>medium</option><option>high</option><option>critical</option></select>
                </div>
                <div class="col-md-6"><label class="form-label">Category</label>
                    <select name="category" class="form-select"><option>operations</option><option>marketing</option><option>sales</option><option>development</option><option>client</option><option>finance</option><option>admin</option><option>ai</option><option>general</option></select>
                </div>
                <div class="col-md-6"><label class="form-label">Assignee</label><input name="assignee" class="form-control" value="ceo"></div>
                <div class="col-md-6"><label class="form-label">Block</label>
                    <select name="bst_block_id" class="form-select"><option value="1">Block 1 (06–12)</option><option value="2">Block 2 (12–18)</option><option value="3">Block 3 (18–00)</option><option value="4">Block 4 (00–06)</option></select>
                </div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-secondary rounded-pill" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary rounded-pill">Create</button></div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
