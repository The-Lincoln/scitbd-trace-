/* ==========================================================================
   AutoFlows — FlowAgent console: runs the plan and renders the live trace.
   Event contract (from app/services/Agent.php):
     run_start → (step → delta* → step_done)* → done
   ========================================================================== */
(function () {
  'use strict';

  const form = AF.$('#agentForm');
  if (!form) return;

  const steps   = AF.$('#traceSteps');
  const empty   = AF.$('#agentEmpty');
  const bar     = AF.$('#traceBar');
  const prov    = AF.$('#traceProvider');
  const foot    = AF.$('#traceFoot');
  const outCard = AF.$('#outCard');
  const outList = AF.$('#outList');
  const runBtn  = AF.$('#aRun');
  const scroll  = AF.$('#traceScroll');

  let running = false;
  let lastPayload = null;

  /* --------------------------------------------------------- payload ----- */
  function payload() {
    const goal = AF.$('#aGoal').value.trim();
    const chans = AF.$$('.a-chan:checked').map(c => c.value);
    const plats = AF.$$('.a-plat:checked').map(c => c.value);
    return {
      goal: goal,
      channels: chans.length ? chans : ['social'],
      platforms: plats.length ? plats : ['twitter'],
      tone: AF.$('#aTone').value,
      audience: AF.$('#aAudience').value,
      count: parseInt(AF.$('#aCount').value, 10) || 3
    };
  }

  /* ----------------------------------------------------------- trace ----- */
  function startStep(def) {
    const el = document.createElement('div');
    el.className = 'trace-step running open';
    el.dataset.step = def.id;
    el.innerHTML =
      '<div class="trace-head">' +
        '<span class="trace-label"><span class="spinner-border spinner-border-sm me-1"></span>' +
          AF.esc(def.label) + '</span>' +
        '<span class="trace-meta small text-secondary">step ' + def.index + ' of ' + def.total + '</span>' +
      '</div>' +
      '<pre class="trace-out"></pre>';
    steps.appendChild(el);
    AF.scrollDown(scroll);
    return el;
  }

  function markPlan(id, state, text) {
    const li = document.querySelector('#planList [data-step="' + id + '"]');
    if (!li) return;
    li.classList.add('done');
    const s = li.querySelector('.step-state');
    s.textContent = state;
    s.className = 'step-state ' + (state === '✓' ? 'text-success' : (state === '✕' ? 'text-danger' : 'text-secondary'));
    if (text) li.title = text;
  }

  /* ------------------------------------------------------------- run ----- */
  function run(p) {
    if (running) return;
    running = true;
    lastPayload = p;

    if (empty) empty.remove();
    steps.innerHTML = '';
    foot.style.display = 'none';
    outCard.style.display = 'none';
    outList.innerHTML = '';
    if (bar) bar.style.width = '4%';
    if (prov) prov.textContent = 'connecting…';

    runBtn.disabled = true;
    runBtn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Agent running…';
    AF.$$('#planList .step-state').forEach(s => { s.textContent = '…'; s.className = 'step-state text-secondary'; });

    let current = null, typer = null, total = 1, done = 0, ids = [];

    AF.stream('api/agent/run', p, {
      onEvent: function (ev) {
        switch (ev.type) {

          case 'run_start':
            total = ev.total || 1;
            if (prov) prov.textContent = ev.offline ? 'template engine' : (ev.model || 'llm');
            break;

          case 'step':
            current = startStep({
              id: ev.id, label: ev.label, index: ev.index, total: ev.total
            });
            markPlan(ev.id, '…');
            if (bar) bar.style.width = Math.round(((ev.index - 1) / total) * 90 + 5) + '%';
            typer = { acc: '', el: current.querySelector('.trace-out') };
            break;

          case 'delta':
            if (typer) {
              typer.acc += ev.t;
              typer.el.textContent = typer.acc;
              AF.scrollDown(scroll);
            }
            break;

          case 'step_done':
            done++;
            if (current) {
              current.classList.remove('running');
              const head = current.querySelector('.trace-head');
              head.querySelector('.trace-label').innerHTML =
                '<i class="bi bi-check-circle-fill text-success me-1"></i>' +
                AF.esc(labelOf(ev.id) || ev.id);
              head.querySelector('.trace-meta').textContent =
                ev.chars + ' chars' + (ev.outputs ? ' · ' + ev.outputs + ' item(s)' : '') +
                (ev.ms ? ' · ' + ev.ms + 'ms' : '') + (ev.score ? ' · score ' + ev.score : '');
              if (ev.score) markPlan(ev.id, '✓', 'score ' + ev.score);
            }
            markPlan(ev.id, '✓');
            if (bar) bar.style.width = Math.round((done / total) * 95 + 5) + '%';
            (ev.content_ids || []).forEach(i => ids.push(i));
            break;

          case 'step_failed':
            if (current) { current.classList.remove('running'); current.classList.add('failed'); }
            markPlan(ev.id, '✕');
            break;

          case 'error':
            AF.toast(ev.error, 'danger', '✕');
            break;
        }
      },

      onDone: function (res) {
        running = false;
        runBtn.disabled = false;
        runBtn.innerHTML = '<i class="bi bi-play-fill me-1"></i>Run agent';

        if (bar) bar.style.width = '100%';
        if (prov && res) {
          prov.textContent = (res.fallback ? 'template engine' : res.provider) || 'done';
        }

        const ok = !res || res.ok !== false;
        const n = (res && res.content_ids && res.content_ids.length) || ids.length;

        if (!ok) {
          AF.toast(res.error || 'Run failed.', 'danger', '✕');
        } else if (n) {
          AF.toast(n + ' item(s) generated' + (res.score ? ' · score ' + res.score : ''), 'success', '✓');
          loadOutputs(res.run_id, n, res.content_ids);
        }

        foot.style.display = '';
        const timing = AF.$('#traceTiming');
        if (timing) timing.textContent = (res && res.ms ? (res.ms / 1000).toFixed(1) + 's · ' : '') + n + ' item(s)';
        const again = AF.$('#traceAgain');
        if (again) again.dataset.run = (res && res.run_id) || '';
        AF.scrollDown(scroll);
      },

      onError: function (msg) {
        running = false;
        runBtn.disabled = false;
        runBtn.innerHTML = '<i class="bi bi-play-fill me-1"></i>Run agent';
        AF.toast(msg, 'danger', '✕');
      }
    });
  }

  function labelOf(id) {
    const li = document.querySelector('#planList [data-step="' + id + '"]');
    return li ? li.querySelector('span').textContent.trim() : '';
  }

  function loadOutputs(runId, n, contentIds) {
    AF.$('#traceOutCount').textContent = n;
    AF.$('#traceOpenLib').style.display = '';
    if (runId) AF.$('#traceOpenLib').href = AF.url('content');

    if (!runId) return;
    AF.api('api/agent/trace', { id: runId }).then(function (r) {
      if (!r.ok || !r.outputs || !r.outputs.length) return;
      outCard.style.display = '';
      outList.innerHTML = r.outputs.map(function (o) {
        return '<a class="list-group-item list-group-item-action bg-transparent d-flex justify-content-between gap-2" ' +
          'href="' + AF.url('item') + '&id=' + o.id + '">' +
          '<span class="text-truncate">' + AF.esc(o.title || o.body.slice(0, 60)) + '</span>' +
          '<span class="text-secondary small text-nowrap">' + AF.esc(o.channel) +
          (o.platform ? ' · ' + AF.esc(o.platform) : '') + ' · ' + o.chars + ' chars</span></a>';
      }).join('');
    });
  }

  /* ------------------------------------------------------------ events --- */
  form.addEventListener('submit', function (e) {
    e.preventDefault();
    const p = payload();
    if (!p.goal) { AF.toast('Describe the goal first.', 'warning', '⚠'); AF.$('#aGoal').focus(); return; }
    run(p);
  });

  const again = AF.$('#traceAgain');
  if (again) again.addEventListener('click', function () {
    if (lastPayload) run(lastPayload);
  });

  document.addEventListener('click', function (e) {
    const chip = e.target.closest('.goal-chip');
    if (chip) { AF.$('#aGoal').value = chip.textContent.trim(); AF.$('#aGoal').focus(); return; }

    // collapse / expand a finished step
    const head = e.target.closest('.trace-head');
    if (head && head.parentElement.classList.contains('trace-step')) {
      head.parentElement.classList.toggle('open');
    }
  });

  AF.$('#aGoal').focus();
})();
