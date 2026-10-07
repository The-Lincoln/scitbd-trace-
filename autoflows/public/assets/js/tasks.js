/* ==========================================================================
   Daily Tasks dashboard — status, assign, delete (list) + create/update (editor)
   ========================================================================== */
(function () {
  'use strict';

  function out(msg, type) {
    var el = document.getElementById('tasksOut') || document.getElementById('taskSaveOut');
    if (el) el.textContent = msg || '';
    if (msg) AF.toast(msg, type || 'info');
  }

  // List: inline status change
  document.addEventListener('change', function (e) {
    var sel = e.target.closest('.task-status');
    if (!sel) return;
    var id = parseInt(sel.dataset.id, 10);
    sel.disabled = true;
    AF.api('api/tasks/status', { id: id, status: sel.value }).then(function (r) {
      sel.disabled = false;
      if (!r.ok) { out(r.error, 'danger'); return; }
      out('Task #' + id + ' -> ' + r.status, 'success');
    });
  });

  // List: assign + delete (delegated)
  document.addEventListener('click', function (e) {
    var asg = e.target.closest('.btn-assign');
    if (asg) {
      var id = parseInt(asg.dataset.id, 10);
      var row = asg.closest('tr');
      var input = row ? row.querySelector('.task-assignee') : null;
      var who = input ? input.value.trim() || 'ceo' : 'ceo';
      asg.disabled = true;
      AF.api('api/tasks/assign', { id: id, assignee: who }).then(function (r) {
        asg.disabled = false;
        if (!r.ok) { out(r.error, 'danger'); return; }
        out('Task #' + id + ' assigned to ' + who, 'success');
      });
      return;
    }
    var del = e.target.closest('.btn-delete');
    if (del) {
      var did = parseInt(del.dataset.id, 10);
      if (!confirm('Delete task #' + did + '?')) return;
      del.disabled = true;
      AF.api('api/tasks/delete', { id: did }).then(function (r) {
        if (!r.ok) { del.disabled = false; out(r.error, 'danger'); return; }
        var tr = del.closest('tr[data-task-row]');
        if (tr) tr.remove();
        out('Task #' + did + ' deleted.', 'success');
      });
      return;
    }
    var delOne = e.target.closest('#btnDeleteTask');
    if (delOne) {
      var oid = parseInt(delOne.dataset.id, 10);
      if (!confirm('Delete task #' + oid + '?')) return;
      AF.api('api/tasks/delete', { id: oid }).then(function (r) {
        if (!r.ok) { out(r.error, 'danger'); return; }
        window.location.href = 'index.php?r=tasks';
      });
    }
  });

  // Editor: create / update
  var form = document.getElementById('taskForm');
  if (form) {
    form.addEventListener('submit', function (ev) {
      ev.preventDefault();
      var id = parseInt(form.dataset.id || '0', 10);
      var payload = {
        task_title: document.getElementById('fTitle').value,
        task_description: document.getElementById('fDesc').value,
        priority: document.getElementById('fPriority').value,
        status: document.getElementById('fStatus').value,
        category: document.getElementById('fCategory').value,
        assignee: document.getElementById('fAssignee').value,
        due_date: document.getElementById('fDue').value,
        estimated_hours: parseFloat(document.getElementById('fEst').value || '1'),
        bst_block_id: parseInt(document.getElementById('fBlock').value || '3', 10)
      };
      var route = id > 0 ? 'api/tasks/update&id=' + id : 'api/tasks/create';
      if (id > 0) payload.id = id;
      AF.api(route, payload).then(function (r) {
        if (!r.ok) { out(r.error, 'danger'); return; }
        out(id > 0 ? 'Saved.' : 'Created #' + r.id, 'success');
        if (!id && r.redirect) window.location.href = r.redirect;
      });
    });
  }
})();
