<?php
/**
 * Message Builder - Create rich Slack messages with Block Kit
 * Supports Block Kit components, attachments, and formatted text
 */

namespace SCITBD\Slack\Message;

class MessageBuilder
{
    public static function text(string $text, bool $mrkdwn = true): array
    {
        return ['type' => 'section', 'text' => ['type' => $mrkdwn ? 'mrkdwn' : 'plain_text', 'text' => $text]];
    }

    public static function header(string $text): array
    {
        return ['type' => 'header', 'text' => ['type' => 'plain_text', 'text' => $text, 'emoji' => true]];
    }

    public static function divider(): array
    {
        return ['type' => 'divider'];
    }

    public static function context(array $elements): array
    {
        return ['type' => 'context', 'elements' => $elements];
    }

    public static function section(string $text, ?array $accessory = null): array
    {
        $section = ['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => $text]];
        if ($accessory) {
            $section['accessory'] = $accessory;
        }
        return $section;
    }

    public static function button(string $text, string $actionId, string $style = 'primary'): array
    {
        return ['type' => 'button', 'text' => ['type' => 'plain_text', 'text' => $text, 'emoji' => true], 'action_id' => $actionId, 'style' => $style];
    }

    public static function select(string $placeholder, string $actionId, array $options): array
    {
        return ['type' => 'actions', 'elements' => [['type' => 'static_select', 'placeholder' => ['type' => 'plain_text', 'text' => $placeholder, 'emoji' => true], 'action_id' => $actionId, 'options' => $options]]];
    }

    public static function option(string $label, string $value, ?string $description = null): array
    {
        return ['text' => ['type' => 'plain_text', 'text' => $label, 'emoji' => true], 'value' => $value, 'description' => $description ? ['type' => 'plain_text', 'text' => $description] : null];
    }

    public static function image(string $url, string $altText = ''): array
    {
        return ['type' => 'image', 'image_url' => $url, 'alt_text' => $altText];
    }

    public static function multiText(array $texts): array
    {
        $fields = array_map(fn($t) => ['type' => 'mrkdwn', 'text' => $t], $texts);
        return ['type' => 'section', 'fields' => $fields];
    }

    public static function confirm(string $title, string $text, string $confirmLabel = 'Yes', string $denyLabel = 'No'): array
    {
        return ['confirm' => ['title' => ['type' => 'plain_text', 'text' => $title, 'emoji' => true], 'text' => ['type' => 'mrkdwn', 'text' => $text], 'confirm' => ['type' => 'plain_text', 'text' => $confirmLabel, 'emoji' => true], 'deny' => ['type' => 'plain_text', 'text' => $denyLabel, 'emoji' => true]]];
    }

    public static function build(array $blocks, string $text = '', array $attachments = []): array
    {
        return array_filter(['blocks' => !empty($blocks) ? $blocks : null, 'text' => !empty($text) ? $text : null, 'attachments' => !empty($attachments) ? $attachments : null]);
    }

    public static function deploymentNotification(string $buildId, string $status, string $branch, string $deployer): array
    {
        $statusEmoji = $status === 'success' ? '✅' : ($status === 'failed' ? '❌' : '⚠️');
        return self::build([
            self::header("{$statusEmoji} Deployment: {$buildId}"),
            self::divider(),
            self::multiText([
                "*Status:* {$status}",
                "*Branch:* {$branch}",
                "*Deployed by:* {$deployer}",
                "*Time:* " . date('Y-m-d H:i:s'),
            ]),
            self::divider(),
            self::context([self::text("*Build #{$buildId} has been {$status}*")]),
        ]);
    }

    public static function bugReport(string $title, string $description, string $reporter, string $priority = 'medium'): array
    {
        $priorityEmoji = ['low' => '🟢', 'medium' => '🟡', 'high' => '🔴', 'critical' => '🟣'];
        $emoji = $priorityEmoji[$priority] ?? '🟡';
        return self::build([
            self::header("{$emoji} Bug Report: {$title}"),
            self::divider(),
            self::multiText([
                "*Priority:* {$priority}",
                "*Reporter:* {$reporter}",
                "*Reported:* " . date('Y-m-d H:i:s'),
            ]),
            self::divider(),
            self::text($description),
        ]);
    }

    public static function taskAssignment(string $task, string $assignedTo, string $channel, ?string $dueDate = null): array
    {
        $blocks = [self::header("📋 Task Assigned"), self::divider(), self::multiText([
            "*Task:* {$task}",
            "*Assigned to:* {@{$assignedTo}}",
            "*Channel:* #{$channel}",
        ])];
        if ($dueDate) {
            $blocks[] = self::multiText(["*Due:* {$dueDate}"]);
        }
        $blocks[] = self::divider();
        $blocks[] = ['type' => 'actions', 'elements' => [self::button('Accept', 'accept_task', 'primary'), self::button('Defer', 'defer_task', 'secondary')]];
        return self::build($blocks);
    }

    public static function actions(array $buttons): array
    {
        return ['type' => 'actions', 'elements' => $buttons];
    }

    public static function statusUpdate(array $checks): array
    {
        $blocks = [self::header("📊 System Status Update"), self::divider()];
        $fields = [];
        foreach ($checks as $name => $status) {
            $emoji = $status === 'healthy' ? '✅' : '❌';
            $fields[] = "*{$name}:* {$emoji} " . ucfirst($status);
        }
        $blocks[] = self::multiText($fields);
        $blocks[] = self::divider();
        $blocks[] = self::context([self::text("*Updated:* " . date('Y-m-d H:i:s'))]);
        return self::build($blocks);
    }
}
