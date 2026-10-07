<?php
// SCCRM > AI Chat — TinyLLM model + chat framework, aligned with CEO/SCCRM/Trace.
if (session_status() === PHP_SESSION_NONE) { session_start(); }
$_sccrm_db_boot = require_once __DIR__ . '/../config/database.php';
if (($_sccrm_db_boot instanceof PDO) && (!isset($db) || !($db instanceof PDO))) { $db = $_sccrm_db_boot; }
require_once __DIR__ . '/ai_bootstrap.php';
aiEnsureTables($db);
$st = aiModelStatus();
require_once __DIR__ . '/../includes/header.php';
?>
<div class="page-title-area">
    <div>
        <h4><i class="fas fa-robot me-2 text-primary"></i>AI Chat <small class="text-muted">TinyLLM</small></h4>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="<?= htmlspecialchars($SCCRM_BASE) ?>/index.php" class="text-decoration-none">Dashboard</a></li>
                <li class="breadcrumb-item active">AI Chat</li>
            </ol>
        </nav>
    </div>
    <div class="d-flex gap-2 align-items-center">
        <span id="aiStatus" class="badge bg-<?= $st['ok'] ? 'success' : 'warning' ?>" title="<?= htmlspecialchars($st['error'] ?? $st['label']) ?>">
            <?= $st['ok'] ? '● online' : '● offline fallback' ?> · <?= htmlspecialchars($st['model']) ?>
        </span>
        <select id="aiModel" class="form-select form-select-sm" style="max-width:220px;" title="Model"></select>
    </div>
</div>

<div class="row g-3">
    <div class="col-xl-3">
        <div class="card-crm">
            <div class="card-header"><h6><i class="fas fa-comments me-2"></i>Threads</h6><button id="aiNew" class="btn btn-sm btn-primary rounded-pill"><i class="fas fa-plus"></i></button></div>
            <div class="list-group list-group-flush" id="aiThreads" style="max-height:560px;overflow-y:auto;"></div>
        </div>
        <div class="card-crm mt-3">
            <div class="card-body" style="font-size:12px;">
                <div class="fw-semibold mb-1">Try</div>
                <div class="d-flex flex-wrap gap-1">
                    <button class="btn btn-sm btn-outline-secondary rounded-pill aiq">/briefing</button>
                    <button class="btn btn-sm btn-outline-secondary rounded-pill aiq">/ceo list</button>
                    <button class="btn btn-sm btn-outline-secondary rounded-pill aiq">/leads 5</button>
                    <button class="btn btn-sm btn-outline-secondary rounded-pill aiq">/tasks</button>
                    <button class="btn btn-sm btn-outline-secondary rounded-pill aiq">/tools Website Development</button>
                    <button class="btn btn-sm btn-outline-secondary rounded-pill aiq">/status</button>
                </div>
            </div>
        </div>
    </div>
    <div class="col-xl-9">
        <div class="card-crm">
            <div class="card-body" id="aiMsgs" style="height:520px;overflow-y:auto;"></div>
            <div class="card-footer bg-transparent">
                <div class="input-group">
                    <input id="aiInput" class="form-control" placeholder="Ask TinyLLM… (try /help)" autocomplete="off">
                    <button id="aiSend" class="btn btn-primary"><i class="fas fa-paper-plane"></i> Send</button>
                </div>
                <small class="text-muted">Replies carry live CEO/CRM/Trace context. <code>/trace &lt;url&gt;</code> runs real intel.</small>
            </div>
        </div>
    </div>
</div>

<script>
(function() {
    const BASE = <?= json_encode($SCCRM_BASE) ?>;
    const API = BASE + '/ai/api.php';
    let tid = 0, busy = false;
    const msgs = document.getElementById('aiMsgs'), input = document.getElementById('aiInput');

    async function call(op, data) {
        const r = await fetch(API, {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify(Object.assign({op}, data || {}))});
        return r.json();
    }
    function esc(s) { const d = document.createElement('div'); d.textContent = s; return d.innerHTML; }
    function bubble(role, text, meta) {
        const w = document.createElement('div');
        w.className = 'mb-2 ' + (role === 'user' ? 'text-end' : '');
        w.innerHTML = '<div class="d-inline-block p-2 rounded ' + (role === 'user' ? 'bg-primary text-white' : 'bg-light border') + '" style="max-width:85%;font-size:13px;white-space:pre-wrap;text-align:left;">' + esc(text) + '</div>' +
            (meta ? '<div><small class="text-muted">' + esc(meta) + '</small></div>' : '');
        msgs.appendChild(w); msgs.scrollTop = msgs.scrollHeight;
    }
    async function loadThreads() {
        const d = await call('threads');
        const box = document.getElementById('aiThreads'); box.innerHTML = '';
        (d.threads || []).forEach(t => {
            const a = document.createElement('a');
            a.href = '#'; a.className = 'list-group-item list-group-item-action' + (t.id == tid ? ' active' : '');
            a.style.fontSize = '13px';
            a.innerHTML = esc(t.title || ('#' + t.id)) + ' <span class="badge bg-light text-dark">' + t.n + '</span>';
            a.onclick = e => { e.preventDefault(); tid = t.id; loadThreads(); loadHistory(); };
            a.ondblclick = async e => { e.preventDefault(); if (confirm('Delete thread?')) { await call('delete_thread', {thread_id: t.id}); tid = 0; loadThreads(); msgs.innerHTML = ''; } };
            box.appendChild(a);
        });
    }
    async function loadHistory() {
        msgs.innerHTML = '';
        if (!tid) { msgs.innerHTML = '<div class="text-muted" style="font-size:13px;">Pick a thread or just send a message — a new one starts automatically.</div>'; return; }
        const d = await call('history', {thread_id: tid});
        (d.messages || []).forEach(m => bubble(m.role, m.content, m.role === 'assistant' && m.model ? (m.model + (m.provider ? ' · ' + m.provider : '') + (m.ms ? ' · ' + m.ms + 'ms' : '')) : ''));
    }
    async function send() {
        const text = input.value.trim();
        if (!text || busy) return;
        busy = true; input.value = '';
        bubble('user', text);
        const think = document.createElement('div');
        think.className = 'text-muted'; think.style.fontSize = '13px'; think.textContent = 'TinyLLM thinking…';
        msgs.appendChild(think); msgs.scrollTop = msgs.scrollHeight;
        try {
            const d = await call('send', {thread_id: tid, message: text, model: document.getElementById('aiModel').value});
            think.remove();
            if (d.ok) {
                tid = d.thread_id;
                bubble('assistant', d.reply, (d.model || '') + (d.provider ? ' · ' + d.provider : '') + (d.ms ? ' · ' + d.ms + 'ms' : '') + (d.fallback ? ' · fallback' : ''));
                loadThreads();
            } else bubble('assistant', 'Error: ' + (d.error || '?'));
        } catch (e) { think.remove(); bubble('assistant', 'Error: ' + e.message); }
        busy = false;
    }
    document.getElementById('aiSend').onclick = send;
    input.addEventListener('keypress', e => { if (e.key === 'Enter') send(); });
    document.getElementById('aiNew').onclick = async () => { const d = await call('new_thread'); tid = d.thread_id; loadThreads(); loadHistory(); };
    document.querySelectorAll('.aiq').forEach(b => b.onclick = () => { input.value = b.textContent; send(); });
    (async () => {
        try {
            const d = await call('models');
            const sel = document.getElementById('aiModel');
            (d.models || []).forEach(m => {
                const o = document.createElement('option');
                o.value = m.name; o.textContent = m.name + (m.desc ? ' — ' + m.desc : '');
                sel.appendChild(o);
            });
        } catch (e) {}
        loadThreads(); loadHistory();
        // Deep-link ask: ai/?q=<question> from CEO/SCCRM dashboards auto-sends.
        const pre = new URLSearchParams(location.search).get('q');
        if (pre && pre.trim() !== '') { input.value = pre; send(); }
    })();
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
