<?php
/**
 * ContentEngineRef — canonical PHP service for salehin96/content-engine reference.
 *
 * Single source of truth for CEO + SCCRM content pipeline (reference architecture).
 * Companion to ceo/content_engine_ref.py, shim sccrm/ai/content_engine_ref_agent.php.
 *
 * Upstream (vendored, reference maps — not runnable code):
 *   external/content-engine (https://github.com/salehin96/content-engine.git)
 *   Live: https://salehin96.github.io/content-engine/
 *   Six phases: research → topical map → briefs → research/write/adversarial-review
 *   → machine gate → publish+self-correct. Node kinds: engine/tool/external/gate.
 *
 * SCITBD mapping: ceo/scitbd_content_engine.py (pipeline) + sccrm/ai/content_agent.php
 * (TinyLLM drafting) + scitbd-content/blog/seo skills + content_items table.
 * Never throws.
 */
declare(strict_types=1);

final class ContentEngineRef
{
    public const VENDOR = 'external/content-engine';
    public const REPO = 'https://github.com/salehin96/content-engine.git';
    public const LIVE = 'https://salehin96.github.io/content-engine/';

    public static function vendorRoot(): string
    {
        $p = __DIR__;
        for ($i = 0; $i < 6; $i++) {
            if (is_file($p . '/skills_index.php')) return $p . '/' . self::VENDOR;
            $up = dirname($p);
            if ($up === $p) break;
            $p = $up;
        }
        return dirname(__DIR__, 3) . '/' . self::VENDOR;
    }

    public static function status(string $module = 'ceo'): array
    {
        $root = self::vendorRoot();
        $readme = is_file($root . '/README.md');
        $views = [];
        foreach (['index.html', 'content-engine.html', 'dependency-map.html'] as $f) {
            if (is_file($root . '/' . $f)) $views[] = $f;
        }
        $ok = $readme && count($views) === 3;
        return ['ok' => $ok, 'driver' => 'content-engine-ref', 'module' => $module,
                'vendor_present' => $readme, 'views' => $views, 'phases' => 6,
                'hint' => $ok ? 'Ready.' : 'Missing vendor: git clone ' . self::REPO . ' ' . self::VENDOR];
    }

    public static function phases(): array
    {
        return [
            ['n' => 1, 'name' => 'Brand + rival research', 'writes' => 'reference files', 'scitbd' => 'contentAgentBrief() context'],
            ['n' => 2, 'name' => 'Topical map', 'writes' => 'plan rows (URL/query/type/links)', 'scitbd' => 'script-built, rebuildable'],
            ['n' => 3, 'name' => 'Per-article briefs', 'writes' => 'brief per page', 'scitbd' => 'content_agent kinds (blog/social/email/video)'],
            ['n' => 4, 'name' => 'Research → write → adversarial review', 'writes' => 'evidence-only draft + skeptical rewrite', 'scitbd' => 'separate agents, cold handoff'],
            ['n' => 5, 'name' => 'Machine quality gate', 'writes' => 'gate proof (blocks save)', 'scitbd' => 'self::gateCheck()'],
            ['n' => 6, 'name' => 'Publish + self-correct', 'writes' => 'page + reconciled plan', 'scitbd' => 'scitbd_content_engine files/email + content_items'],
        ];
    }

    /** Mechanical validator (phase 5) — meaning is judged by reviewer agents, not here. */
    public static function gateCheck(string $text): array
    {
        $issues = [];
        $words = count(preg_split('/\s+/', trim($text)) ?: []);
        if ($words < 50) $issues[] = 'thin content (<50 words)';
        foreach (preg_split("/\n\n/", $text) ?: [] as $p) {
            if (count(preg_split('/\s+/', trim((string)$p)) ?: []) > 150) {
                $issues[] = 'paragraph >150 words — split'; break;
            }
        }
        if (preg_match('/\b(100%|guaranteed #1|best in the world)\b/i', $text, $m)) {
            $issues[] = "unverifiable claim: '{$m[1]}' — cite or remove";
        }
        return ['ok' => !$issues, 'words' => $words, 'issues' => $issues,
                'note' => 'Blocks save on fail. Re-run review on fix.'];
    }
}
