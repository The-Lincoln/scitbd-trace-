<?php
/**
 * Social Analyzer — username → 1000+ social sites (Web UI)
 * Uses vendored external/social-analyzer via autoflows/app/services/SocialAnalyzer.php
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/autoflows/app/services/SocialAnalyzer.php';

$username = trim($_GET['username'] ?? $_POST['username'] ?? '');
$mode = $_GET['mode'] ?? $_POST['mode'] ?? 'fast';
$top = (int)($_GET['top'] ?? $_POST['top'] ?? 0);
$filter = $_GET['filter'] ?? $_POST['filter'] ?? '';
$metadata = !empty($_GET['metadata'] ?? $_POST['metadata'] ?? '');

$result = null;
$error = '';
$status = SocialAnalyzer::status();

if ($username !== '' && isset($_REQUEST['run'])) {
    $opts = ['mode' => $mode, 'metadata' => $metadata];
    if ($top > 0) {
        $opts['top'] = $top;
    }
    if ($filter !== '') {
        $opts['filter'] = $filter;
    }
    $result = SocialAnalyzer::analyze($username, $opts);
    if (empty($result['ok'])) {
        $error = $result['error'] ?? 'analysis failed';
    } else {
        try {
            $pdo = db();
            SocialAnalyzer::logRun($pdo, 'web', 'analyze-' . $mode, mb_substr($username, 0, 200), true, (int)($result['ms'] ?? 0), mb_substr(json_encode($result['summary'] ?? []), 0, 500));
        } catch (Throwable $e) {
        }
    }
}

$PAGE_TITLE = 'Social Analyzer';
$PAGE_DESC = 'Find a person\'s profile across 1000+ social media websites';
include __DIR__ . '/includes/header.php';
?>

<div class="row g-3">
  <section class="col-lg-12">
    <div class="hero-section mb-4">
      <h1 class="display-5"><i class="bi bi-person-search"></i> Social Analyzer</h1>
      <p class="lead text-muted">API, CLI &amp; Web App for analyzing &amp; finding a person's profile across 1000+ social media websites. Detection rating 0–100 (No–Maybe–Yes).</p>
      <div class="quick-stats">
        <span class="badge bg-<?= !empty($status['ok']) ? 'success' : 'danger' ?> fs-6"><?= !empty($status['ok']) ? 'Vendored' : 'Missing' ?>: external/social-analyzer</span>
        <span class="badge bg-info fs-6"><?= h($status['files_present'] ?? '?') ?> files</span>
        <span class="badge bg-<?= !empty($status['webapp_up']) ? 'success' : 'secondary' ?> fs-6">WebApp <?= !empty($status['webapp_up']) ? 'UP' : 'DOWN' ?> · :9005</span>
        <span class="badge bg-primary fs-6">Python: <?= h(basename($status['python'] ?? '?')) ?></span>
      </div>
      <?php if (!empty($status['missing'])): ?>
        <div class="alert alert-warning mt-3"><i class="fas fa-triangle-exclamation"></i> <?= h($status['missing']) ?>
          <br><code>git clone https://github.com/The-Lincoln/social-analyzer.git external/social-analyzer</code>
          <br><code>cd external/social-analyzer &amp;&amp; pip3 install -r requirements.txt</code>
          <br>WebApp (optional): <code>npm update &amp;&amp; npm install &amp;&amp; npm start</code> → <code><?= h($status['base_url'] ?? '') ?>/app.html</code>
        </div>
      <?php endif; ?>
    </div>

    <div class="card mb-4">
      <div class="card-header"><i class="fas fa-user-magnifying-glass"></i> Analyze a username</div>
      <div class="card-body">
        <form method="GET" class="row g-2 align-items-end">
          <div class="col-md-4">
            <label class="form-label" for="username">Username (comma = multi-profile correlation)</label>
            <input type="text" class="form-control form-control-lg" id="username" name="username" value="<?= h($username) ?>" placeholder="johndoe or johndoe,janedoe" required>
          </div>
          <div class="col-md-2">
            <label class="form-label" for="mode">Mode</label>
            <select class="form-select" id="mode" name="mode">
              <?php foreach (['fast' => 'fast — FindUserProfilesFast', 'slow' => 'slow — FindUserProfilesSlow', 'special' => 'special — FB/Gmail/Google'] as $k => $label): ?>
                <option value="<?= $k ?>" <?= $mode === $k ? 'selected' : '' ?>><?= $label ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-2">
            <label class="form-label" for="top">Top sites (0 = all)</label>
            <input type="number" class="form-control" id="top" name="top" value="<?= (int)$top ?>" min="0" max="1000" placeholder="e.g. 100">
          </div>
          <div class="col-md-2">
            <label class="form-label" for="filter">Filter</label>
            <select class="form-select" id="filter" name="filter">
              <?php foreach (['' => 'all', 'good' => 'good', 'maybe' => 'maybe', 'bad' => 'bad', 'good,maybe' => 'good+maybe'] as $k => $label): ?>
                <option value="<?= h($k) ?>" <?= $filter === $k ? 'selected' : '' ?>><?= h($label) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-2">
            <div class="form-check mb-2">
              <input class="form-check-input" type="checkbox" id="metadata" name="metadata" value="1" <?= $metadata ? 'checked' : '' ?>>
              <label class="form-check-label" for="metadata">Metadata + patterns</label>
            </div>
            <button class="btn btn-primary w-100" type="submit" name="run" value="1"><i class="fas fa-play"></i> Analyze</button>
          </div>
        </form>
        <div class="mt-2 d-flex flex-wrap gap-2">
          <small class="text-muted"><i class="fas fa-terminal"></i> CLI equivalent:</small>
          <code>python3 external/social-analyzer/app.py --username "<?= h($username !== '' ? $username : 'johndoe') ?>" --mode <?= h($mode) ?> --output json<?= $top > 0 ? ' --top ' . (int)$top : '' ?><?= $metadata ? ' --metadata' : '' ?></code>
        </div>
        <div class="mt-2 d-flex flex-wrap gap-2">
          <a class="btn btn-sm btn-outline-secondary" href="<?= h($status['base_url'] ?? 'http://127.0.0.1:9005') ?>/app.html" target="_blank" rel="noopener"><i class="fas fa-window-maximize"></i> Open upstream WebApp (:9005/app.html)</a>
          <a class="btn btn-sm btn-outline-secondary" href="api/social-analyzer.php?status=1" target="_blank" rel="noopener"><i class="fas fa-heart-pulse"></i> JSON status</a>
          <?php if ($username !== ''): ?>
            <a class="btn btn-sm btn-outline-secondary" href="api/social-analyzer.php?username=<?= urlencode($username) ?>&mode=<?= urlencode($mode) ?><?= $top > 0 ? '&top=' . (int)$top : '' ?>" target="_blank" rel="noopener"><i class="fas fa-code"></i> JSON result</a>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <?php if ($error !== ''): ?>
      <div class="alert alert-danger"><i class="fas fa-circle-exclamation"></i> <?= h($error) ?></div>
    <?php endif; ?>

    <?php if ($result && !empty($result['ok'])): ?>
      <div class="alert alert-success">
        <i class="fas fa-check-circle"></i>
        Found <strong><?= (int)($result['summary']['detected'] ?? 0) ?></strong> likely profiles
        out of <strong><?= (int)($result['summary']['total'] ?? 0) ?></strong> checked
        for <em>"<?= h($result['username']) ?>"</em>
        <span class="text-muted">(<?= (int)($result['ms'] ?? 0) ?> ms)</span>
      </div>
      <div class="row g-3" id="sa-results">
        <?php foreach ($result['profiles'] as $p):
          $site = (string)($p['site'] ?? $p['website'] ?? 'unknown');
          $link = (string)($p['link'] ?? $p['url'] ?? '');
          $rate = (int)($p['rate'] ?? $p['score'] ?? 0);
          $cls = $rate >= 70 ? 'success' : ($rate >= 30 ? 'warning' : 'secondary');
          $title = (string)($p['title'] ?? '');
          $text = (string)($p['text'] ?? $p['description'] ?? '');
        ?>
          <div class="col-md-6 col-lg-4">
            <div class="card h-100">
              <div class="card-body">
                <div class="d-flex justify-content-between align-items-start mb-2">
                  <strong><?= h($site) ?></strong>
                  <span class="badge bg-<?= $cls ?>"><?= (int)$rate ?>/100</span>
                </div>
                <?php if ($title !== ''): ?><div class="small fw-bold"><?= h(mb_substr($title, 0, 120)) ?></div><?php endif; ?>
                <?php if ($text !== ''): ?><div class="small text-muted"><?= h(mb_substr($text, 0, 220)) ?></div><?php endif; ?>
                <?php if ($link !== ''): ?>
                  <a href="<?= h($link) ?>" target="_blank" rel="noopener" class="btn btn-sm btn-outline-primary mt-2">Open profile <i class="fas fa-external-link-alt"></i></a>
                <?php endif; ?>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php elseif ($username !== '' && isset($_REQUEST['run'])): ?>
      <div class="alert alert-info">Analysis ran but returned no parseable profiles — try mode <code>fast</code> + <code>--top 10</code> first, or check <code>pip3 install -r external/social-analyzer/requirements.txt</code>.</div>
    <?php else: ?>
      <div class="row g-3 mb-4">
        <div class="col-md-4"><div class="card h-100"><div class="card-body text-center"><div class="display-4 text-primary"><i class="fas fa-users"></i></div><h5>1000+ sites</h5><p class="text-muted small">Username permutations, multi-layer detection (OCR/normal/advanced/special), top-ranking &amp; country filters.</p></div></div></div>
        <div class="col-md-4"><div class="card h-100"><div class="card-body text-center"><div class="display-4 text-success"><i class="fas fa-gauge-high"></i></div><h5>Rating 0–100</h5><p class="text-muted small">No–Maybe–Yes rating per detection technique. Fewer false positives. Filter by good/maybe/bad.</p></div></div></div>
        <div class="col-md-4"><div class="card h-100"><div class="card-body text-center"><div class="display-4 text-info"><i class="fas fa-diagram-project"></i></div><h5>Metadata + graphs</h5><p class="text-muted small">Ixora metadata &amp; patterns, force-directed graph, screenshots, Google/DuckDuckGo lookups.</p></div></div></div>
      </div>
    <?php endif; ?>
  </section>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
