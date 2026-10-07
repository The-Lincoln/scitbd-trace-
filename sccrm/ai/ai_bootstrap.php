<?php
/**
 * SCCRM AI bootstrap — loads the TinyLLM model + chat framework from the
 * AutoFlows app stack and exposes it with SCCRM/CEO/Trace-aligned context.
 * Offline-safe: TinyLLM degrades to its template engine when Ollama is down.
 */
if (!defined('SCCRM_AI_BOOTSTRAPPED')) {
    define('SCCRM_AI_BOOTSTRAPPED', true);
    if (!defined('BASE_PATH')) {
        define('BASE_PATH', dirname(__DIR__, 2) . '/autoflows');
    }
    $afRoot = BASE_PATH;
    if (!function_exists('config') && file_exists($afRoot . '/app/core/Helpers.php')) {
        require_once $afRoot . '/app/core/Helpers.php';
    }
    if (!class_exists('TinyLLM') && file_exists($afRoot . '/app/services/TinyLLM.php')) {
        require_once $afRoot . '/app/services/TinyLLM.php';
    }
}

function aiEnsureTables($db) {
    $db->exec("CREATE TABLE IF NOT EXISTS ai_chat_threads (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        title TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    $db->exec("CREATE TABLE IF NOT EXISTS ai_chat_messages (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        thread_id INTEGER NOT NULL,
        role TEXT NOT NULL DEFAULT 'user',
        content TEXT NOT NULL,
        model TEXT,
        provider TEXT,
        ms INTEGER DEFAULT 0,
        fallback INTEGER DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (thread_id) REFERENCES ai_chat_threads(id) ON DELETE CASCADE
    )");
}

/** Live application snapshot injected into the model system prompt. */
function aiAlignedContext($db) {
    $parts = [];
    $parts[] = 'App: SCIT CRM + OSINT Trace + CEO Office (Bangladesh, BST=Asia/Dhaka, now ' .
        (new DateTime('now', new DateTimeZone('Asia/Dhaka')))->format('Y-m-d H:i') . ').';
    try {
        $parts[] = 'CRM: ' . (int)$db->query("SELECT COUNT(*) FROM leads WHERE status='new'")->fetchColumn() . ' new leads; ' .
            (int)$db->query("SELECT COUNT(*) FROM tasks WHERE status NOT IN ('completed','cancelled')")->fetchColumn() . ' open tasks; ' .
            (int)$db->query("SELECT COUNT(*) FROM support_tickets WHERE status NOT IN ('resolved','closed')")->fetchColumn() . ' open tickets.';
    } catch (Throwable $e) { /* ignore */ }
    try {
        require_once dirname(__DIR__, 2) . '/autoflows/app/services/ScitbdCeo.php';
        $b = ScitbdCeo::briefing();
        $parts[] = 'CEO: block ' . ($b['block'] ?? '?') . ', ' . ($b['pending_tasks'] ?? 0) . ' open CEO tasks, ' .
            ($b['escalations'] ?? 0) . ' escalations, ' . ($b['leads'] ?? 0) . ' CEO leads.';
    } catch (Throwable $e) { /* ignore */ }
    try {
        $osintDb = dirname(__DIR__, 2) . '/data/osint.db';
        if (file_exists($osintDb)) {
            $op = new PDO('sqlite:' . $osintDb);
            $parts[] = 'OSINT: ' . (int)$op->query('SELECT COUNT(*) FROM tools')->fetchColumn() . ' tools, ' .
                (int)$op->query('SELECT COUNT(*) FROM categories')->fetchColumn() . ' categories, ' .
                (int)$op->query("SELECT COUNT(*) FROM url_traces WHERE created_at >= datetime('now','-1 day')")->fetchColumn() . ' traces/24h.';
        }
    } catch (Throwable $e) { /* ignore */ }
    return implode(' ', $parts);
}

/** Send messages via TinyLLM (Ollama tinyllama → offline template fallback). Never throws. */
function aiChatReply(array $messages, $systemExtra = '', $model = null) {
    try {
        if (!class_exists('TinyLLM')) {
            return ['content' => 'AI engine unavailable (TinyLLM not loaded).', 'model' => '-', 'provider' => 'none', 'tokens' => 0, 'ms' => 0, 'error' => 'missing', 'fallback' => true];
        }
        $opts = [];
        if ($model) $opts['model'] = $model;
        if ($systemExtra !== '') $opts['system'] = $systemExtra;
        return TinyLLM::chat($messages, $opts);
    } catch (Throwable $e) {
        return ['content' => 'AI error: ' . $e->getMessage(), 'model' => '-', 'provider' => 'none', 'tokens' => 0, 'ms' => 0, 'error' => $e->getMessage(), 'fallback' => true];
    }
}

/** TinyLLM status probe (short timeout). Never throws. */
function aiModelStatus() {
    try {
        if (!class_exists('TinyLLM')) return ['ok' => false, 'provider' => 'none', 'model' => '-', 'label' => 'TinyLLM (not loaded)', 'latency_ms' => 0, 'error' => 'not loaded', 'models' => []];
        return TinyLLM::status(2);
    } catch (Throwable $e) {
        return ['ok' => false, 'provider' => '?', 'model' => '-', 'label' => 'TinyLLM', 'latency_ms' => 0, 'error' => $e->getMessage(), 'models' => []];
    }
}
