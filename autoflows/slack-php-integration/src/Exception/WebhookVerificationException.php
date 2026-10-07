<?php
/**
 * Exception thrown when a webhook signature verification fails
 */

namespace SCITBD\Slack\Exception;

class WebhookVerificationException extends SlackException
{
    public function __construct(string $message = "Webhook signature verification failed")
    {
        parent::__construct($message);
    }
}
