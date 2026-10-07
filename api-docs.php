<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';

$PAGE_TITLE = 'API Documentation';
$PAGE_DESC = 'JSON API endpoints for programmatic access';
include __DIR__ . '/includes/header.php';

// Compute base for examples
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$api_base = $scheme . '://' . $host . $BASE . '/api';
?>

<div class="row">
    <div class="col-md-12 mb-4">
        <h1><i class="fas fa-code"></i> API Documentation</h1>
        <p class="text-muted">All endpoints return JSON. Authentication uses PHP sessions (cookie-based) — call <code>auth/login.php</code> first.</p>
    </div>
</div>

<div class="row g-4">
    <div class="col-md-6">
        <div class="card api-card">
            <div class="card-header d-flex justify-content-between">
                <span><span class="badge bg-success">GET</span> Search tools</span>
            </div>
            <div class="card-body">
                <code><?= h($api_base) ?>/search.php?q=<em>QUERY</em></code>
                <p class="mt-2 mb-1">Search across tool name, description, and tags.</p>
                <p class="text-muted small mb-2">Parameters:</p>
                <ul class="small">
                    <li><code>q</code> — search string (required, min 1 char)</li>
                </ul>
                <p class="text-muted small mb-1">Example response:</p>
                <pre><code>{
  "success": true,
  "count": 2,
  "results": [
    {
      "id": 42,
      "name": "Shodan",
      "url": "https://shodan.io",
      "description": "Search engine for internet devices",
      "cost_type": "freemium",
      "access_type": "web",
      "category": "IP Address",
      "category_slug": "ip",
      "rating_avg": 4.5,
      "rating_count": 12,
      "favicon": "https://www.google.com/s2/favicons?domain=shodan.io",
      "detail_url": ".../tool.php?id=42"
    }
  ]
}</code></pre>
            </div>
        </div>
    </div>

    <div class="col-md-6">
        <div class="card api-card">
            <div class="card-header">
                <span class="badge bg-success">GET</span> List tools by category
            </div>
            <div class="card-body">
                <code><?= h($api_base) ?>/tools.php?cat=<em>SLUG</em></code>
                <p class="mt-2 mb-1">Get all tools in a category (including subcategories).</p>
                <p class="text-muted small mb-2">Parameters:</p>
                <ul class="small">
                    <li><code>cat</code> — category slug (required)</li>
                    <li><code>cost</code> — filter: <code>free</code> | <code>freemium</code> | <code>paid</code></li>
                    <li><code>access</code> — filter: <code>web</code> | <code>api</code> | <code>software</code> | <code>browser-ext</code></li>
                </ul>
                <p class="text-muted small mb-1">Example slugs:</p>
                <p class="small"><code>username</code>, <code>email</code>, <code>domain</code>, <code>ip</code>, <code>phone</code>, <code>people</code>, <code>image</code>, <code>social</code>, <code>crypto</code>, <code>breaches</code>, <code>maps</code>, <code>network</code></p>
            </div>
        </div>
    </div>

    <div class="col-md-6">
        <div class="card api-card">
            <div class="card-header">
                <span class="badge bg-warning">POST</span> Toggle favorite
            </div>
            <div class="card-body">
                <code>POST <?= h($api_base) ?>/favorite.php</code>
                <p class="mt-2 text-muted small">Requires authenticated session (login first).</p>
                <p class="text-muted small mb-1">Request body:</p>
                <pre><code>{
  "tool_id": 42
}</code></pre>
                <p class="text-muted small mb-1">Response:</p>
                <pre><code>{
  "success": true,
  "favorited": true,
  "message": "Added to favorites"
}</code></pre>
            </div>
        </div>
    </div>

    <div class="col-md-6">
        <div class="card api-card">
            <div class="card-header">
                <span class="badge bg-warning">POST</span> Submit rating
            </div>
            <div class="card-body">
                <code>POST <?= h($api_base) ?>/rate.php</code>
                <p class="mt-2 text-muted small">Requires authenticated session. One rating per user per tool (upsert).</p>
                <p class="text-muted small mb-1">Request body:</p>
                <pre><code>{
  "tool_id": 42,
  "rating": 5,
  "review": "Excellent OSINT tool"
}</code></pre>
                <p class="text-muted small mb-1">Response:</p>
                <pre><code>{
  "success": true,
  "message": "Rating saved"
}</code></pre>
            </div>
        </div>
    </div>

    <div class="col-md-6">
        <div class="card api-card">
            <div class="card-header">
                <span class="badge bg-info">GET</span> Fetch tool favicon
            </div>
            <div class="card-body">
                <code><?= h($api_base) ?>/favicon.php?id=<em>TOOL_ID</em></code>
                <p class="mt-2 mb-1">Returns the cached favicon as PNG. Fetches from Google s2 service on first request, caches locally for 1 week.</p>
                <p class="text-muted small mb-1">Use in HTML:</p>
                <pre><code>&lt;img src="<?= h($api_base) ?>/favicon.php?id=42"
     alt="favicon"&gt;</code></pre>
            </div>
        </div>
    </div>

    <div class="col-md-6">
        <div class="card api-card">
            <div class="card-header">
                <span class="badge bg-primary">AUTH</span> Login flow
            </div>
            <div class="card-body">
                <code>POST <?= h($scheme . '://' . $host . $BASE) ?>/auth/login.php</code>
                <p class="mt-2 text-muted small">Standard HTML form, sets PHP session cookie. After login, AJAX calls to <code>/api/*</code> will be authenticated.</p>
                <p class="text-muted small mb-1">Form fields:</p>
                <ul class="small">
                    <li><code>identifier</code> — username or email</li>
                    <li><code>password</code> — password</li>
                    <li><code>redirect</code> — optional URL to redirect after</li>
                </ul>
                <p class="text-muted small mb-1">Example cURL:</p>
                <pre><code>curl -c cookies.txt -X POST \
  -d "identifier=admin&password=admin123" \
  <?= h($scheme . '://' . $host . $BASE) ?>/auth/login.php

# Now authenticated — call protected API:
curl -b cookies.txt \
  -X POST -H "Content-Type: application/json" \
  -d '{"tool_id":42}' \
  <?= h($api_base) ?>/favorite.php</code></pre>
            </div>
        </div>
    </div>
</div>

<div class="row mt-4">
    <div class="col-md-12">
        <div class="card">
            <div class="card-header"><i class="fas fa-info-circle"></i> Notes</div>
            <div class="card-body">
                <ul class="mb-0">
                    <li>All endpoints return <code>{"success": true|false, ...}</code></li>
                    <li>Error responses include an <code>error</code> field with a message</li>
                    <li>HTTP status codes: <code>200</code> OK, <code>400</code> bad request, <code>401</code> unauthenticated, <code>404</code> not found</li>
                    <li>Search results are limited to 50 tools per request</li>
                    <li>Cross-origin (CORS) requests are not enabled by default — same-origin only</li>
                </ul>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
