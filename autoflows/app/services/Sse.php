<?php
/**
 * Server-Sent Events writer.
 *
 * Every long-running endpoint (chat, flow runs, the agent) talks to the
 * browser through this class so the wire format stays identical everywhere:
 *
 *   data: {"type":"delta","t":"…"}
 */
declare(strict_types=1);

final class Sse
{
    private static bool $open = false;

    /** Flip the response into event-stream mode and stop PHP buffering. */
    public static function open(): void
    {
        if (self::$open) {
            return;
        }
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }
        ignore_user_abort(true);
        @set_time_limit(0);
        header('Content-Type: text/event-stream; charset=utf-8');
        header('Cache-Control: no-cache, no-store');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no');
        self::$open = true;
    }

    public static function send(array $payload): void
    {
        self::open();
        echo 'data: ' . json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n\n";
        if (ob_get_level() > 0) {
            @ob_flush();
        }
        flush();
    }

    public static function done(array $extra = []): never
    {
        self::send(array_merge(['type' => 'done'], $extra));
        exit;
    }
}
