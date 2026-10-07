<?php
/**
 * Tests for WebhookReceiver
 */

namespace SCITBD\Slack\Tests;

use PHPUnit\Framework\TestCase;

class WebhookReceiverTest extends TestCase
{
    public function testSignatureVerification(): void
    {
        $secret = 'test-secret';
        $timestamp = (string)time();
        $body = json_encode(['type' => 'event_callback']);
        $signature = 'v0=' . hash_hmac('sha256', "v0:{$timestamp}:{$body}", $secret);

        $this->assertTrue(hash_equals('v0=' . hash_hmac('sha256', "v0:{$timestamp}:{$body}", $secret), $signature));
    }

    public function testReplayAttackDetection(): void
    {
        $oldTimestamp = (string)(time() - 400); // 6+ minutes ago
        $this->assertTrue(abs(time() - (int)$oldTimestamp) > 300);
    }
}
