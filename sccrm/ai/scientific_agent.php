<?php
/**
 * SCCRM Scientific Skills AI agent — research skill discovery + loading.
 * Companion to sccrm/ai/openviking_agent.php (context) and
 * sccrm/ai/agent_memory_agent.php (team memory).
 *
 *   $rec = sciskillRecommend($db, 'market size dermatology devices');
 *   $bod = sciskillLoad($db, 'database-lookup');
 * Never throws: failures return ['ok'=>false,'error'=>...].
 */
require_once __DIR__ . '/ai_bootstrap.php';
require_once dirname(__DIR__) . '/services/ScientificSkillsService.php';

function sciskillRecommend($db, string $task, int $limit = 5): array
{
    try {
        $r = ScientificSkillsService::recommend($task, $limit);
        return $r + ['driver' => 'scientific-skills'];
    } catch (Throwable $e) {
        return ['ok' => false, 'driver' => 'scientific-skills', 'error' => $e->getMessage(), 'hits' => []];
    }
}

function sciskillLoad($db, string $id): array
{
    try {
        $r = ScientificSkillsService::load($id, $db instanceof PDO ? $db : null);
        return $r + ['driver' => 'scientific-skills'];
    } catch (Throwable $e) {
        return ['ok' => false, 'driver' => 'scientific-skills', 'error' => $e->getMessage()];
    }
}
