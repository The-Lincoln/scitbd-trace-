<?php
/**
 * ScitbdCeo — CRM + CEO AI agent bridge for the SCITBD Master Directive.
 *
 * Reads the seeded CEO database (profile, 17 services, 5 divisions, 22
 * tasks, 10 KPIs, 7 prompts, 5 escalations, UTC blocks, decisions) and
 * exposes: current BST block, push-tasks (directive -> daily_tasks),
 * escalation checks (leads $10k+, NPS<40, ticket SLA), prompt library.
 * Mutations only happen via pushTasks() with $dry=false; everything else
 * is read-only. Never touches AutoFlows storage.
 */
declare(strict_types=1);

final class ScitbdCeo
{
    /**
     * Shared vocab — aligned with slack-php-integration/src/Ceo/CeoTaskManager.php.
     * Both stacks accept the superset so `ceo create … high` / `ceo done <id>`
     * behave identically whether they hit AutoFlows (?r=slack/…) or the
     * Composer app (/webhook.php, /tasks, /toolbar/*).
     */
    public const STATUSES = ['pending', 'in_progress', 'completed', 'done', 'blocked', 'cancelled'];
    public const PRIORITIES = ['low', 'medium', 'high', 'critical'];
    public const CATEGORIES = ['sales', 'marketing', 'development', 'operations', 'client', 'admin', 'ai', 'finance', 'general'];

    /** BST = Bangladesh Standard Time (UTC+6, no DST) — same as CeoTaskManager. */
    public const BST_TIMEZONE = 'Asia/Dhaka';

    public static function dbPath(): string
    {
        $env = (string) (getenv('SCITBD_DB_PATH') ?: '');
        if ($env !== '' && is_file($env)) {
            return $env;
        }
        // Prefer the live slack-php-integration CEO database when present so
        // AutoFlows Tasks UI, SlackApp bridge and `ceo …` chatops all read the
        // same daily_tasks/task_logs as /tasks + /toolbar/*.
        $candidates = [];
        if (defined('BASE_PATH')) {
            $candidates[] = BASE_PATH . '/slack-php-integration/ceo/scitbd_ceo.db';
        }
        $candidates[] = __DIR__ . '/../../slack-php-integration/ceo/scitbd_ceo.db';
        $candidates[] = 'D:/CRM_with_GOOGLE_Sheet/ceo/ceo - Copy/scitbd_ceo.db';
        foreach ($candidates as $p) {
            if (is_file($p)) {
                return $p;
            }
        }
        // Fall back to the first writable candidate (lets fresh clones boot).
        if (defined('BASE_PATH')) {
            return BASE_PATH . '/slack-php-integration/ceo/scitbd_ceo.db';
        }
        return $candidates[0];
    }

    private static ?PDO $pdo = null;

    public static function db(): PDO
    {
        if (self::$pdo === null) {
            self::$pdo = new PDO('sqlite:' . self::dbPath());
            self::$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        }
        return self::$pdo;
    }

    /** @return array<string,mixed> */
    public static function profile(): array
    {
        $row = self::db()->query('SELECT * FROM ceo_agent_profile WHERE id=1')->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : [];
    }

    /** @return array<int,array> */
    public static function divisions(): array
    {
        return self::db()->query('SELECT * FROM directive_divisions ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<int,array> */
    public static function directiveTasks(): array
    {
        return self::db()->query('SELECT * FROM directive_tasks ORDER BY task_no')->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<int,array> */
    public static function kpis(): array
    {
        return self::db()->query('SELECT * FROM directive_kpis ORDER BY kpi_order')->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<int,array> */
    public static function prompts(?string $category = null): array
    {
        if ($category === null || $category === '') {
            return self::db()->query('SELECT * FROM directive_prompt_lib ORDER BY category, seq')->fetchAll(PDO::FETCH_ASSOC);
        }
        $st = self::db()->prepare('SELECT * FROM directive_prompt_lib WHERE category=? ORDER BY seq');
        $st->execute([$category]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<int,array> */
    public static function escalations(): array
    {
        return self::db()->query('SELECT * FROM directive_escalations ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<int,array> */
    public static function services(): array
    {
        return self::db()->query('SELECT * FROM directive_services ORDER BY portfolio_no')->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<int,array> */
    public static function engines(): array
    {
        return self::db()->query('SELECT * FROM directive_engines ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Current BST operational block row. */
    public static function currentBlock(): array
    {
        // Aligned with CeoTaskManager::getCurrentBSTBlock() + ceo/index.php:
        // BST here is Bangladesh Standard Time (Asia/Dhaka, UTC+6).
        // (Old code used Europe/London which shifted blocks by 5-6h vs Slack.)
        $now = new DateTime('now', new DateTimeZone(self::BST_TIMEZONE));
        $hm = $now->format('H:i');
        // Blocks: 06-12 (1), 12-18 (2), 18-00 (3, wraps midnight), 00-06 (4).
        if ($hm >= '06:00' && $hm < '12:00') {
            $id = 1;
        } elseif ($hm >= '12:00' && $hm < '18:00') {
            $id = 2;
        } elseif ($hm >= '18:00' || $hm < '00:00') {
            $id = 3; // 18:00-24:00 (00:00 never reached as string compare)
        } else {
            $id = 4;
        }
        // 00:00-06:00 falls through to 4 since '00:xx' < '06:00' handled above? make explicit:
        if ($hm >= '00:00' && $hm < '06:00') {
            $id = 4;
        }
        $st = self::db()->prepare('SELECT * FROM operational_blocks WHERE id=?');
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : ['id' => $id];
    }

    /**
     * Push unpushed directive_tasks into daily_tasks for the current block.
     * Dry-run by default; pass $dry=false with --confirm to write.
     *
     * @return array{created:int[],skipped:int}
     */
    public static function pushTasks(bool $dry = true): array
    {
        $pdo = self::db();
        $block = self::currentBlock();
        $blockId = (int) ($block['id'] ?? 3);
        $due = (new DateTime('now', new DateTimeZone('Europe/London')))->format('Y-m-d') . ' 23:30:00';
        $catMap = ['MKT' => 'marketing', 'SBD' => 'sales', 'AIT' => 'ai', 'CSR' => 'client', 'FGI' => 'operations'];
        $created = [];
        $skipped = 0;
        foreach (self::directiveTasks() as $t) {
            if (!empty($t['daily_task_id'])) {
                // already pushed once; skip to keep the bridge idempotent
                $skipped++;
                continue;
            }
            $pri = strtolower((string) ($t['priority'] ?? 'high'));
            if (!in_array($pri, ['low', 'medium', 'high', 'critical'], true)) {
                $pri = $pri === 'critical' ? 'critical' : 'high';
            }
            $cat = $catMap[(string) ($t['division_code'] ?? '')] ?? 'general';
            $title = '[Directive #' . $t['task_no'] . '] ' . $t['task_title'];
            $desc = trim((string) ($t['deliverable'] ?? '') . "\nKPI: " . ($t['kpi'] ?? '') . "\nDeadline: " . ($t['deadline'] ?? ''));
            if ($dry) {
                continue;
            }
            $st = $pdo->prepare(
                "INSERT INTO daily_tasks (task_title, task_description, priority, status, category, assignee, due_date, estimated_hours, bst_block_id, created_by)
                 VALUES (:t,:d,:p,'pending',:c,'ceo',:due,2.0,:b,'ai_agent')"
            );
            $st->execute([':t' => $title, ':d' => $desc, ':p' => $pri, ':c' => $cat, ':due' => $due, ':b' => $blockId]);
            $newId = (int) $pdo->lastInsertId();
            $pdo->prepare('UPDATE directive_tasks SET daily_task_id=? WHERE id=?')->execute([$newId, $t['id']]);
            $pdo->prepare("INSERT INTO task_logs (task_id, action, performed_by, details) VALUES (:id,'assigned','ai_agent',:d)")
                ->execute([':id' => $newId, ':d' => "Master Directive task {$t['task_no']} ({$t['division_code']}) pushed to Block {$blockId}."]);
            $created[] = $newId;
        }
        return ['created' => $created, 'skipped' => $skipped];
    }

    /**
     * Evaluate Section 7 escalation triggers against live CRM data.
     *
     * @return array<int,array{trigger:string,sla:string,detail:string}>
     */
    public static function checkEscalations(): array
    {
        $pdo = self::db();
        $out = [];
        foreach ($pdo->query("SELECT * FROM leads WHERE deal_value >= 10000 AND status != 'won' ORDER BY deal_value DESC") as $lead) {
            $out[] = [
                'trigger' => 'Lead $10k+ needs CEO video message',
                'sla' => '24 hours',
                'detail' => '#' . $lead['id'] . ' ' . $lead['client_name'] . ' $' . number_format((float) $lead['deal_value'], 0) . ' [' . $lead['status'] . ']',
            ];
        }
        foreach ($pdo->query('SELECT * FROM tickets WHERE nps_score < 40 OR sla_status = \'ESCALATED_TO_CEO\' ORDER BY id DESC') as $tick) {
            $out[] = [
                'trigger' => 'NPS<40 / ticket escalated — emergency review',
                'sla' => '48 hours',
                'detail' => '#' . $tick['id'] . ' ' . $tick['client_name'] . ' NPS ' . $tick['nps_score'] . ' [' . $tick['sla_status'] . ']',
            ];
        }
        return $out;
    }

    /** One-screen CEO briefing for the current block. */
    public static function briefing(): array
    {
        $pdo = self::db();
        $block = self::currentBlock();
        $pending = (int) $pdo->query("SELECT COUNT(*) FROM daily_tasks WHERE status IN ('pending','in_progress')")->fetchColumn();
        $esc = self::checkEscalations();
        return [
            'company' => (string) (self::profile()['company_name'] ?? 'SCITBD'),
            'block' => $block['block_name'] ?? ('Block ' . ($block['id'] ?? '?')),
            'pending_tasks' => $pending,
            'escalations' => count($esc),
            'leads' => (int) $pdo->query('SELECT COUNT(*) FROM leads')->fetchColumn(),
            'tickets' => (int) $pdo->query('SELECT COUNT(*) FROM tickets')->fetchColumn(),
        ];
    }

    // --------------------------------- shared CEO task API (Slack parity) ---

    /** Normalise any accepted status spelling to the stored value. */
    public static function normaliseStatus(string $status): string
    {
        $s = strtolower(trim($status));
        if ($s === 'done') {
            return 'completed';
        }
        return in_array($s, self::STATUSES, true) ? $s : 'pending';
    }

    /** Pending queue for a BST block (defaults to current). Mirrors CeoTaskManager::pendingForBlock(). */
    public static function pendingForBlock(?int $blockId = null, int $limit = 10): array
    {
        if ($blockId === null) {
            $blk = self::currentBlock();
            $blockId = isset($blk['id']) ? (int) $blk['id'] : null;
        }
        $sql = "SELECT id, task_title, priority, category, status, due_date FROM daily_tasks WHERE status IN ('pending','in_progress')";
        $params = [];
        if ($blockId !== null) {
            $sql .= ' AND bst_block_id = ?';
            $params[] = $blockId;
        }
        $sql .= ' ORDER BY CASE priority WHEN \'critical\' THEN 0 WHEN \'high\' THEN 1 ELSE 2 END, id LIMIT ' . max(1, $limit);
        $st = self::db()->prepare($sql);
        $st->execute($params);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Daily summary. Mirrors CeoTaskManager::getSummary(). */
    public static function summary(?string $date = null): array
    {
        $date = $date ?? date('Y-m-d');
        $st = self::db()->prepare("SELECT COUNT(*) as total, SUM(CASE WHEN status IN ('completed','done') THEN 1 ELSE 0 END) as completed, SUM(CASE WHEN status='in_progress' THEN 1 ELSE 0 END) as in_progress, SUM(CASE WHEN status IN ('pending') THEN 1 ELSE 0 END) as pending, SUM(CASE WHEN status='blocked' THEN 1 ELSE 0 END) as blocked, COALESCE(SUM(actual_hours), 0) as total_hours FROM daily_tasks WHERE DATE(created_at) = ?");
        $st->execute([$date]);
        $s = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $total = (int) ($s['total'] ?? 0);
        $completed = (int) ($s['completed'] ?? 0);
        return [
            'date' => $date,
            'total_tasks' => $total,
            'completed_tasks' => $completed,
            'in_progress_tasks' => (int) ($s['in_progress'] ?? 0),
            'pending_tasks' => (int) ($s['pending'] ?? 0),
            'blocked_tasks' => (int) ($s['blocked'] ?? 0),
            'total_hours_worked' => (float) ($s['total_hours'] ?? 0.0),
            'progress_percent' => $total > 0 ? round($completed / $total * 100, 1) : 0.0,
        ];
    }

    /**
     * Parse `ceo …` chatops. Returns null when $text is not a CEO command.
     * @return array{verb:string,arg:string}|null
     */
    public static function parseCeoCommand(string $text): ?array
    {
        if (!preg_match('/\bceo\s+(list|tasks|summary|block|create|done|complete|start|help)\b/i', $text)) {
            return null;
        }
        $clean = trim((string) preg_replace('/<@[^>]+>\s*/', '', $text));
        $after = trim((string) preg_replace('/^.*?ceo\s+/i', '', $clean));
        $parts = preg_split('/\s+/', $after, 2) ?: [];
        return ['verb' => strtolower($parts[0] ?? 'help'), 'arg' => trim($parts[1] ?? ''), 'clean' => $clean];
    }

    /** Slack mrkdwn text for a task queue. Same shape as CeoTaskManager::formatTasksForSlack(). */
    public static function formatTasksText(array $tasks, ?array $block = null): string
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
            : '📋 *CEO Tasks*';
        return $header . "\n" . (empty($lines) ? '_No pending tasks — queue is clear_ ✅' : implode("\n", $lines));
    }

    /** Slack mrkdwn text for a daily summary. */
    public static function formatSummaryText(array $summary, ?array $block = null): string
    {
        $blockLine = $block ? "\n*Block:* {$block['block_name']} ({$block['bst_start']}–{$block['bst_end']} BST)" : '';
        return "📊 *Daily Summary ({$summary['date']})*{$blockLine}\n"
            . "• Total: {$summary['total_tasks']}\n"
            . "• ✅ Completed: {$summary['completed_tasks']}\n"
            . "• 🔄 In Progress: {$summary['in_progress_tasks']}\n"
            . "• ⏳ Pending: {$summary['pending_tasks']}\n"
            . "• 🕐 Hours: {$summary['total_hours_worked']}h\n"
            . "• Progress: {$summary['progress_percent']}%";
    }
}
