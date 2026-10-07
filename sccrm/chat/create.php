<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
$_sccrm_db_boot = require_once __DIR__ . '/../config/database.php';
if (($_sccrm_db_boot instanceof PDO) && (!isset($db) || !($db instanceof PDO))) { $db = $_sccrm_db_boot; }
$contacts = $db->query("SELECT id, first_name, last_name, email FROM contacts ORDER BY first_name")->fetchAll();
$companies = $db->query("SELECT id, name FROM companies ORDER BY name")->fetchAll();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $stmt = $db->prepare("INSERT INTO support_tickets (contact_id, company_id, subject, message, department, priority, status, created_by) VALUES (?,?,?,?,?,?,?,?)");
    $stmt->execute([
        $_POST['contact_id'] ?: null, $_POST['company_id'] ?: null,
        $_POST['subject'], $_POST['message'],
        $_POST['department'] ?: 'general', $_POST['priority'] ?: 'normal',
        'open', $_POST['created_by'] ?: 'Portal User'
    ]);
    $ticket_id = $db->lastInsertId();
    if (!empty($_POST['message'])) {
        $msg = $db->prepare("INSERT INTO support_messages (ticket_id, sender, message, is_staff) VALUES (?,?,?,0)");
        $msg->execute([$ticket_id, $_POST['created_by'] ?: 'Portal User', $_POST['message']]);
    }
    // Best-effort AI triage (ai-support-operations pattern) — never breaks creation.
    try {
        require_once __DIR__ . '/../ai/ticket_triage_agent.php';
        ticketTriage($db, $ticket_id);
    } catch (Throwable $e) {
    }
    $_SESSION['flash'] = ['type' => 'success', 'message' => 'Ticket #' . $ticket_id . ' created!'];
    header('Location: view.php?id=' . $ticket_id);
    exit;
}
require_once __DIR__ . '/../includes/header.php';
?>
<div class="page-title-area">
    <div><h4>New Support Ticket</h4>
        <nav aria-label="breadcrumb"><ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="<?= $SCCRM_BASE ?>/index.php" class="text-decoration-none">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= $SCCRM_BASE ?>/chat/" class="text-decoration-none">Support</a></li>
            <li class="breadcrumb-item active">New Ticket</li>
        </ol></nav>
    </div>
</div>
<div class="card-crm"><div class="card-body">
    <form method="POST" class="row g-3">
        <div class="col-12"><label class="form-label">Subject <span class="text-danger">*</span></label><input type="text" name="subject" class="form-control" required></div>
        <div class="col-md-4">
            <label class="form-label">Contact</label>
            <select name="contact_id" class="form-select">
                <option value="">-- Select Contact --</option>
                <?php foreach ($contacts as $c): ?>
                <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['first_name'].' '.$c['last_name']) ?> (<?= htmlspecialchars($c['email']) ?>)</option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-4">
            <label class="form-label">Company</label>
            <select name="company_id" class="form-select">
                <option value="">-- Select Company --</option>
                <?php foreach ($companies as $comp): ?>
                <option value="<?= $comp['id'] ?>"><?= htmlspecialchars($comp['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label">Department</label>
            <select name="department" class="form-select">
                <?php foreach (['general'=>'General','technical'=>'Technical','billing'=>'Billing','sales'=>'Sales','support'=>'Support'] as $v=>$l): ?>
                <option value="<?= $v ?>"><?= $l ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label">Priority</label>
            <select name="priority" class="form-select">
                <?php foreach (['low'=>'Low','normal'=>'Normal','high'=>'High','urgent'=>'Urgent'] as $v=>$l): ?>
                <option value="<?= $v ?>" <?= $v === 'normal' ? 'selected' : '' ?>><?= $l ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-12"><label class="form-label">Message <span class="text-danger">*</span></label><textarea name="message" class="form-control" rows="6" required></textarea></div>
        <div class="col-md-4"><label class="form-label">Your Name</label><input type="text" name="created_by" class="form-control" value="Portal User"></div>
        <div class="col-12 d-flex gap-2">
            <button type="submit" class="btn btn-success rounded-pill px-4"><i class="fas fa-paper-plane me-1"></i> Submit Ticket</button>
            <a href="<?= $SCCRM_BASE ?>/chat/" class="btn btn-outline-secondary rounded-pill px-4">Cancel</a>
        </div>
    </form>
</div></div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
