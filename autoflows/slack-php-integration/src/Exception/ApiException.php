<?php
/**
 * Exception thrown when an API request fails
 */

namespace SCITBD\Slack\Exception;

class ApiException extends SlackException
{
    public function __construct(string $message, int $code = 400, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
