<?php
/**
 * SCCRM — NeuronAi service shim (layer 2 of 3: SCCRM agents).
 * Canonical logic: autoflows/app/services/NeuronAi.php
 * CEO mirror: ceo/neuron_ai.py
 * Agent bridges: sccrm/ai/neuron_agent.php (CEO), sccrm/ai/neuron_chat_agent.php (chat)
 */
if (!class_exists('NeuronAi')) {
    $canon = dirname(__DIR__, 2) . '/autoflows/app/services/NeuronAi.php';
    if (is_file($canon)) {
        require_once $canon;
    }
}

final class NeuronAiService
{
    public static function status(): array
    {
        if (!class_exists('NeuronAi')) {
            return ['ok' => false, 'driver' => 'neuron-ai', 'hint' => 'canonical service missing'];
        }
        return NeuronAi::status('sccrm');
    }

    public static function catalog(): array
    {
        return class_exists('NeuronAi') ? NeuronAi::catalog() : [];
    }

    public static function search(string $q, int $limit = 10): array
    {
        return class_exists('NeuronAi') ? NeuronAi::search($q, $limit) : ['ok' => false, 'hits' => []];
    }

    public static function ask($db, string $question): array
    {
        require_once __DIR__ . '/../ai/neuron_agent.php';
        return neuronAgentAsk($db, $question);
    }

    public static function draft($db, $ticketId, string $rag = ''): array
    {
        require_once __DIR__ . '/../ai/neuron_chat_agent.php';
        return neuronChatDraft($db, $ticketId, $rag);
    }

    public static function triage($db, string $subject, string $message): array
    {
        require_once __DIR__ . '/../ai/neuron_chat_agent.php';
        return neuronChatTriage($db, $subject, $message);
    }
}
