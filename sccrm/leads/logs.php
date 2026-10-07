<?php
session_start();
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json');

if (isset($_GET['last']) && $_GET['last'] == 1) {
    $log = $db->query("SELECT generated_at FROM lead_generation_logs ORDER BY generated_at DESC LIMIT 1")->fetch();
    if ($log) {
        $timeAgo = '';
        $diff = time() - strtotime($log['generated_at']);
        if ($diff < 60) $timeAgo = 'Just now';
        elseif ($diff < 3600) $timeAgo = floor($diff/60) . 'm ago';
        elseif ($diff < 86400) $timeAgo = floor($diff/3600) . 'h ago';
        else $timeAgo = floor($diff/86400) . 'd ago';
        echo json_encode(['time' => $timeAgo, 'full' => $log['generated_at']]);
    } else {
        echo json_encode(['time' => '-', 'full' => null]);
    }
    exit;
}

$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$per_page = 20;
$offset = ($page - 1) * $per_page;

$total = $db->query("SELECT COUNT(*) FROM lead_generation_logs")->fetchColumn();
$logs = $db->prepare("SELECT * FROM lead_generation_logs ORDER BY generated_at DESC LIMIT ? OFFSET ?");
$logs->execute([$per_page, $offset]);
$logs = $logs->fetchAll();

$total_pages = ceil($total / $per_page);

require_once __DIR__ . '/../includes/header.php';
?>
<div class="page-title-area">
    <div>
        <h4>Generation Logs</h4>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="<?= $SCCRM_BASE ?>/index.php" class="text-decoration-none">Dashboard</a></li>
                <li class="breadcrumb-item"><a href="<?= $SCCRM_BASE ?>/leads/" class="text-decoration-none">Leads</a></li>
                <li class="breadcrumb-item"><a href="<?= $SCCRM_BASE ?>/leads/generate.php" class="text-decoration-none">Generate</a></li>
                <li class="breadcrumb-item active">Logs</li>
            </ol>
        </nav>
    </div>
</div>
<div class="card-crm">
    <div class="card-header"><h6><i class="fas fa-history me-2"></i>All Generation Logs</h6></div>
    <div class="card-body p-0">
        <?php if (count($logs) > 0): ?>
        <div class="table-responsive">
            <table class="table table-crm">
                <thead><tr><th>ID</th><th>Date/Time</th><th>Leads Generated</th><th>Source</th><th>Status</th><th>Error</th></tr></thead>
                <tbody>
                    <?php foreach ($logs as $log): ?>
                    <tr>
                        <td>#<?= $log['id'] ?></td>
                        <td style="font-size:13px;"><?= date('M j, Y g:i:s A', strtotime($log['generated_at'])) ?></td>
                        <td><span class="badge bg-light text-dark fs-6">+<?= $log['leads_generated'] ?></span></td>
                        <td><?= htmlspecialchars(ucfirst(str_replace('_',' ',$log['source'] ?? 'manual'))) ?></td>
                        <td>
                            <?php if ($log['status'] === 'success'): ?>
                            <span class="badge-status completed">Success</span>
                            <?php else: ?>
                            <span class="badge-status cancelled">Failed</span>
                            <?php endif; ?>
                        </td>
                        <td style="font-size:12px;max-width:200px;overflow:hidden;text-overflow:ellipsis;"><?= htmlspecialchars($log['error_message'] ?? '-') ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ($total_pages > 1): ?>
        <div class="d-flex justify-content-center p-3">
            <nav><ul class="pagination pagination-crm">
                <?php for($p=1;$p<=$total_pages;$p++): ?>
                <li class="page-item <?= $p === $page ? 'active' : '' ?>"><a class="page-link" href="?page=<?= $p ?>"><?= $p ?></a></li>
                <?php endfor; ?>
            </ul></nav>
        </div>
        <?php endif; ?>
        <?php else: ?>
        <div class="empty-state"><i class="fas fa-history"></i><h6>No generation logs yet</h6></div>
        <?php endif; ?>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
