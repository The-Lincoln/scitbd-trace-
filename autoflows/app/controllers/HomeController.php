<?php
/**
 * Dashboard — today's queue, channel stats, quick generate, activity.
 */
declare(strict_types=1);

final class HomeController extends Controller
{
    public function index(): void
    {
        $this->view->render('home/index', [
            'title'     => 'Dashboard',
            'content'   => Content::stats(),
            'flowStats' => Flow::stats(),
            'runStats'  => Run::stats(),
            'chatStats' => Conversation::stats(),
            'dueFlows'  => Flow::dueToday(),
            'recent'    => Content::decorateAll(Content::recent(6)),
            'recentRuns'=> Run::all(5),
            'queue'     => Content::decorateAll(self::queue()),
            'activity'  => self::activity(),
            'llm'       => TinyLLM::status(3),
            'channels'  => (array) config('channels'),
            'flashes'   => take_flash(),
        ]);
    }

    /** The next handful of scheduled/published items, newest slot first. */
    private static function queue(): array
    {
        return Database::pdo()->query(
            "SELECT * FROM content
              WHERE status IN ('scheduled','published')
                AND publish_at IS NOT NULL AND publish_at <> ''
              ORDER BY publish_at DESC LIMIT 8"
        )->fetchAll();
    }

    private static function activity(): array
    {
        return Database::pdo()->query(
            'SELECT * FROM task_logs ORDER BY id DESC LIMIT 12'
        )->fetchAll();
    }

    /** Polled by the dashboard for live badges. */
    public function stats(): void
    {
        json_response([
            'ok'       => true,
            'content'  => Content::stats(),
            'flows'    => Flow::stats(),
            'runs'     => Run::stats(),
            'llm'      => TinyLLM::status(3),
            'due'      => count(Flow::dueToday()),
            'time'     => date('H:i:s'),
        ]);
    }
}
