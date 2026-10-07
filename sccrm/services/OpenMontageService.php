<?php
/**
 * SCCRM — OpenMontage service shim (layer 2 of 3: SCCRM agents).
 * Canonical logic: autoflows/app/services/OpenMontage.php
 * CEO mirror: ceo/openmontage.py
 */
if (!class_exists('OpenMontage')) {
    $canon = dirname(__DIR__, 2) . '/autoflows/app/services/OpenMontage.php';
    if (is_file($canon)) {
        require_once $canon;
    }
}

final class OpenMontageService
{
    public static function status(): array
    {
        return OpenMontage::status();
    }

    public static function pipelines(): array
    {
        return OpenMontage::listPipelines();
    }

    public static function models(): array
    {
        return OpenMontage::models();
    }

    public static function preflight(): array
    {
        return OpenMontage::preflight();
    }

    /** Request a lead/campaign video (brief scaffold + audit row). */
    public static function requestVideo(string $title, string $brief, string $pipeline = 'animated-explainer', array $opts = []): array
    {
        $r = OpenMontage::requestProduction($title, $brief, $pipeline, 'sccrm', $opts);
        try {
            $dbFile = dirname(__DIR__) . '/db/scit_crm.db';
            if (is_file($dbFile) && !empty($r['ok'])) {
                $db = new PDO('sqlite:' . $dbFile);
                OpenMontage::logRun($db, 'sccrm', $pipeline, (string)($r['slug'] ?? ''), 'requested');
            }
        } catch (Throwable $e) {
        }
        return $r;
    }

    public static function productions(int $limit = 30): array
    {
        return OpenMontage::listProductions($limit);
    }

    public static function view(string $slug): ?array
    {
        return OpenMontage::getProduction($slug);
    }

    public static function edit(string $slug, array $fields): array
    {
        return OpenMontage::updateProduction($slug, $fields);
    }

    public static function remove(string $slug): array
    {
        return OpenMontage::deleteProduction($slug);
    }

    public static function ensureTables(PDO $db): void
    {
        OpenMontage::ensureTables($db);
    }
}
