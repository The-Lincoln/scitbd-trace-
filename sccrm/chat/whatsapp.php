<?php
// SCCRM > Chat — WhatsApp inbox (Evolution API inbound + replies).
// Inbound lands here via sccrm/chat/evolution_webhook.php (instance webhook).
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../includes/header.php';

$osintRoot = dirname(__DIR__, 2);
foreach ([
    $osintRoot . '/autoflows/app/services/EvolutionApi.php',
    $osintRoot . '/sccrm/services/EvolutionApiService.php',
] as $f) {
    if (is_file($f)) {
        require_once $f;
    }
}

$flash = null;
$qr = null;
$status = class_exists('EvolutionApi') ? EvolutionApi::status() : ['ok' => false, 'state' => 'wrapper missing'];

// Outbound reply (same page, POST).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reply_to'], $_POST['reply_text'])) {
    $to = EvolutionApi::normalizeNumber((string)$_POST['reply_to']);
    $text = trim((string)$_POST['reply_text']);
    if ($to !== '' && $text !== '') {
        $r = EvolutionApi::sendText($to, $text);
        try {
            EvolutionApi::logRun($db, 'sccrm', 'sendText-inbox', $to, !empty($r['ok']), (int)($r['ms'] ?? 0), mb_substr($text, 0, 300));
        } catch (Throwable $e) {
        }
        $flash = !empty($r['ok']) ? ['ok' => true, 'msg' => 'Sent to +' . $to] : ['ok' => false, 'msg' => 'Send failed: ' . ($r['error'] ?? '?')];
    } else {
        $flash = ['ok' => false, 'msg' => 'Phone + message required.'];
    }
}

// QR on demand (operator links the phone).
if (isset($_GET['qr']) && class_exists('EvolutionApi')) {
    $qr = EvolutionApi::connectQr();
}

// Inbox (latest 50, newest first).
$msgs = [];
try {
    $db->exec("CREATE TABLE IF NOT EXISTS whatsapp_messages (
        id INTEGER PRIMARY KEY AUTOINCREMENT, instance TEXT NOT NULL DEFAULT '',
        message_id TEXT NOT NULL DEFAULT '', phone TEXT NOT NULL DEFAULT '',
        push_name TEXT NOT NULL DEFAULT '', direction TEXT NOT NULL DEFAULT 'in',
        body TEXT NOT NULL DEFAULT '', event TEXT NOT NULL DEFAULT '',
        ts INTEGER NOT NULL DEFAULT 0, created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE(instance, message_id))");
    $msgs = $db->query("SELECT * FROM whatsapp_messages ORDER BY id DESC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
}
$proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$base = $proto . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
// CRM base = URL path of the sccrm root (works at /sccrm and /<sub>/sccrm).
$sn = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
$pos = strpos($sn, '/sccrm');
$crmBase = ($pos !== false) ? substr($sn, 0, $pos + 6) : rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '', 2)), '/');
$receiverUrl = $base . $crmBase . '/chat/evolution_webhook.php';
?>
<div class="page-title-area">
    <div>
        <h4><i class="fab fa-whatsapp me-2 text-success"></i>WhatsApp Inbox <small class="text-muted">Evolution API</small></h4>
        <nav aria-label="breadcrumb"><ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="<?= htmlspecialchars($SCCRM_BASE) ?>/index.php" class="text-decoration-none">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= htmlspecialchars($SCCRM_BASE) ?>/chat/index.php" class="text-decoration-none">Support</a></li>
            <li class="breadcrumb-item active">WhatsApp</li>
        </ol></nav>
    </div>
    <div class="d-flex gap-2 align-items-center">
        <span class="badge <?= !empty($status['ok']) ? 'bg-success' : 'bg-warning text-dark' ?>"><?= htmlspecialchars($status['state'] ?? 'offline') ?></span>
        <a href="?qr=1" class="btn btn-sm btn-outline-success rounded-pill"><i class="fas fa-qrcode me-1"></i> QR connect</a>
    </div>
</div>

<?php if ($flash): ?><div class="alert alert-<?= $flash['ok'] ? 'success' : 'danger' ?>"><?= htmlspecialchars($flash['msg']) ?></div><?php endif; ?>
<?php if (empty($status['ok'])): ?>
<div class="alert alert-warning"><i class="fas fa-exclamation-triangle me-1"></i>
    Instance <strong><?= htmlspecialchars($status['instance'] ?? '?') ?></strong> not linked (<?= htmlspecialchars($status['state'] ?? '?') ?>).
    <?= htmlspecialchars($status['hint'] ?? '') ?></div>
<?php endif; ?>

<?php if ($qr): ?>
<div class="card-crm mb-4"><div class="card-body" style="font-size:13px;">
    <h6><i class="fas fa-qrcode me-2"></i>Link phone</h6>
    <div class="trace-json"><?= htmlspecialchars(mb_substr(json_encode($qr['data'] ?? $qr, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), 0, 2000)) ?></div>
    <small class="text-muted">Scan the QR from the Manager UI or the base64 above with WhatsApp → Linked devices.</small>
</div></div>
<?php endif; ?>

<div class="row g-3 mb-4">
    <div class="col-xl-7"><div class="card-crm"><div class="card-header"><h6><i class="fas fa-inbox me-2 text-primary"></i>Inbox (<?= count($msgs) ?>)</h6></div>
        <div class="card-body p-0"><div class="table-responsive"><table class="table table-crm mb-0">
            <thead><tr><th></th><th>Contact</th><th>Message</th><th>Time</th></tr></thead><tbody>
            <?php foreach ($msgs as $m): ?>
            <tr style="font-size:12px;">
                <td><?= ($m['direction'] ?? 'in') === 'out' ? '<span class="badge bg-info">out</span>' : '<span class="badge bg-success">in</span>' ?></td>
                <td class="text-nowrap">+<?= htmlspecialchars($m['phone'] ?? '') ?><div class="text-muted small"><?= htmlspecialchars($m['push_name'] ?? '') ?></div></td>
                <td class="text-break"><?= htmlspecialchars(mb_substr($m['body'] ?? '', 0, 300)) ?></td>
                <td class="text-muted text-nowrap"><?= htmlspecialchars($m['created_at'] ?? '') ?></td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($msgs)): ?><tr><td colspan="4" class="text-center text-muted p-3">No messages yet — register the webhook below, then send a WhatsApp to the linked number.</td></tr><?php endif; ?>
            </tbody></table></div></div></div></div>
    <div class="col-xl-5">
        <div class="card-crm mb-3"><div class="card-header"><h6><i class="fas fa-reply me-2 text-success"></i>Reply</h6></div>
            <div class="card-body"><form method="POST" class="row g-2">
                <div class="col-12"><input name="reply_to" class="form-control" placeholder="Phone, e.g. 8801XXXXXXXXX" value="<?= htmlspecialchars($_POST['reply_to'] ?? ($msgs[0]['phone'] ?? '')) ?>" required></div>
                <div class="col-12"><textarea name="reply_text" class="form-control" rows="3" placeholder="Message…" required></textarea></div>
                <div class="col-12"><button class="btn btn-success w-100"><i class="fab fa-whatsapp me-1"></i> Send via WhatsApp</button></div>
            </form></div></div>
        <div class="card-crm"><div class="card-header"><h6><i class="fas fa-plug me-2 text-secondary"></i>Inbound webhook</h6></div>
            <div class="card-body" style="font-size:12px;">
                <div class="mb-1 text-muted">Receiver URL (register on the instance):</div>
                <code class="text-break"><?= htmlspecialchars($receiverUrl) ?></code>
                <div class="mt-2 text-muted">CLI: <code>php tools/install_evolution_api.php --register <?= htmlspecialchars($base . $crmBase) ?></code></div>
                <div class="mt-1 text-muted">Guarded by <code>EVOLUTION_WEBHOOK_SECRET</code> (?secret= or X-Webhook-Secret).</div>
            </div></div>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
