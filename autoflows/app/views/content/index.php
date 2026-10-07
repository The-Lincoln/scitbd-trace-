<?php
/**
 * Content library — filter tabs, card grid, bulk status actions.
 */
$items     = (array) $items;
$stats     = (array) $stats;
$channels  = (array) $channels;
$statuses  = (array) $statuses;
$platforms = (array) $platforms;
$ch        = (string) $channel;
$st        = (string) $status;

$tabs = array_merge(['all' => 'All'], array_map(fn ($c) => $c['label'], $channels));
$counts = ['all' => (int) $stats['total']] + [
    'social' => (int) $stats['social'],
    'blog'   => (int) $stats['blog'],
    'email'  => (int) $stats['email'],
];
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
  <div>
    <h1 class="h4 mb-1">Content</h1>
    <p class="text-secondary small mb-0">
      <?= (int) $stats['draft'] ?> drafts · <?= (int) $stats['queue'] ?> scheduled · <?= (int) $stats['published'] ?> published
    </p>
  </div>
  <div class="d-flex gap-2">
    <a class="btn btn-outline-light btn-sm" href="<?= url('agent') ?>"><i class="bi bi-robot me-1"></i>Generate more</a>
    <button class="btn btn-accent btn-sm" id="bulkBar" disabled>
      <i class="bi bi-check2-all me-1"></i><span id="bulkCount">0</span> selected
    </button>
  </div>
</div>

<!-- ── filters ───────────────────────────────────────────────────── -->
<form class="mb-3" method="get" action="index.php">
  <input type="hidden" name="r" value="content">
  <div class="row g-2 align-items-end">
    <div class="col-auto">
      <ul class="nav nav-pills">
        <?php foreach ($tabs as $key => $label): ?>
          <li class="nav-item">
            <a class="nav-link nav-link-sm <?= $ch === $key ? 'active' : '' ?>"
               href="<?= url('content') ?>&channel=<?= e($key) ?>&status=<?= e($st) ?>">
              <?= e($label) ?> <span class="badge text-bg-dark ms-1"><?= (int) ($counts[$key] ?? 0) ?></span>
            </a>
          </li>
        <?php endforeach; ?>
      </ul>
    </div>
    <div class="col-auto">
      <select class="form-select form-select-sm" name="status" onchange="this.form.submit()">
        <option value="all">Any status</option>
        <?php foreach ($statuses as $s): ?>
          <option value="<?= e($s) ?>" <?= $st === $s ? 'selected' : '' ?>><?= e(ucfirst($s)) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-auto">
      <div class="input-group input-group-sm">
        <input class="form-control" name="q" value="<?= e((string) $q) ?>" placeholder="Search title or body…">
        <button class="btn btn-outline-light" type="submit"><i class="bi bi-search"></i></button>
      </div>
    </div>
    <?php if ($ch !== 'all' || $st !== 'all' || $q !== ''): ?>
      <div class="col-auto">
        <a class="btn btn-sm btn-outline-secondary" href="<?= url('content') ?>">Clear</a>
      </div>
    <?php endif; ?>
  </div>
</form>

<!-- ── bulk actions ──────────────────────────────────────────────── -->
<div class="card mb-3 d-none" id="bulkCard">
  <div class="card-body py-2 d-flex flex-wrap gap-2 align-items-center">
    <span class="small text-secondary me-1">Set selected to:</span>
    <div class="btn-group btn-group-sm">
      <?php foreach ($statuses as $s): ?>
        <button class="btn btn-outline-light bulk-set" data-status="<?= e($s) ?>"><?= e(ucfirst($s)) ?></button>
      <?php endforeach; ?>
    </div>
    <div class="ms-auto d-flex gap-2 align-items-center">
      <input type="datetime-local" class="form-control form-control-sm" id="bulkWhen" style="width:auto">
      <button class="btn btn-sm btn-outline-danger" id="bulkDelete"><i class="bi bi-trash3"></i> Delete</button>
    </div>
  </div>
</div>

<!-- ── grid ──────────────────────────────────────────────────────── -->
<?php if (empty($items)): ?>
  <div class="card text-center py-5">
    <div class="display-5 mb-3 text-primary"><i class="bi bi-collection"></i></div>
    <h2 class="h5">Nothing here yet</h2>
    <p class="text-secondary mx-auto" style="max-width:48ch">
      Run a <a href="<?= url('flows') ?>">flow</a>, use the <a href="<?= url('agent') ?>">agent</a>,
      or fire a <code>/social</code> command from <a href="<?= url('chat') ?>">chat</a>.
    </p>
  </div>
<?php else: ?>
  <div class="row g-3">
    <?php foreach ($items as $it): $meta = $it['meta_arr']; ?>
      <div class="col-md-6 col-xl-4" data-item="<?= (int) $it['id'] ?>">
        <div class="card h-100 content-card">
          <div class="card-body">
            <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
              <div class="d-flex gap-1 align-items-center flex-wrap">
                <span class="badge text-bg-dark">
                  <i class="bi <?= e((string) $channels[$it['channel']]['icon'] ?? 'bi-pencil') ?> me-1"></i><?= e($it['channel']) ?>
                </span>
                <?php if ($it['platform']): ?>
                  <span class="badge text-bg-secondary"><?= e($it['platform']) ?></span>
                <?php endif; ?>
                <?php if ($it['score'] > 0): ?>
                  <span class="badge <?= $it['score'] >= 70 ? 'text-bg-success' : 'text-bg-warning' ?>"><?= (int) $it['score'] ?></span>
                <?php endif; ?>
              </div>
              <div class="form-check mb-0">
                <input class="form-check-input item-pick" type="checkbox" value="<?= (int) $it['id'] ?>">
              </div>
            </div>

            <h2 class="h6 mb-1">
              <a class="text-decoration-none text-truncate d-block" href="<?= url('item') ?>&id=<?= (int) $it['id'] ?>">
                <?= e($it['title'] ?: excerpt($it['body'], 60)) ?>
              </a>
            </h2>

            <?php if ($it['channel'] === 'email' && !empty($it['excerpt'])): ?>
              <p class="small text-secondary mb-1">Preheader: <?= e($it['excerpt']) ?></p>
            <?php elseif ($it['channel'] === 'blog' && !empty($it['excerpt'])): ?>
              <p class="small text-secondary mb-1"><?= e($it['excerpt']) ?></p>
            <?php endif; ?>

            <p class="small preview-text mb-2"><?= e(excerpt($it['channel'] === 'email' ? strip_tags($it['body']) : $it['body'], 130)) ?></p>

            <?php if ($it['hashtags']): ?>
              <p class="small text-info mb-2"><?= e($it['hashtags']) ?></p>
            <?php endif; ?>

            <div class="d-flex justify-content-between align-items-center small text-secondary">
              <span>
                <i class="bi bi-type me-1"></i><?= (int) $it['chars'] ?> chars
                <?php if ($it['limit']): ?>
                  <span class="<?= $it['over_limit'] ? 'text-danger' : '' ?>">/ <?= (int) $it['limit'] ?></span>
                <?php endif; ?>
                <?php if ($it['read_min']): ?> · <?= (int) $it['read_min'] ?> min read<?php endif; ?>
              </span>
              <span class="badge <?= $it['status'] === 'published' ? 'text-bg-success' : ($it['status'] === 'scheduled' ? 'text-bg-info' : ($it['status'] === 'approved' ? 'text-bg-primary' : 'text-bg-secondary')) ?>">
                <?= e($it['status']) ?>
              </span>
            </div>
          </div>

          <div class="card-footer bg-transparent d-flex gap-1 flex-wrap">
            <a class="btn btn-xs btn-outline-light" href="<?= url('item') ?>&id=<?= (int) $it['id'] ?>"><i class="bi bi-pencil"></i> Edit</a>
            <?php if ($it['status'] === 'draft'): ?>
              <button class="btn btn-xs btn-outline-success quick-status" data-id="<?= (int) $it['id'] ?>" data-status="approved"><i class="bi bi-check2"></i> Approve</button>
            <?php elseif ($it['status'] === 'approved'): ?>
              <button class="btn btn-xs btn-outline-info quick-status" data-id="<?= (int) $it['id'] ?>" data-status="published"><i class="bi bi-send"></i> Publish</button>
            <?php elseif ($it['status'] === 'scheduled'): ?>
              <span class="small text-secondary align-self-center">→ <?= e(substr((string) $it['publish_at'], 0, 16)) ?></span>
            <?php endif; ?>
            <div class="dropdown ms-auto">
              <button class="btn btn-xs btn-outline-light" data-bs-toggle="dropdown" aria-expanded="false">
                <i class="bi bi-three-dots"></i>
              </button>
              <ul class="dropdown-menu dropdown-menu-end">
                <li><a class="dropdown-item" href="<?= url('item') ?>&id=<?= (int) $it['id'] ?>"><i class="bi bi-eye me-2"></i>Preview</a></li>
                <li><a class="dropdown-item" href="<?= url('api/content/export') ?>&id=<?= (int) $it['id'] ?>&type=md"><i class="bi bi-file-earmark-text me-2"></i>Markdown</a></li>
                <li><a class="dropdown-item" href="<?= url('api/content/export') ?>&id=<?= (int) $it['id'] ?>&type=csv"><i class="bi bi-filetype-csv me-2"></i>CSV</a></li>
                <li><a class="dropdown-item" href="<?= url('api/content/export') ?>&id=<?= (int) $it['id'] ?>&type=html"><i class="bi bi-filetype-html me-2"></i>HTML</a></li>
                <?php if ($it['run_id']): ?>
                  <li><a class="dropdown-item" href="<?= url('agent') ?>&run=<?= (int) $it['run_id'] ?>"><i class="bi bi-clock-history me-2"></i>Run trace</a></li>
                <?php endif; ?>
                <li><hr class="dropdown-divider"></li>
                <li><button class="dropdown-item text-danger del-item" data-id="<?= (int) $it['id'] ?>"><i class="bi bi-trash3 me-2"></i>Delete</button></li>
              </ul>
            </div>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php View::share('extraScripts', ['content.js']); ?>
