<?php
/**
 * Base exception class for Slack integration errors
 */

namespace SCITBD\Slack\Exception;

class SlackException extends \Exception
{
    public function __construct(string $message, int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct("Slack Error: {$message}", $code, $previous);
    }
}
