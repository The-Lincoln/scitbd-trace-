<?php
/**
 * SCCRM — AgentMemory service shim (layer 2 of 3: SCCRM agents).
 * Canonical logic: autoflows/app/services/AgentMemory.php
 * CEO mirror: ceo/agent_memory.py
 */
if (!class_exists('AgentMemory')) {
    $canon = dirname(__DIR__, 2) . '/autoflows/app/services/AgentMemory.php';
    if (is_file($canon)) {
        require_once $canon;
    }
}

final class AgentMemoryService
{
    public static function status(): array
    {
        return AgentMemory::status('sccrm');
    }

    /** Recall lead/company context before scoring or outreach. */
    public static function recallLead(string $company, string $extra = '', ?string $session = null): array
    {
        return AgentMemory::recall(trim($company . ' ' . $extra), ['module' => 'sccrm', 'session' => $session, 'limit' => 5]);
    }

    /** Persist lead-research outcome (session = lead/task id when available). */
    public static function rememberLead(string $text, ?string $session = null): array
    {
        return AgentMemory::remember($text, ['module' => 'sccrm', 'session' => $session ?? '']);
    }

    public static function ensureTables(PDO $db): void
    {
        AgentMemory::ensureTables($db);
    }
}
