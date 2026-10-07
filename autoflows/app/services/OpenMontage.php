<?php
/**
 * OpenMontage — canonical wrapper for agentic video production.
 *
 * Single source of truth for CEO + SCCRM + Trace + AutoFlows (video driver).
 * Companion to autoflows/app/services/ToolJet.php (apps),
 * autoflows/app/services/AgentMemory.php (memory).
 * Shims: sccrm/services/OpenMontageService.php, ceo/openmontage.py (Python).
 *
 * Upstream (vendored): external/openmontage
 *   (https://github.com/calesthio/OpenMontage.git — AGPL-3.0)
 * Agent contract: AGENT_GUIDE.md → PROJECT_CONTEXT.md → pipeline_defs/*.yaml
 *   manifests → skills/pipelines/* director skills → tools/* registry.
 * Flow per pipeline: research → proposal → script → scene_plan → assets →
 *   edit → compose (human approval gates between creative stages).
 *
 * Model here: SCITBD agents own orchestration (briefs, approvals, budget);
 * OpenMontage provides pipelines, skills, tools. This wrapper handles
 * discovery (pipelines), intake (production requests → data/video-projects/),
 * status (project checkpoints + cost), and audit (openmontage_runs).
 * Heavy renders run in the vendored checkout (needs ffmpeg + python + node,
 * optional provider keys in external/openmontage/.env per .env.example).
 *
 * Design: no composer deps, PHP 8.1+. Never throws — returns
 * ['ok'=>false,'error'=>…].
 */
declare(strict_types=1);

final class OpenMontage
{
    public const VENDOR = 'external/openmontage';
    public const REPO = 'https://github.com/calesthio/OpenMontage.git';

    public const PIPELINES = [
        'animated-explainer' => 'AI explainer with research, narration, visuals, music',
        'animation' => 'Motion graphics, kinetic typography, animated sequences',
        'avatar-spokesperson' => 'Avatar-driven presenter videos',
        'cinematic' => 'Trailers, teasers, mood-driven edits',
        'clip-factory' => 'Ranked short-form clips from one long source',
        'documentary-montage' => 'Real-footage montage from free/open archives',
        'hybrid' => 'Source footage + AI-generated support visuals',
        'localization-dub' => 'Subtitle, dub and translate existing video',
        'podcast-repurpose' => 'Podcast highlights to video',
        'screen-demo' => 'Software walkthroughs and tutorials',
        'talking-head' => 'Footage-led speaker videos',
        'character-animation' => 'SVG/GSAP rigged character acting',
        'framework-smoke' => 'Minimal 2-stage smoke test (test only)',
    ];

    /**
     * Default models per capability (informational + intake defaults).
     * Names follow external/openmontage AGENT_GUIDE.md Layer-3 guidance and
     * each tool's install_instructions field. Keys live in external/openmontage/.env
     * (never in PHP config) — see .env.example.
     */
    public const DEFAULT_MODELS = [
        'video_generation' => ['provider' => 'fal/seedance', 'model' => 'seedance-2-0', 'key' => 'FAL_KEY', 'note' => 'preferred premium default; local alt wan2.2-ti2v-5b needs GPU (VIDEO_GEN_LOCAL_ENABLED)'],
        'image_generation' => ['provider' => 'fal/flux', 'model' => 'flux-pro', 'key' => 'FAL_KEY', 'note' => 'alts: qwen-image-2-0-pro (DASHSCOPE_API_KEY), grok (XAI_API_KEY)'],
        'tts' => ['provider' => 'elevenlabs', 'model' => 'eleven-multilingual-v2', 'key' => 'ELEVENLABS_API_KEY', 'note' => 'alts: openai tts (OPENAI_API_KEY), doubao (DOUBAO_SPEECH_API_KEY), fish.audio (FISH_AUDIO_API_KEY), piper local (no key)'],
        'stt' => ['provider' => 'whisper-local', 'model' => 'faster-whisper', 'key' => '', 'note' => 'offline default; cloud alt azure (AZURE_SPEECH_KEY)'],
        'music_generation' => ['provider' => 'suno', 'model' => 'suno-v4', 'key' => 'SUNO_API_KEY', 'note' => 'or drop tracks in music_library/'],
        'avatar' => ['provider' => 'heygen', 'model' => 'avatar-v', 'key' => 'HEYGEN_API_KEY', 'note' => 'alt: kling (KLING_API_KEY)'],
        '3d_asset_generation' => ['provider' => 'atlas_3d', 'model' => 'atlas-3d', 'key' => '', 'note' => 'text heroes; image-conditioned via fal_3d (FAL_KEY)'],
    ];

    // ------------------------------------------------------------ config ---

    /** openmontage config: autoflows config > env > defaults. */
    public static function cfg(): array
    {
        $cfg = [
            'projects_dir' => (string)(getenv('OPENMONTAGE_PROJECTS') ?: ''),
            'budget_cap_usd' => (float)(getenv('OPENMONTAGE_BUDGET_CAP') ?: 10),
            'approval_mode' => (string)(getenv('OPENMONTAGE_APPROVAL') ?: 'gated'),
        ];
        try {
            if (function_exists('config')) {
                foreach (['projects_dir', 'budget_cap_usd', 'approval_mode'] as $k) {
                    $v = config('openmontage.' . $k, null);
                    if ($v !== null && $v !== '') {
                        $cfg[$k] = $k === 'budget_cap_usd' ? (float)$v : (string)$v;
                    }
                }
            }
        } catch (Throwable $e) {
        }
        return $cfg;
    }

    /** Recommended default models per capability (+ the key each needs). */
    public static function models(): array
    {
        return self::DEFAULT_MODELS;
    }

    /** Run a command inside the vendored checkout with UTF-8-safe env.
     *  Windows cmd mangles single-quoted args (strips inner double quotes),
     *  so the snippet goes through a temp file and paths use double quotes.
     *  PYTHONUTF8=1 is REQUIRED: vendor output contains emoji/CJK that
     *  crash under the default cp1252 codepage. Never throws. */
    private static function vendorPython(string $code, int $timeout = 90): array
    {
        $isWin = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
        $py = 'python';
        if ($isWin) {
            $probe = trim((string)@shell_exec('where py 2>nul'));
            $first = explode("\n", $probe)[0] ?? '';
            $py = trim($first) !== '' ? 'py' : 'python';
        }
        $tmp = tempnam(sys_get_temp_dir(), 'om_pre_');
        if ($tmp === false) {
            return ['ok' => false, 'raw' => 'no temp file'];
        }
        $tmpPy = $tmp . '.py';
        @rename($tmp, $tmpPy);
        @file_put_contents($tmpPy, $code);
        $vendor = self::vendorRoot();
        if ($isWin) {
            // Script-file mode puts the TEMP dir on sys.path, not cwd —
            // anchor the vendor root explicitly.
            $cmd = 'cd /d "' . $vendor . '" && set PYTHONUTF8=1&& set PYTHONIOENCODING=utf-8&& '
                . 'set PYTHONPATH=' . $vendor . '&& '
                . $py . ' "' . $tmpPy . '" 2>&1';
        } else {
            $cmd = 'cd ' . escapeshellarg($vendor) . ' && PYTHONUTF8=1 PYTHONIOENCODING=utf-8 '
                . 'PYTHONPATH=' . escapeshellarg($vendor) . ' '
                . escapeshellarg($py) . ' ' . escapeshellarg($tmpPy) . ' 2>&1';
        }
        $out = trim((string)@shell_exec($cmd));
        @unlink($tmpPy);
        if (mb_strlen($out) > 256000) {
            $out = mb_substr($out, 0, 256000);
        }
        return ['ok' => $out !== '', 'raw' => $out];
    }

    /** Trace root = ancestor holding skills_index.php (works from any entry point). */
    public static function traceRoot(): string
    {
        $p = __DIR__;
        for ($i = 0; $i < 6; $i++) {
            if (is_file($p . '/skills_index.php')) {
                return $p;
            }
            $up = dirname($p);
            if ($up === $p) {
                break;
            }
            $p = $up;
        }
        // Fallback: relative layout autoflows/app/services → trace/.
        return dirname(__DIR__, 3);
    }

    public static function vendorRoot(): string
    {
        return self::traceRoot() . '/' . self::VENDOR;
    }

    public static function projectsDir(): string
    {
        $d = self::traceRoot() . '/data/video-projects';
        if (!is_dir($d)) {
            @mkdir($d, 0775, true);
        }
        if (is_dir($d)) {
            return $d;
        }
        $d = sys_get_temp_dir() . '/scitbd-video-projects';
        if (!is_dir($d)) {
            @mkdir($d, 0775, true);
        }
        return $d;
    }

    /** Probe a binary (ffmpeg/python/node). Never throws. */
    private static function probe(string $bin, string $args = '--version'): array
    {
        $isWin = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
        $q = $isWin ? "where {$bin} 2>nul" : "command -v {$bin} 2>/dev/null";
        $found = trim((string)@shell_exec($q));
        if ($found === '') {
            return ['ok' => false, 'version' => 'missing'];
        }
        $ver = trim((string)@shell_exec(escapeshellarg(explode("\n", $found)[0]) . " {$args} 2>&1"));
        $first = explode("\n", $ver)[0] ?? '';
        return ['ok' => true, 'version' => mb_substr(trim($first), 0, 120)];
    }

    public static function status(array $over = []): array
    {
        $vendor = self::vendorRoot();
        $ff = self::probe('ffmpeg', '-version');
        $py = self::probe('python', '--version') + ['alt' => null];
        if (empty($py['ok'])) {
            $py = self::probe('py', '--version');
        }
        $node = self::probe('node', '--version');
        $cfg = self::cfg();
        $ready = is_dir($vendor . '/pipeline_defs') && !empty($ff['ok']) && !empty($py['ok']);
        return [
            'ok' => $ready,
            'driver' => 'openmontage',
            'vendor' => self::VENDOR,
            'repo' => self::REPO,
            'ffmpeg' => $ff['version'] ?? 'missing',
            'python' => $py['version'] ?? 'missing',
            'node' => $node['version'] ?? 'missing',
            'pipelines' => count(self::listPipelines()),
            'projects_dir' => self::projectsDir(),
            'budget_cap_usd' => $cfg['budget_cap_usd'],
            'approval_mode' => $cfg['approval_mode'],
            'has_env' => is_file($vendor . '/.env'),
            'default_models' => count(self::DEFAULT_MODELS),
            'hint' => $ready
                ? 'Ready. requestProduction() scaffolds a brief; the agent runs the pipeline in external/openmontage.'
                : 'Need: vendored checkout + ffmpeg + python3.10+ (+ node18 for Remotion). Optional provider keys in external/openmontage/.env.',
        ];
    }

    /**
     * Mandatory preflight per AGENT_GUIDE.md: capability menu, setup offers
     * (the exact keys/codes each unavailable provider needs) and runtime
     * warnings, straight from the vendor tool registry. The registry probe
     * is SLOW (vendor imports), so results cache to data/.cache (TTL 10 min);
     * pass $refresh=true (?refresh_preflight=1) to force. Never throws.
     */
    public static function preflight(bool $refresh = false, int $ttl = 600): array
    {
        $cache = self::traceRoot() . '/data/.cache/om_preflight.json';
        if (!$refresh && is_file($cache) && (time() - (int)@filemtime($cache)) < $ttl) {
            $hit = json_decode((string)@file_get_contents($cache), true);
            if (is_array($hit) && !empty($hit['ok'])) {
                $hit['cached'] = true;
                $hit['cache_age_s'] = time() - (int)@filemtime($cache);
                return $hit;
            }
        }
        $code = 'from tools.tool_registry import registry;'
            . ' import json; registry.discover();'
            . ' print(json.dumps(registry.provider_menu_summary(), separators=(",", ":")))';
        $r = self::vendorPython($code, 240);
        if (empty($r['ok'])) {
            return ['ok' => false, 'cached' => false, 'error' => 'registry probe produced no output (python missing?)'];
        }
        $menu = json_decode($r['raw'], true);
        if (!is_array($menu)) {
            return ['ok' => false, 'cached' => false, 'error' => 'registry probe not JSON: ' . mb_substr($r['raw'], 0, 300)];
        }
        $caps = [];
        foreach (($menu['capabilities'] ?? []) as $c) {
            $caps[] = [
                'capability' => $c['capability'] ?? '?',
                'configured' => ($c['configured'] ?? 0) . '/' . ($c['total'] ?? 0),
                'providers' => $c['available_providers'] ?? [],
                'needs' => $c['unavailable_providers'] ?? [],
            ];
        }
        $out = [
            'ok' => true,
            'cached' => false,
            'cache_age_s' => 0,
            'composition_runtimes' => $menu['composition_runtimes'] ?? [],
            'capabilities' => $caps,
            'setup_offers' => $menu['setup_offers'] ?? [],
            'runtime_warnings' => $menu['runtime_warnings'] ?? [],
        ];
        @mkdir(dirname($cache), 0775, true);
        @file_put_contents($cache, json_encode($out));
        return $out;
    }

    // ---------------------------------------------------------- pipelines ---

    /** Pipelines from vendored manifests (id → description). */
    public static function listPipelines(): array
    {
        $out = [];
        $dir = self::vendorRoot() . '/pipeline_defs';
        if (!is_dir($dir)) {
            return self::PIPELINES;
        }
        foreach (glob($dir . '/*.yaml') ?: [] as $f) {
            $id = basename($f, '.yaml');
            $out[$id] = self::PIPELINES[$id] ?? $id;
        }
        return $out;
    }

    // --------------------------------------------------------- productions ---

    public static function slug(string $title): string
    {
        $s = mb_strtolower(trim($title));
        $s = (string)preg_replace('/[^a-z0-9]+/', '-', $s);
        return trim($s, '-') ?: ('production-' . date('Ymd-His'));
    }

    /**
     * Intake: scaffold data/video-projects/<slug>/brief.md (+ models.json) + audit row.
     * $opts: ['models' => [capability => model], 'budget_usd' => float].
     * budget_usd above the configured cap is rejected. Slugs are
     * collision-safe (title reuse appends -2, -3…). Never renders here —
     * the agent then runs the pipeline in external/openmontage per AGENT_GUIDE.md.
     */
    public static function requestProduction(string $title, string $brief, string $pipeline = 'animated-explainer', string $module = 'ceo', array $opts = []): array
    {
        $pipes = self::listPipelines();
        if (!isset($pipes[$pipeline])) {
            return ['ok' => false, 'error' => 'Unknown pipeline. See listPipelines(): ' . implode(', ', array_keys($pipes))];
        }
        $cfg = self::cfg();
        $budget = isset($opts['budget_usd']) ? (float)$opts['budget_usd'] : $cfg['budget_cap_usd'];
        if ($budget > $cfg['budget_cap_usd']) {
            return ['ok' => false, 'error' => 'budget_usd ' . $budget . ' exceeds cap ' . $cfg['budget_cap_usd'] . ' (OPENMONTAGE_BUDGET_CAP)'];
        }
        $models = [];
        foreach (self::DEFAULT_MODELS as $cap => $def) {
            $models[$cap] = isset($opts['models'][$cap]) && is_string($opts['models'][$cap]) && $opts['models'][$cap] !== ''
                ? $opts['models'][$cap]
                : ($def['provider'] . '/' . $def['model']);
        }
        $base = self::slug($title);
        $slug = $base;
        $dir0 = self::projectsDir();
        for ($i = 2; $i < 1000 && (is_dir($dir0 . '/' . $slug) || is_file($dir0 . '/' . $slug . '.md')); $i++) {
            $slug = $base . '-' . $i;
        }
        $dir = $dir0 . '/' . $slug;
        if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
            return ['ok' => false, 'error' => 'Cannot create ' . $dir];
        }
        $modelLines = '';
        foreach ($models as $cap => $m) {
            $modelLines .= "- {$cap}: {$m}\n";
        }
        $doc = "# {$title}\n\n- Pipeline: {$pipeline}\n- Module: {$module}\n- Requested: " . date('Y-m-d H:i:s')
            . "\n- Budget USD: {$budget} (cap {$cfg['budget_cap_usd']}, approval {$cfg['approval_mode']})\n\n## Brief\n\n{$brief}\n\n## Models\n\n{$modelLines}\n## Runbook\n\n"
            . "1. cd external/openmontage (read AGENT_GUIDE.md + PROJECT_CONTEXT.md)\n"
            . "2. Run preflight: provider menu → capability audit → pipeline selection\n"
            . "3. Follow pipeline_defs/{$pipeline}.yaml stages with director skills\n"
            . "4. Keep checkpoints + decision log + cost snapshot under this folder\n"
            . "5. Human gates: proposal, script, scene plan, assets, publish\n";
        @file_put_contents($dir . '/brief.md', $doc);
        @file_put_contents($dir . '/models.json', json_encode(['pipeline' => $pipeline, 'budget_usd' => $budget, 'models' => $models], JSON_PRETTY_PRINT));
        try {
            $db = self::db();
            if ($db) {
                self::ensureTables($db);
                $st = $db->prepare("INSERT INTO openmontage_runs (module, pipeline, slug, title, status) VALUES (?,?,?,?,?)");
                $st->execute([$module, $pipeline, $slug, mb_substr($title, 0, 200), 'requested']);
            }
        } catch (Throwable $e) {
        }
        return ['ok' => true, 'slug' => $slug, 'pipeline' => $pipeline, 'dir' => $dir, 'budget_usd' => $budget, 'models' => $models, 'next' => 'Agent runs pipeline_defs/' . $pipeline . '.yaml in external/openmontage'];
    }

    /** Productions = intake rows merged with on-disk project state. */
    public static function listProductions(int $limit = 30): array
    {
        $rows = [];
        try {
            $db = self::db();
            if ($db) {
                self::ensureTables($db);
                $rows = $db->query("SELECT * FROM openmontage_runs ORDER BY id DESC LIMIT {$limit}")->fetchAll(PDO::FETCH_ASSOC);
            }
        } catch (Throwable $e) {
        }
        foreach ($rows as &$r) {
            $d = self::projectsDir() . '/' . ($r['slug'] ?? '');
            $r['has_brief'] = is_file($d . '/brief.md');
            $r['has_final'] = is_file($d . '/renders/final.mp4') || is_file($d . '/final.mp4');
        }
        return $rows;
    }

    /** Slug allow-list: lowercase slugs we created (no traversal). */
    public static function validSlug(string $slug): bool
    {
        return (bool)preg_match('/^[a-z0-9][a-z0-9-]{0,120}$/', $slug);
    }

    /** All known audit DB files (intake may double-log to module DBs). */
    public static function dbFiles(): array
    {
        $cands = [
            defined('BASE_PATH') ? BASE_PATH . '/storage/app.db' : null,
            dirname(__DIR__, 2) . '/storage/app.db',
            dirname(__DIR__, 3) . '/data/osint.db',
            dirname(__DIR__, 3) . '/sccrm/db/scit_crm.db',
            dirname(__DIR__, 3) . '/ceo/scitbd_ceo.db',
        ];
        $out = [];
        foreach ($cands as $p) {
            if ($p && is_file($p) && !in_array($p, $out, true)) {
                $out[] = $p;
            }
        }
        return $out;
    }

    /** Detail: audit row + brief + models + on-disk files. Null when unknown. */
    public static function getProduction(string $slug): ?array
    {
        if (!self::validSlug($slug)) {
            return null;
        }
        $row = null;
        foreach (self::dbFiles() as $p) {
            try {
                $db = new PDO('sqlite:' . $p);
                self::ensureTables($db);
                $st = $db->prepare('SELECT * FROM openmontage_runs WHERE slug = ? ORDER BY id DESC LIMIT 1');
                $st->execute([$slug]);
                $hit = $st->fetch(PDO::FETCH_ASSOC);
                if ($hit && $row === null) {
                    $row = $hit;
                }
            } catch (Throwable $e) {
            }
        }
        if ($row === null) {
            return null;
        }
        $d = self::projectsDir() . '/' . $slug;
        $files = [];
        if (is_dir($d)) {
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($d, FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                if ($f->isFile()) {
                    $files[] = substr($f->getPathname(), strlen($d) + 1);
                }
            }
            sort($files);
        }
        $row['dir'] = $d;
        $row['has_brief'] = is_file($d . '/brief.md');
        $row['brief'] = is_file($d . '/brief.md') ? (string)@file_get_contents($d . '/brief.md') : '';
        $row['models'] = is_file($d . '/models.json') ? (json_decode((string)@file_get_contents($d . '/models.json'), true) ?: []) : [];
        $row['files'] = array_slice($files, 0, 100);
        $row['has_final'] = is_file($d . '/renders/final.mp4') || is_file($d . '/final.mp4');
        return $row;
    }

    /**
     * Edit: title/brief/pipeline/status/budget_usd/models. Updates every
     * audit DB holding the slug, rewrites brief.md + models.json sidecar.
     */
    public static function updateProduction(string $slug, array $fields): array
    {
        if (!self::validSlug($slug) || self::getProduction($slug) === null) {
            return ['ok' => false, 'error' => 'Unknown production: ' . $slug];
        }
        $allowedStatus = ['requested', 'approved', 'in_progress', 'blocked', 'completed', 'cancelled'];
        $sets = [];
        $vals = [];
        if (isset($fields['title']) && trim((string)$fields['title']) !== '') {
            $sets[] = 'title = ?';
            $vals[] = mb_substr(trim((string)$fields['title']), 0, 200);
        }
        if (isset($fields['pipeline']) && isset(self::listPipelines()[(string)$fields['pipeline']])) {
            $sets[] = 'pipeline = ?';
            $vals[] = (string)$fields['pipeline'];
        }
        if (isset($fields['status']) && in_array((string)$fields['status'], $allowedStatus, true)) {
            $sets[] = 'status = ?';
            $vals[] = (string)$fields['status'];
        }
        if (isset($fields['budget_usd']) && is_numeric($fields['budget_usd'])) {
            $cfg = self::cfg();
            if ((float)$fields['budget_usd'] > $cfg['budget_cap_usd']) {
                return ['ok' => false, 'error' => 'budget_usd exceeds cap ' . $cfg['budget_cap_usd']];
            }
        }
        $n = 0;
        if ($sets) {
            foreach (self::dbFiles() as $p) {
                try {
                    $db = new PDO('sqlite:' . $p);
                    self::ensureTables($db);
                    $st = $db->prepare('UPDATE openmontage_runs SET ' . implode(', ', $sets) . ' WHERE slug = ?');
                    $st->execute([...$vals, $slug]);
                    $n += $st->rowCount();
                } catch (Throwable $e) {
                }
            }
        }
        // Refresh files from (possibly updated) row.
        $row = self::getProduction($slug);
        $d = self::projectsDir() . '/' . $slug;
        $brief = isset($fields['brief']) ? (string)$fields['brief'] : (string)($row['brief'] ?? '');
        $models = is_array($row['models'] ?? null) && isset($row['models']['models']) ? $row['models']['models'] : [];
        if (isset($fields['models']) && is_array($fields['models'])) {
            foreach (self::DEFAULT_MODELS as $cap => $def) {
                if (isset($fields['models'][$cap]) && is_string($fields['models'][$cap]) && $fields['models'][$cap] !== '') {
                    $models[$cap] = $fields['models'][$cap];
                }
            }
        }
        $budget = isset($fields['budget_usd']) && is_numeric($fields['budget_usd'])
            ? (float)$fields['budget_usd']
            : (float)($row['models']['budget_usd'] ?? self::cfg()['budget_cap_usd']);
        $modelLines = '';
        foreach ($models as $cap => $m) {
            $modelLines .= "- {$cap}: {$m}\n";
        }
        $doc = "# " . ($row['title'] ?? $slug) . "\n\n- Pipeline: " . ($row['pipeline'] ?? '') . "\n- Module: " . ($row['module'] ?? '') . "\n"
            . "- Updated: " . date('Y-m-d H:i:s') . "\n- Budget USD: {$budget} (cap " . self::cfg()['budget_cap_usd'] . ")\n- Status: " . ($row['status'] ?? '') . "\n\n## Brief\n\n{$brief}\n\n## Models\n\n{$modelLines}\n";
        if (is_dir($d)) {
            @file_put_contents($d . '/brief.md', $doc);
            @file_put_contents($d . '/models.json', json_encode(['pipeline' => $row['pipeline'] ?? '', 'budget_usd' => $budget, 'models' => $models], JSON_PRETTY_PRINT));
        }
        return ['ok' => true, 'slug' => $slug, 'rows_updated' => $n];
    }

    /** Delete: audit rows in every known DB + project dir (opt-out via $removeFiles=false). */
    public static function deleteProduction(string $slug, bool $removeFiles = true): array
    {
        if (!self::validSlug($slug)) {
            return ['ok' => false, 'error' => 'Invalid slug'];
        }
        if (self::getProduction($slug) === null) {
            return ['ok' => false, 'error' => 'Unknown production: ' . $slug];
        }
        $n = 0;
        foreach (self::dbFiles() as $p) {
            try {
                $db = new PDO('sqlite:' . $p);
                self::ensureTables($db);
                $st = $db->prepare('DELETE FROM openmontage_runs WHERE slug = ?');
                $st->execute([$slug]);
                $n += $st->rowCount();
            } catch (Throwable $e) {
            }
        }
        $d = self::projectsDir() . '/' . $slug;
        $filesGone = true;
        if ($removeFiles && is_dir($d)) {
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($d, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($it as $f) {
                $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
            }
            $filesGone = @rmdir($d);
        }
        return ['ok' => true, 'slug' => $slug, 'rows_deleted' => $n, 'files_removed' => $filesGone];
    }

    // -------------------------------------------------------------- db ---

    public const SCHEMA = <<<SQL
CREATE TABLE IF NOT EXISTS openmontage_runs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    module TEXT NOT NULL DEFAULT 'ceo',
    pipeline TEXT NOT NULL DEFAULT 'animated-explainer',
    slug TEXT NOT NULL DEFAULT '',
    title TEXT NOT NULL DEFAULT '',
    status TEXT NOT NULL DEFAULT 'requested',
    cost_usd REAL DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);
SQL;

    public static function ensureTables(PDO $db): void
    {
        foreach (explode(';', self::SCHEMA) as $stmt) {
            $stmt = trim($stmt);
            if ($stmt !== '') {
                $db->exec($stmt);
            }
        }
    }

    private static function db(): ?PDO
    {
        $cands = [
            defined('BASE_PATH') ? BASE_PATH . '/storage/app.db' : null,
            dirname(__DIR__, 2) . '/storage/app.db',
            dirname(__DIR__, 3) . '/data/osint.db',
            dirname(__DIR__, 3) . '/sccrm/db/scit_crm.db',
            dirname(__DIR__, 3) . '/ceo/scitbd_ceo.db',
        ];
        foreach ($cands as $p) {
            if ($p && is_file($p)) {
                try {
                    $db = new PDO('sqlite:' . $p);
                    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                    return $db;
                } catch (Throwable $e) {
                    continue;
                }
            }
        }
        return null;
    }

    public static function logRun(PDO $db, string $module, string $pipeline, string $slug, string $status): void
    {
        try {
            self::ensureTables($db);
            $st = $db->prepare('INSERT INTO openmontage_runs (module, pipeline, slug, status) VALUES (?,?,?,?)');
            $st->execute([$module, $pipeline, $slug, $status]);
        } catch (Throwable $e) {
        }
    }
}
