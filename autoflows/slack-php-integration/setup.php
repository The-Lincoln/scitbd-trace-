<?php
/**
 * Environment Setup Script
 * Run this once to set up the project
 */

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/config/config.php';

use SCITBD\Slack\Config\Config;
use SCITBD\Slack\Integration\SlackIntegration;

echo "=== SCITBD Slack Integration Setup ===\n\n";

$phpVersion = php_version();
if (version_compare($phpVersion, '8.2.0', '<')) {
    echo "❌ PHP 8.2 or higher is required. Current: {$phpVersion}\n";
    exit(1);
}
echo "✅ PHP version: {$phpVersion}\n";

if (!file_exists(__DIR__ . '/vendor/autoload.php')) {
    echo "❌ Composer autoload not found. Run: composer install\n";
    exit(1);
}
echo "✅ Composer dependencies installed\n";

if (!file_exists(__DIR__ . '/.env')) {
    echo "⚠️  .env file not found. Copying .env.example...\n";
    copy(__DIR__ . '/.env.example', __DIR__ . '/.env');
    echo "✅ Created .env file\n";
} else {
    echo "✅ .env file exists\n";
}

Config::load();

if (empty(Config::getBotToken())) {
    echo "⚠️  SLACK_BOT_TOKEN not set in .env\n";
} else {
    echo "✅ Slack bot token configured\n";
}

if (empty(Config::getSigningSecret())) {
    echo "⚠️  SLACK_SIGNING_SECRET not set in .env\n";
} else {
    echo "✅ Slack signing secret configured\n";
}

if (!is_dir(__DIR__ . '/logs')) {
    mkdir(__DIR__ . '/logs', 0755, true);
    echo "✅ Created logs directory\n";
} else {
    echo "✅ Logs directory exists\n";
}

try {
    $slack = SlackIntegration::getInstance();
    echo "✅ Slack integration initialized\n";
} catch (Exception $e) {
    echo "⚠️  Could not initialize Slack: " . $e->getMessage() . "\n";
    echo "   Please verify your SLACK_BOT_TOKEN in .env\n";
}

echo "\n=== Setup Complete ===\n";
echo "Run `composer start` or `php -S localhost:8888 -t public/` to launch the server.\n";
echo "Visit http://localhost:8888 to access the application.\n";
