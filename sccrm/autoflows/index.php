<?php
// SCCRM > AutoFlows — automation dashboard (POST handled before layout output).
if (session_status() === PHP_SESSION_NONE) { session_start(); }
$_sccrm_db_boot = require_once __DIR__ . '/../config/database.php';
if (($_sccrm_db_boot instanceof PDO) && (!isset($db) || !($db instanceof PDO))) { $db = $_sccrm_db_boot; }
require_once __DIR__ . '/autoflow_engine.php';
autoflowEnsureTables($db);

require_once __DIR__ . '/../includes/header.php';

$flows = $db->query("SELECT * FROM autoflows ORDER BY is_active DESC, id")->fetchAll(PDO::FETCH_ASSOC);
$runs = $db->query("SELECT r.*, f.name as flow_name FROM autoflow_runs r LEFT JOIN autoflows f ON f.id = r.flow_id ORDER BY r.created_at DESC LIMIT 15")->fetchAll(PDO::FETCH_ASSOC);
$triggers = autoflowTriggers();
$actions = autoflowActions();
$stats = [
    'active' => (int)$db->query("SELECT COUNT(*) FROM autoflows WHERE is_active = 1")->fetchColumn(),
    'runs_24h' => 0,
    'errors_24h' => 0,
];
try {
    $stats['runs_24h'] = (int)$db->query("SELECT COUNT(*) FROM autoflow_runs WHERE created_at >= datetime('now','-1 day')")->fetchColumn();
    $stats['errors_24h'] = (int)$db->query("SELECT COUNT(*) FROM autoflow_runs WHERE status != 'success' AND created_at >= datetime('now','-1 day')")->fetchColumn();
} catch (Throwable $e) { /* ignore */ }
?>
<div class="page-title-area">
    <div>
        <h4><i class="fas fa-wand-magic-sparkles me-2 text-warning"></i>AutoFlows</h4>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="<?= htmlspecialchars($SCCRM_BASE) ?>/index.php" class="text-decoration-none">Dashboard</a></li>
                <li class="breadcrumb-item active">AutoFlows</li>
            </ol>
        </nav>
    </div>
    <div class="d-flex gap-2">
        <a href="<?= htmlspecialchars($SCCRM_BASE) ?>/autoflows/push_trace.php" class="btn btn-outline-info btn-sm rounded-pill"><i class="fas fa-crosshairs me-1"></i> Trace → Lead</a>
        <button class="btn btn-primary btn-sm rounded-pill" data-bs-toggle="modal" data-bs-target="#flowModal"><i class="fas fa-plus me-1"></i> New Flow</button>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-md-4"><div class="stats-card"><div class="icon green"><i class="fas fa-bolt"></i></div><div class="number"><?= $stats['active'] ?></div><div class="label">Active Flows</div></div></div>
    <div class="col-md-4"><div class="stats-card"><div class="icon blue"><i class="fas fa-play"></i></div><div class="number"><?= $stats['runs_24h'] ?></div><div class="label">Runs (24h)</div></div></div>
    <div class="col-md-4"><div class="stats-card"><div class="icon <?= $stats['errors_24h'] ? 'red' : 'purple' ?>"><i class="fas fa-triangle-exclamation"></i></div><div class="number"><?= $stats['errors_24h'] ?></div><div class="label">Errors (24h)</div></div></div>
</div>

<div class="card-crm mb-4">
    <div class="card-header"><h6><i class="fas fa-diagram-project me-2 text-primary"></i>Flows (<?= count($flows) ?>)</h6></div>
    <div class="card-body p-0">
        <?php if (count($flows)): ?>
        <div class="table-responsive">
            <table class="table table-crm mb-0">
                <thead><tr><th>Flow</th><th>When</th><th>Do</th><th>Status</th><th>Runs</th><th>Last run</th><th style="width:190px;">Actions</th></tr></thead>
                <tbody>
                    <?php foreach ($flows as $f):
                        $cfg = json_decode($f['config'] ?? '{}', true) ?: [];
                    ?>
                    <tr style="font-size:13px;">
                        <td><div class="fw-semibold"><?= htmlspecialchars($f['name']) ?></div><div class="text-muted small"><?= htmlspecialchars($f['description'] ?? '') ?></div></td>
                        <td><span class="badge bg-light text-dark border"><?= htmlspecialchars($triggers[$f['trigger']] ?? $f['trigger']) ?></span></td>
                        <td><span class="badge bg-light text-dark border"><?= htmlspecialchars($actions[$f['action']] ?? $f['action']) ?></span>
                            <?php if (!empty($cfg['min_score'])): ?><div class="text-muted small">min score <?= (int)$cfg['min_score'] ?></div><?php endif; ?>
                            <?php if (!empty($cfg['url'])): ?><div class="text-muted small text-break"><?= htmlspecialchars($cfg['url']) ?></div><?php endif; ?>
                        </td>
                        <td><?= $f['is_active'] ? '<span class="badge-status completed">Active</span>' : '<span class="badge-status cancelled">Paused</span>' ?></td>
                        <td><?= (int)$f['run_count'] ?></td>
                        <td class="text-muted"><?= htmlspecialchars($f['last_run_at'] ?? '-') ?></td>
                        <td>
                            <form method="POST" action="<?= htmlspecialchars($SCCRM_BASE) ?>/autoflows/run.php" class="d-inline">
                                <input type="hidden" name="id" value="<?= (int)$f['id'] ?>">
                                <button class="btn btn-sm btn-outline-success rounded-pill" title="Run now"><i class="fas fa-play"></i></button>
                            </form>
                            <form method="POST" action="<?= htmlspecialchars($SCCRM_BASE) ?>/autoflows/save.php" class="d-inline">
                                <input type="hidden" name="op" value="toggle"><input type="hidden" name="id" value="<?= (int)$f['id'] ?>">
                                <button class="btn btn-sm btn-outline-secondary rounded-pill" title="<?= $f['is_active'] ? 'Pause' : 'Activate' ?>"><i class="fas fa-<?= $f['is_active'] ? 'pause' : 'play' ?>"></i></button>
                            </form>
                            <form method="POST" action="<?= htmlspecialchars($SCCRM_BASE) ?>/autoflows/save.php" class="d-inline" onsubmit="return confirm('Delete this flow?')">
                                <input type="hidden" name="op" value="delete"><input type="hidden" name="id" value="<?= (int)$f['id'] ?>">
                                <button class="btn btn-sm btn-outline-danger rounded-pill" title="Delete"><i class="fas fa-trash"></i></button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <div class="empty-state"><i class="fas fa-wand-magic-sparkles"></i><h6>No flows yet</h6><p class="text-muted">Create your first automation above.</p></div>
        <?php endif; ?>
    </div>
</div>

<div class="card-crm">
    <div class="card-header"><h6><i class="fas fa-history me-2 text-secondary"></i>Recent Runs</h6></div>
    <div class="card-body p-0">
        <?php if (count($runs)): ?>
        <div class="table-responsive">
            <table class="table table-crm mb-0">
                <thead><tr><th>Time</th><th>Flow</th><th>Event</th><th>Status</th><th>Message</th><th>ms</th></tr></thead>
                <tbody>
                    <?php foreach ($runs as $r): ?>
                    <tr style="font-size:13px;">
                        <td class="text-muted"><?= htmlspecialchars($r['created_at']) ?></td>
                        <td><?= htmlspecialchars($r['flow_name'] ?? ('#' . $r['flow_id'])) ?></td>
                        <td><span class="badge bg-light text-dark border"><?= htmlspecialchars($r['trigger_event']) ?></span></td>
                        <td><?= $r['status'] === 'success' ? '<span class="badge-status completed">OK</span>' : '<span class="badge-status cancelled">' . htmlspecialchars($r['status']) . '</span>' ?></td>
                        <td class="text-break" style="max-width:340px;"><?= htmlspecialchars($r['message'] ?? '') ?></td>
                        <td class="text-muted"><?= htmlspecialchars((string)($r['duration_ms'] ?? '')) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <div class="empty-state py-3"><i class="fas fa-play"></i><h6>No runs yet</h6></div>
        <?php endif; ?>
    </div>
</div>

<!-- New flow modal -->
<div class="modal fade" id="flowModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" action="<?= htmlspecialchars($SCCRM_BASE) ?>/autoflows/save.php" class="modal-content">
            <div class="modal-header"><h5 class="modal-title">New AutoFlow</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body row g-2">
                <input type="hidden" name="op" value="create">
                <div class="col-12"><label class="form-label">Name</label><input name="name" class="form-control" required placeholder="e.g. Enrich imported leads"></div>
                <div class="col-12"><label class="form-label">Description</label><input name="description" class="form-control" placeholder="What does it do?"></div>
                <div class="col-md-6"><label class="form-label">When (trigger)</label>
                    <select name="trigger" class="form-select"><?php foreach ($triggers as $k => $v): ?><option value="<?= $k ?>"><?= htmlspecialchars($v) ?></option><?php endforeach; ?></select>
                </div>
                <div class="col-md-6"><label class="form-label">Do (action)</label>
                    <select name="action" class="form-select" id="flowAction"><?php foreach ($actions as $k => $v): ?><option value="<?= $k ?>"><?= htmlspecialchars($v) ?></option><?php endforeach; ?></select>
                </div>
                <div class="col-md-6"><label class="form-label">Min lead score (tasks)</label><input name="min_score" type="number" class="form-control" value="70" min="0" max="100"></div>
                <div class="col-md-6"><label class="form-label">Follow-up due (days)</label><input name="due_in_days" type="number" class="form-control" value="2" min="0" max="30"></div>
                <div class="col-12"><label class="form-label">Webhook URL (webhook action only)</label><input name="url" class="form-control" placeholder="https://..."></div>
                <div class="col-12"><label class="form-label">Browser URL (browser_* actions)</label><input name="browser_url" class="form-control" placeholder="https://target-site.com"></div>
                <div class="col-12"><label class="form-label">Browser goal / note</label><input name="browser_goal" class="form-control" placeholder="e.g. Research pricing page for lead scoring"></div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-secondary rounded-pill" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary rounded-pill">Create Flow</button></div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
