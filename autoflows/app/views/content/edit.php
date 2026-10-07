<?php
/**
 * Content editor — split view: form on the left, live preview on the right.
 */
$item      = (array) $item;
$meta      = (array) ($item['meta_arr'] ?? []);
$statuses  = (array) $statuses;
$platforms = (array) $platforms;
$cfg       = (array) $channelCfg;
$isEmail   = $item['channel'] === 'email';
$isSocial  = $item['channel'] === 'social';
$isFacebook = $isSocial && strtolower((string) ($item['platform'] ?? '')) === 'facebook';
$limit     = (int) ($item['limit'] ?? 0);
$run       = (array) ($run ?? []);
$connections = (array) ($connections ?? []);
$gmailToDefault = (string) ($gmailToDefault ?? ($meta['send_to'] ?? ''));
$pubResult = (array) ($meta['publish_result'] ?? []);
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
  <nav aria-label="breadcrumb" class="mb-0">
    <ol class="breadcrumb small mb-0">
      <li class="breadcrumb-item"><a href="<?= url('content') ?>">Content</a></li>
      <li class="breadcrumb-item active">#<?= (int) $item['id'] ?></li>
    </ol>
  </nav>
  <div class="d-flex gap-2 align-items-center flex-wrap">
    <span class="badge text-bg-dark"><i class="bi <?= e($cfg['icon'] ?? 'bi-pencil') ?> me-1"></i><?= e($item['channel']) ?></span>
    <?php if ($item['platform']): ?><span class="badge text-bg-secondary"><?= e($item['platform']) ?></span><?php endif; ?>
    <?php if ($item['score'] > 0): ?><span class="badge <?= $item['score'] >= 70 ? 'text-bg-success' : 'text-bg-warning' ?>">score <?= (int) $item['score'] ?></span><?php endif; ?>
    <?php if ($run): ?>
      <a class="btn btn-sm btn-outline-light" href="<?= url('agent') ?>&run=<?= (int) $run['id'] ?>"><i class="bi bi-clock-history me-1"></i>Trace</a>
    <?php endif; ?>
    <button class="btn btn-sm btn-outline-warning" id="btnRegen"><i class="bi bi-arrow-repeat me-1"></i>Regenerate</button>
  </div>
</div>

<div class="row g-3">
  <!-- ── editor ───────────────────────────────────────────────────── -->
  <div class="col-lg-6">
    <div class="card">
      <div class="card-body">
        <form id="itemForm">
          <input type="hidden" id="iId" value="<?= (int) $item['id'] ?>">

          <div class="mb-3">
            <label class="form-label" for="iTitle">
              <?= $isEmail ? 'Subject line' : 'Title' ?>
              <?php if ($isEmail): ?><span class="badge text-bg-dark ms-1" id="subjectCount">0 / 45</span><?php endif; ?>
            </label>
            <input class="form-control" id="iTitle" maxlength="300" value="<?= e((string) $item['title']) ?>">
          </div>

          <?php if ($isEmail): ?>
            <div class="row g-2 mb-3">
              <div class="col-sm-6">
                <label class="form-label" for="iPreheader">Preheader</label>
                <input class="form-control" id="iPreheader" maxlength="200" value="<?= e((string) ($meta['preheader'] ?? '')) ?>">
              </div>
              <div class="col-sm-6">
                <label class="form-label" for="iCta">CTA button</label>
                <input class="form-control" id="iCta" maxlength="80" value="<?= e((string) ($meta['cta'] ?? '')) ?>">
              </div>
            </div>
            <div class="mb-3">
              <label class="form-label" for="iHeadline">In-email headline</label>
              <input class="form-control" id="iHeadline" maxlength="200" value="<?= e((string) ($meta['headline'] ?? '')) ?>">
            </div>
          <?php endif; ?>

          <?php if ($item['channel'] === 'blog'): ?>
            <div class="row g-2 mb-3">
              <div class="col-sm-6">
                <label class="form-label" for="iSlug">Slug</label>
                <input class="form-control" id="iSlug" value="<?= e((string) $item['slug']) ?>">
              </div>
              <div class="col-sm-6">
                <label class="form-label" for="iExcerpt">Excerpt <span class="badge text-bg-dark" id="excerptCount">0 / 160</span></label>
                <input class="form-control" id="iExcerpt" maxlength="300" value="<?= e((string) $item['excerpt']) ?>">
              </div>
            </div>
          <?php endif; ?>

          <?php if ($isSocial): ?>
            <div class="mb-3">
              <label class="form-label" for="iHashtags">Hashtags</label>
              <input class="form-control" id="iHashtags" value="<?= e((string) $item['hashtags']) ?>" placeholder="#contentmarketing #smallteams">
            </div>
          <?php endif; ?>

          <div class="mb-3">
            <label class="form-label d-flex justify-content-between" for="iBody">
              <span>Body <?= $isEmail ? '<span class="text-secondary">(plain text — rendered as HTML)</span>' : '<span class="text-secondary">(Markdown)</span>' ?></span>
              <span class="badge <?= $limit && mb_strlen($item['body']) > $limit ? 'text-bg-danger' : 'text-bg-dark' ?>" id="charCount">
                <?= (int) $item['chars'] ?><?= $limit ? ' / ' . $limit : '' ?>
              </span>
            </label>
            <textarea class="form-control font-monospace" id="iBody" rows="18"><?= e((string) $item['body']) ?></textarea>
            <div class="form-text" id="limitHint">
              <?php if ($limit): ?>
                <?= e($item['platform']) ?> hard limit is <?= (int) $limit ?> characters including hashtags.
              <?php else: ?>
                Markdown headings, lists and emphasis are supported in the preview.
              <?php endif; ?>
            </div>
          </div>

          <div class="row g-3 mb-3">
            <div class="col-sm-4">
              <label class="form-label" for="iStatus">Status</label>
              <select class="form-select" id="iStatus">
                <?php foreach ($statuses as $s): ?>
                  <option value="<?= e($s) ?>" <?= $item['status'] === $s ? 'selected' : '' ?>><?= e(ucfirst($s)) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-sm-4">
              <label class="form-label" for="iPublish">Publish at</label>
              <input type="datetime-local" class="form-control" id="iPublish"
                     value="<?= e($item['publish_at'] ? date('Y-m-d\TH:i', strtotime($item['publish_at'])) : '') ?>">
            </div>
            <div class="col-sm-4">
              <label class="form-label" for="iScore">Editor score</label>
              <input type="number" class="form-control" id="iScore" min="0" max="100" value="<?= (int) $item['score'] ?>">
            </div>
          </div>

          <div class="d-flex gap-2">
            <button class="btn btn-accent" type="submit" id="iSave"><i class="bi bi-check2 me-1"></i>Save</button>
            <a class="btn btn-outline-light" href="<?= url('api/content/export') ?>&id=<?= (int) $item['id'] ?>"><i class="bi bi-download me-1"></i>Export</a>
            <button class="btn btn-outline-danger ms-auto" type="button" id="iDelete"><i class="bi bi-trash3"></i></button>
          </div>
          <div class="small mt-2" id="itemMsg"></div>
        </form>
      </div>
    </div>

    <!-- ── publish ────────────────────────────────────────────────── -->
    <div class="card mt-3">
      <div class="card-header py-2"><i class="bi bi-send me-1"></i>Publish</div>
      <div class="card-body">
        <?php if ($isEmail): ?>
          <p class="small text-secondary mb-2">
            Sends via Gmail API as <strong><?= e($connections['google']['email'] ?? '—') ?></strong>
            <?= !empty($connections['google']['connected']) ? '<span class="badge ' . (!empty($connections['google']['simulated']) ? 'text-bg-warning' : 'text-bg-success') . ' ms-1">' . (!empty($connections['google']['simulated']) ? 'simulated' : 'connected') . '</span>' : '<span class="badge text-bg-secondary ms-1">not connected</span>' ?>
            <?php if (empty($connections['google']['connected'])): ?><a href="<?= url('settings') ?>" class="ms-1">Connect Gmail</a><?php endif; ?>
          </p>
          <div class="input-group input-group-sm mb-2">
            <input class="form-control" id="pubTo" type="email" placeholder="recipient@example.com" value="<?= e($gmailToDefault) ?>">
            <button class="btn btn-accent" id="btnPublishGmail"><i class="bi bi-envelope-paper me-1"></i>Send via Gmail</button>
          </div>
        <?php elseif ($isFacebook || ($isSocial && !$item['platform'])): ?>
          <p class="small text-secondary mb-2">
            Posts to Facebook <?= !empty($connections['facebook']['page_name']) ? 'page <strong>' . e($connections['facebook']['page_name']) . '</strong>' : 'feed' ?>
            <?= !empty($connections['facebook']['connected']) ? '<span class="badge ' . (!empty($connections['facebook']['simulated']) ? 'text-bg-warning' : 'text-bg-success') . ' ms-1">' . (!empty($connections['facebook']['simulated']) ? 'simulated' : 'connected') . '</span>' : '<span class="badge text-bg-secondary ms-1">not connected</span>' ?>
            <?php if (empty($connections['facebook']['connected'])): ?><a href="<?= url('settings') ?>" class="ms-1">Connect Facebook</a><?php endif; ?>
          </p>
          <button class="btn btn-sm btn-primary" style="background:#1877F2;border-color:#1877F2" id="btnPublishFb"><i class="bi bi-facebook me-1"></i>Post to Facebook</button>
        <?php else: ?>
          <p class="small text-secondary mb-2">Mark this <?= e($item['channel']) ?><?= $item['platform'] ? ' / ' . e($item['platform']) : '' ?> item published (recorded locally).</p>
          <button class="btn btn-sm btn-outline-info" id="btnPublishGeneric"><i class="bi bi-send me-1"></i>Mark published</button>
        <?php endif; ?>
        <div class="small mt-2" id="pubMsg"></div>
        <?php if (!empty($pubResult)): ?>
          <div class="alert <?= !empty($pubResult['ok']) ? 'alert-success' : 'alert-danger' ?> small mb-0 mt-2 py-2">
            <strong><?= !empty($pubResult['simulated']) ? 'Simulated' : 'Last publish' ?>:</strong>
            <?= e($pubResult['note'] ?? $pubResult['error'] ?? '') ?>
            <?php if (!empty($pubResult['url'])): ?><br><a href="<?= e($pubResult['url']) ?>" target="_blank" rel="noopener">View post</a><?php endif; ?>
            <?php if (!empty($pubResult['message_id'])): ?><br><span class="text-secondary">Gmail id: <?= e($pubResult['message_id']) ?></span><?php endif; ?>
            <?php if (!empty($pubResult['at'])): ?><br><span class="text-secondary"><?= e($pubResult['at']) ?></span><?php endif; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- ── preview ──────────────────────────────────────────────────── -->
  <div class="col-lg-6">
    <div class="card">
      <div class="card-header py-2 d-flex justify-content-between align-items-center">
        <span><i class="bi bi-eye me-1"></i>Preview</span>
        <div class="btn-group btn-group-sm" id="previewMode">
          <?php if ($isEmail): ?>
            <button class="btn btn-outline-light active" data-mode="render"><i class="bi bi-envelope me-1"></i>Inbox</button>
            <button class="btn btn-outline-light" data-mode="html"><i class="bi bi-code-slash me-1"></i>HTML</button>
          <?php else: ?>
            <button class="btn btn-outline-light active" data-mode="render"><i class="bi bi-eye me-1"></i>Rendered</button>
            <button class="btn btn-outline-light" data-mode="raw"><i class="bi bi-code-slash me-1"></i>Markdown</button>
          <?php endif; ?>
        </div>
      </div>
      <div class="card-body">
        <?php if ($isEmail): ?>
          <div id="previewRender" class="email-frame-wrap">
            <div class="email-frame" id="emailFrame"></div>
          </div>
          <pre id="previewRaw" class="d-none code-block small mb-0"></pre>
        <?php else: ?>
          <div id="previewRender" class="md preview-pane"></div>
          <pre id="previewRaw" class="d-none code-block small mb-0"></pre>
        <?php endif; ?>
      </div>
    </div>

    <?php if (!empty($sibling)): ?>
      <div class="card mt-3">
        <div class="card-header py-2"><i class="bi bi-hashes me-1"></i>Same run (<?= count($sibling) ?>)</div>
        <ul class="list-group list-group-flush small">
          <?php foreach ($sibling as $s): if ((int) $s['id'] === (int) $item['id']) continue; ?>
            <li class="list-group-item bg-transparent d-flex justify-content-between gap-2">
              <a class="text-truncate text-decoration-none" href="<?= url('item') ?>&id=<?= (int) $s['id'] ?>">
                <?= e($s['title'] ?: excerpt($s['body'], 50)) ?>
              </a>
              <span class="text-secondary text-nowrap"><?= e($s['channel']) ?><?= $s['platform'] ? ' · ' . e($s['platform']) : '' ?></span>
            </li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>
  </div>
</div>

<script>
  window.__ITEM__ = <?= json_encode([
      'id'        => (int) $item['id'],
      'channel'   => $item['channel'],
      'platform'  => $item['platform'],
      'limit'     => $limit,
      'isEmail'   => $isEmail,
      'title'     => (string) $item['title'],
      'excerpt'   => (string) $item['excerpt'],
      'hashtags'  => (string) $item['hashtags'],
      'body'      => (string) $item['body'],
      'headline'  => (string) ($meta['headline'] ?? ''),
      'cta'       => (string) ($meta['cta'] ?? ''),
      'preheader' => (string) ($meta['preheader'] ?? ''),
  ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
</script>

<?php View::share('extraScripts', ['content-edit.js']); ?>
