<?php
/**
 * DailyTask — CEO daily_tasks CRUD + assign, backed by the CEO database.
 * Never touches AutoFlows storage. Every mutation writes task_logs.
 */
declare(strict_types=1);

final class DailyTask
{
    // Aligned superset with slack-php-integration CeoTaskManager so both
    // stacks accept the same values (`done` == `completed`).
    public const STATUSES = ['pending', 'in_progress', 'completed', 'done', 'blocked', 'cancelled'];
    public const PRIORITIES = ['low', 'medium', 'high', 'critical'];
    public const CATEGORIES = ['operations', 'marketing', 'sales', 'development', 'client', 'finance', 'admin', 'ai', 'general'];

    private static function db(): PDO
    {
        return ScitbdCeo::db();
    }

    /** @return array<int,array> */
    public static function all(string $status = '', int $block = 0, string $q = ''): array
    {
        $where = [];
        $params = [];
        if ($status !== '' && in_array($status, self::STATUSES, true)) {
            $where[] = 'status = ?';
            $params[] = $status;
        }
        if ($block >= 1 && $block <= 4) {
            $where[] = 'bst_block_id = ?';
            $params[] = $block;
        }
        if ($q !== '') {
            $where[] = '(task_title LIKE ? OR task_description LIKE ? OR assignee LIKE ?)';
            $like = '%' . $q . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }
        $sql = 'SELECT * FROM daily_tasks';
        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY CASE status WHEN \'in_progress\' THEN 0 WHEN \'pending\' THEN 1 WHEN \'blocked\' THEN 2 ELSE 3 END, due_date ASC, id DESC LIMIT 200';
        $st = self::db()->prepare($sql);
        $st->execute($params);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function find(int $id): ?array
    {
        $st = self::db()->prepare('SELECT * FROM daily_tasks WHERE id = ?');
        $st->execute([$id]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r === false ? null : $r;
    }

    /** @return array<int,array> */
    public static function logs(int $taskId, int $limit = 20): array
    {
        $st = self::db()->prepare('SELECT * FROM task_logs WHERE task_id = ? ORDER BY id DESC LIMIT ?');
        $st->execute([$taskId, $limit]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<string,mixed> */
    public static function stats(): array
    {
        $db = self::db();
        $byStatus = $db->query("SELECT status, COUNT(*) c FROM daily_tasks GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);
        $open = (int) ($db->query("SELECT COUNT(*) FROM daily_tasks WHERE status IN ('pending','in_progress','blocked')")->fetchColumn());
        $today = date('Y-m-d');
        $st = $db->prepare("SELECT COUNT(*) FROM daily_tasks WHERE date(due_date) = ?");
        $st->execute([$today]);
        $dueToday = (int) $st->fetchColumn();
        return ['by_status' => $byStatus, 'open' => $open, 'due_today' => $dueToday, 'total' => array_sum($byStatus)];
    }

    public static function create(array $d): int
    {
        $now = date('Y-m-d H:i:s');
        $st = self::db()->prepare(
            'INSERT INTO daily_tasks (task_title, task_description, priority, status, category, assignee, due_date, estimated_hours, bst_block_id, created_by, created_at, updated_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?)'
        );
        $st->execute([
            mb_substr(trim((string) ($d['task_title'] ?? '')), 0, 200) ?: 'Untitled task',
            (string) ($d['task_description'] ?? ''),
            in_array(($d['priority'] ?? ''), self::PRIORITIES, true) ? $d['priority'] : 'medium',
            in_array(($d['status'] ?? ''), self::STATUSES, true) ? $d['status'] : 'pending',
            in_array(($d['category'] ?? ''), self::CATEGORIES, true) ? $d['category'] : 'operations',
            trim((string) ($d['assignee'] ?? 'ceo')) ?: 'ceo',
            (string) ($d['due_date'] ?? date('Y-m-d H:i:s')),
            (float) ($d['estimated_hours'] ?? 1.0),
            (int) ($d['bst_block_id'] ?? 3),
            (string) ($d['created_by'] ?? 'ceo'),
            $now, $now,
        ]);
        $id = (int) self::db()->lastInsertId();
        self::log($id, 'created', (string) ($d['created_by'] ?? 'ceo'), 'Created from Tasks dashboard');
        return $id;
    }

    public static function update(int $id, array $d): bool
    {
        if (self::find($id) === null) {
            return false;
        }
        $st = self::db()->prepare(
            'UPDATE daily_tasks SET task_title=?, task_description=?, priority=?, category=?, assignee=?, due_date=?, estimated_hours=?, bst_block_id=?, updated_at=? WHERE id=?'
        );
        $st->execute([
            mb_substr(trim((string) ($d['task_title'] ?? '')), 0, 200) ?: 'Untitled task',
            (string) ($d['task_description'] ?? ''),
            in_array(($d['priority'] ?? ''), self::PRIORITIES, true) ? $d['priority'] : 'medium',
            in_array(($d['category'] ?? ''), self::CATEGORIES, true) ? $d['category'] : 'operations',
            trim((string) ($d['assignee'] ?? 'ceo')) ?: 'ceo',
            (string) ($d['due_date'] ?? date('Y-m-d H:i:s')),
            (float) ($d['estimated_hours'] ?? 1.0),
            (int) ($d['bst_block_id'] ?? 3),
            date('Y-m-d H:i:s'), $id,
        ]);
        self::log($id, 'updated', 'ceo', 'Edited from Tasks dashboard');
        return true;
    }

    public static function setStatus(int $id, string $status): bool
    {
        // `done` is accepted as an alias of `completed` (Slack `ceo done <id>`).
        $status = strtolower(trim($status)) === 'done' ? 'completed' : strtolower(trim($status));
        if (!in_array($status, self::STATUSES, true) || self::find($id) === null) {
            return false;
        }
        // Store canonical `completed`; `done` rows read back as completed.
        if ($status === 'done') {
            $status = 'completed';
        }
        $completed = $status === 'completed' ? date('Y-m-d H:i:s') : null;
        // Keep completed_at once set unless reopening.
        $st = self::db()->prepare(
            "UPDATE daily_tasks SET status=?, updated_at=?, completed_at=CASE WHEN ?='completed' THEN ? ELSE CASE WHEN status='completed' AND ?!='completed' THEN NULL ELSE completed_at END END WHERE id=?"
        );
        $st->execute([$status, date('Y-m-d H:i:s'), $status, $completed, $status, $id]);
        self::log($id, $status === 'completed' ? 'completed' : 'status', 'ceo', "Status -> {$status} from Tasks dashboard");
        return true;
    }

    public static function assign(int $id, string $assignee): bool
    {
        $assignee = trim($assignee) !== '' ? mb_substr(trim($assignee), 0, 80) : 'ceo';
        if (self::find($id) === null) {
            return false;
        }
        $st = self::db()->prepare('UPDATE daily_tasks SET assignee=?, updated_at=? WHERE id=?');
        $st->execute([$assignee, date('Y-m-d H:i:s'), $id]);
        self::log($id, 'assigned', 'ceo', "Assignee -> {$assignee} from Tasks dashboard");
        return true;
    }

    public static function destroy(int $id): bool
    {
        if (self::find($id) === null) {
            return false;
        }
        self::log($id, 'deleted', 'ceo', 'Deleted from Tasks dashboard (row removed)');
        $st = self::db()->prepare('DELETE FROM daily_tasks WHERE id=?');
        $st->execute([$id]);
        return true;
    }

    private static function log(int $taskId, string $action, string $by, string $details): void
    {
        $st = self::db()->prepare('INSERT INTO task_logs (task_id, action, performed_by, details) VALUES (?,?,?,?)');
        $st->execute([$taskId, $action, $by, mb_substr($details, 0, 500)]);
    }
}
