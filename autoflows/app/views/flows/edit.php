<?php
/**
 * Flow editor — new or existing recipe.
 */
$isNew   = $flow === null;
$flow    = (array) ($flow ?? []);
$plats   = json_decode((string) ($flow['platforms'] ?? '[]'), true) ?: ['twitter', 'linkedin'];
$channels= (array) $channels;
$llm     = (array) $llm;
?>
<nav aria-label="breadcrumb" class="mb-3">
  <ol class="breadcrumb small mb-0">
    <li class="breadcrumb-item"><a href="<?= url('flows') ?>">AutoFlows</a></li>
    <li class="breadcrumb-item active"><?= $isNew ? 'New flow' : e((string) $flow['name']) ?></li>
  </ol>
</nav>

<div class="row g-3">
  <div class="col-lg-7">
    <div class="card">
      <div class="card-header py-2">
        <i class="bi <?= $isNew ? 'bi-plus-lg' : 'bi-pencil' ?> me-1"></i>
        <?= $isNew ? 'Create a flow' : 'Edit flow #' . (int) $flow['id'] ?>
      </div>
      <div class="card-body">
        <form id="flowForm">
          <input type="hidden" id="fId" value="<?= (int) ($flow['id'] ?? 0) ?>">

          <div class="mb-3">
            <label class="form-label" for="fName">Name <span class="text-danger">*</span></label>
            <input class="form-control" id="fName" required maxlength="120"
                   placeholder="e.g. Weekly founder pack"
                   value="<?= e((string) ($flow['name'] ?? '')) ?>">
          </div>

          <div class="mb-3">
            <label class="form-label d-block mb-1">Channel <span class="text-danger">*</span></label>
            <div class="btn-group w-100 flex-wrap" role="group" id="fChannel">
              <?php $cur = (string) ($flow['channel'] ?? 'pack'); foreach ($channels as $key => $cfg): ?>
                <input type="radio" class="btn-check" name="channel" id="ch_<?= e($key) ?>" value="<?= e($key) ?>"
                       <?= $cur === $key ? 'checked' : '' ?>>
                <label class="btn btn-outline-light" for="ch_<?= e($key) ?>">
                  <i class="bi <?= e($cfg['icon']) ?> me-1"></i><?= e($cfg['label']) ?>
                </label>
              <?php endforeach; ?>
              <input type="radio" class="btn-check" name="channel" id="ch_pack" value="pack"
                     <?= $cur === 'pack' ? 'checked' : '' ?>>
              <label class="btn btn-outline-light" for="ch_pack">
                <i class="bi bi-layers me-1"></i>Daily pack (all three)
              </label>
            </div>
          </div>

          <div class="mb-3">
            <label class="form-label" for="fBrief">Standing brief <span class="text-danger">*</span></label>
            <textarea class="form-control" id="fBrief" rows="4" required
                      placeholder="What should every run write about? e.g. practical content-marketing lessons for small teams"><?= e((string) ($flow['brief'] ?? '')) ?></textarea>
            <div class="form-text">This is prepended to every run. Keep it stable — it is the theme, not the daily variation.</div>
          </div>

          <div class="row g-3 mb-3">
            <div class="col-sm-4">
              <label class="form-label" for="fCount">Variants</label>
              <input type="number" class="form-control" id="fCount" min="1" max="10"
                     value="<?= (int) ($flow['count'] ?? 3) ?>">
            </div>
            <div class="col-sm-4">
              <label class="form-label" for="fTone">Tone</label>
              <input class="form-control" id="fTone" list="toneList" maxlength="60"
                     value="<?= e((string) ($flow['tone'] ?? 'warm')) ?>">
              <datalist id="toneList">
                <option value="warm"><option value="direct"><option value="playful">
                <option value="authoritative"><option value="friendly"><option value="contrarian">
              </datalist>
            </div>
            <div class="col-sm-4">
              <label class="form-label" for="fAudience">Audience</label>
              <input class="form-control" id="fAudience" maxlength="300"
                     placeholder="founders, small marketing teams"
                     value="<?= e((string) ($flow['audience'] ?? '')) ?>">
            </div>
          </div>

          <div class="mb-3" id="platformBox" style="<?= ($flow['channel'] ?? 'pack') === 'social' ? '' : 'display:none' ?>">
            <label class="form-label d-block mb-1">Platforms</label>
            <div class="d-flex flex-wrap gap-3">
              <?php foreach ((array) $platforms as $key => $p): ?>
                <div class="form-check">
                  <input class="form-check-input f-plat" type="checkbox" value="<?= e($key) ?>"
                         id="plat_<?= e($key) ?>" <?= in_array($key, $plats, true) ? 'checked' : '' ?>>
                  <label class="form-check-label small" for="plat_<?= e($key) ?>">
                    <i class="bi <?= e($p['icon']) ?> me-1"></i><?= e($p['label']) ?>
                    <span class="text-secondary">(<?= (int) $p['limit'] ?>)</span>
                  </label>
                </div>
              <?php endforeach; ?>
            </div>
          </div>

          <div class="row g-3 mb-3">
            <div class="col-sm-4">
              <label class="form-label" for="fSchedule">Schedule</label>
              <select class="form-select" id="fSchedule">
                <?php foreach (['daily' => 'Every day', 'weekly' => 'Once a week', 'manual' => 'Manual only'] as $k => $lab): ?>
                  <option value="<?= $k ?>" <?= ($flow['schedule'] ?? 'daily') === $k ? 'selected' : '' ?>><?= $lab ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-sm-4" id="weekdayBox" style="<?= ($flow['schedule'] ?? '') === 'weekly' ? '' : 'display:none' ?>">
              <label class="form-label" for="fWeekday">Weekday</label>
              <select class="form-select" id="fWeekday">
                <?php foreach ([1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'] as $n => $lab): ?>
                  <option value="<?= $n ?>" <?= (int) ($flow['weekday'] ?? 1) === $n ? 'selected' : '' ?>><?= $lab ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-sm-4" id="runAtBox" style="<?= ($flow['schedule'] ?? 'daily') === 'manual' ? 'display:none' : '' ?>">
              <label class="form-label" for="fRunAt">Run at</label>
              <input type="time" class="form-control" id="fRunAt" value="<?= e((string) ($flow['run_at'] ?? '09:00')) ?>">
            </div>
          </div>

          <div class="form-check form-switch mb-3">
            <input class="form-check-input" type="checkbox" id="fActive"
                   <?= (int) ($flow['active'] ?? 1) ? 'checked' : '' ?>>
            <label class="form-check-label" for="fActive">Active — eligible for scheduled runs</label>
          </div>

          <div class="d-flex gap-2">
            <button class="btn btn-accent" type="submit" id="fSave"><i class="bi bi-check2 me-1"></i>Save flow</button>
            <?php if (!$isNew): ?>
              <button class="btn btn-outline-light" type="button" id="fRunNow"><i class="bi bi-play-fill me-1"></i>Save &amp; run</button>
            <?php endif; ?>
            <a class="btn btn-outline-secondary" href="<?= url('flows') ?>">Cancel</a>
          </div>
          <div id="flowMsg" class="small mt-2"></div>
        </form>
      </div>
    </div>
  </div>

  <!-- ── preview + runs ────────────────────────────────────────────── -->
  <div class="col-lg-5">
    <div class="card mb-3">
      <div class="card-header py-2"><i class="bi bi-eye me-1"></i>What a run will do</div>
      <div class="card-body small">
        <div id="flowPreview"></div>
        <hr>
        <dl class="row mb-0">
          <dt class="col-5 text-secondary">Provider</dt>
          <dd class="col-7"><?= e($llm['label']) ?></dd>
          <dt class="col-5 text-secondary">Reachable</dt>
          <dd class="col-7">
            <?php if ($llm['ok']): ?><span class="text-success">yes · <?= (int) $llm['latency_ms'] ?> ms</span>
            <?php else: ?><span class="text-warning">no — template engine will be used</span><?php endif; ?>
          </dd>
          <dt class="col-5 text-secondary">Cron</dt>
          <dd class="col-7"><code>php tools/run_daily.php</code></dd>
        </dl>
        <div class="form-text mt-2">
          Point your scheduler at <code>run_daily.php</code> once a day; it picks up every
          active flow whose time has passed and has not yet run today.
        </div>
      </div>
    </div>

    <?php if (!$isNew): ?>
      <div class="card">
        <div class="card-header py-2"><i class="bi bi-clock-history me-1"></i>This flow's runs</div>
        <ul class="list-group list-group-flush small">
          <?php foreach ((array) $runs as $r): ?>
            <li class="list-group-item bg-transparent d-flex justify-content-between align-items-center gap-2">
              <div class="min-w-0">
                <span class="d-block text-truncate"><?= e($r['goal'] ?: '—') ?></span>
                <span class="text-secondary"><?= e(time_ago($r['created_at'])) ?> · <?= (int) $r['outputs'] ?> outputs</span>
              </div>
              <div class="d-flex gap-1 align-items-center">
                <span class="badge <?= $r['status'] === 'done' ? 'text-bg-success' : 'text-bg-danger' ?>"><?= e($r['status']) ?></span>
                <a class="btn btn-xs btn-outline-light" href="<?= url('agent') ?>&run=<?= (int) $r['id'] ?>">trace</a>
              </div>
            </li>
          <?php endforeach; ?>
          <?php if (empty($runs)): ?><li class="list-group-item bg-transparent text-secondary p-3">Never run.</li><?php endif; ?>
        </ul>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php View::share('extraScripts', ['flow-edit.js']); ?>
