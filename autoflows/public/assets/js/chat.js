/* ==========================================================================
   AutoFlows — chat page
   Streams from api/chat/send. Tolerates the agent events (run_start, step,
   step_done) that arrive when a slash command fires a real generation.
   ========================================================================== */
(function () {
  'use strict';

  const scroll = AF.$('#chatScroll');
  const list   = AF.$('#chatMessages');
  const form   = AF.$('#chatForm');
  const input  = AF.$('#chatInput');
  const badge  = AF.$('#llmBadge');
  const hint   = AF.$('#chatHint');

  let convId   = parseInt(scroll && scroll.dataset.conv, 10) || 0;
  let busy     = false;
  let opts     = { model: '', temperature: 0.75, num_predict: 768, system: '' };

  if (!list) return;

  /* ------------------------------------------------------------ render --- */
  function msgEl(role, content) {
    const div = document.createElement('div');
    div.className = 'msg msg-' + role;
    div.innerHTML =
      '<div class="msg-avatar"><i class="bi ' + (role === 'user' ? 'bi-person-fill' : 'bi-robot') + '"></i></div>' +
      '<div class="msg-body">' +
        '<div class="msg-meta small text-secondary">' +
          '<span>' + (role === 'user' ? 'You' : 'AutoFlows') + '</span><span>·</span>' +
          '<span>' + AF.timeNow() + '</span>' +
        '</div>' +
        '<div class="msg-text md"></div>' +
        '<div class="msg-actions">' +
          '<button class="btn btn-xs btn-ghost act-copy"><i class="bi bi-clipboard"></i> Copy</button>' +
          '<button class="btn btn-xs btn-ghost act-delete text-danger"><i class="bi bi-trash3"></i></button>' +
        '</div>' +
      '</div>';
    const body = div.querySelector('.msg-text');
    if (role === 'user') body.textContent = content;
    else body.innerHTML = AF.md(content);
    list.appendChild(div);
    AF.scrollDown(scroll);
    return div;
  }

  /** A collapsible strip showing agent progress inside the thread. */
  function runStrip(label) {
    const div = document.createElement('div');
    div.className = 'trace-step running mb-2';
    div.innerHTML =
      '<div class="trace-head"><span class="trace-label">' +
      '<span class="spinner-border spinner-border-sm me-1"></span>' + AF.esc(label || 'Working…') +
      '</span><span class="trace-meta small text-secondary"></span></div>' +
      '<pre class="trace-out"></pre>';
    list.appendChild(div);
    AF.scrollDown(scroll);
    return div;
  }

  function emptyState() { return AF.$('.chat-empty'); }

  /* ------------------------------------------------------------- send ---- */
  function send(text) {
    if (busy) return;
    busy = true;

    const est = emptyState();
    if (est) est.remove();

    msgEl('user', text);
    const bot = msgEl('assistant', '');
    const body = bot.querySelector('.msg-text');
    const typer = AF.typer(body);
    let strip = null;
    let meta = null;

    AF.$('#chatSend').disabled = true;
    hint.textContent = 'streaming…';

    AF.stream('api/chat/send', Object.assign({ conversation_id: convId, content: text }, opts), {
      onEvent: function (ev) {
        switch (ev.type) {
          case 'start':
            if (ev.conversation_id) convId = ev.conversation_id;
            scroll.dataset.conv = convId;
            break;

          case 'delta':
            typer.push(ev.t);
            break;

          // --- agent activity raised by /social /blog /email /daily -------
          case 'command':
            strip = runStrip('Running /' + ev.name + ' — ' + (ev.channels || []).join(' + '));
            break;
          case 'run_start':
            if (!strip) strip = runStrip('FlowAgent running');
            strip.querySelector('.trace-meta').textContent =
              (ev.total || 0) + ' steps · ' + (ev.offline ? 'template engine' : ev.model || 'llm');
            break;
          case 'step':
            if (!strip) strip = runStrip('FlowAgent running');
            strip.querySelector('.trace-label').innerHTML =
              '<span class="spinner-border spinner-border-sm me-1"></span>' + AF.esc(ev.label);
            strip.querySelector('.trace-out').textContent = '';
            AF.scrollDown(scroll);
            break;
          case 'step_done':
            if (strip) {
              const out = strip.querySelector('.trace-out');
              out.textContent += (out.textContent ? '\n' : '') + '✓ ' + ev.chars + ' chars' +
                (ev.outputs ? ', ' + ev.outputs + ' item(s)' : '');
            }
            break;

          case 'meta':
            meta = ev;
            break;

          case 'error':
            strip = strip || runStrip('Problem');
            strip.classList.remove('running');
            strip.classList.add('failed');
            strip.querySelector('.trace-label').textContent = ev.error;
            break;
        }
      },
      onDone: function () {
        typer.flush();

        if (meta) {
          // The server text is authoritative — replace whatever we buffered.
          if (meta.text) body.innerHTML = AF.md(meta.text);
          const m = bot.querySelector('.msg-meta');
          const bits = ['<span>' + AF.esc(meta.model || '') + '</span>'];
          if (meta.command) bits.push('<span class="badge text-bg-accent">/' + AF.esc(meta.command) + '</span>');
          if (meta.provider === 'local' || meta.fallback) bits.push('<span class="badge text-bg-warning">template engine</span>');
          if (meta.tokens) bits.push('<span>' + meta.tokens + ' tok · ' + meta.ms + 'ms</span>');
          m.insertAdjacentHTML('beforeend', bits.join(''));

          if (meta.warning && !meta.fallback) {
            AF.toast(meta.warning, 'warning', '⚠');
          }
          if (meta.title) {
            const t = AF.$('#threadTitle');
            if (t) t.textContent = meta.title;
          }
          if (strip) {
            strip.classList.remove('running');
            const n = (meta.generated || []).length;
            if (n) {
              strip.querySelector('.trace-label').innerHTML =
                '<i class="bi bi-check-circle-fill text-success me-1"></i>' + n + ' item(s) generated';
              const link = document.createElement('a');
              link.className = 'btn btn-xs btn-outline-light mt-1';
              link.href = AF.url('agent') + '&run=' + meta.run_id;
              link.innerHTML = '<i class="bi bi-clock-history"></i> Replay trace';
              strip.appendChild(link);
            } else {
              strip.remove();
            }
          }
          if (badge) {
            badge.textContent = meta.fallback ? 'offline · fallback' : 'online';
            badge.className = 'badge ' + (meta.fallback ? 'text-bg-warning' : 'text-bg-success');
          }
        } else if (strip) {
          strip.remove();
        }

        finish();
      },
      onError: function (msg) {
        typer.flush();
        if (!body.textContent.trim()) body.innerHTML = '<em class="text-danger">' + AF.esc(msg) + '</em>';
        AF.toast(msg, 'danger', '✕');
        finish();
      }
    });
  }

  function finish() {
    busy = false;
    AF.$('#chatSend').disabled = false;
    hint.textContent = 'Streaming · local only';
    AF.scrollDown(scroll);
  }

  /* ------------------------------------------------------------ events --- */
  if (form) {
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      const text = input.value.trim();
      if (!text || busy) return;
      input.value = '';
      input.style.height = 'auto';
      send(text);
    });
    input.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); form.requestSubmit(); }
    });
    input.addEventListener('input', function () {
      AF.$('#chatCount').textContent = input.value.length;
    });
  }

  // Starter chips
  document.addEventListener('click', function (e) {
    const chip = e.target.closest('.chip-prompt');
    if (chip) { input.value = chip.textContent.trim(); input.focus(); return; }

    const cmd = e.target.closest('.cmd-btn');
    if (cmd) {
      input.value = cmd.dataset.cmd;
      input.focus();
      input.setSelectionRange(input.value.length, input.value.length);
      return;
    }

    // Copy / delete inside a message
    const copy = e.target.closest('.act-copy');
    if (copy) {
      const msg = copy.closest('.msg');
      AF.copy(msg.querySelector('.msg-text').innerText);
      AF.toast('Copied to clipboard.', 'success', '✓');
      return;
    }
    const del = e.target.closest('.act-delete');
    if (del) {
      const msg = del.closest('.msg');
      const id = parseInt(msg.dataset.id, 10);
      msg.remove();
      if (id) AF.api('api/chat/delete_message', { id: id });
      return;
    }

    // Thread switching
    const th = e.target.closest('.chat-thread');
    if (th) {
      location.href = AF.url('chat') + '&c=' + th.dataset.id;
    }
  });

  /* ----------------------------------------------------------- threads --- */
  const btnNew = AF.$('#btnNewChat');
  if (btnNew) btnNew.addEventListener('click', function () {
    AF.api('api/chat/create', { title: 'New chat' }).then(function (r) {
      if (r.ok) location.href = AF.url('chat') + '&c=' + r.id;
      else AF.toast(r.error, 'danger', '✕');
    });
  });

  const btnDel = AF.$('#btnDeleteChat');
  if (btnDel) btnDel.addEventListener('click', function () {
    if (!convId || !confirm('Delete this thread and all of its messages?')) return;
    AF.api('api/chat/delete', { id: convId }).then(function (r) {
      if (r.ok) location.href = AF.url('chat');
      else AF.toast(r.error, 'danger', '✕');
    });
  });

  const btnClear = AF.$('#btnClear');
  if (btnClear) btnClear.addEventListener('click', function () {
    if (!convId || !confirm('Remove every message from this thread?')) return;
    AF.api('api/chat/clear', { id: convId }).then(function () { location.reload(); });
  });

  const btnExport = AF.$('#btnExport');
  if (btnExport) btnExport.addEventListener('click', function () {
    if (convId) location.href = AF.url('api/chat/export') + '&id=' + convId;
  });

  /* -------------------------------------------------------- model opts --- */
  const t = AF.$('#optTemp'), tOut = AF.$('#optTempOut');
  if (t) {
    opts.temperature = parseFloat(t.value);
    t.addEventListener('input', function () { opts.temperature = parseFloat(t.value); tOut.textContent = t.value; });
  }
  const n = AF.$('#optTokens'), nOut = AF.$('#optTokensOut');
  if (n) {
    opts.num_predict = parseInt(n.value, 10);
    n.addEventListener('input', function () { opts.num_predict = parseInt(n.value, 10); nOut.textContent = n.value; });
  }
  const sys = AF.$('#optSystem');
  if (sys) { opts.system = sys.value; sys.addEventListener('change', function () { opts.system = sys.value; }); }
  const mod = AF.$('#optModel');
  if (mod) { opts.model = mod.value; mod.addEventListener('change', function () { opts.model = mod.value; }); }

  const prov = AF.$('#optProvider');
  if (prov) prov.addEventListener('change', function () {
    AF.api('api/settings/test', {}).then(function () {
      AF.toast('Provider saved on the Settings page.', 'info', 'ℹ');
    });
  });

  const probe = AF.$('#btnProbe');
  if (probe) probe.addEventListener('click', function () {
    probe.disabled = true;
    probe.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Checking…';
    AF.api('api/chat/status').then(function (r) {
      probe.disabled = false;
      probe.innerHTML = '<i class="bi bi-arrow-repeat me-1"></i>Re-check connection';
      const llm = r.llm || {};
      if (badge) {
        badge.textContent = llm.ok ? 'online' : 'offline · fallback';
        badge.className = 'badge ' + (llm.ok ? 'text-bg-success' : 'text-bg-warning');
      }
      if (hint) hint.textContent = llm.ok ? (llm.model + ' · ' + llm.latency_ms + 'ms') : 'template engine active';
      AF.toast(llm.ok ? llm.model + ' reachable in ' + llm.latency_ms + 'ms' : 'Model unreachable — template engine will be used.',
        llm.ok ? 'success' : 'warning', llm.ok ? '✓' : '⚠');
    });
  });

  AF.scrollDown(scroll);
})();
