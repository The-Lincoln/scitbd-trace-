/* ==========================================================================
   AutoFlows — dashboard: quick generate + live badges
   ========================================================================== */
(function () {
  'use strict';

  const form = AF.$('#quickGen');
  if (!form) return;

  const chanIn = AF.$('#qgChannel');
  const bar    = AF.$('#qgProgress');
  const barIn  = bar ? bar.firstElementChild : null;
  const log    = AF.$('#qgLog');
  const btn    = AF.$('#qgRun');

  /* ------------------------------------------------------------- tabs ---- */
  AF.$$('#qgTabs [data-qg]').forEach(function (b) {
    b.addEventListener('click', function () {
      AF.$$('#qgTabs .nav-link').forEach(x => x.classList.remove('active'));
      b.classList.add('active');
      chanIn.value = b.dataset.qg;
      const cfg = {
        social: 'One post per selected platform, inside each character limit.',
        blog:   'Title, slug, excerpt, article body and tags.',
        email:  'Subject, preheader, headline, body and a single CTA.'
      }[b.dataset.qg];
      AF.$('#qgBrief').placeholder = 'e.g. ' + ({
        social: 'why small teams should publish weekly instead of chasing virality',
        blog:   'building a repeatable content loop for a two-person team',
        email:  're-engaging dormant trial users with one useful email'
      }[b.dataset.qg] || '');
      const t = AF.$('#qgTabs').parentElement.querySelector('.form-text');
      if (t && cfg) t.textContent = cfg;
    });
  });

  /* ---------------------------------------------------------- generate --- */
  form.addEventListener('submit', function (e) {
    e.preventDefault();
    const goal = AF.$('#qgBrief').value.trim();
    if (!goal) { AF.toast('Write a brief first.', 'warning', '⚠'); return; }

    const channel = chanIn.value;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Generating…';
    bar.classList.remove('d-none');
    if (barIn) barIn.style.width = '8%';
    log.innerHTML = '';

    let step = 0, total = 1;
    const line = function (html) {
      const d = document.createElement('div');
      d.innerHTML = html;
      log.appendChild(d);
    };

    AF.stream('api/agent/run', {
      goal: goal,
      channels: [channel],
      tone: AF.$('#qgTone').value,
      count: parseInt(AF.$('#qgCount').value, 10) || 3,
      platforms: ['twitter', 'linkedin', 'instagram']
    }, {
      onEvent: function (ev) {
        if (ev.type === 'run_start') {
          total = ev.total || 1;
          line('<span class="text-secondary">provider: <code>' +
            AF.esc(ev.offline ? 'template engine' : ev.model) + '</code> · ' + total + ' steps</span>');
          if (barIn) barIn.style.width = '15%';
        }
        if (ev.type === 'step') {
          step = ev.index;
          line('<i class="bi bi-arrow-right text-accent"></i> ' + AF.esc(ev.label));
          if (barIn) barIn.style.width = Math.round((step / total) * 85 + 10) + '%';
        }
        if (ev.type === 'step_done' && ev.outputs) {
          line('<span class="text-success">✓ ' + ev.outputs + ' item(s) · ' + ev.chars + ' chars</span>');
        }
        if (ev.type === 'delta') {
          // live text would flood the log; ignore for the dashboard card.
        }
      },
      onDone: function (res) {
        if (barIn) barIn.style.width = '100%';
        const n = (res && res.content_ids && res.content_ids.length) || 0;
        if (res && res.ok === false) {
          line('<span class="text-danger">' + AF.esc(res.error || 'Run failed') + '</span>');
          AF.toast(res.error || 'Run failed', 'danger', '✕');
        } else if (n) {
          line('<a class="btn btn-accent btn-sm mt-2" href="' + AF.url('content') + '">' +
            '<i class="bi bi-collection me-1"></i>Open ' + n + ' item(s) in the library</a>');
          AF.toast(n + ' item(s) generated.', 'success', '✓');
        } else {
          line('<span class="text-warning">Nothing was produced.</span>');
        }
        reset();
      },
      onError: function (msg) {
        line('<span class="text-danger">' + AF.esc(msg) + '</span>');
        AF.toast(msg, 'danger', '✕');
        reset();
      }
    });
  });

  function reset() {
    btn.disabled = false;
    btn.innerHTML = '<i class="bi bi-stars me-1"></i>Generate';
    setTimeout(function () { bar.classList.add('d-none'); if (barIn) barIn.style.width = '0%'; }, 1400);
  }

  /* -------------------------------------------------------- live badges --- */
  setInterval(function () {
    AF.api('api/stats').then(function (r) {
      if (!r || !r.ok) return;
      const chip = AF.$('#llmChip');
      if (chip && r.llm) {
        chip.textContent = r.llm.ok ? r.llm.model + ' online' : 'offline · template engine';
        chip.className = 'badge ' + (r.llm.ok ? 'text-bg-success' : 'text-bg-warning');
      }
    });
  }, 30000);
})();
