<?php
// SCCRM AI Chat JSON API: threads, messages, slash commands, TinyLLM replies.
// No layout output — pure JSON. Session + CRM db only.
if (session_status() === PHP_SESSION_NONE) { session_start(); }
header('Content-Type: application/json; charset=utf-8');

try {
    $_b = require_once __DIR__ . '/../config/database.php';
    $db = ($_b instanceof PDO) ? $_b : null;
    if (!$db) throw new Exception('DB unavailable');
    require_once __DIR__ . '/ai_bootstrap.php';
    require_once __DIR__ . '/../leads/generator_functions.php';
    aiEnsureTables($db);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    exit;
}

$in = json_decode(file_get_contents('php://input'), true) ?: $_POST;
$op = trim($in['op'] ?? 'send');

function aiOut($data) { echo json_encode($data); exit; }

try {
    if ($op === 'status') {
        aiOut(['ok' => true, 'status' => aiModelStatus()]);
    }
    if ($op === 'threads') {
        $rows = $db->query("SELECT t.*, (SELECT COUNT(*) FROM ai_chat_messages m WHERE m.thread_id = t.id) AS n FROM ai_chat_threads t ORDER BY t.updated_at DESC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);
        aiOut(['ok' => true, 'threads' => $rows]);
    }
    if ($op === 'history') {
        $tid = intval($in['thread_id'] ?? 0);
        $st = $db->prepare("SELECT role, content, model, provider, ms, fallback, created_at FROM ai_chat_messages WHERE thread_id = ? ORDER BY id ASC LIMIT 200");
        $st->execute([$tid]);
        aiOut(['ok' => true, 'messages' => $st->fetchAll(PDO::FETCH_ASSOC)]);
    }
    if ($op === 'new_thread') {
        $db->exec("INSERT INTO ai_chat_threads (title) VALUES ('New chat')");
        aiOut(['ok' => true, 'thread_id' => (int)$db->lastInsertId()]);
    }
    if ($op === 'delete_thread') {
        $st = $db->prepare("DELETE FROM ai_chat_threads WHERE id = ?");
        $st->execute([intval($in['thread_id'] ?? 0)]);
        aiOut(['ok' => true]);
    }
    if ($op === 'models') {
        $list = class_exists('TinyLLM') ? TinyLLM::models() : [];
        aiOut(['ok' => true, 'models' => $list]);
    }
    if ($op === 'send') {
        $tid = intval($in['thread_id'] ?? 0);
        $text = trim((string)($in['message'] ?? ''));
        $model = trim((string)($in['model'] ?? ''));
        if ($tid <= 0) {
            $db->exec("INSERT INTO ai_chat_threads (title) VALUES ('New chat')");
            $tid = (int)$db->lastInsertId();
        }
        if ($text === '') aiOut(['ok' => false, 'error' => 'Empty message']);
        // Store user message + title thread from first message
        $db->prepare("INSERT INTO ai_chat_messages (thread_id, role, content) VALUES (?, 'user', ?)")->execute([$tid, $text]);
        $db->prepare("UPDATE ai_chat_threads SET title = ?, updated_at = datetime('now') WHERE id = ? AND title = 'New chat'")->execute([mb_substr($text, 0, 60), $tid]);
        $db->prepare("UPDATE ai_chat_threads SET updated_at = datetime('now') WHERE id = ?")->execute([$tid]);

        // 1) App slash-commands (live data, no LLM needed)
        $cmd = aiSlashCommand($db, $tid, $text);
        if ($cmd !== null) {
            $db->prepare("INSERT INTO ai_chat_messages (thread_id, role, content, model, provider) VALUES (?, 'assistant', ?, 'slash', 'app')")->execute([$tid, $cmd]);
            aiOut(['ok' => true, 'thread_id' => $tid, 'reply' => $cmd, 'model' => 'slash', 'provider' => 'app', 'fallback' => false]);
        }

        // 2) LLM with aligned context (last 12 messages)
        $st = $db->prepare("SELECT role, content FROM ai_chat_messages WHERE thread_id = ? ORDER BY id DESC LIMIT 12");
        $st->execute([$tid]);
        $hist = array_reverse($st->fetchAll(PDO::FETCH_ASSOC));
        $res = aiChatReply($hist, aiAlignedContext($db), $model ?: null);
        $db->prepare("INSERT INTO ai_chat_messages (thread_id, role, content, model, provider, ms, fallback) VALUES (?, 'assistant', ?, ?, ?, ?, ?)")
            ->execute([$tid, $res['content'], $res['model'], $res['provider'], $res['ms'], $res['fallback'] ? 1 : 0]);
        aiOut(['ok' => true, 'thread_id' => $tid, 'reply' => $res['content'], 'model' => $res['model'],
            'provider' => $res['provider'], 'ms' => $res['ms'], 'fallback' => $res['fallback'], 'tokens' => $res['tokens'] ?? 0]);
    }
    aiOut(['ok' => false, 'error' => 'Unknown op']);
} catch (Throwable $e) {
    aiOut(['ok' => false, 'error' => $e->getMessage()]);
}

/** App slash commands. Returns string reply or null to fall through to the LLM. */
function aiSlashCommand($db, $tid, $text) {
    $t = trim($text);
    if (preg_match('#^/help#i', $t)) {
        return "**AI commands**\n\n- `/briefing` — CEO block briefing\n- `/leads [n]` — latest CRM leads\n- `/tasks` — CEO block queue + CRM open tasks\n- `/trace <url>` — run URL trace intel\n- `/browser research <url> | [goal]` — rendered Chromium intel (SPA-safe)\n- `/browser monitor <url>` — uptime/snapshot check\n- `/browser act <url> | <click|fill> <sel> [text]` — form action\n- `/trace-render <url>` — rendered trace alias\n- `/tools <service>` — OSINT stack for a service\n- `/status` — model + provider status\n- `/ceo list` — CEO queue for this block\n- `/ceo create <title> | [priority] | [category]` — new CEO task\n- `/ceo done <id>` / `/ceo start <id>` — update CEO task\n- `/crm task <title>` — new CRM follow-up task\n- `/crm lead <Company> | <Contact name> | <email>` — new CRM lead\n- `/blog <topic>` · `/social <topic>` · `/email <topic>` — TinyLLM draft → Knowledge Bank\n- `/rewrite <text>` · `/review <text>` · `/lint <text>` — Clarity prose modes (no file changes; review never rewrites)\n- `/clarity <mode> | <text>` — co-write/rewrite/review/lint explicitly\n- `/skill [n|query]` — OWASP agentic-skills Top-10 reference\n\nAnything else goes to TinyLLM with live app context.";
    }
    if (preg_match('#^/status#i', $t)) {
        $s = aiModelStatus();
        return '**Model status:** ' . ($s['ok'] ? 'online ✅' : 'offline (template fallback) ⚠️') .
            "\n- Provider: {$s['provider']}\n- Model: {$s['model']}\n- Label: {$s['label']}" .
            ($s['latency_ms'] ? "\n- Latency: {$s['latency_ms']}ms" : '') .
            ($s['error'] ? "\n- Note: {$s['error']}" : '');
    }
    if (preg_match('#^/briefing#i', $t)) {
        try {
            require_once dirname(__DIR__, 2) . '/autoflows/app/services/ScitbdCeo.php';
            $b = ScitbdCeo::briefing();
            $sum = ScitbdCeo::summary();
            return "📊 **CEO Briefing** — block {$b['block']}\n- Company: {$b['company']}\n- Open CEO tasks: {$b['pending_tasks']}\n- Escalations: {$b['escalations']}\n- Today: {$sum['completed_tasks']}/{$sum['total_tasks']} done ({$sum['progress_percent']}%)";
        } catch (Throwable $e) { return 'Briefing unavailable: ' . $e->getMessage(); }
    }
    if (preg_match('#^/leads(?:\s+(\d+))?#i', $t, $m)) {
        $n = max(1, min(intval($m[1] ?? 5), 10));
        try {
            $rows = $db->query("SELECT id, first_name, last_name, company_name, score, status FROM leads ORDER BY created_at DESC LIMIT $n")->fetchAll(PDO::FETCH_ASSOC);
            if (!$rows) return 'No leads yet.';
            $out = ["**Latest $n leads:**"];
            foreach ($rows as $l) $out[] = "- #{$l['id']} " . trim(($l['first_name'] ?? '') . ' ' . ($l['last_name'] ?? '')) . " @ {$l['company_name']} — score {$l['score']} [{$l['status']}]";
            return implode("\n", $out);
        } catch (Throwable $e) { return 'Leads unavailable.'; }
    }
    if (preg_match('#^/tasks#i', $t)) {
        try {
            require_once dirname(__DIR__, 2) . '/autoflows/app/services/ScitbdCeo.php';
            $q = ScitbdCeo::pendingForBlock(null, 5);
            $open = (int)$db->query("SELECT COUNT(*) FROM tasks WHERE status NOT IN ('completed','cancelled')")->fetchColumn();
            $out = ["**CEO block queue:**"];
            foreach ($q as $x) $out[] = "- #{$x['id']} {$x['task_title']} [{$x['priority']}/{$x['status']}]";
            $out[] = "CRM open tasks: $open";
            return implode("\n", $out);
        } catch (Throwable $e) { return 'Tasks unavailable: ' . $e->getMessage(); }
    }
    if (preg_match('#^/tools\s+(.+)#i', $t, $m)) {
        $tools = function_exists('osintToolsForService') ? osintToolsForService(trim($m[1]), 6) : [];
        if (!$tools) return 'No tools found.';
        $out = ['**OSINT stack:**'];
        foreach ($tools as $x) $out[] = "- {$x['name']} ({$x['category']}) — {$x['url']}";
        return implode("\n", $out);
    }
    if (preg_match('#^/(browser|trace-render|render)\b#i', $t)) {
        if (!function_exists('aiBrowserCommand')) {
            $bf2 = __DIR__ . '/browser_skill.php';
            if (is_file($bf2)) {
                require_once $bf2;
            }
        }
        if (function_exists('aiBrowserCommand')) {
            $br2 = aiBrowserCommand($db, $t);
            if ($br2 !== null) {
                return $br2;
            }
        }
    }
    if (preg_match('#^/ceo\s+#i', $t)) {
        return aiCeoCommand($db, $t);
    }
    if (preg_match('#^/(browser|trace-render|render)\b#i', $t)) {
        if (!function_exists('aiBrowserCommand')) {
            $bf = __DIR__ . '/browser_skill.php';
            if (is_file($bf)) {
                require_once $bf;
            }
        }
        if (function_exists('aiBrowserCommand')) {
            $br = aiBrowserCommand($db, $t);
            if ($br !== null) {
                return $br;
            }
        }
    }
    if (preg_match('#^/crm\s+#i', $t)) {
        return aiCrmCommand($db, $t);
    }
    if (preg_match('#^/skill(?:\s+(.+))?#is', $t, $m)) {
        return aiSkillCommand(trim($m[1] ?? ''));
    }
    if (preg_match('#^/(blog|social|email)\s+(.+)#is', $t, $m)) {
        return aiContentSkill($db, strtolower($m[1]), trim($m[2]));
    }
    if (preg_match('#^/(rewrite|review|lint)\s+(.+)#is', $t, $m)) {
        return aiClaritySkill($db, strtolower($m[1]), trim($m[2]));
    }
    if (preg_match('#^/clarity\s+(\w+)\s*\|\s*(.*)#is', $t, $m)) {
        return aiClaritySkill($db, strtolower(trim($m[1])), trim($m[2]));
    }
    if (preg_match('#^/clarity#i', $t)) {
        return "Usage: `/clarity <co-write|rewrite|review|lint> | <text>` — or `/rewrite <text>`, `/review <text>`, `/lint <text>`. Review never rewrites; lint is fully offline.";
    }
    if (preg_match('#^/trace\s+(\S+)#i', $t, $m)) {
        $url = trim($m[1]);
        if (!preg_match('#^https?://#i', $url)) $url = 'https://' . $url;
        try {
            $root = dirname(__DIR__, 2);
            if (file_exists($root . '/vendor/autoload.php')) require_once $root . '/vendor/autoload.php';
            if (file_exists($root . '/trace/url_tracer.php')) require_once $root . '/trace/url_tracer.php';
            if (!class_exists('OSINT\\URLTracer')) return 'Tracer engine unavailable.';
            $tracePdo = file_exists($root . '/data/osint.db') ? new PDO('sqlite:' . $root . '/data/osint.db') : null;
            $res = (new OSINT\URLTracer($tracePdo))->trace($url);
            if (!empty($res['error'])) return 'Trace failed: ' . ($res['error']['message'] ?? '?');
            return "**Trace: $url**\n- HTTP " . ($res['basic']['status_code'] ?? '?') . ' · ' . ($res['basic']['response_time_ms'] ?? '?') . "ms\n" .
                '- Title: ' . ($res['content']['title'] ?? '-') . "\n" .
                '- SEO ' . ($res['seo']['score'] ?? 0) . '/100 · Security ' . ($res['security']['score'] ?? 0) . '/100 · Perf ' . ($res['performance']['performance_score'] ?? 0) . '/100';
        } catch (Throwable $e) { return 'Trace error: ' . $e->getMessage(); }
    }
    return null;
}

/** CEO write commands: list / create / done|start|block <id>. */
function aiCeoCommand($db, $text) {
    try {
        require_once dirname(__DIR__, 2) . '/autoflows/app/services/ScitbdCeo.php';
        require_once dirname(__DIR__, 2) . '/autoflows/app/models/DailyTask.php';
    } catch (Throwable $e) { return 'CEO bridge unavailable.'; }
    $rest = trim(preg_replace('#^/ceo\s+#i', '', $text));
    if (preg_match('#^list#i', $rest)) {
        try {
            $q = ScitbdCeo::pendingForBlock(null, 8);
            if (!$q) return 'CEO queue is clear ✅';
            $out = ['**CEO queue:**'];
            foreach ($q as $x) $out[] = "- #{$x['id']} {$x['task_title']} [{$x['priority']}/{$x['status']}]";
            return implode("\n", $out);
        } catch (Throwable $e) { return 'Queue unavailable: ' . $e->getMessage(); }
    }
    if (preg_match('#^create\s+(.+)#is', $rest, $m)) {
        $parts = array_map('trim', explode('|', $m[1]));
        $title = $parts[0] ?? '';
        if ($title === '') return 'Usage: `/ceo create <title> | [priority] | [category]`';
        $pri = strtolower($parts[1] ?? 'medium');
        if (!in_array($pri, ['low', 'medium', 'high', 'critical'], true)) $pri = 'medium';
        $cat = strtolower($parts[2] ?? 'operations');
        if (!in_array($cat, DailyTask::CATEGORIES, true)) $cat = 'operations';
        try {
            $id = DailyTask::create(['task_title' => $title, 'priority' => $pri, 'category' => $cat,
                'assignee' => 'ceo', 'due_date' => date('Y-m-d 23:30:00'), 'created_by' => 'ai_chat']);
            return "✅ CEO task #$id created ($pri/$cat).";
        } catch (Throwable $e) { return 'Create failed: ' . $e->getMessage(); }
    }
    if (preg_match('#^(done|complete|start|block)\s+(\d+)#i', $rest, $m)) {
        $verb = strtolower($m[1]);
        $status = $verb === 'start' ? 'in_progress' : ($verb === 'block' ? 'blocked' : 'completed');
        try {
            $ok = DailyTask::setStatus(intval($m[2]), $status);
            return $ok ? "✅ Task #{$m[2]} → $status." : "Task #{$m[2]} not found.";
        } catch (Throwable $e) { return 'Update failed: ' . $e->getMessage(); }
    }
    return "CEO usage: `/ceo list` · `/ceo create <title> | [priority] | [category]` · `/ceo done <id>`";
}

/** CRM write commands: task / lead. */
function aiCrmCommand($db, $text) {
    $rest = trim(preg_replace('#^/crm\s+#i', '', $text));
    if (preg_match('#^task\s+(.+)#is', $rest, $m)) {
        $title = trim($m[1]);
        if ($title === '') return 'Usage: `/crm task <title>`';
        try {
            $db->prepare("INSERT INTO tasks (title, description, status, priority, due_date, assigned_to) VALUES (?,?, 'pending','medium', date('now','+2 days'),'AI Chat')")
                ->execute([$mb = mb_substr($title, 0, 200), 'Created from AI Chat.']);
            return '✅ CRM task #' . (int)$db->lastInsertId() . ' created.';
        } catch (Throwable $e) { return 'Task create failed: ' . $e->getMessage(); }
    }
    if (preg_match('#^lead\s+(.+)#is', $rest, $m)) {
        $parts = array_map('trim', explode('|', $m[1]));
        $company = $parts[0] ?? '';
        if ($company === '') return 'Usage: `/crm lead <Company> | [Contact name] | [email]`';
        $contact = $parts[1] ?? '';
        $email = $parts[2] ?? '';
        $fn = ''; $ln = $contact;
        if (strpos($contact, ' ') !== false) { [$fn, $ln] = explode(' ', $contact, 2); }
        try {
            $db->prepare("INSERT INTO leads (first_name, last_name, email, company_name, source, status, priority, notes, score, generated_at) VALUES (?,?,?,?, 'ai_chat','new','medium',?,10,datetime('now'))")
                ->execute([$fn, $ln, $email, mb_substr($company, 0, 120), 'Created from AI Chat.']);
            $id = (int)$db->lastInsertId();
            try {
                require_once __DIR__ . '/../autoflows/autoflow_engine.php';
                if (function_exists('autoflowTrigger')) autoflowTrigger($db, 'lead_created', ['lead_id' => $id, 'source' => 'ai_chat']);
            } catch (Throwable $e) { /* ignore */ }
            return "✅ CRM lead #$id ($company) created — automations fired.";
        } catch (Throwable $e) { return 'Lead create failed: ' . $e->getMessage(); }
    }
    return "CRM usage: `/crm task <title>` · `/crm lead <Company> | [Contact] | [email]`";
}

/** Agentic Skills lookup (OWASP Top-10 for agentic skills). */
function aiSkillCommand($query) {
    if (!function_exists('agenticSkills')) {
        $f = __DIR__ . '/agentic_skills.php';
        if (!file_exists($f)) return 'Skills library unavailable.';
        require_once $f;
    }
    $skills = agenticSkills();
    if (!$skills) return 'Skills clone missing (external/agentic-skills-top-10).';
    if ($query === '') {
        $out = ['**Agentic Skills Top-10** (`/skill <n|query>` for detail):'];
        foreach ($skills as $s) $out[] = "- {$s['id']}: {$s['short']} [{$s['severity']}]";
        return implode("\n", $out);
    }
    foreach ($skills as $s) {
        if (stripos($s['id'], $query) !== false || stripos($s['title'], $query) !== false) {
            return "**{$s['id']}: {$s['short']}** [{$s['severity']}]\n\n{$s['description']}\n\n**In this app:** {$s['mitigation']}";
        }
    }
    // keyword fallback across descriptions
    foreach ($skills as $s) {
        if (stripos($s['description'], $query) !== false) {
            return "**{$s['id']}: {$s['short']}** [{$s['severity']}]\n\n{$s['description']}\n\n**In this app:** {$s['mitigation']}";
        }
    }
    return "No skill matches \"$query\". Try `/skill` for the full list.";
}
function aiClaritySkill($db, $mode, $text) {
    if ($text === '' && strtolower($mode) !== 'cowrite') return "Usage: `/clarity <co-write|rewrite|review|lint> | <text>`";
    if (!function_exists('clarityAgentRun')) {
        $f = __DIR__ . '/clarity_agent.php';
        if (!file_exists($f)) return 'Clarity agent unavailable.';
        require_once $f;
    }
    $res = clarityAgentRun($db, $mode, $text);
    if (empty($res['ok'])) return 'Clarity failed: ' . ($res['error'] ?? '?');
    $via = ($res['provider'] ?? '') === 'clarity-scripts' ? 'local lint' : (($res['fallback'] ?? false) ? 'template fallback' : ($res['model'] ?? 'TinyLLM'));
    $out = "✍️ **Clarity (" . ($res['mode'] ?? $mode) . ")** (via $via)\n\n" . mb_substr($res['content'], 0, 1200);
    if (mb_strlen($res['content']) > 1200) $out .= "\n\n…*(truncated — full output in chat)*";
    return $out;
}
function aiContentSkill($db, $kind, $topic) {
    if ($topic === '') return "Usage: `/$kind <topic>`";
    if (!function_exists('contentAgentRun')) {
        $f = __DIR__ . '/content_agent.php';
        if (!file_exists($f)) return 'ContentAgent unavailable.';
        require_once $f;
    }
    $res = contentAgentRun($db, $kind, $topic);
    if (empty($res['ok'])) return 'Draft failed: ' . ($res['error'] ?? '?');
    $via = ($res['fallback'] ? 'template fallback' : $res['model']);
    $out = "✅ **Draft #{$res['article_id']}: {$res['title']}** (via $via)\n\n" . mb_substr($res['content'], 0, 900);
    if (mb_strlen($res['content']) > 900) $out .= "\n\n…*(full text saved in Knowledge Bank draft #{$res['article_id']})*";
    return $out;
}
