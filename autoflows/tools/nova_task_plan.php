<?php
/**
 * AutoFlows — Nova task planner (nova-kernel task-planner.mjs pattern).
 *
 * planTask(intent) at session start: keyword-bigram match over skills,
 * agents, warnings (evolve recall) and proposals (pending memories).
 * Zero-LLM, deterministic, read-only (only writes a task_logs trace).
 *
 * Usage:
 *   php tools/nova_task_plan.php --intent="fix the auth bug, then verify with tests"
 *   php tools/nova_task_plan.php --intent="plan 3 finance videos" --limit=3
 *   php tools/nova_task_plan.php --intent="..." --dry
 */
declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));

require BASE_PATH . '/app/config.php';
require BASE_PATH . '/app/core/Helpers.php';
require BASE_PATH . '/app/core/Database.php';
require BASE_PATH . '/app/core/Model.php';
foreach (['Setting', 'Conversation', 'Message', 'Flow', 'Content', 'Run'] as $m) {
    require BASE_PATH . '/app/models/' . $m . '.php';
}
foreach (['TinyLLM', 'Sse', 'PromptLibrary', 'ContentFactory', 'Agent', 'FinanceTube', 'HermesSkills', 'EvolveMemory'] as $s) {
    require BASE_PATH . '/app/services/' . $s . '.php';
}

Database::boot();
$pdo = Database::pdo();
EvolveMemory::ensureTable($pdo);

$args = $argv;
$opt = static function (string $name, ?string $def = null) use ($args): ?string {
    foreach ($args as $a) {
        if (str_starts_with($a, '--' . $name . '=')) {
            return substr($a, strlen('--' . $name . '='));
        }
    }
    return $def;
};
$flag = static function (string $name) use ($args): bool {
    return in_array('--' . $name, $args, true);
};

$intent = trim((string) ($opt('intent', '') ?? ''));
if ($intent === '') {
    echo "Usage: php tools/nova_task_plan.php --intent=\"...\" [--limit=5] [--dry]\n";
    exit(1);
}
$limit = max(1, min(10, (int) ($opt('limit', '5') ?? '5')));
$dry = $flag('dry');

// 1. skills: bigram match over name + description + md head.
$ranked = [];
foreach (HermesSkills::list() as $s) {
    $full = $s['name'] . ' ' . $s['description'];
    $detail = HermesSkills::get($s['name']);
    if ($detail !== null && trim($detail['md']) !== '') {
        $full .= ' ' . mb_substr(preg_replace('/\s+/u', ' ', strip_tags($detail['md'])) ?? '', 0, 500);
    }
    $score = EvolveMemory::bigramJaccard($intent, $full);
    $ranked[] = ['name' => $s['name'], 'score' => $score, 'description' => $s['description']];
}
usort($ranked, fn ($a, $b) => $b['score'] <=> $a['score']);
$skills = array_slice($ranked, 0, $limit);

// 2. agents: keyword routing (Nova $0-routing spirit: cheapest capable first).
$low = mb_strtolower($intent);
$agents = [
    ['name' => 'FlowAgent', 'keys' => ['blog', 'email', 'social', 'post', 'content', 'campaign', 'flow', 'pack'], 'why' => 'brief→outline→social/blog/email→review runs'],
    ['name' => 'FinanceTube', 'keys' => ['video', 'youtube', 'finance', 'money', 'hook', 'script', 'faceless'], 'why' => 'high-CPM faceless video concepts + scripts'],
    ['name' => 'Enso chat', 'keys' => ['chat', 'ask', 'brief', 'plan', 'idea', 'help'], 'why' => 'skill-aware conversational planning, saved to /chat'],
    ['name' => 'TinyLLM', 'keys' => ['code', 'debug', 'review', 'test', 'refactor', 'api'], 'why' => 'local code reasoning via Ollama'],
];
$ascored = [];
foreach ($agents as $a) {
    $hits = 0;
    foreach ($a['keys'] as $k) {
        if (str_contains($low, $k)) {
            $hits++;
        }
    }
    $ascored[] = ['name' => $a['name'], 'hits' => $hits, 'why' => $a['why']];
}
usort($ascored, fn ($a, $b) => $b['hits'] <=> $a['hits']);
$topAgents = array_slice($ascored, 0, 2);
if ($topAgents[0]['hits'] === 0) {
    $topAgents = [$ascored[2]]; // default: Enso chat for general intent
}

// 3. warnings: durable recall (lessons/decisions first).
$warnings = [];
try {
    foreach (EvolveMemory::recall($pdo, $intent, 3) as $m) {
        $warnings[] = ['source' => "memory #{$m['id']} {$m['kind']}", 'body' => (string) $m['text'], 'why' => 'recall score ' . $m['score']];
    }
} catch (Throwable) {
}

// 4. proposals: pending memories awaiting review.
$proposals = [];
try {
    foreach (EvolveMemory::list($pdo, 'pending', 3) as $m) {
        $proposals[] = ['name' => "memory #{$m['id']} {$m['kind']}", 'path' => 'tools/evolve_memory.php', 'conf' => round(((int) $m['importance']) / 3, 2)];
    }
} catch (Throwable) {
}

echo 'Nova plan — ' . date('Y-m-d H:i:s') . ($dry ? ' [DRY]' : '') . PHP_EOL;
echo '  intent: ' . excerpt($intent, 160) . PHP_EOL;
echo PHP_EOL . '  relevant_skills:' . PHP_EOL;
foreach ($skills as $s) {
    echo '    - ' . $s['name'] . ' (' . round($s['score'], 3) . ') — ' . excerpt($s['description'], 100) . PHP_EOL;
}
echo '  relevant_agents:' . PHP_EOL;
foreach ($topAgents as $a) {
    echo '    - ' . $a['name'] . ' — ' . $a['why'] . PHP_EOL;
}
echo '  warnings (' . count($warnings) . '):' . PHP_EOL;
foreach ($warnings as $w) {
    echo '    - [' . $w['source'] . '] ' . excerpt($w['body'], 120) . ' (' . $w['why'] . ')' . PHP_EOL;
}
echo '  proposals (' . count($proposals) . '):' . PHP_EOL;
foreach ($proposals as $p) {
    echo '    - ' . $p['name'] . ' @' . $p['path'] . ' conf=' . $p['conf'] . PHP_EOL;
}
if (!$dry) {
    Database::log('nova.plan', excerpt($intent, 80) . " skills=" . implode(',', array_column($skills, 'name')));
}
echo PHP_EOL . "Done.\n";
