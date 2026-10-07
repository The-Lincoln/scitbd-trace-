<?php
/**
 * SCCRM Evolution AI agent — WhatsApp lead outreach via Evolution API.
 * Companion to sccrm/ai/tooljet_agent.php (dashboards) and
 * sccrm/ai/agent_memory_agent.php (memory).
 *
 *   $st  = evolutionAgentStatus();
 *   $res = evolutionAgentOutreach($db, $phone, $name, $context);
 * Never throws: failures return ['ok'=>false,'error'=>...].
 */
require_once __DIR__ . '/ai_bootstrap.php';
require_once dirname(__DIR__) . '/services/EvolutionApiService.php';

function evolutionAgentStatus(): array
{
    try {
        return EvolutionApiService::status() + ['driver' => 'evolution-api'];
    } catch (Throwable $e) {
        return ['ok' => false, 'driver' => 'evolution-api', 'error' => $e->getMessage()];
    }
}

function evolutionAgentOutreach($db, string $phone, string $name, string $context = ''): array
{
    try {
        $r = EvolutionApiService::sendLeadMessage($phone, $name, $context);
        return $r + ['driver' => 'evolution-api'];
    } catch (Throwable $e) {
        return ['ok' => false, 'driver' => 'evolution-api', 'error' => $e->getMessage()];
    }
}
