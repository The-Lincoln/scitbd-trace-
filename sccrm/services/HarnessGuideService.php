<?php
/**
 * SCCRM — HarnessGuide service shim (layer 2 of 3: SCCRM agents).
 * Canonical logic: autoflows/app/services/HarnessGuide.php
 * CEO mirror: ceo/harness_guide.py
 */
if (!class_exists('HarnessGuide')) {
    $canon = dirname(__DIR__, 2) . '/autoflows/app/services/HarnessGuide.php';
    if (is_file($canon)) {
        require_once $canon;
    }
}

final class HarnessGuideService
{
    public static function status(): array
    {
        return HarnessGuide::status();
    }

    public static function search(string $topic, int $limit = 12): array
    {
        return HarnessGuide::search($topic, $limit);
    }
}
