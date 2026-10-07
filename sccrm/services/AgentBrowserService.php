<?php
/**
 * SCCRM — AgentBrowser service shim.
 * Canonical logic lives in autoflows/app/services/AgentBrowser.php +
 * BrowserAgent.php. This file adds CRM-flavoured helpers and ensures the
 * class is loaded no matter which entry point booted first.
 */
if (!class_exists('AgentBrowser')) {
    $canon = dirname(__DIR__, 2) . '/autoflows/app/services/AgentBrowser.php';
    if (is_file($canon)) {
        require_once $canon;
    }
}
if (!class_exists('BrowserAgent')) {
    $ba = dirname(__DIR__, 2) . '/autoflows/app/services/BrowserAgent.php';
    if (is_file($ba)) {
        // BrowserAgent references TinyLLM only lazily; safe to load standalone.
        require_once $ba;
    }
}

final class AgentBrowserService
{
    public static function status(): array
    {
        return AgentBrowser::status('sccrm');
    }

    /** Research a lead website and return intel + suggested score bump. */
    public static function researchLeadWebsite(string $company, string $website, string $goal = ''): array
    {
        $res = BrowserAgent::run([
            'plan' => 'research',
            'url' => $website,
            'goal' => $goal !== '' ? $goal : "Research {$company} ({$website}) for lead scoring: business model, tech signals, contact paths.",
            'module' => 'sccrm',
        ]);
        return $res;
    }

    /** Quick rendered snapshot for enrichment (no screenshot = faster). */
    public static function quickSnapshot(string $url): array
    {
        $o = AgentBrowser::open($url, ['module' => 'sccrm', 'timeout' => 60]);
        if (!$o['ok']) {
            return $o;
        }
        $snap = AgentBrowser::snapshot(['module' => 'sccrm']);
        $read = AgentBrowser::read(null, ['module' => 'sccrm']);
        $snap['read_excerpt'] = mb_substr($read['text'] ?? '', 0, 2000);
        return $snap;
    }

    public static function ensureTables(PDO $db): void
    {
        AgentBrowser::ensureTables($db);
    }

    public static function logRun(PDO $db, string $command, string $url, bool $ok, int $ms, string $excerpt = ''): void
    {
        AgentBrowser::logRun($db, 'sccrm', $command, $url, $ok, $ms, $excerpt);
    }
}
