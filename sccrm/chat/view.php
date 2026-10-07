<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
$_sccrm_db_boot = require_once __DIR__ . '/../config/database.php';
if (($_sccrm_db_boot instanceof PDO) && (!isset($db) || !($db instanceof PDO))) { $db = $_sccrm_db_boot; }
$id = $_GET['id'] ?? 0;
$t = $db->prepare("SELECT t.*, c.first_name, c.last_name, c.email, co.name as company_name FROM support_tickets t LEFT JOIN contacts c ON t.contact_id = c.id LEFT JOIN companies co ON t.company_id = co.id WHERE t.id = ?");
$t->execute([$id]); $t = $t->fetch();
if (!$t) { header('Location: index.php'); exit; }

$messages = $db->prepare("SELECT * FROM support_messages WHERE ticket_id = ? ORDER BY created_at ASC");
$messages->execute([$id]);
$messages = $messages->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['message'])) {
    $stmt = $db->prepare("INSERT INTO support_messages (ticket_id, sender, message, is_staff) VALUES (?,?,?,?)");
    $stmt->execute([$id, $_POST['sender'] ?: 'Agent', $_POST['message'], $_POST['is_staff'] ?? 0]);
    $db->prepare("UPDATE support_tickets SET status = CASE WHEN status = 'resolved' THEN 'resolved' WHEN status = 'closed' THEN 'closed' ELSE 'in_progress' END, updated_at = CURRENT_TIMESTAMP WHERE id = ?")->execute([$id]);
    $_SESSION['flash'] = ['type' => 'success', 'message' => 'Reply added!'];
    header('Location: view.php?id=' . $id);
    exit;
}

// AI triage (ai-support-operations pattern): classify on view + manual re-run.
$triage = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['triage_rerun'])) {
    require_once __DIR__ . '/../ai/ticket_triage_agent.php';
    $triage = ticketTriage($db, $id, true);
}
if ($triage === null) {
    try {
        require_once __DIR__ . '/../ai/ticket_triage_agent.php';
        $triage = ticketTriage($db, $id);
    } catch (Throwable $e) {
        $triage = null;
    }
}

// AI draft (ai-response-generator pattern): suggest, don't send.
$aiDraft = null;
$aiProvider = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ai_draft'])) {
    require_once __DIR__ . '/../ai/ticket_reply_agent.php';
    $rag = trim((string)($_POST['rag'] ?? ''));
    $res = ticketReplySuggest($db, $id, $rag);
    if (!empty($res['ok'])) {
        $aiDraft = $res['draft'];
        $aiProvider = $res['provider'];
    } else {
        $_SESSION['flash'] = ['type' => 'danger', 'message' => 'AI draft failed: ' . ($res['error'] ?? '?')];
    }
}

if (isset($_GET['action']) && isset($_GET['status'])) {
    $allowed = ['open','in_progress','waiting','resolved','closed'];
    if (in_array($_GET['status'], $allowed)) {
        $db->prepare("UPDATE support_tickets SET status = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?")->execute([$_GET['status'], $id]);
        $_SESSION['flash'] = ['type' => 'success', 'message' => 'Ticket status updated!'];
        header('Location: view.php?id=' . $id);
        exit;
    }
}

$status_colors = ['open'=>'#3498db','in_progress'=>'#f39c12','waiting'=>'#9b59b6','resolved'=>'#2ecc71','closed'=>'#95a5a6'];
$priority_colors = ['low'=>'secondary','normal'=>'primary','high'=>'warning','urgent'=>'danger'];
$next_statuses = ['open'=>['in_progress'=>'Start Working','waiting'=>'Ask Customer','resolved'=>'Resolve','closed'=>'Close'],'in_progress'=>['waiting'=>'Wait Reply','resolved'=>'Resolve','closed'=>'Close'],'waiting'=>['in_progress'=>'Resume','resolved'=>'Resolve','closed'=>'Close'],'resolved'=>['closed'=>'Close','open'=>'Reopen'],'closed'=>['open'=>'Reopen']];
require_once __DIR__ . '/../includes/header.php';
?>
<div class="page-title-area">
    <div>
        <h4><i class="fas fa-ticket-alt me-2 text-success"></i>Ticket #<?= $id ?>: <?= htmlspecialchars($t['subject']) ?></h4>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="<?= $SCCRM_BASE ?>/index.php" class="text-decoration-none">Dashboard</a></li>
                <li class="breadcrumb-item"><a href="<?= $SCCRM_BASE ?>/chat/" class="text-decoration-none">Support</a></li>
                <li class="breadcrumb-item active">Ticket #<?= $id ?></li>
            </ol>
        </nav>
    </div>
</div>

<div class="row g-3">
    <div class="col-xl-9">
        <div class="card-crm mb-3">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h6 class="mb-0"><i class="fas fa-comments me-2"></i>Conversation (<?= count($messages) ?> messages)</h6>
                <span class="badge-status px-3" style="background:<?= $status_colors[$t['status']] ?>20;color:<?= $status_colors[$t['status']] ?>;border:1px solid <?= $status_colors[$t['status']] ?>40;"><?= str_replace('_',' ',ucfirst($t['status'])) ?></span>
            </div>
            <div class="card-body" style="max-height:500px;overflow-y:auto;">
                <?php if (count($messages) > 0): ?>
                    <?php foreach ($messages as $msg): ?>
                    <div class="d-flex mb-3 <?= $msg['is_staff'] ? 'justify-content-start' : 'justify-content-end' ?>">
                        <div class="rounded-3 p-3 <?= $msg['is_staff'] ? 'bg-light' : 'bg-primary text-white' ?>" style="max-width:80%;<?= $msg['is_staff'] ? '' : 'border-bottom-right-radius:4px;' ?>">
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <strong style="font-size:12px;"><?= htmlspecialchars($msg['is_staff'] ? 'SCIT Support' : ($msg['sender'] ?? 'Customer')) ?></strong>
                                <small style="font-size:11px;opacity:0.7;"><?= date('M j, g:i A', strtotime($msg['created_at'])) ?></small>
                            </div>
                            <div style="font-size:14px;line-height:1.5;"><?= nl2br(htmlspecialchars($msg['message'])) ?></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php else: ?>
                <div class="empty-state py-3"><i class="fas fa-comment-dots"></i><h6>No messages yet</h6></div>
                <?php endif; ?>
            </div>
        </div>

        <div class="card-crm">
            <div class="card-header"><h6><i class="fas fa-reply me-2"></i>Add Reply</h6></div>
            <div class="card-body">
                <form method="POST">
                    <div class="mb-3">
                        <textarea name="message" class="form-control" rows="3" required placeholder="Type your reply..."><?= isset($aiDraft) ? htmlspecialchars($aiDraft) : '' ?></textarea>
                        <?php if (isset($aiDraft)): ?><small class="text-success"><i class="fas fa-robot me-1"></i>AI draft (<?= htmlspecialchars($aiProvider ?? '?') ?>) — review, edit, then Send.</small><?php endif; ?>
                    </div>
                    <div class="row g-2">
                        <div class="col-md-4">
                            <input type="text" name="sender" class="form-control" value="SCIT Support" placeholder="Your name">
                        </div>
                        <div class="col-md-3">
                            <div class="form-check pt-2">
                                <input type="checkbox" name="is_staff" class="form-check-input" value="1" checked id="isStaff">
                                <label class="form-check-label" for="isStaff">Staff reply</label>
                            </div>
                        </div>
                        <div class="col-md-5 text-end">
                            <button type="submit" class="btn btn-primary rounded-pill px-4"><i class="fas fa-paper-plane me-1"></i> Send Reply</button>
                        </div>
                    </div>
                </form>
                <form method="POST" class="row g-2 mt-2">
                    <div class="col-md-9">
                        <input type="text" name="rag" class="form-control form-control-sm" placeholder="Optional extra context for the AI (order #, policy note…)">
                    </div>
                    <div class="col-md-3 text-end">
                        <button type="submit" name="ai_draft" value="1" class="btn btn-outline-success btn-sm rounded-pill px-3"><i class="fas fa-robot me-1"></i> Generate AI reply</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <div class="col-xl-3">
        <div class="card-crm mb-3">
            <div class="card-header"><h6>Ticket Details</h6></div>            <div class="card-body">
                <div class="mb-2"><div class="detail-label">Status</div><div class="detail-value"><?= str_replace('_',' ',ucfirst($t['status'])) ?></div></div>
                <div class="mb-2"><div class="detail-label">Priority</div><div class="detail-value"><span class="badge bg-<?= $priority_colors[$t['priority']] ?>"><?= ucfirst($t['priority']) ?></span></div></div>
                <div class="mb-2"><div class="detail-label">Department</div><div class="detail-value"><?= ucfirst($t['department']) ?></div></div>
                <div class="mb-2"><div class="detail-label">Created By</div><div class="detail-value"><?= htmlspecialchars($t['created_by'] ?? '-') ?></div></div>
                <div class="mb-2"><div class="detail-label">Created</div><div class="detail-value"><?= date('M j, Y', strtotime($t['created_at'])) ?></div></div>
                <?php if ($t['contact_id']): ?>
                <div class="mb-2"><div class="detail-label">Contact</div><div class="detail-value"><a href="<?= $SCCRM_BASE ?>/contacts/view.php?id=<?= $t['contact_id'] ?>" class="text-decoration-none"><?= htmlspecialchars($t['first_name'].' '.$t['last_name']) ?></a></div></div>
                <?php endif; ?>
                <?php if ($t['company_id']): ?>
                <div><div class="detail-label">Company</div><div class="detail-value"><a href="<?= $SCCRM_BASE ?>/companies/view.php?id=<?= $t['company_id'] ?>" class="text-decoration-none"><?= htmlspecialchars($t['company_name']) ?></a></div></div>
                <?php endif; ?>
            </div>
        </div>
        <?php if (!empty($triage['ok'])): ?>
        <div class="card-crm mb-3">
            <div class="card-header"><h6><i class="fas fa-robot me-1"></i>AI Triage</h6></div>
            <div class="card-body" style="font-size:12px;">
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <span class="badge bg-info"><?= htmlspecialchars($triage['category']) ?></span>
                    <small class="text-muted"><?= (int)$triage['confidence'] ?>% conf · <?= htmlspecialchars($triage['provider']) ?></small>
                </div>
                <div class="progress mb-2" style="height:6px;"><div class="progress-bar" style="width:<?= (int)$triage['confidence'] ?>%"></div></div>
                <?php if (!empty($triage['escalate'])): ?><div class="alert alert-warning p-2 mb-2"><i class="fas fa-hand-paper me-1"></i>Needs human review</div><?php endif; ?>
                <div class="text-muted mb-2"><?= htmlspecialchars(mb_substr($triage['reason'] ?? '', 0, 200)) ?></div>
                <form method="POST"><button type="submit" name="triage_rerun" value="1" class="btn btn-sm btn-outline-secondary rounded-pill w-100">Re-run triage</button></form>
            </div>
        </div>
        <?php endif; ?>
        <div class="card-crm">
            <div class="card-header"><h6>Change Status</h6></div>
            <div class="card-body p-2">
                <?php if (isset($next_statuses[$t['status']])): ?>
                    <?php foreach ($next_statuses[$t['status']] as $st => $label): ?>
                    <a href="?id=<?= $id ?>&action=status&status=<?= $st ?>" class="btn w-100 mb-1 rounded-pill btn-sm <?= in_array($st, ['resolved','closed']) ? 'btn-outline-success' : ($st === 'open' ? 'btn-outline-primary' : 'btn-outline-secondary') ?>">
                        <i class="fas fa-<?= $st === 'resolved' ? 'check' : ($st === 'closed' ? 'times' : ($st === 'open' ? 'undo' : 'arrow-right')) ?> me-1"></i> <?= $label ?>
                    </a>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
        <div class="card-crm mt-3">
            <div class="card-header"><h6>Quick Links</h6></div>
            <div class="card-body p-2">
                <a href="<?= $SCCRM_BASE ?>/knowledge/" class="btn btn-outline-info btn-sm w-100 rounded-pill mb-1"><i class="fas fa-book me-1"></i> Knowledge Bank</a>
                <a href="<?= $SCCRM_BASE ?>/faq/" class="btn btn-outline-info btn-sm w-100 rounded-pill"><i class="fas fa-question-circle me-1"></i> FAQ</a>
            </div>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
