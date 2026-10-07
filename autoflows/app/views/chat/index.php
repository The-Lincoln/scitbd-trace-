<?php
/**
 * TinyLLM chat — conversation rail, streaming thread, model + agent controls.
 */
$llm  = (array) $llm;
$defs = (array) $defaults;
?>
<div class="row g-3 chat-layout">

  <!-- ── conversations ─────────────────────────────────────────── -->
  <div class="col-lg-3 col-xl-2">
    <div class="card chat-rail">
      <div class="card-header d-flex justify-content-between align-items-center py-2">
        <span class="small"><i class="bi bi-chat-left-text me-1"></i>Threads</span>
        <button class="btn btn-accent btn-sm px-2" id="btnNewChat" title="New conversation">
          <i class="bi bi-plus-lg"></i>
        </button>
      </div>
      <div class="list-group list-group-flush chat-threads" id="threadList">
        <?php foreach ((array) $conversations as $c): ?>
          <button type="button"
                  class="list-group-item list-group-item-action bg-transparent chat-thread <?= (int) ($current['id'] ?? 0) === (int) $c['id'] ? 'active' : '' ?>"
                  data-id="<?= (int) $c['id'] ?>">
            <span class="d-block text-truncate title"><?= e($c['title']) ?></span>
            <span class="small opacity-75"><?= (int) $c['message_count'] ?> msgs · <?= e(time_ago($c['updated_at'])) ?></span>
          </button>
        <?php endforeach; ?>
        <?php if (empty($conversations)): ?>
          <div class="p-3 small text-secondary text-center">No threads yet — hit <strong>+</strong>.</div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- ── thread ────────────────────────────────────────────────── -->
  <div class="col-lg-6 col-xl-7">
    <div class="card chat-card d-flex flex-column">
      <div class="card-header d-flex justify-content-between align-items-center gap-2 py-2">
        <div class="d-flex align-items-center gap-2 min-w-0">
          <span class="badge text-bg-dark">TinyLLM</span>
          <strong class="text-truncate" id="threadTitle"><?= e($current['title'] ?? 'New chat') ?></strong>
        </div>
        <div class="d-flex gap-1">
          <span class="badge <?= $llm['ok'] ? 'text-bg-success' : 'text-bg-warning' ?>" id="llmBadge">
            <?= $llm['ok'] ? 'online' : 'offline · fallback' ?>
          </span>
          <button class="btn btn-sm btn-outline-secondary" id="btnExport" title="Export as Markdown">
            <i class="bi bi-download"></i>
          </button>
          <button class="btn btn-sm btn-outline-secondary" id="btnClear" title="Clear this thread">
            <i class="bi bi-eraser"></i>
          </button>
          <button class="btn btn-sm btn-outline-danger" id="btnDeleteChat" title="Delete this thread">
            <i class="bi bi-trash3"></i>
          </button>
        </div>
      </div>

      <div class="card-body chat-scroll flex-grow-1" id="chatScroll" data-conv="<?= (int) ($current['id'] ?? 0) ?>">
        <div id="chatMessages">
          <?php if (empty($messages)): ?>
            <div class="chat-empty text-center py-5">
              <div class="display-5 mb-3"><i class="bi bi-robot text-accent"></i></div>
              <h2 class="h5">AutoFlows assistant</h2>
              <p class="text-secondary mx-auto" style="max-width:46ch">
                Ask for copy, or fire a command that actually generates artifacts.
                Everything lands in the <a href="<?= url('content') ?>">Content</a> library.
              </p>
              <div class="d-flex flex-wrap gap-2 justify-content-center mt-3">
                <?php foreach ([
                    '/social why small teams should publish weekly',
                    '/blog building a repeatable content loop',
                    '/email re-engaging dormant trial users',
                    '/daily content marketing without a big team',
                    '/help',
                ] as $chip): ?>
                  <button class="btn btn-outline-light btn-sm chip-prompt"><?= e($chip) ?></button>
                <?php endforeach; ?>
              </div>
            </div>
          <?php else: ?>
            <?php foreach ($messages as $m): $meta = json_decode((string) $m['meta'], true) ?: []; ?>
              <div class="msg msg-<?= e($m['role']) ?>" data-id="<?= (int) $m['id'] ?>">
                <div class="msg-avatar">
                  <i class="bi <?= $m['role'] === 'user' ? 'bi-person-fill' : 'bi-robot' ?>"></i>
                </div>
                <div class="msg-body">
                  <div class="msg-meta small text-secondary">
                    <span><?= $m['role'] === 'user' ? 'You' : 'AutoFlows' ?></span>
                    <span>·</span>
                    <span><?= e($m['created_at']) ?></span>
                    <?php if (!empty($m['model'])): ?>
                      <span class="badge text-bg-dark ms-1"><?= e($m['model']) ?></span>
                    <?php endif; ?>
                    <?php if (!empty($meta['command'])): ?>
                      <span class="badge text-bg-accent ms-1">/<?= e($meta['command']) ?></span>
                    <?php endif; ?>
                  </div>
                  <div class="msg-text md"><?= e($m['content']) ?></div>
                  <div class="msg-actions">
                    <button class="btn btn-xs btn-ghost act-copy"><i class="bi bi-clipboard"></i> Copy</button>
                    <?php if (!empty($meta['run_id'])): ?>
                      <a class="btn btn-xs btn-ghost" href="<?= url('agent') ?>&run=<?= (int) $meta['run_id'] ?>">
                        <i class="bi bi-clock-history"></i> Trace
                      </a>
                    <?php endif; ?>
                    <button class="btn btn-xs btn-ghost act-delete text-danger"><i class="bi bi-trash3"></i></button>
                  </div>
                </div>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>

      <div class="card-footer bg-transparent py-2">
        <form id="chatForm" class="d-flex gap-2 align-items-end">
          <div class="flex-grow-1">
            <textarea id="chatInput" class="form-control chat-input" rows="1"
                      placeholder="Message TinyLLM… or /social, /blog, /email, /daily (Enter to send)"
                      maxlength="8000"></textarea>
            <div class="d-flex justify-content-between small text-secondary mt-1 px-1">
              <span id="chatHint">Streaming · local only</span>
              <span id="chatCount">0</span>
            </div>
          </div>
          <button class="btn btn-accent px-3" id="chatSend" type="submit">
            <i class="bi bi-send-fill"></i>
          </button>
        </form>
      </div>
    </div>
  </div>

  <!-- ── controls ──────────────────────────────────────────────── -->
  <div class="col-lg-3 col-xl-3">
    <div class="card mb-3">
      <div class="card-header py-2 small"><i class="bi bi-rocket-takeoff me-1"></i>Agent shortcuts</div>
      <div class="card-body small d-grid gap-2">
        <button class="btn btn-sm btn-outline-light cmd-btn" data-cmd="/daily ">
          <i class="bi bi-lightning-charge me-1"></i>Daily pack — social + blog + email
        </button>
        <button class="btn btn-sm btn-outline-light cmd-btn" data-cmd="/social ">
          <i class="bi bi-share me-1"></i>Social posts
        </button>
        <button class="btn btn-sm btn-outline-light cmd-btn" data-cmd="/blog ">
          <i class="bi bi-journal-richtext me-1"></i>Blog article
        </button>
        <button class="btn btn-sm btn-outline-light cmd-btn" data-cmd="/email ">
          <i class="bi bi-envelope-paper me-1"></i>Marketing email
        </button>
        <a class="btn btn-sm btn-accent" href="<?= url('agent') ?>">
          <i class="bi bi-robot me-1"></i>Open FlowAgent console
        </a>
        <div class="form-text">Commands run the real generators and post links back into this thread.</div>
      </div>
    </div>

    <div class="card mb-3">
      <div class="card-header py-2 small"><i class="bi bi-diagram-3 me-1"></i>Saved flows</div>
      <div class="list-group list-group-flush">
        <?php foreach (array_slice((array) $flows, 0, 5) as $f): ?>
          <div class="list-group-item bg-transparent px-3 py-2 d-flex justify-content-between align-items-center gap-2">
            <div class="min-w-0">
              <span class="d-block text-truncate"><?= e($f['name']) ?></span>
              <span class="small text-secondary"><?= e($f['channel']) ?> · <?= e($f['schedule']) ?></span>
            </div>
            <button class="btn btn-sm btn-outline-light run-flow" data-id="<?= (int) $f['id'] ?>" title="Run now">
              <i class="bi bi-play-fill"></i>
            </button>
          </div>
        <?php endforeach; ?>
        <?php if (empty($flows)): ?>
          <div class="list-group-item bg-transparent text-secondary small p-3">No flows yet.</div>
        <?php endif; ?>
      </div>
    </div>

    <div class="card mb-3">
      <div class="card-header py-2 small"><i class="bi bi-cpu me-1"></i>Model</div>
      <div class="card-body small">
        <div class="mb-2">
          <label class="form-label" for="optProvider">Provider</label>
          <select id="optProvider" class="form-select form-select-sm">
            <option value="ollama" <?= $llm['provider'] === 'ollama' ? 'selected' : '' ?>>Ollama (local HTTP)</option>
            <option value="local"  <?= $llm['provider'] === 'local'  ? 'selected' : '' ?>>Template engine</option>
          </select>
        </div>
        <div class="mb-2">
          <label class="form-label" for="optModel">Model</label>
          <select id="optModel" class="form-select form-select-sm">
            <?php foreach ((array) $models as $m): ?>
              <option value="<?= e($m['name']) ?>" <?= $m['name'] === (string) config('chat.providers.ollama.model') ? 'selected' : '' ?>>
                <?= e($m['name']) ?> — <?= e($m['desc']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="mb-2">
          <label class="form-label d-flex justify-content-between" for="optTemp">
            <span>Temperature</span><output id="optTempOut"><?= e((string) $defs['temperature']) ?></output>
          </label>
          <input type="range" class="form-range" id="optTemp" min="0" max="1.5" step="0.1"
                 value="<?= e((string) $defs['temperature']) ?>">
        </div>
        <div class="mb-2">
          <label class="form-label d-flex justify-content-between" for="optTokens">
            <span>Max tokens</span><output id="optTokensOut"><?= e((string) $defs['num_predict']) ?></output>
          </label>
          <input type="range" class="form-range" id="optTokens" min="64" max="2048" step="64"
                 value="<?= e((string) $defs['num_predict']) ?>">
        </div>
        <div>
          <label class="form-label" for="optSystem">System prompt</label>
          <textarea id="optSystem" class="form-control form-control-sm" rows="5"><?= e((string) $defs['system']) ?></textarea>
        </div>
        <button class="btn btn-outline-light btn-sm w-100 mt-2" id="btnProbe">
          <i class="bi bi-arrow-repeat me-1"></i>Re-check connection
        </button>
      </div>
    </div>

    <div class="card">
      <div class="card-header py-2 small"><i class="bi bi-collection me-1"></i>Latest output</div>
      <div class="list-group list-group-flush">
        <?php foreach ((array) $recentContent as $r): ?>
          <a href="<?= url('item') ?>&id=<?= (int) $r['id'] ?>" class="list-group-item list-group-item-action bg-transparent px-3 py-2">
            <span class="d-block text-truncate"><?= e($r['title'] ?: excerpt($r['body'], 44)) ?></span>
            <span class="small text-secondary"><?= e($r['channel']) ?> · <?= e($r['status']) ?> · <?= (int) $r['chars'] ?> chars</span>
          </a>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>

<?php View::share('extraScripts', ['chat.js']); ?>
