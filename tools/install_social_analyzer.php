<?php
/**
 * Installer / doctor for the Social Analyzer integration.
 *   php tools/install_social_analyzer.php          # check + migrate all DBs
 *   php tools/install_social_analyzer.php --doctor # deep check (status + --list)
 *
 * Upstream: https://github.com/The-Lincoln/social-analyzer.git
 * (fork of https://github.com/qeeqbox/social-analyzer.git)
 * WebApp: cd external/social-analyzer && npm update && npm install && npm start
 *         → http://0.0.0.0:9005/app.html
 * CLI:    pip3 install -r requirements.txt && python3 app.py --username "johndoe" --output json
 */
$ROOT = dirname(__DIR__);
foreach ([$ROOT . '/autoflows/app/services/SocialAnalyzer.php'] as $f) {
    if (is_file($f)) {
        require_once $f;
    }
}

echo "=== SCITBD Social Analyzer (username → 1000+ sites) integration check ===\n";
echo "Repo: https://github.com/The-Lincoln/social-analyzer.git\n";
echo "Vendor: external/social-analyzer\n\n";

foreach (['README.md', 'app.py', 'app.js', 'package.json', 'requirements.txt', 'data/', 'modules/', 'public/app.html'] as $vf) {
    $p = $ROOT . '/external/social-analyzer/' . $vf;
    echo (is_file($p) || is_dir($p) ? '[ok] ' : '[..] ') . 'external/social-analyzer/' . $vf . "\n";
}
echo "\n";

$st = class_exists('SocialAnalyzer') ? SocialAnalyzer::status() : ['ok' => false];
echo "Mode   : " . ($st['mode'] ?? '?') . "\n";
echo "Python : " . ($st['python'] ?? '?') . "\n";
echo "Node   : " . ($st['node'] ?? '?') . "\n";
echo "WebApp : " . ($st['base_url'] ?? '?') . ' ' . (!empty($st['webapp_up']) ? 'UP (' . ($st['webapp_ms'] ?? 0) . 'ms)' : 'DOWN') . "\n";
echo "Files  : " . ($st['files_present'] ?? '?') . "\n";
echo "Ready  : " . (!empty($st['ok']) ? 'YES' : 'NO') . "\n";
if (!empty($st['missing'])) {
    echo "Missing: " . $st['missing'] . "\n";
}
echo ($st['hint'] ?? '') . "\n\n";
if (empty($st['ok'])) {
    echo "BOOT:\n  git clone https://github.com/The-Lincoln/social-analyzer.git external/social-analyzer\n  cd external/social-analyzer && pip3 install -r requirements.txt\n  # webapp (optional): npm update && npm install && npm start\n\n";
}

if (in_array('--doctor', $argv ?? [])) {
    echo "--- sites ---\n";
    $ls = class_exists('SocialAnalyzer') ? SocialAnalyzer::listSites(['timeout' => 15]) : ['ok' => false];
    echo mb_substr($ls['raw'] ?? $ls['error'] ?? '?', 0, 1200) . "\n";
    echo "--- self-test (top 10, fast) ---\n";
    echo "Run: python3 external/social-analyzer/app.py --username \"johndoe\" --top 10 --output json\n";
    echo "(skipped by default — uncomment to run live)\n";
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
        SocialAnalyzer::ensureTables($pdo);
        $n = $pdo->query("SELECT COUNT(*) FROM socialanalyzer_runs")->fetchColumn();
        echo "[ok] $label — socialanalyzer_runs ($n rows) ready\n";
    } catch (Throwable $e) {
        echo "[fail] $label — {$e->getMessage()}\n";
    }
}

echo "\nFiles:\n";
foreach ([
    'autoflows/app/services/SocialAnalyzer.php',
    'sccrm/services/SocialAnalyzerService.php',
    'trace/SocialAnalyzerHook.php',
    'api/social-analyzer.php',
    'social-analyzer.php',
    'ceo/social_analyzer.py',
    'skills/skills/social-analyzer/SKILL.md',
] as $f) {
    echo (is_file($ROOT . '/' . $f) ? '[ok] ' : '[MISSING] ') . $f . "\n";
}
echo "\nDone. Analyze via SocialAnalyzer::analyze('johndoe', ['top'=>100]).\n";
