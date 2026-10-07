<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';

// Resolve relative path prefix (so it works under both / and /osint-framework/)
$SCRIPT_DIR = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
$BASE = '';
// Walk up to find the project root containing index.php
$dir = dirname($_SERVER['SCRIPT_FILENAME']);
for ($i = 0; $i < 4; $i++) {
    if (file_exists($dir . '/index.php') && file_exists($dir . '/config.php')) {
        $BASE = str_replace('\\', '/', str_replace($_SERVER['DOCUMENT_ROOT'], '', $dir));
        break;
    }
    $dir = dirname($dir);
}
if (!$BASE) $BASE = '';
$BASE = rtrim($BASE, '/');

$THEME = current_theme();
$PAGE_TITLE = $PAGE_TITLE ?? SITE_NAME;
$PAGE_DESC = $PAGE_DESC ?? 'Open Source Intelligence Framework - Find tools for your investigation';
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?= h($THEME) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= h($PAGE_TITLE) ?> · <?= h(SITE_NAME) ?></title>
    <meta name="description" content="<?= h($PAGE_DESC) ?>">

    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <!-- Font Awesome (extra icons) -->
    <link href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.5.1/css/all.min.css" rel="stylesheet">
    <!-- App CSS -->
    <link rel="stylesheet" href="<?= $BASE ?>/assets/css/style.css">
    <style>
        /* Chat Widget Styles */
        .chat-card .card-header {
            cursor: pointer;
            user-select: none;
        }
        .chat-messages {
            height: 350px;
            overflow-y: auto;
            padding: 10px;
            background: #f8f9fa;
        }
        .chat-msg {
            margin-bottom: 8px;
        }
        .chat-msg.ai .chat-bubble {
            background: #e9ecef;
            color: #212529;
            border-radius: 12px 12px 12px 4px;
            padding: 8px 12px;
            max-width: 90%;
            font-size: 0.85rem;
            word-wrap: break-word;
        }
        .chat-msg.user .chat-bubble {
            background: #0d6efd;
            color: #fff;
            border-radius: 12px 12px 4px 12px;
            padding: 8px 12px;
            max-width: 90%;
            font-size: 0.85rem;
            margin-left: auto;
            word-wrap: break-word;
        }
        .chat-input-area {
            display: flex;
            gap: 6px;
            padding: 10px;
            border-top: 1px solid #dee2e6;
            background: #fff;
        }
        .chat-input-area input {
            flex: 1;
        }
        #chat-body.collapsed {
            display: none;
        }
        .chat-loading {
            color: #6c757d;
            font-style: italic;
            font-size: 0.85rem;
            padding: 8px 12px;
        }
        /* phpML Chat Styles */
        .phpml-badge {
            display: inline-block;
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            font-size: 0.65rem;
            padding: 1px 6px;
            border-radius: 10px;
            margin-left: 4px;
            vertical-align: middle;
        }
        .phpml-quick-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 4px;
            padding: 6px 10px;
            border-top: 1px solid #dee2e6;
            background: #f0f0f0;
        }
        .phpml-quick-actions .btn {
            font-size: 0.7rem;
            padding: 3px 8px;
        }
        .chat-tab-content { display: block; }
        #chat-tab-phpml-content { display: none; }
        .chat-card .card-header .btn-group .btn {
            font-size: 0.7rem;
            padding: 4px 8px;
        }
        .chat-card .card-header .btn-group .btn.btn-primary {
            background: var(--accent);
            color: #fff;
            border-color: var(--accent);
        }
        .chat-msg .chat-bubble strong {
            color: var(--accent);
            display: block;
            margin-bottom: 4px;
        }
        /* phpML Skills Panel */
        .phpml-skills-panel {
            display: none;
            border-top: 2px solid var(--accent);
            background: var(--bg-tertiary);
            padding: 10px;
            max-height: 300px;
            overflow-y: auto;
            border-radius: 0 0 8px 8px;
            margin: 4px 0;
        }
        .phpml-skills-panel.visible { display: block; }
        .phpml-skills-panel-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 8px;
            padding-bottom: 6px;
            border-bottom: 1px solid var(--border-color);
            font-size: 0.8rem;
        }
        .phpml-skills-panel-content {
            font-size: 0.75rem;
            color: var(--text-secondary);
            line-height: 1.5;
        }
        .phpml-skills-panel-content pre {
            background: var(--code-bg);
            padding: 8px;
            border-radius: 4px;
            font-size: 0.7rem;
            overflow-x: auto;
            max-height: 250px;
            overflow-y: auto;
        }
        .phpml-skills-panel-btn {
            font-size: 0.7rem;
            padding: 3px 8px;
            margin-top: 4px;
            border-radius: 4px;
        }
        /* ===== Responsive phpML chat ===== */
        @media (max-width: 768px) {
            .chat-card .card-header .btn-group { gap: 2px; }
            .chat-card .card-header .btn-group .btn { font-size: 0.6rem; padding: 3px 6px; }
            .phpml-quick-actions { flex-wrap: wrap; }
        }
    </style>
</head>
<body>
<a href="#main-content" class="skip-link">Skip to main content</a>
<nav class="navbar navbar-expand-lg sticky-top" role="navigation" aria-label="Main navigation">
    <div class="container-fluid">
        <a class="navbar-brand" href="<?= $BASE ?>/index.php" aria-label="<?= h(SITE_NAME) ?> home">
            <i class="bi bi-bug-fill" aria-hidden="true"></i>
            <span><?= h(SITE_NAME) ?></span>
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#nav-main" aria-controls="nav-main" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="nav-main">
            <ul class="navbar-nav me-auto">
                <li class="nav-item">
                    <form class="d-flex search-form" role="search" method="get" action="<?= $BASE ?>/index.php" aria-label="Search tools">
                        <input type="hidden" name="view" value="grid">
                        <input class="form-control" type="search" name="q" id="global-search"
                               placeholder="Search 300+ OSINT tools..." aria-label="Search tools"
                               value="<?= h($_GET['q'] ?? '') ?>">
                        <button class="btn btn-search" type="submit" aria-label="Search"><i class="fas fa-search" aria-hidden="true"></i></button>
                    </form>
                </li>
            </ul>
            <ul class="navbar-nav align-items-center gap-2">
                <li class="nav-item">
                    <a class="nav-link" href="<?= $BASE ?>/trace/index.php" title="URL Tracer">
                        <i class="fas fa-crosshairs"></i> Trace URL
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="<?= $BASE ?>/godseye.php" title="Godseye Intelligence Dashboard">
                        <i class="fas fa-globe"></i> Godseye
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="<?= $BASE ?>/sccrm/index.php" title="SCIT CRM - Social Communication IT">
                        <i class="fas fa-briefcase"></i> SCCRM
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="<?= $BASE ?>/sccrm/ceo/" title="CEO Office - daily operations">
                        <i class="fas fa-crown"></i> CEO
                    </a>
                </li>
                <li class="nav-item">
                    <button class="btn btn-sm btn-theme-toggle" id="theme-toggle" title="Toggle theme" aria-label="Toggle light/dark theme">
                        <i class="bi <?= $THEME === 'dark' ? 'bi-sun-fill' : 'bi-moon-stars-fill' ?>" aria-hidden="true"></i>
                    </button>
                </li>
                <?php if (is_logged_in()): ?>
                    <li class="nav-item">
                        <a class="nav-link" href="<?= $BASE ?>/auth/favorites.php">
                            <i class="fas fa-bookmark"></i> Favorites
                        </a>
                    </li>
                    <?php if (is_admin()): ?>
                        <li class="nav-item">
                            <a class="nav-link nav-admin" href="<?= $BASE ?>/admin/dashboard.php">
                                <i class="fas fa-cog"></i> Admin
                            </a>
                        </li>
                    <?php endif; ?>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" data-bs-toggle="dropdown">
                            <i class="fas fa-user"></i> <?= h($_SESSION['username'] ?? 'User') ?>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li><a class="dropdown-item" href="<?= $BASE ?>/auth/favorites.php"><i class="fas fa-bookmark"></i> My Favorites</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item" href="<?= $BASE ?>/auth/logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
                        </ul>
                    </li>
                <?php else: ?>
                    <li class="nav-item">
                        <a class="btn btn-outline-light btn-sm" href="<?= $BASE ?>/auth/register.php">Register</a>
                    </li>
                    <li class="nav-item">
                        <a class="btn btn-primary btn-sm" href="<?= $BASE ?>/auth/login.php">Login</a>
                    </li>
                <?php endif; ?>
            </ul>
        </div>
    </div>
</nav>
<main class="container-fluid py-4">
