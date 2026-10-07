<?php
/**
 * CeoTaskManager - OOP wrapper around the SCITBD CEO SQLite backend.
 *
 * Bridges the `ceo/` folder codes (scitbd_ceo.db, operational_blocks,
 * daily_tasks, task_logs) with the Slack PHP Integration application.
 *
 * BST truth: Asia/Dhaka (UTC+6, no DST).
 */

namespace SCITBD\Slack\Ceo;

use PDO;
use PDOException;

class CeoTaskManager
{
    private PDO $db;
    private string $dbPath;

    // Aligned superset with AutoFlows DailyTask/ScitbdCeo so `ceo done`
    // (alias of completed) and finance/admin/ai categories work on both stacks.
    public const VALID_CATEGORIES = ['sales','marketing','development','operations','client','admin','ai','finance','general'];
    public const VALID_PRIORITIES = ['low','medium','high','critical'];
    public const VALID_STATUSES = ['pending','in_progress','completed','done','blocked','cancelled'];

    public function __construct(?string $dbPath = null)
    {
        $this->dbPath = $dbPath ?? self::resolveDefaultDbPath();
        $this->db = new PDO('sqlite:' . $this->dbPath);
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->ensureSchema();
    }

    /**
     * Resolve DB path robustly:
     * 1. <project>/ceo/scitbd_ceo.db (normal layout)
     * 2. legacy absolute path D:/CRM_with_GOOGLE_Sheet/ceo/ceo - Copy/scitbd_ceo.db
     */
    public static function resolveDefaultDbPath(): string
    {
        // 1. Explicit env wins (shared with AutoFlows ScitbdCeo::dbPath).
        $env = getenv('SCITBD_DB_PATH') ?: '';
        if ($env !== '' && file_exists($env)) {
            return $env;
        }
        $candidates = [
            dirname(__DIR__, 2) . '/ceo/scitbd_ceo.db',
            __DIR__ . '/../../ceo/scitbd_ceo.db',
            'D:/CRM_with_GOOGLE_Sheet/ceo/ceo - Copy/scitbd_ceo.db',
        ];
        foreach ($candidates as $p) {
            if (file_exists($p)) {
                return $p;
            }
        }
        // default to project ceo path (will be created by ensureSchema)
        return dirname(__DIR__, 2) . '/ceo/scitbd_ceo.db';
    }

    public function getDbPath(): string
    {
        return $this->dbPath;
    }

    public function getConnection(): PDO
    {
        return $this->db;
    }

    /**
     * Create missing tables on fresh installs.
     * Mirrors ceo/schema.sql + daily_tasks/task_logs expected by ceo/index.php.
     */
    private function ensureSchema(): void
    {
        $this->db->exec("CREATE TABLE IF NOT EXISTS leads (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            client_name TEXT NOT NULL, email TEXT NOT NULL, country TEXT NOT NULL,
            deal_value REAL DEFAULT 0.0, service_line TEXT NOT NULL,
            status TEXT DEFAULT 'new', created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
        $this->db->exec("CREATE TABLE IF NOT EXISTS operational_blocks (
            id INTEGER PRIMARY KEY AUTOINCREMENT, block_name TEXT NOT NULL,
            bst_start TEXT NOT NULL, bst_end TEXT NOT NULL,
            utc_start TEXT NOT NULL, utc_end TEXT NOT NULL,
            regional_focus TEXT NOT NULL, core_execution_focus TEXT NOT NULL,
            status TEXT DEFAULT 'INACTIVE'
        )");
        $this->db->exec("CREATE TABLE IF NOT EXISTS operational_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT, block_id INTEGER, block_name TEXT NOT NULL,
            action_taken TEXT NOT NULL, status TEXT DEFAULT 'SUCCESS',
            executed_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
        $this->db->exec("CREATE TABLE IF NOT EXISTS tickets (
            id INTEGER PRIMARY KEY AUTOINCREMENT, client_name TEXT NOT NULL,
            issue_description TEXT NOT NULL, nps_score INTEGER DEFAULT 100,
            sla_status TEXT DEFAULT 'WITHIN_SLA', created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
        $this->db->exec("CREATE TABLE IF NOT EXISTS daily_tasks (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            task_title TEXT NOT NULL, task_description TEXT DEFAULT '',
            priority TEXT DEFAULT 'medium', category TEXT DEFAULT 'general',
            status TEXT DEFAULT 'pending', assignee TEXT DEFAULT 'ceo',
            due_date TEXT NULL, estimated_hours REAL DEFAULT 1.0, actual_hours REAL DEFAULT 0.0,
            bst_block_id INTEGER NULL, related_lead_id INTEGER NULL, related_ticket_id INTEGER NULL,
            created_by TEXT DEFAULT 'ceo_php_app', created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP, completed_at DATETIME NULL
        )");
        $this->db->exec("CREATE TABLE IF NOT EXISTS task_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT, task_id INTEGER NOT NULL,
            action TEXT NOT NULL, performed_by TEXT DEFAULT 'ceo_php_app',
            details TEXT DEFAULT '', created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");

        // Seed 4 BST blocks if empty (same as ceo/schema.sql)
        $count = (int)$this->db->query("SELECT COUNT(*) FROM operational_blocks")->fetchColumn();
        if ($count === 0) {
            $this->db->exec("INSERT OR IGNORE INTO operational_blocks (id, block_name, bst_start, bst_end, utc_start, utc_end, regional_focus, core_execution_focus, status) VALUES
            (1, 'Block 1: 06:00 – 12:00 BST | BD / South Asia', '06:00', '12:00', '00:00', '06:00', 'BD / South Asia', 'SEO, Local Tenders & BD', 'INACTIVE'),
            (2, 'Block 2: 12:00 – 18:00 BST | Middle East / EU', '12:00', '18:00', '06:00', '12:00', 'Middle East / EU', 'ME & EU Sales Outreach', 'INACTIVE'),
            (3, 'Block 3: 18:00 – 00:00 BST | UK / US East Coast', '18:00', '00:00', '12:00', '18:00', 'UK / US East Coast', 'North America Peak Launch', 'INACTIVE'),
            (4, 'Block 4: 00:00 – 06:00 BST | US West / Oceania', '00:00', '06:00', '18:00', '24:00', 'US West / Oceania', 'Night Analytics & Reboot', 'INACTIVE')");
        }
    }

    // ---------- BST block ----------

    public function getCurrentBSTBlock(): ?array
    {
        $tz = new \DateTimeZone('Asia/Dhaka');
        $now = new \DateTime('now', $tz);
        $currentTime = $now->format('H:i');
        $blocks = $this->db->query("SELECT * FROM operational_blocks ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($blocks as $block) {
            $start = $block['bst_start'];
            $end = $block['bst_end'];
            if ($start < $end) {
                if ($currentTime >= $start && $currentTime < $end) {
                    return $block;
                }
            } else {
                if ($currentTime >= $start || $currentTime < $end) {
                    return $block;
                }
            }
        }
        return null;
    }

    public function getAllBlocks(): array
    {
        return $this->db->query("SELECT * FROM operational_blocks ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    }

    public function markBlockActive(int $blockId): void
    {
        $this->db->exec("UPDATE operational_blocks SET status = 'INACTIVE'");
        $stmt = $this->db->prepare("UPDATE operational_blocks SET status = 'ACTIVE' WHERE id = ?");
        $stmt->execute([$blockId]);
    }

    // ---------- Tasks ----------

    public function listTasks(array $filters = []): array
    {
        $query = "SELECT * FROM daily_tasks WHERE 1=1";
        $params = [];
        foreach (['status','priority','category','bst_block_id','assignee'] as $f) {
            if (!empty($filters[$f])) {
                $query .= " AND {$f} = ?";
                $params[] = $filters[$f];
            }
        }
        $query .= " ORDER BY CASE priority WHEN 'critical' THEN 1 WHEN 'high' THEN 2 WHEN 'medium' THEN 3 ELSE 4 END, due_date ASC";
        if (!empty($filters['limit'])) {
            $query .= " LIMIT " . ((int)$filters['limit']);
        }
        $stmt = $this->db->prepare($query);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function pendingForBlock(?int $blockId = null, int $limit = 15): array
    {
        if ($blockId === null) {
            $blk = $this->getCurrentBSTBlock();
            $blockId = $blk ? (int)$blk['id'] : null;
        }
        // Aligned with AutoFlows ScitbdCeo::pendingForBlock(): queue = pending + in_progress.
        $sql = "SELECT id, task_title, priority, category, status, due_date FROM daily_tasks WHERE status IN ('pending','in_progress')";
        $params = [];
        if ($blockId !== null) {
            $sql .= " AND bst_block_id = ?";
            $params[] = $blockId;
        }
        $sql .= " ORDER BY CASE priority WHEN 'critical' THEN 0 WHEN 'high' THEN 1 ELSE 2 END, id LIMIT " . ((int)$limit);
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getTask(int $id): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM daily_tasks WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function createTask(string $title, array $opts = []): array
    {
        $title = trim($title);
        if ($title === '') {
            throw new \InvalidArgumentException('Task title is required');
        }
        $category = in_array($opts['category'] ?? '', self::VALID_CATEGORIES, true) ? $opts['category'] : 'general';
        $priority = in_array($opts['priority'] ?? '', self::VALID_PRIORITIES, true) ? $opts['priority'] : 'medium';
        $now = date('Y-m-d H:i:s');
        $stmt = $this->db->prepare("INSERT INTO daily_tasks (task_title, task_description, priority, category, assignee, due_date, estimated_hours, bst_block_id, related_lead_id, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $title,
            $opts['task_description'] ?? $opts['description'] ?? '',
            $priority,
            $category,
            $opts['assignee'] ?? 'ceo',
            $opts['due_date'] ?? null,
            $opts['estimated_hours'] ?? 1.0,
            $opts['bst_block_id'] ?? null,
            $opts['related_lead_id'] ?? null,
            $opts['created_by'] ?? 'ceo_php_app',
            $now, $now,
        ]);
        $taskId = (int)$this->db->lastInsertId();
        $this->log($taskId, 'created', $opts['performed_by'] ?? 'ceo_php_app', 'Task created via PHP app / Slack');
        $task = $this->getTask($taskId);
        if (!$task) {
            throw new \RuntimeException('Failed to reload created task');
        }
        return $task;
    }

    public function updateTask(int $id, array $data): ?array
    {
        $allowed = ['status','task_title','task_description','priority','category','assignee','due_date','estimated_hours','actual_hours','bst_block_id'];
        $updates = [];
        $params = [];
        foreach ($allowed as $field) {
            if (array_key_exists($field, $data)) {
                $updates[] = "{$field} = ?";
                $params[] = $data[$field];
            }
        }
        if (empty($updates)) {
            return $this->getTask($id);
        }
        $updates[] = "updated_at = ?";
        $params[] = date('Y-m-d H:i:s');
        if (($data['status'] ?? '') === 'completed' || ($data['status'] ?? '') === 'done') {
            $updates[] = "completed_at = ?";
            $params[] = date('Y-m-d H:i:s');
        }
        $params[] = $id;
        $stmt = $this->db->prepare("UPDATE daily_tasks SET " . implode(', ', $updates) . " WHERE id = ?");
        $stmt->execute($params);
        $this->log($id, 'updated', 'ceo_php_app', 'Task updated: ' . json_encode($data));
        return $this->getTask($id);
    }

    public function setStatus(int $id, string $status, string $by = 'ceo_php_app'): ?array
    {
        // `done` is an alias of `completed` (Slack `ceo done <id>`, AutoFlows DailyTask).
        $status = strtolower(trim($status)) === 'done' ? 'completed' : strtolower(trim($status));
        $now = date('Y-m-d H:i:s');
        if ($status === 'completed' || $status === 'done') {
            $this->db->prepare("UPDATE daily_tasks SET status = 'completed', completed_at = ?, updated_at = ? WHERE id = ?")->execute([$now, $now, $id]);
            $this->log($id, 'completed', $by, 'Task completed');
        } elseif ($status === 'in_progress') {
            $this->db->prepare("UPDATE daily_tasks SET status = 'in_progress', updated_at = ? WHERE id = ?")->execute([$now, $id]);
            $this->log($id, 'started', $by, 'Task started');
        } else {
            $this->db->prepare("UPDATE daily_tasks SET status = ?, updated_at = ? WHERE id = ?")->execute([$status, $now, $id]);
            $this->log($id, 'status:' . $status, $by, 'Status changed to ' . $status);
        }
        return $this->getTask($id);
    }

    public function deleteTask(int $id): bool
    {
        $this->db->prepare("DELETE FROM task_logs WHERE task_id = ?")->execute([$id]);
        $this->db->prepare("DELETE FROM daily_tasks WHERE id = ?")->execute([$id]);
        return true;
    }

    public function getSummary(?string $date = null): array
    {
        $date = $date ?? date('Y-m-d');
        $stmt = $this->db->prepare("SELECT COUNT(*) as total, SUM(CASE WHEN status IN ('completed','done') THEN 1 ELSE 0 END) as completed, SUM(CASE WHEN status='in_progress' THEN 1 ELSE 0 END) as in_progress, SUM(CASE WHEN status='pending' THEN 1 ELSE 0 END) as pending, SUM(CASE WHEN status='blocked' THEN 1 ELSE 0 END) as blocked, COALESCE(SUM(actual_hours), 0) as total_hours FROM daily_tasks WHERE DATE(created_at) = ?");
        $stmt->execute([$date]);
        $s = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $total = (int)($s['total'] ?? 0);
        $completed = (int)($s['completed'] ?? 0);
        return [
            'date' => $date,
            'total_tasks' => $total,
            'completed_tasks' => $completed,
            'in_progress_tasks' => (int)($s['in_progress'] ?? 0),
            'pending_tasks' => (int)($s['pending'] ?? 0),
            'blocked_tasks' => (int)($s['blocked'] ?? 0),
            'total_hours_worked' => (float)($s['total_hours'] ?? 0.0),
            'progress_percent' => $total > 0 ? round($completed / $total * 100, 1) : 0.0,
        ];
    }

    public function getToolbarData(): array
    {
        $tz = new \DateTimeZone('Asia/Dhaka');
        $now = new \DateTime('now', $tz);
        $activeBlock = $this->getCurrentBSTBlock();
        $tasks = $this->listTasks();
        $summary = $this->getSummary();
        return [
            'active_bst_block' => $activeBlock
                ? array_merge($activeBlock, ['current_bst_time' => $now->format('H:i'), 'active' => true])
                : ['block_id' => null, 'current_bst_time' => $now->format('H:i'), 'active' => false],
            'tasks' => $tasks,
            'summary' => $summary,
            'timestamp' => date('c'),
        ];
    }

    public function log(int $taskId, string $action, string $by = 'ceo_php_app', string $details = ''): void
    {
        try {
            $stmt = $this->db->prepare("INSERT INTO task_logs (task_id, action, performed_by, details) VALUES (?, ?, ?, ?)");
            $stmt->execute([$taskId, $action, $by, $details]);
        } catch (PDOException $e) {
            // logging must never break main flow
        }
    }

    public function opsLog(?int $blockId, string $blockName, string $action, string $status = 'SUCCESS'): void
    {
        $stmt = $this->db->prepare("INSERT INTO operational_logs (block_id, block_name, action_taken, status) VALUES (?, ?, ?, ?)");
        $stmt->execute([$blockId, $blockName, $action, $status]);
    }

    // ---------- Slack Block-Kit formatting ----------

    /**
     * Format tasks as Slack Block Kit sections (markdown table fallback in text).
     */
    public static function formatTasksForSlack(array $tasks, ?array $block = null): array
    {
        $lines = [];
        foreach (array_slice($tasks, 0, 10) as $t) {
            $emoji = match ($t['priority'] ?? 'medium') {
                'critical' => '🟣', 'high' => '🔴', 'medium' => '🟡', default => '🟢',
            };
            $lines[] = "{$emoji} *#{$t['id']}* {$t['task_title']} — `{$t['status']}` ({$t['category']})";
        }
        $header = $block
            ? "📋 *CEO Tasks — {$block['block_name']}* ({$block['bst_start']}–{$block['bst_end']} BST)"
            : "📋 *CEO Tasks*";
        $text = $header . "\n" . (empty($lines) ? "_No pending tasks — queue is clear_ ✅" : implode("\n", $lines));
        return [
            ['type' => 'header', 'text' => ['type' => 'plain_text', 'text' => '📋 CEO Task Queue', 'emoji' => true]],
            ['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => $text]],
        ];
    }

    public static function formatSummaryForSlack(array $summary, ?array $block = null): array
    {
        $blockLine = $block ? "\n*Block:* {$block['block_name']} ({$block['bst_start']}–{$block['bst_end']} BST)" : "";
        $text = "📊 *Daily Summary ({$summary['date']})*{$blockLine}\n"
            . "• Total: {$summary['total_tasks']}\n"
            . "• ✅ Completed: {$summary['completed_tasks']}\n"
            . "• 🔄 In Progress: {$summary['in_progress_tasks']}\n"
            . "• ⏳ Pending: {$summary['pending_tasks']}\n"
            . "• 🕐 Hours: {$summary['total_hours_worked']}h\n"
            . "• Progress: {$summary['progress_percent']}%";
        return [
            ['type' => 'header', 'text' => ['type' => 'plain_text', 'text' => '📊 CEO Daily Summary', 'emoji' => true]],
            ['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => $text]],
        ];
    }
}
