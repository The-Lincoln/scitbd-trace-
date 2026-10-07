<?php
/**
 * Sign in — Google (Gmail) + Facebook OAuth, with offline dev fallback.
 */
$configured = (array) ($configured ?? []);
$redirects = (array) ($redirects ?? []);
$allowDev = (bool) ($allowDev ?? true);
?>
<div class="row justify-content-center mt-4">
  <div class="col-md-7 col-lg-5">
    <div class="text-center mb-3">
      <span class="brand-badge" style="width:48px;height:48px;font-size:1.4rem"><i class="bi bi-water"></i></span>
      <h1 class="h4 mt-2 mb-1">Welcome to AutoFlows</h1>
      <p class="text-secondary small mb-0">Sign in to connect Gmail &amp; Facebook so your autoflows can publish for real.</p>
    </div>

    <div class="card">
      <div class="card-body d-grid gap-2 p-4">
        <?php if (!empty($configured['google'])): ?>
          <a class="btn btn-light d-flex align-items-center justify-content-center gap-2 py-2" href="<?= url('auth/google') ?>">
            <i class="bi bi-google"></i> Continue with Google <span class="badge text-bg-dark ms-1">Gmail</span>
          </a>
        <?php else: ?>
          <button class="btn btn-outline-secondary d-flex align-items-center justify-content-center gap-2 py-2" disabled title="Add GOOGLE_CLIENT_ID / secret in Settings first">
            <i class="bi bi-google"></i> Continue with Google <span class="badge text-bg-warning ms-1">needs keys</span>
          </button>
        <?php endif; ?>

        <?php if (!empty($configured['facebook'])): ?>
          <a class="btn btn-primary d-flex align-items-center justify-content-center gap-2 py-2" style="background:#1877F2;border-color:#1877F2" href="<?= url('auth/facebook') ?>">
            <i class="bi bi-facebook"></i> Continue with Facebook
          </a>
        <?php else: ?>
          <button class="btn btn-outline-secondary d-flex align-items-center justify-content-center gap-2 py-2" disabled title="Add Facebook App ID / secret in Settings first">
            <i class="bi bi-facebook"></i> Continue with Facebook <span class="badge text-bg-warning ms-1">needs keys</span>
          </button>
        <?php endif; ?>

        <?php if ($allowDev): ?>
          <div class="d-flex align-items-center gap-2 my-1">
            <hr class="flex-grow-1"><span class="text-secondary small">or try offline</span><hr class="flex-grow-1">
          </div>
          <div class="row g-2">
            <div class="col-6">
              <a class="btn btn-outline-light w-100 btn-sm py-2" href="<?= url('auth/dev') ?>&via=google">
                <i class="bi bi-envelope me-1"></i>Gmail dev
              </a>
            </div>
            <div class="col-6">
              <a class="btn btn-outline-light w-100 btn-sm py-2" href="<?= url('auth/dev') ?>&via=facebook">
                <i class="bi bi-facebook me-1"></i>FB dev
              </a>
            </div>
          </div>
          <p class="text-secondary small mb-0 text-center">Dev login plants a <em>simulated</em> channel — publishing is recorded without API calls.</p>
        <?php endif; ?>

        <hr class="my-1">
        <a class="btn btn-link btn-sm text-secondary text-decoration-none" href="<?= url('home') ?>">
          <i class="bi bi-arrow-left me-1"></i>Continue as guest
        </a>
      </div>
    </div>

    <?php if (empty($configured['google']) || empty($configured['facebook'])): ?>
      <div class="card mt-3">
        <div class="card-header py-2 small"><i class="bi bi-key me-1"></i>To enable live login, register these redirect URIs</div>
        <div class="card-body small">
          <dl class="row mb-0">
            <dt class="col-3 text-secondary">Google</dt>
            <dd class="col-9"><code class="text-break"><?= e($redirects['google'] ?? '') ?></code></dd>
            <dt class="col-3 text-secondary">Facebook</dt>
            <dd class="col-9"><code class="text-break"><?= e($redirects['facebook'] ?? '') ?></code></dd>
          </dl>
          <p class="mb-0 mt-2 text-secondary">Google Cloud → Credentials → OAuth client (Web) · Facebook Developers → App → Facebook Login → Valid OAuth Redirect URIs. Full steps in <code>OAUTH_SETUP.md</code>.</p>
        </div>
      </div>
    <?php endif; ?>
  </div>
</div>
