<?php
session_start();
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/upload_helper.php';
$category_filter = $_GET['category'] ?? '';
$sql = "SELECT s.*, (SELECT COUNT(*) FROM leads WHERE service_id = s.id) as lead_count FROM services s";
$params = [];
if ($category_filter) { $sql .= " WHERE s.category = ?"; $params[] = $category_filter; }
$sql .= " ORDER BY s.category, s.name";
$services = $db->prepare($sql);
$services->execute($params);
$services = $services->fetchAll();
$categories = $db->query("SELECT DISTINCT category FROM services ORDER BY category")->fetchAll(PDO::FETCH_COLUMN);
?>
<div class="page-title-area">
    <div>
        <h4>Products & Services</h4>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="<?= $SCCRM_BASE ?>/index.php" class="text-decoration-none">Dashboard</a></li>
                <li class="breadcrumb-item active">Services</li>
            </ol>
        </nav>
    </div>
    <a href="<?= $SCCRM_BASE ?>/services/create.php" class="btn btn-primary rounded-pill px-4"><i class="fas fa-plus me-1"></i> Add Service</a>
</div>
<div class="card-crm">
    <div class="card-header">
        <form method="GET" class="d-flex gap-2 flex-wrap">
            <select name="category" class="form-select form-select-sm" onchange="this.form.submit()" style="width:auto;">
                <option value="">All Categories</option>
                <?php foreach ($categories as $cat): ?>
                <option value="<?= htmlspecialchars($cat) ?>" <?= $category_filter === $cat ? 'selected' : '' ?>><?= htmlspecialchars($cat) ?></option>
                <?php endforeach; ?>
            </select>
            <?php if ($category_filter): ?><a href="<?= $SCCRM_BASE ?>/services/" class="btn btn-sm btn-outline-secondary">Clear</a><?php endif; ?>
        </form>
    </div>
    <div class="card-body p-0">
        <?php if (count($services) > 0): ?>
        <div class="table-responsive">
            <table class="table table-crm">
                <thead><tr><th>Service</th><th>Category</th><th>Silver</th><th>Platinum</th><th>Media</th><th>Leads</th><th>Status</th><th>Actions</th></tr></thead>
                <tbody>
                    <?php foreach ($services as $s): ?>
                    <tr>
                        <td>
                            <div class="d-flex align-items-center gap-2">
                                <?php if (!empty($s['image']) || !empty($s['gif'])): ?>
                                    <img src="<?= htmlspecialchars(sccrm_media_url($s['image'] ?: $s['gif'], $SCCRM_BASE ?? '')) ?>" style="height:32px; width:32px; object-fit:cover; border-radius:4px;">
                                <?php else: ?>
                                    <i class="fas <?= htmlspecialchars($s['icon'] ?? 'fa-cog') ?> text-primary"></i>
                                <?php endif; ?>
                                <div class="fw-semibold"><?= htmlspecialchars($s['name']) ?></div>
                            </div>
                        </td>
                        <td><span class="badge bg-light text-dark"><?= htmlspecialchars($s['category']) ?></span></td>
                        <td><?= $s['price_silver'] !== null ? number_format((float)$s['price_silver'], 0) . ' ' . htmlspecialchars($s['currency'] ?? 'BDT') : '-' ?></td>
                        <td><?= $s['price_platinum'] !== null ? number_format((float)$s['price_platinum'], 0) . ' ' . htmlspecialchars($s['currency'] ?? 'BDT') : '-' ?></td>
                        <td><?= empty($s['image']) && empty($s['gif']) && empty($s['video']) ? '-' : implode(' ', array_filter([!empty($s['image'])?'&#128247;':null, !empty($s['gif'])?'&#127916;':null, !empty($s['video'])?'&#127909;':null])) ?></td>
                        <td><span class="badge bg-light text-dark"><?= $s['lead_count'] ?></span></td>
                        <td><span class="badge-status <?= $s['is_active'] ? 'completed' : 'cancelled' ?>"><?= $s['is_active'] ? 'Active' : 'Inactive' ?></span></td>
                        <td>
                            <div class="d-flex gap-1">
                                <a href="<?= $SCCRM_BASE ?>/services/edit.php?id=<?= $s['id'] ?>" class="btn btn-action btn-outline-primary" data-bs-toggle="tooltip" title="Edit"><i class="fas fa-edit"></i></a>
                                <a href="<?= $SCCRM_BASE ?>/services/delete.php?id=<?= $s['id'] ?>" class="btn btn-action btn-outline-danger" data-confirm="Delete this service?" data-bs-toggle="tooltip" title="Delete"><i class="fas fa-trash"></i></a>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <div class="empty-state"><i class="fas fa-cogs"></i><h6>No services configured</h6></div>
        <?php endif; ?>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
