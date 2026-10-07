<?php
session_start();
require_once __DIR__ . '/../includes/header.php';

$type_filter = $_GET['type'] ?? '';
$search = $_GET['search'] ?? '';
$sql = "SELECT i.*, c.first_name, c.last_name, c.email as contact_email, co.name as company_name
        FROM interactions i
        LEFT JOIN contacts c ON i.contact_id = c.id
        LEFT JOIN companies co ON i.company_id = co.id
        WHERE 1=1";
$params = [];
if ($type_filter) { $sql .= " AND i.type = ?"; $params[] = $type_filter; }
if ($search) { $sql .= " AND (i.subject LIKE ? OR i.content LIKE ? OR c.first_name LIKE ? OR c.last_name LIKE ?)"; $params = array_merge($params, ["%$search%","%$search%","%$search%","%$search%"]); }
$sql .= " ORDER BY i.date DESC, i.created_at DESC";
$interactions = $db->prepare($sql);
$interactions->execute($params);
$interactions = $interactions->fetchAll();

$types = $db->query("SELECT DISTINCT type FROM interactions ORDER BY type")->fetchAll(PDO::FETCH_COLUMN);
?>
<div class="page-title-area">
    <div>
        <h4>Interactions</h4>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="<?= $SCCRM_BASE ?>/index.php" class="text-decoration-none">Dashboard</a></li>
                <li class="breadcrumb-item active">Interactions</li>
            </ol>
        </nav>
    </div>
    <a href="<?= $SCCRM_BASE ?>/interactions/create.php" class="btn btn-primary rounded-pill px-4"><i class="fas fa-plus me-1"></i> Log Interaction</a>
</div>

<div class="card-crm">
    <div class="card-header">
        <form method="GET" class="d-flex gap-2 flex-wrap">
            <div class="input-group input-group-sm" style="width:220px;">
                <span class="input-group-text bg-transparent"><i class="fas fa-search text-muted"></i></span>
                <input type="text" name="search" class="form-control" placeholder="Search interactions..." value="<?= htmlspecialchars($search) ?>">
            </div>
            <select name="type" class="form-select form-select-sm" onchange="this.form.submit()" style="width:auto;">
                <option value="">All Types</option>
                <?php foreach (['call','email','meeting','note','social','other'] as $t): ?>
                <option value="<?= $t ?>" <?= $type_filter === $t ? 'selected' : '' ?>><?= ucfirst($t) ?></option>
                <?php endforeach; ?>
            </select>
            <?php if ($search || $type_filter): ?>
            <a href="<?= $SCCRM_BASE ?>/interactions/" class="btn btn-sm btn-outline-secondary">Clear</a>
            <?php endif; ?>
        </form>
    </div>
    <div class="card-body p-0">
        <?php if (count($interactions) > 0): ?>
        <div class="table-responsive">
            <table class="table table-crm">
                <thead><tr><th>Date</th><th>Type</th><th>Subject</th><th>Contact</th><th>Company</th><th>Actions</th></tr></thead>
                <tbody>
                    <?php foreach ($interactions as $i): ?>
                    <tr>
                        <td style="font-size:13px;"><?= formatDate($i['date']) ?></td>
                        <td><span class="badge-type <?= $i['type'] ?>"><?= ucfirst($i['type']) ?></span></td>
                        <td>
                            <div class="fw-semibold" style="font-size:14px;"><?= htmlspecialchars($i['subject']) ?></div>
                            <?php if ($i['content']): ?><small class="text-muted"><?= htmlspecialchars(mb_substr($i['content'], 0, 80)) ?>...</small><?php endif; ?>
                        </td>
                        <td style="font-size:13px;"><?= $i['first_name'] ? htmlspecialchars($i['first_name'].' '.$i['last_name']) : '-' ?></td>
                        <td style="font-size:13px;"><?= htmlspecialchars($i['company_name'] ?? '-') ?></td>
                        <td>
                            <div class="d-flex gap-1">
                                <a href="<?= $SCCRM_BASE ?>/interactions/edit.php?id=<?= $i['id'] ?>" class="btn btn-action btn-outline-primary" data-bs-toggle="tooltip" title="Edit"><i class="fas fa-edit"></i></a>
                                <a href="<?= $SCCRM_BASE ?>/interactions/delete.php?id=<?= $i['id'] ?>" class="btn btn-action btn-outline-danger" data-confirm="Delete this interaction?" data-bs-toggle="tooltip" title="Delete"><i class="fas fa-trash"></i></a>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <div class="empty-state"><i class="fas fa-comments"></i><h6>No interactions found</h6></div>
        <?php endif; ?>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
