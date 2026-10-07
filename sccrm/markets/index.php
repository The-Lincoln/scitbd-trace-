<?php
session_start();
require_once __DIR__ . '/../includes/header.php';
$markets = $db->query("SELECT * FROM markets ORDER BY name")->fetchAll();
?>
<div class="page-title-area">
    <div>
        <h4>Target Markets</h4>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="<?= $SCCRM_BASE ?>/index.php" class="text-decoration-none">Dashboard</a></li>
                <li class="breadcrumb-item active">Markets</li>
            </ol>
        </nav>
    </div>
    <a href="<?= $SCCRM_BASE ?>/markets/create.php" class="btn btn-primary rounded-pill px-4"><i class="fas fa-plus me-1"></i> Add Market</a>
</div>
<div class="card-crm">
    <div class="card-body p-0">
        <?php if (count($markets) > 0): ?>
        <div class="table-responsive">
            <table class="table table-crm">
                <thead><tr><th>Market</th><th>Country Code</th><th>Region</th><th>Timezone</th><th>Currency</th><th>Language</th><th>Status</th><th>Actions</th></tr></thead>
                <tbody>
                    <?php foreach ($markets as $m): ?>
                    <tr>
                        <td><div class="fw-semibold"><?= htmlspecialchars($m['name']) ?></div></td>
                        <td><span class="badge bg-light text-dark"><?= htmlspecialchars($m['country_code']) ?></span></td>
                        <td style="font-size:13px;"><?= htmlspecialchars($m['region'] ?? '-') ?></td>
                        <td style="font-size:13px;"><?= htmlspecialchars($m['timezone'] ?? '-') ?></td>
                        <td style="font-size:13px;"><?= htmlspecialchars($m['currency'] ?? '-') ?></td>
                        <td style="font-size:13px;"><?= htmlspecialchars($m['language'] ?? '-') ?></td>
                        <td><span class="badge-status <?= $m['is_active'] ? 'completed' : 'cancelled' ?>"><?= $m['is_active'] ? 'Active' : 'Inactive' ?></span></td>
                        <td>
                            <div class="d-flex gap-1">
                                <a href="<?= $SCCRM_BASE ?>/markets/edit.php?id=<?= $m['id'] ?>" class="btn btn-action btn-outline-primary" data-bs-toggle="tooltip" title="Edit"><i class="fas fa-edit"></i></a>
                                <a href="<?= $SCCRM_BASE ?>/markets/delete.php?id=<?= $m['id'] ?>" class="btn btn-action btn-outline-danger" data-confirm="Delete this market?" data-bs-toggle="tooltip" title="Delete"><i class="fas fa-trash"></i></a>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <div class="empty-state"><i class="fas fa-globe"></i><h6>No markets configured</h6></div>
        <?php endif; ?>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
