<?php
// SCCRM > OSINT Library — the full OSINT tools catalog (289 tools, 93 categories)
// inside the CRM, aligned with Trace (trace any tool domain) + Leads (push intel).
if (session_status() === PHP_SESSION_NONE) { session_start(); }
$_sccrm_db_boot = require_once __DIR__ . '/../config/database.php';
if (($_sccrm_db_boot instanceof PDO) && (!isset($db) || !($db instanceof PDO))) { $db = $_sccrm_db_boot; }

$osintDbPath = dirname(__DIR__, 2) . '/data/osint.db';
$opdo = null;
$osintError = null;
try {
    if (!file_exists($osintDbPath)) throw new Exception('OSINT catalog database not found.');
    $opdo = new PDO('sqlite:' . $osintDbPath);
    $opdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Throwable $e) { $osintError = $e->getMessage(); }

$q = trim($_GET['q'] ?? '');
$cat = intval($_GET['cat'] ?? 0);
$page = max(1, intval($_GET['page'] ?? 1));
$perPage = 24;

$categories = [];
$totalTools = 0;
$tools = [];
if ($opdo) {
    $categories = $opdo->query("SELECT c.id, c.name, COUNT(t.id) AS n FROM categories c LEFT JOIN tools t ON t.category_id = c.id GROUP BY c.id ORDER BY c.name")->fetchAll(PDO::FETCH_ASSOC);
    $where = [];
    $params = [];
    if ($cat > 0) { $where[] = 't.category_id = ?'; $params[] = $cat; }
    if ($q !== '') { $where[] = '(t.name LIKE ? OR t.description LIKE ? OR t.tags LIKE ?)'; $like = '%' . $q . '%'; $params[] = $like; $params[] = $like; $params[] = $like; }
    $wsql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
    $st = $opdo->prepare("SELECT COUNT(*) FROM tools t $wsql");
    $st->execute($params);
    $totalTools = (int)$st->fetchColumn();
    $totalPages = max(1, (int)ceil($totalTools / $perPage));
    if ($page > $totalPages) $page = $totalPages;
    $offset = ($page - 1) * $perPage;
    $st = $opdo->prepare("SELECT t.*, c.name AS category_name FROM tools t LEFT JOIN categories c ON c.id = t.category_id $wsql ORDER BY t.rating_avg DESC, t.name ASC LIMIT $perPage OFFSET $offset");
    $st->execute($params);
    $tools = $st->fetchAll(PDO::FETCH_ASSOC);
}

require_once __DIR__ . '/../includes/header.php';

function osint_cost_badge($t) {
    $map = ['free' => 'success', 'freemium' => 'info', 'paid' => 'danger'];
    return '<span class="badge bg-' . ($map[$t] ?? 'secondary') . '">' . htmlspecialchars(ucfirst((string)$t)) . '</span>';
}
function osint_qs($over = []) {
    $p = array_merge(['q' => trim($_GET['q'] ?? ''), 'cat' => intval($_GET['cat'] ?? 0), 'page' => 1], $over);
    return '?' . http_build_query(array_filter($p, function ($v) { return $v !== '' && $v !== 0; }));
}
?>
<div class="page-title-area">
    <div>
        <h4><i class="fas fa-book-open me-2 text-primary"></i>OSINT Library <small class="text-muted">(<?= $opdo ? (int)$opdo->query("SELECT COUNT(*) FROM tools")->fetchColumn() : 0 ?> tools)</small></h4>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="<?= htmlspecialchars($SCCRM_BASE) ?>/index.php" class="text-decoration-none">Dashboard</a></li>
                <li class="breadcrumb-item active">OSINT Library</li>
            </ol>
        </nav>
    </div>
    <div class="d-flex gap-2">
        <a href="<?= htmlspecialchars($SCCRM_BASE) ?>/trace/" class="btn btn-outline-info btn-sm rounded-pill"><i class="fas fa-crosshairs me-1"></i> Trace</a>
        <a href="<?= htmlspecialchars($OSINT_BASE) ?>/index.php" target="_blank" rel="noopener" class="btn btn-outline-secondary btn-sm rounded-pill"><i class="fas fa-external-link-alt me-1"></i> Full Framework</a>
    </div>
</div>

<?php if ($osintError): ?>
<div class="alert alert-danger"><?= htmlspecialchars($osintError) ?></div>
<?php else: ?>
<div class="card-crm mb-3">
    <div class="card-body">
        <form method="GET" class="row g-2">
            <div class="col-md-6">
                <div class="input-group">
                    <span class="input-group-text bg-transparent"><i class="fas fa-search text-muted"></i></span>
                    <input type="text" name="q" class="form-control" placeholder="Search 289 OSINT tools..." value="<?= htmlspecialchars($q) ?>">
                </div>
            </div>
            <div class="col-md-4">
                <select name="cat" class="form-select" onchange="this.form.submit()">
                    <option value="0">All categories (<?= count($categories) ?>)</option>
                    <?php foreach ($categories as $c): ?>
                    <option value="<?= (int)$c['id'] ?>" <?= $cat === (int)$c['id'] ? 'selected' : '' ?>><?= htmlspecialchars($c['name']) ?> (<?= (int)$c['n'] ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2"><button class="btn btn-primary w-100"><i class="fas fa-search me-1"></i> Search</button></div>
        </form>
    </div>
</div>

<div class="d-flex justify-content-between align-items-center mb-2" style="font-size:13px;">
    <span class="text-muted"><?= $totalTools ?> tool(s)<?= $q !== '' ? ' for "' . htmlspecialchars($q) . '"' : '' ?></span>
    <?php if ($totalPages > 1): ?>
    <span>Page <?= $page ?> / <?= $totalPages ?>
        <?php if ($page > 1): ?><a href="<?= osint_qs(['page' => $page - 1]) ?>" class="btn btn-sm btn-outline-secondary rounded-pill">← Prev</a><?php endif; ?>
        <?php if ($page < $totalPages): ?><a href="<?= osint_qs(['page' => $page + 1]) ?>" class="btn btn-sm btn-outline-secondary rounded-pill">Next →</a><?php endif; ?>
    </span>
    <?php endif; ?>
</div>

<?php if (count($tools)): ?>
<div class="row g-3">
    <?php foreach ($tools as $t):
        $host = parse_url($t['url'] ?? '', PHP_URL_HOST) ?: '';
    ?>
    <div class="col-xl-3 col-lg-4 col-md-6">
        <div class="card-crm h-100">
            <div class="card-body" style="font-size:13px;">
                <div class="d-flex gap-2 align-items-center mb-2">
                    <?php if ($host): ?><img src="https://www.google.com/s2/favicons?domain=<?= htmlspecialchars($host) ?>" width="20" height="20" alt="" loading="lazy" onerror="this.style.display='none'"><?php endif; ?>
                    <a href="<?= htmlspecialchars($t['url'] ?? '#') ?>" target="_blank" rel="noopener" class="fw-semibold text-decoration-none text-break"><?= htmlspecialchars($t['name']) ?></a>
                </div>
                <div class="text-muted mb-2"><?= htmlspecialchars(mb_substr($t['description'] ?? '', 0, 140)) ?></div>
                <div class="mb-2 d-flex gap-1 flex-wrap">
                    <?= osint_cost_badge($t['cost_type'] ?? '') ?>
                    <?php if (!empty($t['category_name'])): ?><span class="badge bg-light text-dark border"><?= htmlspecialchars($t['category_name']) ?></span><?php endif; ?>
                    <?php if (!empty($t['rating_avg'])): ?><span class="badge bg-warning text-dark">★ <?= number_format((float)$t['rating_avg'], 1) ?></span><?php endif; ?>
                </div>
                <div class="d-flex gap-1">
                    <a href="<?= htmlspecialchars($SCCRM_BASE) ?>/trace/?url=<?= urlencode($t['url'] ?? '') ?>" class="btn btn-sm btn-outline-info rounded-pill" title="Trace this tool's site"><i class="fas fa-crosshairs"></i> Trace</a>
                    <a href="<?= htmlspecialchars($SCCRM_BASE) ?>/autoflows/push_trace.php?url=<?= urlencode($t['url'] ?? '') ?>" class="btn btn-sm btn-outline-success rounded-pill" title="Create lead from this tool"><i class="fas fa-user-plus"></i> Lead</a>
                </div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php else: ?>
<div class="card-crm"><div class="empty-state"><i class="fas fa-search"></i><h6>No tools found</h6><p class="text-muted">Try a different keyword or category.</p></div></div>
<?php endif; ?>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
