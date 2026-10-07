<?php
/**
 * SCCRM ticket reply AI — "Generate AI reply" for support tickets.
 * Pattern ported from external/ai-response-generator (osTicket plugin:
 * OpenAI-compatible chat/completions + system prompt + RAG context +
 * response template), rewired to SCCRM tickets + TinyLLM with an optional
 * OpenAI-compatible override.
 *
 *   $draft = ticketReplySuggest($db, $ticketId); // ['ok','draft','provider']
 * Never throws. Drafts only — the agent reviews and sends.
 */
require_once __DIR__ . '/ai_bootstrap.php';

function ticketReplyConfig(): array
{
    return [
        // Optional OpenAI-compatible override (mirrors the osTicket plugin config).
        'api_url' => getenv('TICKET_AI_API_URL') ?: '',
        'api_key' => getenv('TICKET_AI_API_KEY') ?: '',
        'model' => getenv('TICKET_AI_MODEL') ?: '',
        'system' => getenv('TICKET_AI_SYSTEM') ?: 'You are SCITBD support. Write a short, warm, concrete reply that answers the customer. No invented facts. End with one clear next step.',
        'max_tokens' => 512,
        'timeout' => 25,
    ];
}

/** Direct OpenAI-compatible chat call (plugin's OpenAIClient pattern). */
function ticketReplyViaApi(array $messages, array $cfg): array
{
    $url = rtrim($cfg['api_url'], '/');
    if (!preg_match('#/chat/(completions|complete)$#', $url)) {
        $url .= '/chat/completions';
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $cfg['timeout'],
        CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json'], $cfg['api_key'] ? ['Authorization: Bearer ' . $cfg['api_key']] : []),
        CURLOPT_POSTFIELDS => json_encode(['model' => $cfg['model'], 'messages' => $messages, 'temperature' => 0.2, 'max_tokens' => $cfg['max_tokens']]),
    ]);
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($resp === false || $resp === '') {
        return ['ok' => false, 'error' => 'API unreachable: ' . $err];
    }
    $j = json_decode($resp, true);
    if ($code >= 400) {
        return ['ok' => false, 'error' => 'API HTTP ' . $code . ': ' . mb_substr($j['error']['message'] ?? $resp, 0, 200)];
    }
    $text = trim($j['choices'][0]['message']['content'] ?? '');
    return $text !== '' ? ['ok' => true, 'draft' => $text, 'provider' => 'openai-compatible'] : ['ok' => false, 'error' => 'Empty model reply'];
}

/**
 * Suggest a reply for a support ticket.
 * @return array{ok:bool,draft:string,provider:string,error?:string}
 */
function ticketReplySuggest($db, $ticketId, string $rag = ''): array
{
    try {
        $t = ['subject' => '(unknown)', 'message' => ''];
        try {
            $st = $db->prepare("SELECT subject, message FROM support_tickets WHERE id = ?");
            $st->execute([$ticketId]);
            if ($r = $st->fetch(PDO::FETCH_ASSOC)) {
                $t = $r;
            }
            $hist = $db->prepare("SELECT sender, message FROM support_messages WHERE ticket_id = ? ORDER BY created_at ASC LIMIT 12");
            $hist->execute([$ticketId]);
            $lines = [];
            foreach ($hist->fetchAll(PDO::FETCH_ASSOC) as $m) {
                $lines[] = (($m['sender'] ?? 'them') . ': ' . mb_substr($m['message'] ?? '', 0, 400));
            }
            if ($lines) {
                $t['message'] .= "\n\nConversation so far:\n" . implode("\n", $lines);
            }
        } catch (Throwable $e) {
        }
        $cfg = ticketReplyConfig();
        $messages = [
            ['role' => 'system', 'content' => $cfg['system']],
            ['role' => 'user', 'content' => "Ticket subject: {$t['subject']}\n\n{$t['message']}" . ($rag !== '' ? "\n\nExtra context:\n{$rag}" : '')],
        ];
        // Plugin-style override first (when fully configured), else local TinyLLM.
        if ($cfg['api_url'] !== '' && $cfg['model'] !== '' && function_exists('curl_init')) {
            $r = ticketReplyViaApi($messages, $cfg);
            if (!empty($r['ok'])) {
                return $r;
            }
            // Fall through to TinyLLM on API failure (offline-safe).
        }
        $res = aiChatReply($messages, 'You are SCITBD support. ' . aiAlignedContext($db));
        $draft = trim($res['content'] ?? '');
        if ($draft === '') {
            return ['ok' => false, 'draft' => '', 'provider' => 'none', 'error' => 'Empty model reply'];
        }
        return ['ok' => true, 'draft' => $draft, 'provider' => $res['provider'] ?? 'tinyllm'];
    } catch (Throwable $e) {
        return ['ok' => false, 'draft' => '', 'provider' => 'none', 'error' => $e->getMessage()];
    }
}
