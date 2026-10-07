<?php
/**
 * Configuration loader for Slack PHP Integration
 * Loads environment variables and provides app configuration
 */

namespace SCITBD\Slack\Config;

class Config
{
    private static array $settings = [];

    /**
     * Load configuration from .env file or environment variables
     */
    public static function load(string $envPath = __DIR__ . '/../.env'): void
    {
        if (file_exists($envPath)) {
            $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                if (str_starts_with(trim($line), '#') || strpos(trim($line), '=') === false) {
                    continue;
                }
                [$key, $value] = explode('=', trim($line), 2);
                $_ENV[trim($key)] = trim($value);
                self::$settings[trim($key)] = trim($value);
            }
        }
    }

    /**
     * Get a configuration value by key
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        return self::$settings[$key] ?? $_ENV[$key] ?? $default;
    }

    /**
     * Get all configuration values
     */
    public static function all(): array
    {
        return array_merge($_ENV, self::$settings);
    }

    /**
     * Get Slack bot token
     */
    public static function getBotToken(): string
    {
        return self::get('SLACK_BOT_TOKEN', '');
    }

    /**
     * Get Slack signing secret
     */
    public static function getSigningSecret(): string
    {
        return self::get('SLACK_SIGNING_SECRET', '');
    }

    /**
     * Get Slack webhook URL
     */
    public static function getWebhookUrl(): string
    {
        return self::get('SLACK_WEBHOOK_URL', '');
    }

    /**
     * Get app URL
     */
    public static function getAppUrl(): string
    {
        return self::get('APP_URL', 'http://localhost:8080');
    }

    /**
     * Check if app is in debug mode
     */
    public static function isDebug(): bool
    {
        return filter_var(self::get('APP_DEBUG', false), FILTER_VALIDATE_BOOLEAN);
    }
}
