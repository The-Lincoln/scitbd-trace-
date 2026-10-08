<?php
/**
 * SCCRM content-engine-ref agent — brief proposals + evidence/adversarial checklists.
 *
 * Upstream: external/content-engine (https://github.com/salehin96/content-engine.git)
 * Canonical: autoflows/app/services/ContentEngineRef.php
 * Python mirror: ceo/content_engine_ref.py
 * Drafter (unchanged): sccrm/ai/content_agent.php (contentAgentBrief/Run)
 *
 *   $b = contentRefBrief($db, 'blog', 'Topical maps for B2B SaaS');
 *   $g = contentRefGate('draft text…');
 *   $c = contentRefReviewChecklist('https://…', ['claim 1','claim 2']);
 * Never throws. Reviewer-facing checklists — humans/lead agents judge meaning.
 */
require_once __DIR__ . '/ai_bootstrap.php';

function contentRefStatus(): array
{
    try {
        require_once dirname(__DIR__, 2) . '/autoflows/app/services/ContentEngineRef.php';
        if (class_exists('ContentEngineRef')) return ContentEngineRef::status('sccrm') + ['bridge' => 'sccrm/ai/content_engine_ref_agent.php'];
    } catch (Throwable $e) {
        return ['ok' => false, 'driver' => 'content-engine-ref', 'error' => $e->getMessage()];
    }
    return ['ok' => false, 'driver' => 'content-engine-ref', 'hint' => 'canonical service missing'];
}

/** Phase-3 brief proposal mapped to SCITBD drafter kinds. Never drafts — proposes. */
function contentRefBrief($db, string $kind, string $topic, string $serviceName = ''): array
{
    $kinds = ['blog', 'social', 'email', 'video', 'briefing'];
    if (!in_array($kind, $kinds, true)) $kind = 'blog';
    $brief = '';
    try {
        if (function_exists('contentAgentBrief')) {
            $brief = contentAgentBrief($db, $kind, $topic, $serviceName);
        }
    } catch (Throwable $e) { /* propose without live context */ }
    if ($brief === '') {
        $brief = "Write a {$kind} about: {$topic}. Evidence-only, no invented stats. CTA toward SCITBD contact.";
    }
    return ['ok' => true, 'driver' => 'content-engine-ref', 'kind' => $kind, 'topic' => $topic,
            'brief' => $brief, 'phase' => 3,
            'next' => 'Research (phase 4a) → draft with evidence only → adversarial review (4c) → gate (5).'];
}

/** Phase-5 mechanical gate. Meaning/factuality stays with reviewer agents. */
function contentRefGate(string $text): array
{
    try {
        require_once dirname(__DIR__, 2) . '/autoflows/app/services/ContentEngineRef.php';
        if (class_exists('ContentEngineRef')) return ContentEngineRef::gateCheck($text) + ['driver' => 'content-engine-ref'];
    } catch (Throwable $e) {
        return ['ok' => false, 'driver' => 'content-engine-ref', 'error' => $e->getMessage()];
    }
    $words = count(preg_split('/\s+/', trim($text)) ?: []);
    return ['ok' => $words >= 50, 'words' => $words, 'issues' => $words < 50 ? ['thin content'] : []];
}

/** Phase-4c adversarial checklist: re-verify each claim vs ranking pages before rewrite. */
function contentRefReviewChecklist(string $url, array $claims): array
{
    $items = [];
    foreach (array_values($claims) as $i => $c) {
        $c = trim((string)$c);
        if ($c === '') continue;
        $items[] = ['claim' => mb_substr($c, 0, 200), 'verify' => 're-check from scratch against live sources + top-ranking page; drop if unsupported'];
    }
    return ['ok' => true, 'driver' => 'content-engine-ref', 'url' => $url,
            'claims' => $items, 'stance' => 'skeptical — usefulness over length',
            'gate' => 'No save without rulebook-load proof + validator pass (phase 5).'];
}
