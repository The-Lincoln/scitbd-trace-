<?php
/**
 * ContributorGokul — canonical PHP service for Gokul-ram-j contributor profile.
 *
 * Single source of truth for CEO + SCCRM talent matching.
 * Companion to ceo/contributor_gokul.py, shim sccrm/ai/contributor_gokul_agent.php.
 *
 * Upstream (vendored, profile repo — not runnable code):
 *   external/Gokul-ram-j (https://github.com/The-Lincoln/Gokul-ram-j.git)
 *   README tech-stack + capabilities + metrics SVGs + profile-3d-contrib.
 *
 * Design: static data service, no composer deps, never throws.
 */
declare(strict_types=1);

final class ContributorGokul
{
    public const VENDOR = 'external/Gokul-ram-j';
    public const REPO = 'https://github.com/The-Lincoln/Gokul-ram-j.git';

    public const STACK = [
        'frontend' => ['HTML5','CSS3','Bootstrap','React','Next.js','React Native','Tailwind CSS','Three.js','Vite.js','Swiper.js'],
        'backend' => ['Node.js','Express.js','Spring','FastAPI','Apache Tomcat'],
        'languages' => ['JavaScript','TypeScript','Python','Java','C','JSON','YAML','LaTeX','Markdown'],
        'data_cloud' => ['MongoDB','MySQL','Firebase','Netlify'],
        'tools' => ['Docker','NPM','Nodemon','Git','GitHub','VS Code','Eclipse IDE','Expo','Postman','Swagger','Figma','Notion','LeetCode'],
    ];

    public const CAPABILITIES = [
        'Problem Solving & Algorithms','Competitive Programming','Open Source Contributions',
        'Responsive Web Applications','UI/UX Web Design','Scalable Backend Systems',
        'Database Design & Management','AI-Powered Web Applications','LLM Integration',
        'RAG-Based Systems','Cross-Platform Applications','Web & Mobile Applications',
    ];

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
        $svgs = is_dir($root) ? count(glob($root . '/*.svg') ?: []) : 0;
        return ['ok' => $readme, 'driver' => 'contributor-gokul', 'module' => $module,
                'vendor_present' => $readme, 'metrics_svgs' => $svgs,
                'contrib_3d' => is_dir($root . '/profile-3d-contrib'),
                'hint' => $readme ? 'Ready.' : 'Missing vendor: git clone ' . self::REPO . ' ' . self::VENDOR];
    }

    public static function matchTask(string $text): array
    {
        $hay = mb_strtolower($text);
        $map = ['react'=>['frontend','React'],'next'=>['frontend','Next.js'],'tailwind'=>['frontend','Tailwind CSS'],
            'three'=>['frontend','Three.js'],'node'=>['backend','Node.js'],'express'=>['backend','Express.js'],
            'spring'=>['backend','Spring'],'fastapi'=>['backend','FastAPI'],'python'=>['languages','Python'],
            'java'=>['languages','Java'],'mongo'=>['data_cloud','MongoDB'],'mysql'=>['data_cloud','MySQL'],
            'firebase'=>['data_cloud','Firebase'],'docker'=>['tools','Docker'],'rag'=>['capability','RAG-Based Systems'],
            'llm'=>['capability','LLM Integration'],'ui'=>['capability','UI/UX Web Design'],
            'mobile'=>['capability','Cross-Platform Applications'],'expo'=>['tools','Expo']];
        $hits = []; $seen = [];
        foreach ($map as $key => [$group, $label]) {
            if (str_contains($hay, $key) && !isset($seen[$label])) {
                $hits[] = ['group' => $group, 'label' => $label, 'via' => $key];
                $seen[$label] = true;
            }
        }
        $fit = $hits ? min(100, 40 + 15 * count($hits)) : 20;
        return ['ok' => true, 'hits' => $hits, 'fit_score' => $fit,
                'proposal' => ($fit >= 55 ? 'Assign' : 'Consider') . ' — ' . count($hits) . ' match(es). Human reviews before assign.'];
    }
}
