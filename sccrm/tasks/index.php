<?php
session_start();
require_once __DIR__ . '/../includes/header.php';

$status_filter = $_GET['status'] ?? '';
$priority_filter = $_GET['priority'] ?? '';
$search = $_GET['search'] ?? '';

$sql = "SELECT t.*, c.first_name, c.last_name, co.name as company_name
        FROM tasks t
        LEFT JOIN contacts c ON t.contact_id = c.id
        LEFT JOIN companies co ON t.company_id = co.id
        WHERE 1=1";
$params = [];
if ($status_filter) { $sql .= " AND t.status = ?"; $params[] = $status_filter; }
if ($priority_filter) { $sql .= " AND t.priority = ?"; $params[] = $priority_filter; }
if ($search) { $sql .= " AND (t.title LIKE ? OR t.description LIKE ?)"; $params = array_merge($params, ["%$search%","%$search%"]); }
$sql .= " ORDER BY t.due_date ASC, t.created_at DESC";
$tasks = $db->prepare($sql);
$tasks->execute($params);
$tasks = $tasks->fetchAll();
?>
<div class="page-title-area">
    <div>
        <h4>Tasks</h4>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="<?= $SCCRM_BASE ?>/index.php" class="text-decoration-none">Dashboard</a></li>
                <li class="breadcrumb-item active">Tasks</li>
            </ol>
        </nav>
    </div>
    <a href="<?= $SCCRM_BASE ?>/tasks/create.php" class="btn btn-primary rounded-pill px-4"><i class="fas fa-plus me-1"></i> Add Task</a>
</div>

<div class="card-crm">
    <div class="card-header">
        <form method="GET" class="d-flex gap-2 flex-wrap">
            <div class="input-group input-group-sm" style="width:200px;">
                <span class="input-group-text bg-transparent"><i class="fas fa-search text-muted"></i></span>
                <input type="text" name="search" class="form-control" placeholder="Search tasks..." value="<?= htmlspecialchars($search) ?>">
            </div>
            <select name="status" class="form-select form-select-sm" onchange="this.form.submit()" style="width:auto;">
                <option value="">All Status</option>
                <?php foreach (['pending'=>'Pending','in_progress'=>'In Progress','completed'=>'Completed','cancelled'=>'Cancelled'] as $val=>$label): ?>
                <option value="<?= $val ?>" <?= $status_filter === $val ? 'selected' : '' ?>><?= $label ?></option>
                <?php endforeach; ?>
            </select>
            <select name="priority" class="form-select form-select-sm" onchange="this.form.submit()" style="width:auto;">
                <option value="">All Priority</option>
                <?php foreach (['low'=>'Low','medium'=>'Medium','high'=>'High','urgent'=>'Urgent'] as $val=>$label): ?>
                <option value="<?= $val ?>" <?= $priority_filter === $val ? 'selected' : '' ?>><?= $label ?></option>
                <?php endforeach; ?>
            </select>
            <?php if ($search || $status_filter || $priority_filter): ?>
            <a href="<?= $SCCRM_BASE ?>/tasks/" class="btn btn-sm btn-outline-secondary">Clear</a>
            <?php endif; ?>
        </form>
    </div>
    <div class="card-body p-0">
        <?php if (count($tasks) > 0): ?>
        <div class="table-responsive">
            <table class="table table-crm">
                <thead><tr><th>Title</th><th>Contact</th><th>Company</th><th>Priority</th><th>Status</th><th>Due Date</th><th>Actions</th></tr></thead>
                <tbody>
                    <?php foreach ($tasks as $t): ?>
                    <tr>
                        <td>
                            <div class="fw-semibold" style="font-size:14px;"><?= htmlspecialchars($t['title']) ?></div>
                            <?php if ($t['description']): ?><small class="text-muted"><?= htmlspecialchars(mb_substr($t['description'], 0, 60)) ?></small><?php endif; ?>
                        </td>
                        <td style="font-size:13px;"><?= $t['first_name'] ? htmlspecialchars($t['first_name'].' '.$t['last_name']) : '-' ?></td>
                        <td style="font-size:13px;"><?= htmlspecialchars($t['company_name'] ?? '-') ?></td>
                        <td><span class="badge-priority <?= $t['priority'] ?>"><?= ucfirst($t['priority']) ?></span></td>
                        <td><span class="badge-status <?= $t['status'] ?>"><?= str_replace('_',' ',ucfirst($t['status'])) ?></span></td>
                        <td style="font-size:13px;color:var(--gray);"><?= $t['due_date'] ? formatDate($t['due_date']) : '-' ?></td>
                        <td>
                            <div class="d-flex gap-1">
                                <a href="<?= $SCCRM_BASE ?>/tasks/edit.php?id=<?= $t['id'] ?>" class="btn btn-action btn-outline-primary" data-bs-toggle="tooltip" title="Edit"><i class="fas fa-edit"></i></a>
                                <a href="<?= $SCCRM_BASE ?>/tasks/delete.php?id=<?= $t['id'] ?>" class="btn btn-action btn-outline-danger" data-confirm="Delete this task?" data-bs-toggle="tooltip" title="Delete"><i class="fas fa-trash"></i></a>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <div class="empty-state"><i class="fas fa-tasks"></i><h6>No tasks found</h6></div>
        <?php endif; ?>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
