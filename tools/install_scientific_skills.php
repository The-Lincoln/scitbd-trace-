<?php
/**
 * Installer / doctor for the Scientific Skills integration.
 *   php tools/install_scientific_skills.php          # check + migrate usage log
 *   php tools/install_scientific_skills.php --doctor # deep check (sample loads)
 *
 * Upstream: https://github.com/The-Lincoln/scientific-agent-skills.git (MIT)
 * No daemon: file-based library (skills/<name>/SKILL.md × 177).
 */
$ROOT = dirname(__DIR__);
foreach ([$ROOT . '/autoflows/app/services/ScientificSkills.php'] as $f) {
    if (is_file($f)) {
        require_once $f;
    }
}

echo "=== SCITBD Scientific Skills integration check ===\n";
echo "Repo: https://github.com/The-Lincoln/scientific-agent-skills.git\n";
echo "Vendor: external/scientific-agent-skills\n\n";

foreach (['README.md', 'AGENTS.md', 'plugin.json', 'pyproject.toml', 'scan_skills.py', 'docs/skills.md'] as $vf) {
    echo (is_file($ROOT . '/external/scientific-agent-skills/' . $vf) ? '[ok] ' : '[..] ') . 'external/scientific-agent-skills/' . $vf . "\n";
}
echo "\n";

$st = class_exists('ScientificSkills') ? ScientificSkills::status() : ['ok' => false, 'skills' => 0];
echo "Skills : " . ($st['skills'] ?? 0) . "\nReady  : " . (!empty($st['ok']) ? 'YES' : 'NO') . "\n" . ($st['hint'] ?? '') . "\n";
echo "Fast paths: " . implode(', ', $st['fast_paths'] ?? []) . "\n\n";

if (in_array('--doctor', $argv ?? [])) {
    echo "--- sample: search + load ---\n";
    $h = class_exists('ScientificSkills') ? ScientificSkills::search('clinical trial evidence', 3) : ['hits' => []];
    foreach ($h['hits'] ?? [] as $x) {
        echo "- {$x['id']} v" . ($x['version'] ?: '?') . ' :: ' . mb_substr($x['description'] ?? '', 0, 90) . "\n";
    }
    $g = class_exists('ScientificSkills') ? ScientificSkills::get('database-lookup') : ['ok' => false];
    echo "load database-lookup: " . (!empty($g['ok']) ? "OK ({$g['chars']} chars)" : ($g['error'] ?? '?')) . "\n";
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
        ScientificSkills::ensureTables($pdo);
        $n = $pdo->query("SELECT COUNT(*) FROM sciskill_runs")->fetchColumn();
        echo "[ok] $label — sciskill_runs ($n rows) ready\n";
    } catch (Throwable $e) {
        echo "[fail] $label — {$e->getMessage()}\n";
    }
}

echo "\nFiles:\n";
foreach ([
    'autoflows/app/services/ScientificSkills.php',
    'sccrm/services/ScientificSkillsService.php',
    'sccrm/ai/scientific_agent.php',
    'ceo/scientific_skills.py',
    'ceo/.agents/skills/scientific-skills/SKILL.md',
    'ceo/agno_agents/skills/scientific-skills/SKILL.md',
    'skills/skills/scientific-skills/SKILL.md',
] as $f) {
    echo (is_file($ROOT . '/' . $f) ? '[ok] ' : '[MISSING] ') . $f . "\n";
}
echo "\nDone. Search-first, load-what-you-need, verify in target env.\n";
