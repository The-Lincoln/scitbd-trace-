<?php
/**
 * Godseye - Geospatial Intelligence Dashboard
 * Serves the built React + CesiumJS application
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';

// NOTE: $BASE is computed by includes/header.php (shared logic) — do not
// duplicate path resolution here; the asset tags below use header's $BASE.

$PAGE_TITLE = 'Godseye';
$PAGE_DESC = 'Geospatial Intelligence Dashboard - 3D Globe, Live Tracking, Surveillance, and OSINT Visualization';
include __DIR__ . '/includes/header.php';
?>

<!-- Godseye Intelligence Dashboard -->
<script>document.body.classList.add('godseye-active');</script>
<div id="root"></div>

<!-- Loading Overlay -->
<div id="godseye-loading" class="godseye-loading-container">
    <div class="godseye-spinner"></div>
    <p>Initializing Godseye Intelligence Dashboard...</p>
    <p style="font-size:0.7rem;opacity:0.5;margin-top:10px;">Loading CesiumJS, Layers, and Live Feeds</p>
</div>

<!-- Error Overlay -->
<div id="godseye-error" class="godseye-error-overlay">
    <h3>⚠️ Godseye Initialization Error</h3>
    <p id="godseye-error-msg">Failed to load the dashboard.</p>
    <p style="font-size:0.75rem;opacity:0.6;margin-bottom:1rem;">Some features require API keys. Check browser console.</p>
    <button class="godseye-btn-retry" onclick="location.reload()">Retry</button>
</div>

<!-- CesiumJS (CDN with chained fallback; local /cesium/ build used if present) -->
<?php $CESIUM_VER = '1.121.0'; ?>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/cesium@<?= $CESIUM_VER ?>/Build/Cesium/Widgets/widgets.css"
      onerror="this.onerror=null;this.href='https://unpkg.com/cesium@<?= $CESIUM_VER ?>/Build/Cesium/Widgets/widgets.css'">
<script>
window.__cesiumSources = [
    'https://cdn.jsdelivr.net/npm/cesium@<?= $CESIUM_VER ?>/Build/Cesium/Cesium.js',
    'https://unpkg.com/cesium@<?= $CESIUM_VER ?>/Build/Cesium/Cesium.js',
    '<?= $BASE ?>/cesium/Cesium.js'
];
(function loadCesium(i) {
    if (window.Cesium) { window.dispatchEvent(new Event('cesium-ready')); return; }
    if (i >= window.__cesiumSources.length) {
        window.dispatchEvent(new CustomEvent('cesium-failed'));
        return;
    }
    var s = document.createElement('script');
    s.src = window.__cesiumSources[i];
    s.onload = function() { window.dispatchEvent(new Event('cesium-ready')); };
    s.onerror = function() { loadCesium(i + 1); };
    document.head.appendChild(s);
})(0);
</script>

<!-- Godseye React Application Bundle (injected after Cesium is ready — the
     bundle calls `new Cesium.Viewer(...)` at mount and needs the global first) -->
<link rel="stylesheet" href="<?= $BASE ?>/assets/index-CzhDypiF.css">
<script type="module" id="godseye-app" data-src="<?= $BASE ?>/assets/index-B-981eCE.js"></script>
<script>
window.addEventListener('cesium-ready', function bootApp() {
    window.removeEventListener('cesium-ready', bootApp);
    var app = document.getElementById('godseye-app');
    if (app && !app.src) { app.src = app.getAttribute('data-src'); }
});
</script>

<!-- Auto-hide loading spinner & error handling -->
<script>
    var godseyeMounted = false;
    var godseyeFailed = null;

    function hideLoading() {
        var loading = document.getElementById('godseye-loading');
        if (loading) {
            loading.style.opacity = '0';
            setTimeout(function() { loading.style.display = 'none'; }, 500);
        }
    }

    function showError(msg) {
        godseyeFailed = msg || godseyeFailed;
        var loading = document.getElementById('godseye-loading');
        var error = document.getElementById('godseye-error');
        if (loading) loading.style.display = 'none';
        if (error) {
            var msgEl = document.getElementById('godseye-error-msg');
            if (msgEl && godseyeFailed) msgEl.textContent = godseyeFailed;
            error.style.display = 'block';
        }
    }

    function maybeReady() {
        var root = document.getElementById('root');
        if (root && root.children && root.children.length > 0) {
            godseyeMounted = true;
            hideLoading();
            return true;
        }
        return false;
    }

    window.addEventListener('cesium-failed', function() {
        showError('Could not load CesiumJS from any CDN and no local /cesium/ build was found. Check your internet connection or add the Cesium build under /cesium/ and retry.');
    });

    // Poll for React mount; only error on genuine timeout (25s) with a useful reason.
    var checks = 0;
    var checkInterval = setInterval(function() {
        if (maybeReady()) { clearInterval(checkInterval); return; }
        if (++checks >= 50) {
            clearInterval(checkInterval);
            if (!window.Cesium) {
                showError('CesiumJS failed to load — the 3D globe cannot start. Check connection/CDN access.');
            } else {
                showError('Dashboard took too long to load. The app bundle may have failed — check the browser console and API keys, then retry.');
            }
        }
    }, 500);

    // Error handling
    window.addEventListener('error', function(e) {
        console.error('Godseye error:', e.message);
    });

    window.addEventListener('unhandledrejection', function(e) {
        console.error('Godseye unhandled rejection:', e.reason);
    });

    // Module load error
    var appScript = document.querySelector('script[type="module"]');
    if (appScript) {
        appScript.addEventListener('error', function() {
            showError('Failed to load the application bundle. Please refresh the page.');
        });
    }
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
