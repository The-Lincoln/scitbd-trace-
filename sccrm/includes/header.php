<?php
// Output buffering safety net: allows header('Location: ...') redirects
// from any page even if a template already echoed content.
if (!ob_get_level()) { ob_start(); }
if (session_status() === PHP_SESSION_NONE) { session_start(); }
// require_once returns the PDO only on FIRST load (true afterwards),
// so never overwrite an already-live $db connection.
$_sccrm_db_boot = require_once __DIR__ . '/../config/database.php';
if (($_sccrm_db_boot instanceof PDO) && (!isset($db) || !($db instanceof PDO))) { $db = $_sccrm_db_boot; }

// --- Dynamic base-path resolution (works at /sccrm AND /<sub>/sccrm AND /osint/.../sccrm) ---
if (!isset($SCCRM_BASE) || $SCCRM_BASE === '') {
    $SCCRM_BASE = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
    // Walk up from current script dir until we find the sccrm root (contains index.php + config/database.php)
    $dir = dirname($_SERVER['SCRIPT_FILENAME']);
    for ($i = 0; $i < 4; $i++) {
        if (file_exists($dir . '/index.php') && file_exists($dir . '/config/database.php')) {
            $docRoot = rtrim(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
            if ($docRoot !== '' && strpos(str_replace('\\', '/', $dir), $docRoot) === 0) {
                $SCCRM_BASE = substr(str_replace('\\', '/', $dir), strlen($docRoot));
            } else {
                // Fallback: derive from SCRIPT_NAME by cutting at /sccrm
                $sn = str_replace('\\', '/', $_SERVER['SCRIPT_NAME']);
                $pos = strpos($sn, '/sccrm');
                $SCCRM_BASE = ($pos !== false) ? substr($sn, 0, strlen('/sccrm') + $pos) : dirname($sn);
                if (substr($SCCRM_BASE, -6) !== '/sccrm') $SCCRM_BASE .= '/sccrm';
            }
            break;
        }
        $dir = dirname($dir);
    }
}
$SCCRM_BASE = rtrim($SCCRM_BASE, '/');
if ($SCCRM_BASE === '') $SCCRM_BASE = '/sccrm';
// OSINT project root = parent of sccrm dir
$OSINT_BASE = rtrim(dirname($SCCRM_BASE), '/');
if ($OSINT_BASE === '' || $OSINT_BASE === '\\') $OSINT_BASE = '';

$current_page = basename($_SERVER['PHP_SELF']);
$script_dir_path = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME']));
$current_dir = basename($script_dir_path);
// Dashboard lives at <base>/index.php -> current_dir is 'sccrm'
$is_dashboard = ($current_page === 'index.php' && ($current_dir === 'sccrm' || $script_dir_path === $SCCRM_BASE));
function isActive($dir, $file = null) {
    global $current_dir, $current_page, $is_dashboard, $script_dir_path, $SCCRM_BASE;
    if ($dir === 'dashboard') return !empty($is_dashboard) ? 'active' : '';
    if ($file) return ($current_dir === $dir && $current_page === $file) ? 'active' : '';
    return ($current_dir === $dir) ? 'active' : '';
}
function getInitials($name) {
    $parts = explode(' ', $name);
    $initials = '';
    foreach ($parts as $p) { if (strlen(trim($p)) > 0) $initials .= strtoupper($p[0]); }
    return substr($initials, 0, 2);
}
function avatarColor($id) {
    $colors = ['#3498db','#2ecc71','#e74c3c','#f39c12','#9b59b6','#1abc9c','#e67e22','#34495e'];
    return $colors[$id % count($colors)];
}
function timeAgo($dateStr) {
    if (!$dateStr) return '-';
    $now = new DateTime();
    $d = new DateTime($dateStr);
    $diff = $now->getTimestamp() - $d->getTimestamp();
    if ($diff < 60) return 'just now';
    if ($diff < 3600) return floor($diff/60) . 'm ago';
    if ($diff < 86400) return floor($diff/3600) . 'h ago';
    if ($diff < 2592000) return floor($diff/86400) . 'd ago';
    return $d->format('M j');
}
function formatDate($dateStr) {
    if (!$dateStr) return '-';
    $d = new DateTime($dateStr);
    return $d->format('M j, Y');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SCIT CRM - Social Communication IT</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= htmlspecialchars($SCCRM_BASE) ?>/css/style.css">
</head>
<body>
    <div class="overlay" id="sidebarOverlay"></div>
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-brand d-flex align-items-center gap-3">
            <div class="avatar-circle avatar-colors" style="width:40px;height:40px;font-size:16px;">SC</div>
            <div>
                <h4>SCIT CRM</h4>
                <small>Social Communication IT</small>
            </div>
        </div>
        <nav class="sidebar-nav">
            <div class="nav-section">Main</div>
            <a href="<?= htmlspecialchars($SCCRM_BASE) ?>/index.php" class="nav-item <?= isActive('dashboard') ?>">
                <i class="fas fa-th-large"></i> Dashboard
            </a>
            <a href="<?= htmlspecialchars($SCCRM_BASE) ?>/ceo/" class="nav-item <?= isActive('ceo') ?>">
                <i class="fas fa-crown"></i> CEO Office
            </a>
            <div class="nav-section">CRM</div>
            <a href="<?= htmlspecialchars($SCCRM_BASE) ?>/contacts/" class="nav-item <?= isActive('contacts') ?>">
                <i class="fas fa-users"></i> Contacts
            </a>
            <a href="<?= htmlspecialchars($SCCRM_BASE) ?>/companies/" class="nav-item <?= isActive('companies') ?>">
                <i class="fas fa-building"></i> Companies
            </a>
            <a href="<?= htmlspecialchars($SCCRM_BASE) ?>/interactions/" class="nav-item <?= isActive('interactions') ?>">
                <i class="fas fa-comments"></i> Interactions
            </a>
            <a href="<?= htmlspecialchars($SCCRM_BASE) ?>/tasks/" class="nav-item <?= isActive('tasks') ?>">
                <i class="fas fa-tasks"></i> Tasks
            </a>
            <div class="nav-section">Lead Generation</div>
            <a href="<?= htmlspecialchars($SCCRM_BASE) ?>/leads/" class="nav-item <?= isActive('leads') ?>">
                <i class="fas fa-flag-checkered"></i> Leads Pipeline
            </a>
            <a href="<?= htmlspecialchars($SCCRM_BASE) ?>/leads/generate.php" class="nav-item <?= (basename($_SERVER['PHP_SELF']) === 'generate.php' && $current_dir === 'leads') ? 'active' : '' ?>">
                <i class="fas fa-bolt"></i> Generate Leads
            </a>
            <div class="nav-section">Service & Support</div>
            <a href="<?= htmlspecialchars($SCCRM_BASE) ?>/knowledge/" class="nav-item <?= isActive('knowledge') ?>">
                <i class="fas fa-book"></i> Knowledge Bank
            </a>
            <a href="<?= htmlspecialchars($SCCRM_BASE) ?>/faq/" class="nav-item <?= isActive('faq') ?>">
                <i class="fas fa-question-circle"></i> FAQ
            </a>
            <a href="<?= htmlspecialchars($SCCRM_BASE) ?>/chat/" class="nav-item <?= isActive('chat') ?>">
                <i class="fas fa-headset"></i> Customer Chat
            </a>
            <div class="nav-section">Configuration</div>
            <a href="<?= htmlspecialchars($SCCRM_BASE) ?>/markets/" class="nav-item <?= isActive('markets') ?>">
                <i class="fas fa-globe"></i> Target Markets
            </a>
            <a href="<?= htmlspecialchars($SCCRM_BASE) ?>/services/" class="nav-item <?= isActive('services') ?>">
                <i class="fas fa-cogs"></i> Products & Services
            </a>
            <div class="nav-section">OSINT Tools</div>
            <a href="<?= htmlspecialchars($SCCRM_BASE) ?>/osint/" class="nav-item <?= isActive('osint') ?>">
                <i class="fas fa-book-open"></i> OSINT Library
            </a>
            <a href="<?= htmlspecialchars($SCCRM_BASE) ?>/security/" class="nav-item <?= isActive('security') ?>">
                <i class="fas fa-shield-halved"></i> ASVS Security
            </a>
            <a href="<?= htmlspecialchars($SCCRM_BASE) ?>/security/enum.php" class="nav-item <?= (isActive('security') && ($current_page === 'enum.php')) ? 'active' : '' ?>">
                <i class="fas fa-radar"></i> Subdomain Enum
            </a>
            <a href="<?= htmlspecialchars($SCCRM_BASE) ?>/trace/" class="nav-item <?= isActive('trace') ?>">
                <i class="fas fa-crosshairs"></i> URL Trace
            </a>
            <div class="nav-section">Media</div>
            <a href="<?= htmlspecialchars($SCCRM_BASE) ?>/video/" class="nav-item <?= isActive('video') ?>">
                <i class="fas fa-clapperboard"></i> Video Productions
            </a>
            <a href="<?= htmlspecialchars($SCCRM_BASE) ?>/dashboards/tooljet.php" class="nav-item <?= (isActive('dashboards') || ($current_page === 'tooljet.php')) ? 'active' : '' ?>">
                <i class="fas fa-th-large"></i> Ops Dashboard
            </a>
            <div class="nav-section">Automation</div>
            <a href="<?= htmlspecialchars($SCCRM_BASE) ?>/autoflows/" class="nav-item <?= isActive('autoflows') ?>">
                <i class="fas fa-wand-magic-sparkles"></i> AutoFlows
            </a>
            <a href="<?= htmlspecialchars($SCCRM_BASE) ?>/ai/" class="nav-item <?= isActive('ai') ?>">
                <i class="fas fa-robot"></i> AI Chat
            </a>
            <a href="<?= htmlspecialchars($SCCRM_BASE) ?>/ai/skills.php" class="nav-item <?= (isActive('ai') && ($current_page === 'skills.php')) ? 'active' : '' ?>">
                <i class="fas fa-shield-virus"></i> Agentic Skills
            </a>
            <a href="<?= htmlspecialchars($SCCRM_BASE) ?>/ai/library.php" class="nav-item <?= (isActive('ai') && ($current_page === 'library.php')) ? 'active' : '' ?>">
                <i class="fas fa-layer-group"></i> Skills Library
            </a>
            <a href="<?= htmlspecialchars($SCCRM_BASE) ?>/autoflows/push_trace.php" class="nav-item <?= (isActive('autoflows') && ($current_page === 'push_trace.php')) ? 'active' : '' ?>">
                <i class="fas fa-share-nodes"></i> Trace → Lead
            </a>
            <a href="<?= htmlspecialchars($OSINT_BASE) ?>/trace/index.php" class="nav-item" target="_blank" rel="noopener" title="Open full Trace app">
                <i class="fas fa-external-link-alt"></i> Trace App
            </a>
            <a href="<?= htmlspecialchars($OSINT_BASE) ?>/index.php" class="nav-item" title="Back to OSINT Framework">
                <i class="fas fa-bug"></i> OSINT Home
            </a>
            <a href="<?= htmlspecialchars($OSINT_BASE) ?>/godseye.php" class="nav-item" title="Godseye Intelligence Dashboard">
                <i class="fas fa-satellite"></i> Godseye
            </a>
            <div class="nav-section">Tools</div>
            <a href="<?= htmlspecialchars($SCCRM_BASE) ?>/search.php" class="nav-item <?= ($current_page === 'search.php') ? 'active' : '' ?>">
                <i class="fas fa-search"></i> Search
            </a>
        </nav>
    </aside>
    <div class="main-wrapper">
        <header class="topbar">
            <div class="topbar-left">
                <button class="sidebar-toggle" id="sidebarToggle"><i class="fas fa-bars"></i></button>
                <h5>
                    <?php
                    $title_map = [
                        'index.php' => 'Dashboard',
                        'ceo' => 'CEO Office',
                        'contacts' => 'Contacts',
                        'companies' => 'Companies',
                        'interactions' => 'Interactions',
                        'tasks' => 'Tasks',
                        'leads' => 'Leads Pipeline',
                        'markets' => 'Target Markets',
                        'services' => 'Products & Services',
                        'knowledge' => 'Knowledge Bank',
                        'faq' => 'FAQ',
                        'chat' => 'Customer Support',
                        'trace' => 'URL Trace',
                        'osint' => 'OSINT Library',
                        'security' => 'ASVS Security',
                        'autoflows' => 'AutoFlows',
                        'ai' => 'AI Chat',
                        'video' => 'Video Productions',
                        'dashboards' => 'Ops Dashboard',
                        'search.php' => 'Search'
                    ];
                    $page_title = 'SCIT CRM';
                    if ($current_dir === 'leads' && $current_page === 'generate.php') $page_title = 'Generate Leads';
                    elseif ($current_dir === 'ai' && $current_page === 'library.php') $page_title = 'Skills Library';
                    elseif ($current_dir === 'ai' && $current_page === 'skills.php') $page_title = 'Agentic Skills';
                    elseif ($current_dir === 'leads' && $current_page === 'logs.php') $page_title = 'Generation Logs';
                    elseif ($current_dir === 'faq' && $current_page === 'admin.php') $page_title = 'Manage FAQ';
                    elseif (!empty($is_dashboard)) $page_title = 'Dashboard';
                    elseif (isset($title_map[$current_dir])) $page_title = $title_map[$current_dir];
                    elseif (isset($title_map[$current_page])) $page_title = $title_map[$current_page];
                    echo htmlspecialchars($page_title);
                    ?>
                </h5>
            </div>
            <div class="topbar-right">
                <div class="search-box">
                    <i class="fas fa-search"></i>
                    <input type="text" placeholder="Search contacts, companies..." id="globalSearch" data-search-base="<?= htmlspecialchars($SCCRM_BASE) ?>" value="<?= htmlspecialchars($_GET['q'] ?? '') ?>">
                </div>
                <a href="<?= htmlspecialchars($OSINT_BASE) ?>/index.php" class="btn btn-outline-secondary btn-sm rounded-pill px-3" title="OSINT Framework home">
                    <i class="fas fa-bug me-1"></i> OSINT
                </a>
                <a href="<?= htmlspecialchars($SCCRM_BASE) ?>/ceo/" class="btn btn-outline-warning btn-sm rounded-pill px-3" title="CEO Office">
                    <i class="fas fa-crown me-1"></i> CEO
                </a>
                <a href="<?= htmlspecialchars($SCCRM_BASE) ?>/trace/" class="btn btn-outline-info btn-sm rounded-pill px-3" title="Trace any URL">
                    <i class="fas fa-crosshairs me-1"></i> Trace
                </a>
                <a href="<?= htmlspecialchars($SCCRM_BASE) ?>/trace/agent_browser.php" class="btn btn-outline-success btn-sm rounded-pill px-3" title="Rendered browser trace (Chromium via agent-browser)">
                    <i class="fas fa-globe me-1"></i> Browser
                </a>
                <a href="<?= htmlspecialchars($SCCRM_BASE) ?>/autoflows/" class="btn btn-outline-warning btn-sm rounded-pill px-3" title="Automation flows">
                    <i class="fas fa-wand-magic-sparkles me-1"></i> AutoFlows
                </a>
                <a href="<?= htmlspecialchars($SCCRM_BASE) ?>/contacts/create.php" class="btn btn-primary btn-sm rounded-pill px-3">
                    <i class="fas fa-plus me-1"></i> Add Contact
                </a>
            </div>
        </header>
        <div class="content-area">
<?php if (isset($_SESSION['flash'])): ?>
    <div class="alert alert-<?= $_SESSION['flash']['type'] ?> alert-dismissible fade show">
        <?= $_SESSION['flash']['message'] ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php unset($_SESSION['flash']); ?>
<?php endif; ?>
