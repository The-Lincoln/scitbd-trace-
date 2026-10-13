<?php
/**
 * OSINT SocialAnalyzerHook — username → social profiles for URL/person traces (layer 3: app).
 *
 * analyzeUsername($username, $opts): run the vendored social-analyzer CLI
 * (python3 external/social-analyzer/app.py --output json) and return
 * normalized profiles. No-op when unconfigured. Never throws, never
 * slows the trace excessively (bounded timeout, silent skip when down).
 */
namespace OSINT;

class SocialAnalyzerHook
{
    public static function ensure(): bool
    {
        if (!class_exists('SocialAnalyzer')) {
            $canon = dirname(__DIR__) . '/autoflows/app/services/SocialAnalyzer.php';
            if (is_file($canon)) {
                require_once $canon;
            }
        }
        return class_exists('SocialAnalyzer');
    }

    public static function analyzeUsername(string $username, array $opts = [], $pdo = null, string $module = 'trace'): array
    {
        try {
            if (!self::ensure()) {
                return ['ok' => false, 'profiles' => [], 'source' => 'none', 'error' => 'SocialAnalyzer service missing'];
            }
            $r = \SocialAnalyzer::analyze($username, $opts);
            if ($pdo instanceof \PDO) {
                \SocialAnalyzer::logRun(
                    $pdo,
                    $module,
                    'analyze-' . ($opts['mode'] ?? 'fast'),
                    mb_substr($username, 0, 200),
                    !empty($r['ok']),
                    (int)($r['ms'] ?? 0),
                    mb_substr(json_encode($r['summary'] ?? []), 0, 500)
                );
            }
            return $r + ['source' => 'social-analyzer'];
        } catch (\Throwable $e) {
            return ['ok' => false, 'profiles' => [], 'source' => 'none', 'error' => $e->getMessage()];
        }
    }
}
