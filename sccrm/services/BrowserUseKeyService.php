<?php
/**
 * SCCRM — BrowserUse key shim (layer 2 of 3).
 * Canonical logic: autoflows/app/services/BrowserUseKey.php
 * CEO mirror: ceo/browseruse_key.py
 */
if (!class_exists('BrowserUseKey')) {
    $canon = dirname(__DIR__, 2) . '/autoflows/app/services/BrowserUseKey.php';
    if (is_file($canon)) {
        require_once $canon;
    }
}

final class BrowserUseKeyService
{
    public static function has(): bool
    {
        return class_exists('BrowserUseKey') && BrowserUseKey::has();
    }

    public static function masked(): string
    {
        return class_exists('BrowserUseKey') ? BrowserUseKey::masked() : '(missing)';
    }

    /** Status for SCCRM settings / diagnostics panels (never full key). */
    public static function status(): array
    {
        return [
            'driver' => 'browseruse-cloud',
            'env' => BrowserUseKey::ENV,
            'configured' => self::has(),
            'key' => self::masked(),
            'header' => BrowserUseKey::HEADER,
        ];
    }
}
