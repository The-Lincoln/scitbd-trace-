<?php
/**
 * SCIT CRM — AutoFlows automation engine.
 * Standalone (no layout dependency). Triggers: lead_created, trace_completed,
 * schedule, manual. Actions: enrich_lead, create_task, log_interaction, digest, webhook.
 * + agent-browser bridge (browser_actions.php): browser_completed trigger,
 *   browser_research/monitor/screenshot/act/extract actions.
 */

// agent-browser bridge (optional file — never fatal when missing).
if (is_file(__DIR__ . '/browser_actions.php')) {
    require_once __DIR__ . '/browser_actions.php';
}

function autoflowEnsureTables($db) {
    $db->exec("CREATE TABLE IF NOT EXISTS autoflows (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        description TEXT,
        trigger TEXT NOT NULL DEFAULT 'manual',
        action TEXT NOT NULL DEFAULT 'digest',
        config TEXT,
        is_active INTEGER DEFAULT 1,
        run_count INTEGER DEFAULT 0,
        last_run_at DATETIME,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    $db->exec("CREATE TABLE IF NOT EXISTS autoflow_runs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        flow_id INTEGER,
        trigger_event TEXT,
        status TEXT DEFAULT 'success',
        message TEXT,
        context TEXT,
        duration_ms REAL DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (flow_id) REFERENCES autoflows(id) ON DELETE CASCADE
    )");
    // Seed default flows once
    try {
        $n = (int)$db->query("SELECT COUNT(*) FROM autoflows")->fetchColumn();
        if ($n === 0) { autoflowSeedDefaults($db); }
    } catch (Throwable $e) { /* ignore */ }
    // Backfill newer automations into installs seeded earlier (idempotent by name).
    autoflowEnsureSeed($db, 'Hot lead CEO review', 'Push high-value leads (85+) to the CEO daily queue for review.', 'lead_created', 'create_ceo_task', ['min_score' => 85]);
    autoflowEnsureSeed($db, 'Morning SEO Blog Draft', 'TinyLLM drafts the Block-1 SEO article into the Knowledge Bank.', 'manual', 'generate_content', ['kind' => 'blog', 'topic' => 'How SCITBD delivers AI-powered digital transformation']);
}

function autoflowSeedDefaults($db) {
    $defaults = [
        ['Enrich new leads with OSINT', 'Auto-trace the lead website (DNS + tech stack + SEO/security scores).', 'lead_created', 'enrich_lead', json_encode([]), 1],
        ['Hot lead follow-up task', 'Auto-create a follow-up task when a new lead scores 70+.', 'lead_created', 'create_task', json_encode(['min_score' => 70, 'due_in_days' => 2, 'title' => 'Follow up: {company} ({score})']), 1],
        ['Hot lead CEO review', 'Push high-value leads (85+) to the CEO daily queue for review.', 'lead_created', 'create_ceo_task', json_encode(['min_score' => 85]), 1],
        ['Trace intel to interaction log', 'Log every completed URL trace as a CRM interaction note.', 'trace_completed', 'log_interaction', json_encode([]), 1],
        ['Daily pipeline digest', 'Scheduled summary of new leads, traces, open tasks and tickets.', 'schedule', 'digest', json_encode(['create_task' => 0]), 1],
    ];
    $stmt = $db->prepare("INSERT INTO autoflows (name, description, trigger, action, config, is_active) VALUES (?,?,?,?,?,?)");
    foreach ($defaults as $d) { $stmt->execute($d); }
}

/** Insert a seed flow by name if missing (lets old installs gain new automations). */
function autoflowEnsureSeed($db, $name, $description, $trigger, $action, $config = []) {
    try {
        $st = $db->prepare("SELECT COUNT(*) FROM autoflows WHERE name = ?");
        $st->execute([$name]);
        if ((int)$st->fetchColumn() === 0) {
            $db->prepare("INSERT INTO autoflows (name, description, trigger, action, config, is_active) VALUES (?,?,?,?,?,1)")
                ->execute([$name, $description, $trigger, $action, json_encode($config)]);
        }
    } catch (Throwable $e) { /* ignore */ }
}

function autoflowTriggers() {
    $base = [
        'lead_created' => 'Lead created (generator, import, trace push)',
        'trace_completed' => 'URL trace completed',
        'schedule' => 'Scheduled (cron / CLI)',
        'manual' => 'Manual run only',
    ];
    if (function_exists('autoflowBrowserTriggers')) {
        $base = array_merge($base, autoflowBrowserTriggers());
    }
    return $base;
}

function autoflowActions() {
    $base = [
        'enrich_lead' => 'Enrich lead with OSINT website intel',
        'create_task' => 'Create follow-up task',
        'create_ceo_task' => 'Push CEO review task (CEO Office)',
        'generate_content' => 'TinyLLM content draft (Knowledge Bank)',
        'log_interaction' => 'Log interaction note',
        'digest' => 'Pipeline digest report',
        'webhook' => 'POST JSON webhook',
    ];
    if (function_exists('autoflowBrowserActions')) {
        $base = array_merge($base, autoflowBrowserActions());
    }
    return $base;
}

/** Fire all active flows for an event. Never throws. Returns per-flow results. */
function autoflowTrigger($db, $event, $context = []) {
    $results = [];
    try {
        autoflowEnsureTables($db);
        $stmt = $db->prepare("SELECT * FROM autoflows WHERE trigger = ? AND is_active = 1 ORDER BY id");
        $stmt->execute([$event]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $flow) {
            $results[] = autoflowRun($db, $flow, $event, $context);
        }
    } catch (Throwable $e) { /* automation must never break the app */ }
    return $results;
}

/** Run a single flow. Returns ['status'=>..., 'message'=>...]. */
function autoflowRun($db, $flow, $event = 'manual', $context = []) {
    $t0 = microtime(true);
    $status = 'success';
    $message = 'OK';
    try {
        autoflowEnsureTables($db);
        if (is_string($flow)) { // flow id passed
            $stmt = $db->prepare("SELECT * FROM autoflows WHERE id = ?");
            $stmt->execute([intval($flow)]);
            $flow = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$flow) return ['status' => 'error', 'message' => 'Flow not found'];
        }
        $config = json_decode($flow['config'] ?? '{}', true) ?: [];
        switch ($flow['action']) {
            case 'enrich_lead':      $message = autoflowActionEnrichLead($db, $context, $config); break;
            case 'create_task':      $message = autoflowActionCreateTask($db, $context, $config); break;
            case 'create_ceo_task':  $message = autoflowActionCreateCeoTask($db, $context, $config); break;
            case 'generate_content': $message = autoflowActionGenerateContent($db, $context, $config); break;
            case 'log_interaction':  $message = autoflowActionLogInteraction($db, $context, $config); break;
            case 'digest':           $message = autoflowActionDigest($db, $context, $config); break;
            case 'webhook':          $message = autoflowActionWebhook($context, $config); break;
            case 'browser_research':
            case 'browser_monitor':
            case 'browser_screenshot':
            case 'browser_act':
            case 'browser_extract':
                $message = autoflowActionBrowser($db, $flow['action'], $context, $config);
                break;
            default:                 $status = 'error'; $message = 'Unknown action: ' . $flow['action'];
        }
    } catch (Throwable $e) { $status = 'error'; $message = $e->getMessage(); }
    $ms = round((microtime(true) - $t0) * 1000, 1);
    try {
        $fid = is_array($flow) ? ($flow['id'] ?? null) : null;
        $stmt = $db->prepare("INSERT INTO autoflow_runs (flow_id, trigger_event, status, message, context, duration_ms) VALUES (?,?,?,?,?,?)");
        $stmt->execute([$fid, $event, $status, mb_substr((string)$message, 0, 2000), json_encode($context), $ms]);
        if ($fid) {
            $db->prepare("UPDATE autoflows SET run_count = run_count + 1, last_run_at = datetime('now'), updated_at = datetime('now') WHERE id = ?")->execute([$fid]);
        }
    } catch (Throwable $e) { /* ignore */ }
    return ['status' => $status, 'message' => $message, 'duration_ms' => $ms];
}

function autoflowGetLead($db, $leadId) {
    $stmt = $db->prepare("SELECT * FROM leads WHERE id = ?");
    $stmt->execute([intval($leadId)]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function autoflowEnsureGeneratorHelpers() {
    if (!function_exists('enrichLeadWithOsint')) {
        $f = __DIR__ . '/../leads/generator_functions.php';
        if (file_exists($f)) { require_once $f; }
    }
}

/** Action: enrich a lead's website intel (skips if already enriched). */
function autoflowActionEnrichLead($db, $context, $config) {
    $leadId = intval($context['lead_id'] ?? 0);
    if (!$leadId) return 'Skipped: no lead_id in context';
    $lead = autoflowGetLead($db, $leadId);
    if (!$lead) return 'Skipped: lead #' . $leadId . ' not found';
    if (!empty($lead['osint_data'])) return 'Skipped: lead #' . $leadId . ' already enriched';
    $website = $lead['website'] ?: ('https://' . preg_replace('/[^a-z0-9]/', '', strtolower($lead['company_name'] ?? 'company')) . '.com');
    autoflowEnsureGeneratorHelpers();
    if (!function_exists('enrichLeadWithOsint')) return 'Skipped: OSINT helpers unavailable';
    $intel = enrichLeadWithOsint($lead['company_name'] ?? 'Company', $website);
    $newScore = min(100, intval($lead['score'] ?? 0) + $intel['score_bonus']);
    $notes = trim(($lead['notes'] ?? '') . ' ' . $intel['note_extra']);
    $db->prepare("UPDATE leads SET website = ?, tech_stack = ?, seo_score = ?, security_score = ?, osint_data = ?, score = ?, notes = ?, updated_at = datetime('now') WHERE id = ?")
        ->execute([$intel['website'], $intel['tech_stack'], $intel['seo_score'], $intel['security_score'], $intel['osint_json'], $newScore, $notes, $leadId]);
    return "Enriched lead #$leadId ({$intel['website']}, +" . $intel['score_bonus'] . ' score)';
}

/** Action: create a follow-up task (default: hot leads >= min_score). */
function autoflowActionCreateTask($db, $context, $config) {
    $leadId = intval($context['lead_id'] ?? 0);
    $minScore = intval($config['min_score'] ?? 70);
    $dueIn = max(0, intval($config['due_in_days'] ?? 2));
    $titleTpl = $config['title'] ?? 'Follow up: {company} ({score})';
    if ($leadId) {
        $lead = autoflowGetLead($db, $leadId);
        if (!$lead) return 'Skipped: lead not found';
        if (intval($lead['score'] ?? 0) < $minScore) return 'Skipped: lead #' . $leadId . ' score ' . ($lead['score'] ?? 0) . " < $minScore";
        $title = str_replace(['{company}', '{score}', '{name}'],
            [$lead['company_name'] ?? '-', $lead['score'] ?? 0, trim(($lead['first_name'] ?? '') . ' ' . ($lead['last_name'] ?? ''))], $titleTpl);
        $db->prepare("INSERT INTO tasks (title, description, status, priority, due_date, assigned_to) VALUES (?,?,?,?,date('now','+$dueIn days'),'AutoFlow')")
            ->execute([$title, 'Auto-created by AutoFlow for lead #' . $leadId . ' (' . ($lead['company_name'] ?? '-') . ').', 'pending', in_array($lead['priority'] ?? '', ['low','medium','high','urgent']) ? $lead['priority'] : 'high']);
        return "Task created for lead #$leadId";
    }
    // No lead context (e.g. digest flow with create_task=1): generic check-in task
    if (!empty($config['create_task'])) {
        $db->prepare("INSERT INTO tasks (title, description, status, priority, due_date, assigned_to) VALUES (?,?,?,?,date('now','+1 day'),'AutoFlow')")
            ->execute(['AutoFlow check-in', 'Scheduled AutoFlow check-in.', 'pending', 'medium']);
        return 'Check-in task created';
    }
    return 'Skipped: no lead_id in context';
}

/** Action: push a high-value lead into the CEO daily queue (ScitbdCeo daily_tasks + task_logs). */
function autoflowActionCreateCeoTask($db, $context, $config) {
    $leadId = intval($context['lead_id'] ?? 0);
    if (!$leadId) return 'Skipped: no lead_id in context';
    $lead = autoflowGetLead($db, $leadId);
    if (!$lead) return 'Skipped: lead not found';
    $minScore = intval($config['min_score'] ?? 85);
    if (intval($lead['score'] ?? 0) < $minScore) {
        return 'Skipped: lead #' . $leadId . ' score ' . ($lead['score'] ?? 0) . " < $minScore";
    }
    $ceoRoot = dirname(__DIR__, 2) . '/autoflows/app';
    if (!class_exists('DailyTask')) {
        $m = $ceoRoot . '/models/DailyTask.php';
        $s = $ceoRoot . '/services/ScitbdCeo.php';
        if (!file_exists($m) || !file_exists($s)) return 'Skipped: CEO bridge unavailable';
        require_once $s;
        require_once $m;
    }
    $company = $lead['company_name'] ?? '-';
    $id = DailyTask::create([
        'task_title' => 'CEO review: ' . mb_substr($company, 0, 120) . ' (score ' . ($lead['score'] ?? 0) . ')',
        'task_description' => 'Auto-pushed by AutoFlow from lead #' . $leadId . '. Contact: ' . trim(($lead['first_name'] ?? '') . ' ' . ($lead['last_name'] ?? '')) . ' <' . ($lead['email'] ?? '-') . '>. Budget: ' . ($lead['budget'] ?? '-') . '. Notes: ' . ($lead['notes'] ?? ''),
        'priority' => 'high',
        'category' => 'sales',
        'assignee' => 'ceo',
        'bst_block_id' => 2,
        'due_date' => date('Y-m-d 23:30:00'),
        'estimated_hours' => 1.0,
        'created_by' => 'autoflow',
    ]);
    return "CEO task #$id created for lead #$leadId";
}

/** Action: TinyLLM content draft via the ContentAgent (blog/social/email/video). */
function autoflowActionGenerateContent($db, $context, $config) {
    $kind = strtolower(trim($config['kind'] ?? 'blog'));
    $topic = trim($config['topic'] ?? ($context['topic'] ?? ''));
    if ($topic === '') return 'Skipped: no topic in flow config';
    if (!function_exists('contentAgentRun')) {
        $f = dirname(__DIR__) . '/ai/content_agent.php';
        if (!file_exists($f)) return 'Skipped: ContentAgent unavailable';
        require_once $f;
    }
    $res = contentAgentRun($db, $kind, $topic, ['service_id' => intval($config['service_id'] ?? 0)]);
    if (empty($res['ok'])) return 'Content failed: ' . ($res['error'] ?? '?');
    $via = ($res['fallback'] ? 'template fallback' : $res['model']);
    return "Draft #{$res['article_id']} '{$res['title']}' via $via";
}

/** Action: log an interaction note (trace intel, lead events). */
function autoflowActionLogInteraction($db, $context, $config) {
    $subject = 'AutoFlow: ' . ($context['subject'] ?? ($context['url'] ?? 'event'));
    $parts = [];
    if (!empty($context['url'])) $parts[] = 'URL: ' . $context['url'];
    if (isset($context['seo_score'])) $parts[] = 'SEO ' . $context['seo_score'] . '/100';
    if (isset($context['security_score'])) $parts[] = 'Security ' . $context['security_score'] . '/100';
    if (!empty($context['tech_stack'])) $parts[] = 'Tech: ' . $context['tech_stack'];
    if (!empty($context['lead_id'])) $parts[] = 'Lead #' . $context['lead_id'];
    if (!empty($context['message'])) $parts[] = $context['message'];
    $content = implode(' | ', $parts) ?: 'Automated event log.';
    $db->prepare("INSERT INTO interactions (type, subject, content, date, created_by) VALUES (?,?,?,?,?)")
        ->execute(['note', mb_substr($subject, 0, 200), $content, date('Y-m-d'), 'AutoFlow']);
    return 'Interaction logged: ' . mb_substr($subject, 0, 120);
}

/** Action: pipeline digest (counts + optional task). */
function autoflowActionDigest($db, $context, $config) {
    $counts = [];
    foreach ([
        'new_leads_24h' => "SELECT COUNT(*) FROM leads WHERE created_at >= datetime('now','-1 day')",
        'open_tasks' => "SELECT COUNT(*) FROM tasks WHERE status != 'completed' AND status != 'cancelled'",
        'open_tickets' => "SELECT COUNT(*) FROM support_tickets WHERE status NOT IN ('resolved','closed')",
        'flows_active' => "SELECT COUNT(*) FROM autoflows WHERE is_active = 1",
    ] as $k => $sql) {
        try { $counts[$k] = (int)$db->query($sql)->fetchColumn(); }
        catch (Throwable $e) { $counts[$k] = -1; }
    }
    // Trace count from the shared OSINT store (best-effort)
    $counts['traces_24h'] = -1;
    try {
        $osintDb = dirname(__DIR__, 2) . '/data/osint.db';
        if (file_exists($osintDb)) {
            $pdo = new PDO('sqlite:' . $osintDb);
            $counts['traces_24h'] = (int)$pdo->query("SELECT COUNT(*) FROM url_traces WHERE created_at >= datetime('now','-1 day')")->fetchColumn();
        }
    } catch (Throwable $e) { /* ignore */ }
    $msg = sprintf('Digest: %d new leads/24h, %d traces/24h, %d open tasks, %d open tickets, %d flows active.',
        $counts['new_leads_24h'], $counts['traces_24h'], $counts['open_tasks'], $counts['open_tickets'], $counts['flows_active']);
    if (!empty($config['create_task'])) { autoflowActionCreateTask($db, [], ['create_task' => 1]); $msg .= ' Check-in task created.'; }
    return $msg;
}

/** Action: POST JSON webhook (http/https only, 8s timeout). */
function autoflowActionWebhook($context, $config) {
    $url = trim($config['url'] ?? '');
    if (!preg_match('#^https?://#i', $url)) return 'Skipped: webhook url must be http(s)';
    $payload = json_encode(['event' => $context, 'at' => date('c')]);
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_TIMEOUT => 8, CURLOPT_CONNECTTIMEOUT => 5]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($resp === false) return 'Webhook failed: ' . $err;
    return 'Webhook POST ' . $code;
}
