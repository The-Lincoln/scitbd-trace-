<?php
/**
 * Tests for SlackClient
 */

namespace SCITBD\Slack\Tests;

use PHPUnit\Framework\TestCase;
use SCITBD\Slack\Client\SlackClient;
use SCITBD\Slack\Message\MessageBuilder;
use SCITBD\Slack\Exception\AuthenticationException;
use SCITBD\Slack\Config\Config;

class SlackClientTest extends TestCase
{
    protected function setUp(): void
    {
        putenv('SLACK_BOT_TOKEN=xoxb-test-token-12345');
        putenv('SLACK_SIGNING_SECRET=test-signing-secret');
        Config::load();
    }

    public function testClientInitialization(): void
    {
        $client = new SlackClient();
        $this->assertInstanceOf(SlackClient::class, $client);
    }

    public function testMissingTokenThrowsException(): void
    {
        $this->expectException(AuthenticationException::class);
        new SlackClient('');
    }

    public function testMessageBuilderCreatesValidBlocks(): void
    {
        $blocks = MessageBuilder::build([
            MessageBuilder::header("Test Header"),
            MessageBuilder::divider(),
            MessageBuilder::text("Test message"),
        ], "Test message");

        $this->assertArrayHasKey('blocks', $blocks);
        $this->assertCount(3, $blocks['blocks']);
        $this->assertEquals('Test message', $blocks['text']);
    }

    public function testDeploymentNotification(): void
    {
        $blocks = MessageBuilder::deploymentNotification('BUILD-123', 'success', 'main', 'developer');
        $this->assertArrayHasKey('blocks', $blocks);
        $this->assertStringContainsString('BUILD-123', json_encode($blocks));
    }

    public function testBugReport(): void
    {
        $blocks = MessageBuilder::bugReport('Test Bug', 'Description', 'reporter', 'high');
        $this->assertStringContainsString('Test Bug', json_encode($blocks));
        $this->assertStringContainsString('high', json_encode($blocks));
    }

    public function testStatusUpdate(): void
    {
        $blocks = MessageBuilder::statusUpdate([
            'API' => 'healthy',
            'DB' => 'healthy',
        ]);
        $json = json_encode($blocks);
        $this->assertStringContainsString('Healthy', $json);
        $this->assertStringContainsString('System Status Update', $json);
    }

    public function testMultiText(): void
    {
        $fields = MessageBuilder::multiText(['*Field1:* Value1', '*Field2:* Value2']);
        $this->assertEquals('section', $fields['type']);
        $this->assertCount(2, $fields['fields']);
    }

    public function testMessageBuilderWithActions(): void
    {
        $blocks = MessageBuilder::build([
            MessageBuilder::header("Task"),
            MessageBuilder::actions([
                MessageBuilder::button('Accept', 'accept', 'primary'),
            ]),
        ]);
        $this->assertArrayHasKey('blocks', $blocks);
        $this->assertEquals('actions', $blocks['blocks'][1]['type']);
    }
}
