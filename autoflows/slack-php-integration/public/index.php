<?php
/**
 * SCITBD Slack Integration - Main Entry Point (public docroot)
 * Handles all incoming Slack webhooks and routes requests.
 * Integrated with CEO Task Management (ceo/ backend via src/Ceo/CeoTaskManager.php).
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/config.php';

use SCITBD\Slack\Config\Config;
use SCITBD\Slack\Integration\SlackIntegration;
use SCITBD\Slack\Webhook\WebhookReceiver;
use SCITBD\Slack\Ceo\CeoTaskManager;

// Load configuration
Config::load(__DIR__ . '/../.env');

// CEO API delegation: allow ?action=get_dashboard etc. from public docroot
// (canonical API also available at /ceo.php and root index.php router).
$__action = $_GET['action'] ?? '';
if (in_array($__action, ['submit_lead', 'get_dashboard', 'get_blocks', 'get_current_block'], true)) {
    require __DIR__ . '/../ceo/index.php';
    exit;
}

// Initialize the integration
$integration = SlackIntegration::getInstance();
$webhook = $integration->webhook();

// Handle the incoming request
$result = $webhook->process(function ($event) use ($integration) {
    $eventType = $event['event_type'] ?? null;
    $channel = $event['channel'] ?? null;
    $user = $event['user'] ?? null;
    $text = $event['text'] ?? null;

    // Route events based on type
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
            $integration->getLogger()->info("Unhandled event type", ['type' => $eventType]);
    }
});

// Send acknowledgment (only if headers not already sent)
if (!headers_sent()) {
    header('Content-Type: application/json');
}
echo json_encode(['ok' => true]);
exit;

/**
 * Handle incoming messages
 */
function handleMessage($integration, $channel, $user, $text): void
{
    if (empty($text)) {
        return;
    }
    if (isCeoCommand((string)$text)) {
        handleCeoSlackCommand($integration, $channel, $user, (string)$text);
        return;
    }
    if (stripos($text, 'hello') !== false || stripos($text, 'hi') !== false) {
        $integration->replyThread($channel, $user, "Hello! 👋 I'm the SCITBD Slack Bot. Use /help for available commands. Try `ceo summary`.");
    }
}

/**
 * Handle @bot mentions
 */
function handleAppMention($integration, $channel, $user, $text): void
{
    if (isCeoCommand((string)$text)) {
        handleCeoSlackCommand($integration, $channel, $user, (string)$text);
        return;
    }
    $integration->send($channel, "Hi <@{$user}>! I'm here to help. Send me messages or use slash commands.", [
        ['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => "*Available Commands:*\n/help - Show help\n/deploy - Trigger deployment notification\n/status - System status check\n/bug - Report a bug\n/channels - List all channels\n*CEO:*\n`ceo list` - Pending tasks (current BST block)\n`ceo summary` - Daily progress\n`ceo block` - Current BST block\n`ceo create <title>` - Create task"]],
    ]);
}

/**
 * Handle button clicks and interactive components
 */
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

function handleCeoSlackCommand($integration, $channel, $user, string $text): void
{
    try {
        $ceo = new CeoTaskManager();
    } catch (Throwable $e) {
        $integration->send($channel, '⚠️ CEO database unavailable: ' . $e->getMessage());
        return;
    }
    $clean = trim(preg_replace('/<@[^>]+>\s*/', '', $text) ?? $text);
    $after = trim((string)preg_replace('/^.*?ceo\s+/i', '', $clean));
    $parts = preg_split('/\s+/', $after, 3) ?: [];
    $verb = strtolower($parts[0] ?? 'help');

    try {
        switch ($verb) {
            case 'list':
            case 'tasks': {
                $block = $ceo->getCurrentBSTBlock();
                $tasks = $ceo->pendingForBlock($block ? (int)$block['id'] : null, 10);
                $blocks = CeoTaskManager::formatTasksForSlack($tasks, $block);
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
                        ['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => "🕐 *{$block['block_name']}*\n*BST:* {$block['bst_start']}–{$block['bst_end']} · *Focus:* {$block['regional_focus']}" ]],
                    ]);
                }
                break;
            }
            case 'create': {
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
                    'created_by' => 'slack:' . ($user ?? 'unknown'),
                    'performed_by' => 'slack:' . ($user ?? 'unknown'),
                ]);
                $integration->send($channel, "Task #{$task['id']} created", [
                    ['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => "✅ *Task #{$task['id']} created*\n{$task['task_title']}\nPriority: `{$task['priority']}`"]],
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
        $integration->send($channel, '⚠️ CEO command failed: ' . $e->getMessage());
    }
}
