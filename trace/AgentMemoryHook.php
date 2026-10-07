<?php
/**
 * OSINT AgentMemoryHook — team-memory hooks for URL traces (layer 3: app).
 *
 * recallBeforeTrace($url): pull prior intel on this host/URL so the next
 *   trace starts from the save file instead of re-learning the target.
 * rememberAfterTrace($url, $traceResult): persist title/tech/scores summary
 *   as L0 (session = trace_id / normalized URL hash).
 *
 * Both never throw and never slow the trace: short timeouts, local-cache
 * fallback inside AgentMemory, silent skip when the stack is down.
 */
namespace OSINT;

class AgentMemoryHook
{
    public static function ensure(): bool
    {
        if (!class_exists('AgentMemory')) {
            $canon = dirname(__DIR__) . '/autoflows/app/services/AgentMemory.php';
            if (is_file($canon)) {
                require_once $canon;
            }
        }
        return class_exists('AgentMemory');
    }

    public static function recallBeforeTrace(string $url, $pdo = null, string $module = 'trace'): array
    {
        try {
            if (!self::ensure()) {
                return ['ok' => false, 'context' => '', 'source' => 'none'];
            }
            $host = (string)(parse_url($url, PHP_URL_HOST) ?: $url);
            return \AgentMemory::recall($host . ' ' . $url, ['module' => $module, 'limit' => 4, 'timeout' => 6]);
        } catch (\Throwable $e) {
            return ['ok' => false, 'context' => '', 'source' => 'none', 'error' => $e->getMessage()];
        }
    }

    public static function rememberAfterTrace(string $url, array $traceResult, $pdo = null, string $module = 'trace'): array
    {
        try {
            if (!self::ensure()) {
                return ['ok' => false, 'stored' => 'none'];
            }
            $session = (string)($traceResult['identity']['trace_id'] ?? $traceResult['basic']['request_uuid'] ?? md5(strtolower($url)));
            $title = (string)($traceResult['content']['title'] ?? '-');
            $techs = [];
            foreach ((array)($traceResult['technology']['all_technologies'] ?? []) as $t) {
                $techs[] = is_array($t) ? ($t['technology'] ?? '') : (string)$t;
            }
            $techs = implode(', ', array_slice(array_values(array_filter(array_unique($techs))), 0, 8));
            $summary = sprintf(
                "Trace %s — title: %s | status %s | tech: %s | seo %d security %d perf %d",
                $url, mb_substr($title, 0, 120),
                (string)($traceResult['basic']['status_code'] ?? '?'), $techs,
                (int)($traceResult['seo']['score'] ?? 0),
                (int)($traceResult['security']['score'] ?? 0),
                (int)($traceResult['performance']['performance_score'] ?? 0)
            );
            return \AgentMemory::remember($summary, ['module' => $module, 'session' => $session, 'timeout' => 8]);
        } catch (\Throwable $e) {
            return ['ok' => false, 'stored' => 'none', 'error' => $e->getMessage()];
        }
    }
}
