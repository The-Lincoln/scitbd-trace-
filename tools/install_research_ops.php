<?php
/**
 * Installer / doctor for Ticket AI + Harness Guide integrations.
 *   php tools/install_research_ops.php          # check vendors
 *   php tools/install_research_ops.php --doctor # live draft suggest + harness search
 *
 * Upstream:
 *   https://github.com/The-Lincoln/ai-response-generator.git (osTicket pattern)
 *   https://github.com/walkinglabs/awesome-harness-engineering.git
 *   https://github.com/ai-boost/awesome-harness-engineering.git
 */
$ROOT = dirname(__DIR__);
foreach ([
    $ROOT . '/autoflows/app/services/HarnessGuide.php',
    $ROOT . '/sccrm/ai/ticket_reply_agent.php',
] as $f) {
    if (is_file($f)) {
        require_once $f;
    }
}

echo "=== SCITBD Ticket AI + Harness Guide check ===\n\n";

foreach ([
    'external/ai-response-generator/README.md',
    'external/ai-response-generator/api/OpenAIClient.php',
    'external/ai-response-generator/src/AIAjax.php',
    'external/awesome-harness-walkinglabs/README.md',
    'external/awesome-harness-aiboost/README.md',
    'sccrm/ai/ticket_reply_agent.php',
    'sccrm/chat/view.php',
] as $vf) {
    echo (is_file($ROOT . '/' . $vf) ? '[ok] ' : '[..] ') . $vf . "\n";
}
echo "\n";

$st = class_exists('HarnessGuide') ? HarnessGuide::status() : ['ok' => false];
echo "Harness sources: " . implode(', ', $st['sources'] ?? []) . " ({$st['sections']} sections, {$st['links']} links)\n";
echo "Ticket AI: TinyLLM-first" . (function_exists('ticketReplyConfig') && ticketReplyConfig()['api_url'] !== '' ? ' + override ' . ticketReplyConfig()['api_url'] : ' (no override configured)') . "\n\n";

if (in_array('--doctor', $argv ?? [])) {
    echo "--- harness search smoke ---\n";
    $h = class_exists('HarnessGuide') ? HarnessGuide::search('sandbox daemon MCP', 3) : ['hits' => []];
    foreach ($h['hits'] ?? [] as $x) {
        echo "- [{$x['source']}] {$x['section']} :: {$x['title']}\n";
    }
    echo "--- ticket draft smoke (needs tickets table) ---\n";
    try {
        $db = new PDO('sqlite:' . $ROOT . '/sccrm/db/scit_crm.db');
        $tid = $db->query("SELECT id FROM support_tickets ORDER BY id DESC LIMIT 1")->fetchColumn();
        if ($tid) {
            $r = ticketReplySuggest($db, $tid);
            echo "ticket #{$tid}: " . (!empty($r['ok']) ? "DRAFT OK ({$r['provider']}, " . mb_strlen($r['draft']) . " chars)" : 'FAIL: ' . ($r['error'] ?? '?')) . "\n";
        } else {
            echo "(no tickets — create one in sccrm/chat/ first)\n";
        }
    } catch (Throwable $e) {
        echo "DB: {$e->getMessage()}\n";
    }
}
echo "\nDone. Drafts only — agents review and send.\n";
