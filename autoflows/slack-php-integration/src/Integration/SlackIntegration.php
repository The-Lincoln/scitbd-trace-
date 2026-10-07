<?php
/**
 * SlackIntegration - Main integration class that ties everything together
 */

namespace SCITBD\Slack\Integration;

use SCITBD\Slack\Client\SlackClient;
use SCITBD\Slack\Channel\ChannelManager;
use SCITBD\Slack\Webhook\WebhookReceiver;
use SCITBD\Slack\Message\MessageBuilder;
use SCITBD\Slack\Config\Config;
use SCITBD\Slack\Exception\SlackException;
use SCITBD\Slack\Ceo\CeoTaskManager;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

class SlackIntegration
{
    private SlackClient $client;
    private ChannelManager $channels;
    private WebhookReceiver $webhook;
    private Logger $logger;
    private static ?self $instance = null;

    private function __construct()
    {
        $this->client = new SlackClient();
        $this->channels = new ChannelManager($this->client);
        $this->webhook = new WebhookReceiver();
        $this->logger = new Logger('slack_integration');
        $logPath = __DIR__ . '/../../logs/slack_integration.log';
        if (!file_exists(dirname($logPath))) {
            mkdir(dirname($logPath), 0755, true);
        }
        $this->logger->pushHandler(new StreamHandler($logPath, Logger::INFO));
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function send(string $channel, string $text, array $blocks = []): array
    {
        $result = $this->client->sendMessage($channel, $text, $blocks);
        $this->logger->info("Message sent", ['channel' => $channel]);
        return $result;
    }

    public function sendWebhook(string $webhookUrl, array $payload): array
    {
        return $this->client->sendWebhookMessage($webhookUrl, $payload);
    }

    public function channels(): ChannelManager
    {
        return $this->channels;
    }

    public function webhook(): WebhookReceiver
    {
        return $this->webhook;
    }

    public function client(): SlackClient
    {
        return $this->client;
    }

    public function messages(): MessageBuilder
    {
        return new MessageBuilder();
    }

    public function notifyDeployment(string $buildId, string $status, string $branch, string $deployer, string $channel = '#deployments'): array
    {
        $blocks = MessageBuilder::deploymentNotification($buildId, $status, $branch, $deployer);
        return $this->send($channel, "Deployment #{$buildId}: {$status}", $blocks);
    }

    public function reportBug(string $title, string $description, string $reporter, string $priority = 'medium', string $channel = '#bugs'): array
    {
        $blocks = MessageBuilder::bugReport($title, $description, $reporter, $priority);
        return $this->send($channel, "Bug Report: {$title}", $blocks);
    }

    public function statusUpdate(array $checks, string $channel = '#status'): array
    {
        $blocks = MessageBuilder::statusUpdate($checks);
        return $this->send($channel, 'System Status Update', $blocks);
    }

    public function replyThread(string $channel, string $threadTs, string $text): array
    {
        return $this->client->sendThreadReply($channel, $threadTs, $text);
    }

    public function react(string $channel, string $timestamp, string $emoji): array
    {
        return $this->client->addReaction($channel, $timestamp, $emoji);
    }

    public function createPublicChannel(string $name): array
    {
        return $this->channels->createChannel($name, 'public_channel');
    }

    public function createPrivateChannel(string $name): array
    {
        return $this->channels->createChannel($name, 'private_channel');
    }

    public function getLogger(): Logger
    {
        return $this->logger;
    }

    // ---------------- CEO Task Management bridge ----------------

    public function ceo(?string $dbPath = null): CeoTaskManager
    {
        return new CeoTaskManager($dbPath);
    }

    /**
     * Post a CEO task creation notification to Slack with Done button.
     */
    public function notifyCeoTask(array $task, string $channel = '#general'): array
    {
        $blocks = [
            ['type' => 'header', 'text' => ['type' => 'plain_text', 'text' => "📋 CEO Task #{$task['id']}", 'emoji' => true]],
            ['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => "*{$task['task_title']}*\nPriority: `{$task['priority']}` · Category: `{$task['category']}` · Status: `{$task['status']}`"]],
            ['type' => 'actions', 'elements' => [
                ['type' => 'button', 'text' => ['type' => 'plain_text', 'text' => 'Mark Done', 'emoji' => true], 'action_id' => 'ceo_complete_task', 'value' => (string)$task['id'], 'style' => 'primary'],
            ]],
        ];
        return $this->send($channel, "CEO Task #{$task['id']}: {$task['task_title']}", $blocks);
    }

    /**
     * Post the daily CEO summary (BST) to a channel.
     */
    public function postCeoDailySummary(string $channel = '#general', ?string $date = null): array
    {
        $ceo = $this->ceo();
        $summary = $ceo->getSummary($date);
        $block = $ceo->getCurrentBSTBlock();
        return $this->send($channel, "Daily summary {$summary['date']}", CeoTaskManager::formatSummaryForSlack($summary, $block));
    }

    /**
     * Post pending tasks for the current BST block to a channel.
     */
    public function postCeoPendingTasks(string $channel = '#general', int $limit = 10): array
    {
        $ceo = $this->ceo();
        $block = $ceo->getCurrentBSTBlock();
        $tasks = $ceo->pendingForBlock($block ? (int)$block['id'] : null, $limit);
        return $this->send($channel, 'CEO pending tasks', CeoTaskManager::formatTasksForSlack($tasks, $block));
    }
}
