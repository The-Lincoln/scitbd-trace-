</main>
<footer class="footer py-4 mt-5">
    <div class="container-fluid">
        <div class="row align-items-center">
            <div class="col-md-6">
                <span class="text-muted">
                    <i class="bi bi-bug-fill"></i>
                    <?= h(SITE_NAME) ?> &middot; Built with PHP + SQLite + Bootstrap
                </span>
            </div>
            <div class="col-md-6 text-md-end">
                <span class="text-muted small">
                    <a href="https://osintframework.com" target="_blank" rel="noopener">Original OSINT Framework</a>
                    &middot;
                    <a href="<?= $BASE ?>/api-docs.php">API Docs</a>
                    &middot;
                    <a href="<?= $BASE ?>/admin/login.php">Admin</a>
                </span>
            </div>
        </div>
    </div>
</footer>

<!-- Bootstrap Bundle -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<!-- App JS -->
<script>
window.OSINT = {
    BASE: '<?= $BASE ?>',
    loggedIn: <?= is_logged_in() ? 'true' : 'false' ?>,
    theme: '<?= h($THEME) ?>'
};
</script>
<script src="<?= $BASE ?>/assets/js/theme.js"></script>
<script src="<?= $BASE ?>/assets/js/app.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const chatToggle = document.getElementById('chat-toggle');
    const chatBody = document.getElementById('chat-body');
    const chatInput = document.getElementById('chat-input');
    const chatSend = document.getElementById('chat-send');
    const chatMessages = document.getElementById('chat-messages');

    // Chat tab switching
    window.switchChatTab = function(tab) {
        const osintContent = document.getElementById('chat-tab-osint-content');
        const phpmlContent = document.getElementById('chat-tab-phpml-content');
        const osintTab = document.getElementById('chat-tab-osint');
        const phpmlTab = document.getElementById('chat-tab-phpml');
        
        if (tab === 'phpml') {
            osintContent.style.display = 'none';
            phpmlContent.style.display = 'block';
            osintTab.classList.remove('btn-primary');
            osintTab.classList.add('btn-outline-light');
            phpmlTab.classList.remove('btn-outline-light');
            phpmlTab.classList.add('btn-primary');
        } else {
            osintContent.style.display = 'block';
            phpmlContent.style.display = 'none';
            phpmlTab.classList.remove('btn-primary');
            phpmlTab.classList.add('btn-outline-light');
            osintTab.classList.remove('btn-outline-light');
            osintTab.classList.add('btn-primary');
        }
    };

    // OSINT Chat Toggle
    if (chatToggle && chatBody) {
        chatToggle.addEventListener('click', function() {
            chatBody.classList.toggle('collapsed');
            const icon = chatToggle.querySelector('i');
            if (icon) {
                icon.className = chatBody.classList.contains('collapsed') ? 'fas fa-plus' : 'fas fa-minus';
            }
        });
    }

    // OSINT Chat - Add message
    function addMessage(containerId, text, sender) {
        const container = document.getElementById(containerId);
        if (!container) return;
        const msgDiv = document.createElement('div');
        msgDiv.className = 'chat-msg ' + sender;
        msgDiv.innerHTML = '<div class="chat-bubble">' + text.replace(/</g, '&lt;').replace(/>/g, '&gt;') + '</div>';
        container.appendChild(msgDiv);
        container.scrollTop = container.scrollHeight;
    }

    // OSINT Chat - Send message
    function sendMessage() {
        const msg = chatInput.value.trim();
        if (!msg) return;
        addMessage('chat-messages', msg, 'user');
        chatInput.value = '';
        chatSend.disabled = true;
        chatSend.innerHTML = '<span class="chat-loading">...</span>';

        fetch('<?= $BASE ?>/api/chat.php?message=' + encodeURIComponent(msg))
            .then(r => r.json())
            .then(data => {
                addMessage('chat-messages', data.response || 'No response', 'ai');
            })
            .catch(e => addMessage('chat-messages', 'Error: ' + e.message, 'ai'))
            .finally(() => {
                chatSend.disabled = false;
                chatSend.innerHTML = '<i class="fas fa-paper-plane"></i>';
            });
    }

    if (chatSend) chatSend.addEventListener('click', sendMessage);
    if (chatInput) chatInput.addEventListener('keypress', function(e) { if (e.key === 'Enter') sendMessage(); });

    // ===== phpML Chat =====
    const phpmlInput = document.getElementById('phpml-input');
    const phpmlSend = document.getElementById('phpml-send');
    const phpmlMessages = document.getElementById('phpml-messages');

    function addPhpMLMessage(text, sender) {
        const msgDiv = document.createElement('div');
        msgDiv.className = 'chat-msg ' + sender;
        
        // Check if response contains skills data (JSON-like)
        if (text.includes('Available Skills by Category:') || text.includes('Found') && text.includes('skills matching')) {
            msgDiv.innerHTML = '<div class="chat-bubble">' + text.replace(/</g, '&lt;').replace(/>/g, '&gt;') + '</div>';
        } else {
            msgDiv.innerHTML = '<div class="chat-bubble">' + text.replace(/</g, '&lt;').replace(/>/g, '&gt;') + '</div>';
        }
        phpmlMessages.appendChild(msgDiv);
        phpmlMessages.scrollTop = phpmlMessages.scrollHeight;
    }
    
    // Add skills panel toggle button
    function addPhpMLSkillsPanel() {
        const panelBtn = document.createElement('button');
        panelBtn.className = 'btn btn-sm btn-outline-primary phpml-skills-panel-btn';
        panelBtn.id = 'phpml-skills-panel-btn';
        panelBtn.innerHTML = '<i class="fas fa-book-open"></i> Skills';
        panelBtn.title = 'Toggle Skills Panel';
        phpmlMessages.appendChild(panelBtn);
        
        panelBtn.addEventListener('click', function() {
            const panel = document.getElementById('phpml-skills-panel');
            if (panel) {
                panel.classList.toggle('visible');
                this.innerHTML = panel.classList.contains('visible') 
                    ? '<i class="fas fa-eye-slash"></i> Hide Skills' 
                    : '<i class="fas fa-book-open"></i> Skills';
            }
        });
        
        // Create skills panel
        const panel = document.createElement('div');
        panel.id = 'phpml-skills-panel';
        panel.className = 'phpml-skills-panel';
        panel.innerHTML = '<div class="phpml-skills-panel-header"><strong>📚 Agent Skills Catalog</strong> <small>Click a skill to execute</small></div><div class="phpml-skills-panel-content">Loading skills...</div>';
        phpmlMessages.appendChild(panel);
        
        // Load skills panel content
        fetch('<?= $BASE ?>/api/phpml_chat.php?message=skills')
            .then(r => r.json())
            .then(data => {
                const content = panel.querySelector('.phpml-skills-panel-content');
                if (data.success && data.response) {
                    content.innerHTML = '<pre style="margin:0;white-space:pre-wrap;font-size:0.7rem;">' + data.response.replace(/</g, '&lt;').replace(/>/g, '&gt;') + '</pre>';
                } else {
                    content.innerHTML = '<p style="color:var(--text-muted);font-size:0.75rem;">Skills loaded with ' + (data.skills?.total_skills || 0) + ' skills.</p>';
                }
            })
            .catch(() => {
                panel.querySelector('.phpml-skills-panel-content').innerHTML = '<p style="color:var(--text-muted);font-size:0.75rem;">Failed to load skills. Try typing "skills" in the chat.</p>';
            });
    }

    function addPhpMLLoading() {
        const msgDiv = document.createElement('div');
        msgDiv.className = 'chat-msg ai';
        msgDiv.id = 'phpml-loading';
        msgDiv.innerHTML = '<div class="chat-bubble chat-loading"><i class="fas fa-spinner fa-spin"></i> phpML processing...</div>';
        phpmlMessages.appendChild(msgDiv);
        phpmlMessages.scrollTop = phpmlMessages.scrollHeight;
    }

    function removePhpMLLoading() {
        const loading = document.getElementById('phpml-loading');
        if (loading) loading.remove();
    }

    // Add quick suggestion buttons to phpML
    function addPhpMLQuickActions() {
        const quickDiv = document.createElement('div');
        quickDiv.className = 'phpml-quick-actions';
        quickDiv.id = 'phpml-quick-actions';
        quickDiv.innerHTML = `
            <button class="btn btn-sm btn-outline-primary phpml-quick" data-msg="stats">📊 Stats</button>
            <button class="btn btn-sm btn-outline-success phpml-quick" data-msg="models">🧠 Models</button>
            <button class="btn btn-sm btn-outline-info phpml-quick" data-msg="skills">📚 Skills</button>
            <button class="btn btn-sm btn-outline-warning phpml-quick" data-msg="search skills predict">🔍 Search Skills</button>
            <button class="btn btn-sm btn-outline-danger phpml-quick" data-msg="predict 5">🔮 Predict</button>
            <button class="btn btn-sm btn-outline-secondary phpml-quick" data-msg="analyze 5 10 15 20 25">📈 Analyze</button>
            <button class="btn btn-sm btn-outline-info phpml-quick" data-msg="clusters">📊 Clusters</button>
            <button class="btn btn-sm btn-outline-dark phpml-quick" data-msg="openclaw">🦞 OpenClaw</button>
        `;
        phpmlMessages.appendChild(quickDiv);
        
        quickDiv.querySelectorAll('.phpml-quick').forEach(btn => {
            btn.addEventListener('click', function() {
                phpmlInput.value = this.dataset.msg;
                sendPhpMLMessage();
            });
        });
    }

    function sendPhpMLMessage() {
        const msg = phpmlInput.value.trim();
        if (!msg) return;
        addPhpMLMessage(msg, 'user');
        phpmlInput.value = '';
        phpmlSend.disabled = true;
        phpmlSend.innerHTML = '<span class="chat-loading"><i class="fas fa-spinner fa-spin"></i></span>';
        addPhpMLLoading();

        fetch('<?= $BASE ?>/api/phpml_chat.php?message=' + encodeURIComponent(msg))
            .then(r => r.json())
            .then(data => {
                removePhpMLLoading();
                if (data.success) {
                    addPhpMLMessage(data.response, 'ai');
                } else {
                    addPhpMLMessage('Error: ' + (data.error || 'Unknown error'), 'ai');
                }
            })
            .catch(e => {
                removePhpMLLoading();
                addPhpMLMessage('Error: ' + e.message, 'ai');
            })
            .finally(() => {
                phpmlSend.disabled = false;
                phpmlSend.innerHTML = '<i class="fas fa-brain"></i>';
            });
    }

    if (phpmlSend) phpmlSend.addEventListener('click', sendPhpMLMessage);
    if (phpmlInput) phpmlInput.addEventListener('keypress', function(e) { if (e.key === 'Enter') sendPhpMLMessage(); });

    // Initialize phpML quick actions
    addPhpMLQuickActions();
    addPhpMLSkillsPanel();
});
</script>
</body>
</html>
