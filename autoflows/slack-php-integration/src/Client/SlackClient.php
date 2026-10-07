<?php
/**
 * Slack API Client - Core class for interacting with Slack Web API
 * Handles authentication, API requests, and response handling
 */

namespace SCITBD\Slack\Client;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use SCITBD\Slack\Config\Config;
use SCITBD\Slack\Exception\AuthenticationException;
use SCITBD\Slack\Exception\ApiException;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

class SlackClient
{
    private string $botToken;
    private Client $httpClient;
    private Logger $logger;
    private string $apiBaseUrl = 'https://slack.com/api';

    public function __construct(?string $botToken = null)
    {
        $this->botToken = $botToken ?? Config::getBotToken();
        if (empty($this->botToken)) {
            throw new AuthenticationException("Slack bot token is required");
        }
        $this->httpClient = new Client([
            'base_uri' => $this->apiBaseUrl,
            'timeout' => 30,
            'headers' => [
                'Authorization' => "Bearer {$this->botToken}",
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
        ]);
        $this->logger = new Logger('slack_client');
        $logPath = __DIR__ . '/../../logs/slack_client.log';
        if (!file_exists(dirname($logPath))) {
            mkdir(dirname($logPath), 0755, true);
        }
        $this->logger->pushHandler(new StreamHandler($logPath, Config::isDebug() ? Logger::DEBUG : Logger::INFO));
    }

    public function sendMessage(string $channel, string $text, array $blocks = [], array $attachments = []): array
    {
        $payload = ['channel' => $channel, 'text' => $text, 'blocks' => $blocks, 'attachments' => $attachments, 'unfurl_links' => false, 'unfurl_media' => false];
        $payload = array_filter($payload, fn($v) => !empty($v) || is_string($v));
        try {
            $response = $this->httpClient->post('/chat.postMessage', ['json' => $payload]);
            $data = json_decode($response->getBody()->getContents(), true);
            $this->logger->info("Message sent to channel: {$channel}", ['channel' => $channel, 'ts' => $data['ts'] ?? null, 'ok' => $data['ok'] ?? false]);
            return $data;
        } catch (RequestException $e) {
            $this->logger->error("Failed to send message", ['error' => $e->getMessage()]);
            throw new ApiException("Failed to send message: " . $e->getMessage(), 500, $e);
        }
    }

    public function sendWebhookMessage(string $webhookUrl, array $payload): array
    {
        try {
            $client = new Client(['timeout' => 30]);
            $response = $client->post($webhookUrl, ['json' => $payload]);
            $body = $response->getBody()->getContents();
            $this->logger->info("Webhook message sent", ['webhook' => $webhookUrl]);
            return ['ok' => true, 'body' => $body];
        } catch (RequestException $e) {
            $this->logger->error("Webhook request failed", ['error' => $e->getMessage()]);
            throw new ApiException("Webhook request failed: " . $e->getMessage(), 500, $e);
        }
    }

    public function listChannels(string $types = 'public_channel,private_channel'): array
    {
        try {
            $response = $this->httpClient->get('/conversations.list', ['query' => ['types' => $types, 'limit' => 100]]);
            $data = json_decode($response->getBody()->getContents(), true);
            return $data['channels'] ?? [];
        } catch (RequestException $e) {
            throw new ApiException("Failed to list channels: " . $e->getMessage(), 500, $e);
        }
    }

    public function getChannelInfo(string $channelId): array
    {
        try {
            $response = $this->httpClient->get('/conversations.info', ['query' => ['channel' => $channelId]]);
            return json_decode($response->getBody()->getContents(), true);
        } catch (RequestException $e) {
            throw new ApiException("Failed to get channel info: " . $e->getMessage(), 500, $e);
        }
    }

    public function createChannel(string $name, string $type = 'public_channel'): array
    {
        try {
            $response = $this->httpClient->post('/conversations.create', ['json' => ['name' => $name, 'is_private' => $type === 'private_channel']]);
            return json_decode($response->getBody()->getContents(), true);
        } catch (RequestException $e) {
            throw new ApiException("Failed to create channel: " . $e->getMessage(), 500, $e);
        }
    }

    public function sendThreadReply(string $channel, string $threadTs, string $text, array $blocks = []): array
    {
        try {
            $response = $this->httpClient->post('/chat.postMessage', ['json' => ['channel' => $channel, 'text' => $text, 'thread_ts' => $threadTs, 'blocks' => $blocks, 'reply_broadcast' => true]]);
            return json_decode($response->getBody()->getContents(), true);
        } catch (RequestException $e) {
            throw new ApiException("Failed to send thread reply: " . $e->getMessage(), 500, $e);
        }
    }

    public function addReaction(string $channel, string $timestamp, string $emoji): array
    {
        try {
            $response = $this->httpClient->post('/reactions.add', ['json' => ['channel' => $channel, 'timestamp' => $timestamp, 'name' => $emoji]]);
            return json_decode($response->getBody()->getContents(), true);
        } catch (RequestException $e) {
            throw new ApiException("Failed to add reaction: " . $e->getMessage(), 500, $e);
        }
    }

    public function getChannelHistory(string $channelId, int $limit = 50): array
    {
        try {
            $response = $this->httpClient->get('/conversations.history', ['query' => ['channel' => $channelId, 'limit' => $limit]]);
            return json_decode($response->getBody()->getContents(), true);
        } catch (RequestException $e) {
            throw new ApiException("Failed to get channel history: " . $e->getMessage(), 500, $e);
        }
    }

    public function getUserInfo(string $userId): array
    {
        try {
            $response = $this->httpClient->get('/users.info', ['query' => ['user' => $userId]]);
            return json_decode($response->getBody()->getContents(), true);
        } catch (RequestException $e) {
            throw new ApiException("Failed to get user info: " . $e->getMessage(), 500, $e);
        }
    }

    public function listUsers(): array
    {
        try {
            $response = $this->httpClient->get('/users.list');
            $data = json_decode($response->getBody()->getContents(), true);
            return $data['members'] ?? [];
        } catch (RequestException $e) {
            throw new ApiException("Failed to list users: " . $e->getMessage(), 500, $e);
        }
    }

    public function updateMessage(string $channel, string $timestamp, string $text, array $blocks = []): array
    {
        try {
            $response = $this->httpClient->post('/chat.update', ['json' => ['channel' => $channel, 'ts' => $timestamp, 'text' => $text, 'blocks' => $blocks]]);
            return json_decode($response->getBody()->getContents(), true);
        } catch (RequestException $e) {
            throw new ApiException("Failed to update message: " . $e->getMessage(), 500, $e);
        }
    }

    public function deleteMessage(string $channel, string $timestamp): array
    {
        try {
            $response = $this->httpClient->post('/chat.delete', ['json' => ['channel' => $channel, 'ts' => $timestamp]]);
            return json_decode($response->getBody()->getContents(), true);
        } catch (RequestException $e) {
            throw new ApiException("Failed to delete message: " . $e->getMessage(), 500, $e);
        }
    }

    public function getLogger(): Logger
    {
        return $this->logger;
    }
}
