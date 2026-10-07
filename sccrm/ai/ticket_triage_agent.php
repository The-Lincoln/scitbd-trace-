<?php
/**
 * SCCRM ticket triage AI — classify + confidence + human escalation.
 * Pattern ported from external/ai-support-operations (Node/Express + Gemini:
 * /api/analyze → {category, confidence, escalate}, KB-keyword retrieval,
 * safety guards), rewired to SCCRM tickets + TinyLLM with a deterministic
 * keyword fallback (offline-safe, same safety rules).
 *
 * Categories: Authentication | Billing | Technical | How-to | Unknown.
 * Safety (upstream parity): billing/refunds, account security, data deletion
 * and other sensitive requests ALWAYS escalate; KB-insufficient → Unknown +
 * escalate. Results stored in ticket_triage (UNIQUE ticket_id) — no schema
 * change to support_tickets.
 *
 *   $t = ticketTriage($db, $ticketId); // ['ok','category','confidence','escalate','reason','provider']
 * Never throws. Triage advises routing — humans decide.
 */
require_once __DIR__ . '/ai_bootstrap.php';

function ticketTriageCategories(): array
{
    return ['Authentication', 'Billing', 'Technical', 'How-to', 'Unknown'];
}

/** KB candidates from the Knowledge Bank (title/tags overlap, upstream line-512 pattern). */
function ticketTriageKB($db, string $text, int $limit = 3): array
{
    $out = [];
    try {
        $words = array_values(array_filter(preg_split('/\s+/', mb_strtolower($text)) ?: [], fn($w) => mb_strlen($w) > 3));
        if (empty($words)) {
            return $out;
        }
        $rows = $db->query("SELECT id, title, excerpt, tags FROM knowledge_articles WHERE COALESCE(title,'') != '' ORDER BY id DESC LIMIT 60")->fetchAll(PDO::FETCH_ASSOC);
        $scored = [];
        foreach ($rows as $r) {
            $hay = mb_strtolower(($r['title'] ?? '') . ' ' . ($r['excerpt'] ?? '') . ' ' . ($r['tags'] ?? ''));
            $score = 0;
            foreach ($words as $w) {
                if (str_contains($hay, $w)) {
                    $score += mb_strlen($w);
                }
            }
            if ($score > 0) {
                $scored[] = ['score' => $score, 'title' => $r['title'], 'excerpt' => mb_substr($r['excerpt'] ?? '', 0, 300)];
            }
        }
        usort($scored, fn($a, $b) => $b['score'] <=> $a['score']);
        $out = array_slice($scored, 0, max(1, min($limit, 5)));
    } catch (Throwable $e) {
    }
    return $out;
}

/** Deterministic fallback classifier (same safety guards as upstream). */
function ticketTriageHeuristic(string $text): array
{
    $t = mb_strtolower($text);
    $sensitive = ['refund', 'chargeback', 'delete my', 'delete account', 'close my account', 'hacked', 'breach', 'stolen', 'fraud', 'gdpr', 'privacy', 'password reset'];
    $isSensitive = false;
    foreach ($sensitive as $s) {
        if (str_contains($t, $s)) {
            $isSensitive = true;
            break;
        }
    }
    $cat = 'Unknown';
    $conf = 35;
    $rules = [
        'Billing' => ['bill', 'invoice', 'charge', 'payment', 'refund', 'subscription', 'pricing', 'receipt'],
        'Authentication' => ['login', 'log in', 'sign in', 'password', 'otp', '2fa', 'two-factor', 'auth', 'account locked', 'reset'],
        'Technical' => ['error', 'bug', 'crash', 'broken', 'api', 'integration', 'slow', 'timeout', '500', '404', 'fail'],
        'How-to' => ['how to', 'how do', 'tutorial', 'guide', 'setup', 'configure', 'learn', 'docs'],
    ];
    foreach ($rules as $c => $keys) {
        foreach ($keys as $k) {
            if (str_contains($t, $k)) {
                $cat = $c;
                $conf = 65;
                break 2;
            }
        }
    }
    return [
        'category' => $cat,
        'confidence' => $isSensitive ? max($conf, 70) : $conf,
        'escalate' => $isSensitive || $cat === 'Unknown',
        'reason' => $isSensitive ? 'Sensitive request — human review required.' : ($cat === 'Unknown' ? 'No KB match — human review required.' : 'Keyword routing (offline fallback).'),
        'provider' => 'heuristic',
    ];
}

function ticketTriageEnsureTable($db): void
{
    $db->exec("CREATE TABLE IF NOT EXISTS ticket_triage (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        ticket_id INTEGER NOT NULL UNIQUE,
        category TEXT NOT NULL DEFAULT 'Unknown',
        confidence INTEGER NOT NULL DEFAULT 0,
        escalate INTEGER NOT NULL DEFAULT 1,
        reason TEXT NOT NULL DEFAULT '',
        provider TEXT NOT NULL DEFAULT '',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
}

/**
 * Triage a ticket (stored result unless $force). Never throws.
 * @return array{ok:bool,category:string,confidence:int,escalate:bool,reason:string,provider:string}
 */
function ticketTriage($db, $ticketId, bool $force = false): array
{
    try {
        ticketTriageEnsureTable($db);
        if (!$force) {
            $st = $db->prepare("SELECT * FROM ticket_triage WHERE ticket_id = ?");
            $st->execute([$ticketId]);
            if ($r = $st->fetch(PDO::FETCH_ASSOC)) {
                return ['ok' => true, 'category' => $r['category'], 'confidence' => (int)$r['confidence'], 'escalate' => (bool)$r['escalate'], 'reason' => $r['reason'], 'provider' => $r['provider']];
            }
        }
        $st = $db->prepare("SELECT subject, message FROM support_tickets WHERE id = ?");
        $st->execute([$ticketId]);
        $t = $st->fetch(PDO::FETCH_ASSOC) ?: ['subject' => '', 'message' => ''];
        $text = trim(($t['subject'] ?? '') . "\n" . ($t['message'] ?? ''));
        if ($text === '') {
            return ['ok' => false, 'category' => 'Unknown', 'confidence' => 0, 'escalate' => true, 'reason' => 'Empty ticket.', 'provider' => 'none'];
        }
        $kb = ticketTriageKB($db, $text);
        $kbBlock = '';
        foreach ($kb as $k) {
            $kbBlock .= "- {$k['title']}: {$k['excerpt']}\n";
        }
        $res = ticketTriageHeuristic($text);
        try {
            $prompt = "Classify this support ticket. Reply with JSON ONLY: {\"category\": one of Authentication|Billing|Technical|How-to|Unknown, \"confidence\": 0-100, \"escalate\": true|false, \"reason\": \"short\"}.\n"
                . "Rules: billing/refunds, account security, data deletion or sensitive requests → escalate=true. No KB match → category Unknown + escalate=true.\n"
                . ($kbBlock !== '' ? "Knowledge base:\n{$kbBlock}\n" : "Knowledge base: (empty — lean Unknown+escalate when unsure)\n")
                . "Ticket:\n{$text}";
            $ai = aiChatReply([['role' => 'user', 'content' => $prompt]], 'You are a support triage classifier. JSON only, no prose.');
            if (empty($ai['fallback']) && preg_match('/\{.*\}/s', $ai['content'] ?? '', $m)) {
                $j = json_decode($m[0], true);
                if (is_array($j) && in_array($j['category'] ?? '', ticketTriageCategories(), true)) {
                    $res = [
                        'category' => $j['category'],
                        'confidence' => max(0, min(100, (int)($j['confidence'] ?? 50))),
                        'escalate' => !empty($j['escalate']),
                        'reason' => mb_substr((string)($j['reason'] ?? ''), 0, 300),
                        'provider' => $ai['provider'] ?? 'tinyllm',
                    ];
                    // Safety net stays on even when the model says otherwise.
                    $heur = ticketTriageHeuristic($text);
                    if ($heur['escalate']) {
                        $res['escalate'] = true;
                        $res['reason'] = trim($res['reason'] . ' [Safety guard: human review required.]');
                    }
                }
            }
        } catch (Throwable $e) {
        }
        $db->prepare("INSERT OR REPLACE INTO ticket_triage (ticket_id, category, confidence, escalate, reason, provider) VALUES (?,?,?,?,?,?)")
            ->execute([$ticketId, $res['category'], $res['confidence'], $res['escalate'] ? 1 : 0, $res['reason'], $res['provider']]);
        return ['ok' => true] + $res;
    } catch (Throwable $e) {
        return ['ok' => false, 'category' => 'Unknown', 'confidence' => 0, 'escalate' => true, 'reason' => $e->getMessage(), 'provider' => 'none'];
    }
}

/** Tickets awaiting human review (escalate=1, ticket still open-ish). */
function ticketTriageQueue($db, int $limit = 25): array
{
    try {
        ticketTriageEnsureTable($db);
        $st = $db->prepare("SELECT t.id, t.subject, t.status, t.priority, tr.category, tr.confidence, tr.reason, tr.created_at
            FROM ticket_triage tr JOIN support_tickets t ON t.id = tr.ticket_id
            WHERE tr.escalate = 1 AND t.status NOT IN ('resolved','closed')
            ORDER BY tr.id DESC LIMIT ?");
        $st->execute([$limit]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }
}
