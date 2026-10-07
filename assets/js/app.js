/* OSINT Framework main JS - tree, search, favorites, ratings */
(function() {
    'use strict';

    const BASE = window.OSINT?.BASE || '';
    const loggedIn = window.OSINT?.loggedIn || false;

    // ============== Tree sidebar ==============
    document.addEventListener('DOMContentLoaded', function() {
        // Toggle child nodes when clicking the chevron (not the link)
        document.querySelectorAll('.tree-toggle').forEach(function(toggle) {
            toggle.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                const node = this.closest('.tree-node');
                const children = node.querySelector('.tree-children');
                if (children) {
                    const visible = children.style.display !== 'none';
                    children.style.display = visible ? 'none' : 'block';
                    node.classList.toggle('expanded', !visible);
                }
            });
        });

        // Expand all
        const expandAll = document.getElementById('expand-all');
        if (expandAll) {
            expandAll.addEventListener('click', function() {
                document.querySelectorAll('.tree-children').forEach(c => c.style.display = 'block');
                document.querySelectorAll('.tree-node').forEach(n => n.classList.add('expanded'));
            });
        }
        // Collapse all
        const collapseAll = document.getElementById('collapse-all');
        if (collapseAll) {
            collapseAll.addEventListener('click', function() {
                document.querySelectorAll('.tree-children').forEach(c => c.style.display = 'none');
                document.querySelectorAll('.tree-node').forEach(n => n.classList.remove('expanded'));
            });
        }

        // Auto-expand active parent
        const activeNode = document.querySelector('.tree-node.active');
        if (activeNode) {
            let parent = activeNode.parentElement;
            while (parent && !parent.classList.contains('tree')) {
                if (parent.classList.contains('tree-children')) {
                    parent.style.display = 'block';
                    parent.closest('.tree-node')?.classList.add('expanded');
                }
                parent = parent.parentElement;
            }
            // Scroll into view
            activeNode.scrollIntoView({ block: 'center', behavior: 'instant' });
        }

        // Mobile tree drawer
        const mobileBtn = document.getElementById('mobile-tree-toggle');
        const modalEl = document.getElementById('mobileTreeModal');
        if (mobileBtn && modalEl) {
            mobileBtn.addEventListener('click', function() {
                const sidebar = document.getElementById('sidebar-tree');
                const target = document.getElementById('mobile-tree-container');
                if (sidebar && target) {
                    target.innerHTML = sidebar.querySelector('.tree').outerHTML;
                }
                bootstrap.Modal.getOrCreateInstance(modalEl).show();
            });
        }

        // ============== Favorite toggle ==============
        document.querySelectorAll('.btn-fav').forEach(btn => {
            btn.addEventListener('click', async function(e) {
                e.preventDefault();
                e.stopPropagation();
                if (!loggedIn) {
                    window.location.href = BASE + '/auth/login.php?redirect=' + encodeURIComponent(window.location.pathname + window.location.search);
                    return;
                }
                const toolId = this.dataset.toolId;
                const originalHTML = this.innerHTML;
                this.disabled = true;
                this.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
                try {
                    const resp = await fetch(BASE + '/api/favorite.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ tool_id: toolId })
                    });
                    const data = await resp.json();
                    if (data.success) {
                        const allBtns = document.querySelectorAll(`.btn-fav[data-tool-id="${toolId}"]`);
                        allBtns.forEach(b => b.classList.toggle('active', data.favorited));
                        const textSpan = this.querySelector('span');
                        if (textSpan) textSpan.textContent = data.favorited ? 'Favorited' : 'Add to Favorites';
                        showToast(data.message || (data.favorited ? 'Added to favorites' : 'Removed from favorites'));
                    } else {
                        showToast(data.error || 'Failed', 'error');
                    }
                } catch (err) {
                    showToast('Network error', 'error');
                } finally {
                    this.innerHTML = originalHTML;
                    this.disabled = false;
                }
            });
        });

        // ============== Rating form ==============
        const ratingForm = document.getElementById('rating-form');
        if (ratingForm) {
            ratingForm.addEventListener('submit', async function(e) {
                e.preventDefault();
                const fd = new FormData(this);
                const payload = {
                    tool_id: fd.get('tool_id'),
                    rating: fd.get('rating'),
                    review: fd.get('review')
                };
                if (!payload.rating) {
                    showToast('Please select a star rating', 'error');
                    return;
                }
                const submitBtn = this.querySelector('button[type="submit"]');
                const orig = submitBtn.innerHTML;
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Submitting...';
                try {
                    const resp = await fetch(BASE + '/api/rate.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify(payload)
                    });
                    const data = await resp.json();
                    if (data.success) {
                        showToast('Rating submitted!');
                        setTimeout(() => location.reload(), 800);
                    } else {
                        showToast(data.error || 'Failed to submit', 'error');
                    }
                } catch (err) {
                    showToast('Failed to submit rating', 'error');
                } finally {
                    submitBtn.innerHTML = orig;
                    submitBtn.disabled = false;
                }
            });
        }

        // ============== Live search hint (debounced) ==============
        const search = document.getElementById('global-search');
        if (search) {
            let timeout;
            search.addEventListener('input', function() {
                clearTimeout(timeout);
                const q = this.value.trim();
                if (q.length < 2) return;
                timeout = setTimeout(() => {
                    // Could implement live results here
                }, 300);
            });
        }

        // ============== Filter tools in current page by tag (when clicking a tag badge) ==============
        document.querySelectorAll('.badge.bg-light').forEach(tag => {
            tag.addEventListener('click', function(e) {
                // Let the link do its job - just adds visual feedback
                this.style.transform = 'scale(0.9)';
                setTimeout(() => this.style.transform = '', 150);
            });
        });
    });

    // ============== Toast helper ==============
    function showToast(msg, type) {
        const existing = document.querySelector('.app-toast');
        if (existing) existing.remove();
        const toast = document.createElement('div');
        toast.className = 'app-toast toast-' + (type || 'success');
        toast.textContent = msg;
        toast.style.cssText = `
            position: fixed; bottom: 20px; right: 20px; z-index: 9999;
            padding: 12px 20px; border-radius: 8px; color: #fff;
            background: ${type === 'error' ? '#dc2626' : '#16a34a'};
            box-shadow: 0 4px 12px rgba(0,0,0,0.2);
            font-size: 0.9rem; max-width: 320px;
            animation: fadeIn 0.2s ease-out;
        `;
        document.body.appendChild(toast);
        setTimeout(() => {
            toast.style.opacity = '0';
            toast.style.transition = 'opacity 0.3s';
            setTimeout(() => toast.remove(), 300);
        }, 2500);
    }

    window.OSINT.showToast = showToast;
})();
