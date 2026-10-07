<?php
/**
 * AutoFlows list — cards with schedule, active toggle and one-click run.
 */
$flows   = (array) $flows;
$due     = (array) $due;
$dueById = array_column($due, 'due', 'id');
$llm     = (array) $llm;
$channels= (array) $channels;
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
  <div>
    <h1 class="h4 mb-1">AutoFlows</h1>
    <p class="text-secondary small mb-0">Standing recipes — brief, channel and schedule, run by hand or by cron.</p>
  </div>
  <div class="d-flex gap-2">
    <span class="badge <?= $llm['ok'] ? 'text-bg-success' : 'text-bg-warning' ?> align-self-center">
      <?= $llm['ok'] ? e($llm['model']) . ' online' : 'offline · template engine' ?>
    </span>
    <a class="btn btn-accent btn-sm" href="<?= url('flow') ?>#new" id="newFlowBtn">
      <i class="bi bi-plus-lg me-1"></i>New flow
    </a>
  </div>
</div>

<?php if (empty($flows)): ?>
  <div class="card text-center py-5">
    <div class="display-5 mb-3 text-primary"><i class="bi bi-diagram-3"></i></div>
    <h2 class="h5">No flows yet</h2>
    <p class="text-secondary mx-auto" style="max-width:52ch">
      A flow is a standing brief plus a schedule. Create one and AutoFlows will
      generate the same content family every day — or press <strong>Run now</strong> to try it immediately.
    </p>
    <a class="btn btn-accent mx-auto" href="<?= url('flow') ?>#new"><i class="bi bi-plus-lg me-1"></i>Create your first flow</a>
  </div>
<?php else: ?>
  <div class="row g-3">
    <?php foreach ($flows as $f):
      $plats = json_decode((string) $f['platforms'], true) ?: [];
      $isDue = !empty($dueById[(int) $f['id']]);
    ?>
      <div class="col-md-6 col-xl-4" data-flow-card="<?= (int) $f['id'] ?>">
        <div class="card h-100 flow-card <?= (int) $f['active'] ? '' : 'is-off' ?>">
          <div class="card-header py-2 d-flex justify-content-between align-items-center">
            <span class="d-flex align-items-center gap-2">
              <i class="bi <?= e((string) ($channels[$f['channel']]['icon'] ?? 'bi-collection')) ?> text-accent"></i>
              <span class="badge text-bg-dark"><?= e($f['channel']) ?></span>
              <?php if ($isDue): ?><span class="badge text-bg-warning">due</span><?php endif; ?>
            </span>
            <div class="form-check form-switch mb-0">
              <input class="form-check-input flow-toggle" type="checkbox" role="switch"
                     data-id="<?= (int) $f['id'] ?>" <?= (int) $f['active'] ? 'checked' : '' ?>>
            </div>
          </div>

          <div class="card-body">
            <h2 class="h6 mb-1">
              <a class="text-decoration-none" href="<?= url('flow') ?>&id=<?= (int) $f['id'] ?>"><?= e($f['name']) ?></a>
            </h2>
            <p class="small text-secondary mb-2"><?= e(excerpt((string) $f['brief'], 110) ?: 'No brief set.') ?></p>

            <?php if ($f['channel'] === 'social' && $plats !== []): ?>
              <div class="d-flex flex-wrap gap-1 mb-2">
                <?php foreach ($plats as $p): ?>
                  <span class="badge text-bg-secondary"><?= e($p) ?></span>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>

            <dl class="row small mb-0">
              <dt class="col-5 text-secondary">Schedule</dt>
              <dd class="col-7"><?= e($f['schedule']) ?><?= $f['schedule'] !== 'manual' ? ' · ' . e($f['run_at']) : '' ?></dd>
              <dt class="col-5 text-secondary">Variants</dt>
              <dd class="col-7"><?= (int) $f['count'] ?></dd>
              <dt class="col-5 text-secondary">Tone</dt>
              <dd class="col-7"><?= e($f['tone']) ?></dd>
              <dt class="col-5 text-secondary">Last run</dt>
              <dd class="col-7"><?= $f['last_run_at'] ? e(time_ago($f['last_run_at'])) : 'never' ?></dd>
            </dl>
          </div>

          <div class="card-footer bg-transparent d-flex gap-2">
            <button class="btn btn-accent btn-sm flex-grow-1 run-flow" data-id="<?= (int) $f['id'] ?>">
              <i class="bi bi-play-fill me-1"></i>Run now
            </button>
            <a class="btn btn-outline-light btn-sm" href="<?= url('flow') ?>&id=<?= (int) $f['id'] ?>" title="Edit">
              <i class="bi bi-pencil"></i>
            </a>
            <button class="btn btn-outline-danger btn-sm del-flow" data-id="<?= (int) $f['id'] ?>" data-name="<?= e($f['name']) ?>" title="Delete">
              <i class="bi bi-trash3"></i>
            </button>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<!-- ── run history ───────────────────────────────────────────────── -->
<div class="card mt-4">
  <div class="card-header py-2 d-flex justify-content-between align-items-center">
    <span><i class="bi bi-clock-history me-1"></i>Recent runs</span>
    <a class="small" href="<?= url('agent') ?>">agent console</a>
  </div>
  <div class="table-responsive">
    <table class="table table-sm table-hover mb-0 align-middle">
      <thead class="table-dark">
        <tr><th>#</th><th>Goal</th><th>Steps</th><th>Outputs</th><th>Provider</th><th>Time</th><th>Status</th><th></th></tr>
      </thead>
      <tbody>
        <?php foreach ((array) $runs as $r): ?>
          <tr>
            <td class="text-secondary"><?= (int) $r['id'] ?></td>
            <td class="text-truncate" style="max-width:32ch"><?= e($r['goal'] ?: '—') ?></td>
            <td><?= (int) $r['steps'] ?></td>
            <td><?= (int) $r['outputs'] ?></td>
            <td><span class="badge text-bg-dark"><?= e($r['provider'] ?? '—') ?></span></td>
            <td class="text-secondary"><?= $r['ms'] ? round($r['ms'] / 1000, 1) . 's' : '—' ?></td>
            <td>
              <span class="badge <?= $r['status'] === 'done' ? 'text-bg-success' : ($r['status'] === 'failed' ? 'text-bg-danger' : 'text-bg-info') ?>">
                <?= e($r['status']) ?>
              </span>
            </td>
            <td class="text-end">
              <a class="btn btn-xs btn-outline-light" href="<?= url('agent') ?>&run=<?= (int) $r['id'] ?>">trace</a>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (empty($runs)): ?>
          <tr><td colspan="8" class="text-secondary text-center py-3">No runs yet — press <strong>Run now</strong> on a flow.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php View::share('extraScripts', ['flows.js']); ?>
