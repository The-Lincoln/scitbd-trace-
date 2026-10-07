<?php
/**
 * OSINT OpenVikingHook — viking:// recall for URL traces (layer 3: app).
 *
 * recallBeforeTrace($url): scoped find on the host so the next trace starts
 * from existing project/memory context instead of cold. Never throws, never
 * slows the trace (short timeout, silent skip when ov/server is down).
 */
namespace OSINT;

class OpenVikingHook
{
    public static function ensure(): bool
    {
        if (!class_exists('OpenViking')) {
            $canon = dirname(__DIR__) . '/autoflows/app/services/OpenViking.php';
            if (is_file($canon)) {
                require_once $canon;
            }
        }
        return class_exists('OpenViking');
    }

    public static function recallBeforeTrace(string $url, $pdo = null, string $scope = ''): array
    {
        try {
            if (!self::ensure()) {
                return ['ok' => false, 'text' => '', 'source' => 'none'];
            }
            $host = (string)(parse_url($url, PHP_URL_HOST) ?: $url);
            return \OpenViking::recall($host, $scope !== '' ? $scope : null, ['timeout' => 20, 'limit' => 4]);
        } catch (\Throwable $e) {
            return ['ok' => false, 'text' => '', 'source' => 'none', 'error' => $e->getMessage()];
        }
    }
}
