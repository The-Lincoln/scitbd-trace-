<?php
// Long trace does 1x page fetch + ipinfo + whois + SSL — needs >30s budget
@set_time_limit(120);
@ini_set('max_execution_time', '120');
@ini_set('default_socket_timeout', '10');
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/url_tracer.php';

use OSINT\URLTracer;

// Handle history clear BEFORE any output (headers must precede HTML)
if (isset($_GET['clear'])) {
    try {
        db()->exec("DELETE FROM url_traces");
    } catch (Throwable $e) { /* ignore */ }
    $clearBase = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
    header('Location: ' . $clearBase . '/url_trace.php');
    exit;
}

// $TRACE_BASE = this directory (…/trace), $ROOT_BASE = OSINT project root (parent of trace)
$TRACE_BASE = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
$ROOT_BASE = preg_replace('#/trace$#', '', $TRACE_BASE);
$BASE = $TRACE_BASE; // keep legacy var pointing at trace dir for in-page anchors
$traceResult = null;
$error = null;
$traces = [];

// Initialize tracer
$pdo = db();
$tracer = new URLTracer($pdo);
$traces = $tracer->getTraces();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $url = trim($_POST['url'] ?? '');
    if (!empty($url)) {
        try {
            $traceResult = $tracer->trace($url);
            // AutoFlows: mirror completed traces into SCCRM automations (interaction log, ...).
            if (empty($traceResult['error'])) {
                try {
                    $_sccrmDb = require_once __DIR__ . '/../sccrm/config/database.php';
                    require_once __DIR__ . '/../sccrm/autoflows/autoflow_engine.php';
                    if (($_sccrmDb instanceof PDO) && function_exists('autoflowTrigger')) {
                        $techs = [];
                        foreach ((array)($traceResult['technology']['all_technologies'] ?? []) as $t) {
                            $techs[] = is_array($t) ? ($t['technology'] ?? '') : (string)$t;
                        }
                        autoflowTrigger($_sccrmDb, 'trace_completed', [
                            'url' => $traceResult['basic']['final_url'] ?? $url,
                            'subject' => 'URL traced: ' . ($traceResult['content']['title'] ?? $url),
                            'seo_score' => (int)($traceResult['seo']['score'] ?? 0),
                            'security_score' => (int)($traceResult['security']['score'] ?? 0),
                            'tech_stack' => implode(', ', array_slice(array_values(array_filter(array_unique($techs))), 0, 8)),
                            'source' => 'trace_app',
                        ]);
                    }
                } catch (Throwable $e) { /* automation never breaks tracing */ }
            }
        } catch (\Throwable $e) {
            $error = 'Trace Error: ' . $e->getMessage();
        }
    } else {
        $error = 'Please enter a URL.';
    }
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>URL Tracer | OSINT Framework</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.5.1/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --bg-primary: #0a0e1a;
            --bg-secondary: #131826;
            --bg-tertiary: #1c2236;
            --bg-card: #131826;
            --bg-hover: #1c2236;
            --text-primary: #e2e8f0;
            --text-secondary: #94a3b8;
            --text-muted: #64748b;
            --border-color: #2d3748;
            --accent: #00d9ff;
            --accent-hover: #00b8e6;
            --accent-light: rgba(0, 217, 255, 0.1);
            --success: #10b981;
            --danger: #ef4444;
            --warning: #f59e0b;
            --code-bg: #0d1326;
        }
        [data-theme="light"] {
            --bg-primary: #f5f7fa;
            --bg-secondary: #ffffff;
            --bg-tertiary: #eef1f6;
            --bg-card: #ffffff;
            --bg-hover: #f1f4f9;
            --text-primary: #1a1f2e;
            --text-secondary: #4a5568;
            --text-muted: #6c757d;
            --border-color: #e2e8f0;
            --accent: #2563eb;
            --accent-light: rgba(37, 99, 235, 0.1);
            --code-bg: #f1f4f9;
        }
        * { box-sizing: border-box; }
        body {
            background: var(--bg-primary);
            color: var(--text-primary);
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            min-height: 100vh;
        }
        .navbar {
            background: linear-gradient(135deg, #0f172a, #1e293b);
            backdrop-filter: blur(8px);
            border-bottom: 1px solid var(--border-color);
        }
        .navbar-brand { color: var(--accent) !important; font-weight: 700; }
        .trace-hero {
            background: linear-gradient(135deg, var(--accent-light), transparent);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            padding: 3rem 2rem;
            text-align: center;
            margin-bottom: 2rem;
        }
        .trace-form { max-width: 700px; margin: 0 auto; }
        .trace-result-card {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            overflow: hidden;
            margin-bottom: 1.5rem;
        }
        .trace-card-header {
            background: var(--bg-tertiary);
            border-bottom: 1px solid var(--border-color);
            padding: 1rem 1.5rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .trace-card-body { padding: 1.5rem; }
        .score-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 60px;
            height: 60px;
            border-radius: 50%;
            font-size: 1.2rem;
            font-weight: 700;
        }
        .score-excellent { background: rgba(16, 185, 129, 0.2); color: #10b981; border: 2px solid #10b981; }
        .score-good { background: rgba(59, 130, 246, 0.2); color: #3b82f6; border: 2px solid #3b82f6; }
        .score-fair { background: rgba(245, 158, 11, 0.2); color: #f59e0b; border: 2px solid #f59e0b; }
        .score-poor { background: rgba(239, 68, 68, 0.2); color: #ef4444; border: 2px solid #ef4444; }
        .tech-tag {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 500;
            margin: 2px;
        }
        .tech-tag.server { background: rgba(0, 217, 255, 0.15); color: #00d9ff; border: 1px solid rgba(0, 217, 255, 0.3); }
        .tech-tag.framework { background: rgba(168, 85, 247, 0.15); color: #a855f7; border: 1px solid rgba(168, 85, 247, 0.3); }
        .tech-tag.cms { background: rgba(236, 72, 153, 0.15); color: #ec4899; border: 1px solid rgba(236, 72, 153, 0.3); }
        .tech-tag.js { background: rgba(234, 179, 8, 0.15); color: #eab308; border: 1px solid rgba(234, 179, 8, 0.3); }
        .tech-tag.cdn { background: rgba(59, 130, 246, 0.15); color: #3b82f6; border: 1px solid rgba(59, 130, 246, 0.3); }
        .tech-tag.library { background: rgba(16, 185, 129, 0.15); color: #10b981; border: 1px solid rgba(16, 185, 129, 0.3); }
        .header-item {
            display: flex;
            justify-content: space-between;
            padding: 8px 12px;
            border-bottom: 1px solid var(--border-color);
            font-size: 0.85rem;
        }
        .header-item:last-child { border-bottom: none; }
        .header-key { color: var(--text-muted); font-weight: 500; }
        .header-value { color: var(--text-primary); word-break: break-all; text-align: right; max-width: 60%; }
        .header-value.secure { color: #10b981; }
        .header-value.insecure { color: #ef4444; }
        .link-item {
            display: flex;
            align-items: center;
            padding: 6px 10px;
            border-radius: 6px;
            margin: 3px 0;
            font-size: 0.8rem;
            background: var(--bg-tertiary);
            word-break: break-all;
        }
        .link-item.internal { border-left: 3px solid var(--success); }
        .link-item.external { border-left: 3px solid var(--warning); }
        .link-item .link-type {
            font-size: 0.65rem;
            padding: 2px 6px;
            border-radius: 4px;
            margin-right: 8px;
            text-transform: uppercase;
            font-weight: 600;
        }
        .link-type.image { background: rgba(168, 85, 247, 0.2); color: #a855f7; }
        .link-type.script { background: rgba(234, 179, 8, 0.2); color: #eab308; }
        .link-type.stylesheet { background: rgba(59, 130, 246, 0.2); color: #3b82f6; }
        .link-type.internal { background: rgba(16, 185, 129, 0.2); color: #10b981; }
        .link-type.external { background: rgba(245, 158, 11, 0.2); color: #f59e0b; }
        .form-item {
            background: var(--bg-tertiary);
            border-radius: 8px;
            padding: 12px;
            margin-bottom: 10px;
            border-left: 3px solid var(--accent);
        }
        .form-item-title { font-weight: 600; margin-bottom: 8px; color: var(--accent); }
        .form-input-list { display: flex; flex-wrap: wrap; gap: 4px; }
        .form-input-tag {
            background: var(--bg-card);
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 0.75rem;
            border: 1px solid var(--border-color);
        }
        .history-item {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            padding: 12px;
            margin-bottom: 8px;
            cursor: pointer;
            transition: all 0.2s;
        }
        .history-item:hover {
            border-color: var(--accent);
            background: var(--bg-hover);
        }
        .status-indicator {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
        }
        .status-200 { background: rgba(16, 185, 129, 0.15); color: #10b981; }
        .status-3xx { background: rgba(59, 130, 246, 0.15); color: #3b82f6; }
        .status-4xx { background: rgba(245, 158, 11, 0.15); color: #f59e0b; }
        .status-5xx { background: rgba(239, 68, 68, 0.15); color: #ef4444; }
        .loading-overlay {
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(0,0,0,0.5);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 9999;
            display: none;
        }
        .loading-overlay.show { display: flex; }
        .loading-spinner {
            width: 50px;
            height: 50px;
            border: 4px solid var(--border-color);
            border-top-color: var(--accent);
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }
        @keyframes spin { to { transform: rotate(360deg); } }
        .section-title { font-size: 1.1rem; font-weight: 600; margin-bottom: 1rem; display: flex; align-items: center; gap: 8px; }
        .trace-json {
            background: var(--code-bg);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            padding: 1rem;
            font-size: 0.75rem;
            overflow-x: auto;
            max-height: 400px;
            overflow-y: auto;
            white-space: pre-wrap;
            word-break: break-all;
        }
        @media (max-width: 768px) {
            .trace-hero { padding: 1.5rem 1rem; }
            .header-item { flex-direction: column; gap: 2px; }
            .header-value { text-align: left; max-width: 100%; }
        }
        /* Identity Section Styles */
        .identity-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }
        .identity-card {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 1.25rem;
        }
        .identity-card h5 {
            margin-bottom: 0.75rem;
            color: var(--accent);
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .identity-item {
            display: flex;
            justify-content: space-between;
            padding: 4px 0;
            font-size: 0.85rem;
            border-bottom: 1px solid var(--border-color);
        }
        .identity-item:last-child { border-bottom: none; }
        .identity-label { color: var(--text-secondary); }
        .identity-value { font-weight: 600; text-align: right; max-width: 60%; word-break: break-all; }
        .mac-list { font-size: 0.75rem; color: var(--text-secondary); margin-top: 4px; }
        .mac-item { padding: 2px 0; }
        .trace-id-badge {
            background: var(--accent);
            color: white;
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 0.7rem;
            font-family: monospace;
        }
        /* Godseye Banner Styles */
        .godseye-banner-card {
            background: linear-gradient(135deg, #0a0a1e, #1a1a3e) !important;
            border-color: #00ff88 !important;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .godseye-banner-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 20px rgba(0, 255, 136, 0.15);
        }
        .godseye-banner-card .card-body { padding: 1.5rem; }
        @keyframes godseye-spin { to { transform: rotate(360deg); } }
    </style>
</head>
<body>
    <!-- Loading Overlay -->
    <div class="loading-overlay" id="loading-overlay">
        <div class="text-center">
            <div class="loading-spinner mx-auto mb-3"></div>
            <p class="text-light">Tracing URL...</p>
        </div>
    </div>

    <nav class="navbar navbar-expand-lg sticky-top">
        <div class="container-fluid">
            <a class="navbar-brand" href="<?= $ROOT_BASE ?>/index.php">
                <i class="bi bi-bug-fill"></i> OSINT Framework
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#trace-nav" aria-controls="trace-nav" aria-expanded="false" aria-label="Toggle navigation">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="trace-nav">
                <div class="navbar-nav ms-auto align-items-lg-center gap-lg-2">
                    <a class="nav-link" href="<?= $ROOT_BASE ?>/index.php">
                        <i class="fas fa-home"></i> OSINT Home
                    </a>
                    <a class="nav-link active" href="<?= $TRACE_BASE ?>/url_trace.php">
                        <i class="fas fa-crosshairs"></i> URL Tracer
                    </a>
                    <a class="nav-link" href="<?= $ROOT_BASE ?>/sccrm/trace/agent_browser.php">
                        <i class="fas fa-globe"></i> Browser
                    </a>
                    <a class="nav-link" href="<?= $ROOT_BASE ?>/godseye.php">
                        <i class="fas fa-globe"></i> Godseye
                    </a>
                    <a class="nav-link" href="<?= $ROOT_BASE ?>/sccrm/index.php">
                        <i class="fas fa-briefcase"></i> SCCRM
                    </a>
                    <a class="nav-link" href="<?= $ROOT_BASE ?>/sccrm/trace/">
                        <i class="fas fa-exchange-alt"></i> Trace in SCCRM
                    </a>
                </div>
            </div>
        </div>
    </nav>

    <main class="container-fluid py-4">
        <div class="row justify-content-center">
            <div class="col-lg-10">
                <!-- Hero -->
                <div class="trace-hero">
                    <h1 class="display-5 mb-3"><i class="fas fa-crosshairs text-primary"></i> URL Tracer</h1>
                    <p class="lead text-muted">Trace any URL and extract all details: Technologies, Headers, SSL, Links, Forms, SEO, Security & Performance</p>
                    <div class="trace-form">
                        <form method="POST" action="" id="traceForm">
                            <div class="input-group mb-3">
                                <input type="url" class="form-control form-control-lg" name="url" id="urlInput"
                                       placeholder="Enter URL to trace (e.g., https://example.com)"
                                       required aria-label="URL to trace">
                                <button class="btn btn-primary btn-lg" type="submit" id="traceBtn">
                                    <i class="fas fa-play"></i> Trace
                                </button>
                            </div>
                            <small class="text-muted">
                                <i class="bi bi-shield-lock"></i> Analyzes HTTP Headers, SSL/TLS, Technologies, Links, Forms, SEO, Security Headers & Performance
                            </small>
                        </form>
                    </div>
                </div>

                <?php if ($error): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-triangle"></i> <?= h($error) ?>
                </div>
                <?php endif; ?>

                <?php if ($traceResult && empty($traceResult['error'])): ?>
                <!-- Results -->
                <div id="traceResults">
                    
                    <!-- Status Card -->
                    <div class="trace-result-card">
                        <div class="trace-card-header bg-primary text-white">
                            <i class="fas fa-info-circle"></i> Basic Information
                            <span class="status-indicator status-<?= ($traceResult['basic']['status_code'] ?? 0) < 400 ? '200' : '4xx' ?>">
                                <?= $traceResult['basic']['status_code'] ?? 'N/A' ?>
                            </span>
                            <a class="btn btn-sm btn-light ms-auto" title="Create SCCRM lead from this trace (AutoFlow)"
                               href="<?= $ROOT_BASE ?>/sccrm/autoflows/push_trace.php?url=<?= urlencode($traceResult['basic']['final_url'] ?? $traceResult['basic']['url'] ?? '') ?>">
                                <i class="fas fa-user-plus"></i> Send to SCCRM
                            </a>
                        </div>
                        <div class="trace-card-body">
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <strong><i class="fas fa-link"></i> URL</strong><br>
                                    <small class="text-muted"><?= h($traceResult['basic']['url'] ?? '') ?></small>
                                    <br><strong><?= h($traceResult['basic']['final_url'] ?? '') ?></strong>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <strong><i class="fas fa-stopwatch"></i> Response Time</strong><br>
                                    <span class="fs-4"><?= $traceResult['basic']['response_time_ms'] ?? 'N/A' ?>ms</span>
                                </div>
                                <div class="col-md-3 mb-3">
                                    <strong><i class="fas fa-file-code"></i> Content</strong><br>
                                    <?= round(($traceResult['basic']['content_length'] ?? 0) / 1024, 1) ?> KB
                                </div>
                                <div class="col-md-3 mb-3">
                                    <strong><i class="fas fa-globe"></i> Type</strong><br>
                                    <small><?= h($traceResult['basic']['content_type'] ?? 'N/A') ?></small>
                                </div>
                                <div class="col-md-3 mb-3">
                                    <strong><i class="fas fa-server"></i> Server</strong><br>
                                    <small><?= h($traceResult['basic']['server'] ?? 'N/A') ?></small>
                                </div>
                                <div class="col-md-3 mb-3">
                                    <strong><i class="fas fa-server"></i> IP</strong><br>
                                    <small><?= h($traceResult['basic']['server_ip'] ?? 'N/A') ?></small>
                                </div>
                                <div class="col-md-3 mb-3">
                                    <strong><i class="fas fa-language"></i> Language</strong><br>
                                    <?= h($traceResult['content']['language'] ?? 'N/A') ?>
                                </div>
                                <div class="col-md-3 mb-3">
                                    <strong><i class="fas fa-cogs"></i> Encoding</strong><br>
                                    <?= h($traceResult['content']['charset'] ?? 'N/A') ?>
                                </div>
                                <div class="col-md-3 mb-3">
                                    <strong><i class="fas fa-redo"></i> Redirects</strong><br>
                                    <?= $traceResult['basic']['redirects'] ?? 0 ?>
                                </div>
                                <div class="col-md-3 mb-3">
                                    <strong><i class="fas fa-clock"></i> HTTP</strong><br>
                                    <?= h($traceResult['basic']['http_version'] ?? 'N/A') ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Scores Overview -->
                    <div class="trace-result-card">
                        <div class="trace-card-header">
                            <i class="fas fa-chart-bar"></i> Scores Overview
                        </div>
                        <div class="trace-card-body">
                            <div class="row text-center">
                                <div class="col-4 mb-3">
                                    <div class="score-badge <?= ($traceResult['seo']['score'] ?? 0) >= 70 ? 'score-excellent' : (($traceResult['seo']['score'] ?? 0) >= 40 ? 'score-fair' : 'score-poor') ?>">
                                        <span><?= $traceResult['seo']['score'] ?? 0 ?>/100</span>
                                    </div>
                                    <small class="text-muted mt-1 d-block">SEO Score</small>
                                    <small class="text-primary"><?= $traceResult['seo']['score_label'] ?? 'N/A' ?></small>
                                </div>
                                <div class="col-4 mb-3">
                                    <div class="score-badge <?= ($traceResult['security']['score'] ?? 0) >= 70 ? 'score-excellent' : (($traceResult['security']['score'] ?? 0) >= 40 ? 'score-fair' : 'score-poor') ?>">
                                        <span><?= $traceResult['security']['score'] ?? 0 ?>/100</span>
                                    </div>
                                    <small class="text-muted mt-1 d-block">Security</small>
                                    <small class="text-primary"><?= $traceResult['security']['score_label'] ?? 'N/A' ?></small>
                                </div>
                                <div class="col-4 mb-3">
                                    <div class="score-badge <?= ($traceResult['performance']['performance_score'] ?? 0) >= 70 ? 'score-excellent' : (($traceResult['performance']['performance_score'] ?? 0) >= 40 ? 'score-fair' : 'score-poor') ?>">
                                        <span><?= $traceResult['performance']['performance_score'] ?? 0 ?>/100</span>
                                    </div>
                                    <small class="text-muted mt-1 d-block">Performance</small>
                                    <small class="text-primary"><?= $traceResult['performance']['indicators']['overall_score'] ? 'Good' : 'Check' ?></small>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Identity & Location -->
                    <?php if (!empty($traceResult['identity'])): ?>
                    <?php
                    $id = $traceResult['identity'];
                    $loc = $id['location'] ?? [];
                    $fp = $id['first_published'] ?? [];
                    $net = $id['network'] ?? [];
                    $lt = $id['local_time'] ?? [];
                    $macs = $id['mac_address'] ?? [];
                    ?>
                    <div class="trace-result-card">
                        <div class="trace-card-header">
                            <i class="fas fa-fingerprint"></i> Identity & Location
                            <span class="ms-auto"><span class="trace-id-badge"><?= h($id['trace_id'] ?? 'N/A') ?></span></span>
                        </div>
                        <div class="trace-card-body">
                            <div class="identity-grid">
                                <!-- Time & Trace -->
                                <div class="identity-card">
                                    <h5><i class="fas fa-clock"></i> Trace Time</h5>
                                    <div class="identity-item"><span class="identity-label">Date</span><span class="identity-value"><?= h($id['trace_date'] ?? 'N/A') ?></span></div>
                                    <div class="identity-item"><span class="identity-label">Time</span><span class="identity-value"><?= h($id['trace_time'] ?? 'N/A') ?></span></div>
                                    <div class="identity-item"><span class="identity-label">Timezone</span><span class="identity-value"><?= h($id['trace_timezone'] ?? 'N/A') ?></span></div>
                                    <div class="identity-item"><span class="identity-label">Epoch</span><span class="identity-value"><?= h($id['trace_epoch'] ?? 'N/A') ?></span></div>
                                    <div class="identity-item"><span class="identity-label">Local Date</span><span class="identity-value"><?= h($lt['current_date'] ?? 'N/A') ?></span></div>
                                    <div class="identity-item"><span class="identity-label">Local Time</span><span class="identity-value"><?= h($lt['current_time'] ?? 'N/A') ?></span></div>
                                    <div class="identity-item"><span class="identity-label">DST</span><span class="identity-value"><?= ($lt['is_dst'] ?? false) ? 'Yes' : 'No' ?></span></div>
                                </div>
                                
                                <!-- IP Address -->
                                <div class="identity-card">
                                    <h5><i class="fas fa-network-wired"></i> IP Address</h5>
                                    <div class="identity-item"><span class="identity-label">IP</span><span class="identity-value"><?= h($id['ip_address'] ?? 'N/A') ?></span></div>
                                    <div class="identity-item"><span class="identity-label">Reverse DNS</span><span class="identity-value"><?= h($id['reverse_dns'] ?? 'N/A') ?></span></div>
                                    <div class="identity-item"><span class="identity-label">Country</span><span class="identity-value"><?= h($loc['country'] ?? 'N/A') ?></span></div>
                                    <div class="identity-item"><span class="identity-label">Region</span><span class="identity-value"><?= h($loc['region'] ?? 'N/A') ?></span></div>
                                    <div class="identity-item"><span class="identity-label">City</span><span class="identity-value"><?= h($loc['city'] ?? 'N/A') ?></span></div>
                                    <div class="identity-item"><span class="identity-label">Coordinates</span><span class="identity-value"><?= ($loc['latitude'] && $loc['longitude']) ? $loc['latitude'] . ', ' . $loc['longitude'] : 'N/A' ?></span></div>
                                    <div class="identity-item"><span class="identity-label">ISP</span><span class="identity-value"><?= h($loc['isp'] ?? 'N/A') ?></span></div>
                                    <div class="identity-item"><span class="identity-label">ASN</span><span class="identity-value"><?= h($id['asn']['asn'] ?? 'N/A') ?></span></div>
                                </div>
                                
                                <!-- MAC Address -->
                                <div class="identity-card">
                                    <h5><i class="fas fa-wifi"></i> MAC Address</h5>
                                    <?php if (!empty($macs)): ?>
                                        <?php foreach ($macs as $mac): ?>
                                        <div class="mac-item"><strong><?= h($mac['mac_address'] ?? 'N/A') ?></strong> <?= h($mac['interface'] ?? '') ?></div>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <div class="identity-item"><span class="identity-label">Status</span><span class="identity-value">Not detected</span></div>
                                    <?php endif; ?>
                                </div>
                                
                                <!-- First Published -->
                                <div class="identity-card">
                                    <h5><i class="fas fa-calendar-check"></i> First Published</h5>
                                    <div class="identity-item"><span class="identity-label">Created</span><span class="identity-value"><?= h($fp['created_date'] ?? 'N/A') ?></span></div>
                                    <div class="identity-item"><span class="identity-label">Registrar</span><span class="identity-value"><?= h($fp['registrar'] ?? 'N/A') ?></span></div>
                                    <div class="identity-item"><span class="identity-label">Expiry</span><span class="identity-value"><?= h($fp['expiry_date'] ?? 'N/A') ?></span></div>
                                    <div class="identity-item"><span class="identity-label">Country</span><span class="identity-value"><?= h($fp['registrant_country'] ?? 'N/A') ?></span></div>
                                </div>
                                
                                <!-- Network -->
                                <div class="identity-card">
                                    <h5><i class="fas fa-cogs"></i> Network</h5>
                                    <div class="identity-item"><span class="identity-label">Subnet</span><span class="identity-value"><?= h($net['subnet'] ?? 'N/A') ?></span></div>
                                    <div class="identity-item"><span class="identity-label">Gateway</span><span class="identity-value"><?= h($net['gateway'] ?? 'N/A') ?></span></div>
                                    <div class="identity-item"><span class="identity-label">DNS</span><span class="identity-value"><?= implode(', ', $net['dns_servers'] ?? []) ?></span></div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Technologies -->
                    <?php if (!empty($traceResult['technology']['categories'])): ?>
                    <div class="trace-result-card">
                        <div class="trace-card-header">
                            <i class="fas fa-microchip"></i> Technologies Detected
                            <span class="ms-auto badge bg-primary"><?= count($traceResult['technology']['all_technologies'] ?? []) ?></span>
                        </div>
                        <div class="trace-card-body">
                            <?php foreach ($traceResult['technology']['categories'] as $cat => $items): ?>
                                <?php if (!empty($items)): ?>
                                <div class="mb-3">
                                    <h6 class="text-muted text-uppercase small"><i class="fas fa-tag"></i> <?= ucfirst($cat) ?></h6>
                                    <?php foreach ((array)$items as $item): ?>
                                        <span class="tech-tag <?= $cat === 'cdn' ? 'cdn' : ($cat === 'frameworks' ? 'framework' : ($cat === 'cms' ? 'cms' : ($cat === 'javascript_libraries' ? 'js' : 'library'))) ?>">
                                            <?= h($item) ?>
                                        </span>
                                    <?php endforeach; ?>
                                </div>
                                <?php endif; ?>
                            <?php endforeach; ?>
                            <?php if (!empty($traceResult['technology']['details'])): ?>
                            <div class="mt-3">
                                <h6 class="text-muted text-uppercase small"><i class="fas fa-info-circle"></i> Details</h6>
                                <?php foreach ($traceResult['technology']['details'] as $key => $val): ?>
                                    <span class="tech-tag library"><?= h($key) ?>: <?= h($val) ?></span>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- HTTP Headers -->
                    <?php if (!empty($traceResult['headers']) && !empty($traceResult['headers']['headers'])): ?>
                    <div class="trace-result-card">
                        <div class="trace-card-header">
                            <i class="fas fa-shield-halved"></i> HTTP Headers (<?= count($traceResult['headers']['headers'] ?? []) ?> total)
                        </div>
                        <div class="trace-card-body p-0">
                            <?php foreach ($traceResult['headers']['headers'] as $name => $value): ?>
                            <div class="header-item">
                                <span class="header-key"><?= h($name) ?></span>
                                <span class="header-value <?= in_array($name, ['Strict-Transport-Security', 'Content-Security-Policy', 'X-Content-Type-Options', 'X-Frame-Options']) ? (strpos($value, 'none') === false ? 'secure' : 'insecure') : '' ?>">
                                    <?= h($value) ?>
                                </span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Security Headers -->
                    <?php if (!empty($traceResult['security']['headers'])): ?>
                    <div class="trace-result-card">
                        <div class="trace-card-header">
                            <i class="fas fa-shield-alt"></i> Security Headers
                            <span class="ms-auto badge bg-<?= ($traceResult['security']['score'] ?? 0) >= 70 ? 'success' : 'warning' ?>">
                                <?= $traceResult['security']['score'] ?? 0 ?>/100
                            </span>
                        </div>
                        <div class="trace-card-body">
                            <div class="row">
                                <?php foreach ($traceResult['security']['headers'] as $header => $info): ?>
                                <div class="col-md-6 mb-2">
                                    <div class="d-flex align-items-center gap-2">
                                        <i class="fas <?= $info['present'] ? 'fa-check-circle text-success' : 'fa-times-circle text-muted' ?>"></i>
                                        <span class="small"><?= h($header) ?></span>
                                        <span class="badge <?= $info['present'] ? 'bg-success' : 'bg-secondary' ?> ms-auto">
                                            <?= $info['present'] ? 'Present' : 'Missing' ?>
                                        </span>
                                    </div>
                                    <?php if ($info['present']): ?>
                                    <small class="text-muted ms-5"><?= h($info['value']) ?></small>
                                    <?php endif; ?>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- SSL/TLS -->
                    <?php if (!empty($traceResult['ssl']['certificate'])): ?>
                    <div class="trace-result-card">
                        <div class="trace-card-header">
                            <i class="fas fa-lock"></i> SSL/TLS Certificate
                            <span class="ms-auto badge bg-<?= $traceResult['ssl']['certificate']['is_valid'] ? 'success' : 'danger' ?>">
                                <?= $traceResult['ssl']['certificate']['is_valid'] ? 'Valid' : 'Expired' ?>
                            </span>
                        </div>
                        <div class="trace-card-body">
                            <div class="row">
                                <div class="col-md-4 mb-3"><strong>Subject:</strong><br><small><?= h($traceResult['ssl']['certificate']['subject'] ?? 'N/A') ?></small></div>
                                <div class="col-md-4 mb-3"><strong>Issuer:</strong><br><small><?= h($traceResult['ssl']['certificate']['issuer'] ?? 'N/A') ?></small></div>
                                <div class="col-md-4 mb-3"><strong>Valid From:</strong><br><small><?= h($traceResult['ssl']['certificate']['valid_from'] ?? 'N/A') ?></small></div>
                                <div class="col-md-4 mb-3"><strong>Valid To:</strong><br><small><?= h($traceResult['ssl']['certificate']['valid_to'] ?? 'N/A') ?></small></div>
                                <div class="col-md-4 mb-3"><strong>Days Remaining:</strong><br><strong><?= $traceResult['ssl']['certificate']['days_remaining'] ?? 'N/A' ?></strong></div>
                                <div class="col-md-4 mb-3"><strong>Key Size:</strong><br><small><?= $traceResult['ssl']['certificate']['public_key_size'] ?? 'N/A' ?> bit</small></div>
                                <div class="col-md-4 mb-3"><strong>Algorithm:</strong><br><small><?= h($traceResult['ssl']['certificate']['signature_algorithm'] ?? 'N/A') ?></small></div>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Content Analysis -->
                    <?php if (!empty($traceResult['content'])): ?>
                    <div class="trace-result-card">
                        <div class="trace-card-header">
                            <i class="fas fa-file-alt"></i> Content Analysis
                            <span class="ms-auto badge bg-primary">
                                <?= $traceResult['content']['word_count'] ?? 0 ?> words | <?= $traceResult['content']['total_images'] ?? 0 ?> images
                            </span>
                        </div>
                        <div class="trace-card-body">
                            <div class="row">
                                <div class="col-md-6">
                                    <h5><i class="fas fa-heading"></i> Title</h5>
                                    <p class="lead"><?= h($traceResult['content']['title'] ?? 'N/A') ?></p>
                                    <?php if (!empty($traceResult['content']['meta_description'])): ?>
                                    <h5><i class="fas fa-comment-dots"></i> Meta Description</h5>
                                    <p class="text-muted"><?= h($traceResult['content']['meta_description']) ?></p>
                                    <?php endif; ?>
                                    <?php if (!empty($traceResult['content']['meta_keywords'])): ?>
                                    <h5><i class="fas fa-key"></i> Meta Keywords</h5>
                                    <p><small class="text-muted"><?= h($traceResult['content']['meta_keywords']) ?></small></p>
                                    <?php endif; ?>
                                </div>
                                <div class="col-md-6">
                                    <div class="row text-center">
                                        <div class="col-4"><strong><?= $traceResult['content']['element_counts']['h1_count'] ?? 0 ?></strong><br><small>H1</small></div>
                                        <div class="col-4"><strong><?= $traceResult['content']['element_counts']['h2_count'] ?? 0 ?></strong><br><small>H2</small></div>
                                        <div class="col-4"><strong><?= $traceResult['content']['element_counts']['div_count'] ?? 0 ?></strong><br><small>DIV</small></div>
                                        <div class="col-4"><strong><?= $traceResult['content']['element_counts']['script_count'] ?? 0 ?></strong><br><small>Script</small></div>
                                        <div class="col-4"><strong><?= $traceResult['content']['element_counts']['img_count'] ?? 0 ?></strong><br><small>Img</small></div>
                                        <div class="col-4"><strong><?= $traceResult['content']['element_counts']['form_count'] ?? 0 ?></strong><br><small>Form</small></div>
                                    </div>
                                </div>
                            </div>
                            <?php if (!empty($traceResult['content']['og_tags'])): ?>
                            <div class="mt-3">
                                <h6><i class="fab fa-facebook"></i> Open Graph Tags</h6>
                                <?php foreach ($traceResult['content']['og_tags'] as $key => $value): ?>
                                <div class="header-item"><span class="header-key"><?= h($key) ?></span><span class="header-value"><?= h($value) ?></span></div>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                            <?php if (!empty($traceResult['content']['twitter_tags'])): ?>
                            <div class="mt-3">
                                <h6><i class="fab fa-twitter"></i> Twitter Cards</h6>
                                <?php foreach ($traceResult['content']['twitter_tags'] as $key => $value): ?>
                                <div class="header-item"><span class="header-key"><?= h($key) ?></span><span class="header-value"><?= h($value) ?></span></div>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Links -->
                    <?php if (!empty($traceResult['links']['stats'])): ?>
                    <div class="trace-result-card">
                        <div class="trace-card-header">
                            <i class="fas fa-link"></i> Links Analysis
                            <span class="ms-auto badge bg-primary">
                                <?= $traceResult['links']['stats']['total_links'] ?> total | 
                                <?= $traceResult['links']['stats']['internal_links'] ?> internal | 
                                <?= $traceResult['links']['stats']['external_links'] ?> external
                            </span>
                        </div>
                        <div class="trace-card-body">
                            <div class="row mb-3">
                                <div class="col-md-4 text-center"><h3><?= $traceResult['links']['stats']['total_links'] ?></h3><small>Total Links</small></div>
                                <div class="col-md-4 text-center"><h3 class="text-success"><?= $traceResult['links']['stats']['internal_links'] ?></h3><small>Internal</small></div>
                                <div class="col-md-4 text-center"><h3 class="text-warning"><?= $traceResult['links']['stats']['external_links'] ?></h3><small>External</small></div>
                            </div>
                            <?php if (!empty($traceResult['links']['unique_domains'])): ?>
                            <div class="mb-3">
                                <h6><i class="fas fa-globe"></i> Unique Domains (<?= $traceResult['links']['stats']['unique_domains'] ?>)</h6>
                                <?php foreach (array_slice($traceResult['links']['unique_domains'], 0, 15) as $domain): ?>
                                <span class="tech-tag library"><?= h($domain) ?></span>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                            <?php if (!empty($traceResult['links']['links'])): ?>
                            <div>
                                <h6><i class="fas fa-chain"></i> Sample Links</h6>
                                <?php foreach (array_slice($traceResult['links']['links'], 0, 20) as $link): ?>
                                <div class="link-item <?= $link['is_internal'] ? 'internal' : 'external' ?>">
                                    <span class="link-type <?= $link['is_internal'] ? 'internal' : 'external' ?>">
                                        <?= $link['is_internal'] ? 'Internal' : 'External' ?>
                                    </span>
                                    <a href="<?= h($link['absolute_url']) ?>" target="_blank" rel="noopener">
                                        <?= h($link['absolute_url']) ?>
                                    </a>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Assets -->
                    <?php if (!empty($traceResult['links']['src_links'])): ?>
                    <div class="trace-result-card">
                        <div class="trace-card-header">
                            <i class="fas fa-cubes"></i> Assets (<?= $traceResult['links']['stats']['total_assets'] ?> total)
                        </div>
                        <div class="trace-card-body">
                            <?php foreach (array_slice($traceResult['links']['src_links'], 0, 20) as $asset): ?>
                            <div class="link-item">
                                <span class="link-type <?= $asset['type'] ?>"><?= h($asset['type']) ?></span>
                                <a href="<?= h($asset['url']) ?>" target="_blank" rel="noopener" class="text-truncate" style="max-width: 70%;">
                                    <?= h($asset['url']) ?>
                                </a>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Forms -->
                    <?php if (!empty($traceResult['forms']['total_forms'])): ?>
                    <div class="trace-result-card">
                        <div class="trace-card-header">
                            <i class="fas fa-file-signature"></i> Forms Detected (<?= $traceResult['forms']['total_forms'] ?>)
                        </div>
                        <div class="trace-card-body">
                            <?php if (!empty($traceResult['forms']['summary'])): ?>
                            <div class="row text-center mb-3">
                                <div class="col-3"><strong><?= $traceResult['forms']['summary']['total_inputs'] ?></strong><br><small>Inputs</small></div>
                                <div class="col-3"><strong><?= $traceResult['forms']['summary']['total_selects'] ?></strong><br><small>Selects</small></div>
                                <div class="col-3"><strong><?= $traceResult['forms']['summary']['total_textareas'] ?></strong><br><small>Textareas</small></div>
                                <div class="col-3"><strong><?= $traceResult['forms']['summary']['forms_with_password'] ?></strong><br><small>Password</small></div>
                            </div>
                            <?php endif; ?>
                            <?php foreach ($traceResult['forms']['forms'] as $form): ?>
                            <div class="form-item">
                                <div class="form-item-title">
                                    <i class="fas fa-file-alt"></i> Form #<?= $form['index'] + 1 ?>
                                    <span class="badge bg-<?= $form['method'] === 'POST' ? 'info' : 'secondary' ?> ms-2"><?= $form['method'] ?></span>
                                    <?php if ($form['has_password']): ?><span class="badge bg-danger ms-1"><i class="fas fa-lock"></i> Password</span><?php endif; ?>
                                    <?php if ($form['has_file']): ?><span class="badge bg-warning text-dark ms-1"><i class="fas fa-upload"></i> File</span><?php endif; ?>
                                </div>
                                <div class="small text-muted mb-2">Action: <a href="<?= h($form['action'] ?? '#') ?>"><?= h($form['action'] ?? 'N/A') ?></a></div>
                                <div class="form-input-list">
                                    <?php foreach (array_slice($form['input_names'] ?? [], 0, 10) as $name): ?>
                                        <span class="form-input-tag"><?= h($name) ?></span>
                                    <?php endforeach; ?>
                                    <?php if (($form['input_count'] ?? 0) > 10): ?>
                                        <span class="form-input-tag">+<?= $form['input_count'] - 10 ?> more</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- SEO Details -->
                    <?php if (!empty($traceResult['seo']['checks'])): ?>
                    <div class="trace-result-card">
                        <div class="trace-card-header">
                            <i class="fas fa-search"></i> SEO Analysis
                            <span class="ms-auto badge bg-<?= ($traceResult['seo']['score'] ?? 0) >= 70 ? 'success' : 'warning' ?>">
                                <?= $traceResult['seo']['score_label'] ?? 'N/A' ?> (<?= $traceResult['seo']['score'] ?? 0 ?>/100)
                            </span>
                        </div>
                        <div class="trace-card-body">
                            <div class="row">
                                <?php foreach ($traceResult['seo']['checks'] as $check => $info): ?>
                                <div class="col-md-6 mb-2">
                                    <div class="d-flex align-items-center gap-2">
                                        <i class="fas <?= $info['pass'] ? 'fa-check-circle text-success' : 'fa-times-circle text-danger' ?>"></i>
                                        <span class="small"><?= ucfirst(str_replace('_', ' ', $check)) ?></span>
                                        <?php if (isset($info['length'])): ?>
                                        <span class="badge bg-secondary ms-auto"><?= $info['length'] ?> chars</span>
                                        <?php endif; ?>
                                    </div>
                                    <?php if (isset($info['message'])): ?>
                                    <small class="text-muted ms-5"><?= h($info['message']) ?></small>
                                    <?php endif; ?>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Performance -->
                    <?php if (!empty($traceResult['performance']['indicators'])): ?>
                    <div class="trace-result-card">
                        <div class="trace-card-header">
                            <i class="fas fa-tachometer-alt"></i> Performance Analysis
                        </div>
                        <div class="trace-card-body">
                            <div class="row">
                                <?php foreach ($traceResult['performance']['indicators'] as $metric => $info): ?>
                                <?php if ($metric !== 'overall_score'): ?>
                                <div class="col-md-4 mb-3 text-center">
                                    <div class="score-badge <?= $info['grade'] === 'A+' ? 'score-excellent' : ($info['grade'] === 'A' ? 'score-good' : ($info['grade'] === 'B' ? 'score-fair' : 'score-poor')) ?>">
                                        <small><?= $info['grade'] ?></small>
                                    </div>
                                    <strong class="d-block mt-1"><?= h($metric) ?></strong>
                                    <small class="text-muted"><?= h($info['value']) ?></small>
                                </div>
                                <?php endif; ?>
                                <?php endforeach; ?>
                                <div class="col-md-4 mb-3 text-center">
                                    <div class="score-badge <?= ($traceResult['performance']['performance_score'] ?? 0) >= 70 ? 'score-excellent' : 'score-fair' ?>">
                                        <span><?= $traceResult['performance']['performance_score'] ?? 0 ?>%</span>
                                    </div>
                                    <strong class="d-block mt-1">Overall</strong>
                                    <small class="text-muted">Performance Score</small>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Raw JSON Export -->
                    <div class="trace-result-card">
                        <div class="trace-card-header">
                            <i class="fas fa-code"></i> Raw Data (JSON)
                            <button class="btn btn-sm btn-outline-light ms-auto" onclick="copyJSON()" title="Copy to clipboard">
                                <i class="fas fa-copy"></i> Copy
                            </button>
                        </div>
                        <div class="trace-card-body">
                            <pre class="trace-json" id="rawJson"><?= htmlspecialchars(json_encode($traceResult, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></pre>
                        </div>
                    </div>

                </div><!-- end #traceResults -->
                <?php endif; ?>

                <?php if ($traceResult && isset($traceResult['error'])): ?>
                <div class="trace-result-card">
                    <div class="trace-card-header bg-danger text-white">
                        <i class="fas fa-exclamation-triangle"></i> Trace Error
                    </div>
                    <div class="trace-card-body">
                        <p class="text-danger"><?= h($traceResult['error']['message'] ?? 'Unknown error') ?></p>
                        <small class="text-muted">Error code: <?= $traceResult['error']['code'] ?? 'N/A' ?></small>
                    </div>
                </div>
                <?php endif; ?>

                <!-- History -->
                <?php if (!empty($traces)): ?>
                <div class="trace-result-card">
                    <div class="trace-card-header">
                        <i class="fas fa-history"></i> Trace History (<?= count($traces) ?> traces)
                        <span class="ms-auto">
                            <a href="?clear=1" class="btn btn-sm btn-outline-danger btn-sm">Clear All</a>
                        </span>
                    </div>
                    <div class="trace-card-body">
                        <?php foreach ($traces as $trace): ?>
                        <div class="history-item" onclick="location.href='?url=<?= urlencode($trace['url']) ?>'">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <strong><?= h($trace['url']) ?></strong>
                                    <span class="badge bg-<?= ($trace['status_code'] ?? 0) < 400 ? 'success' : 'warning' ?> ms-2">
                                        <?= $trace['status_code'] ?? 'N/A' ?>
                                    </span>
                                    <small class="text-muted ms-2"><?= h($trace['title'] ?? 'No title') ?></small>
                                </div>
                                <div class="text-end">
                                    <small class="text-muted"><?= h($trace['created_at'] ?? '') ?></small>
                                    <br>
                                    <small class="text-muted">
                                        SEO: <?= $trace['seo_score'] ?? 0 ?>/100 | 
                                        Sec: <?= $trace['security_score'] ?? 0 ?>/100 | 
                                        Perf: <?= $trace['performance_score'] ?? 0 ?>/100
                                    </small>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </main>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const form = document.getElementById('traceForm');
        const input = document.getElementById('urlInput');
        const loading = document.getElementById('loading-overlay');
        const traceBtn = document.getElementById('traceBtn');

        if (form) {
            form.addEventListener('submit', function(e) {
                if (!input.value.trim()) {
                    e.preventDefault();
                    return;
                }
                loading.classList.add('show');
                traceBtn.disabled = true;
                traceBtn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Tracing...';
            });
        }

        // If results exist, scroll to them
        const results = document.getElementById('traceResults');
        if (results) {
            setTimeout(() => results.scrollIntoView({ behavior: 'smooth', block: 'start' }), 100);
        }
    });

    function copyJSON() {
        const json = document.getElementById('rawJson');
        const text = json.textContent;
        navigator.clipboard.writeText(text).then(function() {
            alert('JSON copied to clipboard!');
        }).catch(function() {
            // Fallback
            const range = document.createRange();
            range.selectNodeContents(json);
            window.getSelection().removeAllRanges();
            window.getSelection().addRange(range);
            try {
                document.execCommand('copy');
                alert('JSON copied!');
            } catch(e) {
                alert('Copy failed. Please select and copy manually.');
            }
            window.getSelection().removeAllRanges();
        });
    }
    </script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
