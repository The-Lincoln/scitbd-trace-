<?php
/**
 * Dashboard — today's queue, channel stats, quick generate, activity feed.
 */
$content   = (array) $content;
$flowStats = (array) $flowStats;
$runStats  = (array) $runStats;
$llm       = (array) $llm;
$channels  = (array) $channels;
$cards = [
    ['Today',     $content['today'],      'bi-lightning-charge', 'text-accent', 'items created today'],
    ['Queue',     $content['queue'],      'bi-calendar-check',   'text-info',   'scheduled, awaiting publish'],
    ['Drafts',    $content['draft'],      'bi-pencil',           'text-warning','waiting on review'],
    ['Published', $content['published'],  'bi-send',             'text-success','live in the wild'],
    ['Flows',     $flowStats['active'],   'bi-diagram-3',        'text-primary',(int) $flowStats['flows'] . ' total, ' . (int) $runStats['today'] . ' runs today'],
    ['Items',     $content['total'],      'bi-collection',       'text-secondary', (int) $content['social'] . ' social · ' . (int) $content['blog'] . ' blog · ' . (int) $content['email'] . ' email'],
];
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
  <div>
    <h1 class="h4 mb-1">Dashboard</h1>
    <p class="text-secondary small mb-0">
      <?= e(date('l, j F Y')) ?> ·
      <span class="badge <?= $llm['ok'] ? 'text-bg-success' : 'text-bg-warning' ?>" id="llmChip">
        <?= $llm['ok'] ? e($llm['model']) . ' online' : 'offline · template engine' ?>
      </span>
    </p>
  </div>
  <div class="d-flex gap-2">
    <a class="btn btn-outline-light btn-sm" href="<?= url('flows') ?>#new"><i class="bi bi-plus-lg me-1"></i>New flow</a>
    <a class="btn btn-accent btn-sm" href="<?= url('agent') ?>"><i class="bi bi-robot me-1"></i>Run FlowAgent</a>
  </div>
</div>

<!-- ── stat cards ─────────────────────────────────────────────────── -->
<div class="row g-3 mb-4">
  <?php foreach ($cards as [$label, $value, $icon, $cls, $sub]): ?>
    <div class="col-6 col-md-4 col-xl-2">
      <div class="card stat-card h-100">
        <div class="card-body py-3">
          <div class="d-flex justify-content-between align-items-start">
            <span class="small text-secondary"><?= e($label) ?></span>
            <i class="bi <?= $icon ?> <?= $cls ?>"></i>
          </div>
          <div class="stat-value"><?= (int) $value ?></div>
          <div class="small text-secondary text-truncate"><?= e($sub) ?></div>
        </div>
      </div>
    </div>
  <?php endforeach; ?>
</div>

<div class="row g-3">
  <!-- ── quick generate ───────────────────────────────────────────── -->
  <div class="col-lg-6">
    <div class="card h-100">
      <div class="card-header py-2 d-flex align-items-center gap-2">
        <i class="bi bi-lightning-charge-fill text-accent"></i><strong>Quick generate</strong>
      </div>
      <div class="card-body">
        <p class="text-secondary small">One brief, one click. Output lands in <a href="<?= url('content') ?>">Content</a> as a draft.</p>
        <ul class="nav nav-pills mb-3" role="tablist" id="qgTabs">
          <?php $first = true; foreach ($channels as $key => $cfg): ?>
            <li class="nav-item" role="presentation">
              <button class="nav-link nav-link-sm <?= $first ? 'active' : '' ?>" data-qg="<?= e($key) ?>" type="button">
                <i class="bi <?= e($cfg['icon']) ?> me-1"></i><?= e($cfg['label']) ?>
              </button>
            </li>
          <?php $first = false; endforeach; ?>
        </ul>

        <form id="quickGen">
          <input type="hidden" name="channel" id="qgChannel" value="social">
          <div class="mb-3">
            <label class="form-label" for="qgBrief">Brief</label>
            <textarea class="form-control" id="qgBrief" rows="3" required
              placeholder="e.g. why small teams should publish weekly instead of chasing virality"></textarea>
            <div class="form-text">Feeds the prompt, the brand voice and the review step.</div>
          </div>
          <div class="row g-2 mb-3">
            <div class="col-sm-6">
              <label class="form-label" for="qgTone">Tone</label>
              <select class="form-select form-select-sm" id="qgTone">
                <option value="warm">Warm</option>
                <option value="direct">Direct</option>
                <option value="playful">Playful</option>
                <option value="authoritative">Authoritative</option>
                <option value="friendly">Friendly</option>
              </select>
            </div>
            <div class="col-sm-6">
              <label class="form-label" for="qgCount">Variants</label>
              <input type="number" class="form-control form-control-sm" id="qgCount" value="3" min="1" max="10">
            </div>
          </div>
          <div class="d-flex gap-2">
            <button class="btn btn-accent" type="submit" id="qgRun"><i class="bi bi-stars me-1"></i>Generate</button>
            <a class="btn btn-outline-light" href="<?= url('agent') ?>">Open full agent</a>
          </div>
          <div class="progress mt-3 d-none" id="qgProgress" style="height:6px">
            <div class="progress-bar progress-bar-striped progress-bar-animated" style="width:10%"></div>
          </div>
          <div id="qgLog" class="small mt-2"></div>
        </form>
      </div>
    </div>
  </div>

  <!-- ── today's queue ────────────────────────────────────────────── -->
  <div class="col-lg-6">
    <div class="card h-100">
      <div class="card-header py-2 d-flex justify-content-between align-items-center">
        <span><i class="bi bi-calendar2-week me-1"></i>Schedule</span>
        <span class="badge text-bg-dark"><?= count($queue) ?> queued</span>
      </div>
      <div class="card-body p-0">
        <?php if (empty($queue)): ?>
          <p class="text-secondary small p-3 mb-0">
            Nothing scheduled yet. Approve a draft and set a publish time, or
            <a href="<?= url('flows') ?>">create a daily flow</a>.
          </p>
        <?php else: ?>
          <ul class="list-group list-group-flush">
            <?php foreach ($queue as $q): $q = Content::decorate($q); ?>
              <li class="list-group-item bg-transparent d-flex justify-content-between align-items-center gap-2">
                <div class="d-flex align-items-center gap-2 min-w-0">
                  <i class="bi <?= e((string) config('channels.' . $q['channel'] . '.icon', 'bi-pencil')) ?> text-secondary"></i>
                  <div class="min-w-0">
                    <a class="d-block text-truncate text-decoration-none" href="<?= url('item') ?>&id=<?= (int) $q['id'] ?>">
                      <?= e($q['title'] ?: excerpt($q['body'], 50)) ?>
                    </a>
                    <span class="small text-secondary"><?= e($q['channel']) ?><?= $q['platform'] ? ' · ' . e($q['platform']) : '' ?></span>
                  </div>
                </div>
                <div class="text-end small text-nowrap">
                  <span class="badge <?= $q['status'] === 'published' ? 'text-bg-success' : 'text-bg-info' ?>"><?= e($q['status']) ?></span>
                  <div class="text-secondary"><?= e(substr((string) $q['publish_at'], 5, 11)) ?></div>
                </div>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<div class="row g-3 mt-1">
  <!-- ── flows due ────────────────────────────────────────────────── -->
  <div class="col-lg-5">
    <div class="card h-100">
      <div class="card-header py-2 d-flex justify-content-between align-items-center">
        <span><i class="bi bi-diagram-3 me-1"></i>Flows due today</span>
        <a class="small" href="<?= url('flows') ?>">all</a>
      </div>
      <div class="card-body p-0">
        <?php if (empty($dueFlows)): ?>
          <p class="text-secondary small p-3 mb-0">Every active flow has already run today. 🎉</p>
        <?php else: ?>
          <ul class="list-group list-group-flush">
            <?php foreach ($dueFlows as $f): ?>
              <li class="list-group-item bg-transparent d-flex justify-content-between align-items-center gap-2">
                <div class="min-w-0">
                  <a class="d-block text-truncate text-decoration-none fw-semibold" href="<?= url('flow') ?>&id=<?= (int) $f['id'] ?>"><?= e($f['name']) ?></a>
                  <span class="small text-secondary"><?= e($f['channel']) ?> · <?= e($f['schedule']) ?> at <?= e($f['run_at']) ?></span>
                </div>
                <button class="btn btn-sm btn-outline-light run-flow" data-id="<?= (int) $f['id'] ?>" title="Run now">
                  <i class="bi bi-play-fill"></i>
                </button>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- ── recent content ───────────────────────────────────────────── -->
  <div class="col-lg-4">
    <div class="card h-100">
      <div class="card-header py-2 d-flex justify-content-between align-items-center">
        <span><i class="bi bi-collection me-1"></i>Recent content</span>
        <a class="small" href="<?= url('content') ?>">library</a>
      </div>
      <div class="list-group list-group-flush">
        <?php foreach ($recent as $r): ?>
          <a href="<?= url('item') ?>&id=<?= (int) $r['id'] ?>" class="list-group-item list-group-item-action bg-transparent px-3 py-2">
            <div class="d-flex justify-content-between gap-2">
              <span class="text-truncate"><?= e($r['title'] ?: excerpt($r['body'], 48)) ?></span>
              <span class="badge text-bg-dark text-nowrap"><?= e($r['status']) ?></span>
            </div>
            <span class="small text-secondary"><?= e($r['channel']) ?><?= $r['platform'] ? ' · ' . e($r['platform']) : '' ?> · <?= (int) $r['chars'] ?> chars</span>
          </a>
        <?php endforeach; ?>
        <?php if (empty($recent)): ?>
          <div class="list-group-item bg-transparent text-secondary small p-3">Nothing generated yet.</div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- ── activity ─────────────────────────────────────────────────── -->
  <div class="col-lg-3">
    <div class="card h-100">
      <div class="card-header py-2"><i class="bi bi-activity me-1"></i>Activity</div>
      <div class="list-group list-group-flush small">
        <?php foreach ($activity as $a): ?>
          <div class="list-group-item bg-transparent px-3 py-2">
            <div class="d-flex justify-content-between gap-2">
              <code class="text-truncate"><?= e($a['action']) ?></code>
              <span class="text-secondary text-nowrap"><?= e(time_ago($a['created_at'])) ?></span>
            </div>
            <?php if (($a['detail'] ?? '') !== ''): ?>
              <div class="text-secondary text-truncate"><?= e($a['detail']) ?></div>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
        <?php if (empty($activity)): ?>
          <div class="list-group-item bg-transparent text-secondary p-3">No activity logged yet.</div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<?php View::share('extraScripts', ['dashboard.js']); ?>
