<?php
/**
 * Automation flows — the standing "generate this every day" recipes.
 */
declare(strict_types=1);

final class Flow extends Model
{
    public static function all(bool $activeOnly = false): array
    {
        $sql = 'SELECT f.*,
                       (SELECT COUNT(*) FROM content c WHERE c.flow_id = f.id) AS content_count,
                       (SELECT COUNT(*) FROM runs r WHERE r.flow_id = f.id)    AS latest_runs
                  FROM flows f';
        if ($activeOnly) {
            $sql .= ' WHERE f.active = 1';
        }
        return self::rows($sql . ' ORDER BY f.active DESC, f.id DESC');
    }

    public static function find(int $id): ?array
    {
        return self::row('SELECT * FROM flows WHERE id = ?', [$id]);
    }

    public static function create(array $d): int
    {
        self::write(
            'INSERT INTO flows (name, channel, brief, platforms, count, tone, audience, schedule, run_at, weekday, active, created_at, updated_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)',
            [
                mb_substr($d['name'], 0, 120),
                $d['channel'],
                $d['brief'] ?? '',
                self::json($d['platforms'] ?? []) ?: '[]',
                (int) ($d['count'] ?? 3),
                $d['tone'] ?? 'warm',
                $d['audience'] ?? '',
                $d['schedule'] ?? 'daily',
                $d['run_at'] ?? '09:00',
                (int) ($d['weekday'] ?? 1),
                !empty($d['active']) ? 1 : 0,
                self::now(),
                self::now(),
            ]
        );
        return self::newId();
    }

    public static function update(int $id, array $d): void
    {
        $current = self::find($id);
        if ($current === null) {
            return;
        }
        self::write(
            'UPDATE flows SET name=?, channel=?, brief=?, platforms=?, count=?, tone=?, audience=?,
                              schedule=?, run_at=?, weekday=?, active=?, updated_at=? WHERE id=?',
            [
                mb_substr((string) ($d['name'] ?? $current['name']), 0, 120),
                (string) ($d['channel'] ?? $current['channel']),
                (string) ($d['brief'] ?? $current['brief']),
                self::json($d['platforms'] ?? self::unjson($current['platforms'], [])) ?: '[]',
                (int) ($d['count'] ?? $current['count']),
                (string) ($d['tone'] ?? $current['tone']),
                (string) ($d['audience'] ?? $current['audience']),
                (string) ($d['schedule'] ?? $current['schedule']),
                (string) ($d['run_at'] ?? $current['run_at']),
                (int) ($d['weekday'] ?? $current['weekday']),
                isset($d['active']) ? (!empty($d['active']) ? 1 : 0) : (int) $current['active'],
                self::now(),
                $id,
            ]
        );
    }

    public static function destroy(int $id): void
    {
        self::write('DELETE FROM flows WHERE id = ?', [$id]);
    }

    public static function toggle(int $id): bool
    {
        self::write('UPDATE flows SET active = 1 - active, updated_at = ? WHERE id = ?', [self::now(), $id]);
        $row = self::find($id);
        return $row !== null && (int) $row['active'] === 1;
    }

    public static function markRun(int $id): void
    {
        self::write('UPDATE flows SET last_run_at = ?, run_count = run_count + 1, updated_at = ? WHERE id = ?', [
            self::now(), self::now(), $id,
        ]);
    }

    /**
     * Flows due for the daily/weekly cron. `run_daily.php` and the dashboard
     * badge both read from here so the two never disagree.
     */
    public static function dueToday(): array
    {
        $today   = (int) date('N');
        $now     = date('H:i');
        $out     = [];
        foreach (self::all(true) as $f) {
            if ($f['schedule'] === 'manual') {
                continue;
            }
            if ($f['schedule'] === 'weekly' && (int) $f['weekday'] !== $today) {
                continue;
            }
            $ranToday = $f['last_run_at'] !== null && substr((string) $f['last_run_at'], 0, 10) === date('Y-m-d');
            if ($ranToday) {
                continue;
            }
            $f['due']    = $now >= (string) $f['run_at'];
            $f['run_at'] = (string) $f['run_at'];
            $out[] = $f;
        }
        return $out;
    }

    /** Channels this flow produces, expanded from `pack`. */
    public static function channelsFor(array $flow): array
    {
        if (($flow['channel'] ?? '') === 'pack') {
            return ['social', 'blog', 'email'];
        }
        if (($flow['channel'] ?? '') === 'slack') {
            return ['slack'];
        }
        return [(string) $flow['channel']];
    }

    public static function stats(): array
    {
        return [
            'flows'  => (int) self::scalar('SELECT COUNT(*) FROM flows'),
            'active' => (int) self::scalar('SELECT COUNT(*) FROM flows WHERE active = 1'),
        ];
    }
}
