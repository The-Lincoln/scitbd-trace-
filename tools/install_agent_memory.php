<?php
/**
 * Installer / doctor for the TencentDB Agent Memory integration.
 *   php tools/install_agent_memory.php          # check + migrate all DBs
 *   php tools/install_agent_memory.php --doctor # deep check (core health)
 *
 * Upstream: https://github.com/The-Lincoln/TencentDB-Agent-Memory.git
 * Stack:    external/tencentdb-agent-memory/deploy/global-images/./start-all.sh
 *           (core :8420, panel :8125, knowledge :8424, proxy :8096)
 */
$ROOT = dirname(__DIR__);
foreach ([$ROOT . '/autoflows/app/services/AgentMemory.php'] as $f) {
    if (is_file($f)) {
        require_once $f;
    }
}

echo "=== SCITBD Agent Memory (TencentDB) integration check ===\n";
echo "Repo: https://github.com/The-Lincoln/TencentDB-Agent-Memory.git\n";
echo "Vendor: external/tencentdb-agent-memory\n\n";

foreach ([
    'external/tencentdb-agent-memory/INSTALL.md',
    'external/tencentdb-agent-memory/deploy/global-images/.env.example',
    'external/tencentdb-agent-memory/sdk/memory-core/python/tencentdb_agent_memory/v3/client.py',
    'external/tencentdb-agent-memory/agents/adapter-agent-development.md',
] as $vf) {
    echo (is_file($ROOT . '/' . $vf) ? '[ok] ' : '[MISSING] ') . $vf . "\n";
}
echo "\n";

$st = class_exists('AgentMemory') ? AgentMemory::status('shared') : ['ok' => false];
echo "Core   : " . ($st['core_url'] ?? '?') . "\nTeam   : " . ($st['team'] ?? '?') . " / agent scitbd-shared\n";
echo "IDs    : service_id " . (!empty($st['has_service_id']) ? 'SET' : 'MISSING') . ", user_key " . (!empty($st['has_user_key']) ? 'SET' : 'MISSING') . "\n";
echo "Ready  : " . (!empty($st['ok']) ? 'YES' : 'NO') . " (" . ($st['latency_ms'] ?? 0) . "ms)\n";
echo ($st['hint'] ?? '') . "\n\n";
if (empty($st['ok'])) {
    echo "BOOT STACK:\n  cd external/tencentdb-agent-memory/deploy/global-images\n  cp .env.example .env && ./start-all.sh\n  # Panel http://localhost:8125 → user_key (sk-mem-…) → export TDAI_MEMORY_KEY=...\n\n";
}

if (in_array('--doctor', $argv ?? [])) {
    echo "--- core health ---\n";
    echo json_encode(class_exists('AgentMemory') ? AgentMemory::health(['timeout' => 8]) : ['ok' => false], JSON_PRETTY_PRINT) . "\n";
    echo "--- knowledge tools/list ---\n";
    echo json_encode(class_exists('AgentMemory') ? AgentMemory::toolsList(['timeout' => 8]) : ['ok' => false], JSON_PRETTY_PRINT) . "\n";
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
        AgentMemory::ensureTables($pdo);
        $n = $pdo->query("SELECT COUNT(*) FROM agent_memory_cache")->fetchColumn();
        echo "[ok] $label — agent_memory_cache ($n rows) ready\n";
    } catch (Throwable $e) {
        echo "[fail] $label — {$e->getMessage()}\n";
    }
}

echo "\nFiles:\n";
foreach ([
    'autoflows/app/services/AgentMemory.php',
    'sccrm/services/AgentMemoryService.php',
    'sccrm/ai/agent_memory_agent.php',
    'trace/AgentMemoryHook.php',
    'ceo/agent_memory.py',
    'ceo/.agents/skills/tencentdb-agent-memory/SKILL.md',
    'ceo/agno_agents/skills/agent-memory/SKILL.md',
    'skills/skills/tencentdb-agent-memory/SKILL.md',
] as $f) {
    echo (is_file($ROOT . '/' . $f) ? '[ok] ' : '[MISSING] ') . $f . "\n";
}
echo "\nDone. recall() before work, remember() after — stack down = local cache.\n";
