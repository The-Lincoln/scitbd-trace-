<?php
// SCCRM > AI > Skills Library — every agent integration in one place,
// each with a live status dot. Never breaks the page: every probe is
// guarded (missing wrapper = grey, platform down = amber).
if (session_status() === PHP_SESSION_NONE) { session_start(); }
$_sccrm_db_boot = require_once __DIR__ . '/../config/database.php';
if (($_sccrm_db_boot instanceof PDO) && (!isset($db) || !($db instanceof PDO))) { $db = $_sccrm_db_boot; }

$osintRoot = dirname(__DIR__, 2);
foreach ([
    $osintRoot . '/autoflows/app/services/AgentBrowser.php',
    $osintRoot . '/autoflows/app/services/BrowserSkill.php',
    $osintRoot . '/autoflows/app/services/AgentMemory.php',
    $osintRoot . '/autoflows/app/services/OpenViking.php',
    $osintRoot . '/autoflows/app/services/EvolutionApi.php',
    $osintRoot . '/autoflows/app/services/ToolJet.php',
    $osintRoot . '/autoflows/app/services/OpenMontage.php',
    $osintRoot . '/autoflows/app/services/DiagramDesign.php',
    $osintRoot . '/autoflows/app/services/HarnessGuide.php',
    $osintRoot . '/autoflows/app/services/ScientificSkills.php',
    $osintRoot . '/autoflows/app/services/BrowserUseKey.php',
    __DIR__ . '/clarity_agent.php',
] as $f) {
    if (is_file($f)) {
        require_once $f;
    }
}

function libProbe(callable $fn) {
    try {
        $r = $fn();
        if (is_array($r)) {
            return $r;
        }
    } catch (Throwable $e) {
    }
    return ['ok' => false, 'hint' => 'wrapper missing'];
}

$groups = [
    'Content & Writing' => [
        ['name' => 'Clarity', 'desc' => 'Co-write / rewrite / review / lint reader-facing prose; never invents facts. Lint fully offline.', 'page' => '../knowledge/', 'probe' => fn() => function_exists('clarityAgentStatus') ? clarityAgentStatus() : ['ok' => false], 'base' => 'sccrm'],
    ],
    'Browser & Trace' => [
        ['name' => 'Agent Browser', 'desc' => 'Rendered Chromium via agent-browser CLI (open, read, snapshot, shot).', 'page' => 'trace/agent_browser.php', 'probe' => fn() => class_exists('AgentBrowser') ? AgentBrowser::status('sccrm') : ['ok' => false]],
        ['name' => 'BrowserSkill (bsk)', 'desc' => 'Logged-in Chromium via Tencent bsk + extension; website-debugging evidence.', 'page' => 'trace/agent_browser.php', 'probe' => fn() => class_exists('BrowserSkill') ? BrowserSkill::status('sccrm') : ['ok' => false]],
        ['name' => 'BrowserUse Cloud', 'desc' => 'Cloud browser key (bu_…) for -p browseruse sessions.', 'page' => null, 'probe' => fn() => class_exists('BrowserUseKey') ? ['ok' => BrowserUseKey::has(), 'hint' => BrowserUseKey::masked()] : ['ok' => false]],
    ],
    'Memory & Knowledge' => [
        ['name' => 'Agent Memory', 'desc' => 'TencentDB team memory hub: chat, skills, wiki, code-graph.', 'page' => null, 'probe' => fn() => class_exists('AgentMemory') ? AgentMemory::status('sccrm') : ['ok' => false]],
        ['name' => 'OpenViking', 'desc' => 'viking:// context filesystem: ls/tree/find/grep over memories & docs.', 'page' => null, 'probe' => fn() => class_exists('OpenViking') ? OpenViking::status() : ['ok' => false]],
        ['name' => 'Scientific Skills', 'desc' => '177 validated research skills: databases, papers, stats, writing.', 'page' => null, 'probe' => fn() => class_exists('ScientificSkills') ? ScientificSkills::status() : ['ok' => false]],
        ['name' => 'Harness Guide', 'desc' => 'Curated harness-engineering reading (hooks, MCP, evals).', 'page' => null, 'probe' => fn() => class_exists('HarnessGuide') ? HarnessGuide::status() : ['ok' => false]],
    ],
    'Messaging' => [
        ['name' => 'Evolution API', 'desc' => 'WhatsApp: QR connect, inbox, lead outreach, CEO alerts.', 'page' => 'chat/whatsapp.php', 'probe' => fn() => class_exists('EvolutionApi') ? EvolutionApi::status() : ['ok' => false]],
        ['name' => 'Ticket AI + Triage', 'desc' => 'AI draft replies + auto-classification with human review queue.', 'page' => 'chat/', 'probe' => fn() => ['ok' => true, 'hint' => 'TinyLLM local-first']],
    ],
    'Video & Media' => [
        ['name' => 'OpenMontage', 'desc' => 'Agentic video productions: 13 pipelines, gates, budgets.', 'page' => '../video/', 'probe' => fn() => class_exists('OpenMontage') ? OpenMontage::status() : ['ok' => false], 'base' => 'sccrm'],
        ['name' => 'Diagram Design', 'desc' => '44 editorial diagram types as HTML+SVG for reports.', 'page' => null, 'probe' => fn() => class_exists('DiagramDesign') ? DiagramDesign::status() : ['ok' => false]],
    ],
    'Dashboards & Apps' => [
        ['name' => 'ToolJet', 'desc' => 'Low-code KPI/lead/trace apps + workflow webhooks.', 'page' => 'dashboards/tooljet.php', 'probe' => fn() => class_exists('ToolJet') ? ToolJet::status() : ['ok' => false]],
    ],
];

require_once __DIR__ . '/../includes/header.php';

function libDot($st) {
    if (!is_array($st) || !array_key_exists('ok', $st)) {
        return '<span class="badge bg-secondary">unknown</span>';
    }
    if (!empty($st['ok'])) {
        return '<span class="badge bg-success">● ready</span>';
    }
    // Honest middle state: functional offline (AgentMemory local cache).
    if (($st['mode'] ?? '') === 'local') {
        return '<span class="badge bg-info text-dark">● local' . (isset($st['local_notes']) ? ' (' . (int)$st['local_notes'] . ')' : '') . '</span>';
    }
    return '<span class="badge bg-warning text-dark">● offline</span>';
}

function libSub($st) {
    if (!is_array($st)) {
        return '';
    }
    if (!empty($st['missing'])) {
        return 'Missing: ' . mb_substr($st['missing'], 0, 160);
    }
    return mb_substr($st['hint'] ?? '', 0, 160);
}
?>
<div class="page-title-area">
    <div>
        <h4><i class="fas fa-layer-group me-2 text-primary"></i>Skills Library</h4>
        <nav aria-label="breadcrumb"><ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="<?= htmlspecialchars($SCCRM_BASE) ?>/index.php" class="text-decoration-none">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="<?= htmlspecialchars($SCCRM_BASE) ?>/ai/" class="text-decoration-none">AI Chat</a></li>
            <li class="breadcrumb-item active">Skills Library</li>
        </ol></nav>
    </div>
    <div><a href="<?= htmlspecialchars($SCCRM_BASE) ?>/ai/skills.php" class="btn btn-outline-warning btn-sm rounded-pill"><i class="fas fa-shield-virus me-1"></i> Agentic Skills Top-10</a></div>
</div>

<?php foreach ($groups as $gname => $items): ?>
<h6 class="text-muted text-uppercase small mt-3 mb-2"><i class="fas fa-folder-open me-1"></i><?= htmlspecialchars($gname) ?></h6>
<div class="row g-3 mb-2">
    <?php foreach ($items as $it):
        $st = libProbe($it['probe']);
        $href = null;
        if (!empty($it['page'])) {
            $href = str_starts_with($it['page'], '../')
                ? htmlspecialchars($SCCRM_BASE) . '/' . ltrim($it['page'], './')
                : htmlspecialchars($SCCRM_BASE) . '/' . $it['page'];
        }
    ?>
    <div class="col-xl-4 col-md-6"><div class="card-crm h-100"><div class="card-body" style="font-size:13px;">
        <div class="d-flex justify-content-between align-items-center mb-1">
            <strong><?= htmlspecialchars($it['name']) ?></strong><?= libDot($st) ?>
        </div>
        <p class="text-muted mb-2"><?= htmlspecialchars($it['desc']) ?></p>
        <?php $sub = libSub($st); if ($sub !== ''): ?><div class="text-muted small mb-2"><?= htmlspecialchars($sub) ?></div><?php endif; ?>
        <?php if ($href): ?><a href="<?= $href ?>" class="btn btn-sm btn-outline-primary rounded-pill">Open <i class="fas fa-arrow-right ms-1"></i></a><?php endif; ?>
    </div></div></div>
    <?php endforeach; ?>
</div>
<?php endforeach; ?>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
