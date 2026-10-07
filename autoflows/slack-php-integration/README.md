# SCITBD Slack PHP Integration

A comprehensive PHP library for integrating with Slack's API, featuring channel-based messaging, webhook handling, Block Kit message builder, automated deployment notifications, bug reporting, and more.

![PHP](https://img.shields.io/badge/PHP-8.2%2B-blue.svg)
![License](https://img.shields.io/badge/License-MIT-green.svg)
![Slack API](https://img.shields.io/badge/Slack-API-4A154B?logo=slack)

## 📋 Table of Contents

- [Features](#features)
- [Installation](#installation)
- [Quick Start](#quick-start)
- [Architecture](#architecture)
- [Core Components](#core-components)
- [Usage Examples](#usage-examples)
- [Webhook Setup](#webhook-setup)
- [Integration Guide](#integration-guide)
- [Channels & Messaging](#channels--messaging)
- [Block Kit Builder](#block-kit-builder)
- [Testing](#testing)
- [Project Structure](#project-structure)

## ✨ Features

| Feature | Description |
|---------|-------------|
| **Slack Web API Client** | Full-featured API client for channels, messages, users, reactions |
| **Incoming Webhooks** | Send messages to Slack channels via webhooks |
| **Event Subscriptions** | Receive and process Slack events and interactive components |
| **Block Kit Builder** | Rich message builder with Block Kit components |
| **Thread Messaging** | Reply to threads and broadcast replies |
| **Interactive Components** | Handle buttons, select menus, and action callbacks |
| **Deployment Notifications** | Automated deployment alerts to Slack |
| **Bug Reporting** | Structured bug reports with priority levels |
| **Channel Management** | Create, list, and manage Slack channels |
| **Signature Verification** | Secure webhook signature verification |
| **Auto-Response** | Bot command handling and auto-replies |

## 📦 Installation

### Prerequisites

- PHP 8.2 or higher
- Composer
- Slack App with Bot Token
- MySQL/MariaDB (optional, for persistent storage)

```bash
# Clone the repository
git clone https://github.com/scitbd/slack-php-integration.git
cd slack-php-integration

# Install dependencies
composer install

# Copy environment file
cp .env.example .env

# Set up database (optional)
mysql -u root -p < database/schema.sql
```

## 🚀 Quick Start

### 1. Configure Slack Credentials

Edit `.env`:
```env
SLACK_BOT_TOKEN=xoxb-your-bot-token
SLACK_SIGNING_SECRET=your-signing-secret
SLACK_WEBHOOK_URL=https://hooks.slack.com/services/YOUR/WEBHOOK/URL
APP_URL=http://localhost:8080
```

### 2. Start the Server

```bash
composer start
```

### 3. Basic Usage

```php
<?php
require_once 'vendor/autoload.php';
require_once 'config/config.php';

use SCITBD\Slack\Integration\SlackIntegration;
use SCITBD\Slack\Message\MessageBuilder;

// Get the singleton instance
$slack = SlackIntegration::getInstance();

// Send a simple message
$slack->send('#general', 'Hello, team! 👋');

// Send a rich message with Block Kit
$blocks = MessageBuilder::build([
    MessageBuilder::header('📢 Announcement'),
    MessageBuilder::divider(),
    MessageBuilder::text('New features are coming next week!'),
    MessageBuilder::divider(),
    MessageBuilder::actions([
        MessageBuilder::button('Learn More', 'learn_more', 'primary'),
        MessageBuilder::button('Dismiss', 'dismiss', 'secondary'),
    ]),
]);
$slack->send('#announcements', 'New Update!', $blocks);
```

## 🏗️ Architecture

```
slack-php-integration/
├── src/
│   ├── Client/
│   │   └── SlackClient.php          # Core API client
│   ├── Channel/
│   │   └── ChannelManager.php       # Channel operations
│   ├── Message/
│   │   └── MessageBuilder.php       # Block Kit builder
│   ├── Webhook/
│   │   └── WebhookReceiver.php      # Webhook handler
│   ├── Middleware/
│   │   └── SignatureVerifier.php    # Security middleware
│   ├── Integration/
│   │   ├── SlackIntegration.php     # Main integration class
│   │   ├── DeployNotifier.php       # Deployment notifications
│   │   └── BugReporter.php          # Bug reporting
│   ├── Config/
│   │   └── Config.php               # Configuration loader
│   └── Exception/
│       ├── SlackException.php       # Base exception
│       ├── AuthenticationException.php
│       ├── ApiException.php
│       └── WebhookVerificationException.php
├── public/
│   ├── index.php                    # Main entry point
│   ├── webhook.php                  # Webhook handler
│   └── incoming-webhook.php         # Incoming webhook handler
├── database/
│   └── schema.sql                   # Database schema
├── tests/                           # PHPUnit tests
├── config/
│   └── config.php                   # Configuration loader
├── logs/                            # Log files
├── vendor/                          # Composer dependencies
└── composer.json
```

## 🔌 Core Components

### SlackClient
The core HTTP client for the Slack Web API:

```php
$client = new SlackClient();

// Send a message
$client->sendMessage('#general', 'Hello!');

// Reply to a thread
$client->sendThreadReply('#general', '1234567890.123456', 'Reply in thread');

// Add reaction
$client->addReaction('#general', '1234567890.123456', '👍');

// List channels
$channels = $client->listChannels();

// Get channel history
$history = $client->getChannelHistory('#general');

// Create channel
$client->createChannel('new-project', 'public_channel');
```

### ChannelManager
Channel management operations:

```php
$channels = new ChannelManager($client);

// List all channels
$all = $channels->listAll();

// Create public channel
$channels->createPublic('proj-website-redesign');

// Create private channel
$channels->createPrivate('proj-secret-planning');

// Get formatted channel list
$public = $channels->getPublicChannelsFormatted();
```

### MessageBuilder
Build rich Slack messages with Block Kit:

```php
use SCITBD\Slack\Message\MessageBuilder;

// Simple text
MessageBuilder::text('Hello *world*');

// Header
MessageBuilder::header('📢 Announcement');

// Multi-field section
MessageBuilder::multiText([
    '*Status:* ✅ Running',
    '*Uptime:* 99.9%',
]);

// Deployment notification
MessageBuilder::deploymentNotification('BUILD-123', 'success', 'main', 'devops');

// Bug report
MessageBuilder::bugReport('Login Issue', 'Users cannot login', 'reporter', 'high');

// Status update
MessageBuilder::statusUpdate(['API' => 'healthy', 'DB' => 'healthy']);
```

### WebhookReceiver
Handle incoming Slack events securely:

```php
$webhook = new WebhookReceiver();

// Process incoming request
$result = $webhook->process(function ($event) {
    switch ($event['event_type']) {
        case 'message':
            // Handle message
            break;
        case 'block_actions':
            // Handle button clicks
            break;
    }
});

// Verify signature manually
$verified = $webhook->verifySignature($timestamp, $body, $signature);
```

## 📡 Slack Integration Patterns

### 1. Project Collaboration
Instead of long email threads, organize discussions by project channels.

```php
// Post a bug report to #dev-bug-fixes
$slack->reportBug('Payment Gateway Error', 'Exception on checkout', 'alex', 'high');

// Reply in thread
$ts = $message['ts'];
$slack->replyThread('#dev-bug-fixes', $ts, 'Looking into it...');
```

### 2. File Sharing and Feedback

```php
// Post wireframe review notification
$slack->send('#design-review', 'Wireframes ready for review', [
    MessageBuilder::text('Attached the landing page wireframes below.'),
    ['type' => 'actions', 'elements' => [
        MessageBuilder::button('Approve', 'approve', 'primary'),
        MessageBuilder::button('Request Changes', 'changes', 'secondary'),
    ]],
]);
```

### 3. Automated Alerts & Webhooks

```php
// GitHub deployment webhook
$slack->notifyDeployment('BUILD-456', 'success', 'main', 'CI/CD');

// Error alert
$slack->send('#incidents', '🚨 System Alert', [
    MessageBuilder::header('🚨 Critical Alert'),
    MessageBuilder::text('Database connection pool exhausted'),
]);
```

## 🌐 Webhook Setup

### Step 1: Create Slack App
1. Go to [api.slack.com/apps](https://api.slack.com/apps)
2. Click "Create New App"
3. Choose "From scratch"

### Step 2: Add Bot Permissions
Required scopes:
- `chat:write` - Send messages
- `channels:read` - List channels
- `channels:manage` - Create channels
- `reactions:write` - Add reactions
- `files:write` - Upload files

### Step 3: Configure Event Subscriptions
1. Enable Events
2. Set Request URL to `https://your-domain.com/webhook.php`
3. Subscribe to bot events: `message`, `app_mention`, `block_actions`

### Step 4: Set Up Incoming Webhooks
1. Go to "Incoming Webhooks"
2. Activate and add webhook to channels
3. Copy the webhook URL to `.env`

### Step 5: Install App to Workspace
1. Install the app
2. Copy Bot User OAuth Token to `.env`
3. Copy Signing Secret to `.env`

## 🔐 Security

### Signature Verification
All incoming requests are verified using HMAC-SHA256:

```php
use SCITBD\Slack\Middleware\SignatureVerifier;

$verifier = new SignatureVerifier($signingSecret);
$verified = $verifier->verifyFromServer($body);
```

The `SignatureVerifier` also:
- Prevents replay attacks (5-minute window)
- Uses `hash_equals()` for timing-safe comparison
- Validates all required headers

## 📊 Usage Examples

### Deployment Pipeline Integration

```php
$notifier = new DeployNotifier($slack);

// Build started
$notifier->started('BUILD-789', 'feature/login', 'jenkins');

// Build success
$notifier->success('BUILD-789', 'feature/login', 'jenkins');

// Build failed
$notifier->failed('BUILD-789', 'feature/login', 'jenkins', 'Timeout exceeded');

// Rollback
$notifier->rolledBack('BUILD-789', 'main', 'jenkins');
```

### Bug Tracking Integration

```php
$reporter = new BugReporter($slack);

// Report a bug
$reporter->report(
    'Login page crashes',
    'Application crashes when submitting credentials',
    'developer',
    'high',
    ['Browser' => 'Chrome', 'OS' => 'macOS']
);

// Report from exception
try {
    // risky code
} catch (\Exception $e) {
    $reporter->fromException($e, 'automated-system');
}
```

### Interactive Button Handling

```php
// Send message with buttons
$slack->send('#project-alpha', 'Task Review', [
    MessageBuilder::actions([
        MessageBuilder::button('Approve', 'approve_task', 'primary'),
        MessageBuilder::button('Reject', 'reject_task', 'danger'),
    ]),
]);

// In webhook handler
if ($event['event_type'] === 'block_actions') {
    foreach ($event['actions'] as $action) {
        if ($action['action_id'] === 'approve_task') {
            $slack->replyThread($channel, $ts, 'Task approved! ✅');
        }
    }
}
```

### Keyboard Shortcuts & Mentions in Messages

```php
// Mention users
$slack->send('#general', 'Hey @sarah, check this out!');

// Mention everyone
$slack->send('#announcements', '@here Important announcement!');

// Format text
$slack->send('#general', '*Bold* text, `code` block, _italic_ text');

// Format as Slack markdown (mrkdwn)
$slack->send('#general', 'Link: <https://example.com|Example>');
```

### Channel Operations

```php
// Create project channels
$slack->createPublicChannel('proj-website-redesign');
$slack->createPrivateChannel('proj-security-audit');

// List all channels
$channels = $slack->channels()->listAll();

// Get channel history
$history = $slack->channels()->getHistory($channelId, 50);

// Post in channel
$slack->send('#proj-website-redesign', 'Design review at 3 PM!');
```

## 🧪 Testing

```bash
# Run all tests
composer test

# Run tests with coverage
./vendor/bin/phpunit --coverage-text

# Run specific test
./vendor/bin/phpunit tests/SlackClientTest.php

# Code linting
composer lint
```

## 📝 Available Slash Commands

| Command | Description |
|---------|-------------|
| `/help` | Show available commands |
| `/deploy` | Trigger deployment notification |
| `/status` | Check system status |
| `/channels` | List all channels |
| `/bug <title> <desc>` | Report a bug |
| `/task <text> <@user>` | Assign a task |

## 📁 Project Structure

```
├── src/                     # Source code
│   ├── Client/             # API client
│   ├── Channel/            # Channel management
│   ├── Message/            # Block Kit builder
│   ├── Webhook/            # Webhook handling
│   ├── Middleware/         # Security middleware
│   ├── Integration/        # High-level integrations
│   ├── Config/             # Configuration
│   └── Exception/          # Custom exceptions
├── public/                 # Web entry points
│   ├── index.php           # Main entry point
│   ├── webhook.php         # Webhook handler
│   └── incoming-webhook.php # Incoming webhooks
├── database/               # Database schema
│   └── schema.sql
├── tests/                  # PHPUnit tests
├── config/                 # Config files
├── logs/                   # Application logs
└── vendor/                 # Composer dependencies
```

## 🐛 Troubleshooting

### Common Issues

1. **Invalid Token**: Verify bot token starts with `xoxb-`
2. **Signature Error**: Check signing secret matches Slack app settings
3. **Channel Not Found**: Ensure bot is a member of the channel
4. **Webhook Not Responding**: Verify URL is accessible and returns 200 within 3 seconds

### Debug Mode
Set `APP_DEBUG=true` in `.env` for verbose logging. Logs are stored in `logs/`.

## 🤝 Contributing

1. Fork the repository
2. Create a feature branch
3. Write tests for new features
4. Run `composer lint` and `composer test`
5. Submit a pull request

## 📄 License

MIT License - see [LICENSE](LICENSE) for details.

## 📞 Support

For issues and questions, please open an issue on GitHub or contact the SCITBD team.
