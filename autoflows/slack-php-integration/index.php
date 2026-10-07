<?php
/**
 * SCITBD Unified Entry Point — Slack Integration + CEO Task Management
 *
 * Fixes applied:
 *  - Corrected autoload/config/.env paths (root-level __DIR__, not __DIR__.'/../')
 *  - Added CEO folder integration (ceo/scitbd_ceo.db via src/Ceo/CeoTaskManager.php)
 *  - Routes /tasks, /toolbar/*, /ceo* and ?action=submit_lead|get_dashboard|get_blocks|get_current_block
 *    to ceo/index.php backend engine
 *  - Adds Slack slash-style CEO commands: `ceo list|summary|block|create|complete|start`
 *  - GET / with no Slack event renders a landing page (instead of hanging on signature verify)
 *
 * Layout:
 *   /                  -> landing page (Slack + CEO links, health)
 *   /health            -> JSON health (slack + ceo db + bst block)
 *   /webhook.php       -> Slack events (see public/webhook.php)
 *   /ceo.php           -> CEO Task API bridge (see public/ceo.php)
 *   /tasks, /toolbar/* -> CEO Task Management API (ceo/index.php)
 *   ?action=...        -> CEO dashboard API (ceo/index.php)
 *   Slack events       -> verified webhook processing (below)
 */

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/config/config.php';

use SCITBD\Slack\Config\Config;
use SCITBD\Slack\Integration\SlackIntegration;
use SCITBD\Slack\Ceo\CeoTaskManager;

// Load configuration (FIXED: root .env, not parent dir)
Config::load(__DIR__ . '/.env');

// ---------------------------------------------------------------------------
// CLI mode: helpful output instead of fatal webhook error
// ---------------------------------------------------------------------------
if (PHP_SAPI === 'cli') {
    $arg = $argv[1] ?? 'help';
    if (in_array($arg, ['-h', '--help', 'help'], true)) {
        echo "SCITBD Unified App — Slack + CEO\n\n";
        echo "Web (docroot public/ recommended):\n";
        echo "  php -S localhost:8888 -t public/\n";
        echo "  php -S localhost:8888 index.php   (alt: root router)\n\n";
        echo "Routes:\n";
        echo "  GET  /                    landing page\n";
        echo "  GET  /health              slack+ceo health JSON\n";
        echo "  ANY  /tasks, /toolbar/*   CEO Task API (ceo/index.php)\n";
        echo "  ANY  ?action=submit_lead|get_dashboard|get_blocks|get_current_block\n";
        echo "  POST /webhook.php         Slack events\n";
        echo "  ANY  /ceo.php             CEO API bridge\n\n";
        echo "CEO via Slack message:\n";
        echo "  ceo list | ceo summary | ceo block | ceo create <title> | ceo done <id> | ceo start <id>\n";
        exit(0);
    }
    if (str_starts_with($arg, 'ceo:')) {
        try {
            $ceo = new CeoTaskManager();
            $block = $ceo->getCurrentBSTBlock();
            $cmd = substr($arg, 4);
            if ($cmd === 'block') {
                echo json_encode(['ok' => true, 'block' => $block], JSON_PRETTY_PRINT) . PHP_EOL;
            } elseif ($cmd === 'summary') {
                echo json_encode(['ok' => true, 'summary' => $ceo->getSummary()], JSON_PRETTY_PRINT) . PHP_EOL;
            } elseif ($cmd === 'pending') {
                echo json_encode(['ok' => true, 'pending' => $ceo->pendingForBlock()], JSON_PRETTY_PRINT) . PHP_EOL;
            } else {
                echo "Unknown ceo command: {$cmd} (try ceo:block|ceo:summary|ceo:pending)\n";
                exit(1);
            }
            exit(0);
        } catch (Throwable $e) {
            fwrite(STDERR, 'CEO error: ' . $e->getMessage() . PHP_EOL);
            exit(1);
        }
    }
    // fall through to web-style handling for other CLI uses
}

// ---------------------------------------------------------------------------
// CEO route delegation — hand off to ceo/index.php backend engine
// ---------------------------------------------------------------------------
$requestUri = $_SERVER['REQUEST_URI'] ?? '/';
$requestPath = parse_url($requestUri, PHP_URL_PATH) ?: '/';
$actionParam = $_GET['action'] ?? '';
$ceoActions = ['submit_lead', 'get_dashboard', 'get_blocks', 'get_current_block'];

$isCeoRoute = in_array($actionParam, $ceoActions, true)
    || $requestPath === '/tasks'
    || str_starts_with($requestPath, '/tasks/')
    || str_starts_with($requestPath, '/toolbar')
    || str_starts_with($requestPath, '/ceo');

if ($isCeoRoute) {
    // ceo/index.php reads $_GET['action'], $requestPath/method itself and exit()s.
    require __DIR__ . '/ceo/index.php';
    exit;
}

// Health endpoint: proves both Slack config + CEO DB wiring
if ($requestPath === '/health' || ($requestPath === '/' && ($actionParam === 'health' || ($_GET['route'] ?? '') === 'health'))) {
    header('Content-Type: application/json');
    $ceoOk = false;
    $ceoInfo = [];
    try {
        $ceo = new CeoTaskManager();
        $block = $ceo->getCurrentBSTBlock();
        $summary = $ceo->getSummary();
        $ceoOk = true;
        $ceoInfo = [
            'db' => basename($ceo->getDbPath()),
            'current_block' => $block['block_name'] ?? null,
            'pending_today' => $summary['pending_tasks'] ?? 0,
        ];
    } catch (Throwable $e) {
        $ceoInfo = ['error' => $e->getMessage()];
    }
    echo json_encode([
        'ok' => true,
        'app' => Config::get('APP_NAME', 'SCITBD Slack Integration'),
        'env' => Config::get('APP_ENV', 'development'),
        'slack' => [
            'bot_token_configured' => Config::getBotToken() !== '',
            'signing_secret_configured' => Config::getSigningSecret() !== '',
            'webhook_configured' => Config::getWebhookUrl() !== '',
        ],
        'ceo' => array_merge(['ok' => $ceoOk], $ceoInfo),
        'routes' => ['GET /', 'GET /health', 'ANY /tasks', 'ANY /toolbar/*', 'ANY ?action=get_dashboard', 'POST /webhook.php', 'ANY /ceo.php'],
        'time' => date('c'),
    ]);
    exit;
}

// ---------------------------------------------------------------------------
// Landing page for plain browser GET / (no Slack signature) — avoids 403 hang
// ---------------------------------------------------------------------------
$isSlackEvent = isset($_SERVER['HTTP_SLACK_REQUEST_TIMESTAMP']) || isset($_SERVER['HTTP_SLACK_SIGNATURE']);
$rawBody = '';
if ($isSlackEvent || ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $rawBody = file_get_contents('php://input') ?: '';
}
if (!$isSlackEvent && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && $requestPath === '/' && empty($actionParam)) {
    $botOk = Config::getBotToken() !== '' ? '✅' : '⚠️';
    $secretOk = Config::getSigningSecret() !== '' ? '✅' : '⚠️';
    $ceoStatus = '⚠️ unavailable';
    $blockLine = '';
    try {
        $ceoLanding = new CeoTaskManager();
        $blk = $ceoLanding->getCurrentBSTBlock();
        $sum = $ceoLanding->getSummary();
        $ceoStatus = "✅ connected ({$sum['total_tasks']} tasks today, {$sum['pending_tasks']} pending)";
        if ($blk) {
            $blockLine = htmlspecialchars("{$blk['block_name']} ({$blk['bst_start']}–{$blk['bst_end']} BST)");
        }
    } catch (Throwable $e) {
        $ceoStatus = '⚠️ ' . htmlspecialchars($e->getMessage());
    }
    header('Content-Type: text/html; charset=utf-8');
    echo <<<HTML
<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>SCITBD — Slack + CEO</title>
<style>body{font-family:system-ui,Segoe UI,Arial;background:#0d1117;color:#c9d1d9;margin:0;padding:32px} .wrap{max-width:860px;margin:auto}
.card{background:#161b22;border:1px solid #30363d;border-radius:10px;padding:20px;margin:16px 0} a{color:#58a6ff} code{background:#21262d;padding:2px 6px;border-radius:6px}
h1{color:#f1e05a} .ok{color:#3fb950}</style></head><body><div class="wrap">
<h1>🌐 SCITBD — Slack + CEO Task Management</h1>
<div class="card"><b>Slack:</b> bot {$botOk} · signing secret {$secretOk}<br><b>CEO DB:</b> <span class="ok">{$ceoStatus}</span><br><b>Active BST block:</b> {$blockLine}</div>
<div class="card"><h3>Slack endpoints</h3>
<ul><li><code>POST /webhook.php</code> — Slack events (Event Subscriptions URL)</li>
<li><code>POST /incoming-webhook.php</code> — deployment / alert / ticket payloads</li>
<li>Try in Slack: <code>ceo list</code> · <code>ceo summary</code> · <code>ceo block</code> · <code>ceo create &lt;title&gt;</code></li></ul></div>
<div class="card"><h3>CEO Task API (BST)</h3>
<ul><li><a href="/tasks">GET /tasks</a> — list (filters: status, priority, category, bst_block_id)</li>
<li><code>POST /tasks</code> — create {"task_title": "...", "priority": "high"}</li>
<li><a href="/toolbar/data">GET /toolbar/data</a> · <a href="/toolbar/summary">GET /toolbar/summary</a> · <a href="/toolbar/blocks">GET /toolbar/blocks</a></li>
<li><a href="/?action=get_dashboard">?action=get_dashboard</a> · <a href="/?action=get_blocks">?action=get_blocks</a> · <a href="/?action=get_current_block">?action=get_current_block</a></li>
<li><a href="/health">GET /health</a> — combined health</li></ul></div>
<div class="card"><h3>Dashboards</h3><ul><li><a href="/ceo-dashboard.html">CEO dashboard</a> (copy <code>ceo/dashboard.html</code> to <code>public/ceo-dashboard.html</code> to serve it)</li>
<li>CEO backend file: <code>ceo/index.php</code> · DB: <code>ceo/scitbd_ceo.db</code></li></ul></div>
</div></body></html>
HTML;
    exit;
}

// ---------------------------------------------------------------------------
// Slack webhook processing (original behaviour, now with CEO commands)
// ---------------------------------------------------------------------------
$integration = SlackIntegration::getInstance();
$webhook = $integration->webhook();

$result = $webhook->process(function ($event) use ($integration) {
    $eventType = $event['event_type'] ?? $event['type'] ?? null;
    $channel = $event['channel'] ?? null;
    if (is_array($channel)) {
        $channel = $channel['id'] ?? null;
    }
    $user = $event['user'] ?? null;
    if (is_array($user)) {
        $user = $user['id'] ?? null;
    }
    $text = $event['text'] ?? null;

    switch ($eventType) {
        case 'message':
            handleMessage($integration, $channel, $user, $text);
            break;
        case 'app_mention':
            handleAppMention($integration, $channel, $user, $text);
            break;
        case 'block_actions':
            handleBlockActions($integration, $event);
            break;
        default:
            $integration->getLogger()->info('Unhandled event type', ['type' => $eventType]);
    }
});

if (!headers_sent()) {
    header('Content-Type: application/json');
}
echo json_encode(['ok' => true]);
exit;

// ---------------------------------------------------------------------------
// Handlers
// ---------------------------------------------------------------------------

function handleMessage($integration, $channel, $user, $text): void
{
    if (empty($text)) {
        return;
    }
    // CEO natural-language commands inside normal messages
    if (isCeoCommand($text)) {
        handleCeoSlackCommand($integration, $channel, $user, $text);
        return;
    }
    if (stripos($text, 'hello') !== false || stripos($text, 'hi') !== false) {
        $integration->replyThread($channel, $user, "Hello! 👋 I'm the SCITBD Slack Bot. Use /help for commands, or try `ceo summary`.");
    }
}

function handleAppMention($integration, $channel, $user, $text): void
{
    if (isCeoCommand((string)$text)) {
        handleCeoSlackCommand($integration, $channel, $user, (string)$text);
        return;
    }
    $integration->send($channel, "Hi <@{$user}>! I'm here to help. Send me messages or use slash commands.", [
        ['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => "*Available Commands:*\n/help - Show help\n/deploy - Deployment notification\n/status - System status\n/bug - Report a bug\n/channels - List channels\n*CEO:*\n`ceo list` - Pending tasks (current BST block)\n`ceo summary` - Daily progress\n`ceo block` - Current BST block\n`ceo create <title>` - Create task"]],
    ]);
}

function handleBlockActions($integration, $event): void
{
    $actions = $event['actions'] ?? [];
    $channelId = is_array($event['channel'] ?? null) ? ($event['channel']['id'] ?? '') : ($event['channel'] ?? '');
    foreach ($actions as $action) {
        $actionId = $action['action_id'] ?? '';
        $userId = is_array($event['user'] ?? null) ? ($event['user']['id'] ?? '') : ($event['user'] ?? '');
        switch ($actionId) {
            case 'accept_task':
                $integration->send($channelId, "<@{$userId}> accepted the task! ✅", [
                    ['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => '✅ Task accepted!']],
                ]);
                break;
            case 'defer_task':
                $integration->send($channelId, "<@{$userId}> deferred the task. ⏳", [
                    ['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => '⏳ Task deferred.']],
                ]);
                break;
            case 'ceo_complete_task':
                $taskId = (int)($action['value'] ?? 0);
                if ($taskId > 0) {
                    try {
                        $ceo = new CeoTaskManager();
                        $ceo->setStatus($taskId, 'completed', 'slack:' . $userId);
                        $integration->send($channelId, "✅ CEO task #{$taskId} completed by <@{$userId}>");
                    } catch (Throwable $e) {
                        $integration->send($channelId, "⚠️ Could not complete task #{$taskId}: " . $e->getMessage());
                    }
                }
                break;
        }
    }
}

function isCeoCommand(string $text): bool
{
    return (bool)preg_match('/\bceo\s+(list|tasks|summary|block|create|done|complete|start|help)\b/i', $text);
}

/**
 * Slack chatops for CEO task management.
 * Examples: ceo list | ceo summary | ceo block | ceo create Fix homepage SEO high | ceo done 12
 */
function handleCeoSlackCommand($integration, $channel, $user, string $text): void
{
    try {
        $ceo = new CeoTaskManager();
    } catch (Throwable $e) {
        $integration->send($channel, '⚠️ CEO database unavailable: ' . $e->getMessage());
        return;
    }

    $clean = trim(preg_replace('/<@[^>]+>\s*/', '', $text) ?? $text);
    $cmd = strtolower(trim((string)preg_replace('/^.*?ceo\s+/i', '', $clean)));
    $parts = preg_split('/\s+/', $cmd, 3) ?: [];
    $verb = strtolower($parts[0] ?? 'help');

    try {
        switch ($verb) {
            case 'list':
            case 'tasks': {
                $block = $ceo->getCurrentBSTBlock();
                $tasks = $ceo->pendingForBlock($block ? (int)$block['id'] : null, 10);
                $blocks = CeoTaskManager::formatTasksForSlack($tasks, $block);
                // add quick-complete buttons
                if (!empty($tasks)) {
                    $actions = ['type' => 'actions', 'elements' => []];
                    foreach (array_slice($tasks, 0, 3) as $t) {
                        $actions['elements'][] = [
                            'type' => 'button',
                            'text' => ['type' => 'plain_text', 'text' => "Done #{$t['id']}", 'emoji' => true],
                            'action_id' => 'ceo_complete_task',
                            'value' => (string)$t['id'],
                            'style' => 'primary',
                        ];
                    }
                    $blocks[] = $actions;
                }
                $first = $tasks[0]['task_title'] ?? 'no pending tasks';
                $integration->send($channel, "CEO tasks: {$first}", $blocks);
                break;
            }
            case 'summary': {
                $summary = $ceo->getSummary();
                $block = $ceo->getCurrentBSTBlock();
                $integration->send($channel, "Daily summary {$summary['date']}", CeoTaskManager::formatSummaryForSlack($summary, $block));
                break;
            }
            case 'block': {
                $block = $ceo->getCurrentBSTBlock();
                if (!$block) {
                    $integration->send($channel, 'No active BST block right now.');
                } else {
                    $integration->send($channel, "Active block: {$block['block_name']}", [
                        ['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => "🕐 *{$block['block_name']}*\n*BST:* {$block['bst_start']}–{$block['bst_end']} · *UTC:* {$block['utc_start']}–{$block['utc_end']}\n*Focus:* {$block['regional_focus']} — {$block['core_execution_focus']}"]],
                    ]);
                }
                break;
            }
            case 'create': {
                // everything after "ceo create "
                $title = trim(substr($clean, stripos($clean, 'create') + 6));
                if ($title === '') {
                    $integration->send($channel, 'Usage: `ceo create <task title>`');
                    break;
                }
                $priority = 'medium';
                if (preg_match('/\b(critical|high|medium|low)\b/i', $title, $m)) {
                    $priority = strtolower($m[1]);
                }
                $block = $ceo->getCurrentBSTBlock();
                $task = $ceo->createTask($title, [
                    'priority' => $priority,
                    'bst_block_id' => $block ? (int)$block['id'] : null,
                    'assignee' => 'ceo',
                    'created_by' => 'slack:' . ($user ?? 'unknown'),
                    'performed_by' => 'slack:' . ($user ?? 'unknown'),
                ]);
                $integration->send($channel, "Task #{$task['id']} created", [
                    ['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => "✅ *Task #{$task['id']} created*\n{$task['task_title']}\nPriority: `{$task['priority']}` · Block: `{$task['bst_block_id']}`"]],
                ]);
                break;
            }
            case 'done':
            case 'complete': {
                $id = (int)($parts[1] ?? 0);
                if ($id <= 0) {
                    $integration->send($channel, 'Usage: `ceo done <task_id>`');
                    break;
                }
                $ceo->setStatus($id, 'completed', 'slack:' . ($user ?? 'unknown'));
                $integration->send($channel, "✅ Task #{$id} marked completed.");
                break;
            }
            case 'start': {
                $id = (int)($parts[1] ?? 0);
                if ($id <= 0) {
                    $integration->send($channel, 'Usage: `ceo start <task_id>`');
                    break;
                }
                $ceo->setStatus($id, 'in_progress', 'slack:' . ($user ?? 'unknown'));
                $integration->send($channel, "🔄 Task #{$id} started.");
                break;
            }
            default:
                $integration->send($channel, "CEO commands: `ceo list` · `ceo summary` · `ceo block` · `ceo create <title>` · `ceo done <id>` · `ceo start <id>`");
        }
    } catch (Throwable $e) {
        $integration->getLogger()->info('CEO command failed', ['error' => $e->getMessage()]);
        $integration->send($channel, '⚠️ CEO command failed: ' . $e->getMessage());
    }
}
