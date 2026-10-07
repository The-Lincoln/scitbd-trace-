<?php
/**
 * Installer / doctor for the Tencent BrowserSkill integration.
 *   php tools/install_browserskill.php          # check + migrate all DBs
 *   php tools/install_browserskill.php --doctor # deep check (bsk doctor)
 *
 * Companion to tools/install_agent_browser.php (vercel driver).
 * Upstream: https://github.com/Tencent/BrowserSkill.git
 * Setup:    external/tencent-browserskill/AGENT_INSTALL.md
 */
$ROOT = dirname(__DIR__);
foreach ([$ROOT . '/autoflows/app/services/BrowserSkill.php'] as $f) {
    if (is_file($f)) {
        require_once $f;
    }
}

echo "=== SCITBD BrowserSkill (Tencent) integration check ===\n";
echo "Repo: https://github.com/Tencent/BrowserSkill.git\n";
echo "Vendor: external/tencent-browserskill\n\n";

$vendorSkill = $ROOT . '/external/tencent-browserskill/crates/bsk-cli/skill/SKILL.md';
echo (is_file($vendorSkill) ? '[ok] ' : '[MISSING] ') . 'external/tencent-browserskill/crates/bsk-cli/skill/SKILL.md' . "\n";
echo (is_file($ROOT . '/external/tencent-browserskill/AGENT_INSTALL.md') ? '[ok] ' : '[MISSING] ') . 'external/tencent-browserskill/AGENT_INSTALL.md' . "\n\n";

$st = class_exists('BrowserSkill') ? BrowserSkill::status('shared') : ['ok' => false, 'bin' => 'bsk', 'version' => 'missing'];
echo "Binary : {$st['bin']}\nVersion: {$st['version']}\nReady  : " . ($st['ok'] ? 'YES' : 'NO') . "\nShots  : {$st['shot_dir']}\n\n";
if (!$st['ok']) {
    echo "INSTALL (Windows PowerShell):\n  irm https://raw.githubusercontent.com/Tencent/BrowserSkill/main/install.ps1 | iex\n  bsk --version\n  bsk doctor\nINSTALL (macOS/Linux):\n  curl -fsSL https://raw.githubusercontent.com/Tencent/BrowserSkill/main/install.sh | sh\n  bsk --version\nTHEN: install Chrome/Edge extension + connect it (see AGENT_INSTALL.md Step 4).\n\n";
}

if (in_array('--doctor', $argv ?? [])) {
    $bin = $st['bin'];
    echo "--- bsk doctor ---\n";
    echo shell_exec(escapeshellarg($bin) . ' doctor 2>&1') . "\n";
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
        BrowserSkill::ensureTables($pdo);
        $n = $pdo->query("SELECT COUNT(*) FROM browserskill_runs")->fetchColumn();
        echo "[ok] $label — browserskill_runs ($n rows) ready\n";
    } catch (Throwable $e) {
        echo "[fail] $label — {$e->getMessage()}\n";
    }
}

echo "\nFiles:\n";
$files = [
    'external/tencent-browserskill/AGENT_INSTALL.md',
    'autoflows/app/services/BrowserSkill.php',
    'sccrm/services/BrowserSkillService.php',
    'sccrm/ai/browserskill_agent.php',
    'trace/BrowserSkillTracer.php',
    'trace/browserskill_trace.php',
    'ceo/browserskill.py',
    'ceo/browserskill_api.php',
    'ceo/.agents/skills/tencent-browserskill/SKILL.md',
    'ceo/agno_agents/skills/browserskill/SKILL.md',
    'skills/skills/tencent-browserskill/SKILL.md',
];
foreach ($files as $f) {
    echo (is_file($ROOT . '/' . $f) ? '[ok] ' : '[MISSING] ') . $f . "\n";
}
echo "\nDone. CEO engine BROWSER prefers bsk, falls back to agent-browser.\n";
