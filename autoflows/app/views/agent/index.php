<?php
/**
 * FlowAgent console — goal form, live step trace, outputs, run history.
 */
$run       = (array) ($run ?? []);
$plan      = (array) $plan;
$runs      = (array) $runs;
$channels  = (array) $channels;
$platforms = (array) $platforms;
$llm       = (array) $llm;
$outputs   = (array) $outputs;
$isReplay  = $run !== [];
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
  <div>
    <h1 class="h4 mb-1"><i class="bi bi-robot me-2"></i>FlowAgent</h1>
    <p class="text-secondary small mb-0">One goal → six steps → every channel, streamed live and traced for replay.</p>
  </div>
  <div class="d-flex gap-2 align-items-center">
    <span class="badge <?= $llm['ok'] ? 'text-bg-success' : 'text-bg-warning' ?>">
      <?= $llm['ok'] ? e($llm['model']) . ' online' : 'offline · template engine' ?>
    </span>
    <?php if ($isReplay): ?>
      <a class="btn btn-sm btn-outline-light" href="<?= url('agent') ?>"><i class="bi bi-plus-lg me-1"></i>New run</a>
    <?php endif; ?>
  </div>
</div>

<div class="row g-3">
  <!-- ── goal + plan ────────────────────────────────────────────── -->
  <div class="col-lg-4">
    <div class="card mb-3">
      <div class="card-header py-2"><i class="bi bi-bullseye me-1"></i>Goal</div>
      <div class="card-body">
        <form id="agentForm">
          <div class="mb-3">
            <label class="form-label" for="aGoal">What should today's content be about?</label>
            <textarea class="form-control" id="aGoal" rows="4" required
              placeholder="e.g. why small teams should publish weekly instead of chasing virality"><?= $isReplay ? e((string) ($run['goal'] ?? '')) : '' ?></textarea>
          </div>

          <div class="mb-3">
            <label class="form-label d-block mb-1">Channels</label>
            <div class="d-flex flex-wrap gap-3">
              <?php foreach ($channels as $key => $cfg): ?>
                <div class="form-check">
                  <input class="form-check-input a-chan" type="checkbox" value="<?= e($key) ?>" id="a_<?= e($key) ?>" checked>
                  <label class="form-check-label" for="a_<?= e($key) ?>">
                    <i class="bi <?= e($cfg['icon']) ?> me-1"></i><?= e($cfg['label']) ?>
                  </label>
                </div>
              <?php endforeach; ?>
            </div>
          </div>

          <div class="mb-3">
            <label class="form-label d-block mb-1">Social platforms</label>
            <div class="d-flex flex-wrap gap-3">
              <?php $i = 0; foreach ($platforms as $key => $p): ?>
                <div class="form-check">
                  <input class="form-check-input a-plat" type="checkbox" value="<?= e($key) ?>" id="p_<?= e($key) ?>" <?= $i++ < 3 ? 'checked' : '' ?>>
                  <label class="form-check-label small" for="p_<?= e($key) ?>">
                    <i class="bi <?= e($p['icon']) ?> me-1"></i><?= e($p['label']) ?>
                  </label>
                </div>
              <?php endforeach; ?>
            </div>
          </div>

          <div class="row g-2 mb-3">
            <div class="col-6">
              <label class="form-label" for="aTone">Tone</label>
              <select class="form-select form-select-sm" id="aTone">
                <option value="">Brand default</option>
                <option value="warm">Warm</option>
                <option value="direct">Direct</option>
                <option value="playful">Playful</option>
                <option value="authoritative">Authoritative</option>
                <option value="contrarian">Contrarian</option>
              </select>
            </div>
            <div class="col-6">
              <label class="form-label" for="aCount">Social variants</label>
              <input type="number" class="form-control form-control-sm" id="aCount" value="3" min="1" max="10">
            </div>
          </div>

          <div class="mb-3">
            <label class="form-label" for="aAudience">Audience override</label>
            <input class="form-control form-control-sm" id="aAudience" placeholder="leave blank to use the brand voice">
          </div>

          <button class="btn btn-accent w-100" type="submit" id="aRun">
            <i class="bi bi-play-fill me-1"></i>Run agent
          </button>
          <div class="form-text mt-2">Each step streams its draft below, then writes items into the <a href="<?= url('content') ?>">library</a>.</div>
        </form>
      </div>
    </div>

    <!-- planned steps -->
    <div class="card">
      <div class="card-header py-2"><i class="bi bi-list-ol me-1"></i>Plan</div>
      <ol class="list-group list-group-numbered list-group-flush small" id="planList">
        <?php foreach ($plan as $p): ?>
          <li class="list-group-item bg-transparent d-flex align-items-center gap-2" data-step="<?= e($p['id']) ?>">
            <i class="bi <?= e($p['icon']) ?> text-secondary"></i>
            <span class="flex-grow-1"><?= e($p['label']) ?></span>
            <span class="step-state text-secondary">—</span>
          </li>
        <?php endforeach; ?>
      </ol>
    </div>
  </div>

  <!-- ── trace ──────────────────────────────────────────────────── -->
  <div class="col-lg-8">
    <div class="card">
      <div class="card-header py-2 d-flex justify-content-between align-items-center gap-2">
        <span><i class="bi bi-diagram-3 me-1"></i>
          <?= $isReplay ? 'Run #' . (int) $run['id'] : 'Live trace' ?>
        </span>
        <div class="d-flex gap-2 align-items-center">
          <span class="badge text-bg-dark" id="traceProvider">
            <?= $isReplay ? e((string) ($run['provider'] ?? '—')) : 'idle' ?>
          </span>
          <div class="progress" id="traceProgress" style="width:140px;height:6px">
            <div class="progress-bar" id="traceBar" style="width:0%"></div>
          </div>
        </div>
      </div>

      <div class="card-body agent-scroll" id="traceScroll">
        <?php if (!$isReplay): ?>
          <div class="agent-empty text-center py-5" id="agentEmpty">
            <div class="display-5 mb-3 text-primary"><i class="bi bi-signpost-split"></i></div>
            <h2 class="h5">Nothing running</h2>
            <p class="text-secondary mx-auto" style="max-width:46ch">
              Enter a goal and press <strong>Run agent</strong>. You'll watch each step
              draft, then land in the library as an editable draft.
            </p>
            <div class="d-flex flex-wrap gap-2 justify-content-center mt-3">
              <?php foreach ([
                  'Publishing weekly without a big team',
                  'Turning one blog post into a week of social posts',
                  'Re-engaging dormant trial users',
              ] as $chip): ?>
                <button class="btn btn-outline-light btn-sm goal-chip"><?= e($chip) ?></button>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endif; ?>
        <div id="traceSteps">
          <?php if ($isReplay): foreach (Run::trace($run) as $t): ?>
            <div class="trace-step done">
              <div class="trace-head">
                <span class="trace-label"><i class="bi bi-check-circle-fill text-success me-1"></i><?= e($t['label']) ?></span>
                <span class="trace-meta small text-secondary"><?= (int) $t['ms'] ?>ms · <?= (int) $t['chars'] ?> chars
                  <?= (int) $t['outputs'] > 0 ? '· ' . (int) $t['outputs'] . ' items' : '' ?></span>
              </div>
              <pre class="trace-out"><?= e((string) ($t['preview'] ?? '')) ?></pre>
            </div>
          <?php endforeach; endif; ?>
        </div>
      </div>

      <div class="card-footer bg-transparent d-flex gap-2" id="traceFoot" style="display:none">
        <a class="btn btn-accent btn-sm" id="traceOpenLib" href="<?= url('content') ?>" style="display:none">
          <i class="bi bi-collection me-1"></i><span id="traceOutCount">0</span> items → library
        </a>
        <button class="btn btn-outline-light btn-sm" id="traceAgain"><i class="bi bi-arrow-repeat me-1"></i>Run again</button>
        <span class="ms-auto small text-secondary align-self-center" id="traceTiming"></span>
      </div>
    </div>

    <!-- outputs -->
    <div class="card mt-3" id="outCard" style="display:none">
      <div class="card-header py-2"><i class="bi bi-collection me-1"></i>Generated in this run</div>
      <div class="list-group list-group-flush" id="outList"></div>
    </div>

    <?php if ($outputs): ?>
      <div class="card mt-3">
        <div class="card-header py-2"><i class="bi bi-collection me-1"></i>Outputs (<?= count($outputs) ?>)</div>
        <div class="list-group list-group-flush">
          <?php foreach ($outputs as $o): ?>
            <a class="list-group-item list-group-item-action bg-transparent d-flex justify-content-between gap-2"
               href="<?= url('item') ?>&id=<?= (int) $o['id'] ?>">
              <span class="text-truncate"><?= e($o['title'] ?: excerpt($o['body'], 60)) ?></span>
              <span class="text-secondary text-nowrap small"><?= e($o['channel']) ?><?= $o['platform'] ? ' · ' . e($o['platform']) : '' ?> · <?= (int) $o['chars'] ?> chars</span>
            </a>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endif; ?>
  </div>
</div>

<!-- ── run history ─────────────────────────────────────────────────── -->
<div class="card mt-4">
  <div class="card-header py-2"><i class="bi bi-clock-history me-1"></i>Run history</div>
  <div class="table-responsive">
    <table class="table table-sm table-hover mb-0 align-middle">
      <thead class="table-dark">
        <tr><th>#</th><th>Goal</th><th>Channels</th><th>Steps</th><th>Items</th><th>Score</th><th>Provider</th><th>When</th><th></th></tr>
      </thead>
      <tbody>
        <?php
        // Extract a review score from a stored trace (declared as a closure so
        // re-rendering the view can never hit a redeclare fatal).
        $scoreOf = function (array $trace): int {
            foreach ($trace as $t) {
                if (isset($t['score']) && (int) $t['score'] > 0) {
                    return (int) $t['score'];
                }
                if (($t['id'] ?? '') === 'review' && preg_match('/SCORE\s*:\s*(\d+)/i', (string) ($t['preview'] ?? ''), $m)) {
                    return (int) $m[1];
                }
            }
            return 0;
        };
        foreach ($runs as $r): $tr = Run::trace($r); $sc = $scoreOf($tr); ?>
          <tr class="<?= $isReplay && (int) $run['id'] === (int) $r['id'] ? 'table-secondary' : '' ?>">
            <td class="text-secondary"><?= (int) $r['id'] ?></td>
            <td class="text-truncate" style="max-width:30ch"><?= e($r['goal'] ?: '—') ?></td>
            <td class="small"><?= e((string) ($r['channel'] ?? '—')) ?></td>
            <td><?= (int) $r['steps'] ?></td>
            <td><?= (int) $r['outputs'] ?></td>
            <td><?= $sc ? $sc : '—' ?></td>
            <td><span class="badge text-bg-dark"><?= e($r['provider'] ?? '—') ?></span></td>
            <td class="text-secondary small"><?= e(time_ago($r['created_at'])) ?></td>
            <td class="text-end">
              <a class="btn btn-xs btn-outline-light" href="<?= url('agent') ?>&run=<?= (int) $r['id'] ?>">trace</a>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (empty($runs)): ?>
          <tr><td colspan="9" class="text-secondary text-center py-3">No runs yet.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php View::share('extraScripts', ['agent.js']); ?>
