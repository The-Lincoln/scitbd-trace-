<?php
/**
 * Incoming Webhook Handler
 * Process simple incoming webhook payloads from external systems
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/config.php';

use SCITBD\Slack\Config\Config;
use SCITBD\Slack\Integration\SlackIntegration;

Config::load(__DIR__ . '/../.env');

$integration = SlackIntegration::getInstance();

// Parse incoming JSON payload
$rawBody = file_get_contents('php://input');
$payload = json_decode($rawBody, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON']);
    exit;
}

// Route based on payload type
$type = $payload['type'] ?? 'generic';
$webhookUrl = Config::getWebhookUrl();

switch ($type) {
    case 'deployment':
        $integration->sendWebhook($webhookUrl, [
            'text' => "🚀 Deployment: {$payload['build_id']}",
            'blocks' => [
                ['type' => 'header', 'text' => ['type' => 'plain_text', 'text' => "🚀 Deployment #{$payload['build_id']}", 'emoji' => true]],
                ['type' => 'section', 'fields' => [
                    ['type' => 'mrkdwn', 'text' => "*Status:* {$payload['status']}"],
                    ['type' => 'mrkdwn', 'text' => "*Branch:* {$payload['branch']}"],
                ]],
            ],
        ]);
        break;

    case 'alert':
        $integration->sendWebhook($webhookUrl, [
            'text' => "🚨 Alert: {$payload['title']}",
            'blocks' => [
                ['type' => 'header', 'text' => ['type' => 'plain_text', 'text' => "🚨 {$payload['title']}", 'emoji' => true]],
                ['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => $payload['message']]],
            ],
        ]);
        break;

    case 'ticket':
        $integration->sendWebhook($webhookUrl, [
            'text' => "🎫 New Ticket: {$payload['title']}",
            'blocks' => [
                ['type' => 'header', 'text' => ['type' => 'plain_text', 'text' => "🎫 Ticket #{$payload['id']}", 'emoji' => true]],
                ['type' => 'section', 'fields' => [
                    ['type' => 'mrkdwn', 'text' => "*Priority:* {$payload['priority']}"],
                    ['type' => 'mrkdwn', 'text' => "*Assignee:* {$payload['assignee']}"],
                ]],
            ],
        ]);
        break;

    default:
        $integration->sendWebhook($webhookUrl, [
            'text' => "📨 Generic notification",
            'blocks' => [
                ['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => json_encode($payload, JSON_PRETTY_PRINT)]],
            ],
        ]);
        break;
}

http_response_code(200);
echo json_encode(['ok' => true]);
