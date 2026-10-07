<?php
/**
 * SCCRM AgentMemory AI agent — team-memory recall/remember for lead workflows.
 * Companion to sccrm/ai/browserskill_agent.php (browser driver) and
 * sccrm/ai/content_agent.php (drafting driver).
 *
 *   $ctx = agentMemoryRecall($db, 'Acme Corp renewal risk');
 *   $res = agentMemoryRemember($db, 'Acme uses old auth module — do not refactor', $leadId);
 * Never throws: failures return ['ok'=>false,'error'=>...].
 */
require_once __DIR__ . '/ai_bootstrap.php';
require_once dirname(__DIR__) . '/services/AgentMemoryService.php';

function agentMemoryRecall($db, string $query, ?string $session = null, int $limit = 5): array
{
    try {
        $r = AgentMemoryService::recallLead($query, '', $session);
        if (!empty($r['ok'])) {
            $r['ms'] = $r['ms'] ?? 0;
        }
        return $r + ['driver' => 'tencentdb-agent-memory'];
    } catch (Throwable $e) {
        return ['ok' => false, 'driver' => 'tencentdb-agent-memory', 'error' => $e->getMessage(), 'context' => ''];
    }
}

function agentMemoryRemember($db, string $text, ?string $session = null): array
{
    try {
        $r = AgentMemoryService::rememberLead($text, $session);
        return $r + ['driver' => 'tencentdb-agent-memory'];
    } catch (Throwable $e) {
        return ['ok' => false, 'driver' => 'tencentdb-agent-memory', 'error' => $e->getMessage(), 'stored' => 'none'];
    }
}
