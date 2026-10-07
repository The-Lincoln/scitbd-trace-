<?php
session_start();
require_once __DIR__ . '/../includes/header.php';
$search = $_GET['search'] ?? '';
$company_filter = $_GET['company_id'] ?? '';

$sql = "SELECT c.*, co.name as company_name FROM contacts c LEFT JOIN companies co ON c.company_id = co.id WHERE 1=1";
$params = [];
if ($search) {
    $sql .= " AND (c.first_name LIKE ? OR c.last_name LIKE ? OR c.email LIKE ? OR c.position LIKE ?)";
    $params = array_fill(0, 4, "%$search%");
}
if ($company_filter) {
    $sql .= " AND c.company_id = ?";
    $params[] = $company_filter;
}
$sql .= " ORDER BY c.created_at DESC";
$contacts = $db->prepare($sql);
$contacts->execute($params);
$contacts = $contacts->fetchAll();
$companies = $db->query("SELECT id, name FROM companies ORDER BY name")->fetchAll();
?>
<div class="page-title-area">
    <div>
        <h4>Contacts</h4>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="<?= $SCCRM_BASE ?>/index.php" class="text-decoration-none">Dashboard</a></li>
                <li class="breadcrumb-item active">Contacts</li>
            </ol>
        </nav>
    </div>
    <a href="<?= $SCCRM_BASE ?>/contacts/create.php" class="btn btn-primary rounded-pill px-4"><i class="fas fa-plus me-1"></i> Add Contact</a>
</div>

<div class="card-crm">
    <div class="card-header">
        <div class="filter-bar">
            <form method="GET" class="d-flex gap-2 flex-wrap">
                <div class="input-group input-group-sm" style="width:220px;">
                    <span class="input-group-text bg-transparent"><i class="fas fa-search text-muted"></i></span>
                    <input type="text" name="search" class="form-control" placeholder="Search contacts..." value="<?= htmlspecialchars($search) ?>">
                </div>
                <select name="company_id" class="form-select form-select-sm" onchange="this.form.submit()">
                    <option value="">All Companies</option>
                    <?php foreach ($companies as $comp): ?>
                    <option value="<?= $comp['id'] ?>" <?= $company_filter == $comp['id'] ? 'selected' : '' ?>><?= htmlspecialchars($comp['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <?php if ($search || $company_filter): ?>
                <a href="<?= $SCCRM_BASE ?>/contacts/" class="btn btn-sm btn-outline-secondary">Clear</a>
                <?php endif; ?>
            </form>
        </div>
    </div>
    <div class="card-body p-0">
        <?php if (count($contacts) > 0): ?>
        <div class="table-responsive">
            <table class="table table-crm">
                <thead>
                    <tr><th>Name</th><th>Position</th><th>Company</th><th>Email</th><th>Phone</th><th>Actions</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($contacts as $c): ?>
                    <tr>
                        <td>
                            <div class="contact-row">
                                <div class="avatar-circle" style="background:<?= avatarColor($c['id']) ?>"><?= getInitials($c['first_name'] . ' ' . $c['last_name']) ?></div>
                                <div class="info">
                                    <a href="<?= $SCCRM_BASE ?>/contacts/view.php?id=<?= $c['id'] ?>" class="name text-decoration-none"><?= htmlspecialchars($c['first_name'] . ' ' . $c['last_name']) ?></a>
                                </div>
                            </div>
                        </td>
                        <td style="font-size:13px;"><?= htmlspecialchars($c['position'] ?? '-') ?></td>
                        <td style="font-size:13px;"><?= htmlspecialchars($c['company_name'] ?? '-') ?></td>
                        <td style="font-size:13px;"><a href="mailto:<?= htmlspecialchars($c['email']) ?>" class="text-decoration-none"><?= htmlspecialchars($c['email'] ?? '-') ?></a></td>
                        <td style="font-size:13px;"><?= htmlspecialchars($c['phone'] ?? '-') ?></td>
                        <td>
                            <div class="d-flex gap-1">
                                <a href="<?= $SCCRM_BASE ?>/contacts/view.php?id=<?= $c['id'] ?>" class="btn btn-action btn-outline-info" data-bs-toggle="tooltip" title="View"><i class="fas fa-eye"></i></a>
                                <a href="<?= $SCCRM_BASE ?>/contacts/edit.php?id=<?= $c['id'] ?>" class="btn btn-action btn-outline-primary" data-bs-toggle="tooltip" title="Edit"><i class="fas fa-edit"></i></a>
                                <a href="<?= $SCCRM_BASE ?>/contacts/delete.php?id=<?= $c['id'] ?>" class="btn btn-action btn-outline-danger" data-confirm="Delete this contact?" data-bs-toggle="tooltip" title="Delete"><i class="fas fa-trash"></i></a>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <div class="empty-state">
            <i class="fas fa-users"></i>
            <h6>No contacts found</h6>
            <p class="text-muted"><?= $search ? 'Try a different search term.' : 'Add your first contact to get started.' ?></p>
            <?php if (!$search): ?><a href="<?= $SCCRM_BASE ?>/contacts/create.php" class="btn btn-primary btn-sm mt-2">Add Contact</a><?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
