<?php
/**
 * HarnessGuide — canonical catalog for harness-engineering references.
 *
 * Single source of truth for CEO + SCCRM + Trace + AutoFlows (harness driver).
 * Companion to autoflows/app/services/ScientificSkills.php (skill catalog).
 * Shims: sccrm/services/HarnessGuideService.php, ceo/harness_guide.py.
 *
 * Upstream (vendored, both are curated reading lists — no runtime):
 *   external/awesome-harness-walkinglabs (walkinglabs/awesome-harness-engineering)
 *   external/awesome-harness-aiboost     (ai-boost/awesome-harness-engineering)
 * This wrapper indexes README ## sections + links, searches them, and
 * returns scoped reading lists for agent-harness work (hooks, MCP, evals,
 * sandboxing, provider routing — the same concerns as our AgentBrowser /
 * BrowserSkill / memory-proxy integrations).
 *
 * Design: no composer deps, PHP 8.1+. Never throws.
 */
declare(strict_types=1);

final class HarnessGuide
{
    public const SOURCES = [
        'walkinglabs' => ['dir' => 'external/awesome-harness-walkinglabs', 'repo' => 'https://github.com/walkinglabs/awesome-harness-engineering.git'],
        'aiboost' => ['dir' => 'external/awesome-harness-aiboost', 'repo' => 'https://github.com/ai-boost/awesome-harness-engineering.git'],
    ];

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
        return dirname(__DIR__, 3);
    }

    /** Sections per source: [{source, heading, links:[{title,url}]}]. Cached. */
    public static function catalog(bool $refresh = false): array
    {
        static $cache = null;
        if ($cache !== null && !$refresh) {
            return $cache;
        }
        $cache = [];
        $root = self::traceRoot();
        foreach (self::SOURCES as $key => $s) {
            $readme = $root . '/' . $s['dir'] . '/README.md';
            if (!is_file($readme)) {
                continue;
            }
            $sections = [];
            $cur = null;
            foreach (file($readme, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
                if (preg_match('/^#{1,3}\s+(.+)/', trim($line), $m)) {
                    $cur = trim($m[1]);
                    $sections[$cur] = [];
                } elseif ($cur !== null && preg_match_all('/\[([^\]]+)\]\((https?:[^)]+)\)/', $line, $mm, PREG_SET_ORDER)) {
                    foreach ($mm as $l) {
                        $sections[$cur][] = ['title' => $l[1], 'url' => $l[2]];
                    }
                }
            }
            $cache[] = ['source' => $key, 'repo' => $s['repo'], 'sections' => $sections,
                'section_count' => count($sections),
                'link_count' => array_sum(array_map('count', $sections))];
        }
        return $cache;
    }

    public static function status(): array
    {
        $cat = self::catalog();
        $links = array_sum(array_column($cat, 'link_count'));
        return [
            'ok' => !empty($cat),
            'driver' => 'harness-guide',
            'sources' => array_column($cat, 'source'),
            'sections' => array_sum(array_column($cat, 'section_count')),
            'links' => $links,
            'hint' => $cat ? 'Ready. search() for topics, readingList() for scoped briefs.' : 'Missing vendor checkouts (external/awesome-harness-*)',
        ];
    }

    /** Keyword search across headings + link titles. */
    public static function search(string $query, int $limit = 12): array
    {
        $query = mb_strtolower(trim($query));
        if ($query === '') {
            return ['ok' => false, 'error' => 'query required', 'hits' => []];
        }
        $words = array_values(array_filter(preg_split('/\s+/', $query) ?: [], fn($w) => mb_strlen($w) > 2)) ?: [$query];
        $hits = [];
        foreach (self::catalog() as $src) {
            foreach ($src['sections'] as $heading => $links) {
                foreach ($links as $l) {
                    $hay = mb_strtolower($heading . ' ' . $l['title']);
                    $score = 0;
                    foreach ($words as $w) {
                        if (str_contains($hay, $w)) {
                            $score += mb_strlen($w);
                        }
                    }
                    if ($score > 0) {
                        $hits[] = ['score' => $score, 'source' => $src['source'], 'section' => $heading] + $l;
                    }
                }
            }
        }
        usort($hits, fn($a, $b) => $b['score'] <=> $a['score']);
        $hits = array_slice($hits, 0, max(1, min($limit, 30)));
        return ['ok' => true, 'hits' => $hits, 'total' => count($hits)];
    }
}
