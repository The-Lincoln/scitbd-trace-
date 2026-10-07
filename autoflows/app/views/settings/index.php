<?php
/**
 * Settings — TinyLLM connection + brand voice inherited by every prompt.
 */
$values   = (array) $values;
$models   = (array) $models;
$llm      = (array) $llm;
$defaults = (array) $defaults;
$brandDef = (array) $brandDef;
$tables   = (array) $tables;
$connections = (array) ($connections ?? []);
$oauthConfigured = (array) ($oauthConfigured ?? ['google' => false, 'facebook' => false]);
$redirects = (array) ($redirects ?? []);
$navUserS = (array) ($user ?? []);
$gConn = $connections['google'] ?? ['connected' => false];
$fbConn = $connections['facebook'] ?? ['connected' => false];
$maskSecret = fn ($v) => $v !== '' ? '•••••• (saved — leave blank to keep)' : '';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
  <div>
    <h1 class="h4 mb-1">Settings</h1>
    <p class="text-secondary small mb-0">Stored in the <code>settings</code> table; file defaults act as the fallback.</p>
  </div>
  <div class="d-flex gap-2">
    <button class="btn btn-outline-light btn-sm" id="btnReset"><i class="bi bi-arrow-counterclockwise me-1"></i>Reset all</button>
    <button class="btn btn-accent btn-sm" id="btnSaveTop"><i class="bi bi-check2-all me-1"></i>Save settings</button>
  </div>
</div>

<form id="settingsForm">
<div class="row g-3">
  <!-- ── connection ─────────────────────────────────────────────── -->
  <div class="col-lg-6">
    <div class="card mb-3">
      <div class="card-header py-2 d-flex justify-content-between align-items-center">
        <span><i class="bi bi-cpu me-1"></i>TinyLLM connection</span>
        <span class="badge <?= $llm['ok'] ? 'text-bg-success' : 'text-bg-warning' ?>" id="setBadge">
          <?= $llm['ok'] ? 'reachable' : 'offline' ?>
        </span>
      </div>
      <div class="card-body">
        <div class="mb-3">
          <label class="form-label" for="sProvider">Provider</label>
          <select class="form-select" id="sProvider">
            <option value="ollama" <?= $values['chat_provider'] === 'ollama' ? 'selected' : '' ?>>Ollama (local HTTP)</option>
            <option value="local"  <?= $values['chat_provider'] === 'local'  ? 'selected' : '' ?>>Template engine — force offline</option>
          </select>
        </div>

        <div class="mb-3">
          <label class="form-label" for="sEndpoint">Ollama endpoint</label>
          <input class="form-control" id="sEndpoint" value="<?= e($values['chat_endpoint']) ?>"
                 placeholder="http://127.0.0.1:11434">
          <div class="form-text">Start it with <code>ollama serve</code>. If unreachable, AutoFlows silently uses the built-in template engine.</div>
        </div>

        <div class="mb-3">
          <label class="form-label" for="sModel">Model</label>
          <select class="form-select" id="sModel" data-selected="<?= e($values['chat_model']) ?>"></select>
        </div>

        <div class="row g-3 mb-3">
          <div class="col-sm-6">
            <label class="form-label d-flex justify-content-between" for="sTemp">
              <span>Temperature</span><output id="sTempOut"><?= e($values['chat_temperature']) ?></output>
            </label>
            <input type="range" class="form-range" id="sTemp" min="0" max="2" step="0.05"
                   value="<?= e($values['chat_temperature']) ?>">
          </div>
          <div class="col-sm-6">
            <label class="form-label d-flex justify-content-between" for="sTokens">
              <span>Max tokens</span><output id="sTokensOut"><?= e($values['chat_num_predict']) ?></output>
            </label>
            <input type="range" class="form-range" id="sTokens" min="64" max="4096" step="64"
                   value="<?= e($values['chat_num_predict']) ?>">
          </div>
        </div>

        <div class="mb-3">
          <label class="form-label" for="sSystem">System prompt</label>
          <textarea class="form-control" id="sSystem" rows="6"><?= e($values['chat_system']) ?></textarea>
        </div>

        <button class="btn btn-outline-light btn-sm" type="button" id="btnTest">
          <i class="bi bi-plug me-1"></i>Test connection
        </button>
        <span class="small text-secondary ms-2" id="testOut"></span>

        <hr>
        <dl class="row small mb-0">
          <dt class="col-5 text-secondary">Current status</dt>
          <dd class="col-7" id="setStatus">
            <?= $llm['ok']
                ? '<span class="text-success">reachable · ' . (int) $llm['latency_ms'] . ' ms</span>'
                : '<span class="text-warning">unreachable — ' . e((string) ($llm['error'] ?? 'no response')) . '</span>' ?>
          </dd>
          <dt class="col-5 text-secondary">Endpoint</dt>
          <dd class="col-7"><code><?= e($values['chat_endpoint']) ?></code></dd>
          <dt class="col-5 text-secondary">Default system</dt>
          <dd class="col-7 text-truncate"><?= e(excerpt((string) $defaults['system'], 70)) ?></dd>
        </dl>
      </div>
    </div>

    <div class="card">
      <div class="card-header py-2"><i class="bi bi-database me-1"></i>Database</div>
      <div class="card-body small">
        <dl class="row mb-0">
          <dt class="col-6 text-secondary">File</dt><dd class="col-6 text-break"><code><?= e(basename($dbPath)) ?></code></dd>
          <?php foreach ($tables as $t => $n): ?>
            <dt class="col-6 text-secondary"><?= e($t) ?></dt><dd class="col-6"><?= (int) $n ?> rows</dd>
          <?php endforeach; ?>
        </dl>
      </div>
    </div>
  </div>

  <!-- ── brand voice ────────────────────────────────────────────── -->
  <div class="col-lg-6">
    <div class="card">
      <div class="card-header py-2"><i class="bi bi-megaphone me-1"></i>Brand voice</div>
      <div class="card-body">
        <p class="text-secondary small">
          Injected into every generation prompt as a <code>## Brand</code> block —
          social posts, articles, emails and all six agent steps.
        </p>

        <div class="mb-3">
          <label class="form-label" for="brand_name">Brand name</label>
          <input class="form-control" id="brand_name" value="<?= e($values['brand_name']) ?>">
        </div>

        <div class="mb-3">
          <label class="form-label" for="brand_voice">Voice</label>
          <textarea class="form-control" id="brand_voice" rows="3"
            placeholder="friendly, practical, lightly witty; short sentences; no hype"><?= e($values['brand_voice']) ?></textarea>
          <div class="form-text">Adjectives and rules, not sentences about the company.</div>
        </div>

        <div class="mb-3">
          <label class="form-label" for="brand_audience">Audience</label>
          <input class="form-control" id="brand_audience" value="<?= e($values['brand_audience']) ?>">
        </div>

        <div class="row g-3 mb-3">
          <div class="col-sm-6">
            <label class="form-label" for="brand_products">Product / offer</label>
            <input class="form-control" id="brand_products" value="<?= e($values['brand_products']) ?>">
          </div>
          <div class="col-sm-6">
            <label class="form-label" for="brand_links">Primary link</label>
            <input class="form-control" id="brand_links" value="<?= e($values['brand_links']) ?>" placeholder="https://…">
          </div>
        </div>

        <div class="mb-3">
          <label class="form-label" for="brand_cta">Default call to action</label>
          <input class="form-control" id="brand_cta" value="<?= e($values['brand_cta']) ?>">
        </div>

        <div class="row g-3 mb-3">
          <div class="col-sm-6">
            <div class="form-check form-switch">
              <input class="form-check-input" type="checkbox" id="brand_emoji" value="1"
                     <?= (int) $values['brand_emoji'] ? 'checked' : '' ?>>
              <label class="form-check-label" for="brand_emoji">Allow emoji</label>
            </div>
          </div>
          <div class="col-sm-6">
            <div class="form-check form-switch">
              <input class="form-check-input" type="checkbox" id="brand_hashtags" value="1"
                     <?= (int) $values['brand_hashtags'] ? 'checked' : '' ?>>
              <label class="form-check-label" for="brand_hashtags">Use hashtags</label>
            </div>
          </div>
        </div>

        <div class="card bg-body-tertiary border-secondary">
          <div class="card-body py-2 small">
            <div class="text-uppercase text-secondary" style="letter-spacing:.08em">Prompt preview</div>
            <pre class="mb-0 small brand-preview mt-1" id="brandPreview"></pre>
          </div>
        </div>
      </div>
      <div class="card-footer bg-transparent d-flex gap-2">
        <button class="btn btn-accent" type="submit" id="btnSave"><i class="bi bi-check2 me-1"></i>Save settings</button>
        <span class="ms-auto small text-secondary align-self-center" id="saveOut"></span>
      </div>
    </div>
  </div>
</div>

<!-- ── channels: Gmail + Facebook OAuth ─────────────────────────────── -->
<div class="row g-3 mt-1">
  <div class="col-12">
    <div class="card">
      <div class="card-header py-2 d-flex justify-content-between align-items-center flex-wrap gap-2">
        <span><i class="bi bi-plug me-1"></i>Channels — Gmail &amp; Facebook</span>
        <?php if (!empty($navUserS)): ?>
          <span class="small text-secondary">Signed in as <strong><?= e($navUserS['name'] ?? '') ?></strong> · <?= e($navUserS['email'] ?? '') ?> · <a href="<?= url('logout') ?>">Sign out</a></span>
        <?php else: ?>
          <a class="btn btn-sm btn-outline-light" href="<?= url('login') ?>"><i class="bi bi-person-circle me-1"></i>Sign in to connect</a>
        <?php endif; ?>
      </div>
      <div class="card-body">
        <div class="row g-3">
          <!-- Google / Gmail -->
          <div class="col-lg-6">
            <div class="border rounded p-3 h-100">
              <div class="d-flex justify-content-between align-items-center mb-2">
                <h2 class="h6 mb-0"><i class="bi bi-google me-1"></i>Google <span class="badge text-bg-dark ms-1">Gmail send</span></h2>
                <?php if (!empty($gConn['connected'])): ?>
                  <span class="badge <?= !empty($gConn['expired']) ? 'text-bg-warning' : 'text-bg-success' ?>">
                    <?= !empty($gConn['simulated']) ? 'simulated' : (!empty($gConn['expired']) ? 'expired' : 'connected') ?>
                  </span>
                <?php else: ?>
                  <span class="badge text-bg-secondary">not connected</span>
                <?php endif; ?>
              </div>
              <?php if (!empty($gConn['connected'])): ?>
                <p class="small mb-1"><?= e($gConn['name'] ?? '') ?> <?= $gConn['email'] ? '· <span class="text-secondary">' . e($gConn['email']) . '</span>' : '' ?></p>
                <p class="small text-secondary mb-2">Email-channel items send through the Gmail API as this account.</p>
              <?php else: ?>
                <p class="small text-secondary mb-2">Connect to send marketing emails via the Gmail API. Scope: <code>gmail.send</code> + profile.</p>
              <?php endif; ?>
              <div class="mb-2">
                <label class="form-label small" for="oauth_google_client_id">Client ID</label>
                <input class="form-control form-control-sm" id="oauth_google_client_id" value="<?= e($values['oauth_google_client_id'] ?? '') ?>" placeholder="….apps.googleusercontent.com" autocomplete="off">
              </div>
              <div class="mb-2">
                <label class="form-label small" for="oauth_google_client_secret">Client secret</label>
                <input class="form-control form-control-sm" type="password" id="oauth_google_client_secret" value="" placeholder="<?= e($maskSecret($values['oauth_google_client_secret'] ?? '')) ?>" autocomplete="new-password">
              </div>
              <div class="mb-2">
                <label class="form-label small" for="publish_gmail_to">Default test recipient</label>
                <input class="form-control form-control-sm" id="publish_gmail_to" type="email" value="<?= e($values['publish_gmail_to'] ?? '') ?>" placeholder="you@example.com">
                <div class="form-text">Prefills the “send test” box on email items.</div>
              </div>
              <div class="d-flex gap-2 flex-wrap">
                <?php if (!empty($oauthConfigured['google'])): ?>
                  <a class="btn btn-sm <?= !empty($gConn['connected']) ? 'btn-outline-light' : 'btn-light' ?>" href="<?= url('auth/google') ?>&connect=1">
                    <i class="bi bi-google me-1"></i><?= !empty($gConn['connected']) ? 'Reconnect Gmail' : 'Connect Gmail' ?>
                  </a>
                <?php else: ?>
                  <button class="btn btn-sm btn-outline-secondary" disabled title="Save a Client ID + secret first">Connect Gmail</button>
                <?php endif; ?>
                <?php if (!empty($gConn['connected'])): ?>
                  <button class="btn btn-sm btn-outline-danger" data-disconnect="google"><i class="bi bi-x-circle me-1"></i>Disconnect</button>
                <?php endif; ?>
              </div>
              <div class="form-text mt-2">Redirect URI to register in Google Cloud:<br><code class="text-break"><?= e($redirects['google'] ?? '') ?></code></div>
            </div>
          </div>
          <!-- Facebook -->
          <div class="col-lg-6">
            <div class="border rounded p-3 h-100">
              <div class="d-flex justify-content-between align-items-center mb-2">
                <h2 class="h6 mb-0"><i class="bi bi-facebook me-1"></i>Facebook <span class="badge text-bg-dark ms-1">Page posts</span></h2>
                <?php if (!empty($fbConn['connected'])): ?>
                  <span class="badge <?= !empty($fbConn['expired']) ? 'text-bg-warning' : 'text-bg-success' ?>">
                    <?= !empty($fbConn['simulated']) ? 'simulated' : (!empty($fbConn['expired']) ? 'expired' : 'connected') ?>
                  </span>
                <?php else: ?>
                  <span class="badge text-bg-secondary">not connected</span>
                <?php endif; ?>
              </div>
              <?php if (!empty($fbConn['connected'])): ?>
                <p class="small mb-1"><?= e($fbConn['name'] ?? '') ?> <?= $fbConn['email'] ? '· <span class="text-secondary">' . e($fbConn['email']) . '</span>' : '' ?></p>
                <?php if (!empty($fbConn['page_name'])): ?>
                  <p class="small mb-2"><i class="bi bi-flag me-1"></i>Posting as page: <strong><?= e($fbConn['page_name']) ?></strong></p>
                <?php else: ?>
                  <p class="small text-secondary mb-2">No Page found — posts go to <code>/me/feed</code>. Add the account as a Page admin to post as a Page.</p>
                <?php endif; ?>
              <?php else: ?>
                <p class="small text-secondary mb-2">Connect to auto-post <code>social/facebook</code> items to your Page via the Graph API.</p>
              <?php endif; ?>
              <div class="mb-2">
                <label class="form-label small" for="oauth_facebook_app_id">App ID</label>
                <input class="form-control form-control-sm" id="oauth_facebook_app_id" value="<?= e($values['oauth_facebook_app_id'] ?? '') ?>" placeholder="1234567890" autocomplete="off">
              </div>
              <div class="mb-2">
                <label class="form-label small" for="oauth_facebook_app_secret">App secret</label>
                <input class="form-control form-control-sm" type="password" id="oauth_facebook_app_secret" value="" placeholder="<?= e($maskSecret($values['oauth_facebook_app_secret'] ?? '')) ?>" autocomplete="new-password">
              </div>
              <div class="d-flex gap-2 flex-wrap">
                <?php if (!empty($oauthConfigured['facebook'])): ?>
                  <a class="btn btn-sm <?= !empty($fbConn['connected']) ? 'btn-outline-light' : 'btn-primary' ?>" style="<?= empty($fbConn['connected']) ? 'background:#1877F2;border-color:#1877F2' : '' ?>" href="<?= url('auth/facebook') ?>&connect=1">
                    <i class="bi bi-facebook me-1"></i><?= !empty($fbConn['connected']) ? 'Reconnect Facebook' : 'Connect Facebook' ?>
                  </a>
                <?php else: ?>
                  <button class="btn btn-sm btn-outline-secondary" disabled title="Save an App ID + secret first">Connect Facebook</button>
                <?php endif; ?>
                <?php if (!empty($fbConn['connected'])): ?>
                  <button class="btn btn-sm btn-outline-danger" data-disconnect="facebook"><i class="bi bi-x-circle me-1"></i>Disconnect</button>
                <?php endif; ?>
              </div>
              <div class="form-text mt-2">Redirect URI to register in Facebook Login settings:<br><code class="text-break"><?= e($redirects['facebook'] ?? '') ?></code></div>
            </div>
          </div>
        </div>
        <p class="small text-secondary mb-0 mt-3">
          <i class="bi bi-shield-lock me-1"></i>Tokens are obfuscated before storage and never echoed back.
          Without a connection, publishing is <em>simulated</em> and recorded on the item so autoflows still complete end-to-end.
          See <code>OAUTH_SETUP.md</code> for console steps.
        </p>
      </div>
    </div>
  </div>
</div>
</form>

<script>
  window.__MODELS__ = <?= json_encode($models, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
</script>
<?php View::share('extraScripts', ['settings.js']); ?>
