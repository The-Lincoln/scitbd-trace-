<?php
/**
 * Installer / doctor for the Evolution API integration.
 *   php tools/install_evolution_api.php          # check + migrate all DBs
 *   php tools/install_evolution_api.php --doctor # deep check (instances + state)
 *
 * Upstream: https://github.com/The-Lincoln/evolution-api.git
 * Self-host: docker run -p 8080:8080 --env-file .env evoapicloud/evolution-api:latest
 * Docs: https://docs.evolutionfoundation.com.br
 */
$ROOT = dirname(__DIR__);
foreach ([$ROOT . '/autoflows/app/services/EvolutionApi.php'] as $f) {
    if (is_file($f)) {
        require_once $f;
    }
}

echo "=== SCITBD Evolution API (WhatsApp) integration check ===\n";
echo "Repo: https://github.com/The-Lincoln/evolution-api.git\n";
echo "Vendor: external/evolution-api\n\n";

foreach (['README.md', 'docker-compose.yaml', '.env.example', 'AGENTS.md', 'src/api/routes/instance.router.ts', 'src/api/routes/sendMessage.router.ts'] as $vf) {
    echo (is_file($ROOT . '/external/evolution-api/' . $vf) ? '[ok] ' : '[..] ') . 'external/evolution-api/' . $vf . "\n";
}
echo "\n";

$st = class_exists('EvolutionApi') ? EvolutionApi::status() : ['ok' => false];
echo "Base   : " . ($st['base_url'] ?? '?') . "\nInstance: " . ($st['instance'] ?? '?') . "\n";
echo "Keys   : global " . (!empty($st['has_api_key']) ? 'SET' : 'MISSING') . ", instance-token " . (!empty($st['has_instance_token']) ? 'SET' : '(optional)') . "\n";
echo "State  : " . ($st['state'] ?? '?') . " (" . ($st['latency_ms'] ?? 0) . "ms)\n";
echo "Ready  : " . (!empty($st['ok']) ? 'YES' : 'NO') . "\n" . ($st['hint'] ?? '') . "\n\n";
if (empty($st['ok'])) {
    echo "BOOT:\n  docker run -p 8080:8080 --env-file .env evoapicloud/evolution-api:latest\n  # Manager UI → create instance 'scitbd' → scan QR → EVOLUTION_API_KEY + EVOLUTION_INSTANCE\n\n";
}

if (in_array('--doctor', $argv ?? [])) {
    echo "--- instances ---\n";
    echo json_encode(class_exists('EvolutionApi') ? EvolutionApi::fetchInstances(['timeout' => 8]) : ['ok' => false], JSON_PRETTY_PRINT) . "\n";
}

// Register this app as the instance webhook target:
//   php tools/install_evolution_api.php --register https://crm.example.com
foreach ($argv ?? [] as $i => $a) {
    if ($a === '--register' && isset($argv[$i + 1])) {
        $base = rtrim($argv[$i + 1], '/');
        echo "--- register inbound webhook ---\n";
        echo "Receiver: {$base}/sccrm/chat/evolution_webhook.php\n";
        require_once $ROOT . '/sccrm/services/EvolutionApiService.php';
        $r = class_exists('EvolutionApiService')
            ? EvolutionApiService::registerInbound($base)
            : ['ok' => false, 'error' => 'service missing'];
        echo json_encode($r, JSON_PRETTY_PRINT) . "\n";
        echo !empty($r['ok']) ? "Inbox: {$base}/sccrm/chat/whatsapp.php\n" : "Fix the error above, then retry.\n";
    }
}

$dbs = [
    'autoflows/storage/app.db' => $ROOT . '/autoflows/storage/app.db',
    'sccrm/db/scit_crm.db' => $ROOT . '/sccrm/db/scit_crm.db',
    'data/osint.db' => $ROOT . '/data/osint.db',
    'ceo/scitbd_ceo.db' => $ROOT . '/ceo/scitbd_ceo.db',
];
foreach ($dbs as $label => $path) {
    if (!is_file($path)) {
        echo "[skip] $label (missing — will be created on first boot)\n";
        continue;
    }
    try {
        $pdo = new PDO('sqlite:' . $path);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        EvolutionApi::ensureTables($pdo);
        $n = $pdo->query("SELECT COUNT(*) FROM evolution_runs")->fetchColumn();
        echo "[ok] $label — evolution_runs ($n rows) ready\n";
    } catch (Throwable $e) {
        echo "[fail] $label — {$e->getMessage()}\n";
    }
}

echo "\nFiles:\n";
foreach ([
    'autoflows/app/services/EvolutionApi.php',
    'sccrm/services/EvolutionApiService.php',
    'sccrm/ai/evolution_agent.php',
    'trace/EvolutionHook.php',
    'ceo/evolution_api.py',
    'ceo/.agents/skills/evolution-api/SKILL.md',
    'ceo/agno_agents/skills/evolution-api/SKILL.md',
    'skills/skills/evolution-api/SKILL.md',
] as $f) {
    echo (is_file($ROOT . '/' . $f) ? '[ok] ' : '[MISSING] ') . $f . "\n";
}
echo "\nDone. Status before sending; log every send; never gate CRM on WhatsApp.\n";
