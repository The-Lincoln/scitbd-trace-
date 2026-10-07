<?php
/**
 * SCCRM — ToolJet service shim (layer 2 of 3: SCCRM agents).
 * Canonical logic: autoflows/app/services/ToolJet.php
 * CEO mirror: ceo/tooljet.py
 */
if (!class_exists('ToolJet')) {
    $canon = dirname(__DIR__, 2) . '/autoflows/app/services/ToolJet.php';
    if (is_file($canon)) {
        require_once $canon;
    }
}

final class ToolJetService
{
    public static function status(): array
    {
        return ToolJet::status();
    }

    /** Dashboard iframe URL for CRM layout ('' = app slug not configured). */
    public static function dashboardUrl(): string
    {
        return ToolJet::embedUrl('sccrm');
    }

    /** Fan out a new/updated lead to the ToolJet lead workflow (fire-and-log). */
    public static function pushLead(array $lead): array
    {
        $c = ToolJet::config();
        if ($c['webhook_lead'] === '') {
            return ['ok' => false, 'error' => 'TOOLJET_WORKFLOW_LEAD not configured'];
        }
        $r = ToolJet::triggerWorkflow($c['webhook_lead'], [
            'lead' => $lead,
            'source' => 'sccrm',
            'at' => date('c'),
        ]);
        try {
            $dbFile = dirname(__DIR__) . '/db/scit_crm.db';
            if (is_file($dbFile)) {
                $db = new PDO('sqlite:' . $dbFile);
                ToolJet::logRun($db, 'sccrm', 'webhook-lead', $c['webhook_lead'], !empty($r['ok']), (int)($r['ms'] ?? 0), mb_substr(json_encode($r['data'] ?? $r['error'] ?? ''), 0, 500));
            }
        } catch (Throwable $e) {
        }
        return $r;
    }

    public static function ensureTables(PDO $db): void
    {
        ToolJet::ensureTables($db);
    }
}
