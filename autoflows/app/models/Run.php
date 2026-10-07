<?php
/**
 * One execution of a flow / agent run, with a JSON step trace in `log`.
 */
declare(strict_types=1);

final class Run extends Model
{
    public static function create(array $d): int
    {
        self::write(
            'INSERT INTO runs (flow_id, trigger_by, goal, channel, status, created_at) VALUES (?,?,?,?,?,?)',
            [
                $d['flow_id'] ?? null,
                (string) ($d['trigger_by'] ?? 'manual'),
                $d['goal'] ?? null,
                $d['channel'] ?? null,
                'running',
                self::now(),
            ]
        );
        return self::newId();
    }

    public static function find(int $id): ?array
    {
        return self::decorate(self::row('SELECT * FROM runs WHERE id = ?', [$id]));
    }

    public static function all(int $limit = 30): array
    {
        $rows = self::rows('SELECT * FROM runs ORDER BY id DESC LIMIT ' . max(1, $limit));
        return array_map([self::class, 'decorate'], $rows);
    }

    public static function forFlow(int $flowId, int $limit = 10): array
    {
        return self::rows('SELECT * FROM runs WHERE flow_id = ? ORDER BY id DESC LIMIT ' . max(1, $limit), [$flowId]);
    }

    /**
     * Append a step to the trace. Cheap for the small number of steps in a
     * run (6 max), and keeps the trace queryable after the process exits.
     */
    public static function appendStep(int $id, array $entry): void
    {
        $row = self::row('SELECT log FROM runs WHERE id = ?', [$id]);
        if ($row === null) {
            return;
        }
        $log        = self::unjson($row['log'], []);
        $log[]      = $entry;
        self::write('UPDATE runs SET log = ? WHERE id = ?', [self::json($log) ?: '[]', $id]);
    }

    public static function finish(int $id, array $d): void
    {
        self::write(
            'UPDATE runs SET status=?, steps=?, outputs=?, provider=?, model=?, ms=?, log=?, error=?, finished_at=? WHERE id=?',
            [
                (string) ($d['status'] ?? 'done'),
                (int) ($d['steps'] ?? 0),
                (int) ($d['outputs'] ?? 0),
                $d['provider'] ?? null,
                $d['model'] ?? null,
                (int) ($d['ms'] ?? 0),
                self::json($d['log'] ?? null) ?: '[]',
                $d['error'] ?? null,
                self::now(),
                $id,
            ]
        );
    }

    public static function markFailed(int $id, string $error): void
    {
        $row = self::find($id);
        self::finish($id, [
            'status'   => 'failed',
            'error'    => $error,
            'steps'    => (int) ($row['steps'] ?? 0),
            'outputs'  => (int) ($row['outputs'] ?? 0),
            'log'      => self::unjson($row['log'] ?? null, []),
            'ms'       => (int) ($row['ms'] ?? 0),
            'provider' => $row['provider'] ?? null,
            'model'    => $row['model'] ?? null,
        ]);
    }

    /** @return array<int,array> decoded trace entries */
    public static function trace(array $run): array
    {
        return self::unjson($run['log'] ?? null, []);
    }

    public static function stats(): array
    {
        return [
            'runs'    => (int) self::scalar('SELECT COUNT(*) FROM runs'),
            'done'    => (int) self::scalar("SELECT COUNT(*) FROM runs WHERE status = 'done'"),
            'failed'  => (int) self::scalar("SELECT COUNT(*) FROM runs WHERE status = 'failed'"),
            'running' => (int) self::scalar("SELECT COUNT(*) FROM runs WHERE status = 'running'"),
            'today'   => (int) self::scalar("SELECT COUNT(*) FROM runs WHERE date(created_at) = date('now')"),
        ];
    }

    private static function decorate(?array $row): ?array
    {
        if ($row === null) {
            return null;
        }
        $row['trace'] = self::unjson($row['log'] ?? null, []);
        return $row;
    }
}
