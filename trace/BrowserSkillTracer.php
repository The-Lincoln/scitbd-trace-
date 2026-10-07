<?php
/**
 * OSINT BrowserSkillTracer — Tencent `bsk` rendered-DOM mode for JS-heavy targets.
 *
 * Companion to trace/AgentBrowserTracer.php (vercel agent-browser driver).
 * Drives real Chromium via bsk CLI:
 *   session start → navigate → observe → screenshot → session stop
 *   (+ optional static Guzzle trace merge for headers/SSL volumetric data).
 *
 * Usage:
 *   $t = new BrowserSkillTracer($pdo);
 *   $res = $t->traceRendered('https://spa.example.com', ['screenshot'=>true]);
 *
 * Result shape: ['driver'=>'bsk','mode'=>'rendered','static'=>[…], 'rendered'=>[…],
 *   'merged'=>[title,tech,links,…], 'shot'=>path|null, 'ms'=>int]
 */
namespace OSINT;

class BrowserSkillTracer
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
        if (!class_exists('BrowserSkill')) {
            $canon = dirname(__DIR__) . '/autoflows/app/services/BrowserSkill.php';
            if (is_file($canon)) {
                require_once $canon;
            }
        }
    }

    public static function isAvailable(): bool
    {
        if (!class_exists('BrowserSkill')) {
            $canon = dirname(__DIR__) . '/autoflows/app/services/BrowserSkill.php';
            if (is_file($canon)) {
                require_once $canon;
            } else {
                return false;
            }
        }
        try {
            return \BrowserSkill::isAvailable();
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
                'driver' => 'bsk', 'mode' => 'rendered', 'url' => $url, 'ok' => false,
                'error' => 'bsk CLI not installed (irm https://raw.githubusercontent.com/Tencent/BrowserSkill/main/install.ps1 | iex ; then extension + bsk doctor)',
                'static' => $static, 'rendered' => null, 'merged' => $this->merge($static, null),
                'shot' => null, 'ms' => (int)round((microtime(true) - $t0) * 1000),
            ];
        }

        $common = ['module' => $this->module, 'timeout' => 60, 'no_focus' => true];
        $res = \BrowserSkill::research($url, $common + ['screenshot' => $wantShot]);
        $steps = [];
        foreach (($res['steps'] ?? []) as $k => $s) {
            $steps[$k] = ['ok' => (bool)($s['ok'] ?? false), 'text' => mb_substr((string)($s['text'] ?? ''), 0, 2000)];
        }
        $rendered = [
            'observe' => $res['observe'] ?? null,
            'refs' => $res['refs'] ?? [],
            'steps' => $steps,
            'session_id' => $res['session_id'] ?? null,
        ];
        $merged = $this->merge($static, $rendered);
        $ok = (bool)($res['ok'] ?? false);
        $ms = (int)round((microtime(true) - $t0) * 1000);

        $this->persistNote($url, $merged, $res['shot'] ?? null, $ms, $ok);
        $this->fireAutoflow($url, $merged);
        // Team memory: persist trace intel (never breaks the trace).
        try {
            if (is_file(__DIR__ . '/AgentMemoryHook.php')) {
                require_once __DIR__ . '/AgentMemoryHook.php';
            }
            if (class_exists('OSINT\\AgentMemoryHook')) {
                $mem = \OSINT\AgentMemoryHook::rememberAfterTrace($url, ['content' => ['title' => $merged['title_rendered'] ?? $merged['title'] ?? '-'], 'basic' => ['status_code' => $static['basic']['status_code'] ?? '?'], 'technology' => $static['technology'] ?? [], 'seo' => $static['seo'] ?? [], 'security' => $static['security'] ?? [], 'performance' => $static['performance'] ?? [], 'identity' => ['trace_id' => $merged['title'] ?? '']] + (is_array($static) ? $static : []), $this->pdo, $this->module);
                $merged['memory'] = ['stored' => $mem['stored'] ?? 'none', 'ok' => !empty($mem['ok'])];
            }
        } catch (\Throwable $e) {
        }

        return [
            'driver' => 'bsk', 'mode' => 'rendered', 'url' => $url, 'ok' => $ok,
            'static' => $static, 'rendered' => $rendered, 'merged' => $merged,
            'shot' => $res['shot'] ?? null, 'ms' => $ms,
        ];
    }

    /** Hybrid: static 11-phase trace + bsk rendered overlay (recommended default). */
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

    /** Combine static + bsk observe into one intel card. */
    private function merge($static, ?array $rendered): array
    {
        $m = ['title' => '-', 'description' => '-', 'tech_extra' => [], 'links_extra' => [], 'driver' => 'bsk'];
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
                $obs = (string)($rendered['observe'] ?? '');
                $lines = array_values(array_filter(array_map('trim', preg_split('/\R/', $obs) ?: [])));
                if (!empty($lines)) {
                    $m['title_rendered'] = mb_substr($lines[0], 0, 200);
                    $m['description_rendered'] = mb_substr(implode(' ', array_slice($lines, 1, 3)), 0, 300);
                }
                $m['read_chars'] = mb_strlen($obs);
                $m['ref_count'] = count((array)($rendered['refs'] ?? []));
                $m['refs'] = array_slice((array)($rendered['refs'] ?? []), 0, 30);
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
            $note = 'bsk-rendered:' . ($ok ? 'ok' : 'fail') . " {$ms}ms title=" . mb_substr($merged['title_rendered'] ?? $merged['title'] ?? '-', 0, 120) . ($shot ? " shot={$shot}" : '');
            $st = $this->pdo->prepare("UPDATE url_traces SET {$noteCol}=? WHERE url=?");
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
                    'subject' => 'BrowserSkill trace: ' . mb_substr($merged['title_rendered'] ?? $merged['title'] ?? $url, 0, 120),
                    'seo_score' => (int)($merged['static_scores']['seo'] ?? 0),
                    'security_score' => (int)($merged['static_scores']['security'] ?? 0),
                    'tech_stack' => implode(', ', array_slice(array_merge(['bsk-rendered'], $merged['refs'] ?? []), 0, 8)),
                    'source' => 'browserskill_trace',
                ]);
            }
        } catch (\Throwable $e) {
        }
    }
}
