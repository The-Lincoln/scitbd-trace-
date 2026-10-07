/* ==========================================================================
   AutoFlows — flows list: toggle active, delete
   (.run-flow is handled globally by app.js)
   ========================================================================== */
(function () {
  'use strict';

  // Active toggle
  document.addEventListener('change', function (e) {
    const tog = e.target.closest('.flow-toggle');
    if (!tog) return;
    const id = parseInt(tog.dataset.id, 10);
    tog.disabled = true;
    AF.api('api/flow/toggle', { id: id }).then(function (r) {
      tog.disabled = false;
      if (!r.ok) { tog.checked = !tog.checked; AF.toast(r.error, 'danger', '✕'); return; }
      const card = tog.closest('[data-flow-card]');
      if (card) card.querySelector('.flow-card').classList.toggle('is-off', !r.active);
      AF.toast('Flow ' + (r.active ? 'activated' : 'paused') + '.', 'success', '✓');
    });
  });

  // Delete
  document.addEventListener('click', function (e) {
    const del = e.target.closest('.del-flow');
    if (!del) return;
    const id = parseInt(del.dataset.id, 10);
    const name = del.dataset.name || ('#' + id);
    if (!confirm('Delete "' + name + '"?\n\nIts generated content stays in the library.')) return;

    del.disabled = true;
    AF.api('api/flow/delete', { id: id }).then(function (r) {
      if (!r.ok) { del.disabled = false; AF.toast(r.error, 'danger', '✕'); return; }
      const card = del.closest('[data-flow-card]');
      if (card) {
        card.style.transition = 'opacity .2s, transform .2s';
        card.style.opacity = '0';
        card.style.transform = 'scale(.97)';
        setTimeout(function () { card.remove(); }, 220);
      }
      AF.toast('Flow deleted.', 'success', '✓');
    });
  });
})();
