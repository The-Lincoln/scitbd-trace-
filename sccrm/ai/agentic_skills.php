<?php
/**
 * Agentic Skills Top-10 — parses the cloned OWASP project
 * (external/agentic-skills-top-10/ast01..ast10.md) and maps each
 * skill-risk to how this application already mitigates it.
 */

function agenticSkillsRoot() {
    return dirname(__DIR__, 2) . '/external/agentic-skills-top-10';
}

/** App mitigations per skill (how SCCRM/Trace/AI/AutoFlows answers it). */
function agenticSkillMitigations() {
    return [
        'AST01' => 'Only first-party reviewed code runs here — chat skills execute read-only app queries, never shell. No external SKILL.md is ever loaded.',
        'AST02' => 'Upstream clones (ASVS, Amass, skills) are pinned shallow checkouts; review `git log` before updating. No package auto-installs.',
        'AST03' => 'Least privilege by design: chat API reads data; writes happen only via explicit /commands. Webhooks restricted to http(s) with 8s timeouts.',
        'AST04' => 'Skill front-matter is parsed as data (title/severity/description) and HTML-escaped on render — never executed.',
        'AST05' => 'Traced web content is quoted as *data* inside briefs and result cards — the model is instructed with app context, page text never becomes instructions.',
        'AST06' => 'TinyLLM runs on local Ollama — prompts, leads and traces never leave this machine. Offline template fallback needs no network at all.',
        'AST07' => 'Pinned versions shown in-app (ASVS 5.0, Amass module version); flow/skill changes are idempotent seed backfills, logged in autoflow_runs.',
        'AST08' => 'Every trace, flow run, CEO mutation and chat message is timestamped and listed (history tables + task_logs) — full audit trail.',
        'AST09' => 'Hot leads (85+) require CEO review via seeded flow; all CEO mutations write task_logs with actor + reason.',
        'AST10' => 'One shared vocab (statuses/priorities/categories) across SCCRM, CEO Office, AutoFlows and chat — behavior is identical on every surface.',
    ];
}

/** Parse the 10 skill files. Returns [{id, title, severity, platforms, description}]. */
function agenticSkills() {
    $root = agenticSkillsRoot();
    if (!is_dir($root)) return [];
    $mit = agenticSkillMitigations();
    $out = [];
    foreach (glob("$root/ast*.md") ?: [] as $f) {
        $raw = @file_get_contents($f);
        if ($raw === false) continue;
        $id = strtoupper(pathinfo($f, PATHINFO_FILENAME)); // AST01
        $title = $id;
        $severity = '—';
        $platforms = '—';
        if (preg_match('/^title:\s*(.+)$/m', $raw, $m)) $title = trim($m[1]);
        // Titles already carry the ID ("AST01 — Malicious Skills") — also keep a short form.
        $short = preg_replace('/^AST\d+\s*[—–-]\s*/u', '', $title);
        if ($short === null || $short === '') $short = $title;
        if (preg_match('/\*\*Severity\*\*:\s*(.+)/', $raw, $m)) $severity = trim($m[1]);
        if (preg_match('/\*\*Platforms?Affected\*\*:\s*(.+)/', $raw, $m)) $platforms = trim($m[1]);
        $desc = '';
        if (preg_match('/## Description\s+(.+?)(?=\n## |\z)/s', $raw, $m)) {
            $desc = trim(preg_replace('/\s+/', ' ', strip_tags($m[1])));
        }
        $out[] = ['id' => $id, 'title' => $title, 'short' => $short, 'severity' => $severity, 'platforms' => $platforms,
            'description' => mb_substr($desc, 0, 900), 'mitigation' => $mit[$id] ?? ''];
    }
    usort($out, function ($a, $b) { return strcmp($a['id'], $b['id']); });
    return $out;
}
