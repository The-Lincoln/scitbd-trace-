<?php
session_start();
require_once __DIR__ . '/../includes/header.php';
$status_filter = $_GET['status'] ?? '';
$dept_filter = $_GET['department'] ?? '';
$search = $_GET['search'] ?? '';
$review_filter = isset($_GET['review']) && $_GET['review'] === '1';

// Human review queue (ai-support-operations pattern): escalated + still open.
try {
    require_once __DIR__ . '/../ai/ticket_triage_agent.php';
    ticketTriageEnsureTable($db);
} catch (Throwable $e) {
}

$sql = "SELECT t.*, c.first_name, c.last_name, co.name as company_name,
        (SELECT COUNT(*) FROM support_messages WHERE ticket_id = t.id) as msg_count,
        (SELECT message FROM support_messages WHERE ticket_id = t.id ORDER BY created_at DESC LIMIT 1) as last_msg
        FROM support_tickets t
        LEFT JOIN contacts c ON t.contact_id = c.id
        LEFT JOIN companies co ON t.company_id = co.id
        WHERE 1=1";
$params = [];
if ($status_filter) { $sql .= " AND t.status = ?"; $params[] = $status_filter; }
if ($dept_filter) { $sql .= " AND t.department = ?"; $params[] = $dept_filter; }
if ($search) { $sql .= " AND (t.subject LIKE ? OR t.message LIKE ?)"; $params = array_merge($params, ["%$search%","%$search%"]); }
if ($review_filter) { $sql .= " AND EXISTS (SELECT 1 FROM ticket_triage tr WHERE tr.ticket_id = t.id AND tr.escalate = 1) AND t.status NOT IN ('resolved','closed')"; }
$sql .= " ORDER BY COALESCE(t.updated_at, t.created_at) DESC";
$tickets = $db->prepare($sql); $tickets->execute($params); $tickets = $tickets->fetchAll();

$open_count = $db->query("SELECT COUNT(*) FROM support_tickets WHERE status NOT IN ('resolved','closed')")->fetchColumn();
$total_tickets = $db->query("SELECT COUNT(*) FROM support_tickets")->fetchColumn();
?>
<div class="page-title-area">
    <div>
        <h4><i class="fas fa-headset me-2 text-success"></i>Customer Support</h4>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="<?= $SCCRM_BASE ?>/index.php" class="text-decoration-none">Dashboard</a></li>
                <li class="breadcrumb-item active">Support Tickets</li>
            </ol>
        </nav>
    </div>
    <div class="d-flex gap-2">
        <a href="<?= $SCCRM_BASE ?>/chat/create.php" class="btn btn-success rounded-pill px-3"><i class="fas fa-plus me-1"></i> New Ticket</a>
        <a href="<?= $SCCRM_BASE ?>/faq/" class="btn btn-outline-info rounded-pill px-3"><i class="fas fa-question-circle me-1"></i> FAQ</a>
    </div>
</div>

<div class="row g-2 mb-4">
    <div class="col-md-3"><div class="stats-card"><div class="icon blue"><i class="fas fa-ticket"></i></div><div class="number"><?= $total_tickets ?></div><div class="label">Total Tickets</div></div></div>
    <div class="col-md-3"><div class="stats-card"><div class="icon orange"><i class="fas fa-spinner"></i></div><div class="number"><?= $open_count ?></div><div class="label">Open Tickets</div></div></div>
    <div class="col-md-3"><div class="stats-card"><div class="icon purple"><i class="fas fa-hourglass-half"></i></div>
        <div class="number"><?= $db->query("SELECT COUNT(*) FROM support_tickets WHERE status = 'in_progress'")->fetchColumn() ?></div>
        <div class="label">In Progress</div></div></div>
    <div class="col-md-3"><div class="stats-card"><div class="icon green"><i class="fas fa-check-circle"></i></div>
        <div class="number"><?= $db->query("SELECT COUNT(*) FROM support_tickets WHERE status = 'resolved'")->fetchColumn() ?></div>
        <div class="label">Resolved</div></div></div>
</div>

<div class="card-crm">
    <div class="card-header">
        <form method="GET" class="d-flex gap-2 flex-wrap">
            <div class="input-group input-group-sm" style="width:200px;">
                <span class="input-group-text bg-transparent"><i class="fas fa-search text-muted"></i></span>
                <input type="text" name="search" class="form-control" placeholder="Search tickets..." value="<?= htmlspecialchars($search) ?>">
            </div>
            <select name="status" class="form-select form-select-sm" onchange="this.form.submit()" style="width:auto;">
                <option value="">All Status</option>
                <?php foreach (['open','in_progress','waiting','resolved','closed'] as $st): ?>
                <option value="<?= $st ?>" <?= $status_filter === $st ? 'selected' : '' ?>><?= str_replace('_',' ',ucfirst($st)) ?></option>
                <?php endforeach; ?>
            </select>
            <select name="department" class="form-select form-select-sm" onchange="this.form.submit()" style="width:auto;">
                <option value="">All Departments</option>
                <?php foreach (['general','technical','billing','sales','support'] as $d): ?>
                <option value="<?= $d ?>" <?= $dept_filter === $d ? 'selected' : '' ?>><?= ucfirst($d) ?></option>
                <?php endforeach; ?>
            </select>
            <?php if ($search || $status_filter || $dept_filter || $review_filter): ?><a href="<?= $SCCRM_BASE ?>/chat/" class="btn btn-sm btn-outline-secondary">Clear</a><?php endif; ?>
            <a href="<?= $SCCRM_BASE ?>/chat/?review=1" class="btn btn-sm <?= $review_filter ? 'btn-warning' : 'btn-outline-warning' ?>"><i class="fas fa-hand-paper me-1"></i>Needs human<?= $review_filter ? '' : ' (' . (int)$db->query("SELECT COUNT(*) FROM ticket_triage tr JOIN support_tickets t ON t.id = tr.ticket_id WHERE tr.escalate = 1 AND t.status NOT IN ('resolved','closed')")->fetchColumn() . ')' ?></a>
        </form>
    </div>
    <div class="card-body p-0">
        <?php if (count($tickets) > 0): ?>
        <div class="table-responsive">
            <table class="table table-crm">
                <thead><tr><th>Ticket</th><th>Subject</th><th>Contact</th><th>Dept</th><th>Priority</th><th>Status</th><th>Messages</th><th>Last Activity</th><th>Actions</th></tr></thead>
                <tbody>
                    <?php foreach ($tickets as $t):
                        $priority_colors = ['low'=>'secondary','normal'=>'primary','high'=>'warning','urgent'=>'danger'];
                        $status_colors = ['open'=>'#3498db','in_progress'=>'#f39c12','waiting'=>'#9b59b6','resolved'=>'#2ecc71','closed'=>'#95a5a6'];
                    ?>
                    <tr>
                        <td><span class="badge bg-light text-dark">#<?= $t['id'] ?></span></td>
                        <td><a href="<?= $SCCRM_BASE ?>/chat/view.php?id=<?= $t['id'] ?>" class="fw-semibold text-decoration-none" style="font-size:14px;"><?= htmlspecialchars($t['subject']) ?></a></td>
                        <td style="font-size:13px;"><?= htmlspecialchars(($t['first_name'] ?? '') . ' ' . ($t['last_name'] ?? $t['created_by'] ?? '-')) ?></td>
                        <td><span class="badge bg-light text-dark" style="font-size:11px;"><?= ucfirst($t['department']) ?></span></td>
                        <td><span class="badge bg-<?= $priority_colors[$t['priority']] ?> text-white" style="font-size:11px;"><?= ucfirst($t['priority']) ?></span></td>
                        <td><span class="badge-status px-3" style="background:<?= $status_colors[$t['status']] ?>20;color:<?= $status_colors[$t['status']] ?>;border:1px solid <?= $status_colors[$t['status']] ?>40;"><?= str_replace('_',' ',ucfirst($t['status'])) ?></span></td>
                        <td><span class="badge bg-light text-dark"><?= $t['msg_count'] ?></span></td>
                        <td style="font-size:12px;color:var(--gray);"><?= timeAgo($t['updated_at']) ?></td>
                        <td>
                            <div class="d-flex gap-1">
                                <a href="<?= $SCCRM_BASE ?>/chat/view.php?id=<?= $t['id'] ?>" class="btn btn-action btn-outline-info" data-bs-toggle="tooltip" title="View & Reply"><i class="fas fa-eye"></i></a>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <div class="empty-state"><i class="fas fa-ticket-alt"></i><h6>No support tickets</h6>
            <p class="text-muted">Create a ticket or check the FAQ for answers.</p>
            <div class="d-flex gap-2 justify-content-center mt-2">
                <a href="<?= $SCCRM_BASE ?>/chat/create.php" class="btn btn-success btn-sm rounded-pill"><i class="fas fa-plus me-1"></i> New Ticket</a>
                <a href="<?= $SCCRM_BASE ?>/faq/" class="btn btn-outline-info btn-sm rounded-pill"><i class="fas fa-question-circle me-1"></i> FAQ</a>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
