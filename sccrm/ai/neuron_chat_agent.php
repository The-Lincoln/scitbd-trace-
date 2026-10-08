<?php
/**
 * SCCRM Neuron chat agents — NeuronAI wiring for support chat (tickets,
 * WhatsApp/Evolution, lead follow-ups).
 *
 * Upstream: external/neuron-ai (https://github.com/neuron-core/neuron-ai.git)
 * Canonical service: autoflows/app/services/NeuronAi.php
 * CEO bridge: sccrm/ai/neuron_agent.php
 * Python mirror: ceo/neuron_ai.py
 *
 *   $draft = neuronChatDraft($db, $ticketId, $rag);   // ticket reply draft
 *   $tri   = neuronChatTriage($db, $subject, $msg);   // dept/priority/escalate
 *   $wa    = neuronChatWhatsapp($db, $incomingText);   // WhatsApp-style reply
 *
 * Rules (mirror ticket-ai safety):
 *   - Drafts only — agent reviews and sends. Never auto-send.
 *   - RAG = ticket history (last 12) + contact/company + optional $rag note.
 *   - Neuron native path when runnable; else TinyLLM fallback (offline-safe).
 *   - Human-in-the-loop: tool calls / approvals surface as proposed actions.
 * Never throws.
 */
require_once __DIR__ . '/ai_bootstrap.php';
require_once __DIR__ . '/neuron_agent.php';

function neuronChatStatus(): array
{
    $s = neuronAgentStatus();
    $s['bridge'] = 'sccrm/ai/neuron_chat_agent.php';
    $s['chat_wiring'] = 'sccrm/chat/view.php (Generate AI reply) + sccrm/chat/whatsapp.php';
    return $s;
}

function neuronChatContext($db, $ticketId): array
{
    $ctx = ['subject' => '(unknown)', 'thread' => '', 'contact' => ''];
    try {
        $st = $db->prepare("SELECT subject, message FROM support_tickets WHERE id = ?");
        $st->execute([$ticketId]);
        if ($r = $st->fetch(PDO::FETCH_ASSOC)) {
            $ctx['subject'] = $r['subject'] ?? '(unknown)';
            $ctx['thread'] = (string)($r['message'] ?? '');
        }
        try {
            $h = $db->prepare("SELECT sender, message FROM support_messages WHERE ticket_id = ? ORDER BY created_at ASC LIMIT 12");
            $h->execute([$ticketId]);
            $lines = [];
            foreach ($h->fetchAll(PDO::FETCH_ASSOC) as $m) {
                $lines[] = (($m['sender'] ?? 'them') . ': ' . mb_substr($m['message'] ?? '', 0, 400));
            }
            if ($lines) $ctx['thread'] .= "\n\nConversation so far:\n" . implode("\n", $lines);
        } catch (Throwable $e) {}
        try {
            $c = $db->prepare("SELECT c.first_name, c.last_name, co.name AS company FROM support_tickets t LEFT JOIN contacts c ON t.contact_id=c.id LEFT JOIN companies co ON t.company_id=co.id WHERE t.id=?");
            $c->execute([$ticketId]);
            if ($r = $c->fetch(PDO::FETCH_ASSOC)) {
                $ctx['contact'] = trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? '')) . (($r['company'] ?? '') !== '' ? ' @ ' . $r['company'] : '');
            }
        } catch (Throwable $e) {}
    } catch (Throwable $e) {}
    return $ctx;
}

/** Draft a ticket reply (Neuron-first, TinyLLM fallback). */
function neuronChatDraft($db, $ticketId, string $rag = ''): array
{
    $ctx = neuronChatContext($db, $ticketId);
    $prompt = "Ticket subject: {$ctx['subject']}\n"
        . ($ctx['contact'] !== '' ? "Customer: {$ctx['contact']}\n" : '')
        . "\n{$ctx['thread']}"
        . ($rag !== '' ? "\n\nExtra context:\n{$rag}" : '')
        . "\n\nWrite a short, warm, concrete support reply. No invented facts. End with one clear next step.";
    $r = neuronAgentAsk($db, $prompt,
        'You are SCITBD support (Neuron chat agent). Drafts only — an agent reviews before sending.');
    if (empty($r['ok'])) return ['ok' => false, 'draft' => '', 'provider' => $r['driver'] ?? 'none', 'error' => $r['error'] ?? 'draft failed'];
    return ['ok' => true, 'draft' => $r['text'], 'provider' => ($r['driver'] === 'neuron-ai' ? 'neuron-ai' : ($r['provider'] ?? 'tinyllm'))];
}

/** Triage: department + priority + escalate flag (structured). */
function neuronChatTriage($db, string $subject, string $message): array
{
    $schema = ['department' => 'general|technical|billing|sales|support',
               'priority' => 'low|normal|high|urgent',
               'escalate' => true, 'summary' => 'one-line summary'];
    $r = neuronAgentExtract($db, "Subject: {$subject}\n\n{$message}", $schema);
    if (!empty($r['ok'])) {
        $d = $r['data'];
        return ['ok' => true, 'driver' => $r['driver'],
                'department' => $d['department'] ?? 'general',
                'priority' => $d['priority'] ?? 'normal',
                'escalate' => !empty($d['escalate']),
                'summary' => $d['summary'] ?? mb_substr($subject, 0, 120)];
    }
    // Heuristic fallback (offline-safe).
    $hay = mb_strtolower($subject . ' ' . $message);
    $dept = str_contains($hay, 'bill') || str_contains($hay, 'invoice') ? 'billing'
        : (str_contains($hay, 'sale') || str_contains($hay, 'price') || str_contains($hay, 'quote') ? 'sales'
        : (str_contains($hay, 'error') || str_contains($hay, 'bug') || str_contains($hay, 'api') ? 'technical' : 'support'));
    $urgent = (bool)preg_match('/\burgent\b|asap|immediately|down|critical|refund|angry/i', $hay);
    return ['ok' => true, 'driver' => 'heuristic-fallback', 'department' => $dept,
            'priority' => $urgent ? 'urgent' : 'normal', 'escalate' => $urgent,
            'summary' => mb_substr($subject, 0, 120)];
}

/** WhatsApp-style short reply (Evolution inbox helper). */
function neuronChatWhatsapp($db, string $incomingText, string $leadName = ''): array
{
    $prompt = ($leadName !== '' ? "Lead: {$leadName}\n" : '') . "Incoming WhatsApp:\n{$incomingText}\n\nReply in ≤ 3 short sentences, warm + concrete, one question to move the deal forward.";
    $r = neuronAgentAsk($db, $prompt, 'You are SCITBD sales chat (Neuron). Short WhatsApp voice. No invented facts.');
    if (empty($r['ok'])) return ['ok' => false, 'reply' => '', 'error' => $r['error'] ?? 'reply failed'];
    return ['ok' => true, 'reply' => $r['text'], 'driver' => $r['driver']];
}
