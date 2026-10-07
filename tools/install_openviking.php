<?php
/**
 * Installer / doctor for the OpenViking integration.
 *   php tools/install_openviking.php          # check + migrate all DBs
 *   php tools/install_openviking.php --doctor # deep check (ov status + ls)
 *
 * Upstream: https://github.com/The-Lincoln/OpenViking.git
 * Install: curl -fsSL https://openviking.ai/install | bash -s -- --yes --url <SERVER_URL>
 * Docs: https://docs.openviking.ai
 */
$ROOT = dirname(__DIR__);
foreach ([$ROOT . '/autoflows/app/services/OpenViking.php'] as $f) {
    if (is_file($f)) {
        require_once $f;
    }
}

echo "=== SCITBD OpenViking (viking:// context) integration check ===\n";
echo "Repo: https://github.com/The-Lincoln/OpenViking.git\n";
echo "Vendor: external/openviking\n\n";

foreach (['README.md', 'pyproject.toml', 'docker-compose.yml', 'sdk/python/README.md', 'crates/ov_cli/README.md', 'docs/'] as $vf) {
    $p = $ROOT . '/external/openviking/' . $vf;
    echo (is_file($p) || is_dir($p) ? '[ok] ' : '[..] ') . 'external/openviking/' . $vf . "\n";
}
echo "\n";

$st = class_exists('OpenViking') ? OpenViking::status() : ['ok' => false];
echo "Binary : " . ($st['bin'] ?? '?') . "\nServer : " . ($st['server_url'] ?? '?') . "\nKey    : " . (!empty($st['has_api_key']) ? 'SET (user key)' : 'MISSING (user key — root keys cannot touch memories)') . "\n";
echo "Ready  : " . (!empty($st['ok']) ? 'YES' : 'NO') . " (" . ($st['ms'] ?? 0) . "ms)\n" . ($st['detail'] ?? '') . "\n" . ($st['hint'] ?? '') . "\n\n";
if (empty($st['ok'])) {
    echo "BOOT:\n  curl -fsSL https://openviking.ai/install | bash -s -- --yes --url <SERVER_URL>\n  # set OPENVIKING_URL + OPENVIKING_API_KEY (user key), then re-run --doctor\n\n";
}

if (in_array('--doctor', $argv ?? [])) {
    echo "--- viking:// root ---\n";
    $ls = class_exists('OpenViking') ? OpenViking::ls('viking://', ['timeout' => 15]) : ['ok' => false];
    echo mb_substr($ls['text'] ?? $ls['error'] ?? '?', 0, 800) . "\n";
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
        OpenViking::ensureTables($pdo);
        $n = $pdo->query("SELECT COUNT(*) FROM openviking_runs")->fetchColumn();
        echo "[ok] $label — openviking_runs ($n rows) ready\n";
    } catch (Throwable $e) {
        echo "[fail] $label — {$e->getMessage()}\n";
    }
}

echo "\nFiles:\n";
foreach ([
    'autoflows/app/services/OpenViking.php',
    'sccrm/services/OpenVikingService.php',
    'sccrm/ai/openviking_agent.php',
    'trace/OpenVikingHook.php',
    'ceo/openviking.py',
    'ceo/.agents/skills/openviking/SKILL.md',
    'ceo/agno_agents/skills/openviking/SKILL.md',
    'skills/skills/openviking/SKILL.md',
] as $f) {
    echo (is_file($ROOT . '/' . $f) ? '[ok] ' : '[MISSING] ') . $f . "\n";
}
echo "\nDone. Browse with ls/tree, retrieve scoped with find/grep.\n";
