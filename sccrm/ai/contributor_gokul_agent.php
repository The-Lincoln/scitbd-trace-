<?php
/**
 * SCCRM contributor-gokul agent — task-to-stack matching + assignment proposals.
 *
 * Upstream: external/Gokul-ram-j (https://github.com/The-Lincoln/Gokul-ram-j.git)
 * Canonical: autoflows/app/services/ContributorGokul.php
 * Python mirror: ceo/contributor_gokul.py
 *
 *   $m = contributorGokulMatch('Build RAG lead-search UI in React');
 *   $p = contributorGokulPropose($db, $ticketId, 'Fix Expo mobile crash');
 * Never throws. Proposals only — human assigns.
 */
require_once __DIR__ . '/ai_bootstrap.php';

function contributorGokulStatus(): array
{
    try {
        require_once dirname(__DIR__, 2) . '/autoflows/app/services/ContributorGokul.php';
        if (class_exists('ContributorGokul')) return ContributorGokul::status('sccrm') + ['bridge' => 'sccrm/ai/contributor_gokul_agent.php'];
    } catch (Throwable $e) {
        return ['ok' => false, 'driver' => 'contributor-gokul', 'error' => $e->getMessage()];
    }
    return ['ok' => false, 'driver' => 'contributor-gokul', 'hint' => 'canonical service missing'];
}

function contributorGokulMatch(string $text): array
{
    try {
        require_once dirname(__DIR__, 2) . '/autoflows/app/services/ContributorGokul.php';
        if (class_exists('ContributorGokul')) return ContributorGokul::matchTask($text) + ['driver' => 'contributor-gokul'];
    } catch (Throwable $e) {
        return ['ok' => false, 'driver' => 'contributor-gokul', 'error' => $e->getMessage()];
    }
    return ['ok' => false, 'driver' => 'contributor-gokul', 'error' => 'service missing'];
}

function contributorGokulPropose($db, $ticketId, string $note = ''): array
{
    $subject = ''; $message = '';
    try {
        $st = $db->prepare("SELECT subject, message FROM support_tickets WHERE id = ?");
        $st->execute([$ticketId]);
        if ($r = $st->fetch(PDO::FETCH_ASSOC)) { $subject = (string)($r['subject'] ?? ''); $message = (string)($r['message'] ?? ''); }
    } catch (Throwable $e) {}
    $m = contributorGokulMatch($subject . ' ' . $message . ' ' . $note);
    if (empty($m['ok'])) return $m;
    $m['ticket_id'] = $ticketId;
    $m['assignee_candidate'] = 'gokul-ram-j';
    $m['action'] = 'Propose only — human assigns in SCCRM.';
    return $m;
}
