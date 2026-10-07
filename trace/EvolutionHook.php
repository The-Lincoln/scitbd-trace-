<?php
/**
 * OSINT EvolutionHook — WhatsApp alerts for URL traces (layer 3: app).
 *
 * pushTrace($url, $traceResult, $to): send a compact trace summary to the
 * owner/operator on WhatsApp. No-op when unconfigured. Never throws, never
 * slows the trace (short timeout, fire-and-log).
 */
namespace OSINT;

class EvolutionHook
{
    public static function ensure(): bool
    {
        if (!class_exists('EvolutionApi')) {
            $canon = dirname(__DIR__) . '/autoflows/app/services/EvolutionApi.php';
            if (is_file($canon)) {
                require_once $canon;
            }
        }
        return class_exists('EvolutionApi');
    }

    public static function pushTrace(string $url, array $traceResult, string $to = '', $pdo = null, string $module = 'trace'): array
    {
        try {
            if (!self::ensure()) {
                return ['ok' => false, 'fanout' => 'none'];
            }
            if ($to === '') {
                $c = \EvolutionApi::config();
                $to = $c['ceo_number'];
            }
            if ($to === '') {
                return ['ok' => false, 'fanout' => 'none', 'note' => 'no recipient (pass $to or set EVOLUTION_CEO_NUMBER)'];
            }
            $title = mb_substr((string)($traceResult['content']['title'] ?? '-'), 0, 100);
            $text = sprintf(
                "🔍 Trace done: %s\n%s | status %s | SEO %d · SEC %d",
                $url, $title,
                (string)($traceResult['basic']['status_code'] ?? '?'),
                (int)($traceResult['seo']['score'] ?? 0),
                (int)($traceResult['security']['score'] ?? 0)
            );
            $r = \EvolutionApi::sendText($to, $text, null, ['timeout' => 12]);
            if ($pdo instanceof \PDO) {
                \EvolutionApi::logRun($pdo, $module, 'sendText-trace', $to, !empty($r['ok']), (int)($r['ms'] ?? 0), mb_substr($url, 0, 300));
            }
            return $r + ['fanout' => 'evolution-api'];
        } catch (\Throwable $e) {
            return ['ok' => false, 'fanout' => 'none', 'error' => $e->getMessage()];
        }
    }
}
