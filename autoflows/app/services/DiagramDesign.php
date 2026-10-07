<?php
/**
 * DiagramDesign — canonical catalog for editorial diagrams.
 *
 * Single source of truth for CEO + SCCRM + Trace + AutoFlows (diagrams driver).
 * Companion to autoflows/app/services/OpenMontage.php (video).
 * Shims: sccrm/services/DiagramDesignService.php, ceo/diagram_design.py.
 *
 * Upstream (vendored): external/diagram-design
 *   (https://github.com/The-Lincoln/diagram-design.git — fork of
 *    cathrynlavery/diagram-design; Agent Skills standard)
 * Skill: skills/diagram-design/{SKILL.md, references/type-*.md, assets/*.html}.
 * Output: self-contained HTML+SVG (no build, no external images).
 * Rules: pick type first, density 4/10, accent = 1–2 focal points, no shadows,
 *   no Mermaid slop; brand via references/style-guide.md onboarding.
 *
 * This wrapper catalogs types (from type-*.md), scaffolds output from
 * template.html into data/diagrams/, and validates the finished file.
 * The agent writes the SVG per the type reference; export (SVG/PNG) follows
 * references/export.md (Playwright for PNG).
 *
 * Design: no composer deps, PHP 8.1+. Never throws.
 */
declare(strict_types=1);

final class DiagramDesign
{
    public const VENDOR = 'external/diagram-design';
    public const REPO = 'https://github.com/The-Lincoln/diagram-design.git';

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

    public static function skillDir(): string
    {
        return self::vendorRoot() . '/skills/diagram-design';
    }

    public static function diagramsDir(): string
    {
        $p = __DIR__;
        for ($i = 0; $i < 6; $i++) {
            if (is_dir($p . '/data')) {
                $d = $p . '/data/diagrams';
                if (!is_dir($d)) {
                    @mkdir($d, 0775, true);
                }
                if (is_dir($d)) {
                    return $d;
                }
            }
            $up = dirname($p);
            if ($up === $p) {
                break;
            }
            $p = $up;
        }
        $d = sys_get_temp_dir() . '/scitbd-diagrams';
        if (!is_dir($d)) {
            @mkdir($d, 0775, true);
        }
        return $d;
    }

    /** Types from references/type-*.md (id → title from first heading). */
    public static function listTypes(): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        $cache = [];
        foreach (glob(self::skillDir() . '/references/type-*.md') ?: [] as $f) {
            $id = preg_replace('/^type-|\.md$/', '', basename($f));
            $title = $id;
            $h = @file($f, FILE_IGNORE_NEW_LINES);
            if (is_array($h)) {
                foreach ($h as $line) {
                    if (preg_match('/^#\s+(.+)/', trim($line), $m)) {
                        $title = trim($m[1]);
                        break;
                    }
                }
            }
            $cache[$id] = $title;
        }
        ksort($cache);
        return $cache;
    }

    public static function status(): array
    {
        $n = count(self::listTypes());
        return [
            'ok' => $n > 0,
            'driver' => 'diagram-design',
            'types' => $n,
            'vendor' => self::VENDOR,
            'repo' => self::REPO,
            'out_dir' => self::diagramsDir(),
            'hint' => $n > 0
                ? 'Ready. scaffold() from template.html, agent draws per type reference.'
                : 'Missing vendor checkout: git clone ' . self::REPO . ' ' . self::VENDOR,
        ];
    }

    /** Type reference body (selection guide + grammar for the agent). */
    public static function getType(string $id): array
    {
        $id = preg_replace('/[^a-z0-9-]/', '', strtolower(trim($id)));
        $f = self::skillDir() . '/references/type-' . $id . '.md';
        if (!is_file($f)) {
            $alts = array_filter(array_keys(self::listTypes()), fn($t) => str_contains($t, explode('-', $id)[0] ?? ''));
            return ['ok' => false, 'error' => 'unknown type.' . ($alts ? ' Try: ' . implode(', ', array_slice($alts, 0, 5)) : '')];
        }
        return ['ok' => true, 'id' => $id, 'content' => (string)@file_get_contents($f), 'chars' => filesize($f)];
    }

    public static function slug(string $title): string
    {
        $s = mb_strtolower(trim($title));
        $s = (string)preg_replace('/[^a-z0-9]+/', '-', $s);
        return trim($s, '-') ?: ('diagram-' . date('Ymd-His'));
    }

    /**
     * Scaffold data/diagrams/<slug>.html from a template variant.
     * $variant: template (minimal light) | template-dark | template-full | template-motion.
     */
    public static function scaffold(string $title, string $type, string $variant = 'template', string $module = 'ceo'): array
    {
        $types = self::listTypes();
        if (!isset($types[$type])) {
            return ['ok' => false, 'error' => 'Unknown type. See listTypes() (' . count($types) . ' available)'];
        }
        $tpl = self::skillDir() . '/assets/' . preg_replace('/[^a-z-]/', '', $variant) . '.html';
        if (!is_file($tpl)) {
            $tpl = self::skillDir() . '/assets/template.html';
        }
        if (!is_file($tpl)) {
            return ['ok' => false, 'error' => 'template missing in vendor assets/'];
        }
        $slug = self::slug($title);
        $dest = self::diagramsDir() . '/' . $slug . '.html';
        if (!@copy($tpl, $dest)) {
            return ['ok' => false, 'error' => 'Cannot write ' . $dest];
        }
        @file_put_contents(self::diagramsDir() . '/' . $slug . '.spec.json', json_encode([
            'title' => $title, 'type' => $type, 'variant' => basename($tpl, '.html'),
            'module' => $module, 'created' => date('c'),
            'next' => "Agent draws per references/type-{$type}.md; density 4/10; brand via style-guide.md",
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        try {
            $db = self::db();
            if ($db) {
                self::ensureTables($db);
                $st = $db->prepare("INSERT INTO diagram_runs (module, diagram_type, slug, title, status) VALUES (?,?,?,?,?)");
                $st->execute([$module, $type, $slug, mb_substr($title, 0, 200), 'scaffolded']);
            }
        } catch (Throwable $e) {
        }
        return ['ok' => true, 'slug' => $slug, 'type' => $type, 'file' => $dest, 'reference' => "references/type-{$type}.md"];
    }

    /** Validate a finished diagram (static-first checks from output-spec). */
    public static function validate(string $file): array
    {
        if (!is_file($file)) {
            return ['ok' => false, 'error' => 'file missing'];
        }
        $html = (string)@file_get_contents($file);
        $checks = [
            'has_svg' => str_contains($html, '<svg'),
            'no_mermaid' => !preg_match('/mermaid\.min\.js|class="mermaid"|mermaid\.initialize/i', $html),
            'no_remote_assets' => !preg_match('/<(img|script)[^>]+src="https?:/i', $html),
            'accessible_svg' => (bool)preg_match('/<svg[^>]*role="img"/i', $html),
            'size_kb' => round(filesize($file) / 1024, 1),
        ];
        $pass = $checks['has_svg'] && $checks['no_mermaid'] && $checks['no_remote_assets'];
        return ['ok' => $pass, 'checks' => $checks];
    }

    // -------------------------------------------------------------- db ---

    public const SCHEMA = <<<SQL
CREATE TABLE IF NOT EXISTS diagram_runs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    module TEXT NOT NULL DEFAULT 'ceo',
    diagram_type TEXT NOT NULL DEFAULT '',
    slug TEXT NOT NULL DEFAULT '',
    title TEXT NOT NULL DEFAULT '',
    status TEXT NOT NULL DEFAULT 'scaffolded',
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
        foreach ([dirname(__DIR__, 2) . '/storage/app.db'] as $p) {
            if (is_file($p)) {
                try {
                    $db = new PDO('sqlite:' . $p);
                    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                    return $db;
                } catch (Throwable $e) {
                    continue;
                }
            }
        }
        // Trace-root DBs (services live two levels under trace/).
        $root = dirname(__DIR__, 3);
        foreach ([$root . '/data/osint.db', $root . '/sccrm/db/scit_crm.db', $root . '/ceo/scitbd_ceo.db'] as $p) {
            if (is_file($p)) {
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
}
