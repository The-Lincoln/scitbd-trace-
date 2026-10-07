<?php
/**
 * Channel Manager - Handles Slack channel operations
 * Create, manage, and organize channels for team communication
 */

namespace SCITBD\Slack\Channel;

use SCITBD\Slack\Client\SlackClient;
use SCITBD\Slack\Exception\SlackException;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

class ChannelManager
{
    private SlackClient $client;
    private Logger $logger;

    public function __construct(SlackClient $client)
    {
        $this->client = $client;
        $this->logger = new Logger('channel_manager');
        $logPath = __DIR__ . '/../../logs/channel_manager.log';
        if (!file_exists(dirname($logPath))) {
            mkdir(dirname($logPath), 0755, true);
        }
        $this->logger->pushHandler(new StreamHandler($logPath, Logger::INFO));
    }

    /**
     * List all channels
     */
    public function listAll(string $types = 'public_channel,private_channel'): array
    {
        $channels = $this->client->listChannels($types);
        $this->logger->info("Listed channels", ['count' => count($channels)]);
        return $channels;
    }

    /**
     * Get channel info by ID
     */
    public function getInfo(string $channelId): array
    {
        return $this->client->getChannelInfo($channelId);
    }

    /**
     * Create a public channel
     */
    public function createPublic(string $name): array
    {
        if (!preg_match('/^[a-z0-9_-]{1,80}$/', $name)) {
            throw new SlackException("Channel name must be lowercase, 1-80 chars: letters, numbers, hyphens, underscores");
        }
        $result = $this->client->createChannel($name, 'public_channel');
        $this->logger->info("Created public channel", ['name' => $name]);
        return $result;
    }

    /**
     * Create a private channel
     */
    public function createPrivate(string $name): array
    {
        if (!preg_match('/^[a-z0-9_-]{1,80}$/', $name)) {
            throw new SlackException("Channel name must be lowercase, 1-80 chars: letters, numbers, hyphens, underscores");
        }
        $result = $this->client->createChannel($name, 'private_channel');
        $this->logger->info("Created private channel", ['name' => $name]);
        return $result;
    }

    /**
     * Get channel history/messages
     */
    public function getHistory(string $channelId, int $limit = 50): array
    {
        return $this->client->getChannelHistory($channelId, $limit);
    }

    /**
     * Archive a channel
     */
    public function archive(string $channelId): array
    {
        try {
            $response = $this->client->getHttpClient()->post('/conversations.archive', [
                'json' => ['channel' => $channelId],
            ]);
            return json_decode($response->getBody()->getContents(), true);
        } catch (\Exception $e) {
            throw new SlackException("Failed to archive channel: " . $e->getMessage());
        }
    }

    /**
     * Get channel info by name
     */
    public function getByName(string $name): ?array
    {
        $channels = $this->listAll();
        foreach ($channels as $channel) {
            if (($channel['name'] ?? '') === $name) {
                return $channel;
            }
        }
        return null;
    }

    /**
     * Get all public channels as a formatted list
     */
    public function getPublicChannelsFormatted(): array
    {
        $channels = $this->listAll('public_channel');
        return array_map(function ($channel) {
            return [
                'id' => $channel['id'] ?? '',
                'name' => '#'.($channel['name'] ?? ''),
                'is_member' => $channel['is_member'] ?? false,
                'num_members' => $channel['num_members'] ?? 0,
                'created' => date('Y-m-d H:i:s', $channel['created'] ?? 0),
                'purpose' => $channel['purpose']['value'] ?? '',
            ];
        }, $channels);
    }

    /**
     * Get all private channels the bot has access to
     */
    public function getPrivateChannelsFormatted(): array
    {
        $channels = $this->listAll('private_channel');
        return array_map(function ($channel) {
            return [
                'id' => $channel['id'] ?? '',
                'name' => '#'.($channel['name'] ?? ''),
                'is_private' => true,
                'num_members' => $channel['num_members'] ?? 0,
            ];
        }, $channels);
    }

    /**
     * Check if bot is a member of a channel
     */
    public function isMember(string $channelId): bool
    {
        return $this->client->getChannelInfo($channelId)['channel']['is_member'] ?? false;
    }
}
