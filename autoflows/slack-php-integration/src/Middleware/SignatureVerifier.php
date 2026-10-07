<?php
/**
 * Signature Verifier Middleware
 * Verifies Slack request signatures using the signing secret
 */

namespace SCITBD\Slack\Middleware;

use SCITBD\Slack\Exception\WebhookVerificationException;

class SignatureVerifier
{
    private string $signingSecret;

    public function __construct(string $signingSecret)
    {
        $this->signingSecret = $signingSecret;
    }

    /**
     * Verify the request signature from Slack
     * Uses HMAC-SHA256 signing method
     */
    public function verify(string $timestamp, string $body, string $signature): bool
    {
        if (empty($timestamp) || empty($signature)) {
            throw new WebhookVerificationException("Missing timestamp or signature");
        }

        // Prevent replay attacks
        if (abs(time() - (int)$timestamp) > 300) {
            throw new WebhookVerificationException("Request expired");
        }

        $baseString = "v0:{$timestamp}:{$body}";
        $expectedSignature = 'v0=' . hash_hmac('sha256', $baseString, $this->signingSecret);

        if (!hash_equals($expectedSignature, $signature)) {
            throw new WebhookVerificationException("Invalid signature");
        }

        return true;
    }

    /**
     * Verify from global server variables (convenience method)
     */
    public function verifyFromServer(string $body): bool
    {
        $timestamp = $_SERVER['HTTP_SLACK_REQUEST_TIMESTAMP'] ?? '';
        $signature = $_SERVER['HTTP_SLACK_SIGNATURE'] ?? '';
        return $this->verify($timestamp, $body, $signature);
    }
}
