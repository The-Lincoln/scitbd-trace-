<?php
session_start();
require_once __DIR__ . '/../includes/header.php';

$search = $_GET['search'] ?? '';
$industry_filter = $_GET['industry'] ?? '';
$sql = "SELECT c.*, (SELECT COUNT(*) FROM contacts WHERE company_id = c.id) as contact_count FROM companies c WHERE 1=1";
$params = [];
if ($search) { $sql .= " AND (c.name LIKE ? OR c.email LIKE ? OR c.city LIKE ?)"; $params = array_fill(0,3,"%$search%"); }
if ($industry_filter) { $sql .= " AND c.industry = ?"; $params[] = $industry_filter; }
$sql .= " ORDER BY c.created_at DESC";
$companies = $db->prepare($sql);
$companies->execute($params);
$companies = $companies->fetchAll();

$industries = $db->query("SELECT DISTINCT industry FROM companies WHERE industry IS NOT NULL AND industry != '' ORDER BY industry")->fetchAll(PDO::FETCH_COLUMN);
?>
<div class="page-title-area">
    <div>
        <h4>Companies</h4>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="<?= $SCCRM_BASE ?>/index.php" class="text-decoration-none">Dashboard</a></li>
                <li class="breadcrumb-item active">Companies</li>
            </ol>
        </nav>
    </div>
    <a href="<?= $SCCRM_BASE ?>/companies/create.php" class="btn btn-primary rounded-pill px-4"><i class="fas fa-plus me-1"></i> Add Company</a>
</div>

<div class="card-crm">
    <div class="card-header">
        <form method="GET" class="d-flex gap-2 flex-wrap">
            <div class="input-group input-group-sm" style="width:220px;">
                <span class="input-group-text bg-transparent"><i class="fas fa-search text-muted"></i></span>
                <input type="text" name="search" class="form-control" placeholder="Search companies..." value="<?= htmlspecialchars($search) ?>">
            </div>
            <select name="industry" class="form-select form-select-sm" onchange="this.form.submit()" style="width:auto;">
                <option value="">All Industries</option>
                <?php foreach ($industries as $ind): ?>
                <option value="<?= htmlspecialchars($ind) ?>" <?= $industry_filter === $ind ? 'selected' : '' ?>><?= htmlspecialchars($ind) ?></option>
                <?php endforeach; ?>
            </select>
            <?php if ($search || $industry_filter): ?>
            <a href="<?= $SCCRM_BASE ?>/companies/" class="btn btn-sm btn-outline-secondary">Clear</a>
            <?php endif; ?>
        </form>
    </div>
    <div class="card-body p-0">
        <?php if (count($companies) > 0): ?>
        <div class="table-responsive">
            <table class="table table-crm">
                <thead><tr><th>Company</th><th>Industry</th><th>Email</th><th>Phone</th><th>City</th><th>Contacts</th><th>Actions</th></tr></thead>
                <tbody>
                    <?php foreach ($companies as $comp): ?>
                    <tr>
                        <td>
                            <div class="contact-row">
                                <div class="avatar-circle" style="background:<?= avatarColor($comp['id']) ?>"><i class="fas fa-building"></i></div>
                                <div class="info">
                                    <a href="<?= $SCCRM_BASE ?>/companies/view.php?id=<?= $comp['id'] ?>" class="name text-decoration-none"><?= htmlspecialchars($comp['name']) ?></a>
                                </div>
                            </div>
                        </td>
                        <td style="font-size:13px;"><?= htmlspecialchars($comp['industry'] ?? '-') ?></td>
                        <td style="font-size:13px;"><a href="mailto:<?= htmlspecialchars($comp['email']) ?>" class="text-decoration-none"><?= htmlspecialchars($comp['email'] ?? '-') ?></a></td>
                        <td style="font-size:13px;"><?= htmlspecialchars($comp['phone'] ?? '-') ?></td>
                        <td style="font-size:13px;"><?= htmlspecialchars($comp['city'] ?? '-') ?></td>
                        <td><span class="badge bg-light text-dark"><?= $comp['contact_count'] ?></span></td>
                        <td>
                            <div class="d-flex gap-1">
                                <a href="<?= $SCCRM_BASE ?>/companies/view.php?id=<?= $comp['id'] ?>" class="btn btn-action btn-outline-info" data-bs-toggle="tooltip" title="View"><i class="fas fa-eye"></i></a>
                                <a href="<?= $SCCRM_BASE ?>/companies/edit.php?id=<?= $comp['id'] ?>" class="btn btn-action btn-outline-primary" data-bs-toggle="tooltip" title="Edit"><i class="fas fa-edit"></i></a>
                                <a href="<?= $SCCRM_BASE ?>/companies/delete.php?id=<?= $comp['id'] ?>" class="btn btn-action btn-outline-danger" data-confirm="Delete this company?" data-bs-toggle="tooltip" title="Delete"><i class="fas fa-trash"></i></a>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <div class="empty-state"><i class="fas fa-building"></i><h6>No companies found</h6><p class="text-muted"><?= $search ? 'Try a different search.' : 'Add your first company.' ?></p></div>
        <?php endif; ?>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
