<?php
/**
 * SocialAnalyzerService — SCCRM shim. Delegates to canonical
 * autoflows/app/services/SocialAnalyzer.php. Keeps CRM Naturally.
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/autoflows/app/services/SocialAnalyzer.php';

final class SocialAnalyzerService
{
    public static function analyze(string $username, array $opts = []): array
    {
        return SocialAnalyzer::analyze($username, $opts);
    }

    public static function status(array $over = []): array
    {
        return SocialAnalyzer::status($over);
    }

    /** Log a run into the CRM db (auditable outreach/investigation trail). */
    public static function log(PDO $db, string $username, array $result, string $module = 'sccrm'): void
    {
        SocialAnalyzer::logRun($db, $module, 'analyze', $username, !empty($result['ok']), (int)($result['ms'] ?? 0), mb_substr(json_encode($result['summary'] ?? []), 0, 500));
    }
}
