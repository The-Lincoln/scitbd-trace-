/* ==========================================================================
   AutoFlows — flow editor: save, conditional fields, live preview
   ========================================================================== */
(function () {
  'use strict';

  const form = AF.$('#flowForm');
  if (!form) return;

  const id       = parseInt(AF.$('#fId').value, 10) || 0;
  const msg      = AF.$('#flowMsg');
  const platformBox = AF.$('#platformBox');
  const weekdayBox  = AF.$('#weekdayBox');
  const runAtBox    = AF.$('#runAtBox');

  /* --------------------------------------------------- read the form ----- */
  function read() {
    const channel = (AF.$('input[name="channel"]:checked') || {}).value || 'pack';
    return {
      id: id,
      name: AF.$('#fName').value.trim(),
      channel: channel,
      brief: AF.$('#fBrief').value.trim(),
      count: parseInt(AF.$('#fCount').value, 10) || 3,
      tone: AF.$('#fTone').value.trim() || 'warm',
      audience: AF.$('#fAudience').value.trim(),
      platforms: AF.$$('.f-plat:checked').map(c => c.value),
      schedule: AF.$('#fSchedule').value,
      run_at: AF.$('#fRunAt').value || '09:00',
      weekday: parseInt(AF.$('#fWeekday').value, 10) || 1,
      active: AF.$('#fActive').checked ? 1 : 0
    };
  }

  /* ------------------------------------------------- conditional fields -- */
  function sync() {
    const channel = (AF.$('input[name="channel"]:checked') || {}).value || 'pack';
    const schedule = AF.$('#fSchedule').value;

    platformBox.style.display = channel === 'social' ? '' : 'none';
    weekdayBox.style.display  = schedule === 'weekly' ? '' : 'none';
    runAtBox.style.display    = schedule === 'manual' ? 'none' : '';
    renderPreview();
  }

  AF.$$('input[name="channel"]').forEach(r => r.addEventListener('change', sync));
  AF.$('#fSchedule').addEventListener('change', sync);
  ['fName', 'fBrief', 'fCount', 'fTone', 'fAudience', 'fRunAt'].forEach(function (sel) {
    const el = AF.$(sel);
    if (el) el.addEventListener('input', renderPreview);
  });
  AF.$$('.f-plat').forEach(c => c.addEventListener('change', renderPreview));

  /* ---------------------------------------------------------- preview ---- */
  function renderPreview() {
    const d = read();
    const box = AF.$('#flowPreview');
    if (!box) return;

    const steps = d.channel === 'pack'
      ? ['social', 'blog', 'email']
      : [d.channel];

    const icons = { social: 'bi-share', blog: 'bi-journal-richtext', email: 'bi-envelope-paper' };
    const labels = { social: 'Social posts', blog: 'Blog article', email: 'Marketing email' };

    let html = '<div class="d-flex flex-wrap gap-1 mb-2">';
    html += '<span class="badge text-bg-secondary"><i class="bi bi-funnel me-1"></i>brief</span>';
    html += '<span class="badge text-bg-secondary"><i class="bi bi-diagram-3 me-1"></i>outline</span>';
    steps.forEach(function (s) {
      html += '<span class="badge text-bg-dark"><i class="bi ' + icons[s] + ' me-1"></i>' + labels[s] + '</span>';
    });
    html += '<span class="badge text-bg-secondary"><i class="bi bi-clipboard-check me-1"></i>review</span>';
    html += '</div>';

    html += '<ul class="list-unstyled mb-0">';
    html += '<li><i class="bi bi-fonts me-2 text-secondary"></i>' + esc(d.brief || 'no brief yet') + '</li>';
    if (d.channel === 'social') {
      html += '<li><i class="bi bi-phone me-2 text-secondary"></i>' +
        (d.platforms.length ? esc(d.platforms.join(', ')) : 'no platform selected') +
        ' · ' + d.count + ' variant(s)</li>';
    } else {
      html += '<li><i class="bi bi-collection me-2 text-secondary"></i>' + d.count + ' item(s) per run</li>';
    }
    html += '<li><i class="bi bi-megaphone me-2 text-secondary"></i>' + esc(d.tone) +
      (d.audience ? ' → ' + esc(d.audience) : '') + '</li>';
    html += '<li><i class="bi bi-clock me-2 text-secondary"></i>' +
      (d.schedule === 'manual'
        ? 'manual only — run it yourself'
        : d.schedule + ' at ' + esc(d.run_at) + (d.schedule === 'weekly' ? ', weekday ' + d.weekday : '')) +
      '</li>';
    if (!d.active) html += '<li class="text-warning"><i class="bi bi-pause-circle me-2"></i>currently paused</li>';
    html += '</ul>';

    box.innerHTML = html;
  }

  function esc(s) { return AF.esc(s); }

  /* ------------------------------------------------------------- save ---- */
  function save() {
    const d = read();
    if (!d.name) { msg.innerHTML = '<span class="text-danger">Name is required.</span>'; AF.$('#fName').focus(); return Promise.resolve(null); }
    if (!d.brief) { msg.innerHTML = '<span class="text-danger">A standing brief is required.</span>'; AF.$('#fBrief').focus(); return Promise.resolve(null); }
    if (d.channel === 'social' && !d.platforms.length) {
      msg.innerHTML = '<span class="text-danger">Pick at least one platform.</span>';
      return Promise.resolve(null);
    }

    msg.innerHTML = '<span class="text-secondary">Saving…</span>';
    const route = d.id ? 'api/flow/update' : 'api/flow/create';

    return AF.api(route, d).then(function (r) {
      if (!r.ok) {
        msg.innerHTML = '<span class="text-danger">' + esc(r.error || 'Save failed') + '</span>';
        return null;
      }
      msg.innerHTML = '<span class="text-success">Saved ✓</span>';
      if (!d.id && r.id) {
        history.replaceState(null, '', AF.url('flow') + '&id=' + r.id);
        AF.$('#fId').value = r.id;
        setTimeout(function () { location.href = AF.url('flow') + '&id=' + r.id; }, 350);
      }
      return r;
    });
  }

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    save();
  });

  const runBtn = AF.$('#fRunNow');
  if (runBtn) runBtn.addEventListener('click', function () {
    save().then(function (r) {
      const flowId = r && r.id ? r.id : id;
      if (!flowId) return;
      runBtn.disabled = true;
      runBtn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Running…';

      AF.stream('api/flow/run', { id: flowId }, {
        onEvent: function (ev) {
          if (ev.type === 'step') {
            msg.innerHTML = '<span class="text-secondary"><i class="bi bi-arrow-right"></i> ' +
              AF.esc(ev.label) + ' (' + ev.index + '/' + ev.total + ')</span>';
          }
          if (ev.type === 'done') {
            msg.innerHTML = '<span class="text-success">Done — ' + (ev.outputs || 0) + ' item(s) · ' +
              '<a href="' + AF.url('agent') + '&run=' + ev.run_id + '">trace</a> · ' +
              '<a href="' + AF.url('content') + '">library</a></span>';
          }
        },
        onDone: function () {
          runBtn.disabled = false;
          runBtn.innerHTML = '<i class="bi bi-play-fill me-1"></i>Save &amp; run';
        },
        onError: function (m) {
          runBtn.disabled = false;
          runBtn.innerHTML = '<i class="bi bi-play-fill me-1"></i>Save &amp; run';
          msg.innerHTML = '<span class="text-danger">' + AF.esc(m) + '</span>';
        }
      });
    });
  });

  // Support #new deep link from the flows page.
  if (location.hash === '#new') AF.$('#fName').focus();

  sync();
})();
