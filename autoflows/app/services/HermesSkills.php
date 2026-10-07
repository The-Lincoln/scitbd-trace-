<?php
/**
 * HermesSkills — registry for Hermes-House skill packs inside AutoFlows.
 *
 * Skills live verbatim under storage/skills/<skill-name>/SKILL.md (+skill.json).
 * Imported via tools/import_hermes_skills.php from a Hermes-House checkout.
 * This registry is read-only at runtime and never hits the network.
 */
declare(strict_types=1);

final class HermesSkills
{
    /** Curated default set aligned to Block 3 peak launch + FinanceTube. */
    public static function defaultSet(): array
    {
        return [
            'youtube-auto',
            'content-generator',
            'marketing-seo-specialist',
            'marketing-content-creator',
            'marketing-social-media-strategist',
            'seo-analysis',
            'website-seo',
            'meta-tags-optimizer',
            'keyword-research',
            'trendradar',
            'finance-investment-researcher',
            'finance-financial-analyst',
        ];
    }

    public static function baseDir(): string
    {
        $base = defined('BASE_PATH') ? (string) BASE_PATH : dirname(__DIR__, 2);
        return rtrim($base, '/\\') . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'skills';
    }

    /** @return array<int,array{name:string,description:string,path:string,has_md:bool,has_json:bool}> */
    public static function list(): array
    {
        $dir = self::baseDir();
        if (!is_dir($dir)) {
            return [];
        }
        $out = [];
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $entry;
            if (!is_dir($path)) {
                continue;
            }
            $md = $path . DIRECTORY_SEPARATOR . 'SKILL.md';
            $js = $path . DIRECTORY_SEPARATOR . 'skill.json';
            $out[] = [
                'name' => $entry,
                'description' => self::describe($path, $entry),
                'path' => $path,
                'has_md' => is_file($md),
                'has_json' => is_file($js),
            ];
        }
        usort($out, fn ($a, $b) => strcmp($a['name'], $b['name']));
        return $out;
    }

    public static function exists(string $name): bool
    {
        $safe = self::safeName($name);
        if ($safe === '') {
            return false;
        }
        return is_dir(self::baseDir() . DIRECTORY_SEPARATOR . $safe);
    }

    /** @return null|array{name:string,description:string,md:string,json:array,path:string} */
    public static function get(string $name): ?array
    {
        $safe = self::safeName($name);
        if ($safe === '') {
            return null;
        }
        $path = self::baseDir() . DIRECTORY_SEPARATOR . $safe;
        if (!is_dir($path)) {
            return null;
        }
        $mdFile = $path . DIRECTORY_SEPARATOR . 'SKILL.md';
        $jsFile = $path . DIRECTORY_SEPARATOR . 'skill.json';
        $md = is_file($mdFile) ? (string) file_get_contents($mdFile) : '';
        $js = [];
        if (is_file($jsFile)) {
            $decoded = json_decode((string) file_get_contents($jsFile), true);
            if (is_array($decoded)) {
                $js = $decoded;
            }
        }
        return [
            'name' => $safe,
            'description' => self::describe($path, $safe),
            'md' => $md,
            'json' => $js,
            'path' => $path,
        ];
    }

    /**
     * Build a compact prompt block from one or more skills.
     * Truncates each SKILL.md to keep prompts within model limits.
     */
    public static function promptBlock(array $names, int $maxCharsPerSkill = 2000): string
    {
        $parts = [];
        foreach ($names as $name) {
            $skill = self::get((string) $name);
            if ($skill === null || trim($skill['md']) === '') {
                continue;
            }
            $body = trim($skill['md']);
            if (mb_strlen($body) > $maxCharsPerSkill) {
                $body = rtrim(mb_substr($body, 0, $maxCharsPerSkill)) . "\n…(truncated)";
            }
            $parts[] = "## Skill: {$skill['name']}\n{$body}";
        }
        if ($parts === []) {
            return '';
        }
        return "## Hermes Skills (apply where relevant)\n" . implode("\n\n", $parts) . "\n";
    }

    public static function safeName(string $name): string
    {
        $name = strtolower(trim($name));
        $name = (string) preg_replace('/[^a-z0-9\-_]/', '', $name);
        if ($name === '' || $name === '.' || $name === '..') {
            return '';
        }
        return $name;
    }

    private static function describe(string $path, string $fallback): string
    {
        $jsFile = $path . DIRECTORY_SEPARATOR . 'skill.json';
        if (is_file($jsFile)) {
            $decoded = json_decode((string) file_get_contents($jsFile), true);
            $desc = trim((string) ($decoded['description'] ?? ''));
            if ($desc !== '') {
                return mb_substr($desc, 0, 300);
            }
        }
        $mdFile = $path . DIRECTORY_SEPARATOR . 'SKILL.md';
        if (is_file($mdFile)) {
            $md = (string) file_get_contents($mdFile);
            if (preg_match('/description\s*:\s*["\']?([^"\'\r\n]+)["\']?/i', $md, $m)) {
                return mb_substr(trim($m[1]), 0, 300);
            }
        }
        return $fallback;
    }
}
