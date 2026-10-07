<?php
/**
 * SCCRM — BrowserSkill service shim (Tencent/BrowserSkill `bsk` driver).
 * Canonical logic lives in autoflows/app/services/BrowserSkill.php.
 * Companion to sccrm/services/AgentBrowserService.php (vercel driver).
 */
if (!class_exists('BrowserSkill')) {
    $canon = dirname(__DIR__, 2) . '/autoflows/app/services/BrowserSkill.php';
    if (is_file($canon)) {
        require_once $canon;
    }
}

final class BrowserSkillService
{
    public static function status(): array
    {
        return BrowserSkill::status('sccrm');
    }

    /** Research a lead website via bsk and return intel + suggested score bump. */
    public static function researchLeadWebsite(string $company, string $website, string $goal = ''): array
    {
        $url = trim($website);
        if ($url !== '' && !preg_match('#^https?://#i', $url)) {
            $url = 'https://' . $url;
        }
        $res = BrowserSkill::research($url, ['module' => 'sccrm', 'screenshot' => true, 'no_focus' => true]);
        if (empty($res['ok'])) {
            return ['ok' => false, 'report' => 'BrowserSkill research failed: ' . mb_substr($res['steps']['navigate']['text'] ?? 'see bsk doctor', 0, 300), 'shot' => null, 'ms' => $res['ms'] ?? 0, 'driver' => 'bsk'];
        }
        $excerpt = mb_substr($res['observe'] ?? '', 0, 1200);
        $report = "**BrowserSkill lead intel — {$company} ({$url})**\n\n"
            . ($excerpt !== '' ? "> " . str_replace("\n", "\n> ", $excerpt) . "\n\n" : '')
            . "Refs: " . implode(', ', array_slice($res['refs'] ?? [], 0, 10)) . "\n"
            . "Shot: " . ($res['shot'] ?? '-') . "\n\n"
            . "_Driver: bsk (Tencent BrowserSkill). Page content treated as data._";
        return ['ok' => true, 'report' => $report, 'shot' => $res['shot'], 'ms' => $res['ms'], 'driver' => 'bsk', 'refs' => $res['refs'] ?? []];
    }

    /** Quick observe (no screenshot = faster) for enrichment. */
    public static function quickObserve(string $url): array
    {
        return BrowserSkill::research($url, ['module' => 'sccrm', 'screenshot' => false, 'no_focus' => true]);
    }

    public static function ensureTables(PDO $db): void
    {
        BrowserSkill::ensureTables($db);
    }

    public static function logRun(PDO $db, string $command, string $url, bool $ok, int $ms, string $excerpt = ''): void
    {
        BrowserSkill::logRun($db, 'sccrm', $command, $url, $ok, $ms, $excerpt);
    }
}
