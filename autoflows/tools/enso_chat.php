<?php
/**
 * AutoFlows — Enso-style chat AI CLI with all-skills support.
 *
 * Enso bridge: multi-provider chat (OpenAI-compatible -> Ollama TinyLLM ->
 * offline template), skill-aware via HermesSkills::promptBlock(), persisted
 * to conversations/messages so threads appear in /chat.
 *
 * Mirrors Enso server/src/llm-provider.ts provider order
 * (OpenAI-compat with env-key fallback, then Ollama) in PHP.
 *
 * Nova $0-routing map (same spirit — cheapest capable first, $0 marginal):
 *   Nova Sonnet/Opus via ag-bridge sub  -> AutoFlows Ollama TinyLLM (local, $0)
 *   Nova GPT-5/Codex via ChatGPT sub     -> AutoFlows OPENAI_API_KEY BaseUrl override ($0 if sub-backed)
 *   Nova Gemini free tier                -> AutoFlows OPENAI_BASE_URL=https://generativelanguage.googleapis.com/v1beta/openai ($0 free tier)
 *   Nova local bge-m3/Ollama :11434      -> AutoFlows TinyLLM :11434 (local, $0)
 *   Fallback when nothing reachable      -> offline template engine ($0, always)
 * Nova kernel server :3700 never binds inside AutoFlows (grep-verified); web UI stays :8020.
 *
 * Usage:
 *   php tools/enso_chat.php --show-skills
 *   php tools/enso_chat.php --message="Plan 3 faceless finance videos" --skills=youtube-auto,youtube_manager
 *   php tools/enso_chat.php --message="..." --all-skills --dry
 *   php tools/enso_chat.php --message="..." --conversation=3
 *   php tools/enso_chat.php --message="..." --new --model=gpt-4o --title="Enso brief"
 *   php tools/enso_chat.php --message="..." --preset=radar|finance|video
 *   php tools/enso_chat.php --message="..." --no-recall  # skip durable memory
 *
 * Env:
 *   OPENAI_API_KEY / OPENAI_BASE_URL  OpenAI-compatible provider (Enso-style)
 *   ENSO_CHAT_MODEL                   default OpenAI model (default gpt-4o-mini)
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

if ($flag('show-skills')) {
    $items = HermesSkills::list();
    printf("%-34s %-6s %s\n", 'NAME', 'FILES', 'DESCRIPTION');
    foreach ($items as $it) {
        $files = ($it['has_md'] ? 'md' : '--') . '+' . ($it['has_json'] ? 'json' : '--');
        printf("%-34s %-6s %s\n", $it['name'], $files, mb_substr($it['description'], 0, 85));
    }
    echo count($items) . " skill(s).\n";
    exit(0);
}

$message = trim((string) ($opt('message', '') ?? ''));
if ($message === '') {
    echo "Usage: php tools/enso_chat.php --message=\"...\" [--skills=a,b] [--all-skills] [--conversation=ID|--new] [--model=...] [--dry]\n";
    exit(1);
}

$dry = $flag('dry');
$modelOpt = (string) ($opt('model', '') ?? '');
$customSystem = (string) ($opt('system', '') ?? '');

// Presets bundle radar/finance/video skill sets (merge with --skills).
$presets = [
    'radar' => ['trendradar', 'ai_pulse', 'market_intelligence'],
    'finance' => ['finance-investment-researcher', 'finance-financial-analyst', 'market_intelligence'],
    'video' => ['youtube-auto', 'youtube_manager', 'video_studio', 'video_highlight_pipeline'],
    'nova' => ['nova-api-key-header-pattern', 'nova-atomic-write-pattern', 'nova-path-traversal-defense'],
];
$preset = strtolower(trim((string) ($opt('preset', '') ?? '')));
$presetSkills = $preset !== '' && isset($presets[$preset]) ? $presets[$preset] : [];
if ($preset !== '' && $presetSkills === []) {
    echo "Unknown --preset=\"{$preset}\" (radar|finance|video|nova). Continuing without preset.\n";
}
if ($flag('all-skills')) {
    $skillNames = array_column(HermesSkills::list(), 'name');
} else {
    $raw = (string) ($opt('skills', '') ?? '');
    $skillNames = array_values(array_filter(array_map('trim', explode(',', $raw))));
    $skillNames = array_values(array_unique(array_merge($skillNames, $presetSkills)));
}
// Validate against registry; keep unknown names out but warn.
$valid = [];
$unknown = [];
foreach ($skillNames as $s) {
    if (HermesSkills::exists($s)) {
        $valid[] = $s;
    } else {
        $unknown[] = $s;
    }
}
$skillNames = $valid;

$baseSystem = $customSystem !== '' ? $customSystem : (string) config('chat.defaults.system', 'You are AutoFlows, a helpful content assistant.');
$skillBlock = '';
try {
    $skillBlock = HermesSkills::promptBlock($skillNames, 1500);
} catch (Throwable) {
    $skillBlock = '';
}
// Durable memory hook (dsh-evolve pattern): recall top hits for this message.
$memoryBlock = '';
$memories = [];
if (!$flag('no-recall')) {
    try {
        EvolveMemory::ensureTable(Database::pdo());
        $memories = EvolveMemory::recall(Database::pdo(), $message, 3);
        if ($memories !== []) {
            $lines = [];
            foreach ($memories as $m) {
                $lines[] = '- [' . $m['kind'] . '/' . $m['scope'] . '] ' . $m['text'];
            }
            $memoryBlock = "## Durable memory (recall — apply where relevant)\n" . implode("\n", $lines) . "\n";
        }
    } catch (Throwable) {
        $memories = [];
        $memoryBlock = '';
    }
}
$system = $baseSystem . ($skillBlock !== '' ? "\n\n" . $skillBlock : '') . ($memoryBlock !== '' ? "\n\n" . $memoryBlock : '');

$convId = (int) ($opt('conversation', '0') ?? '0');
$isNew = $flag('new') || $convId <= 0;

echo 'Enso chat — ' . date('Y-m-d H:i:s') . PHP_EOL;
echo '  skills: ' . ($skillNames === [] ? '(none)' : implode(',', $skillNames)) . ($flag('all-skills') ? ' [ALL]' : '') . ($preset !== '' && $presetSkills !== [] ? " [preset={$preset}]" : '') . PHP_EOL;
echo '  memory: ' . ($flag('no-recall') ? 'off' : count($memories) . ' hit(s)') . PHP_EOL;
if ($unknown !== []) {
    echo '  unknown skills skipped: ' . implode(',', $unknown) . PHP_EOL;
}
if ($dry) {
    echo "  [dry] would chat via OpenAI-compat -> Ollama -> offline, then save to conversations/messages.\n";
    echo '  system: ' . excerpt($system, 160) . PHP_EOL;
    echo '  message: ' . excerpt($message, 160) . PHP_EOL;
    exit(0);
}

// Resolve or create conversation.
if ($isNew) {
    $title = trim((string) ($opt('title', '') ?? ''));
    if ($title === '') {
        $title = mb_substr($message, 0, 60);
    }
    $convId = Conversation::create($title, $modelOpt !== '' ? $modelOpt : null, mb_substr($system, 0, 2000));
    echo "  conversation: #{$convId} (new)\n";
} else {
    $conv = Conversation::find($convId);
    if ($conv === null) {
        echo "Conversation #{$convId} not found. Use --new to start one.\n";
        exit(1);
    }
    echo "  conversation: #{$convId}\n";
}

$history = Message::transcript($convId, 30);
$history[] = ['role' => 'user', 'content' => $message];

// Provider chain: 1) OpenAI-compatible (Enso-style env key) 2) Ollama TinyLLM 3) offline.
$provider = 'offline';
$modelUsed = $modelOpt !== '' ? $modelOpt : 'template-engine-v1';
$reply = '';
$openaiKey = (string) (getenv('OPENAI_API_KEY') ?: '');

if ($openaiKey !== '' && $openaiKey !== 'YOUR_API_KEY_HERE') {
    $openModel = $modelOpt !== '' ? $modelOpt : ((string) (getenv('ENSO_CHAT_MODEL') ?: 'gpt-4o-mini'));
    $flat = '';
    foreach ($history as $h) {
        $flat .= strtoupper((string) $h['role']) . ': ' . (string) $h['content'] . "\n\n";
    }
    $res = FinanceTube::callOpenAI($system, trim($flat), $openModel, 0.7);
    if ($res['ok']) {
        $reply = $res['text'];
        $provider = 'openai-compatible';
        $modelUsed = $openModel;
        echo "  online via openai-compatible ({$modelUsed})\n";
    } else {
        echo '  openai-compat unavailable (' . $res['error'] . ') — trying Ollama.' . PHP_EOL;
    }
}

if ($reply === '') {
    $ollamaModel = $modelOpt !== '' ? $modelOpt : TinyLLM::model();
    try {
        $res = TinyLLM::chat($history, ['system' => $system, 'model' => $ollamaModel]);
        if (trim($res['content']) !== '') {
            $reply = trim($res['content']);
            $provider = $res['provider'] . ($res['fallback'] ? '-fallback' : '');
            $modelUsed = $res['model'];
            echo "  via TinyLLM ({$modelUsed}, provider={$provider})\n";
        }
    } catch (Throwable $e) {
        echo '  ollama unavailable (' . $e->getMessage() . ') — using offline template.' . PHP_EOL;
    }
}

if ($reply === '') {
    $reply = "Here's a plan for: {$message}\n\n"
        . "1) Clarify the outcome in one sentence.\n"
        . "2) List 3 angles (beginner, intermediate, advanced).\n"
        . "3) Draft one variant per angle, then pick the strongest hook.\n"
        . ($skillNames !== [] ? "\nApplied skills: " . implode(', ', $skillNames) . " (offline guidance).\n" : "\nTip: re-run with --skills=youtube-auto,youtube_manager for video SEO guidance.\n");
    $provider = 'local';
    $modelUsed = 'template-engine-v1';
    echo "  offline template engine.\n";
}

// Persist both sides so /chat shows the thread.
Message::create($convId, 'user', $message, $modelUsed, ['provider' => $provider, 'skills' => $skillNames, 'memory' => array_column($memories, 'id'), 'source' => 'enso_chat']);
$assistantId = Message::create($convId, 'assistant', $reply, $modelUsed, ['provider' => $provider, 'skills' => $skillNames, 'memory' => array_column($memories, 'id'), 'source' => 'enso_chat']);
Database::log('enso.chat', "conv #{$convId} msg #{$assistantId} provider={$provider} model={$modelUsed} skills=[" . implode(',', $skillNames) . '] mem=' . count($memories) . ' ' . excerpt($message, 80));

echo PHP_EOL . '=== Enso Chat Reply (conv #' . $convId . ') ===' . PHP_EOL . PHP_EOL;
echo $reply . PHP_EOL . PHP_EOL;
echo "[Saved conversation #{$convId}, messages user+assistant, provider={$provider}]" . PHP_EOL;
