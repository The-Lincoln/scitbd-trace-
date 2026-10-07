/* ==========================================================================
   AutoFlows — content library: selection, bulk status, quick actions
   ========================================================================== */
(function () {
  'use strict';

  const bulkCard = AF.$('#bulkCard');
  const bulkBtn  = AF.$('#bulkBar');
  const bulkCnt  = AF.$('#bulkCount');
  if (!bulkCard) return;

  function picked() { return AF.$$('.item-pick:checked').map(c => parseInt(c.value, 10)); }

  function refresh() {
    const n = picked().length;
    bulkCnt.textContent = n;
    bulkBtn.disabled = n === 0;
    bulkCard.classList.toggle('d-none', n === 0);
  }

  document.addEventListener('change', function (e) {
    if (e.target.closest('.item-pick')) refresh();
  });

  bulkBtn.addEventListener('click', function () {
    AF.toast(picked().length + ' selected — choose a status below.', 'info', 'ℹ');
    bulkCard.scrollIntoView({ behavior: 'smooth', block: 'center' });
  });

  /* --------------------------------------------------------- bulk set ----- */
  AF.$$('.bulk-set').forEach(function (b) {
    b.addEventListener('click', function () {
      const ids = picked();
      if (!ids.length) return;
      const when = AF.$('#bulkWhen').value;

      if (b.dataset.status === 'scheduled' && !when) {
        AF.toast('Pick a publish time first.', 'warning', '⚠');
        AF.$('#bulkWhen').focus();
        return;
      }
      b.disabled = true;

      AF.api('api/content/status', {
        ids: ids,
        status: b.dataset.status,
        publish_at: when ? when.replace('T', ' ') + ':00' : ''
      }).then(function (r) {
        b.disabled = false;
        if (!r.ok) { AF.toast(r.error, 'danger', '✕'); return; }
        AF.toast(r.changed + ' item(s) → ' + r.status + '.', 'success', '✓');
        setTimeout(function () { location.reload(); }, 500);
      });
    });
  });

  /* ------------------------------------------------------ bulk delete ----- */
  const delBtn = AF.$('#bulkDelete');
  if (delBtn) delBtn.addEventListener('click', function () {
    const ids = picked();
    if (!ids.length) return;
    if (!confirm('Delete ' + ids.length + ' item(s)? This cannot be undone.')) return;
    delBtn.disabled = true;

    Promise.all(ids.map(id => AF.api('api/content/delete', { id: id }))).then(function (res) {
      const ok = res.filter(r => r.ok).length;
      AF.toast(ok + ' item(s) deleted.', 'success', '✓');
      ids.forEach(function (id) {
        const card = document.querySelector('[data-item="' + id + '"]');
        if (card) card.remove();
      });
      delBtn.disabled = false;
      refresh();
    });
  });

  /* ---------------------------------------------------- quick status ------ */
  document.addEventListener('click', function (e) {
    const q = e.target.closest('.quick-status');
    if (q) {
      const id = parseInt(q.dataset.id, 10);
      const status = q.dataset.status;
      q.disabled = true;

      const payload = { ids: [id], status: status };
      if (status === 'scheduled') {
        const when = prompt('Publish at (YYYY-MM-DD HH:MM):',
          new Date(Date.now() + 3600000).toISOString().slice(0, 16).replace('T', ' '));
        if (!when) { q.disabled = false; return; }
        payload.publish_at = when.length === 16 ? when + ':00' : when;
      }

      AF.api('api/content/status', payload).then(function (r) {
        if (!r.ok) { q.disabled = false; AF.toast(r.error, 'danger', '✕'); return; }
        AF.toast('Status → ' + status + '.', 'success', '✓');
        setTimeout(function () { location.reload(); }, 450);
      });
      return;
    }

    const del = e.target.closest('.del-item');
    if (del) {
      const id = parseInt(del.dataset.id, 10);
      if (!confirm('Delete content #' + id + '?')) return;
      AF.api('api/content/delete', { id: id }).then(function (r) {
        if (!r.ok) { AF.toast(r.error, 'danger', '✕'); return; }
        const card = document.querySelector('[data-item="' + id + '"]');
        if (card) {
          card.style.transition = 'opacity .2s, transform .2s';
          card.style.opacity = '0';
          card.style.transform = 'scale(.97)';
          setTimeout(function () { card.remove(); }, 220);
        }
        AF.toast('Deleted.', 'success', '✓');
      });
    }
  });
})();
