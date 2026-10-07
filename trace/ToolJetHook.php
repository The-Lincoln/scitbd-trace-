<?php
/**
 * OSINT ToolJetHook — workflow fan-out for URL traces (layer 3: app).
 *
 * pushTrace($url, $traceResult): POST a trace summary to the configured
 * TOOLJET_WORKFLOW_TRACE webhook (lead scoring, Slack, sheets…). No-op when
 * unconfigured. Never throws, never slows the trace (short timeout).
 */
namespace OSINT;

class ToolJetHook
{
    public static function ensure(): bool
    {
        if (!class_exists('ToolJet')) {
            $canon = dirname(__DIR__) . '/autoflows/app/services/ToolJet.php';
            if (is_file($canon)) {
                require_once $canon;
            }
        }
        return class_exists('ToolJet');
    }

    public static function pushTrace(string $url, array $traceResult, $pdo = null, string $module = 'trace'): array
    {
        try {
            if (!self::ensure()) {
                return ['ok' => false, 'fanout' => 'none'];
            }
            $c = \ToolJet::config();
            if ($c['webhook_trace'] === '') {
                return ['ok' => false, 'fanout' => 'none', 'note' => 'TOOLJET_WORKFLOW_TRACE not configured'];
            }
            $title = (string)($traceResult['content']['title'] ?? '-');
            $r = \ToolJet::triggerWorkflow($c['webhook_trace'], [
                'url' => $url,
                'title' => mb_substr($title, 0, 200),
                'status_code' => $traceResult['basic']['status_code'] ?? null,
                'seo' => (int)($traceResult['seo']['score'] ?? 0),
                'security' => (int)($traceResult['security']['score'] ?? 0),
                'source' => 'trace_app',
                'at' => date('c'),
            ], ['timeout' => 10]);
            if ($pdo instanceof \PDO) {
                \ToolJet::logRun($pdo, $module, 'webhook-trace', $c['webhook_trace'], !empty($r['ok']), (int)($r['ms'] ?? 0), mb_substr(json_encode($r['data'] ?? $r['error'] ?? ''), 0, 500));
            }
            return $r + ['fanout' => 'tooljet-workflow'];
        } catch (\Throwable $e) {
            return ['ok' => false, 'fanout' => 'none', 'error' => $e->getMessage()];
        }
    }
}
