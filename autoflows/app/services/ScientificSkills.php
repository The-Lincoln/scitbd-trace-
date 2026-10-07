<?php
/**
 * ScientificSkills — canonical catalog for scientific agent skills.
 *
 * Single source of truth for CEO + SCCRM + Trace + AutoFlows (science driver).
 * Companion to autoflows/app/services/OpenViking.php (context) and
 * autoflows/app/services/AgentMemory.php (team memory).
 * Shims: sccrm/services/ScientificSkillsService.php, ceo/scientific_skills.py.
 *
 * Upstream (vendored): external/scientific-agent-skills
 *   (https://github.com/The-Lincoln/scientific-agent-skills.git — MIT,
 *    fork of K-Dense-AI/scientific-agent-skills, v2.72.0, 177 skills)
 * Standard: open Agent Skills (SKILL.md + YAML frontmatter) + Agent Plugins
 *   1.0.0 package (plugin.json + skills/). Docs: docs/skills.md (catalog),
 *   docs/skill-guides/. Validation: scan_skills.py, tests/<skill>/.
 *
 * Model: file-based library, no daemon. This wrapper catalogs
 * skills/<name>/SKILL.md (frontmatter name/description/version), searches
 * them, and returns skill bodies for prompt injection. Agents read ONLY the
 * skills they need (upstream security guidance: never bulk-install).
 * Skill deps install per-skill via uv (see skill compatibility fields).
 *
 * SCITBD fast paths: database-lookup (80 sources), paper-lookup, exa-search,
 * scientific-writing, scientific-visualization, scikit-learn, statsmodels —
 * lead/market research, CEO report figures, evidence-traceable writing.
 *
 * Design: no composer deps, PHP 8.1+. Never throws — returns
 * ['ok'=>false,'error'=>…]. Results cached per request; catalog rescanned on
 * demand (177 small files, fast).
 */
declare(strict_types=1);

final class ScientificSkills
{
    public const VENDOR = 'external/scientific-agent-skills';
    public const REPO = 'https://github.com/The-Lincoln/scientific-agent-skills.git';

    /** SCITBD-curated fast paths (skill → use). */
    public const FAST_PATHS = [
        'database-lookup' => '80 scientific/financial databases (PubChem, ChEMBL, ClinicalTrials.gov, FRED, USPTO…)',
        'paper-lookup' => 'Full-text papers + FDA/PMDA/EMA filings with line-pinned citations',
        'scientific-writing' => 'Evidence-traceable reports, briefs, literature synthesis',
        'scientific-visualization' => 'Publication-quality figures for CEO reports',
        'scikit-learn' => 'Predictive models on lead/sales data',
        'statsmodels' => 'Correlations, regressions, significance for KPI analysis',
        'grant-writing' => 'Proposal drafting structure (adapt for client proposals)',
    ];

    // ------------------------------------------------------------ catalog ---

    public static function vendorRoot(): string
    {
        $p = __DIR__;
        for ($i = 0; $i < 6; $i++) {
            if (is_file($p . '/skills_index.php')) {
                return $p . '/' . self::VENDOR;
            }
            $up = dirname($p);
            if ($up === $p) {
                break;
            }
            $p = $up;
        }
        return dirname(__DIR__, 3) . '/' . self::VENDOR;
    }

    public static function skillsDir(): string
    {
        return self::vendorRoot() . '/skills';
    }

    /** Parse YAML frontmatter (name/description/version/compatibility). Never throws. */
    private static function frontmatter(string $file): array
    {
        $out = ['name' => basename(dirname($file)), 'description' => '', 'version' => '', 'compatibility' => ''];
        $raw = @file_get_contents($file);
        if (!is_string($raw) || !str_starts_with($raw, '---')) {
            return $out;
        }
        $end = strpos($raw, "\n---", 3);
        $fm = $end === false ? '' : substr($raw, 3, $end - 3);
        foreach (['name', 'description', 'version', 'compatibility'] as $k) {
            if (preg_match('/^' . $k . ':\s*(.+)$/m', $fm, $m)) {
                $out[$k] = trim(trim($m[1]), "\"'");
            }
        }
        // Upstream nests version under metadata: (metadata:\n  version: "x.y").
        if ($out['version'] === '' && preg_match('/^\s+version:\s*(.+)$/m', $fm, $m)) {
            $out['version'] = trim(trim($m[1]), "\"'");
        }
        // Folded multiline description (description: | ... or >).
        if ($out['description'] === '' || $out['description'] === '|' || $out['description'] === '>') {
            if (preg_match('/^description:\s*[|>]\s*\n((?:[ \t]+.*\n?)+)/m', $fm, $m)) {
                $out['description'] = mb_substr(trim(preg_replace('/\s+/', ' ', $m[1])), 0, 500);
            }
        }
        return $out;
    }

    /** Full catalog: [{id,name,description,version,path}]. Cached per request. */
    public static function catalog(bool $refresh = false): array
    {
        static $cache = null;
        if ($cache !== null && !$refresh) {
            return $cache;
        }
        $cache = [];
        $dir = self::skillsDir();
        if (!is_dir($dir)) {
            return $cache;
        }
        foreach (glob($dir . '/*/SKILL.md') ?: [] as $f) {
            $fm = self::frontmatter($f);
            $cache[] = [
                'id' => basename(dirname($f)),
                'name' => $fm['name'] !== '' ? $fm['name'] : basename(dirname($f)),
                'description' => mb_substr($fm['description'], 0, 300),
                'version' => $fm['version'],
                'path' => $f,
            ];
        }
        usort($cache, fn($a, $b) => strcmp($a['id'], $b['id']));
        return $cache;
    }

    public static function status(): array
    {
        $n = count(self::catalog());
        return [
            'ok' => $n > 0,
            'driver' => 'scientific-skills',
            'skills' => $n,
            'vendor' => self::VENDOR,
            'repo' => self::REPO,
            'fast_paths' => array_keys(self::FAST_PATHS),
            'hint' => $n > 0
                ? 'Ready. search() to pick, get() to load the SKILL.md body.'
                : 'Missing vendor checkout: git clone ' . self::REPO . ' ' . self::VENDOR,
        ];
    }

    /** Keyword search over id + name + description. */
    public static function search(string $query, int $limit = 10): array
    {
        $query = mb_strtolower(trim($query));
        if ($query === '') {
            return ['ok' => false, 'error' => 'query required', 'hits' => []];
        }
        $words = array_values(array_filter(preg_split('/\s+/', $query) ?: [], fn($w) => mb_strlen($w) > 2));
        if (empty($words)) {
            $words = [$query];
        }
        $hits = [];
        foreach (self::catalog() as $s) {
            $hay = mb_strtolower($s['id'] . ' ' . $s['name'] . ' ' . $s['description']);
            $score = 0;
            foreach ($words as $w) {
                if (str_contains($hay, $w)) {
                    $score += mb_strlen($w);
                }
            }
            if ($score > 0) {
                $hits[] = ['score' => $score] + $s;
            }
        }
        usort($hits, fn($a, $b) => $b['score'] <=> $a['score']);
        $hits = array_slice($hits, 0, max(1, min($limit, 30)));
        foreach ($hits as &$h) {
            unset($h['path']);
        }
        return ['ok' => true, 'hits' => $hits, 'total' => count($hits)];
    }

    /** Load one SKILL.md body (for prompt injection). Returns content + meta. */
    public static function get(string $id): array
    {
        $id = trim($id);
        if ($id === '' || str_contains($id, '..') || str_contains($id, '/')) {
            return ['ok' => false, 'error' => 'invalid skill id'];
        }
        $f = self::skillsDir() . '/' . $id . '/SKILL.md';
        if (!is_file($f)) {
            $s = self::search($id, 1);
            $alt = !empty($s['hits']) ? ' Did you mean: ' . $s['hits'][0]['id'] . '?' : '';
            return ['ok' => false, 'error' => 'skill not found.' . $alt];
        }
        $fm = self::frontmatter($f);
        $body = (string)@file_get_contents($f);
        // Strip frontmatter from injected body.
        if (str_starts_with($body, '---') && ($end = strpos($body, "\n---")) !== false) {
            $body = ltrim(substr($body, $end + 4));
        }
        return ['ok' => true, 'id' => $id, 'name' => $fm['name'], 'version' => $fm['version'], 'content' => $body, 'chars' => mb_strlen($body)];
    }

    // -------------------------------------------------------------- db ---

    public const SCHEMA = <<<SQL
CREATE TABLE IF NOT EXISTS sciskill_runs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    module TEXT NOT NULL DEFAULT 'shared',
    kind TEXT NOT NULL DEFAULT 'get',
    target TEXT NOT NULL DEFAULT '',
    ok INTEGER NOT NULL DEFAULT 0,
    ms INTEGER NOT NULL DEFAULT 0,
    output_excerpt TEXT,
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

    public static function logRun(PDO $db, string $module, string $kind, string $target, bool $ok, int $ms, string $excerpt = ''): void
    {
        try {
            self::ensureTables($db);
            $st = $db->prepare('INSERT INTO sciskill_runs (module, kind, target, ok, ms, output_excerpt) VALUES (?,?,?,?,?,?)');
            $st->execute([$module, mb_substr($kind, 0, 60), mb_substr($target, 0, 200), $ok ? 1 : 0, $ms, mb_substr($excerpt, 0, 1000)]);
        } catch (Throwable $e) {
        }
    }
}
