<?php
/**
 * Installer / doctor for the AI Support Ops integration.
 *   php tools/install_support_ops.php          # check + migrate triage table
 *   php tools/install_support_ops.php --doctor # live triage on latest ticket
 *
 * Pattern: https://github.com/rivannagrale/ai-support-operations.git
 * Port: sccrm/ai/ticket_triage_agent.php (TinyLLM + heuristic fallback).
 */
$ROOT = dirname(__DIR__);
foreach ([$ROOT . '/sccrm/ai/ticket_triage_agent.php'] as $f) {
    if (is_file($f)) {
        require_once $f;
    }
}

echo "=== SCITBD AI Support Ops integration check ===\n";
echo "Pattern: https://github.com/rivannagrale/ai-support-operations.git\n";
echo "Vendor: external/ai-support-operations\n\n";

foreach (['README.md', 'server.js', 'knowledge-base.json', 'package.json'] as $vf) {
    echo (is_file($ROOT . '/external/ai-support-operations/' . $vf) ? '[ok] ' : '[..] ') . 'external/ai-support-operations/' . $vf . "\n";
}
foreach (['sccrm/ai/ticket_triage_agent.php', 'sccrm/chat/create.php', 'sccrm/chat/view.php', 'sccrm/chat/index.php'] as $f) {
    echo (is_file($ROOT . '/' . $f) ? '[ok] ' : '[MISSING] ') . $f . "\n";
}
echo "\nCategories: " . implode(' | ', ticketTriageCategories()) . "\n\n";

$dbs = ['sccrm/db/scit_crm.db' => $ROOT . '/sccrm/db/scit_crm.db'];
foreach ($dbs as $label => $path) {
    if (!is_file($path)) {
        echo "[skip] $label\n";
        continue;
    }
    try {
        $pdo = new PDO('sqlite:' . $path);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        ticketTriageEnsureTable($pdo);
        $n = $pdo->query("SELECT COUNT(*) FROM ticket_triage")->fetchColumn();
        $q = $pdo->query("SELECT COUNT(*) FROM ticket_triage tr JOIN support_tickets t ON t.id = tr.ticket_id WHERE tr.escalate = 1 AND t.status NOT IN ('resolved','closed')")->fetchColumn();
        echo "[ok] $label — ticket_triage ($n rows, $q awaiting human)\n";
    } catch (Throwable $e) {
        echo "[fail] $label — {$e->getMessage()}\n";
    }
}

if (in_array('--doctor', $argv ?? [])) {
    echo "\n--- live triage smoke ---\n";
    try {
        $db = new PDO('sqlite:' . $ROOT . '/sccrm/db/scit_crm.db');
        $tid = $db->query("SELECT id FROM support_tickets ORDER BY id DESC LIMIT 1")->fetchColumn();
        if ($tid) {
            $r = ticketTriage($db, $tid, true);
            echo "ticket #{$tid}: {$r['category']} ({$r['confidence']}%) escalate=" . ($r['escalate'] ? 'YES' : 'no') . " [{$r['provider']}] :: {$r['reason']}\n";
        } else {
            echo "(no tickets — create one in sccrm/chat/ first)\n";
        }
    } catch (Throwable $e) {
        echo "DB: {$e->getMessage()}\n";
    }
}
echo "\nDone. Triage routes, humans decide.\n";
