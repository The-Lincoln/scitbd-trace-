<?php
/**
 * SCCRM ToolJet AI agent — dashboard context + lead fan-out via workflows.
 * Companion to sccrm/ai/agent_memory_agent.php (memory) and
 * sccrm/ai/browserskill_agent.php (browser).
 *
 *   $st  = tooljetAgentStatus();
 *   $res = tooljetAgentPushLead($db, ['name' => 'Acme', 'value' => 12000]);
 * Never throws: failures return ['ok'=>false,'error'=>...].
 */
require_once __DIR__ . '/ai_bootstrap.php';
require_once dirname(__DIR__) . '/services/ToolJetService.php';

function tooljetAgentStatus(): array
{
    try {
        return ToolJetService::status() + ['driver' => 'tooljet'];
    } catch (Throwable $e) {
        return ['ok' => false, 'driver' => 'tooljet', 'error' => $e->getMessage()];
    }
}

function tooljetAgentPushLead($db, array $lead): array
{
    try {
        $r = ToolJetService::pushLead($lead);
        return $r + ['driver' => 'tooljet'];
    } catch (Throwable $e) {
        return ['ok' => false, 'driver' => 'tooljet', 'error' => $e->getMessage()];
    }
}
