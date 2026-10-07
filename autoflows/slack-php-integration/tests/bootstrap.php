<?php
/**
 * Test Bootstrap
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/config.php';

use SCITBD\Slack\Config\Config;

// Load configuration from .env
Config::load();

// Set test environment
putenv('APP_ENV=test');
putenv('APP_DEBUG=true');
putenv('SLACK_BOT_TOKEN=xoxb-test-token');
putenv('SLACK_SIGNING_SECRET=test-secret');

// Reload config after setting env vars
Config::load();
