<?php
/**
 * SCCRM — Evolution inbound webhook receiver (WhatsApp → CRM).
 *
 * Point the Evolution instance here:
 *   POST /webhook/set/sc​itbd  {webhook:{enabled:true, url:"https://<host>/sccrm/chat/evolution_webhook.php?secret=…", events:[MESSAGES_UPSERT,…]}}
 * or: php tools/install_evolution_api.php --register https://<host>
 *
 * Security: no session (Evolution can't send cookies). Guarded by shared
 * secret — ?secret=… or X-Webhook-Secret header vs EVOLUTION_WEBHOOK_SECRET.
 * Fail-closed when a secret is configured, fail-open (logged) when none is.
 * Always answers 200 quickly (Evolution retries on 5xx/timeout).
 *
 * Stores into whatsapp_messages (idempotent on message id) in sccrm/db/scit_crm.db.
 */
header('Content-Type: application/json; charset=utf-8');

$ROOT = dirname(__DIR__, 2);
require_once $ROOT . '/autoflows/app/services/EvolutionApi.php';

function whOut(array $d, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($d);
    exit;
}

$raw = file_get_contents('php://input');
$in = json_decode($raw ?: '', true);
if (!is_array($in)) {
    // Evolution sends JSON; log anything else and ack to stop retries.
    $in = ['event' => 'UNKNOWN', '_raw' => mb_substr((string)$raw, 0, 500)];
}

// --- secret guard (timing-safe) ---
$want = (string)(getenv('EVOLUTION_WEBHOOK_SECRET') ?: '');
$got = (string)($_GET['secret'] ?? '');
if ($got === '') {
    foreach (['HTTP_X_WEBHOOK_SECRET', 'HTTP_X_EVOLUTION_SECRET'] as $hk) {
        if (!empty($_SERVER[$hk])) {
            $got = (string)$_SERVER[$hk];
            break;
        }
    }
}
if ($want !== '' && ($got === '' || !hash_equals($want, $got))) {
    whOut(['ok' => false, 'error' => 'bad secret'], 403);
}

$parsed = EvolutionApi::parseInbound($in);

try {
    $db = new PDO('sqlite:' . $ROOT . '/sccrm/db/scit_crm.db');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec("CREATE TABLE IF NOT EXISTS whatsapp_messages (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        instance TEXT NOT NULL DEFAULT '',
        message_id TEXT NOT NULL DEFAULT '',
        phone TEXT NOT NULL DEFAULT '',
        push_name TEXT NOT NULL DEFAULT '',
        direction TEXT NOT NULL DEFAULT 'in',
        body TEXT NOT NULL DEFAULT '',
        event TEXT NOT NULL DEFAULT '',
        ts INTEGER NOT NULL DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE(instance, message_id)
    )");
    $db->exec("CREATE TABLE IF NOT EXISTS evolution_runs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        module TEXT NOT NULL DEFAULT 'shared',
        kind TEXT NOT NULL DEFAULT 'webhook',
        target TEXT NOT NULL DEFAULT '',
        ok INTEGER NOT NULL DEFAULT 0,
        ms INTEGER NOT NULL DEFAULT 0,
        output_excerpt TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
} catch (Throwable $e) {
    whOut(['ok' => false, 'error' => 'db unavailable'], 500);
}

$stored = 0;
try {
    if (!empty($parsed['messages'])) {
        $st = $db->prepare("INSERT OR IGNORE INTO whatsapp_messages (instance, message_id, phone, push_name, direction, body, event, ts) VALUES (?,?,?,?,?,?,?,?)");
        foreach ($parsed['messages'] as $m) {
            if ($m['id'] === '' && $m['text'] === '') {
                continue;
            }
            $st->execute([
                $parsed['instance'], $m['id'], $m['phone'], $m['push_name'],
                $m['from_me'] ? 'out' : 'in', $m['text'], $parsed['event'], $m['timestamp'],
            ]);
            $stored += $st->rowCount();
        }
    }
    if ($parsed['state'] !== null) {
        $db->prepare("INSERT INTO evolution_runs (module, kind, target, ok, ms, output_excerpt) VALUES (?,?,?,?,?,?)")
            ->execute(['sccrm', 'webhook:' . $parsed['event'], $parsed['instance'], 1, 0, mb_substr($parsed['state'], 0, 300)]);
    }
} catch (Throwable $e) {
    whOut(['ok' => false, 'error' => 'store failed'], 500);
}

whOut(['ok' => true, 'event' => $parsed['event'], 'stored' => $stored]);
