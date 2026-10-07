<?php
/**
 * BugReporter - Automated bug reporting integration
 * Creates structured bug reports in Slack channels
 */

namespace SCITBD\Slack\Integration;

use SCITBD\Slack\Message\MessageBuilder;

class BugReporter
{
    private SlackIntegration $integration;

    public function __construct(SlackIntegration $integration)
    {
        $this->integration = $integration;
    }

    /**
     * Report a bug with full details
     */
    public function report(string $title, string $description, string $reporter, string $priority = 'medium', array $metadata = []): array
    {
        $blocks = [
            MessageBuilder::header(MessageBuilder::text("🐛 Bug: {$title}")),
            MessageBuilder::divider(),
            MessageBuilder::multiText([
                "*Priority:* " . strtoupper($priority),
                "*Reporter:* {$reporter}",
                "*Reported:* " . date('Y-m-d H:i:s'),
                "*Ticket ID:* BUG-" . date('Ymd') . '-' . mt_rand(1000, 9999),
            ]),
            MessageBuilder::divider(),
            MessageBuilder::text("*Description:*\n{$description}"),
        ];

        if (!empty($metadata)) {
            $metaFields = [];
            foreach ($metadata as $key => $value) {
                $metaFields[] = "*{$key}:* {$value}";
            }
            $blocks[] = MessageBuilder::divider();
            $blocks[] = MessageBuilder::multiText($metaFields);
        }

        $blocks[] = MessageBuilder::divider();
        $blocks[] = MessageBuilder::actions([
            MessageBuilder::button('View Details', 'view_bug', 'primary'),
            MessageBuilder::button('Assign', 'assign_bug', 'secondary'),
        ]);

        return $this->integration->send('#bugs', "🐛 Bug Report: {$title}", $blocks);
    }

    /**
     * Report from an error exception
     */
    public function fromException(\Throwable $e, string $reporter): array
    {
        return $this->report(
            $e->getMessage(),
            "File: {$e->getFile()}\nLine: {$e->getLine()}\nTrace:\n{$e->getTraceAsString()}",
            $reporter,
            'high',
            [
                'Exception Type' => get_class($e),
                'Code' => (string)$e->getCode(),
            ]
        );
    }
}
