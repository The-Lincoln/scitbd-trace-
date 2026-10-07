<?php
/**
 * Main layout — Bootstrap 5 shell for AutoFlows.
 * Expects: $title, $content
 */
$currentPage = explode('/', (string) ($_GET['r'] ?? 'home'))[0];
$navItems = [
    'home'     => ['Dashboard', 'bi-grid-1x2'],
    'tasks'    => ['Tasks', 'bi-list-check'],
    'agent'    => ['FlowAgent', 'bi-robot'],
    'browser'  => ['Browser', 'bi-globe'],
    'flows'    => ['AutoFlows', 'bi-diagram-3'],
    'content'  => ['Content', 'bi-collection'],
    'chat'     => ['Chat', 'bi-chat-dots'],
    'settings' => ['Settings', 'bi-gear'],
];
$extraScripts = (array) View::pull('extraScripts', []);
$bodyClass    = (string) View::pull('bodyClass', '');
$isAgent      = $currentPage === 'agent';
$navUser = function_exists('current_user') ? current_user() : null;
?>
<!doctype html>
<html lang="en" data-bs-theme="dark">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title ?? 'AutoFlows') ?> · <?= e((string) config('app_short')) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="assets/css/app.css" rel="stylesheet">
<meta name="csrf" content="<?= e(csrf_token()) ?>">
<meta name="base" content="<?= e(url('')) ?>">
</head>
<body class="<?= e(trim($bodyClass . ($isAgent ? ' agent-body' : ''))) ?>">

<nav class="navbar navbar-expand-lg app-nav sticky-top border-bottom border-secondary">
  <div class="container-fluid px-3 px-lg-4">
    <a class="navbar-brand d-flex align-items-center gap-2" href="<?= url('home') ?>">
      <span class="brand-badge"><i class="bi bi-water"></i></span>
      <span class="fw-semibold">AutoFlows</span>
    </a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNav"
            aria-controls="mainNav" aria-expanded="false" aria-label="Toggle navigation">
      <span class="navbar-toggler-icon"></span>
    </button>
    <div class="collapse navbar-collapse" id="mainNav">
      <ul class="navbar-nav ms-auto align-items-lg-center gap-lg-1">
        <?php foreach ($navItems as $route => [$label, $icon]): ?>
          <?php $active = $currentPage === $route; ?>
          <li class="nav-item">
            <a class="nav-link <?= $active ? 'active' : '' ?>" href="<?= url($route) ?>">
              <i class="bi <?= $icon ?> me-1 d-none d-sm-inline"></i><?= e($label) ?>
            </a>
          </li>
        <?php endforeach; ?>
        <li class="nav-item ms-lg-2">
          <a class="btn btn-sm px-3" style="background:#4A154B;color:#fff;border:none;" href="https://app.slack.com/client/T0AGURY3K1D" target="_blank" rel="noopener" title="Open Slack workspace T0AGURY3K1D">
            <i class="bi bi-slack me-1"></i>Slack
          </a>
        </li>
        <li class="nav-item ms-lg-2">
          <a class="btn btn-accent btn-sm px-3" href="<?= url('agent') ?>">
            <i class="bi bi-robot me-1"></i>Run agent
          </a>
        </li>
        <?php if ($navUser): ?>
          <li class="nav-item dropdown ms-lg-1">
            <a class="nav-link dropdown-toggle d-flex align-items-center gap-2" href="#" data-bs-toggle="dropdown" aria-expanded="false">
              <?php if (!empty($navUser['avatar'])): ?>
                <img src="<?= e($navUser['avatar']) ?>" alt="" width="24" height="24" class="rounded-circle">
              <?php else: ?>
                <span class="rounded-circle bg-secondary d-inline-flex align-items-center justify-content-center" style="width:24px;height:24px">
                  <i class="bi bi-person-fill small"></i>
                </span>
              <?php endif; ?>
              <span class="d-none d-xl-inline small"><?= e(explode('@', (string) $navUser['email'])[0] ?: $navUser['name']) ?></span>
            </a>
            <ul class="dropdown-menu dropdown-menu-end">
              <li><h6 class="dropdown-header"><?= e($navUser['name']) ?><br><span class="text-secondary fw-normal"><?= e($navUser['email']) ?></span></h6></li>
              <li><a class="dropdown-item" href="<?= url('settings') ?>"><i class="bi bi-plug me-2"></i>Channels</a></li>
              <li><hr class="dropdown-divider"></li>
              <li><a class="dropdown-item" href="<?= url('logout') ?>"><i class="bi bi-box-arrow-right me-2"></i>Sign out</a></li>
            </ul>
          </li>
        <?php else: ?>
          <li class="nav-item dropdown ms-lg-1">
            <a class="btn btn-outline-light btn-sm dropdown-toggle" href="#" data-bs-toggle="dropdown" aria-expanded="false">
              <i class="bi bi-person-circle me-1"></i>Sign in
            </a>
            <ul class="dropdown-menu dropdown-menu-end">
              <li><a class="dropdown-item" href="<?= url('auth/google') ?>"><i class="bi bi-google me-2"></i>Gmail / Google</a></li>
              <li><a class="dropdown-item" href="<?= url('auth/facebook') ?>"><i class="bi bi-facebook me-2"></i>Facebook</a></li>
              <li><hr class="dropdown-divider"></li>
              <li><a class="dropdown-item" href="<?= url('login') ?>"><i class="bi bi-info-circle me-2"></i>All options…</a></li>
            </ul>
          </li>
        <?php endif; ?>
      </ul>
    </div>
  </div>
</nav>

<main class="container-fluid px-3 px-lg-4 py-4">
  <?php foreach (($flashes ?? []) as $f): ?>
    <div class="alert alert-<?= in_array($f['type'], ['success', 'info', 'warning', 'danger'], true) ? e($f['type']) : 'info' ?> alert-dismissible fade show" role="alert">
      <?= e($f['message']) ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
  <?php endforeach; ?>

  <?= $content ?? '' ?>
</main>

<footer class="app-footer border-top border-secondary mt-4">
  <div class="container-fluid px-3 px-lg-4 py-3 d-flex flex-wrap justify-content-between gap-2">
    <span class="text-secondary small">
      <?= e((string) config('app_name')) ?> v<?= e((string) config('app_version')) ?> — PHP <?= PHP_VERSION ?> · SQLite · Bootstrap 5
    </span>
    <span class="text-secondary small"><i class="bi bi-cpu me-1"></i>TinyLLM runs locally via Ollama — nothing leaves this machine.</span>
  </div>
</footer>

<div class="toast-container position-fixed bottom-0 end-0 p-3" id="toastHost"></div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="assets/js/app.js"></script>
<?php foreach ($extraScripts as $s): ?>
<script src="assets/js/<?= e($s) ?>"></script>
<?php endforeach; ?>
</body>
</html>
