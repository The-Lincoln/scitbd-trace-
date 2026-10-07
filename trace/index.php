<?php
/**
 * Trace Directory - Index page for URL Tracer
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

$BASE = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
$BASE = rtrim($BASE, '/');

$page_title = 'URL Tracer';
$page_desc = 'Trace and analyze any URL - Technologies, Headers, SSL, Links, Forms, SEO, Security & Performance';
include __DIR__ . '/../includes/header.php';
?>

<div class="row g-3">
    <!-- Main content -->
    <section class="col-lg-12">
        <div class="hero-section mb-4">
            <h1 class="display-5"><i class="bi bi-crosshairs"></i> URL Tracer</h1>
            <p class="lead text-muted">Comprehensive URL analysis tool powered by phpML. Trace any URL to extract all details including technologies, headers, SSL certificates, links, forms, SEO analysis, security headers & performance metrics.</p>
            <div class="quick-stats">
                <span class="badge bg-primary fs-6">10 Analysis Phases</span>
                <span class="badge bg-success fs-6">100+ Data Points</span>
                <span class="badge bg-info fs-6">SQLite Storage</span>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-header">
                <i class="fas fa-crosshairs"></i> Trace a URL
            </div>
            <div class="card-body">
                <form method="POST" action="url_trace.php" class="trace-form">
                    <div class="input-group">
                        <input type="url" class="form-control form-control-lg" name="url" id="urlInput"
                               placeholder="https://example.com" required aria-label="URL to trace">
                        <button class="btn btn-primary btn-lg" type="submit" id="traceBtn">
                            <i class="fas fa-play"></i> Trace URL
                        </button>
                    </div>
                    <div class="mt-2 d-flex flex-wrap gap-2">
                        <small class="text-muted"><i class="fas fa-info-circle"></i> Analyzes:</small>
                        <a href="#" class="btn btn-sm btn-outline-secondary" onclick="document.getElementById('urlInput').value='https://'; return false;">
                            <i class="fas fa-globe"></i> Basic Info
                        </a>
                        <a href="#" class="btn btn-sm btn-outline-secondary" onclick="document.getElementById('urlInput').value='https://'; return false;">
                            <i class="fas fa-shield-halved"></i> Headers
                        </a>
                        <a href="#" class="btn btn-sm btn-outline-secondary" onclick="document.getElementById('urlInput').value='https://'; return false;">
                            <i class="fas fa-lock"></i> SSL/TLS
                        </a>
                        <a href="#" class="btn btn-sm btn-outline-secondary" onclick="document.getElementById('urlInput').value='https://'; return false;">
                            <i class="fas fa-microchip"></i> Technologies
                        </a>
                        <a href="#" class="btn btn-sm btn-outline-secondary" onclick="document.getElementById('urlInput').value='https://'; return false;">
                            <i class="fas fa-link"></i> Links & Forms
                        </a>
                        <a href="#" class="btn btn-sm btn-outline-secondary" onclick="document.getElementById('urlInput').value='https://'; return false;">
                            <i class="fas fa-search"></i> SEO
                        </a>
                        <a href="#" class="btn btn-sm btn-outline-secondary" onclick="document.getElementById('urlInput').value='https://'; return false;">
                            <i class="fas fa-tachometer-alt"></i> Performance
                        </a>
                    </div>
                </form>
            </div>
        </div>

        <!-- Features Grid -->
        <div class="row g-3 mb-4">
            <div class="col-md-4">
                <div class="card h-100">
                    <div class="card-body text-center">
                        <div class="display-4 text-primary"><i class="fas fa-crosshairs"></i></div>
                        <h5>10 Analysis Phases</h5>
                        <p class="text-muted small">Basic Info → Headers → SSL/TLS → Content → Technologies → Links → Forms → SEO → Security → Performance</p>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card h-100">
                    <div class="card-body text-center">
                        <div class="display-4 text-success"><i class="fas fa-shield-halved"></i></div>
                        <h5>Security Analysis</h5>
                        <p class="text-muted small">HSTS, CSP, X-Frame-Options, X-Content-Type-Options, CORS, cookie security scanning</p>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card h-100">
                    <div class="card-body text-center">
                        <div class="display-4 text-info"><i class="fas fa-database"></i></div>
                        <h5>SQLite Storage</h5>
                        <p class="text-muted small">All trace results stored in SQLite database with history and search capabilities</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Analysis Categories -->
        <div class="row g-3">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header"><i class="fas fa-wrench"></i> Technical Analysis</div>
                    <div class="card-body">
                        <ul class="list-group list-group-flush">
                            <li class="list-group-item"><i class="fas fa-check text-success"></i> HTTP Headers & Response Analysis</li>
                            <li class="list-group-item"><i class="fas fa-check text-success"></i> SSL/TLS Certificate Details</li>
                            <li class="list-group-item"><i class="fas fa-check text-success"></i> Technology Stack Detection</li>
                            <li class="list-group-item"><i class="fas fa-check text-success"></i> Server & CDN Detection</li>
                            <li class="list-group-item"><i class="fas fa-check text-success"></i> Framework & CMS Identification</li>
                        </ul>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header"><i class="fas fa-search"></i> Content & SEO Analysis</div>
                    <div class="card-body">
                        <ul class="list-group list-group-flush">
                            <li class="list-group-item"><i class="fas fa-check text-success"></i> Meta Tags & Open Graph</li>
                            <li class="list-group-item"><i class="fas fa-check text-success"></i> Heading Hierarchy Analysis</li>
                            <li class="list-group-item"><i class="fas fa-check text-success"></i> Image Alt Attribute Check</li>
                            <li class="list-group-item"><i class="fas fa-check text-success"></i> SEO Score Calculation</li>
                            <li class="list-group-item"><i class="fas fa-check text-success"></i> Performance Score</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </section>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
