<?php
/**
 * Installer / doctor for the OpenMontage integration.
 *   php tools/install_openmontage.php          # check + migrate all DBs
 *   php tools/install_openmontage.php --doctor # deep check (registry probe)
 *
 * Upstream: https://github.com/calesthio/OpenMontage.git (AGPL-3.0)
 * Contract: external/openmontage/AGENT_GUIDE.md
 */
$ROOT = dirname(__DIR__);
foreach ([$ROOT . '/autoflows/app/services/OpenMontage.php'] as $f) {
    if (is_file($f)) {
        require_once $f;
    }
}

echo "=== SCITBD OpenMontage (agentic video) integration check ===\n";
echo "Repo: https://github.com/calesthio/OpenMontage.git\n";
echo "Vendor: external/openmontage\n\n";

foreach (['AGENT_GUIDE.md', 'PROJECT_CONTEXT.md', 'config.yaml', '.env.example', 'Makefile', 'pipeline_defs/animated-explainer.yaml', 'tools/base_tool.py', 'skills/'] as $vf) {
    $p = $ROOT . '/external/openmontage/' . $vf;
    echo (is_file($p) || is_dir($p) ? '[ok] ' : '[..] ') . 'external/openmontage/' . $vf . "\n";
}
echo "\n";

$st = class_exists('OpenMontage') ? OpenMontage::status() : ['ok' => false];
echo "Studio : " . (!empty($st['ok']) ? 'READY' : 'OFFLINE') . "\n";
echo "ffmpeg : " . ($st['ffmpeg'] ?? '?') . "\npython : " . ($st['python'] ?? '?') . "\nnode   : " . ($st['node'] ?? '?') . "\n";
echo "Pipelines: " . ($st['pipelines'] ?? 0) . " · projects: " . ($st['projects_dir'] ?? '?') . "\n" . ($st['hint'] ?? '') . "\n\n";

if (in_array('--doctor', $argv ?? [])) {
    echo "--- tool registry probe (vendored) ---\n";
    $pre = class_exists('OpenMontage') ? OpenMontage::preflight() : ['ok' => false, 'error' => 'wrapper missing'];
    if (empty($pre['ok'])) {
        echo 'probe FAILED: ' . ($pre['error'] ?? '?') . "\n";
    } else {
        echo 'runtimes: ' . json_encode($pre['composition_runtimes'] ?? []) . "\n";
        foreach (($pre['capabilities'] ?? []) as $c) {
            echo '  ' . ($c['capability'] ?? '?') . ': ' . ($c['configured'] ?? '?')
                . ' [' . implode(',', (array)($c['providers'] ?? [])) . "]\n";
        }
        if (!empty($pre['runtime_warnings'])) {
            echo "warnings:\n  - " . implode("\n  - ", array_slice($pre['runtime_warnings'], 0, 5)) . "\n";
        }
    }
}

$envFile = $ROOT . '/external/openmontage/.env';
echo is_file($envFile)
    ? "[ok] external/openmontage/.env present (provider keys live here, never in PHP config)\n"
    : "[..] external/openmontage/.env MISSING — copy .env.example to .env and fill keys (zero-key path still works: ffmpeg + local TTS/STT)\n";
echo "\n";

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
        OpenMontage::ensureTables($pdo);
        $n = $pdo->query("SELECT COUNT(*) FROM openmontage_runs")->fetchColumn();
        echo "[ok] $label — openmontage_runs ($n rows) ready\n";
    } catch (Throwable $e) {
        echo "[fail] $label — {$e->getMessage()}\n";
    }
}

echo "\nFiles:\n";
foreach ([
    'autoflows/app/services/OpenMontage.php',
    'sccrm/services/OpenMontageService.php',
    'sccrm/ai/openmontage_agent.php',
    'sccrm/video/index.php',
    'ceo/openmontage.py',
    'ceo/.agents/skills/openmontage/SKILL.md',
    'ceo/agno_agents/skills/openmontage/SKILL.md',
    'skills/skills/openmontage/SKILL.md',
] as $f) {
    echo (is_file($ROOT . '/' . $f) ? '[ok] ' : '[MISSING] ') . $f . "\n";
}
echo "\nDone. Pick pipeline → brief → gates → compose. Zero-key path first.\n";
