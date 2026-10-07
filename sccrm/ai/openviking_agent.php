<?php
/**
 * SCCRM OpenViking AI agent — viking:// recall + ingest for lead workflows.
 * Companion to sccrm/ai/agent_memory_agent.php (TencentDB memory) and
 * sccrm/ai/tooljet_agent.php (dashboards).
 *
 *   $ctx = openvikingAgentRecall($db, 'Acme Corp renewal risk');
 *   $res = openvikingAgentIndex($db, 'https://github.com/org/repo');
 * Never throws: failures return ['ok'=>false,'error'=>...].
 */
require_once __DIR__ . '/ai_bootstrap.php';
require_once dirname(__DIR__) . '/services/OpenVikingService.php';

function openvikingAgentRecall($db, string $query, ?string $scope = null): array
{
    try {
        $r = OpenVikingService::recallLead($query, $scope);
        return $r + ['driver' => 'openviking'];
    } catch (Throwable $e) {
        return ['ok' => false, 'driver' => 'openviking', 'error' => $e->getMessage(), 'text' => ''];
    }
}

function openvikingAgentIndex($db, string $source): array
{
    try {
        $r = OpenVikingService::indexSource($source);
        return $r + ['driver' => 'openviking'];
    } catch (Throwable $e) {
        return ['ok' => false, 'driver' => 'openviking', 'error' => $e->getMessage()];
    }
}
