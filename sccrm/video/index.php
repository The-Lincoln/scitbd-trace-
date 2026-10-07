<?php
// SCCRM > Video — OpenMontage productions board (briefs, pipelines, status).
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../includes/header.php';

$osintRoot = dirname(__DIR__, 2);
foreach ([
    $osintRoot . '/autoflows/app/services/OpenMontage.php',
    $osintRoot . '/sccrm/services/OpenMontageService.php',
] as $f) {
    if (is_file($f)) {
        require_once $f;
    }
}

$flash = null;
$status = class_exists('OpenMontage') ? OpenMontage::status() : ['ok' => false, 'hint' => 'wrapper missing'];
$pipes = class_exists('OpenMontage') ? OpenMontage::listPipelines() : [];
$models = class_exists('OpenMontage') ? OpenMontage::models() : [];
$preflight = class_exists('OpenMontageService') ? OpenMontageService::preflight(!empty($_GET['refresh_preflight'])) : ['ok' => false];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['title'], $_POST['brief']) && !isset($_POST['action'])) {
    $title = trim((string)$_POST['title']);
    $brief = trim((string)$_POST['brief']);
    $pipe = trim((string)($_POST['pipeline'] ?? 'animated-explainer'));
    $opts = [];
    if (isset($_POST['budget_usd']) && $_POST['budget_usd'] !== '') {
        $opts['budget_usd'] = (float)$_POST['budget_usd'];
    }
    if ($title !== '' && $brief !== '') {
        $r = OpenMontageService::requestVideo($title, $brief, $pipe, $opts);
        $flash = !empty($r['ok'])
            ? ['ok' => true, 'msg' => 'Production requested: ' . ($r['slug'] ?? '') . ' — agent runs pipeline_defs/' . $pipe . '.yaml next.']
            : ['ok' => false, 'msg' => 'Request failed: ' . ($r['error'] ?? '?')];
    } else {
        $flash = ['ok' => false, 'msg' => 'Title + brief required.'];
    }
}

$rows = class_exists('OpenMontageService') ? OpenMontageService::productions() : [];

// --- view / edit / delete actions ---
$detail = null;
$editRow = null;
if (isset($_GET['view']) && class_exists('OpenMontageService')) {
    $detail = OpenMontageService::view(trim((string)$_GET['view']));
    if ($detail === null) {
        $flash = ['ok' => false, 'msg' => 'Unknown production.'];
    }
}
if (isset($_GET['edit']) && class_exists('OpenMontageService')) {
    $editRow = OpenMontageService::view(trim((string)$_GET['edit']));
    if ($editRow === null) {
        $flash = ['ok' => false, 'msg' => 'Unknown production.'];
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'], $_POST['slug']) && class_exists('OpenMontageService')) {
    $slug = trim((string)$_POST['slug']);
    if ($_POST['action'] === 'save_edit') {
        $fields = [];
        foreach (['title', 'brief', 'pipeline', 'status'] as $k) {
            if (isset($_POST[$k])) {
                $fields[$k] = (string)$_POST[$k];
            }
        }
        if (isset($_POST['budget_usd']) && $_POST['budget_usd'] !== '') {
            $fields['budget_usd'] = (float)$_POST['budget_usd'];
        }
        $r = OpenMontageService::edit($slug, $fields);
        $flash = !empty($r['ok'])
            ? ['ok' => true, 'msg' => 'Updated ' . $slug . ' (' . ($r['rows_updated'] ?? 0) . ' audit rows).']
            : ['ok' => false, 'msg' => 'Update failed: ' . ($r['error'] ?? '?')];
        $rows = OpenMontageService::productions();
        $detail = OpenMontageService::view($slug);
    } elseif ($_POST['action'] === 'delete') {
        $r = OpenMontageService::remove($slug);
        $flash = !empty($r['ok'])
            ? ['ok' => true, 'msg' => 'Deleted ' . $slug . ' (' . ($r['rows_deleted'] ?? 0) . ' audit rows, files ' . (!empty($r['files_removed']) ? 'removed' : 'kept') . ').']
            : ['ok' => false, 'msg' => 'Delete failed: ' . ($r['error'] ?? '?')];
        $rows = OpenMontageService::productions();
    }
}
?>
<div class="page-title-area">
    <div>
        <h4><i class="fas fa-clapperboard me-2 text-danger"></i>Video Productions <small class="text-muted">OpenMontage · agentic</small></h4>
        <nav aria-label="breadcrumb"><ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="<?= htmlspecialchars($SCCRM_BASE) ?>/index.php" class="text-decoration-none">Dashboard</a></li>
            <li class="breadcrumb-item active">Video</li>
        </ol></nav>
    </div>
    <div><span class="badge <?= !empty($status['ok']) ? 'bg-success' : 'bg-warning text-dark' ?>"><?= !empty($status['ok']) ? '● studio ready' : '● studio offline' ?></span></div>
</div>

<?php if ($flash): ?><div class="alert alert-<?= $flash['ok'] ? 'success' : 'danger' ?>"><?= htmlspecialchars($flash['msg']) ?></div><?php endif; ?>
<?php if (empty($status['ok'])): ?>
<div class="alert alert-warning"><i class="fas fa-exclamation-triangle me-1"></i><?= htmlspecialchars($status['hint'] ?? '') ?>
<br><small>ffmpeg: <?= htmlspecialchars($status['ffmpeg'] ?? '?') ?> · python: <?= htmlspecialchars($status['python'] ?? '?') ?> · node: <?= htmlspecialchars($status['node'] ?? '?') ?></small></div>
<?php endif; ?>
<?php if (!empty($preflight['ok'])): ?>
<div class="alert alert-info py-2"><i class="fas fa-satellite-dish me-1"></i><small>
    <?php $rt = $preflight['composition_runtimes'] ?? []; ?>
    Runtimes: ffmpeg <?= !empty($rt['ffmpeg']) ? '✅' : '❌' ?> · remotion <?= !empty($rt['remotion']) ? '✅' : '❌ (npm install in remotion-composer)' ?> · hyperframes <?= !empty($rt['hyperframes']) ? '✅' : '❌' ?>
    · Capabilities: <?= htmlspecialchars(implode(' · ', array_map(fn($c) => ($c['capability'] ?? '?') . ' ' . ($c['configured'] ?? '?'), array_slice($preflight['capabilities'] ?? [], 0, 8)))) ?><?= count($preflight['capabilities'] ?? []) > 8 ? ' …' : '' ?>
    <?php if (!empty($preflight['runtime_warnings'])): ?><br>Warnings: <?= htmlspecialchars(implode(' | ', array_slice($preflight['runtime_warnings'], 0, 3))) ?><?php endif; ?>
    <br><a href="?refresh_preflight=1" class="text-decoration-none">↻ refresh preflight<?= !empty($preflight['cached']) ? ' (cached ' . (int)($preflight['cache_age_s'] ?? 0) . 's ago)' : '' ?></a>
</small></div>
<?php endif; ?>

<?php if ($editRow): ?>
<div class="card-crm mb-3"><div class="card-header"><h6><i class="fas fa-pen me-2 text-warning"></i>Edit — <?= htmlspecialchars($editRow['slug'] ?? '') ?></h6></div>
    <div class="card-body"><form method="POST" class="row g-2">
        <input type="hidden" name="action" value="save_edit">
        <input type="hidden" name="slug" value="<?= htmlspecialchars($editRow['slug'] ?? '') ?>">
        <div class="col-md-6"><label class="form-label small">Title</label><input name="title" class="form-control" value="<?= htmlspecialchars($editRow['title'] ?? '') ?>" required></div>
        <div class="col-md-3"><label class="form-label small">Pipeline</label><select name="pipeline" class="form-select">
            <?php foreach ($pipes as $id => $desc): ?><option value="<?= htmlspecialchars($id) ?>" <?= ($editRow['pipeline'] ?? '') === $id ? 'selected' : '' ?>><?= htmlspecialchars($id) ?></option><?php endforeach; ?>
        </select></div>
        <div class="col-md-3"><label class="form-label small">Status</label><select name="status" class="form-select">
            <?php foreach (['requested','approved','in_progress','blocked','completed','cancelled'] as $st): ?><option <?= ($editRow['status'] ?? '') === $st ? 'selected' : '' ?>><?= $st ?></option><?php endforeach; ?>
        </select></div>
        <div class="col-md-3"><label class="form-label small">Budget USD</label><input name="budget_usd" type="number" step="0.5" min="0" class="form-control" value="<?= htmlspecialchars((string)($editRow['models']['budget_usd'] ?? '')) ?>"></div>
        <div class="col-md-9"><label class="form-label small">Brief</label><textarea name="brief" class="form-control" rows="5"><?= htmlspecialchars(trim((string)preg_replace(['/^.*## Brief\s*\n/s', '/\n## Models[\s\S]*$/'], '', (string)($editRow['brief'] ?? '')))) ?></textarea></div>
        <div class="col-12"><button class="btn btn-warning"><i class="fas fa-save me-1"></i> Save changes</button>
        <a class="btn btn-secondary ms-2" href="?view=<?= htmlspecialchars($editRow['slug'] ?? '') ?>">Cancel</a></div>
    </form></div></div>
<?php endif; ?>

<?php if ($detail && !$editRow): ?>
<div class="card-crm mb-3"><div class="card-header d-flex justify-content-between align-items-center">
    <h6><i class="fas fa-eye me-2 text-info"></i><?= htmlspecialchars($detail['title'] ?? $detail['slug'] ?? '') ?> <small class="text-muted"><?= htmlspecialchars($detail['slug'] ?? '') ?></small></h6>
    <div>
        <a class="btn btn-sm btn-warning" href="?edit=<?= htmlspecialchars($detail['slug'] ?? '') ?>"><i class="fas fa-pen"></i> Edit</a>
        <form method="POST" class="d-inline" onsubmit="return confirm('Delete <?= htmlspecialchars($detail['slug'] ?? '') ?> + its files?');">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="slug" value="<?= htmlspecialchars($detail['slug'] ?? '') ?>">
            <button class="btn btn-sm btn-danger"><i class="fas fa-trash"></i> Delete</button>
        </form>
        <a class="btn btn-sm btn-secondary" href="./">Close</a>
    </div>
</div>
    <div class="card-body">
        <p class="small mb-1"><span class="badge bg-secondary"><?= htmlspecialchars($detail['pipeline'] ?? '-') ?></span>
        <span class="badge bg-info text-dark"><?= htmlspecialchars($detail['status'] ?? '-') ?></span>
        <?= !empty($detail['has_final']) ? '<span class="badge bg-success">final ready</span>' : '<span class="badge bg-warning text-dark">no final yet</span>' ?></p>
        <h6 class="mt-2">Brief</h6><pre class="small bg-light p-2 rounded" style="white-space:pre-wrap;"><?= htmlspecialchars(mb_substr($detail['brief'] ?? '(no brief.md)', 0, 4000)) ?></pre>
        <?php if (!empty($detail['models']['models'])): ?><h6>Models</h6><ul class="small mb-2"><?php foreach ($detail['models']['models'] as $cap => $m): ?><li><code><?= htmlspecialchars($cap) ?></code> → <?= htmlspecialchars($m) ?></li><?php endforeach; ?></ul><?php endif; ?>
        <?php if (!empty($detail['files'])): ?><h6>Files (<?= count($detail['files']) ?>)</h6><ul class="small text-muted"><?php foreach (array_slice($detail['files'], 0, 30) as $f): ?><li><?= htmlspecialchars($f) ?></li><?php endforeach; ?></ul><?php endif; ?>
    </div></div>
<?php endif; ?>

<div class="row g-3 mb-4">
    <div class="col-xl-4"><div class="card-crm"><div class="card-header"><h6><i class="fas fa-plus me-2 text-success"></i>Request video</h6></div>
        <div class="card-body"><form method="POST" class="row g-2">
            <div class="col-12"><input name="title" class="form-control" placeholder="Title, e.g. Acme launch teaser" required></div>
            <div class="col-12"><select name="pipeline" class="form-select">
                <?php foreach ($pipes as $id => $desc): ?><option value="<?= htmlspecialchars($id) ?>"><?= htmlspecialchars($id) ?> — <?= htmlspecialchars(mb_substr($desc, 0, 60)) ?></option><?php endforeach; ?>
            </select></div>
            <div class="col-12"><textarea name="brief" class="form-control" rows="4" placeholder="Brief: goal, audience, duration, tone, must-include facts…" required></textarea></div>
            <div class="col-6"><input name="budget_usd" type="number" step="0.5" min="0" class="form-control" placeholder="Budget USD (cap <?= htmlspecialchars((string)($status['budget_cap_usd'] ?? 10)) ?>)"></div>
            <div class="col-6 d-flex align-items-center"><small class="text-muted">Defaults models: tts, video, image per intake.</small></div>
            <div class="col-12"><button class="btn btn-danger w-100"><i class="fas fa-clapperboard me-1"></i> Request production</button></div>
        </form><small class="text-muted">Scaffolds <code>data/video-projects/&lt;slug&gt;/brief.md</code> + <code>models.json</code>. Agent runs research → proposal (gate) → script → scenes → assets → edit → compose.</small></div></div></div>
    <div class="col-xl-8"><div class="card-crm"><div class="card-header"><h6><i class="fas fa-film me-2 text-primary"></i>Productions (<?= count($rows) ?>)</h6></div>
        <div class="card-body p-0"><div class="table-responsive"><table class="table table-crm mb-0">
            <thead><tr><th>Title</th><th>Pipeline</th><th>Status</th><th>Requested</th><th class="text-end">Actions</th></tr></thead><tbody>
            <?php foreach ($rows as $r): ?>
            <tr style="font-size:12px;">
                <td><strong><?= htmlspecialchars($r['title'] ?? '-') ?></strong><div class="text-muted small"><?= htmlspecialchars($r['slug'] ?? '') ?><?= !empty($r['has_final']) ? ' · <span class="text-success">final ready</span>' : '' ?></div></td>
                <td class="text-nowrap"><?= htmlspecialchars($r['pipeline'] ?? '-') ?></td>
                <td><span class="badge bg-secondary"><?= htmlspecialchars($r['status'] ?? '-') ?></span></td>
                <td class="text-muted text-nowrap"><?= htmlspecialchars($r['created_at'] ?? '') ?></td>
                <td class="text-end text-nowrap">
                    <a class="btn btn-sm btn-outline-info" title="View" href="?view=<?= htmlspecialchars($r['slug'] ?? '') ?>"><i class="fas fa-eye"></i></a>
                    <a class="btn btn-sm btn-outline-warning" title="Edit" href="?edit=<?= htmlspecialchars($r['slug'] ?? '') ?>"><i class="fas fa-pen"></i></a>
                    <form method="POST" class="d-inline" onsubmit="return confirm('Delete <?= htmlspecialchars($r['slug'] ?? '') ?> + its files?');">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="slug" value="<?= htmlspecialchars($r['slug'] ?? '') ?>">
                        <button class="btn btn-sm btn-outline-danger" title="Delete"><i class="fas fa-trash"></i></button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($rows)): ?><tr><td colspan="5" class="text-center text-muted p-3">No productions yet — request the first video.</td></tr><?php endif; ?>
            </tbody></table></div></div></div></div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
