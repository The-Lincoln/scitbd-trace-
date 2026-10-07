<?php
/**
 * SCCRM — OpenViking service shim (layer 2 of 3: SCCRM agents).
 * Canonical logic: autoflows/app/services/OpenViking.php
 * CEO mirror: ceo/openviking.py
 */
if (!class_exists('OpenViking')) {
    $canon = dirname(__DIR__, 2) . '/autoflows/app/services/OpenViking.php';
    if (is_file($canon)) {
        require_once $canon;
    }
}

final class OpenVikingService
{
    public static function status(): array
    {
        return OpenViking::status();
    }

    /** Recall company/lead context from the viking:// filesystem. */
    public static function recallLead(string $company, ?string $scope = null): array
    {
        return OpenViking::recall(trim($company), $scope, ['timeout' => 45]);
    }

    /** Index a repo or docs folder into context (async task on the server). */
    public static function indexSource(string $source): array
    {
        return OpenViking::addResource($source);
    }

    public static function ensureTables(PDO $db): void
    {
        OpenViking::ensureTables($db);
    }

    public static function logRun(PDO $db, string $kind, string $target, bool $ok, int $ms, string $excerpt = ''): void
    {
        OpenViking::logRun($db, 'sccrm', $kind, $target, $ok, $ms, $excerpt);
    }
}
