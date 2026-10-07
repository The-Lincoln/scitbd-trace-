<?php
/**
 * OSINT AgentBrowserTracer — rendered-DOM mode for JS-heavy targets.
 *
 * The classic URLTracer (Guzzle, static HTML) misses SPA content.
 * This class drives the real Chromium via agent-browser CLI:
 *   open → read(rendered) → snapshot(refs) → eval(structured) →
 *   screenshot(evidence) → console/errors → merge with static trace.
 *
 * Usage:
 *   $t = new AgentBrowserTracer($pdo);
 *   $res = $t->traceRendered('https://spa.example.com', ['screenshot'=>true]);
 *
 * Result shape: ['mode'=>'rendered','static'=>[…], 'rendered'=>[…],
 *   'merged'=>[title,tech,links,…], 'shot'=>path|null, 'ms'=>int]
 */
namespace OSINT;

class AgentBrowserTracer
{
    private $pdo;
    private string $module = 'trace';

    public function __construct($pdo = null, string $module = 'trace')
    {
        $this->pdo = $pdo;
        $this->module = $module;
        $this->ensureDeps();
    }

    private function ensureDeps(): void
    {
        if (!class_exists('AgentBrowser')) {
            $canon = dirname(__DIR__) . '/autoflows/app/services/AgentBrowser.php';
            if (is_file($canon)) {
                require_once $canon;
            }
        }
        if (!class_exists('BrowserAgent')) {
            $ba = dirname(__DIR__) . '/autoflows/app/services/BrowserAgent.php';
            if (is_file($ba)) {
                require_once $ba;
            }
        }
    }

    public static function isAvailable(): bool
    {
        if (!class_exists('AgentBrowser')) {
            $canon = dirname(__DIR__) . '/autoflows/app/services/AgentBrowser.php';
            if (is_file($canon)) {
                require_once $canon;
            } else {
                return false;
            }
        }
        try {
            return \AgentBrowser::isAvailable();
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Full rendered trace. Set $withStatic=false to skip the Guzzle pass
     * (faster, but no headers/SSL volumetric data).
     */
    public function traceRendered(string $url, array $opts = []): array
    {
        $t0 = microtime(true);
        $url = $this->normalise($url);
        $withStatic = ($opts['static'] ?? true) === true;
        $wantShot = ($opts['screenshot'] ?? true) === true;

        $static = null;
        if ($withStatic && class_exists('OSINT\\URLTracer')) {
            try {
                $static = (new URLTracer($this->pdo))->trace($url);
            } catch (\Throwable $e) {
                $static = ['error' => ['message' => $e->getMessage()]];
            }
        }

        if (!self::isAvailable()) {
            return [
                'mode' => 'rendered', 'url' => $url, 'ok' => false,
                'error' => 'agent-browser CLI not installed (npm i -g agent-browser && agent-browser install)',
                'static' => $static, 'rendered' => null, 'merged' => $this->merge($static, null),
                'shot' => null, 'ms' => (int)round((microtime(true) - $t0) * 1000),
            ];
        }

        $common = ['module' => $this->module, 'timeout' => 60];
        $steps = [];

        $rOpen = \AgentBrowser::open($url, $common + ['timeout' => 75]);
        $steps['open'] = $this->step($rOpen);
        $rRead = \AgentBrowser::read(null, $common);
        $steps['read'] = $this->step($rRead);
        $rSnap = \AgentBrowser::snapshot($common);
        $steps['snapshot'] = $this->step($rSnap);
        $rEval = \AgentBrowser::eval(
            '(function(){try{var o={title:document.title,desc:(document.querySelector(\'meta[name="description"]\')||{getAttribute:()=>""}).getAttribute("content")||"",h1:[...document.querySelectorAll("h1")].map(e=>e.innerText.slice(0,200)),forms:[...document.querySelectorAll("form")].length,scripts:[...document.querySelectorAll("script[src]")].map(s=>s.src).slice(0,15),tech:{react:!!document.querySelector("[data-reactroot],#root,#__next"),vue:!!window.Vue,next:!!window.__NEXT_DATA__,nuxt:!!window.__NUXT__},links:[...document.querySelectorAll("a[href]")].map(a=>a.href).filter((v,i,s)=>v&&s.indexOf(v)===i).slice(0,30)};return JSON.stringify(o)}catch(e){return JSON.stringify({error:String(e)})}})()',
            $common
        );
        $steps['eval'] = $this->step($rEval);
        $rConsole = \AgentBrowser::exec(['console'], ['session' => \AgentBrowser::sessionFor($this->module), 'timeout' => 30]);
        $steps['console'] = ['ok' => (bool)$rConsole['ok'], 'text' => mb_substr(trim($rConsole['stdout'] . $rConsole['stderr']), 0, 2000)];

        $shot = null;
        if ($wantShot) {
            $rShot = \AgentBrowser::screenshot(null, $common);
            $steps['shot'] = ['ok' => (bool)$rShot['ok'], 'text' => (string)($rShot['path'] ?? '')];
            if (!empty($rShot['path']) && !empty($rShot['exists'])) {
                $shot = $rShot['path'];
            }
        }

        $rendered = [
            'read' => $rRead['ok'] ? $rRead['text'] : null,
            'read_error' => $rRead['ok'] ? null : ($rRead['error'] ?? '?'),
            'snapshot' => $rSnap['ok'] ? $rSnap['text'] : null,
            'refs' => $rSnap['refs'] ?? [],
            'structured' => $this->safeJson($rEval['text'] ?? ''),
            'console' => $steps['console']['text'] ?? '',
            'steps' => $steps,
        ];

        $merged = $this->merge($static, $rendered);
        $ok = (bool)$rOpen['ok'];
        $ms = (int)round((microtime(true) - $t0) * 1000);

        // Persist rendered note into url_traces when the table exists.
        $this->persistNote($url, $merged, $shot, $ms, $ok);

        // Chain into SCCRM automations (trace_completed) like the static path.
        $this->fireAutoflow($url, $merged);

        return [
            'mode' => 'rendered', 'url' => $url, 'ok' => $ok,
            'static' => $static, 'rendered' => $rendered, 'merged' => $merged,
            'shot' => $shot, 'ms' => $ms,
        ];
    }

    /** Hybrid: static 11-phase trace + rendered overlay (recommended default). */
    public function traceHybrid(string $url, array $opts = []): array
    {
        return $this->traceRendered($url, $opts + ['static' => true]);
    }

    // ------------------------------------------------------------ helpers ---

    private function normalise(string $u): string
    {
        $u = trim($u);
        if (!preg_match('#^https?://#i', $u)) {
            $u = 'https://' . $u;
        }
        return $u;
    }

    private function step(array $r): array
    {
        return ['ok' => (bool)$r['ok'], 'text' => mb_substr($r['text'] ?? '', 0, 2000), 'ms' => (int)($r['ms'] ?? 0)];
    }

    private function safeJson(string $t): array
    {
        // CLI eval returns JSON-of-JSON: "\"{\\\"title\\\":…}\"".
        // Decode iteratively (up to 3 levels), then fall back to salvage.
        $candidates = [trim($t), trim(trim($t), '"\'')];
        foreach ($candidates as $cand) {
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
        // salvage: first {...} block, decoded iteratively
        if (preg_match('/\{.*\}/s', $t, $m)) {
            $cur = $m[0];
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
        return ['_raw' => mb_substr($t, 0, 1500)];
    }

    /** Combine static + rendered into one intel card. */
    private function merge($static, ?array $rendered): array
    {
        $m = ['title' => '-', 'description' => '-', 'tech_extra' => [], 'links_extra' => [], 'spa_signals' => []];
        try {
            if (is_array($static)) {
                $m['title'] = (string)($static['content']['title'] ?? '-');
                $m['description'] = (string)($static['content']['meta_description'] ?? '-');
                $m['static_scores'] = [
                    'seo' => (int)($static['seo']['score'] ?? 0),
                    'security' => (int)($static['security']['score'] ?? 0),
                    'performance' => (int)($static['performance']['performance_score'] ?? 0),
                ];
            }
            if (is_array($rendered)) {
                $s = $rendered['structured'] ?? [];
                if (!empty($s['title'])) {
                    $m['title_rendered'] = (string)$s['title'];
                }
                if (!empty($s['desc'])) {
                    $m['description_rendered'] = (string)$s['desc'];
                }
                foreach (($s['tech'] ?? []) as $k => $v) {
                    if ($v) {
                        $m['spa_signals'][] = $k;
                    }
                }
                $m['links_extra'] = array_slice((array)($s['links'] ?? []), 0, 30);
                $m['scripts_rendered'] = array_slice((array)($s['scripts'] ?? []), 0, 15);
                $m['read_chars'] = mb_strlen((string)($rendered['read'] ?? ''));
                $m['ref_count'] = count((array)($rendered['refs'] ?? []));
            }
        } catch (\Throwable $e) {
        }
        return $m;
    }

    private function persistNote(string $url, array $merged, ?string $shot, int $ms, bool $ok): void
    {
        try {
            if (!$this->pdo instanceof \PDO) {
                return;
            }
            $cols = [];
            foreach ($this->pdo->query("PRAGMA table_info(url_traces)") as $c) {
                $cols[] = $c['name'];
            }
            if (!in_array('url', $cols, true)) {
                return;
            }
            // Only write when the table has a notes-ish column; else skip (never migrate OSINT schema here).
            $noteCol = null;
            foreach (['notes', 'rendered_note', 'extra'] as $cand) {
                if (in_array($cand, $cols, true)) {
                    $noteCol = $cand;
                    break;
                }
            }
            if ($noteCol === null) {
                return;
            }
            $note = 'rendered:' . ($ok ? 'ok' : 'fail') . " {$ms}ms title=" . mb_substr($merged['title_rendered'] ?? $merged['title'] ?? '-', 0, 120)
                . ' spa=[' . implode(',', $merged['spa_signals'] ?? []) . ']' . ($shot ? " shot={$shot}" : '');
            $st = $this->pdo->prepare("UPDATE url_traces SET {$noteCol}=? WHERE url=? ORDER BY id DESC LIMIT 1");
            // SQLite has no ORDER BY/LIMIT on UPDATE — fallback insert is skipped; keep silent.
            @$st->execute([$note, $url]);
        } catch (\Throwable $e) {
        }
    }

    private function fireAutoflow(string $url, array $merged): void
    {
        try {
            $dbFile = dirname(__DIR__) . '/sccrm/db/scit_crm.db';
            if (!is_file($dbFile)) {
                return;
            }
            $_db = new \PDO('sqlite:' . $dbFile);
            $_db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $eng = dirname(__DIR__) . '/sccrm/autoflows/autoflow_engine.php';
            if (is_file($eng)) {
                require_once $eng;
            }
            if (function_exists('autoflowTrigger')) {
                \autoflowTrigger($_db, 'trace_completed', [
                    'url' => $url,
                    'subject' => 'Rendered trace: ' . mb_substr($merged['title_rendered'] ?? $merged['title'] ?? $url, 0, 120),
                    'seo_score' => (int)($merged['static_scores']['seo'] ?? 0),
                    'security_score' => (int)($merged['static_scores']['security'] ?? 0),
                    'tech_stack' => implode(', ', array_slice(array_merge($merged['spa_signals'] ?? [], ['rendered-dom']), 0, 8)),
                    'source' => 'agent_browser_trace',
                ]);
            }
        } catch (\Throwable $e) {
        }
    }
}
