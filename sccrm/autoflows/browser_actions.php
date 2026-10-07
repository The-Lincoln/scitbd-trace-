<?php
/**
 * SCCRM AutoFlows — agent-browser actions.
 * Required by autoflow_engine.php (loaded automatically if present).
 *
 * New triggers: browser_completed
 * New actions : browser_research | browser_monitor | browser_screenshot |
 *               browser_act | browser_extract
 *
 * Config keys:
 *   url, plan, goal, actions (array for browser_act), min_score passthrough,
 *   log_interaction (bool, default true), create_task_on_fail (bool)
 */

if (!class_exists('AgentBrowser')) {
    $canon = dirname(__DIR__, 2) . '/autoflows/app/services/AgentBrowser.php';
    if (is_file($canon)) {
        require_once $canon;
    }
}
if (!class_exists('BrowserAgent')) {
    $ba = dirname(__DIR__, 2) . '/autoflows/app/services/BrowserAgent.php';
    if (is_file($ba)) {
        require_once $ba;
    }
}
if (!class_exists('AgentBrowserService')) {
    $shim = __DIR__ . '/../services/AgentBrowserService.php';
    if (is_file($shim)) {
        require_once $shim;
    }
}

function autoflowBrowserTriggers(): array
{
    return ['browser_completed' => 'Browser job completed (research/monitor/act)'];
}

function autoflowBrowserActions(): array
{
    return [
        'browser_research' => 'Browser research (rendered intel + report)',
        'browser_monitor' => 'Browser monitor (uptime + snapshot check)',
        'browser_screenshot' => 'Browser screenshot evidence',
        'browser_act' => 'Browser actions (click/fill/wait sequence)',
        'browser_extract' => 'Browser extract (structured JSON-LD/links)',
    ];
}

/** Dispatch helper called from autoflowRun() switch. Returns message string. */
function autoflowActionBrowser(PDO $db, string $action, array $context, array $config): string
{
    if (function_exists('AgentBrowser') || class_exists('AgentBrowser')) {
        AgentBrowser::ensureTables($db);
    }
    $url = trim((string)($config['url'] ?? $context['url'] ?? $context['website'] ?? ''));
    $goal = trim((string)($config['goal'] ?? $context['subject'] ?? 'Scheduled browser check.'));
    $t0 = microtime(true);
    $status = 'success';
    $message = 'OK';
    try {
        switch ($action) {
            case 'browser_research':
                if ($url === '') {
                    return 'Skipped: no url in flow config/context';
                }
                $res = BrowserAgent::run(['plan' => 'research', 'url' => $url, 'goal' => $goal, 'module' => 'sccrm']);
                $message = $res['ok'] ? ('Research OK (' . count($res['steps']) . ' steps, ' . $res['ms'] . 'ms)') : ('Research FAILED: ' . mb_substr($res['report'], 0, 300));
                if (!$res['ok']) {
                    $status = 'error';
                }
                autoflowBrowserLog($db, $url, $goal, $res, $status);
                break;

            case 'browser_monitor':
                if ($url === '') {
                    return 'Skipped: no url in flow config/context';
                }
                $res = BrowserAgent::run(['plan' => 'monitor', 'url' => $url, 'goal' => $goal, 'module' => 'sccrm']);
                $message = $res['ok'] ? ('Monitor OK ' . $res['ms'] . 'ms') : ('Monitor FAIL: ' . mb_substr($res['report'], 0, 300));
                if (!$res['ok']) {
                    $status = 'error';
                }
                autoflowBrowserLog($db, $url, $goal, $res, $status);
                // Optional follow-up task on failure.
                if (!$res['ok'] && !empty($config['create_task_on_fail'])) {
                    $db->prepare("INSERT INTO tasks (title, description, status, priority, due_date, assigned_to) VALUES (?,?,?,?,date('now','+1 day'),'AutoFlow')")
                        ->execute(['Browser monitor failed: ' . mb_substr($url, 0, 120), $message, 'pending', 'high']);
                    $message .= ' (follow-up task created)';
                }
                break;

            case 'browser_screenshot':
                if ($url !== '') {
                    AgentBrowser::open($url, ['module' => 'sccrm']);
                }
                $shot = AgentBrowser::screenshot(null, ['module' => 'sccrm']);
                $message = $shot['ok'] ? ('Screenshot: ' . ($shot['path'] ?? '?')) : ('Screenshot failed: ' . ($shot['error'] ?? '?'));
                if (!$shot['ok']) {
                    $status = 'error';
                }
                autoflowBrowserLog($db, $url, $goal, ['ok' => $shot['ok'], 'steps' => [], 'shot' => $shot['path'] ?? null, 'report' => $message, 'ms' => $shot['ms'] ?? 0], $status);
                break;

            case 'browser_act':
                if ($url !== '') {
                    AgentBrowser::open($url, ['module' => 'sccrm']);
                }
                $actions = (array)($config['actions'] ?? $context['actions'] ?? []);
                if ($actions === []) {
                    return 'Skipped: no actions[] in flow config';
                }
                $res = BrowserAgent::run(['plan' => 'act', 'url' => $url !== '' ? $url : 'https://example.com', 'goal' => $goal, 'actions' => $actions, 'module' => 'sccrm']);
                $message = $res['ok'] ? ('Act OK (' . count($res['steps']) . ' steps)') : ('Act FAILED: ' . mb_substr($res['report'], 0, 300));
                if (!$res['ok']) {
                    $status = 'error';
                }
                autoflowBrowserLog($db, $url, $goal, $res, $status);
                break;

            case 'browser_extract':
                if ($url === '') {
                    return 'Skipped: no url in flow config/context';
                }
                $res = BrowserAgent::run(['plan' => 'extract', 'url' => $url, 'goal' => $goal, 'module' => 'sccrm']);
                $message = $res['ok'] ? ('Extract OK ' . $res['ms'] . 'ms') : ('Extract FAILED');
                if (!$res['ok']) {
                    $status = 'error';
                }
                autoflowBrowserLog($db, $url, $goal, $res, $status);
                break;

            default:
                return 'Unknown browser action: ' . $action;
        }
    } catch (Throwable $e) {
        $status = 'error';
        $message = 'Browser action exception: ' . $e->getMessage();
    }
    $ms = round((microtime(true) - $t0) * 1000, 1);
    // Fire chained flows (e.g. log interaction on completion).
    try {
        if (function_exists('autoflowTrigger')) {
            autoflowTrigger($db, 'browser_completed', ['url' => $url, 'subject' => $goal, 'message' => mb_substr($message, 0, 500), 'status' => $status, 'duration_ms' => $ms]);
        }
    } catch (Throwable $e) {
    }
    if ($status === 'error') {
        throw new RuntimeException($message);
    }
    return $message . " ({$ms}ms)";
}

/** Log interaction + persist run row (best-effort, never throws). */
function autoflowBrowserLog(PDO $db, string $url, string $goal, array $res, string $status): void
{
    try {
        AgentBrowser::logRun($db, 'sccrm', 'browser:' . ($res['plan'] ?? 'job'), $url, (bool)($res['ok'] ?? false), (int)($res['ms'] ?? 0), mb_substr($res['report'] ?? '', 0, 1500));
    } catch (Throwable $e) {
    }
    try {
        $content = mb_substr(($res['report'] ?? '') . ($res['shot'] ? ' | shot: ' . $res['shot'] : ''), 0, 2000);
        $db->prepare("INSERT INTO interactions (type, subject, content, date, created_by) VALUES (?,?,?,?,?)")
            ->execute(['note', mb_substr('Browser: ' . ($goal !== '' ? $goal : $url), 0, 200), $content !== '' ? $content : $status, date('Y-m-d'), 'BrowserAgent']);
    } catch (Throwable $e) {
    }
}
