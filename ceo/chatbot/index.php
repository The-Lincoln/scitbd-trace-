<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SCITBD AI Assistant & Knowledge Bank</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        body { background-color: #0a0d14; color: #e2e8f0; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .chat-card { background-color: #111827; border: 1px solid #1e293b; border-radius: 12px; box-shadow: 0 0 20px rgba(0, 255, 135, 0.1); }
        .chat-header { background-color: #0f172a; border-bottom: 1px solid #1e293b; border-top-left-radius: 12px; border-top-right-radius: 12px; }
        .glow-text { color: #00ff87; text-shadow: 0 0 8px rgba(0, 255, 135, 0.4); }
        .chat-box { height: 420px; overflow-y: auto; background-color: #070a10; padding: 15px; }
        .msg-bubble { max-width: 80%; padding: 10px 14px; border-radius: 10px; margin-bottom: 12px; font-size: 0.95rem; line-height: 1.4; }
        .msg-user { background-color: #00ff87; color: #050811; font-weight: 600; margin-left: auto; border-bottom-right-radius: 2px; }
        .msg-bot { background-color: #1e293b; color: #f1f5f9; border: 1px solid #334155; margin-right: auto; border-bottom-left-radius: 2px; }
        .btn-nano { background-color: #00ff87; color: #050811; font-weight: 700; border: none; transition: all 0.2s ease-in-out; }
        .btn-nano:hover { background-color: #00cc6a; box-shadow: 0 0 12px rgba(0, 255, 135, 0.5); }
        .btn-nano-outline { background-color: transparent; color: #00ff87; border: 1px solid #00ff87; transition: all 0.2s; }
        .btn-nano-outline:hover { background-color: #00ff87; color: #050811; }
        .btn-danger-nano { background-color: #ff4444; color: #fff; font-weight: 700; border: none; transition: all 0.2s; }
        .btn-danger-nano:hover { background-color: #cc2222; }
        .btn-warning-nano { background-color: #ffaa00; color: #050811; font-weight: 700; border: none; transition: all 0.2s; }
        .btn-warning-nano:hover { background-color: #dd9500; }
        .form-control-dark { background-color: #0f172a; border: 1px solid #334155; color: #ffffff; }
        .form-control-dark:focus { background-color: #0f172a; color: #ffffff; border-color: #00ff87; box-shadow: 0 0 8px rgba(0, 255, 135, 0.3); }
        .form-label { color: #00ff87; font-weight: 600; }
        .admin-card { background-color: #111827; border: 1px solid #1e293b; border-radius: 12px; box-shadow: 0 0 20px rgba(0, 255, 135, 0.1); }
        .admin-table { --bs-table-bg: #0f172a; --bs-table-color: #e2e8f0; }
        .admin-table th { background-color: #0a0d14; color: #00ff87; border-color: #1e293b; }
        .admin-table td { border-color: #1e293b; color: #e2e8f0; }
        .admin-table tr:hover td { background-color: #1e293b; }
        .tab-nav .nav-link { color: #94a3b8; border: none; padding: 12px 20px; font-weight: 600; }
        .tab-nav .nav-link.active { color: #00ff87; background-color: #0f172a; border-bottom: 2px solid #00ff87; }
        .tab-nav .nav-link:hover { color: #00ff87; }
        .badge-cat { font-size: 0.7rem; }
        .kb-search { max-width: 350px; }
        .action-btns { gap: 4px; }
    </style>
</head>
<body class="d-flex align-items-center justify-content-center min-vh-100 py-4">

<div class="container" style="max-width: 820px;">
    <div class="card chat-card">
        <div class="card-header chat-header p-3 d-flex align-items-center justify-content-between">
            <div class="d-flex align-items-center">
                <i class="fa-solid fa-robot fa-xl glow-text me-3"></i>
                <div>
                    <h5 class="mb-0 text-white fw-bold">SCITBD AI Assistant</h5>
                    <small class="text-secondary"><span class="badge bg-success text-dark p-1 me-1">24/7 ACTIVE</span> Powered by Master AI CEO Directive</small>
                </div>
            </div>
            <a href="https://scit.zya.me" target="_blank" class="btn btn-sm btn-outline-secondary text-light">scit.zya.me</a>
        </div>

        <ul class="nav tab-nav card-header p-0 justify-content-center border-bottom border-secondary" style="background-color: #0f172a;">
            <li class="nav-item">
                <a class="nav-link active" id="tabChat" href="#" onclick="switchTab('chat')"><i class="fa-solid fa-comments me-1"></i> Chat</a>
            </li>
            <li class="nav-item">
                <a class="nav-link" id="tabAdmin" href="#" onclick="switchTab('admin')"><i class="fa-solid fa-book me-1"></i> Knowledge Bank</a>
            </li>
        </ul>

        <!-- ====== CHAT PANEL ====== -->
        <div id="panelChat" class="p-0">
            <div id="chatBox" class="chat-box d-flex flex-column">
                <div class="msg-bubble msg-bot">
                    👋 Welcome to <strong>SCITBD</strong> (Social Communication IT Bangladesh).<br>
                    How can I assist your enterprise today? Ask about our <strong>17 Strategic Capabilities</strong>, <strong>Pricing</strong>, or <strong>Knowledge Bank</strong>.
                </div>
            </div>
            <div class="card-footer bg-dark border-top border-secondary p-3">
                <form id="chatForm" class="input-group">
                    <input type="text" id="userInput" class="form-control form-control-dark" placeholder="Ask about services, pricing, or knowledge base..." required autocomplete="off">
                    <button class="btn btn-nano px-4" type="submit"><i class="fa-solid fa-paper-plane me-1"></i> Send</button>
                </form>
                <div class="d-flex justify-content-between align-items-center mt-2 px-1">
                    <small class="text-muted" style="font-size: 0.75rem;">SLA Response: &lt; 2 Hours | ISO 27001 & GDPR Compliant</small>
                    <a href="https://scit.zya.me/consultation.php" target="_blank" class="text-success text-decoration-none" style="font-size: 0.75rem;">Book Zoom Call</a>
                </div>
            </div>
        </div>

        <!-- ====== ADMIN PANEL ====== -->
        <div id="panelAdmin" class="p-3 d-none">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h6 class="text-white mb-0"><i class="fa-solid fa-database me-2"></i>Knowledge Bank Manager</h6>
                <button class="btn btn-nano btn-sm" onclick="showAddModal()"><i class="fa-solid fa-plus me-1"></i> Add Entry</button>
            </div>
            <div class="input-group mb-3 kb-search">
                <input type="text" id="kbSearch" class="form-control form-control-dark" placeholder="Search knowledge bank...">
                <button class="btn btn-nano btn-sm" onclick="searchKB()"><i class="fa-solid fa-search"></i></button>
            </div>
            <div class="d-flex gap-2 mb-3">
                <select id="kbCategoryFilter" class="form-control form-control-dark" style="max-width: 180px;" onchange="loadKB()">
                    <option value="">All Categories</option>
                    <option value="General">General</option>
                    <option value="Services">Services</option>
                    <option value="Pricing">Pricing</option>
                    <option value="AI">AI & Automation</option>
                    <option value="Contact">Contact</option>
                </select>
            </div>
            <div style="max-height: 480px; overflow-y: auto;">
                <table class="table table-dark admin-table">
                    <thead>
                        <tr>
                            <th style="width:30px;">#</th>
                            <th>Question</th>
                            <th>Category</th>
                            <th style="width:140px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="kbTableBody">
                        <tr><td colspan="4" class="text-center text-muted">Loading...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- ADD/EDIT MODAL -->
<div class="modal fade" id="kbModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content bg-dark border-secondary">
            <div class="modal-header border-secondary">
                <h5 class="modal-title text-white" id="kbModalTitle"><i class="fa-solid fa-plus-circle me-2"></i>Add Knowledge Entry</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="kbEditId" value="">
                <div class="mb-3">
                    <label class="form-label">Question</label>
                    <input type="text" id="kbQuestion" class="form-control form-control-dark" placeholder="Enter question...">
                </div>
                <div class="mb-3">
                    <label class="form-label">Answer</label>
                    <textarea id="kbAnswer" class="form-control form-control-dark" rows="4" placeholder="Enter answer..."></textarea>
                </div>
                <div class="mb-3">
                    <label class="form-label">Category</label>
                    <select id="kbCategory" class="form-control form-control-dark">
                        <option value="General">General</option>
                        <option value="Services">Services</option>
                        <option value="Pricing">Pricing</option>
                        <option value="AI">AI & Automation</option>
                        <option value="Contact">Contact</option>
                    </select>
                </div>
            </div>
            <div class="modal-footer border-secondary">
                <button type="button" class="btn btn-nano-outline" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-nano" id="kbSaveBtn" onclick="saveKB()"><i class="fa-solid fa-save me-1"></i> Save</button>
            </div>
        </div>
    </div>
</div>

<!-- Bootstrap JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
    const session_id = 'sess_' + Math.random().toString(36).substring(2, 9);
    const chatBox = document.getElementById('chatBox');
    const chatForm = document.getElementById('chatForm');
    const userInput = document.getElementById('userInput');
    const kbModal = new bootstrap.Modal(document.getElementById('kbModal'));
    let kbData = [];

    function switchTab(tab) {
        document.getElementById('tabChat').classList.toggle('active', tab === 'chat');
        document.getElementById('tabAdmin').classList.toggle('active', tab === 'admin');
        document.getElementById('panelChat').classList.toggle('d-none', tab !== 'chat');
        document.getElementById('panelAdmin').classList.toggle('d-none', tab !== 'admin');
        if (tab === 'admin') loadKB();
    }

    function appendMessage(text, isUser) {
        const msgDiv = document.createElement('div');
        msgDiv.classList.add('msg-bubble', isUser ? 'msg-user' : 'msg-bot');
        msgDiv.innerHTML = text;
        chatBox.appendChild(msgDiv);
        chatBox.scrollTop = chatBox.scrollHeight;
    }

    chatForm.addEventListener('submit', async function(e) {
        e.preventDefault();
        const text = userInput.value.trim();
        if (!text) return;

        appendMessage(text, true);
        userInput.value = '';

        const typingDiv = document.createElement('div');
        typingDiv.classList.add('msg-bubble', 'msg-bot', 'text-muted');
        typingDiv.id = 'typingIndicator';
        typingDiv.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-2"></i>SCITBD AI is typing...';
        chatBox.appendChild(typingDiv);
        chatBox.scrollTop = chatBox.scrollHeight;

        try {
            const res = await fetch('api.php', {
                method: 'POST', headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ message: text, session_id: session_id })
            });
            const data = await res.json();
            const typing = document.getElementById('typingIndicator');
            if (typing) typing.remove();
            if (data.status === 'success') {
                appendMessage(data.response, false);
            } else {
                appendMessage("Error: Could not retrieve response.", false);
            }
        } catch (err) {
            const typing = document.getElementById('typingIndicator');
            if (typing) typing.remove();
            appendMessage("Server connectivity issue. Please try again later.", false);
        }
    });

    function loadKB() {
        const cat = document.getElementById('kbCategoryFilter').value;
        const search = document.getElementById('kbSearch').value;
        const params = new URLSearchParams();
        if (cat) params.set('category', cat);
        if (search) params.set('search', search);
        fetch('api.php?action=get_knowledge_bank&' + params.toString())
            .then(r => r.json())
            .then(data => {
                if (data.status === 'success') {
                    kbData = data.data;
                    renderKB(data.data);
                }
            })
            .catch(() => {});
    }

    function renderKB(entries) {
        const tbody = document.getElementById('kbTableBody');
        if (!entries.length) {
            tbody.innerHTML = '<tr><td colspan="4" class="text-center text-muted">No entries found. Add one!</td></tr>';
            return;
        }
        tbody.innerHTML = entries.map((e, i) => `
            <tr>
                <td>${i + 1}</td>
                <td><strong>${escHtml(e.question)}</strong><br><small class="text-muted">${escHtml(e.answer.substring(0, 80))}...</small></td>
                <td><span class="badge badge-cat" style="background:#1e293b; color:#00ff87;">${escHtml(e.category)}</span></td>
                <td>
                    <div class="action-btns">
                        <button class="btn btn-warning-nano btn-sm" onclick="editKB(${e.id})" title="Edit"><i class="fa-solid fa-pen"></i></button>
                        <button class="btn btn-danger-nano btn-sm" onclick="deleteKB(${e.id})" title="Delete"><i class="fa-solid fa-trash"></i></button>
                    </div>
                </td>
            </tr>
        `).join('');
    }

    function searchKB() { loadKB(); }

    function showAddModal() {
        document.getElementById('kbModalTitle').innerHTML = '<i class="fa-solid fa-plus-circle me-2"></i>Add Knowledge Entry';
        document.getElementById('kbEditId').value = '';
        document.getElementById('kbQuestion').value = '';
        document.getElementById('kbAnswer').value = '';
        document.getElementById('kbCategory').value = 'General';
        kbModal.show();
    }

    function editKB(id) {
        const entry = kbData.find(e => e.id === id);
        if (!entry) return;
        document.getElementById('kbModalTitle').innerHTML = '<i class="fa-solid fa-pen me-2"></i>Edit Knowledge Entry';
        document.getElementById('kbEditId').value = id;
        document.getElementById('kbQuestion').value = entry.question;
        document.getElementById('kbAnswer').value = entry.answer;
        document.getElementById('kbCategory').value = entry.category;
        kbModal.show();
    }

    function saveKB() {
        const id = document.getElementById('kbEditId').value;
        const question = document.getElementById('kbQuestion').value.trim();
        const answer = document.getElementById('kbAnswer').value.trim();
        const category = document.getElementById('kbCategory').value;
        if (!question || !answer) { alert('Question and answer are required.'); return; }

        const url = id ? 'api.php' : 'api.php';
        const method = 'POST';
        const body = id
            ? JSON.stringify({ action: 'update_knowledge', id: parseInt(id), question, answer, category })
            : JSON.stringify({ action: 'add_knowledge', question, answer, category });

        fetch(url, { method, headers: { 'Content-Type': 'application/json' }, body })
            .then(r => r.json())
            .then(data => {
                if (data.status === 'success') {
                    kbModal.hide();
                    loadKB();
                } else {
                    alert('Error: ' + (data.message || 'Failed to save.'));
                }
            })
            .catch(() => alert('Network error.'));
    }

    function deleteKB(id) {
        if (!confirm('Are you sure you want to delete this knowledge entry?')) return;
        fetch('api.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'delete_knowledge', id: id })
        })
            .then(r => r.json())
            .then(data => {
                if (data.status === 'success') {
                    loadKB();
                } else {
                    alert('Error: ' + (data.message || 'Failed to delete.'));
                }
            })
            .catch(() => alert('Network error.'));
    }

    function escHtml(str) {
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    loadKB();
</script>
</body>
</html>