<?php
/**
 * Slack Webhook Handler
 * Processes incoming Slack events, interactive components, and slash commands
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/config.php';

use SCITBD\Slack\Config\Config;
use SCITBD\Slack\Integration\SlackIntegration;
use SCITBD\Slack\Webhook\WebhookReceiver;
use SCITBD\Slack\Exception\WebhookVerificationException;
use SCITBD\Slack\Ceo\CeoTaskManager;

Config::load(__DIR__ . '/../.env');

$integration = SlackIntegration::getInstance();
$webhook = $integration->webhook();

try {
    $result = $webhook->process(function ($event) use ($integration) {
        $eventType = $event['event_type'] ?? null;
        $channel = $event['channel'] ?? null;
        $user = $event['user'] ?? null;
        $text = $event['text'] ?? null;
        $ts = $event['ts'] ?? null;
        $messageTs = $event['message_ts'] ?? null;

        switch ($eventType) {
            case 'message':
                $lowerText = strtolower($text ?? '');
                if (str_contains($lowerText, 'deploy')) {
                    $integration->notifyDeployment(
                        'BUILD-' . date('YmdHis'),
                        'success',
                        'main',
                        $user ?? 'unknown'
                    );
                }
                break;

            case 'app_mention':
                handleCommand($integration, $channel, $text);
                break;

            case 'block_actions':
                handleInteractiveActions($integration, $event);
                break;

            case 'message_changed':
                $integration->getLogger()->info("Message changed", ['channel' => $channel, 'ts' => $ts]);
                break;
        }
    });
} catch (WebhookVerificationException $e) {
    if (!headers_sent()) {
        http_response_code(403);
    }
    echo json_encode(['error' => $e->getMessage()]);
    exit;
} catch (\Exception $e) {
    if (!headers_sent()) {
        http_response_code(500);
    }
    echo json_encode(['error' => $e->getMessage()]);
    exit;
}

// Send acknowledgment
if (!headers_sent()) {
    header('Content-Type: application/json');
}
echo json_encode(['ok' => true]);
exit;

function handleCommand($integration, $channel, $text): void
{
    $parts = explode(' ', trim($text ?? ''));
    $command = strtolower($parts[1] ?? '');

    switch ($command) {
        case 'help':
            $integration->send($channel, "📚 *Help Center*\nAvailable commands:\n/deploy - Send deployment notification\n/status - Check system status\n/channels - List channels\n/bug <title> <desc> - Report a bug\n/task <text> <@user> - Assign a task", [
                ['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => '*Commands:*\n/deploy, /status, /channels, /bug, /task']],
            ]);
            break;

        case 'deploy':
            $integration->notifyDeployment('BUILD-' . date('YmdHis'), 'success', 'main', 'manual');
            break;

        case 'status':
            $integration->statusUpdate(['API' => 'healthy', 'DB' => 'healthy']);
            break;

        case 'channels':
            $channels = $integration->channels()->listAll();
            $channelList = implode(', ', array_map(fn($c) => '#' . ($c['name'] ?? ''), $channels));
            $integration->send($channel, "📋 *Channels:*\n{$channelList}");
            break;

        case 'bug':
            $title = $parts[2] ?? 'Untitled';
            $description = implode(' ', array_slice($parts, 3));
            $integration->reportBug($title, $description, 'manual');
            break;

        case 'task':
            $taskText = $parts[2] ?? 'No task';
            $assignedTo = $parts[3] ?? 'everyone';
            $integration->send($channel, "📋 Task: {$taskText}", [
                ['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => "📋 *{$taskText}*\nAssigned to: {@{$assignedTo}}"]],
                ['type' => 'actions', 'elements' => [
                    ['type' => 'button', 'text' => ['type' => 'plain_text', 'text' => 'Accept', 'emoji' => true], 'action_id' => 'accept_task', 'style' => 'primary'],
                    ['type' => 'button', 'text' => ['type' => 'plain_text', 'text' => 'Defer', 'emoji' => true], 'action_id' => 'defer_task', 'style' => 'secondary'],
                ]],
            ]);
            break;

        case 'ceo':
            handleCeoCommand($integration, $channel, $text);
            break;

        default:
            // CEO chatops fallback: `ceo list|summary|block|create ...`
            if (preg_match('/\bceo\s+(list|tasks|summary|block|create|done|complete|start|help)\b/i', $text ?? '')) {
                handleCeoCommand($integration, $channel, $text);
            } else {
                $integration->send($channel, "❓ Unknown command: /{$command}. Type /help for available commands. Try `ceo summary`.");
            }
            break;
    }
}

function handleCeoCommand($integration, $channel, $text): void
{
    try {
        $ceo = new CeoTaskManager();
    } catch (Throwable $e) {
        $integration->send($channel, '⚠️ CEO database unavailable: ' . $e->getMessage());
        return;
    }
    $clean = trim(preg_replace('/<@[^>]+>\s*/', '', (string)$text) ?? (string)$text);
    // support both "@bot ceo list" and "@bot help ceo list" shapes
    $after = trim((string)preg_replace('/^.*?ceo\s+/i', '', $clean));
    $parts = preg_split('/\s+/', $after, 3) ?: [];
    $verb = strtolower($parts[0] ?? 'help');
    try {
        switch ($verb) {
            case 'list':
            case 'tasks': {
                $block = $ceo->getCurrentBSTBlock();
                $tasks = $ceo->pendingForBlock($block ? (int)$block['id'] : null, 10);
                $integration->send($channel, 'CEO pending tasks', CeoTaskManager::formatTasksForSlack($tasks, $block));
                break;
            }
            case 'summary': {
                $integration->send($channel, 'CEO daily summary', CeoTaskManager::formatSummaryForSlack($ceo->getSummary(), $ceo->getCurrentBSTBlock()));
                break;
            }
            case 'block': {
                $block = $ceo->getCurrentBSTBlock();
                $integration->send($channel, $block ? "Active: {$block['block_name']}" : 'No active block', [
                    ['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => $block ? "🕐 *{$block['block_name']}*\n*BST:* {$block['bst_start']}–{$block['bst_end']} · *Focus:* {$block['regional_focus']}" : 'No active BST block']],
                ]);
                break;
            }
            case 'create': {
                $title = trim(substr($clean, stripos($clean, 'create') + 6));
                if ($title === '') {
                    $integration->send($channel, 'Usage: `ceo create <task title>`');
                    break;
                }
                $block = $ceo->getCurrentBSTBlock();
                $task = $ceo->createTask($title, ['priority' => 'medium', 'bst_block_id' => $block ? (int)$block['id'] : null, 'created_by' => 'slack:webhook']);
                $integration->notifyCeoTask($task, $channel);
                break;
            }
            case 'done':
            case 'complete': {
                $id = (int)($parts[1] ?? 0);
                if ($id <= 0) {
                    $integration->send($channel, 'Usage: `ceo done <task_id>`');
                    break;
                }
                $ceo->setStatus($id, 'completed', 'slack:webhook');
                $integration->send($channel, "✅ Task #{$id} completed.");
                break;
            }
            case 'start': {
                $id = (int)($parts[1] ?? 0);
                if ($id <= 0) {
                    $integration->send($channel, 'Usage: `ceo start <task_id>`');
                    break;
                }
                $ceo->setStatus($id, 'in_progress', 'slack:webhook');
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

function handleInteractiveActions($integration, $event): void
{
    $actions = $event['actions'] ?? [];
    $responseUrl = $event['response_url'] ?? '';
    $userId = $event['user']['id'] ?? '';
    $channelId = $event['channel']['id'] ?? '';

    // FIX (aligned with AutoFlows SlackApp::postToResponseUrl): process actions
    // FIRST, then ack once. Old code echoed + exit() here so ceo_complete_task
    // / accept / defer below never ran.

    foreach ($actions as $action) {
        $actionId = $action['action_id'] ?? '';
        switch ($actionId) {
            case 'accept_task':
                if ($responseUrl) {
                    $ch = curl_init($responseUrl);
                    curl_setopt_array($ch, [
                        CURLOPT_POST => true,
                        CURLOPT_POSTFIELDS => json_encode([
                            'response_type' => 'in_channel',
                            'text' => "<@{$userId}> accepted the task! ✅",
                            'replace_original' => true,
                            'blocks' => [['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => "✅ <@{$userId}> accepted the task!"]]],
                        ]),
                        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                        CURLOPT_TIMEOUT => 10,
                    ]);
                    curl_exec($ch);
                    curl_close($ch);
                }
                break;

            case 'defer_task':
                if ($responseUrl) {
                    $ch = curl_init($responseUrl);
                    curl_setopt_array($ch, [
                        CURLOPT_POST => true,
                        CURLOPT_POSTFIELDS => json_encode([
                            'response_type' => 'in_channel',
                            'text' => "<@{$userId}> deferred the task. ⏳",
                            'replace_original' => true,
                            'blocks' => [['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => "⏳ <@{$userId}> deferred the task."]]],
                        ]),
                        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                        CURLOPT_TIMEOUT => 10,
                    ]);
                    curl_exec($ch);
                    curl_close($ch);
                }
                break;

            case 'ceo_complete_task': {
                // Aligned with root index.php + public/index.php handlers:
                // quick-complete button from `ceo list` marks the task done.
                $taskId = (int)($action['value'] ?? 0);
                if ($taskId > 0) {
                    try {
                        $ceo = new CeoTaskManager();
                        $ceo->setStatus($taskId, 'completed', 'slack:' . ($userId !== '' ? $userId : 'webhook'));
                        $integration->send($channelId, "✅ CEO task #{$taskId} completed by <@{$userId}>");
                    } catch (Throwable $e) {
                        $integration->send($channelId, "⚠️ Could not complete task #{$taskId}: " . $e->getMessage());
                    }
                }
                break;
            }
        }
    }
    // NOTE: no echo/exit here — the caller sends the single {"ok":true} ack
    // after $webhook->process() returns. Exiting here would skip it / double-ack.
}
