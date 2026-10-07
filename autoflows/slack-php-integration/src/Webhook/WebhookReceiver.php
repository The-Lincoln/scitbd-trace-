<?php
/**
 * Webhook Receiver - Handles incoming Slack events and interactive components
 * Verifies signatures and routes events to appropriate handlers
 */

namespace SCITBD\Slack\Webhook;

use SCITBD\Slack\Config\Config;
use SCITBD\Slack\Exception\WebhookVerificationException;
use SCITBD\Slack\Exception\SlackException;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

class WebhookReceiver
{
    private string $signingSecret;
    private Logger $logger;

    public function __construct(?string $signingSecret = null)
    {
        $this->signingSecret = $signingSecret ?? Config::getSigningSecret();

        if (empty($this->signingSecret)) {
            throw new SlackException("Slack signing secret is required for webhook verification");
        }

        $this->logger = new Logger('webhook_receiver');
        $logPath = __DIR__ . '/../../logs/webhook_receiver.log';
        if (!file_exists(dirname($logPath))) {
            mkdir(dirname($logPath), 0755, true);
        }
        $this->logger->pushHandler(new StreamHandler($logPath, Logger::DEBUG));
    }

    /**
     * Verify the Slack request signature
     * Uses the signing secret to validate the request comes from Slack
     */
    public function verifySignature(string $timestamp, string $body, string $signature): bool
    {
        // Prevent replay attacks - reject requests older than 5 minutes
        $currentTime = time();
        if (abs($currentTime - (int)$timestamp) > 300) {
            $this->logger->warning("Request timestamp too old", ['timestamp' => $timestamp, 'current' => $currentTime]);
            throw new WebhookVerificationException("Request timestamp is invalid");
        }

        $baseString = "v0:{$timestamp}:{$body}";
        $expectedSignature = 'v0=' . hash_hmac('sha256', $baseString, $this->signingSecret);

        if (!hash_equals($expectedSignature, $signature)) {
            $this->logger->error("Signature verification failed", [
                'expected' => $expectedSignature,
                'received' => $signature,
            ]);
            throw new WebhookVerificationException("Invalid signature");
        }

        $this->logger->info("Signature verified successfully");
        return true;
    }

    /**
     * Get the raw POST body
     */
    public function getRawBody(): string
    {
        return file_get_contents('php://input');
    }

    /**
     * Parse the incoming request
     */
    public function parseRequest(): array
    {
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        $body = $this->getRawBody();

        // Parse based on content type
        if (strpos($contentType, 'application/x-www-form-urlencoded') !== false) {
            parse_str($body, $parsed);
            return $parsed;
        }

        // JSON payload
        $data = json_decode($body, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new SlackException("Invalid JSON in request body");
        }

        return $data;
    }

    /**
     * Handle an incoming event
     */
    public function handleEvent(): array
    {
        $data = $this->parseRequest();

        // URL verification challenge (first request from Slack)
        if (isset($data['type']) && $data['type'] === 'url_verification') {
            return [
                'success' => true,
                'response' => [
                    'challenge' => $data['challenge'],
                    'type' => 'ephemeral',
                ],
            ];
        }

        // Event callback
        if (isset($data['type']) && $data['type'] === 'event_callback') {
            $event = $data['event'];
            $this->logger->info("Received event", ['event_type' => $event['type'] ?? 'unknown']);

            return [
                'success' => true,
                'event' => $event,
                'event_type' => $event['type'] ?? null,
                'channel' => $event['channel'] ?? null,
                'user' => $event['user'] ?? null,
                'text' => $event['text'] ?? null,
                'ts' => $event['ts'] ?? null,
                'message_ts' => $event['message_ts'] ?? null,
            ];
        }

        // Interactive component (buttons, select menus, etc.)
        if (isset($data['type']) && $data['type'] === 'block_actions') {
            $actions = $data['actions'] ?? [];
            $this->logger->info("Received block actions", ['action_count' => count($actions)]);

            return [
                'success' => true,
                'type' => 'block_actions',
                'actions' => $actions,
                'message' => $data['message'] ?? null,
                'message_ts' => $data['message_ts'] ?? null,
                'trigger_id' => $data['trigger_id'] ?? null,
                'response_url' => $data['response_url'] ?? null,
                'channel' => $data['channel'] ?? null,
                'user' => $data['user'] ?? null,
            ];
        }

        // Block suggestion
        if (isset($data['type']) && $data['type'] === 'block_suggestion') {
            return [
                'success' => true,
                'type' => 'block_suggestion',
                'selected_option' => $data['selected_option'] ?? null,
            ];
        }

        return ['success' => false, 'type' => 'unknown', 'data' => $data];
    }

    /**
     * Process an incoming event and call the appropriate handler
     */
    public function process(callable $eventHandler = null): array
    {
        try {
            // Get signature headers
            $timestamp = $_SERVER['HTTP_SLACK_REQUEST_TIMESTAMP'] ?? '';
            $signature = $_SERVER['HTTP_SLACK_SIGNATURE'] ?? '';
            $body = $this->getRawBody();

            // Verify signature
            $this->verifySignature($timestamp, $body, $signature);

            // Handle the event
            $result = $this->handleEvent();

            if ($eventHandler && $result['success']) {
                call_user_func($eventHandler, $result);
            }

            return $result;
        } catch (WebhookVerificationException $e) {
            $this->logger->error("Webhook verification failed", ['error' => $e->getMessage()]);
            http_response_code(403);
            return ['success' => false, 'error' => $e->getMessage()];
        } catch (\Exception $e) {
            $this->logger->error("Webhook processing error", ['error' => $e->getMessage()]);
            http_response_code(500);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Send an acknowledgment response (for interactive components)
     */
    public function sendAcknowledgment(): void
    {
        header('Content-Type: application/json');
        echo json_encode(['ok' => true]);
        exit;
    }

    /**
     * Send a response via response_url (for delayed/updated responses)
     */
    public function sendResponse(string $responseUrl, array $payload): bool
    {
        $ch = curl_init($responseUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT => 10,
        ]);
        $result = curl_exec($ch);
        curl_close($ch);
        return $result !== false;
    }

    /**
     * Get the logger
     */
    public function getLogger(): Logger
    {
        return $this->logger;
    }
}
