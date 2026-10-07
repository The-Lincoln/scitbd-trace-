<?php
/**
 * Installer / doctor for the ToolJet integration.
 *   php tools/install_tooljet.php          # check + migrate all DBs
 *   php tools/install_tooljet.php --doctor # deep check (health + apps)
 *
 * Upstream: https://github.com/ToolJet/ToolJet.git
 * Self-host: docker run -p 80:80 tooljet/try:ee-lts-latest (docs.tooljet.com/docs/setup/)
 */
$ROOT = dirname(__DIR__);
foreach ([$ROOT . '/autoflows/app/services/ToolJet.php'] as $f) {
    if (is_file($f)) {
        require_once $f;
    }
}

echo "=== SCITBD ToolJet integration check ===\n";
echo "Repo: https://github.com/ToolJet/ToolJet.git\n";
echo "Vendor: external/tooljet\n\n";

echo (is_dir($ROOT . '/external/tooljet') ? '[ok] ' : '[CLONING] ') . "external/tooljet\n";
foreach (['README.md', 'docker-compose.yaml', '.env.example', 'AGENTS.md'] as $vf) {
    echo (is_file($ROOT . '/external/tooljet/' . $vf) ? '[ok] ' : '[..] ') . 'external/tooljet/' . $vf . "\n";
}
echo "\n";

$st = class_exists('ToolJet') ? ToolJet::status() : ['ok' => false];
echo "Host   : " . ($st['host'] ?? '?') . "\n";
echo "Token  : " . (!empty($st['has_api_token']) ? 'SET' : 'MISSING (Profile → API tokens → TOOLJET_API_TOKEN)') . "\n";
echo "Ready  : " . (!empty($st['ok']) ? 'YES' : 'NO') . " (" . ($st['latency_ms'] ?? 0) . "ms)\n";
echo ($st['hint'] ?? '') . "\n\n";
if (empty($st['ok'])) {
    echo "BOOT:\n  docker run --name tooljet --restart unless-stopped -p 80:80 tooljet/try:ee-lts-latest\n  # open TOOLJET_HOST, build CEO/SCCRM/Trace apps, set slugs + webhook URLs\n\n";
}

if (in_array('--doctor', $argv ?? [])) {
    echo "--- health ---\n";
    echo json_encode(class_exists('ToolJet') ? ToolJet::health(['timeout' => 8]) : ['ok' => false], JSON_PRETTY_PRINT) . "\n";
    if (!empty($st['has_api_token'])) {
        echo "--- apps ---\n";
        echo json_encode(ToolJet::listApps(['timeout' => 8]), JSON_PRETTY_PRINT) . "\n";
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
        ToolJet::ensureTables($pdo);
        $n = $pdo->query("SELECT COUNT(*) FROM tooljet_runs")->fetchColumn();
        echo "[ok] $label — tooljet_runs ($n rows) ready\n";
    } catch (Throwable $e) {
        echo "[fail] $label — {$e->getMessage()}\n";
    }
}

echo "\nFiles:\n";
foreach ([
    'autoflows/app/services/ToolJet.php',
    'sccrm/services/ToolJetService.php',
    'sccrm/ai/tooljet_agent.php',
    'sccrm/dashboards/tooljet.php',
    'trace/ToolJetHook.php',
    'ceo/tooljet.py',
    'ceo/.agents/skills/tooljet/SKILL.md',
    'ceo/agno_agents/skills/tooljet/SKILL.md',
    'skills/skills/tooljet/SKILL.md',
] as $f) {
    echo (is_file($ROOT . '/' . $f) ? '[ok] ' : '[MISSING] ') . $f . "\n";
}
echo "\nDone. Embed via ToolJet::embedUrl(), fan out via triggerWorkflow().\n";
