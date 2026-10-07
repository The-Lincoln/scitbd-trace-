<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
$_sccrm_db_boot = require_once __DIR__ . '/../config/database.php';
if (($_sccrm_db_boot instanceof PDO) && (!isset($db) || !($db instanceof PDO))) { $db = $_sccrm_db_boot; }
$id = $_GET['id'] ?? 0;
$company = $db->prepare("SELECT * FROM companies WHERE id = ?");
$company->execute([$id]);
$company = $company->fetch();
if (!$company) { header('Location: index.php'); exit; }

$contacts = $db->prepare("SELECT * FROM contacts WHERE company_id = ? ORDER BY first_name");
$contacts->execute([$id]);
$contacts = $contacts->fetchAll();

$interactions = $db->prepare("SELECT i.*, c.first_name, c.last_name FROM interactions i LEFT JOIN contacts c ON i.contact_id = c.id WHERE i.company_id = ? ORDER BY i.date DESC LIMIT 10");
$interactions->execute([$id]);
$interactions = $interactions->fetchAll();

$tasks = $db->prepare("SELECT t.*, c.first_name, c.last_name FROM tasks t LEFT JOIN contacts c ON t.contact_id = c.id WHERE t.company_id = ? ORDER BY t.created_at DESC LIMIT 10");
$tasks->execute([$id]);
$tasks = $tasks->fetchAll();
require_once __DIR__ . '/../includes/header.php';
?>
<div class="page-title-area">
    <div>
        <h4><?= htmlspecialchars($company['name']) ?></h4>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="<?= $SCCRM_BASE ?>/index.php" class="text-decoration-none">Dashboard</a></li>
                <li class="breadcrumb-item"><a href="<?= $SCCRM_BASE ?>/companies/" class="text-decoration-none">Companies</a></li>
                <li class="breadcrumb-item active"><?= htmlspecialchars($company['name']) ?></li>
            </ol>
        </nav>
    </div>
    <div class="d-flex gap-2">
        <a href="<?= $SCCRM_BASE ?>/companies/edit.php?id=<?= $id ?>" class="btn btn-primary rounded-pill px-3"><i class="fas fa-edit me-1"></i> Edit</a>
        <a href="<?= $SCCRM_BASE ?>/contacts/create.php?company_id=<?= $id ?>" class="btn btn-success rounded-pill px-3"><i class="fas fa-user-plus me-1"></i> Add Contact</a>
    </div>
</div>

<div class="row g-3">
    <div class="col-xl-4">
        <div class="card-crm">
            <div class="card-body text-center">
                <div class="avatar-circle mx-auto" style="width:80px;height:80px;font-size:32px;background:<?= avatarColor($id) ?>"><i class="fas fa-building"></i></div>
                <h5 class="mt-3 mb-1"><?= htmlspecialchars($company['name']) ?></h5>
                <?php if ($company['industry']): ?><span class="badge bg-light text-dark px-3 py-2"><?= htmlspecialchars($company['industry']) ?></span><?php endif; ?>
            </div>
        </div>
        <div class="card-crm mt-3">
            <div class="card-header"><h6>Company Details</h6></div>
            <div class="card-body">
                <div class="mb-3"><div class="detail-label">Email</div><div class="detail-value"><a href="mailto:<?= htmlspecialchars($company['email'] ?? '') ?>" class="text-decoration-none"><?= htmlspecialchars($company['email'] ?? '-') ?></a></div></div>
                <div class="mb-3"><div class="detail-label">Phone</div><div class="detail-value"><?= htmlspecialchars($company['phone'] ?? '-') ?></div></div>
                <div class="mb-3"><div class="detail-label">Mobile</div><div class="detail-value"><?= htmlspecialchars($company['mobile'] ?? '-') ?></div></div>
                <div class="mb-3"><div class="detail-label">Website</div><div class="detail-value"><?= $company['website'] ? '<a href="' . htmlspecialchars($company['website']) . '" target="_blank" class="text-decoration-none"><i class="fas fa-globe me-1"></i> ' . htmlspecialchars($company['website']) . '</a>' : '-' ?></div></div>
                <div class="mb-3"><div class="detail-label">Address</div><div class="detail-value"><?php $addr = array_filter([$company['address'],$company['city'],$company['state'],$company['zip'],$company['country']]); echo htmlspecialchars(implode(', ', $addr)) ?: '-'; ?></div></div>
            </div>
        </div>
        <?php if ($company['description']): ?>
        <div class="card-crm mt-3">
            <div class="card-header"><h6>Description</h6></div>
            <div class="card-body"><p class="mb-0" style="font-size:14px;"><?= nl2br(htmlspecialchars($company['description'])) ?></p></div>
        </div>
        <?php endif; ?>
    </div>
    <div class="col-xl-8">
        <div class="card-crm mb-3">
            <div class="card-header">
                <h6><i class="fas fa-users me-2 text-primary"></i>Contacts (<?= count($contacts) ?>)</h6>
            </div>
            <div class="card-body p-0">
                <?php if (count($contacts) > 0): ?>
                <div class="table-responsive">
                    <table class="table table-crm">
                        <thead><tr><th>Name</th><th>Position</th><th>Email</th><th>Phone</th></tr></thead>
                        <tbody>
                            <?php foreach ($contacts as $c): ?>
                            <tr>
                                <td>
                                    <div class="contact-row">
                                        <div class="avatar-circle avatar-sm" style="background:<?= avatarColor($c['id']) ?>"><?= getInitials($c['first_name'].' '.$c['last_name']) ?></div>
                                        <div class="info"><a href="<?= $SCCRM_BASE ?>/contacts/view.php?id=<?= $c['id'] ?>" class="name text-decoration-none"><?= htmlspecialchars($c['first_name'].' '.$c['last_name']) ?></a></div>
                                    </div>
                                </td>
                                <td style="font-size:13px;"><?= htmlspecialchars($c['position'] ?? '-') ?></td>
                                <td style="font-size:13px;"><?= htmlspecialchars($c['email'] ?? '-') ?></td>
                                <td style="font-size:13px;"><?= htmlspecialchars($c['phone'] ?? '-') ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                <div class="empty-state"><i class="fas fa-users"></i><h6>No contacts in this company</h6><a href="<?= $SCCRM_BASE ?>/contacts/create.php?company_id=<?= $id ?>" class="btn btn-primary btn-sm mt-2">Add Contact</a></div>
                <?php endif; ?>
            </div>
        </div>
        <div class="card-crm mb-3">
            <div class="card-header">
                <h6><i class="fas fa-comments me-2 text-success"></i>Recent Interactions</h6>
                <a href="<?= $SCCRM_BASE ?>/interactions/create.php?company_id=<?= $id ?>" class="btn btn-sm btn-success rounded-pill"><i class="fas fa-plus"></i> Log</a>
            </div>
            <div class="card-body p-0">
                <?php if (count($interactions) > 0): ?>
                <div class="activity-timeline p-4">
                    <?php foreach ($interactions as $i): ?>
                    <div class="timeline-item">
                        <div class="time"><?= formatDate($i['date']) ?></div>
                        <div class="title"><span class="badge-type <?= $i['type'] ?> me-1"><?= ucfirst($i['type']) ?></span> <?= htmlspecialchars($i['subject']) ?></div>
                        <div class="desc">by <strong><?= htmlspecialchars($i['first_name'].' '.$i['last_name']) ?></strong></div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                <div class="empty-state"><i class="fas fa-comments"></i><h6>No interactions</h6></div>
                <?php endif; ?>
            </div>
        </div>
        <div class="card-crm">
            <div class="card-header">
                <h6><i class="fas fa-tasks me-2 text-warning"></i>Tasks</h6>
                <a href="<?= $SCCRM_BASE ?>/tasks/create.php?company_id=<?= $id ?>" class="btn btn-sm btn-primary rounded-pill"><i class="fas fa-plus"></i> Add</a>
            </div>
            <div class="card-body p-0">
                <?php if (count($tasks) > 0): ?>
                <div class="table-responsive">
                    <table class="table table-crm">
                        <thead><tr><th>Title</th><th>Assigned To</th><th>Priority</th><th>Status</th><th>Due</th></tr></thead>
                        <tbody>
                            <?php foreach ($tasks as $t): ?>
                            <tr>
                                <td><div class="fw-semibold"><?= htmlspecialchars($t['title']) ?></div></td>
                                <td style="font-size:13px;"><?= htmlspecialchars($t['first_name'].' '.$t['last_name'] ?? '-') ?></td>
                                <td><span class="badge-priority <?= $t['priority'] ?>"><?= ucfirst($t['priority']) ?></span></td>
                                <td><span class="badge-status <?= $t['status'] ?>"><?= str_replace('_',' ',ucfirst($t['status'])) ?></span></td>
                                <td style="font-size:13px;color:var(--gray);"><?= $t['due_date'] ? formatDate($t['due_date']) : '-' ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                <div class="empty-state"><i class="fas fa-tasks"></i><h6>No tasks</h6></div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
