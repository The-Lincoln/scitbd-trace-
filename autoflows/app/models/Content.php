<?php
/**
 * Generated content artifacts (social posts, blog articles, emails).
 */
declare(strict_types=1);

final class Content extends Model
{
    /** @param array{channel?:string,status?:string,q?:string,run_id?:int,limit?:int} $f */
    public static function all(array $f = []): array
    {
        $where = [];
        $args  = [];

        if (!empty($f['channel']) && $f['channel'] !== 'all') {
            $where[] = 'c.channel = ?';
            $args[]  = $f['channel'];
        }
        if (!empty($f['status']) && $f['status'] !== 'all') {
            $where[] = 'c.status = ?';
            $args[]  = $f['status'];
        }
        if (!empty($f['run_id'])) {
            $where[] = 'c.run_id = ?';
            $args[]  = (int) $f['run_id'];
        }
        if (!empty($f['q'])) {
            $where[] = '(c.title LIKE ? OR c.body LIKE ? OR c.excerpt LIKE ?)';
            $like = '%' . $f['q'] . '%';
            array_push($args, $like, $like, $like);
        }

        $sql = 'SELECT c.*, f.name AS flow_name,
                       (SELECT COUNT(*) FROM content x WHERE x.run_id = c.run_id) AS run_siblings
                  FROM content c LEFT JOIN flows f ON f.id = c.flow_id';
        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY c.id DESC';
        if (!empty($f['limit'])) {
            $sql .= ' LIMIT ' . max(1, (int) $f['limit']);
        }
        return self::rows($sql, $args);
    }

    public static function find(int $id): ?array
    {
        $row = self::row('SELECT c.*, f.name AS flow_name FROM content c LEFT JOIN flows f ON f.id = c.flow_id WHERE c.id = ?', [$id]);
        return $row === null ? null : self::decorate($row);
    }

    public static function forRun(int $runId): array
    {
        return self::rows('SELECT * FROM content WHERE run_id = ? ORDER BY id ASC', [$runId]);
    }

    public static function recent(int $limit = 8): array
    {
        return self::rows('SELECT * FROM content ORDER BY id DESC LIMIT ' . max(1, $limit));
    }

    public static function create(array $d): int
    {
        $body   = (string) ($d['body'] ?? '');
        $status = (string) ($d['status'] ?? 'draft');
        $score  = clamp((int) ($d['score'] ?? 0), 0, 100);

        self::write(
            'INSERT INTO content (flow_id, run_id, channel, platform, title, slug, excerpt, body, hashtags,
                                  status, score, chars, publish_at, meta, created_at, updated_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
            [
                $d['flow_id'] ?? null,
                $d['run_id'] ?? null,
                (string) $d['channel'],
                $d['platform'] ?? null,
                mb_substr((string) ($d['title'] ?? ''), 0, 300),
                $d['slug'] ?? null,
                mb_substr((string) ($d['excerpt'] ?? ''), 0, 600),
                $body,
                $d['hashtags'] ?? null,
                $status,
                $score,
                mb_strlen($body),
                $d['publish_at'] ?? null,
                self::json($d['meta'] ?? []) ?: null,
                self::now(),
                self::now(),
            ]
        );
        return self::newId();
    }

    public static function update(int $id, array $d): void
    {
        $cur = self::row('SELECT * FROM content WHERE id = ?', [$id]);
        if ($cur === null) {
            return;
        }
        $body = array_key_exists('body', $d) ? (string) $d['body'] : (string) $cur['body'];

        self::write(
            'UPDATE content SET platform=?, title=?, slug=?, excerpt=?, body=?, hashtags=?, status=?, score=?,
                                chars=?, publish_at=?, meta=?, updated_at=? WHERE id=?',
            [
                array_key_exists('platform', $d) ? $d['platform'] : $cur['platform'],
                mb_substr((string) ($d['title'] ?? $cur['title']), 0, 300),
                array_key_exists('slug', $d) ? $d['slug'] : $cur['slug'],
                mb_substr((string) ($d['excerpt'] ?? $cur['excerpt']), 0, 600),
                $body,
                array_key_exists('hashtags', $d) ? $d['hashtags'] : $cur['hashtags'],
                (string) ($d['status'] ?? $cur['status']),
                clamp((int) ($d['score'] ?? $cur['score']), 0, 100),
                mb_strlen($body),
                array_key_exists('publish_at', $d) ? $d['publish_at'] : $cur['publish_at'],
                array_key_exists('meta', $d) ? (self::json($d['meta']) ?: null) : $cur['meta'],
                self::now(),
                $id,
            ]
        );
    }

    public static function setStatus(int $id, string $status): void
    {
        $set = 'status = ?, updated_at = ?';
        $args = [$status, self::now(), $id];
        if ($status === 'published') {
            $set .= ', publish_at = COALESCE(publish_at, ?)';
            $args = [$status, self::now(), self::now(), $id];
        }
        self::write("UPDATE content SET {$set} WHERE id = ?", $args);
    }

    public static function destroy(int $id): void
    {
        self::write('DELETE FROM content WHERE id = ?', [$id]);
    }

    /** Add derived fields the views need (limits, badges, HTML preview). */
    public static function decorate(array $row): array
    {
        $row['meta_arr'] = self::unjson($row['meta'] ?? null, []);
        $row['chars']    = (int) ($row['chars'] ?? mb_strlen((string) ($row['body'] ?? '')));

        $platforms = (array) config('channels.social.platforms', []);
        $limit     = $row['platform'] !== null && isset($platforms[$row['platform']])
            ? (int) $platforms[$row['platform']]['limit']
            : null;
        $row['limit']      = $limit;
        $row['over_limit'] = $limit !== null && $row['chars'] > $limit;
        $row['read_min']   = $row['channel'] === 'blog' ? reading_minutes((string) $row['body']) : 0;
        return $row;
    }

    public static function decorateAll(array $rows): array
    {
        return array_map([self::class, 'decorate'], $rows);
    }

    /** Counts per channel + status for the dashboard and filter tabs. */
    public static function stats(): array
    {
        $byChannel = [];
        foreach (self::rows('SELECT channel, COUNT(*) AS n FROM content GROUP BY channel') as $r) {
            $byChannel[(string) $r['channel']] = (int) $r['n'];
        }
        $byStatus = [];
        foreach (self::rows('SELECT status, COUNT(*) AS n FROM content GROUP BY status') as $r) {
            $byStatus[(string) $r['status']] = (int) $r['n'];
        }
        $today = (int) self::scalar("SELECT COUNT(*) FROM content WHERE date(created_at) = date('now')");
        $queue = (int) self::scalar("SELECT COUNT(*) FROM content WHERE status = 'scheduled'");

        return [
            'total'     => array_sum($byChannel),
            'by_channel'=> $byChannel,
            'by_status' => $byStatus,
            'today'     => $today,
            'queue'     => $queue,
            'social'    => $byChannel['social'] ?? 0,
            'blog'      => $byChannel['blog'] ?? 0,
            'email'     => $byChannel['email'] ?? 0,
            'youtube'   => $byChannel['youtube'] ?? 0,
            'draft'     => $byStatus['draft'] ?? 0,
            'published' => $byStatus['published'] ?? 0,
        ];
    }

    /** Items whose scheduled time has arrived (used by run_daily.php). */
    public static function dueForPublish(): array
    {
        return self::rows("SELECT * FROM content WHERE status = 'scheduled' AND publish_at <= ?", [self::now()]);
    }
}
