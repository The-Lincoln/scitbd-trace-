<?php
// Trace → Lead bridge: trace any URL with OSINT intel, then auto-create a CRM lead
// (fires lead_created AutoFlows, but skips re-tracing since intel is stored).
@set_time_limit(120);
@ini_set('max_execution_time', '120');
if (session_status() === PHP_SESSION_NONE) { session_start(); }
$_sccrm_db_boot = require_once __DIR__ . '/../config/database.php';
if (($_sccrm_db_boot instanceof PDO) && (!isset($db) || !($db instanceof PDO))) { $db = $_sccrm_db_boot; }
require_once __DIR__ . '/autoflow_engine.php';
require_once __DIR__ . '/../leads/generator_functions.php';
autoflowEnsureTables($db);
ensureLeadOsintColumns($db);

$base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'], 2)), '/'); // .../sccrm

$url = trim($_REQUEST['url'] ?? '');
if ($url !== '') {
    // Normalize + validate before any output (safe redirect afterwards)
    if (!preg_match('#^https?://#i', $url)) { $url = 'https://' . $url; }
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Invalid URL.'];
        header('Location: ' . $base . '/autoflows/push_trace.php');
        exit;
    }
    try {
        $root = dirname(__DIR__, 2);
        if (file_exists($root . '/vendor/autoload.php')) { require_once $root . '/vendor/autoload.php'; }
        if (file_exists($root . '/trace/url_tracer.php')) { require_once $root . '/trace/url_tracer.php'; }
        if (!class_exists('OSINT\\URLTracer')) throw new Exception('Tracer engine unavailable.');
        $tracePdo = null;
        try {
            if (file_exists($root . '/data/osint.db')) {
                $tracePdo = new PDO('sqlite:' . $root . '/data/osint.db');
                $tracePdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            }
        } catch (Throwable $e) { $tracePdo = null; }
        $tracer = new OSINT\URLTracer($tracePdo);
        $res = $tracer->trace($url);
        if (!empty($res['error'])) throw new Exception($res['error']['message'] ?? 'Trace failed.');

        $host = parse_url($url, PHP_URL_HOST) ?: $url;
        $companyGuess = ucfirst(preg_replace('/\.(com|io|net|org|tech|bd)$/', '', explode('.', $host)[0] ?? $host)) . ' Inc';
        $techs = [];
        foreach ((array)($res['technology']['all_technologies'] ?? []) as $t) {
            $techs[] = is_array($t) ? ($t['technology'] ?? '') : (string)$t;
        }
        $techs = array_values(array_filter(array_unique($techs)));
        $seo = (int)($res['seo']['score'] ?? 0);
        $sec = (int)($res['security']['score'] ?? 0);
        $live = (int)($res['basic']['status_code'] ?? 0) > 0 && (int)($res['basic']['status_code'] ?? 500) < 400;
        $score = ($live ? 45 : 20) + ($seo >= 70 ? 10 : 0) + ($sec >= 70 ? 5 : 0);
        $intel = ['host' => $host, 'traced_at' => date('Y-m-d H:i:s'), 'via' => 'trace_push',
            'http_status' => $res['basic']['status_code'] ?? null, 'server' => $res['basic']['server'] ?? null,
            'technologies' => $techs, 'seo_score' => $seo, 'security_score' => $sec];
        $note = '[Trace Push] ' . ($res['content']['title'] ?? $host) . '. HTTP ' . ($intel['http_status'] ?? '?') . '.'
            . ($techs ? ' Tech: ' . implode(', ', array_slice($techs, 0, 8)) . '.' : '');
        $stmt = $db->prepare("INSERT INTO leads (company_name, first_name, last_name, email, position, source, status, priority, notes, budget, score, website, tech_stack, seo_score, security_score, osint_data, generated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,datetime('now'))");
        $stmt->execute([$companyGuess, 'Web', 'Prospect', 'info@' . $host, 'Website Owner', 'trace_push', 'new',
            $score >= 55 ? 'high' : 'medium', $note, 50000, min(100, $score), $url,
            implode(', ', array_slice($techs, 0, 10)), $seo, $sec, json_encode($intel)]);
        $leadId = (int)$db->lastInsertId();
        // Fire automations (enrich skips — intel already stored; task flow may fire)
        try { autoflowTrigger($db, 'lead_created', ['lead_id' => $leadId, 'source' => 'trace_push']); } catch (Throwable $e) { /* ignore */ }
        try {
            autoflowTrigger($db, 'trace_completed', ['url' => $url, 'subject' => 'Trace pushed to lead #' . $leadId,
                'seo_score' => $seo, 'security_score' => $sec, 'tech_stack' => implode(', ', array_slice($techs, 0, 8)), 'lead_id' => $leadId]);
        } catch (Throwable $e) { /* ignore */ }
        $_SESSION['flash'] = ['type' => 'success', 'message' => "Trace intel saved as lead #$leadId ($companyGuess)."];
        header('Location: ' . $base . '/leads/');
        exit;
    } catch (Throwable $e) {
        $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Trace push failed: ' . $e->getMessage()];
        header('Location: ' . $base . '/autoflows/push_trace.php');
        exit;
    }
}

require_once __DIR__ . '/../includes/header.php';
?>
<div class="page-title-area">
    <div>
        <h4><i class="fas fa-share-nodes me-2 text-info"></i>Trace → Lead</h4>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="<?= htmlspecialchars($SCCRM_BASE) ?>/index.php" class="text-decoration-none">Dashboard</a></li>
                <li class="breadcrumb-item"><a href="<?= htmlspecialchars($SCCRM_BASE) ?>/autoflows/" class="text-decoration-none">AutoFlows</a></li>
                <li class="breadcrumb-item active">Trace → Lead</li>
            </ol>
        </nav>
    </div>
</div>
<div class="card-crm">
    <div class="card-body">
        <p class="text-muted" style="font-size:14px;">Enter a prospect website. AutoFlow traces it with OSINT intel (tech stack, SEO/security scores) and creates a scored lead — then lead automations (follow-up tasks, logs) fire automatically.</p>
        <form method="GET" class="row g-2">
            <div class="col-md-10">
                <div class="input-group">
                    <span class="input-group-text bg-transparent"><i class="fas fa-link text-muted"></i></span>
                    <input type="url" name="url" class="form-control form-control-lg" placeholder="https://prospect-company.com" required>
                </div>
            </div>
            <div class="col-md-2"><button class="btn btn-info w-100 h-100"><i class="fas fa-bolt me-1"></i> Trace &amp; Create Lead</button></div>
        </form>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
