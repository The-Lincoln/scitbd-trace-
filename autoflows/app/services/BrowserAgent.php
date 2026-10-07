<?php
/**
 * BrowserAgent — AI-driven browser autoflow.
 *
 * TinyLLM plans (offline-safe template fallback), AgentBrowser executes.
 * Used by: AutoFlows Browser console, SCCRM /browser AI skill, CEO tasks,
 *          Trace rendered-mode enrichment, cron monitors.
 *
 * Plans:
 *   research  : open → read → snapshot → eval(extract) → screenshot → report
 *   monitor   : open → snapshot → get(title/url) → screenshot → compare
 *   act       : open → snapshot → click|fill|press → wait → snapshot → shot
 *   extract   : open → read → eval(article/JSON-LD/links) → report
 */
declare(strict_types=1);

final class BrowserAgent
{
    public const PLANS = ['research', 'monitor', 'act', 'extract'];

    /**
     * Run a browser job.
     *
     * @param array $input {plan,url,goal,actions[],session,module,screenshot:bool,allowed_domains[]}
     * @param callable|null $emit fn(array $e):void  SSE-style progress events
     * @return array{ok:bool,plan:string,url:string,steps:array,shot:string|null,report:string,ms:int}
     */
    public static function run(array $input, ?callable $emit = null): array
    {
        $t0 = microtime(true);
        $emit ??= static function (array $e): void {};
        $plan = strtolower(trim((string)($input['plan'] ?? 'research')));
        if (!in_array($plan, self::PLANS, true)) {
            $plan = 'research';
        }
        $url = trim((string)($input['url'] ?? ''));
        if ($url !== '' && !preg_match('#^https?://#i', $url)) {
            $url = 'https://' . $url;
        }
        if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
            return ['ok' => false, 'plan' => $plan, 'url' => $url, 'steps' => [], 'shot' => null, 'report' => 'A valid http(s) URL is required.', 'ms' => 0];
        }
        $module = (string)($input['module'] ?? 'autoflows');
        $session = (string)($input['session'] ?? AgentBrowser::sessionFor($module));
        $wantShot = array_key_exists('screenshot', $input) ? (bool)$input['screenshot'] : true;
        $common = ['module' => $module, 'session' => $session, 'timeout' => 60];
        if (!empty($input['allowed_domains']) && is_array($input['allowed_domains'])) {
            $common['allowed_domains'] = $input['allowed_domains'];
        }

        $steps = [];
        $push = function (string $id, string $label, array $res) use (&$steps, $emit): void {
            $steps[] = ['id' => $id, 'label' => $label, 'ok' => $res['ok'], 'ms' => $res['ms'] ?? 0, 'excerpt' => mb_substr($res['text'] ?? '', 0, 600)];
            $emit(['type' => 'browser_step', 'id' => $id, 'label' => $label, 'ok' => $res['ok']]);
        };

        // 1) open
        $emit(['type' => 'step', 'id' => 'open', 'label' => 'Open ' . $url]);
        $rOpen = AgentBrowser::open($url, $common + ['timeout' => 75]);
        $push('open', 'Open page', $rOpen);
        if (!$rOpen['ok']) {
            return self::finish(false, $plan, $url, $steps, null, 'Open failed: ' . ($rOpen['error'] ?? '?'), $t0);
        }

        // 2) read (agent-friendly text, no new navigation)
        $emit(['type' => 'step', 'id' => 'read', 'label' => 'Read rendered text']);
        $rRead = AgentBrowser::read(null, $common);
        $push('read', 'Read rendered DOM', $rRead);
        $readText = $rRead['ok'] ? $rRead['text'] : '';

        // 3) snapshot (refs for AI + act plans)
        $emit(['type' => 'step', 'id' => 'snapshot', 'label' => 'Snapshot refs']);
        $rSnap = AgentBrowser::snapshot($common);
        $push('snapshot', 'Accessibility snapshot', $rSnap);

        // 4) plan-specific middle
        $extractions = [];
        if ($plan === 'act') {
            foreach (array_slice((array)($input['actions'] ?? []), 0, 12) as $i => $a) {
                $res = self::runAction($a, $common);
                $push('act' . $i, 'Action: ' . self::describeAction($a), $res);
                if (!$res['ok'] && !empty($a['required'])) {
                    break;
                }
            }
            // re-snapshot after actions so refs stay fresh (agent-browser guidance)
            $rSnap2 = AgentBrowser::snapshot($common);
            $push('snapshot2', 'Re-snapshot after actions', $rSnap2);
            $rSnap = $rSnap2['ok'] ? $rSnap2 : $rSnap;
        } else {
            // structured extraction via page JS (works even when read is thin)
            $emit(['type' => 'step', 'id' => 'extract', 'label' => 'Extract structured data']);
            $rEval = AgentBrowser::eval(
                '(function(){try{var t=document.title||"";var d="";var m=document.querySelector(\'meta[name="description"]\');if(m)d=m.getAttribute("content")||"";var h1s=[...document.querySelectorAll("h1")].map(e=>e.innerText.trim()).filter(Boolean).slice(0,5);var links=[...document.querySelectorAll("a[href]")].map(a=>a.href).filter((v,i,s)=>v&&s.indexOf(v)===i).slice(0,20);var ld=[...document.querySelectorAll(\'script[type="application/ld+json"]\')].map(s=>s.textContent.slice(0,2000));return JSON.stringify({title:t,desc:d,h1:h1s,links:links,ld:ld});}catch(e){return JSON.stringify({error:String(e)})}})()',
                $common
            );
            $push('extract', 'JS extraction', $rEval);
            $extractions['js'] = $rEval['ok'] ? $rEval['text'] : '';
        }

        // 5) evidence screenshot
        $shot = null;
        if ($wantShot) {
            $emit(['type' => 'step', 'id' => 'shot', 'label' => 'Screenshot evidence']);
            $rShot = AgentBrowser::screenshot(null, $common + ['full' => ($plan === 'research')]);
            $push('shot', 'Screenshot', $rShot);
            if (!empty($rShot['path']) && !empty($rShot['exists'])) {
                $shot = $rShot['path'];
            }
        }

        // 6) TinyLLM summarises into a report (offline template when Ollama down)
        $goal = trim((string)($input['goal'] ?? 'Summarise this page for CRM/CEO use.'));
        $report = self::summarise($plan, $url, $goal, $readText, $rSnap['text'] ?? '', $extractions['js'] ?? '', $steps);

        $emit(['type' => 'done', 'ok' => true]);
        return self::finish(true, $plan, $url, $steps, $shot, $report, $t0);
    }

    /** Execute one declarative action: {do:click|fill|type|press|wait|eval|shot, sel, text, key, ms, js}. */
    public static function runAction(array $a, array $common): array
    {
        $do = strtolower(trim((string)($a['do'] ?? $a['action'] ?? 'click')));
        return match ($do) {
            'click' => AgentBrowser::click((string)($a['sel'] ?? ''), $common),
            'fill' => AgentBrowser::fill((string)($a['sel'] ?? ''), (string)($a['text'] ?? ''), $common),
            'type' => AgentBrowser::type((string)($a['sel'] ?? ''), (string)($a['text'] ?? ''), $common),
            'press' => AgentBrowser::press((string)($a['key'] ?? $a['text'] ?? 'Enter'), $common),
            'hover' => AgentBrowser::hover((string)($a['sel'] ?? ''), $common),
            'wait' => AgentBrowser::wait((string)($a['target'] ?? $a['ms'] ?? '1000'), $common),
            'eval' => AgentBrowser::eval((string)($a['js'] ?? ''), $common),
            'shot' => AgentBrowser::screenshot(null, $common),
            'get' => AgentBrowser::get((string)($a['what'] ?? 'text'), (string)($a['sel'] ?? ''), '', $common),
            'find' => AgentBrowser::find((string)($a['locator'] ?? 'role'), (string)($a['value'] ?? ''), (string)($a['act'] ?? 'click'), (string)($a['text'] ?? ''), $common),
            default => ['ok' => false, 'code' => 422, 'text' => 'Unknown action: ' . $do, 'ms' => 0, 'command' => '', 'json' => null, 'error' => 'Unknown action'],
        };
    }

    public static function describeAction(array $a): string
    {
        $do = strtolower(trim((string)($a['do'] ?? $a['action'] ?? '?')));
        $sel = (string)($a['sel'] ?? $a['target'] ?? $a['text'] ?? $a['key'] ?? '');
        return trim($do . ' ' . $sel);
    }

    /** TinyLLM report with deterministic fallback (never blocks the job). */
    private static function summarise(string $plan, string $url, string $goal, string $read, string $snap, string $jsJson, array $steps): string
    {
        $fails = count(array_filter($steps, fn($s) => empty($s['ok'])));
        $context = "URL: {$url}\nPlan: {$plan}\nGoal: {$goal}\nFailed steps: {$fails}\n\n"
            . "## Rendered text (truncated)\n" . mb_substr($read, 0, 4000) . "\n\n"
            . "## Snapshot (truncated)\n" . mb_substr($snap, 0, 3000) . "\n\n"
            . "## JS extraction\n" . mb_substr($jsJson, 0, 2000);
        try {
            if (class_exists('TinyLLM') && !TinyLLM::isLocal()) {
                $res = TinyLLM::chat(
                    [['role' => 'user', 'content' => $context]],
                    ['system' => 'You are SCITBD BrowserAgent. Write a short structured intel report: Title, What it is, Key facts (bullets), Suggested CRM next step, Confidence. Keep under 220 words. No invented stats.', 'num_predict' => 420, 'temperature' => 0.4]
                );
                if (!empty($res['content']) && empty($res['fallback'])) {
                    return $res['content'] . "\n\n_Source: agent-browser rendered DOM._";
                }
            }
        } catch (Throwable $e) {
            /* fall through to template */
        }
        // Deterministic template fallback.
        $title = '-';
        $desc = '-';
        try {
            $k = self::deepJson($jsJson);
            if (is_array($k)) {
                $title = (string)($k['title'] ?? '-');
                $desc = (string)($k['desc'] ?? '-');
            }
        } catch (Throwable $e) {
        }
        $firstLines = implode("\n", array_slice(array_values(array_filter(array_map('trim', preg_split('/\R/', $read) ?: []))), 0, 6));
        $okCount = count($steps) - $fails;
        return "**BrowserAgent {$plan} — {$url}**\n\n"
            . "- Title: {$title}\n- Meta: {$desc}\n"
            . "- Steps: {$okCount}/" . count($steps) . " ok" . ($fails ? " ({$fails} failed — see step log)" : '') . "\n"
            . "- Goal: {$goal}\n\n"
            . ($firstLines !== '' ? "**Lead text:**\n> " . str_replace("\n", "\n> ", mb_substr($firstLines, 0, 800)) . "\n\n" : '')
            . "**Suggested next step:** attach this report + screenshot to the CRM interaction log / CEO task and score the lead.\n\n"
            . '_Summarised offline by template engine (TinyLLM unreachable)._';
    }

    /** Decode JSON-of-JSON iteratively (CLI eval double-encodes). Returns array or null. */
    private static function deepJson(string $t): ?array
    {
        foreach ([trim($t), trim(trim($t), '"\'')] as $cand) {
            $cur = $cand;
            for ($i = 0; $i < 3; $i++) {
                $j = json_decode($cur, true);
                if (is_array($j)) {
                    return $j;
                }
                if (is_string($j) && $j !== $cur) {
                    $cur = $j;
                    continue;
                }
                break;
            }
        }
        return null;
    }

    private static function finish(bool $ok, string $plan, string $url, array $steps, ?string $shot, string $report, float $t0): array
    {
        return [
            'ok' => $ok, 'plan' => $plan, 'url' => $url, 'steps' => $steps,
            'shot' => $shot, 'report' => $report,
            'ms' => (int)round((microtime(true) - $t0) * 1000),
        ];
    }
}
