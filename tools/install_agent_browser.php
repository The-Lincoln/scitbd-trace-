<?php
/**
 * Installer / doctor for the agent-browser integration.
 *   php tools/install_agent_browser.php          # check + migrate all DBs
 *   php tools/install_agent_browser.php --doctor # deep check (doctor --fix hint)
 */
$ROOT = dirname(__DIR__);
$AF = $ROOT . '/autoflows';
foreach ([$AF . '/app/services/AgentBrowser.php'] as $f) {
    if (is_file($f)) {
        require_once $f;
    }
}

echo "=== SCITBD agent-browser integration check ===\n";
echo "Repo: https://github.com/vercel-labs/agent-browser.git\n\n";

$st = AgentBrowser::status('shared');
echo "Binary : {$st['bin']}\nVersion: {$st['version']}\nReady  : " . ($st['ok'] ? 'YES' : 'NO') . "\nShots  : {$st['shot_dir']}\n\n";
if (!$st['ok']) {
    echo "INSTALL:\n  npm install -g agent-browser\n  agent-browser install\n  agent-browser install --with-deps   # Linux system libs\n\n";
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
        AgentBrowser::ensureTables($pdo);
        $n = $pdo->query("SELECT COUNT(*) FROM agent_browser_runs")->fetchColumn();
        echo "[ok] $label — agent_browser_runs ($n rows) + browser_monitors ready\n";
    } catch (Throwable $e) {
        echo "[fail] $label — {$e->getMessage()}\n";
    }
}

echo "\nFiles:\n";
$files = [
    'autoflows/app/services/AgentBrowser.php',
    'autoflows/app/services/BrowserAgent.php',
    'autoflows/app/controllers/BrowserController.php',
    'autoflows/app/views/browser/index.php',
    'autoflows/tools/run_browser_daily.php',
    'sccrm/services/AgentBrowserService.php',
    'sccrm/autoflows/browser_actions.php',
    'sccrm/ai/browser_skill.php',
    'sccrm/trace/agent_browser.php',
    'trace/AgentBrowserTracer.php',
    'trace/agent_browser_trace.php',
    'ceo/agent_browser.py',
    'ceo/agent_browser_api.php',
    'ceo/browser_autoflow.py',
];
foreach ($files as $f) {
    echo (is_file($ROOT . '/' . $f) ? '[ok] ' : '[MISSING] ') . $f . "\n";
}
echo "\nDone. See docs/AGENT_BROWSER_INTEGRATION.md\n";
