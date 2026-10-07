<?php
session_start();
require_once __DIR__ . '/../includes/header.php';

$status_filter = $_GET['status'] ?? '';
$market_filter = $_GET['market_id'] ?? '';
$service_filter = $_GET['service_id'] ?? '';
$source_filter = $_GET['source'] ?? '';
$search = $_GET['search'] ?? '';

$sql = "SELECT l.*, m.name as market_name, s.name as service_name, s.icon as service_icon,
        c.first_name, c.last_name, co.name as company_name
        FROM leads l
        LEFT JOIN markets m ON l.market_id = m.id
        LEFT JOIN services s ON l.service_id = s.id
        LEFT JOIN contacts c ON l.contact_id = c.id
        LEFT JOIN companies co ON l.company_id = co.id
        WHERE 1=1";
$params = [];
if ($status_filter) { $sql .= " AND l.status = ?"; $params[] = $status_filter; }
if ($market_filter) { $sql .= " AND l.market_id = ?"; $params[] = $market_filter; }
if ($service_filter) { $sql .= " AND l.service_id = ?"; $params[] = $service_filter; }
if ($source_filter) { $sql .= " AND l.source = ?"; $params[] = $source_filter; }
if ($search) { $sql .= " AND (l.first_name LIKE ? OR l.last_name LIKE ? OR l.email LIKE ? OR l.company_name LIKE ?)"; $params = array_merge($params, ["%$search%","%$search%","%$search%","%$search%"]); }
$sql .= " ORDER BY l.created_at DESC";
$leads = $db->prepare($sql);
$leads->execute($params);
$leads = $leads->fetchAll();

$markets = $db->query("SELECT id, name FROM markets WHERE is_active = 1 ORDER BY name")->fetchAll();
$services = $db->query("SELECT id, name FROM services WHERE is_active = 1 ORDER BY name")->fetchAll();

$pipeline = $db->query("SELECT status, COUNT(*) as count FROM leads GROUP BY status")->fetchAll();
$total_leads = $db->query("SELECT COUNT(*) FROM leads")->fetchColumn();
$new_leads = $db->query("SELECT COUNT(*) FROM leads WHERE status = 'new'")->fetchColumn();
$won_leads = $db->query("SELECT COUNT(*) FROM leads WHERE status = 'won'")->fetchColumn();
$today_leads = $db->query("SELECT COUNT(*) FROM leads WHERE date(created_at) = date('now')")->fetchColumn();
?>
<div class="page-title-area">
    <div>
        <h4>Leads Pipeline</h4>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="<?= $SCCRM_BASE ?>/index.php" class="text-decoration-none">Dashboard</a></li>
                <li class="breadcrumb-item active">Leads</li>
            </ol>
        </nav>
    </div>
    <div class="d-flex gap-2">
        <a href="<?= $SCCRM_BASE ?>/leads/generate.php" class="btn btn-success rounded-pill px-3"><i class="fas fa-bolt me-1"></i> Generate Leads</a>
        <a href="<?= $SCCRM_BASE ?>/leads/create.php" class="btn btn-primary rounded-pill px-3"><i class="fas fa-plus me-1"></i> Add Lead</a>
    </div>
</div>

<div class="row g-2 mb-4">
    <div class="col-xl-2 col-lg-4 col-md-6">
        <div class="stats-card">
            <div class="icon blue"><i class="fas fa-users"></i></div>
            <div class="number"><?= $total_leads ?></div>
            <div class="label">Total Leads</div>
        </div>
    </div>
    <div class="col-xl-2 col-lg-4 col-md-6">
        <div class="stats-card">
            <div class="icon orange"><i class="fas fa-star"></i></div>
            <div class="number"><?= $new_leads ?></div>
            <div class="label">New Leads</div>
        </div>
    </div>
    <div class="col-xl-2 col-lg-4 col-md-6">
        <div class="stats-card">
            <div class="icon green"><i class="fas fa-check-circle"></i></div>
            <div class="number"><?= $won_leads ?></div>
            <div class="label">Converted</div>
        </div>
    </div>
    <div class="col-xl-2 col-lg-4 col-md-6">
        <div class="stats-card">
            <div class="icon purple"><i class="fas fa-trophy"></i></div>
            <div class="number"><?= $total_leads > 0 ? round(($won_leads / $total_leads) * 100) : 0 ?>%</div>
            <div class="label">Conversion Rate</div>
        </div>
    </div>
    <div class="col-xl-2 col-lg-4 col-md-6">
        <div class="stats-card">
            <div class="icon red"><i class="fas fa-calendar-day"></i></div>
            <div class="number"><?= $today_leads ?></div>
            <div class="label">Today's Leads</div>
        </div>
    </div>
    <div class="col-xl-2 col-lg-4 col-md-6">
        <div class="stats-card">
            <div class="icon blue"><i class="fas fa-clock"></i></div>
            <div class="number" id="lastGenTime">-</div>
            <div class="label">Last Generation</div>
        </div>
    </div>
</div>

<?php if (count($pipeline) > 0): ?>
<div class="row g-2 mb-4">
    <div class="col-12">
        <div class="card-crm">
            <div class="card-body">
                <div class="d-flex align-items-center gap-2 overflow-auto py-1">
                    <?php
                    $status_colors = ['new'=>'#3498db','contacted'=>'#f39c12','qualified'=>'#9b59b6','proposal'=>'#e67e22','negotiation'=>'#1abc9c','won'=>'#2ecc71','lost'=>'#e74c3c'];
                    $total_pipeline = array_sum(array_column($pipeline, 'count')) ?: 1;
                    foreach ($pipeline as $p):
                        $pct = round(($p['count'] / $total_pipeline) * 100);
                    ?>
                    <div class="text-center px-3">
                        <div class="fw-bold" style="font-size:22px;color:<?= $status_colors[$p['status']] ?? '#95a5a6' ?>;"><?= $p['count'] ?></div>
                        <div style="font-size:11px;color:#95a5a6;text-transform:uppercase;letter-spacing:0.5px;"><?= str_replace('_',' ',ucfirst($p['status'])) ?></div>
                        <div class="progress" style="height:4px;width:60px;margin:4px auto;">
                            <div class="progress-bar" style="width:<?= $pct ?>%;background:<?= $status_colors[$p['status']] ?? '#95a5a6' ?>;"></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="card-crm">
    <div class="card-header">
        <form method="GET" class="d-flex gap-2 flex-wrap align-items-center">
            <div class="input-group input-group-sm" style="width:180px;">
                <span class="input-group-text bg-transparent"><i class="fas fa-search text-muted"></i></span>
                <input type="text" name="search" class="form-control" placeholder="Search leads..." value="<?= htmlspecialchars($search) ?>">
            </div>
            <select name="status" class="form-select form-select-sm" onchange="this.form.submit()" style="width:auto;">
                <option value="">All Status</option>
                <?php foreach (['new','contacted','qualified','proposal','negotiation','won','lost'] as $st): ?>
                <option value="<?= $st ?>" <?= $status_filter === $st ? 'selected' : '' ?>><?= ucfirst($st) ?></option>
                <?php endforeach; ?>
            </select>
            <select name="market_id" class="form-select form-select-sm" onchange="this.form.submit()" style="width:auto;">
                <option value="">All Markets</option>
                <?php foreach ($markets as $m): ?>
                <option value="<?= $m['id'] ?>" <?= $market_filter == $m['id'] ? 'selected' : '' ?>><?= htmlspecialchars($m['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <select name="service_id" class="form-select form-select-sm" onchange="this.form.submit()" style="width:auto;">
                <option value="">All Services</option>
                <?php foreach ($services as $s): ?>
                <option value="<?= $s['id'] ?>" <?= $service_filter == $s['id'] ? 'selected' : '' ?>><?= htmlspecialchars($s['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <select name="source" class="form-select form-select-sm" onchange="this.form.submit()" style="width:auto;">
                <option value="">All Sources</option>
                <?php foreach (['manual','hourly_generator','web','referral','campaign'] as $sr): ?>
                <option value="<?= $sr ?>" <?= $source_filter === $sr ? 'selected' : '' ?>><?= str_replace('_',' ',ucfirst($sr)) ?></option>
                <?php endforeach; ?>
            </select>
            <?php if ($search || $status_filter || $market_filter || $service_filter || $source_filter): ?>
            <a href="<?= $SCCRM_BASE ?>/leads/" class="btn btn-sm btn-outline-secondary">Clear</a>
            <?php endif; ?>
        </form>
    </div>
    <div class="card-body p-0">
        <?php if (count($leads) > 0): ?>
        <div class="table-responsive">
            <table class="table table-crm">
                <thead><tr><th>Lead</th><th>Company</th><th>Market</th><th>Service</th><th>Source</th><th>Score</th><th>Status</th><th>Created</th><th>Actions</th></tr></thead>
                <tbody>
                    <?php foreach ($leads as $l): 
                        $score_class = $l['score'] >= 80 ? 'bg-success' : ($l['score'] >= 50 ? 'bg-warning text-dark' : 'bg-secondary');
                        $status_colors_map = ['new'=>'#3498db','contacted'=>'#f39c12','qualified'=>'#9b59b6','proposal'=>'#e67e22','negotiation'=>'#1abc9c','won'=>'#2ecc71','lost'=>'#e74c3c'];
                    ?>
                    <tr>
                        <td>
                            <div class="contact-row">
                                <div class="avatar-circle avatar-sm" style="background:<?= avatarColor($l['id']) ?>"><?= getInitials(($l['first_name'] ?? '?') . ' ' . ($l['last_name'] ?? '?')) ?></div>
                                <div class="info">
                                    <a href="<?= $SCCRM_BASE ?>/leads/view.php?id=<?= $l['id'] ?>" class="name text-decoration-none"><?= htmlspecialchars(($l['first_name'] ?? '') . ' ' . ($l['last_name'] ?? '')) ?></a>
                                    <div class="sub"><?= htmlspecialchars($l['email'] ?? '') ?></div>
                                </div>
                            </div>
                        </td>
                        <td style="font-size:13px;"><?= htmlspecialchars($l['company_name'] ?? '-') ?></td>
                        <td><span class="badge bg-light text-dark" style="font-size:11px;"><?= htmlspecialchars($l['market_name'] ?? '-') ?></span></td>
                        <td style="font-size:13px;"><?= htmlspecialchars($l['service_name'] ?? '-') ?></td>
                        <td><span class="badge bg-light text-dark" style="font-size:11px;"><?= str_replace('_',' ',ucfirst($l['source'] ?? 'manual')) ?></span></td>
                        <td><span class="badge <?= $score_class ?>" style="font-size:11px;"><?= $l['score'] ?></span></td>
                        <td>
                            <span class="badge-status px-3" style="background:<?= $status_colors_map[$l['status']] ?? '#95a5a6' ?>20;color:<?= $status_colors_map[$l['status']] ?? '#95a5a6' ?>;border:1px solid <?= $status_colors_map[$l['status']] ?? '#95a5a6' ?>40;">
                                <?= str_replace('_',' ',ucfirst($l['status'])) ?>
                            </span>
                        </td>
                        <td style="font-size:12px;color:var(--gray);"><?= timeAgo($l['created_at']) ?></td>
                        <td>
                            <div class="d-flex gap-1">
                                <a href="<?= $SCCRM_BASE ?>/leads/view.php?id=<?= $l['id'] ?>" class="btn btn-action btn-outline-info" data-bs-toggle="tooltip" title="View"><i class="fas fa-eye"></i></a>
                                <a href="<?= $SCCRM_BASE ?>/leads/edit.php?id=<?= $l['id'] ?>" class="btn btn-action btn-outline-primary" data-bs-toggle="tooltip" title="Edit"><i class="fas fa-edit"></i></a>
                                <a href="<?= $SCCRM_BASE ?>/leads/delete.php?id=<?= $l['id'] ?>" class="btn btn-action btn-outline-danger" data-confirm="Delete this lead?" data-bs-toggle="tooltip" title="Delete"><i class="fas fa-trash"></i></a>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <div class="empty-state">
            <i class="fas fa-flag-checkered"></i>
            <h6>No leads yet</h6>
            <p class="text-muted">Generate leads automatically or add them manually.</p>
            <div class="d-flex gap-2 justify-content-center mt-2">
                <a href="<?= $SCCRM_BASE ?>/leads/generate.php" class="btn btn-success btn-sm rounded-pill px-3"><i class="fas fa-bolt me-1"></i> Generate Now</a>
                <a href="<?= $SCCRM_BASE ?>/leads/create.php" class="btn btn-primary btn-sm rounded-pill px-3"><i class="fas fa-plus me-1"></i> Add Manually</a>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>
<script>
fetch((window.SCCRM_BASE || '') + '/leads/logs.php?last=1')
    .then(r => r.json())
    .then(d => { if (d.time) document.getElementById('lastGenTime').textContent = d.time; })
    .catch(() => {});
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
