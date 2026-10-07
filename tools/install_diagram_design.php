<?php
/**
 * Installer / doctor for the Diagram Design integration.
 *   php tools/install_diagram_design.php          # check + migrate audit log
 *   php tools/install_diagram_design.php --doctor # deep check (type refs + scaffold test)
 *
 * Upstream: https://github.com/The-Lincoln/diagram-design.git
 * Skill: external/diagram-design/skills/diagram-design/
 */
$ROOT = dirname(__DIR__);
foreach ([$ROOT . '/autoflows/app/services/DiagramDesign.php'] as $f) {
    if (is_file($f)) {
        require_once $f;
    }
}

echo "=== SCITBD Diagram Design integration check ===\n";
echo "Repo: https://github.com/The-Lincoln/diagram-design.git\n";
echo "Vendor: external/diagram-design\n\n";

foreach (['README.md', 'skills/diagram-design/SKILL.md', 'skills/diagram-design/assets/template.html', 'skills/diagram-design/references/style-guide.md'] as $vf) {
    echo (is_file($ROOT . '/external/diagram-design/' . $vf) ? '[ok] ' : '[..] ') . 'external/diagram-design/' . $vf . "\n";
}
echo "\n";

$st = class_exists('DiagramDesign') ? DiagramDesign::status() : ['ok' => false, 'types' => 0];
echo "Types  : " . ($st['types'] ?? 0) . "\nReady  : " . (!empty($st['ok']) ? 'YES' : 'NO') . "\n" . ($st['hint'] ?? '') . "\nOut    : " . ($st['out_dir'] ?? '?') . "\n\n";

if (in_array('--doctor', $argv ?? [])) {
    echo "--- scaffold + validate smoke test ---\n";
    $r = class_exists('DiagramDesign') ? DiagramDesign::scaffold('Doctor smoke test', 'flowchart', 'template', 'shared') : ['ok' => false];
    echo "scaffold: " . (!empty($r['ok']) ? 'OK (' . ($r['slug'] ?? '?') . ')' : ($r['error'] ?? '?')) . "\n";
    if (!empty($r['ok'])) {
        $v = DiagramDesign::validate($r['file']);
        echo "validate template: " . ($v['ok'] ? 'PASS' : 'FAIL') . ' ' . json_encode($v['checks'] ?? []) . "\n";
        @unlink($r['file']);
        @unlink(dirname($r['file']) . '/' . ($r['slug'] ?? '') . '.spec.json');
        echo "(smoke artifacts removed)\n";
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
        DiagramDesign::ensureTables($pdo);
        $n = $pdo->query("SELECT COUNT(*) FROM diagram_runs")->fetchColumn();
        echo "[ok] $label — diagram_runs ($n rows) ready\n";
    } catch (Throwable $e) {
        echo "[fail] $label — {$e->getMessage()}\n";
    }
}

echo "\nFiles:\n";
foreach ([
    'autoflows/app/services/DiagramDesign.php',
    'sccrm/services/DiagramDesignService.php',
    'ceo/diagram_design.py',
    'ceo/.agents/skills/diagram-design/SKILL.md',
    'ceo/agno_agents/skills/diagram-design/SKILL.md',
    'skills/skills/diagram-design/SKILL.md',
] as $f) {
    echo (is_file($ROOT . '/' . $f) ? '[ok] ' : '[MISSING] ') . $f . "\n";
}
echo "\nDone. Type reference first, density 4/10, static default.\n";
