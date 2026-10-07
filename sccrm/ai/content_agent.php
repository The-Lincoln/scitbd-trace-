<?php
/**
 * ContentAgent — TinyLLM-powered drafting agent for CEO Office + AutoFlows.
 * Pipeline: brief (live app context) → draft (TinyLLM/Ollama, offline-safe
 * fallback) → review (excerpt/tags) → save (Knowledge Bank draft).
 * Never throws: failures return ['ok'=>false,'error'=>...].
 */
require_once __DIR__ . '/ai_bootstrap.php';

function contentAgentKinds() {
    return [
        'blog' => 'SEO blog article (title, intro, H2 sections, CTA)',
        'social' => 'Social pack (LinkedIn + Facebook + TikTok variants)',
        'email' => 'Nurture email (subject, preheader, body, CTA)',
        'video' => 'Short-form video script (hook, beats, CTA, captions)',
        'briefing' => 'Daily CEO Intelligence Briefing (text, not saved)',
    ];
}

/** Build the drafting brief with live SCITBD context. */
function contentAgentBrief($db, $kind, $topic, $serviceName = '') {
    $ctx = aiAlignedContext($db);
    $services = '';
    try {
        $names = [];
        foreach ($db->query("SELECT name FROM services WHERE is_active = 1 ORDER BY name LIMIT 17") as $r) { $names[] = $r['name']; }
        if ($names) $services = ' SCITBD services: ' . implode('; ', $names) . '.';
    } catch (Throwable $e) { /* ignore */ }
    $voice = 'Voice: expert, concrete, skimmable Markdown. No invented statistics. CTA toward SCITBD contact.';
    $kindBrief = [
        'blog' => "Write an SEO blog article about: $topic. Structure: title (<60 chars), excerpt (140-160 chars), intro, 3-5 H2 sections with examples, closing CTA.",
        'social' => "Write a 3-variant social pack about: $topic — one LinkedIn post (professional), one Facebook post (warm), one TikTok caption + 5 hashtags (punchy).",
        'email' => "Write a nurture email about: $topic. Subject (<=45 chars), preheader (60-90 chars), short body, single CTA.",
        'video' => "Write a 45-second short-form video script about: $topic. Hook (0-3s), 3 beats with on-screen text, CTA, caption + hashtags.",
        'briefing' => "Write today's Daily CEO Intelligence Briefing. Cover: revenue vs \$1.5M year-end goal, new leads & proposals, ad ROAS, organic traffic, uptime, top risks, top 3 actions.",
    ];
    $serviceLine = $serviceName !== '' ? " Angle it to the service: $serviceName." : '';
    return ($kindBrief[$kind] ?? $kindBrief['blog']) . $serviceLine . ' ' . $voice . ' Context: ' . $ctx . $services;
}

/**
 * Run the agent. Saves a Knowledge Bank draft except for kind=briefing.
 * @return array{ok:bool,title:string,content:string,article_id:int,model:string,provider:string,ms:int,fallback:bool,error?:string}
 */
function contentAgentRun($db, $kind, $topic, $options = []) {
    $kinds = contentAgentKinds();
    if (!isset($kinds[$kind])) $kind = 'blog';
    $topic = trim((string)$topic);
    if ($topic === '') return ['ok' => false, 'error' => 'Topic is required'];
    $serviceId = intval($options['service_id'] ?? 0);
    $serviceName = '';
    if ($serviceId > 0) {
        try {
            $st = $db->prepare("SELECT name FROM services WHERE id = ?");
            $st->execute([$serviceId]);
            $serviceName = (string)($st->fetchColumn() ?: '');
        } catch (Throwable $e) { /* ignore */ }
    }
    try {
        $res = aiChatReply(
            [['role' => 'user', 'content' => contentAgentBrief($db, $kind, $topic, $serviceName)]],
            'You are the SCITBD content engine. ' . aiAlignedContext($db),
            $options['model'] ?? null
        );
        $content = trim($res['content'] ?? '');
        if ($content === '') return ['ok' => false, 'error' => 'Empty model reply'];
        if (!empty($res['fallback'])) {
            // Offline template replies are generic — build a real structured
            // draft from the brief instead so drafts stay useful without Ollama.
            $content = contentAgentOfflineDraft($kind, $topic, $serviceName);
        }
        // Title: first Markdown heading or first line, capped.
        $title = $topic;
        if (preg_match('/^#\s+(.+)$/m', $content, $m)) $title = trim($m[1]);
        else { $lines = preg_split('/\R/', $content, 2); $title = trim($lines[0] ?? $topic, "#* \t"); }
        $title = mb_substr($title, 0, 120) ?: $topic;
        $title = trim($title, "*`# \t");
        if ($title === '' || preg_match('/[*`]/', $title)) $title = ($kind === 'blog' ? $topic : ucfirst($kind) . ': ' . $topic);
        $plain = trim(preg_replace('/[#>*`]/', '', $content));
        $excerpt = mb_substr($plain, 0, 160);
        $articleId = 0;
        if ($kind !== 'briefing') {
            $db->prepare("INSERT INTO knowledge_articles (service_id, title, content, excerpt, tags, status, created_by) VALUES (?,?,?,?,?,'draft','TinyLLM')")
                ->execute([$serviceId ?: null, $title, $content, $excerpt, 'ai-generated,' . $kind . ($serviceName ? ',' . mb_strtolower($serviceName) : '')]);
            $articleId = (int)$db->lastInsertId();
        }
        return ['ok' => true, 'title' => $title, 'content' => $content, 'excerpt' => $excerpt,
            'article_id' => $articleId, 'model' => $res['model'], 'provider' => $res['provider'],
            'ms' => $res['ms'], 'fallback' => $res['fallback']];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

/** Deterministic offline draft (used when Ollama is unreachable). */
function contentAgentOfflineDraft($kind, $topic, $serviceName = '') {
    $svc = $serviceName !== '' ? " from SCITBD's **$serviceName** practice" : ' from SCITBD';
    $note = "> Offline draft — generated without the LLM (Ollama unreachable). Expand each section before publishing.\n\n";
    switch ($kind) {
        case 'social':
            return "# Social pack: $topic\n\n$note## LinkedIn\n$topic$svc — here is how we ship it for clients in Bangladesh, the Gulf, EU and North America. DM for a scoping call.\n\n## Facebook\nBig news for teams exploring $topic! We deliver it end-to-end — strategy, build, support. Comment or inbox us.\n\n## TikTok\nPOV: your $topic project actually ships on time. #SCITBD #Bangladesh #Tech #DigitalTransformation #AI";
        case 'email':
            return "# Email: $topic\n\n$note**Subject:** $topic — scoped in 48 hours\n\n**Preheader:** See exactly how SCITBD delivers $topic for your team.\n\n**Body:** Hi there — if $topic is on your roadmap, we can scope it this week$svc. Reply for a free 30-minute consultation and a fixed quote.\n\n**CTA:** Book your free scoping call.";
        case 'video':
            return "# Video script (45s): $topic\n\n$note**Hook (0-3s):** \"$topic — done right, in weeks not months.\"\n\n**Beat 1:** The costly mistake most teams make with $topic.\n**Beat 2:** How SCITBD delivers it$svc — show the dashboard.\n**Beat 3:** Client outcome + proof point.\n\n**CTA:** Follow + comment \"$topic\" for the free playbook.\n\n**Caption:** $topic explained in 45 seconds. #SCITBD #TikTok #Reels #Shorts";
        default: // blog (+ briefing text shape)
            return "# $topic\n\n$note## Introduction\n$topic is now a board-level priority. This article shows a practical path to value$svc.\n\n## Why it matters now\nFaster cycles, tighter budgets, higher buyer expectations — teams need $topic delivered, not discussed.\n\n## How SCITBD delivers\nDiscovery in days, fixed-scope build, aligned to measurable outcomes, plus support and training.\n\n## Next steps\nBook a free scoping call — get a fixed quote and timeline within 48 hours.";
    }
}
