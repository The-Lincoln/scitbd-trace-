<?php
/**
 * AutoFlows — Faceless Finance YouTube agent (Digital Maker AI).
 *
 * Standalone-friendly port of the lightweight agent.php + agent.db pattern,
 * wired into AutoFlows storage (storage/app.db):
 *
 *   agent_logs  — every execution (niche, prompt_used, response)
 *   content     — one draft per concept (channel=youtube, platform=youtube)
 *   task_logs   — operational trace (Database::log)
 *
 * Usage:
 *   $env:OPENAI_API_KEY="sk-..."            # PowerShell
 *   export OPENAI_API_KEY="sk-..."          # bash
 *   php tools/youtube_finance_agent.php
 *   php tools/youtube_finance_agent.php --query="...angle..." --count=3
 *   php tools/youtube_finance_agent.php --model=gpt-4o --dry
 *   php tools/youtube_finance_agent.php --show-logs
 *
 * View stored logs any time (agent.db contract, AutoFlows path):
 *   php tools/youtube_finance_agent.php --show-logs
 *
 * Env knobs:
 *   OPENAI_API_KEY   required for online mode (else offline template engine)
 *   OPENAI_BASE_URL  default https://api.openai.com/v1 (any OpenAI-compatible OK)
 *   YT_AGENT_MODEL   default gpt-4o-mini
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
foreach (['TinyLLM', 'Sse', 'PromptLibrary', 'ContentFactory', 'Agent', 'FinanceTube', 'HermesSkills'] as $s) {
    require BASE_PATH . '/app/services/' . $s . '.php';
}

Database::boot();
$pdo = Database::pdo();
FinanceTube::ensureTable($pdo);

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

// ---------------------------------------------------------- --show-logs ---
if ($flag('show-logs')) {
    $rows = $pdo->query(
        "SELECT id, niche, created_at, SUBSTR(response, 1, 100) AS preview, LENGTH(response) AS chars FROM agent_logs ORDER BY id DESC LIMIT 20"
    )->fetchAll(PDO::FETCH_ASSOC);
    if ($rows === []) {
        echo "No agent_logs yet. Run: php tools/youtube_finance_agent.php\n";
        exit(0);
    }
    printf("%-5s %-18s %-19s %-6s %s\n", 'ID', 'NICHE', 'CREATED_AT', 'CHARS', 'PREVIEW');
    foreach ($rows as $r) {
        printf(
            "%-5s %-18s %-19s %-6s %s\n",
            $r['id'],
            mb_substr((string) $r['niche'], 0, 18),
            $r['created_at'],
            $r['chars'],
            str_replace(["\r", "\n"], ' ', (string) $r['preview'])
        );
    }
    exit(0);
}

// ------------------------------------------------------------- params ----
$count = max(1, min(10, (int) ($opt('count', '3') ?? '3')));
$model = (string) ($opt('model', getenv('YT_AGENT_MODEL') ?: 'gpt-4o-mini') ?? 'gpt-4o-mini');
$niche = (string) ($opt('niche', FinanceTube::NICHE) ?? FinanceTube::NICHE);
$angle = (string) ($opt('query', $opt('angle', '')) ?? '');
$dry = $flag('dry');
$temperature = 0.7;

$systemPrompt = FinanceTube::systemPrompt();
$userQuery = $angle !== '' && !str_contains(strtolower($angle), 'generate')
    ? FinanceTube::userQuery($count, $angle)
    : ($angle !== '' ? $angle : FinanceTube::userQuery($count));

echo 'AutoFlows FinanceTube — ' . date('Y-m-d H:i:s') . PHP_EOL;
echo "  niche: {$niche} | count: {$count} | model: {$model}" . ($dry ? ' | DRY-RUN' : '') . PHP_EOL;

if ($dry) {
    echo "  [dry] would call OpenAI-compatible /chat/completions, then insert agent_logs + {$count} content draft(s)." . PHP_EOL;
    echo "  system: " . excerpt($systemPrompt, 120) . PHP_EOL;
    echo "  user: " . excerpt($userQuery, 160) . PHP_EOL;
    exit(0);
}

// --------------------------------------------------------------- call ----
$res = FinanceTube::callOpenAI($systemPrompt, $userQuery, $model, $temperature);
if ($res['ok']) {
    $aiResult = $res['text'];
    $provider = 'openai-compatible';
    echo "  online via {$provider} ({$model}, HTTP {$res['http']})" . PHP_EOL;
} else {
    echo '  online unavailable (' . $res['error'] . ') — using offline template engine.' . PHP_EOL;
    $aiResult = FinanceTube::localDraft($count);
    $provider = 'local';
}

// --------------------------------------------------------------- save ----
$stmt = $pdo->prepare(
    'INSERT INTO agent_logs (niche, prompt_used, response) VALUES (:niche, :prompt, :response)'
);
$stmt->execute([':niche' => $niche, ':prompt' => $userQuery, ':response' => $aiResult]);
$logId = (int) $pdo->lastInsertId();

// One editable draft per concept so titles/scripts can ship via /content.
$contentIds = [];
foreach (FinanceTube::splitConcepts($aiResult) as $i => $concept) {
    $title = FinanceTube::titleOf($concept);
    try {
        $contentIds[] = Content::create([
            'flow_id' => null,
            'run_id' => null,
            'channel' => 'youtube',
            'platform' => 'youtube',
            'title' => $title,
            'slug' => slugify($title),
            'excerpt' => excerpt($concept, 160),
            'body' => $concept,
            'hashtags' => '#finance #money #wealth',
            'status' => 'draft',
            'meta' => [
                'niche' => $niche,
                'provider' => $provider,
                'model' => $provider === 'local' ? 'template-engine-v1' : $model,
                'agent_log_id' => $logId,
                'variant' => $i + 1,
            ],
        ]);
    } catch (Throwable) {
        // content save must not fail the agent run
    }
}

Database::log(
    'youtube.agent',
    "log #{$logId} niche={$niche} count={$count} provider={$provider} model={$model} content=[" . implode(',', $contentIds) . ']'
);

// -------------------------------------------------------------- output ---
echo PHP_EOL . '=== Digital Maker AI Agent Output ===' . PHP_EOL . PHP_EOL;
echo $aiResult . PHP_EOL . PHP_EOL;
echo "[Saved to storage/app.db agent_logs #{$logId} successfully]" . PHP_EOL;
if ($contentIds !== []) {
    echo '[Content drafts: #' . implode(', #', $contentIds) . ' (channel=youtube, status=draft)]' . PHP_EOL;
}
