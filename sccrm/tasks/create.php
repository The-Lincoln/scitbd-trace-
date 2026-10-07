<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
$_sccrm_db_boot = require_once __DIR__ . '/../config/database.php';
if (($_sccrm_db_boot instanceof PDO) && (!isset($db) || !($db instanceof PDO))) { $db = $_sccrm_db_boot; }
$contacts = $db->query("SELECT id, first_name, last_name FROM contacts ORDER BY first_name")->fetchAll();
$companies = $db->query("SELECT id, name FROM companies ORDER BY name")->fetchAll();
$preselected_contact = $_GET['contact_id'] ?? '';
$preselected_company = $_GET['company_id'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $stmt = $db->prepare("INSERT INTO tasks (contact_id, company_id, title, description, status, priority, due_date, assigned_to) VALUES (?,?,?,?,?,?,?,?)");
    $stmt->execute([
        $_POST['contact_id'] ?: null,
        $_POST['company_id'] ?: null,
        $_POST['title'], $_POST['description'],
        $_POST['status'] ?: 'pending', $_POST['priority'] ?: 'medium',
        $_POST['due_date'] ?: null, $_POST['assigned_to'] ?: null
    ]);
    $_SESSION['flash'] = ['type' => 'success', 'message' => 'Task created successfully!'];
    header('Location: index.php');
    exit;
}
require_once __DIR__ . '/../includes/header.php';
?>
<div class="page-title-area">
    <div>
        <h4>Add Task</h4>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="<?= $SCCRM_BASE ?>/index.php" class="text-decoration-none">Dashboard</a></li>
                <li class="breadcrumb-item"><a href="<?= $SCCRM_BASE ?>/tasks/" class="text-decoration-none">Tasks</a></li>
                <li class="breadcrumb-item active">Add Task</li>
            </ol>
        </nav>
    </div>
</div>
<div class="card-crm">
    <div class="card-body">
        <form method="POST" class="row g-3">
            <div class="col-12"><hr class="my-1"><h6 class="text-primary">Task Details</h6></div>
            <div class="col-md-8">
                <label class="form-label">Title <span class="text-danger">*</span></label>
                <input type="text" name="title" class="form-control" required>
            </div>
            <div class="col-md-2">
                <label class="form-label">Priority</label>
                <select name="priority" class="form-select">
                    <?php foreach (['low'=>'Low','medium'=>'Medium','high'=>'High','urgent'=>'Urgent'] as $val=>$label): ?>
                    <option value="<?= $val ?>" <?= $val === 'medium' ? 'selected' : '' ?>><?= $label ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Status</label>
                <select name="status" class="form-select">
                    <?php foreach (['pending'=>'Pending','in_progress'=>'In Progress','completed'=>'Completed','cancelled'=>'Cancelled'] as $val=>$label): ?>
                    <option value="<?= $val ?>" <?= $val === 'pending' ? 'selected' : '' ?>><?= $label ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12">
                <label class="form-label">Description</label>
                <textarea name="description" class="form-control" rows="3"></textarea>
            </div>
            <div class="col-md-4">
                <label class="form-label">Contact</label>
                <select name="contact_id" class="form-select">
                    <option value="">-- Select Contact --</option>
                    <?php foreach ($contacts as $c): ?>
                    <option value="<?= $c['id'] ?>" <?= $preselected_contact == $c['id'] ? 'selected' : '' ?>><?= htmlspecialchars($c['first_name'].' '.$c['last_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label">Company</label>
                <select name="company_id" class="form-select">
                    <option value="">-- Select Company --</option>
                    <?php foreach ($companies as $comp): ?>
                    <option value="<?= $comp['id'] ?>" <?= $preselected_company == $comp['id'] ? 'selected' : '' ?>><?= htmlspecialchars($comp['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label">Due Date</label>
                <input type="date" name="due_date" class="form-control">
            </div>
            <div class="col-md-4">
                <label class="form-label">Assigned To</label>
                <input type="text" name="assigned_to" class="form-control" placeholder="Person responsible">
            </div>
            <div class="col-12 d-flex gap-2">
                <button type="submit" class="btn btn-primary rounded-pill px-4"><i class="fas fa-save me-1"></i> Create Task</button>
                <a href="<?= $SCCRM_BASE ?>/tasks/" class="btn btn-outline-secondary rounded-pill px-4">Cancel</a>
            </div>
        </form>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
