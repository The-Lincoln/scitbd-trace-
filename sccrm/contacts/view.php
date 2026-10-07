<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
$_sccrm_db_boot = require_once __DIR__ . '/../config/database.php';
if (($_sccrm_db_boot instanceof PDO) && (!isset($db) || !($db instanceof PDO))) { $db = $_sccrm_db_boot; }
$id = $_GET['id'] ?? 0;
$contact = $db->prepare("SELECT c.*, co.name as company_name, co.industry, co.website as company_website FROM contacts c LEFT JOIN companies co ON c.company_id = co.id WHERE c.id = ?");
$contact->execute([$id]);
$contact = $contact->fetch();
if (!$contact) { header('Location: index.php'); exit; }

$interactions = $db->prepare("SELECT i.*, co.name as company_name FROM interactions i LEFT JOIN companies co ON i.company_id = co.id WHERE i.contact_id = ? ORDER BY i.date DESC LIMIT 10");
$interactions->execute([$id]);
$interactions = $interactions->fetchAll();

$tasks = $db->prepare("SELECT t.*, co.name as company_name FROM tasks t LEFT JOIN companies co ON t.company_id = co.id WHERE t.contact_id = ? ORDER BY t.created_at DESC LIMIT 10");
$tasks->execute([$id]);
$tasks = $tasks->fetchAll();

$notes = $db->prepare("SELECT * FROM notes WHERE contact_id = ? ORDER BY created_at DESC");
$notes->execute([$id]);
$notes = $notes->fetchAll();

$full_name = $contact['first_name'] . ' ' . $contact['last_name'];
require_once __DIR__ . '/../includes/header.php';
?>
<div class="page-title-area">
    <div>
        <h4><?= htmlspecialchars($full_name) ?></h4>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="<?= $SCCRM_BASE ?>/index.php" class="text-decoration-none">Dashboard</a></li>
                <li class="breadcrumb-item"><a href="<?= $SCCRM_BASE ?>/contacts/" class="text-decoration-none">Contacts</a></li>
                <li class="breadcrumb-item active"><?= htmlspecialchars($full_name) ?></li>
            </ol>
        </nav>
    </div>
    <div class="d-flex gap-2">
        <a href="<?= $SCCRM_BASE ?>/contacts/edit.php?id=<?= $id ?>" class="btn btn-primary rounded-pill px-3"><i class="fas fa-edit me-1"></i> Edit</a>
        <a href="<?= $SCCRM_BASE ?>/interactions/create.php?contact_id=<?= $id ?>" class="btn btn-success rounded-pill px-3"><i class="fas fa-comment me-1"></i> Log Interaction</a>
    </div>
</div>

<div class="row g-3">
    <div class="col-xl-4">
        <div class="card-crm">
            <div class="card-body text-center">
                <div class="avatar-circle mx-auto" style="width:80px;height:80px;font-size:32px;background:<?= avatarColor($id) ?>"><?= getInitials($full_name) ?></div>
                <h5 class="mt-3 mb-1"><?= htmlspecialchars($full_name) ?></h5>
                <p class="text-muted mb-2"><?= htmlspecialchars($contact['position'] ?? 'No Position') ?></p>
                <?php if ($contact['company_name']): ?>
                <span class="badge bg-light text-dark px-3 py-2"><?= htmlspecialchars($contact['company_name']) ?></span>
                <?php endif; ?>
            </div>
        </div>
        <div class="card-crm mt-3">
            <div class="card-header"><h6>Contact Details</h6></div>
            <div class="card-body">
                <div class="mb-3"><div class="detail-label">Email</div><div class="detail-value"><a href="mailto:<?= htmlspecialchars($contact['email'] ?? '') ?>" class="text-decoration-none"><?= htmlspecialchars($contact['email'] ?? '-') ?></a></div></div>
                <div class="mb-3"><div class="detail-label">Phone</div><div class="detail-value"><?= htmlspecialchars($contact['phone'] ?? '-') ?></div></div>
                <div class="mb-3"><div class="detail-label">Mobile</div><div class="detail-value"><?= htmlspecialchars($contact['mobile'] ?? '-') ?></div></div>
                <div class="mb-3"><div class="detail-label">Department</div><div class="detail-value"><?= htmlspecialchars($contact['department'] ?? '-') ?></div></div>
                <div class="mb-3"><div class="detail-label">LinkedIn</div><div class="detail-value"><?= $contact['linkedin'] ? '<a href="' . htmlspecialchars($contact['linkedin']) . '" target="_blank" class="text-decoration-none"><i class="fab fa-linkedin me-1"></i> View Profile</a>' : '-' ?></div></div>
                <div class="mb-0"><div class="detail-label">Twitter/X</div><div class="detail-value"><?= htmlspecialchars($contact['twitter'] ?? '-') ?></div></div>
            </div>
        </div>
        <?php if ($contact['notes']): ?>
        <div class="card-crm mt-3">
            <div class="card-header"><h6>Notes</h6></div>
            <div class="card-body"><p class="mb-0" style="font-size:14px;"><?= nl2br(htmlspecialchars($contact['notes'])) ?></p></div>
        </div>
        <?php endif; ?>
    </div>
    <div class="col-xl-8">
        <div class="card-crm mb-3">
            <div class="card-header">
                <h6>Recent Interactions</h6>
                <a href="<?= $SCCRM_BASE ?>/interactions/create.php?contact_id=<?= $id ?>" class="btn btn-sm btn-success rounded-pill"><i class="fas fa-plus"></i> New</a>
            </div>
            <div class="card-body p-0">
                <?php if (count($interactions) > 0): ?>
                <div class="activity-timeline p-4">
                    <?php foreach ($interactions as $i): ?>
                    <div class="timeline-item">
                        <div class="time"><?= formatDate($i['date']) ?></div>
                        <div class="title">
                            <span class="badge-type <?= $i['type'] ?> me-1"><?= ucfirst($i['type']) ?></span>
                            <?= htmlspecialchars($i['subject']) ?>
                        </div>
                        <div class="desc"><?= htmlspecialchars(mb_substr($i['content'] ?? '', 0, 120)) ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                <div class="empty-state"><i class="fas fa-comments"></i><h6>No interactions logged</h6></div>
                <?php endif; ?>
            </div>
        </div>
        <div class="card-crm">
            <div class="card-header">
                <h6>Tasks</h6>
                <a href="<?= $SCCRM_BASE ?>/tasks/create.php?contact_id=<?= $id ?>" class="btn btn-sm btn-primary rounded-pill"><i class="fas fa-plus"></i> New</a>
            </div>
            <div class="card-body p-0">
                <?php if (count($tasks) > 0): ?>
                <div class="table-responsive">
                    <table class="table table-crm">
                        <thead><tr><th>Title</th><th>Priority</th><th>Status</th><th>Due</th></tr></thead>
                        <tbody>
                            <?php foreach ($tasks as $t): ?>
                            <tr>
                                <td><div class="fw-semibold"><?= htmlspecialchars($t['title']) ?></div></td>
                                <td><span class="badge-priority <?= $t['priority'] ?>"><?= ucfirst($t['priority']) ?></span></td>
                                <td><span class="badge-status <?= $t['status'] ?>"><?= str_replace('_',' ',ucfirst($t['status'])) ?></span></td>
                                <td style="font-size:13px;color:var(--gray);"><?= $t['due_date'] ? formatDate($t['due_date']) : '-' ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                <div class="empty-state"><i class="fas fa-tasks"></i><h6>No tasks assigned</h6></div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
