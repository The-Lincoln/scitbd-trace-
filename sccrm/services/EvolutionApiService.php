<?php
/**
 * SCCRM — EvolutionApi service shim (layer 2 of 3: SCCRM agents).
 * Canonical logic: autoflows/app/services/EvolutionApi.php
 * CEO mirror: ceo/evolution_api.py
 */
if (!class_exists('EvolutionApi')) {
    $canon = dirname(__DIR__, 2) . '/autoflows/app/services/EvolutionApi.php';
    if (is_file($canon)) {
        require_once $canon;
    }
}

final class EvolutionApiService
{
    public static function status(): array
    {
        return EvolutionApi::status();
    }

    /** QR payload for the CRM connect panel (operator scans with WhatsApp). */
    public static function connectQr(): array
    {
        return EvolutionApi::connectQr();
    }

    /**
     * Point the instance webhook at this app's receiver.
     * $publicBase = e.g. https://scit.zya.me/trace (no trailing slash).
     * Receiver: {base}/sccrm/chat/evolution_webhook.php (+?secret= when set).
     */
    public static function registerInbound(string $publicBase, ?string $secret = null): array
    {
        $secret = $secret ?? (string)(getenv('EVOLUTION_WEBHOOK_SECRET') ?: '');
        $url = rtrim($publicBase, '/') . '/sccrm/chat/evolution_webhook.php'
            . ($secret !== '' ? '?secret=' . urlencode($secret) : '');
        $over = $secret !== '' ? ['headers' => ['x-webhook-secret' => $secret]] : [];
        return EvolutionApi::setWebhook($url, EvolutionApi::INBOUND_EVENTS, null, $over);
    }

    /** Templated lead follow-up via WhatsApp (fire-and-log, never blocks CRM). */
    public static function sendLeadMessage(string $phone, string $name, string $context = ''): array
    {
        $text = "Hello {$name}, this is SCITBD. " . ($context !== '' ? $context . ' ' : '')
            . "Reply here if you'd like a free 30-minute scoping call.";
        $r = EvolutionApi::sendText($phone, $text);
        try {
            $dbFile = dirname(__DIR__) . '/db/scit_crm.db';
            if (is_file($dbFile)) {
                $db = new PDO('sqlite:' . $dbFile);
                EvolutionApi::logRun($db, 'sccrm', 'sendText-lead', $phone, !empty($r['ok']), (int)($r['ms'] ?? 0), mb_substr(json_encode($r['data'] ?? $r['error'] ?? ''), 0, 500));
            }
        } catch (Throwable $e) {
        }
        return $r;
    }

    public static function ensureTables(PDO $db): void
    {
        EvolutionApi::ensureTables($db);
    }
}
