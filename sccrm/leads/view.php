<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
$_sccrm_db_boot = require_once __DIR__ . '/../config/database.php';
if (($_sccrm_db_boot instanceof PDO) && (!isset($db) || !($db instanceof PDO))) { $db = $_sccrm_db_boot; }
$id = $_GET['id'] ?? 0;
$lead = $db->prepare("SELECT l.*, m.name as market_name, m.country_code, m.currency, s.name as service_name, s.category as service_category, s.icon as service_icon,
        c.first_name as contact_first, c.last_name as contact_last, co.name as company_name
        FROM leads l
        LEFT JOIN markets m ON l.market_id = m.id
        LEFT JOIN services s ON l.service_id = s.id
        LEFT JOIN contacts c ON l.contact_id = c.id
        LEFT JOIN companies co ON l.company_id = co.id
        WHERE l.id = ?");
$lead->execute([$id]); $lead = $lead->fetch();
if (!$lead) { header('Location: index.php'); exit; }

$full_name = ($lead['first_name'] ?? '') . ' ' . ($lead['last_name'] ?? '');
$status_colors = ['new'=>'#3498db','contacted'=>'#f39c12','qualified'=>'#9b59b6','proposal'=>'#e67e22','negotiation'=>'#1abc9c','won'=>'#2ecc71','lost'=>'#e74c3c'];
$score_class = $lead['score'] >= 80 ? 'bg-success' : ($lead['score'] >= 50 ? 'bg-warning text-dark' : 'bg-secondary');
require_once __DIR__ . '/../includes/header.php';
?>
<div class="page-title-area">
    <div>
        <h4><i class="fas fa-flag-checkered me-2 text-warning"></i><?= htmlspecialchars($full_name ?: 'Unknown Lead') ?></h4>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="<?= $SCCRM_BASE ?>/index.php" class="text-decoration-none">Dashboard</a></li>
                <li class="breadcrumb-item"><a href="<?= $SCCRM_BASE ?>/leads/" class="text-decoration-none">Leads</a></li>
                <li class="breadcrumb-item active"><?= htmlspecialchars($full_name ?: 'Lead') ?></li>
            </ol>
        </nav>
    </div>
    <div class="d-flex gap-2">
        <a href="<?= $SCCRM_BASE ?>/leads/edit.php?id=<?= $id ?>" class="btn btn-primary rounded-pill px-3"><i class="fas fa-edit me-1"></i> Edit</a>
        <?php if ($lead['contact_id']): ?>
        <a href="<?= $SCCRM_BASE ?>/contacts/view.php?id=<?= $lead['contact_id'] ?>" class="btn btn-info rounded-pill px-3 text-white"><i class="fas fa-user me-1"></i> View Contact</a>
        <?php endif; ?>
    </div>
</div>

<div class="row g-3">
    <div class="col-xl-4">
        <div class="card-crm">
            <div class="card-body text-center">
                <div class="avatar-circle mx-auto" style="width:80px;height:80px;font-size:32px;background:<?= avatarColor($id) ?>"><?= getInitials($full_name) ?></div>
                <h5 class="mt-3 mb-1"><?= htmlspecialchars($full_name) ?></h5>
                <p class="text-muted mb-2"><?= htmlspecialchars($lead['position'] ?? 'Lead') ?></p>
                <div class="d-flex justify-content-center gap-2 mb-2">
                    <span class="badge-status px-3" style="background:<?= $status_colors[$lead['status']] ?? '#95a5a6' ?>20;color:<?= $status_colors[$lead['status']] ?? '#95a5a6' ?>;border:1px solid <?= $status_colors[$lead['status']] ?? '#95a5a6' ?>40;"><?= str_replace('_',' ',ucfirst($lead['status'])) ?></span>
                    <span class="badge badge-<?= $lead['priority'] === 'urgent' ? 'danger' : ($lead['priority'] === 'high' ? 'warning' : ($lead['priority'] === 'medium' ? 'primary' : 'secondary')) ?>"><?= ucfirst($lead['priority']) ?></span>
                </div>
                <div><span class="badge <?= $score_class ?>" style="font-size:14px;padding:6px 16px;">Score: <?= $lead['score'] ?>/100</span></div>
            </div>
        </div>
        <div class="card-crm mt-3">
            <div class="card-header"><h6>Lead Details</h6></div>
            <div class="card-body">
                <div class="mb-3"><div class="detail-label">Email</div><div class="detail-value"><?= htmlspecialchars($lead['email'] ?? '-') ?></div></div>
                <div class="mb-3"><div class="detail-label">Phone</div><div class="detail-value"><?= htmlspecialchars($lead['phone'] ?? '-') ?></div></div>
                <div class="mb-3"><div class="detail-label">Company</div><div class="detail-value"><?= htmlspecialchars($lead['company_name'] ?? '-') ?></div></div>
                <div class="mb-3"><div class="detail-label">Position</div><div class="detail-value"><?= htmlspecialchars($lead['position'] ?? '-') ?></div></div>
                <div class="mb-3"><div class="detail-label">Source</div><div class="detail-value"><?= htmlspecialchars(ucfirst(str_replace('_',' ',$lead['source'] ?? 'manual'))) ?></div></div>
                <div class="mb-3"><div class="detail-label">Budget</div><div class="detail-value"><?= $lead['budget'] ? '$' . number_format($lead['budget'], 2) : '-' ?></div></div>
                <div class="mb-0"><div class="detail-label">Created</div><div class="detail-value"><?= $lead['created_at'] ? date('M j, Y g:i A', strtotime($lead['created_at'])) : '-' ?></div></div>
            </div>
        </div>
    </div>
    <div class="col-xl-8">
        <div class="row g-3 mb-3">
            <div class="col-md-4">
                <div class="card-crm">
                    <div class="card-body text-center py-3">
                        <div class="fw-bold" style="font-size:24px;color:<?= $status_colors[$lead['status']] ?? '#95a5a6' ?>;"><?= str_replace('_',' ',ucfirst($lead['status'])) ?></div>
                        <div class="text-muted" style="font-size:12px;">Current Status</div>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card-crm">
                    <div class="card-body text-center py-3">
                        <div class="fw-bold" style="font-size:24px;color:#3498db;"><?= htmlspecialchars($lead['market_name'] ?? '-') ?></div>
                        <div class="text-muted" style="font-size:12px;">Target Market</div>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card-crm">
                    <div class="card-body text-center py-3">
                        <div class="fw-bold" style="font-size:24px;color:#2ecc71;"><i class="fas <?= htmlspecialchars($lead['service_icon'] ?? 'fa-cog') ?> me-1"></i></div>
                        <div class="text-muted" style="font-size:12px;"><?= htmlspecialchars($lead['service_name'] ?? '-') ?></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="card-crm">
            <div class="card-header"><h6>Activity Timeline</h6></div>
            <div class="card-body">
                <div class="activity-timeline">
                    <?php if ($lead['converted_at']): ?>
                    <div class="timeline-item" style="--tl-color:#2ecc71;">
                        <div class="time"><?= date('M j, Y g:i A', strtotime($lead['converted_at'])) ?></div>
                        <div class="title" style="color:#27ae60;"><i class="fas fa-check-circle me-1"></i> Lead Converted</div>
                        <div class="desc">Lead status changed to Won</div>
                    </div>
                    <?php endif; ?>
                    <?php if ($lead['contacted_at']): ?>
                    <div class="timeline-item" style="--tl-color:#f39c12;">
                        <div class="time"><?= date('M j, Y g:i A', strtotime($lead['contacted_at'])) ?></div>
                        <div class="title" style="color:#e67e22;"><i class="fas fa-phone me-1"></i> Lead Contacted</div>
                        <div class="desc">First contact attempt made</div>
                    </div>
                    <?php endif; ?>
                    <?php if ($lead['generated_at']): ?>
                    <div class="timeline-item" style="--tl-color:#3498db;">
                        <div class="time"><?= date('M j, Y g:i A', strtotime($lead['generated_at'])) ?></div>
                        <div class="title" style="color:#2980b9;"><i class="fas fa-bolt me-1"></i> Lead Generated</div>
                        <div class="desc">Generated via <?= str_replace('_',' ',ucfirst($lead['source'] ?? 'system')) ?></div>
                    </div>
                    <?php endif; ?>
                    <div class="timeline-item" style="--tl-color:#95a5a6;">
                        <div class="time"><?= date('M j, Y g:i A', strtotime($lead['created_at'])) ?></div>
                        <div class="title" style="color:#7f8c8d;"><i class="fas fa-plus-circle me-1"></i> Lead Created</div>
                        <div class="desc">Lead entered into the system</div>
                    </div>
                </div>
            </div>
        </div>
        <?php if ($lead['notes']): ?>
        <div class="card-crm mt-3">
            <div class="card-header"><h6>Notes</h6></div>
            <div class="card-body"><p class="mb-0"><?= nl2br(htmlspecialchars($lead['notes'])) ?></p></div>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
