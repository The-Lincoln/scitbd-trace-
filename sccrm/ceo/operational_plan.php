<?php
/**
 * SCITBD AI CEO Daily Operational Plan — 24-hour BST (UTC+6) cycle.
 * Four regional blocks x four execution slots = 16 operational tasks.
 * Times are BST wall-clock; due dates resolve to today 23:30 Asia/Dhaka.
 */
function ceoOperationalPlan() {
    return [
        // Block 1: 06:00 – 12:00 BST | South Asia & Domestic
        ['block' => 1, 'start' => '06:00', 'end' => '07:00', 'region' => 'BD / South Asia',
         'title' => 'SEO & Local Content Deploy',
         'detail' => 'Publish 1 SEO-optimized blog post across SCITBD 17 capabilities. Post morning updates on LinkedIn, Facebook, TikTok for South Asian audiences.',
         'outcome' => 'Organic indexing + early regional traffic + brand visibility.', 'category' => 'marketing', 'priority' => 'high'],
        ['block' => 1, 'start' => '07:00', 'end' => '09:00', 'region' => 'BD / South Asia',
         'title' => 'Domestic & Regional RFP Scrapers',
         'detail' => 'Run scrapers on Bangladesh e-GP + South Asia procurement portals. Flag matching RFPs, trigger Proposal Factory drafts.',
         'outcome' => 'Tenders caught on release, bids drafted fast.', 'category' => 'sales', 'priority' => 'high'],
        ['block' => 1, 'start' => '09:00', 'end' => '11:00', 'region' => 'BD / South Asia',
         'title' => 'BD Lead Nurturing & Pipeline',
         'detail' => 'Deploy lead-nurturing email workflows (Touchpoints 1-3). Update CRM records for active South Asian accounts.',
         'outcome' => 'Warm prospects advance, active contact maintained.', 'category' => 'sales', 'priority' => 'medium'],
        ['block' => 1, 'start' => '11:00', 'end' => '12:00', 'region' => 'BD / South Asia',
         'title' => 'Brand SLA & Review Audit',
         'detail' => 'Monitor Trustpilot, Google Business, local directories. Auto-reply positives; escalate rating <40 / negative mention to CEO queue within 1 hour.',
         'outcome' => 'Reputation protected, rapid resolution.', 'category' => 'client', 'priority' => 'critical'],
        // Block 2: 12:00 – 18:00 BST | Middle East & Europe
        ['block' => 2, 'start' => '12:00', 'end' => '13:30', 'region' => 'Middle East / EU',
         'title' => 'Ad Engine Performance Audit',
         'detail' => 'Audit Google Search + LinkedIn ads (UAE, KSA, UK, DE, FR). Auto-pause campaigns with negative ROAS 3 days running.',
         'outcome' => 'Wasted spend cut, capital to winners.', 'category' => 'marketing', 'priority' => 'high'],
        ['block' => 2, 'start' => '13:30', 'end' => '16:00', 'region' => 'Middle East / EU',
         'title' => 'MEA & EU B2B Prospecting',
         'detail' => 'Contact 10-12 CTOs/CIOs daily via Sales Navigator + cold email. Focus: Custom ERP, Cloud Transformation, AI Solutions.',
         'outcome' => 'Predictable high-value enterprise pipeline.', 'category' => 'sales', 'priority' => 'high'],
        ['block' => 2, 'start' => '16:00', 'end' => '17:30', 'region' => 'Middle East / EU',
         'title' => 'European Content Engine',
         'detail' => 'Publish Blog Post #2 for UK/EU queries. Distribute gated case studies / whitepapers for lead capture.',
         'outcome' => 'EU organic footprint + qualified downloads.', 'category' => 'marketing', 'priority' => 'medium'],
        ['block' => 2, 'start' => '17:30', 'end' => '18:00', 'region' => 'Middle East / EU',
         'title' => 'Retainer & Upsell Automations',
         'detail' => 'Dispatch upsell sequences to EU clients on 1-2 service lines only.',
         'outcome' => 'Higher LTV, retainers over one-offs.', 'category' => 'sales', 'priority' => 'medium'],
        // Block 3: 18:00 – 00:00 BST | UK & North America
        ['block' => 3, 'start' => '18:00', 'end' => '19:30', 'region' => 'UK / North America',
         'title' => 'US Market Campaign Launch',
         'detail' => 'Launch refreshed Facebook Carousel + LinkedIn sets for US/CA SMEs. A/B test AI Product Suite offers.',
         'outcome' => 'NA buyers engaged at day-start with trial offers.', 'category' => 'marketing', 'priority' => 'high'],
        ['block' => 3, 'start' => '19:30', 'end' => '22:00', 'region' => 'UK / North America',
         'title' => 'High-Value Proposals & Pipeline',
         'detail' => 'Process NA/UK inquiries. Custom technical proposals within sub-2-hour SLA. Leads >= $10k: CEO video proposal within 24h.',
         'outcome' => 'High conversion on high-ticket contracts.', 'category' => 'sales', 'priority' => 'critical'],
        ['block' => 3, 'start' => '22:00', 'end' => '23:30', 'region' => 'UK / North America',
         'title' => 'US Content & Video Engine',
         'detail' => 'Publish Blog Post #3 for NA search. Distribute short-form video to TikTok, Reels, YouTube Shorts (10k views/video goal).',
         'outcome' => 'High-intent US traffic + view velocity.', 'category' => 'marketing', 'priority' => 'medium'],
        ['block' => 3, 'start' => '23:30', 'end' => '00:00', 'region' => 'UK / North America',
         'title' => 'Client Support & System Health Audit',
         'detail' => 'Verify support response times (sub-2h SLA). Audit live hosting for 99.9% uptime compliance.',
         'outcome' => 'Reliability upheld, churn prevented.', 'category' => 'operations', 'priority' => 'high'],
        // Block 4: 00:00 – 06:00 BST | Oceania & Reboot
        ['block' => 4, 'start' => '00:00', 'end' => '02:00', 'region' => 'Oceania / Global',
         'title' => 'Daily Campaign Optimization',
         'detail' => 'Analyze Google/Meta/LinkedIn/TikTok performance. Reallocate budgets to top performers.',
         'outcome' => 'Max blended marketing ROAS.', 'category' => 'marketing', 'priority' => 'medium'],
        ['block' => 4, 'start' => '02:00', 'end' => '04:00', 'region' => 'Oceania / Global',
         'title' => 'Oceania Market Operations',
         'detail' => 'Trigger B2B outreach for Australian SMEs at their day-open. Scan Australian tender databases.',
         'outcome' => 'Early-mover edge in AU/NZ.', 'category' => 'sales', 'priority' => 'medium'],
        ['block' => 4, 'start' => '04:00', 'end' => '05:30', 'region' => 'Oceania / Global',
         'title' => 'Daily CEO Intelligence Summary',
         'detail' => 'Compile briefing: revenue vs $1.5M goal, new leads & proposals, ad ROAS, organic traffic, uptime.',
         'outcome' => 'Executive real-time visibility.', 'category' => 'admin', 'priority' => 'high'],
        ['block' => 4, 'start' => '05:30', 'end' => '06:00', 'region' => 'Oceania / Global',
         'title' => 'System Flush & Reset',
         'detail' => 'Clear caches, refresh automation queues, sync workflows for the 06:00 BST loop.',
         'outcome' => 'Zero latency, continuous 24/7 run.', 'category' => 'operations', 'priority' => 'medium'],
    ];
}

/** Non-negotiable SLA standards (trigger, target, protocol). */
function ceoSlaStandards() {
    return [
        ['trigger' => 'Client Support Ticket', 'target' => '< 2 hours', 'protocol' => 'Auto-route to technical team'],
        ['trigger' => 'Inbound Lead / RFP', 'target' => 'Proposal < 2 hours', 'protocol' => 'Proposal Factory automation'],
        ['trigger' => 'Lead value >= $10,000', 'target' => 'Action < 24 hours', 'protocol' => 'Personalized CEO video message'],
        ['trigger' => 'Negative brand review', 'target' => 'Action < 1 hour', 'protocol' => 'CEO review queue notification'],
        ['trigger' => 'NPS score < 40', 'target' => 'Action < 48 hours', 'protocol' => 'Emergency review meeting'],
        ['trigger' => 'Hosted uptime', 'target' => '99.9% guarantee', 'protocol' => 'Instant ops engineering alert'],
    ];
}

/** Canonical daily_task title for a plan slot (idempotency key). */
function ceoPlanTaskTitle($slot) {
    return '[Block ' . $slot['block'] . ' ' . $slot['start'] . '-' . $slot['end'] . ' BST] ' . $slot['title'];
}
