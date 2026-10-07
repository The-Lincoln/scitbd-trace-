<?php
/**
 * Exception thrown when authentication fails
 */

namespace SCITBD\Slack\Exception;

class AuthenticationException extends SlackException
{
    public function __construct(string $message = "Invalid Slack credentials")
    {
        parent::__construct($message);
    }
}
