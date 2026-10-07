<?php
/**
 * SCCRM AI — browser skill for the TinyLLM chat.
 * Slash commands (handled inside aiSlashCommand() chain):
 *
 *   /browser status                    — CLI availability + session
 *   /browser open <url>                — open + snapshot refs
 *   /browser shot [url]                — screenshot evidence
 *   /browser read <url>                — agent-readable text (no Chrome needed for http fetch)
 *   /browser research <url> | [goal]   — full BrowserAgent research + report
 *   /browser monitor <url>             — uptime/snapshot check
 *   /browser act <url> | <do> <sel> [text] — single action (click/fill/type/press/wait/get)
 *   /trace-render <url>                — alias for research (rendered DOM for SPA)
 *
 * Write ops log to interactions + agent_browser_runs. Never throws.
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

/** Returns string reply or null (not a browser command). */
function aiBrowserCommand(PDO $db, string $text): ?string
{
    $t = trim($text);
    if (!preg_match('#^/(browser|trace-render|render)\b#i', $t)) {
        return null;
    }
    try {
        AgentBrowser::ensureTables($db);
    } catch (Throwable $e) {
    }

    // /trace-render <url> — shortcut
    if (preg_match('#^/trace-render\s+(\S+)(?:\s*\|\s*(.+))?#is', $t, $m)) {
        return aiBrowserResearch($db, trim($m[1]), trim($m[2] ?? ''));
    }
    if (preg_match('#^/render\s+(\S+)#i', $t, $m)) {
        return aiBrowserResearch($db, trim($m[1]), '');
    }

    $rest = trim((string)preg_replace('#^/browser\s*#i', '', $t));
    if ($rest === '' || preg_match('#^(help|\?)$#i', $rest)) {
        return "**Browser commands** (agent-browser, session `scitbd-sccrm`)\n\n"
            . "- `/browser status` — CLI availability\n"
            . "- `/browser open <url>` — open + refs\n"
            . "- `/browser read <url>` — agent-readable text\n"
            . "- `/browser shot [url]` — screenshot\n"
            . "- `/browser research <url> | [goal]` — full intel report\n"
            . "- `/browser monitor <url>` — uptime check\n"
            . "- `/browser act <url> | <click|fill|type|press|wait|get> <sel> [text]`\n"
            . "- `/trace-render <url>` — rendered trace for JS-heavy pages";
    }
    if (preg_match('#^status#i', $rest)) {
        $s = AgentBrowser::status('sccrm');
        return '**Browser status:** ' . ($s['ok'] ? 'ready ✅' : 'not installed ⚠️')
            . "\n- Binary: `{$s['bin']}`\n- Version: {$s['version']}\n- Session: `{$s['session']}`"
            . ($s['ok'] ? '' : "\n- Install: `npm install -g agent-browser && agent-browser install`");
    }
    if (preg_match('#^open\s+(\S+)#i', $rest, $m)) {
        $url = aiBrowserNormaliseUrl($m[1]);
        $o = AgentBrowser::open($url, ['module' => 'sccrm', 'timeout' => 60]);
        AgentBrowser::logRun($db, 'sccrm', 'open', $url, $o['ok'], $o['ms'], mb_substr($o['text'], 0, 800));
        if (!$o['ok']) {
            return 'Open failed: ' . ($o['error'] ?? '?');
        }
        $snap = AgentBrowser::snapshot(['module' => 'sccrm']);
        $refs = implode(' ', array_slice($snap['refs'] ?? [], 0, 12));
        $out = "**Opened:** $url ({$o['ms']}ms)\n\n**Snapshot (truncated):**\n```\n" . mb_substr($snap['text'] ?? '', 0, 1800) . "\n```";
        if ($refs !== '') {
            $out .= "\n**Refs:** $refs — e.g. `/browser act $url | click @e2`";
        }
        return $out;
    }
    if (preg_match('#^read\s+(\S+)#i', $rest, $m)) {
        $url = aiBrowserNormaliseUrl($m[1]);
        $r = AgentBrowser::read($url, ['module' => 'sccrm']);
        AgentBrowser::logRun($db, 'sccrm', 'read', $url, $r['ok'], $r['ms'], mb_substr($r['text'], 0, 800));
        if (!$r['ok']) {
            return 'Read failed: ' . ($r['error'] ?? '?');
        }
        return "**Read: $url** ({$r['ms']}ms)\n\n" . mb_substr($r['text'], 0, 1800);
    }
    if (preg_match('#^shot(?:shot)?\s*(\S*)$#i', $rest, $m)) {
        $url = trim($m[1] ?? '');
        if ($url !== '') {
            AgentBrowser::open(aiBrowserNormaliseUrl($url), ['module' => 'sccrm']);
        }
        $s = AgentBrowser::screenshot(null, ['module' => 'sccrm']);
        AgentBrowser::logRun($db, 'sccrm', 'screenshot', $url, $s['ok'], $s['ms'], (string)($s['path'] ?? ''));
        return $s['ok'] ? ("📸 Screenshot saved: `" . ($s['path'] ?? '?') . "`") : ('Screenshot failed: ' . ($s['error'] ?? '?'));
    }
    if (preg_match('#^research\s+(\S+)(?:\s*\|\s*(.+))?#is', $rest, $m)) {
        return aiBrowserResearch($db, trim($m[1]), trim($m[2] ?? ''));
    }
    if (preg_match('#^monitor\s+(\S+)#i', $rest, $m)) {
        $url = aiBrowserNormaliseUrl($m[1]);
        $res = BrowserAgent::run(['plan' => 'monitor', 'url' => $url, 'goal' => 'AI chat monitor check.', 'module' => 'sccrm']);
        AgentBrowser::logRun($db, 'sccrm', 'browser:monitor', $url, $res['ok'], $res['ms'], mb_substr($res['report'], 0, 800));
        return ($res['ok'] ? '✅ ' : '❌ ') . $res['report'] . ($res['shot'] ? "\n\n📸 `" . $res['shot'] . '`' : '');
    }
    if (preg_match('#^act\s+(\S+)\s*\|\s*(\S+)(?:\s+(\S+))?(?:\s+(.+))?#is', $rest, $m)) {
        $url = aiBrowserNormaliseUrl($m[1]);
        $do = strtolower(trim($m[2]));
        $sel = trim($m[3] ?? '');
        $text = trim($m[4] ?? '');
        // allow `/browser act <url> | click @e2` (no sel split)
        if ($sel === '' && $text === '') {
            // $do already holds e.g. "click"; nothing else — error out with usage.
            return 'Usage: `/browser act <url> | <click|fill|type|press|wait|get> <sel> [text]`';
        }
        AgentBrowser::open($url, ['module' => 'sccrm']);
        $action = ['do' => $do, 'sel' => $sel, 'text' => $text, 'key' => $text, 'target' => $sel !== '' ? $sel : $text, 'what' => 'text'];
        $r = BrowserAgent::runAction($action, ['module' => 'sccrm', 'session' => AgentBrowser::sessionFor('sccrm')]);
        AgentBrowser::logRun($db, 'sccrm', 'browser:act:' . $do, $url, $r['ok'], $r['ms'], mb_substr($r['text'] ?? '', 0, 800));
        $snap = AgentBrowser::snapshot(['module' => 'sccrm']);
        return ($r['ok'] ? '✅' : '❌') . " `{$do} {$sel} {$text}` on $url ({$r['ms']}ms)\n```\n" . mb_substr($r['text'] ?? '', 0, 900) . "\n```\n**Fresh refs:** " . implode(' ', array_slice($snap['refs'] ?? [], 0, 10));
    }
    return 'Unknown browser sub-command. Try `/browser help`.';
}

function aiBrowserResearch(PDO $db, string $url, string $goal): string
{
    $url = aiBrowserNormaliseUrl($url);
    $res = BrowserAgent::run(['plan' => 'research', 'url' => $url, 'goal' => $goal !== '' ? $goal : 'Research for CRM lead scoring.', 'module' => 'sccrm']);
    AgentBrowser::logRun($db, 'sccrm', 'browser:research', $url, $res['ok'], $res['ms'], mb_substr($res['report'], 0, 1000));
    try {
        $db->prepare("INSERT INTO interactions (type, subject, content, date, created_by) VALUES (?,?,?,?,?)")
            ->execute(['note', mb_substr('Browser research: ' . $url, 0, 200), mb_substr($res['report'] . ($res['shot'] ? "\nShot: " . $res['shot'] : ''), 0, 2000), date('Y-m-d'), 'BrowserAgent']);
    } catch (Throwable $e) {
    }
    return ($res['ok'] ? "✅ **Research complete** ({$res['ms']}ms, " . count($res['steps']) . " steps)\n\n" : "❌ **Research had failures**\n\n") . $res['report'] . ($res['shot'] ? "\n\n📸 `" . $res['shot'] . '`' : '');
}

function aiBrowserNormaliseUrl(string $u): string
{
    $u = trim($u, '<> ');
    if (!preg_match('#^https?://#i', $u)) {
        $u = 'https://' . $u;
    }
    return $u;
}
