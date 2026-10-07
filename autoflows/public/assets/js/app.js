/* ==========================================================================
   AutoFlows — shared front-end library
   Defines the contracts every page script depends on:
     AF.csrf · AF.api() · AF.stream() · AF.md() · AF.toast()
   ========================================================================== */
(function () {
  'use strict';

  const AF = window.AF = window.AF || {};

  AF.base = (document.querySelector('meta[name="base"]') || {}).content || 'index.php?r=';
  AF.csrf = (document.querySelector('meta[name="csrf"]') || {}).content || '';

  AF.$  = (s, r) => (r || document).querySelector(s);
  AF.$$ = (s, r) => Array.prototype.slice.call((r || document).querySelectorAll(s));

  AF.url = (route) => 'index.php?r=' + route;

  /* ----------------------------------------------------------- JSON API --- */
  AF.api = function (route, data) {
    return fetch(AF.url(route), {
      method: data ? 'POST' : 'GET',
      headers: Object.assign(
        { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        data ? { 'Content-Type': 'application/json', 'X-CSRF-Token': AF.csrf } : {}
      ),
      body: data ? JSON.stringify(data) : undefined
    }).then(function (res) {
      return res.json().catch(function () {
        return { ok: false, error: 'HTTP ' + res.status };
      });
    }).catch(function (e) {
      return { ok: false, error: e.message };
    });
  };

  /* --------------------------------------------------------------- SSE ---- */
  /**
   * POST-based Server-Sent Events.
   * EventSource cannot send a body or a CSRF header, so we read the stream
   * with fetch() and split frames ourselves.
   *
   * opts: { onEvent(obj), onDone(obj), onError(msg) }
   */
  AF.stream = function (route, payload, opts) {
    opts = opts || {};
    const done = typeof opts.onDone === 'function' ? opts.onDone : function () {};
    const fail = typeof opts.onError === 'function' ? opts.onError : function () {};

    return fetch(AF.url(route), {
      method: 'POST',
      headers: {
        'Accept': 'text/event-stream',
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        'X-CSRF-Token': AF.csrf
      },
      body: JSON.stringify(payload || {})
    }).then(function (res) {
      if (!res.ok || !res.body) {
        throw new Error('HTTP ' + res.status);
      }
      const reader = res.body.getReader();
      const decoder = new TextDecoder();
      let buffer = '';
      let finished = false;

      function finish(payload) {
        if (finished) return;
        finished = true;
        done(payload || {});
      }

      function pump() {
        return reader.read().then(function (r) {
          if (r.done) { finish({}); return; }

          buffer += decoder.decode(r.value, { stream: true });
          let idx;
          while ((idx = buffer.indexOf('\n\n')) !== -1) {
            const frame = buffer.slice(0, idx);
            buffer = buffer.slice(idx + 2);
            frame.split('\n').forEach(function (line) {
              if (line.indexOf('data:') !== 0) return;
              const json = line.slice(5).trim();
              if (!json) return;
              let evt;
              try { evt = JSON.parse(json); } catch (e) { return; }
              if (!evt || !evt.type) return;
              if (evt.type === 'error') { fail(evt.error || 'Stream error'); return; }
              if (opts.onEvent) opts.onEvent(evt);
              if (evt.type === 'done') finish(evt);
            });
          }
          return pump();
        });
      }
      return pump();
    }).catch(function (e) {
      fail(e.message);
      return {};
    });
  };

  /* ------------------------------------------------------------- toast ---- */
  AF.toast = function (message, type, icon) {
    const host = AF.$('#toastHost');
    if (!host) { return; }
    type = type || 'info';
    const bg = { success: 'text-bg-success', danger: 'text-bg-danger', warning: 'text-bg-warning', info: 'text-bg-dark' }[type] || 'text-bg-dark';

    const el = document.createElement('div');
    el.className = 'toast align-items-center ' + bg + ' border-0';
    el.setAttribute('role', 'alert');
    el.innerHTML =
      '<div class="d-flex"><div class="toast-body">' +
      (icon ? icon + ' ' : '') + AF.esc(message) +
      '</div><button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button></div>';
    host.appendChild(el);
    const t = new bootstrap.Toast(el, { delay: 3800 });
    t.show();
    el.addEventListener('hidden.bs.toast', () => el.remove());
  };

  AF.esc = function (s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  };

  /* ---------------------------------------------------------- markdown ---- */
  AF.md = function (src) {
    src = String(src == null ? '' : src).replace(/\r\n?/g, '\n');
    const stash = [];
    const keep = (html) => { stash.push(html); return '\u0000' + (stash.length - 1) + '\u0000'; };

    // 1. fenced code blocks — must be extracted before escaping
    src = src.replace(/```([a-z0-9-]*)\n([\s\S]*?)```/gi, function (_, lang, code) {
      return keep('<pre><code' + (lang ? ' class="lang-' + lang + '"' : '') + '>' +
        AF.esc(code.replace(/\n$/, '')) + '</code></pre>');
    });

    // 2. escape everything that remains
    let out = AF.esc(src);

    // 3. tables
    out = out.replace(/(?:^|\n)((?:[^\n]*\|[^\n]*\n)+(?:[^\n]*\|[^\n]*\n?))/g, function (_, block) {
      const lines = block.replace(/^\n/, '').split('\n').filter(l => l.indexOf('|') !== -1);
      if (lines.length < 2) return block;
      const cells = l => l.replace(/^\s*\|/, '').replace(/\|\s*$/, '').split('|').map(c => c.trim());
      const head = cells(lines[0]);
      const body = lines.slice(1).filter(l => !/^\s*\|?\s*:?-{3,}/.test(l));
      let html = '<table><thead><tr>' + head.map(h => '<th>' + inline(h) + '</th>').join('') + '</tr></thead><tbody>';
      body.forEach(l => { html += '<tr>' + cells(l).map(c => '<td>' + inline(c) + '</td>').join('') + '</tr>'; });
      return '\n' + keep(html + '</tbody></table>') + '\n';
    });

    // 4. block-level line walk
    const lines = out.split('\n');
    const html = [];
    let list = null, para = [], quote = [];

    const flushP = () => { if (para.length) { html.push('<p>' + inline(para.join('<br>')) + '</p>'); para = []; } };
    const flushQ = () => { if (quote.length) { html.push('<blockquote><p>' + inline(quote.join(' ')) + '</p></blockquote>'); quote = []; } };
    const closeList = () => { if (list) { html.push('</' + list + '>'); list = null; } };

    lines.forEach(function (raw) {
      const line = raw.trimEnd();
      const t = line.trim();

      if (t === '') { flushP(); flushQ(); closeList(); return; }
      if (t.indexOf('\u0000') === 0 && /^\u0000\d+\u0000$/.test(t)) { flushP(); flushQ(); closeList(); html.push(t); return; }

      const h = t.match(/^(#{1,6})\s+(.*)$/);
      if (h) { flushP(); flushQ(); closeList(); const n = h[1].length; html.push('<h' + n + '>' + inline(h[2]) + '</h' + n + '>'); return; }

      if (/^([-*_])\s*\1\s*\1[\s\-*_]*$/.test(t)) { flushP(); flushQ(); closeList(); html.push('<hr>'); return; }

      if (t.indexOf('&gt;') === 0 || t.indexOf('> ') === 0) {
        flushP(); closeList();
        quote.push(t.replace(/^\s*&gt;\s?/, '').replace(/^\s*>\s?/, ''));
        return;
      }
      flushQ();

      const ul = t.match(/^[-*+]\s+(.*)$/);
      const ol = t.match(/^\d+[.)]\s+(.*)$/);
      if (ul || ol) {
        flushP();
        const want = ul ? 'ul' : 'ol';
        if (list !== want) { closeList(); html.push('<' + want + '>'); list = want; }
        html.push('<li>' + inline((ul || ol)[1]) + '</li>');
        return;
      }
      closeList();

      para.push(t);
    });
    flushP(); flushQ(); closeList();

    return html.join('\n').replace(/\u0000(\d+)\u0000/g, (_, i) => stash[+i] || '');
  };

  function inline(s) {
    return s
      .replace(/`([^`]+)`/g, '<code>$1</code>')
      .replace(/!\[([^\]]*)\]\(([^)\s]+)\)/g, '<img alt="$1" src="$2">')
      .replace(/\[([^\]]+)\]\(([^)\s]+)\)/g, '<a href="$2" rel="noopener">$1</a>')
      .replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>')
      .replace(/(^|[^*])\*([^*\n]+)\*/g, '$1<em>$2</em>')
      .replace(/__([^_]+)__/g, '<strong>$1</strong>');
  }
  AF.mdInline = inline;

  /* --------------------------------------------------------- streaming ---- */
  /** Typewriter-append deltas into an element, then flush the remainder. */
  AF.typer = function (el) {
    let acc = '';
    let queue = '';
    let running = false;

    function tick() {
      if (!queue) { running = false; return; }
      running = true;
      acc += queue.slice(0, 3);
      queue = queue.slice(3);
      el.innerHTML = AF.md(acc);
      AF.scrollDown(el.closest('.chat-scroll') || el);
      setTimeout(tick, 16);
    }
    return {
      push: function (t) { queue += t; if (!running) tick(); },
      flush: function () { acc += queue; queue = ''; el.innerHTML = AF.md(acc); return acc; },
      text: function () { return acc + queue; }
    };
  };

  AF.scrollDown = function (el) { if (el) { el.scrollTop = el.scrollHeight; } };

  AF.copy = function (text) {
    if (navigator.clipboard && navigator.clipboard.writeText) {
      return navigator.clipboard.writeText(text);
    }
    const ta = document.createElement('textarea');
    ta.value = text; document.body.appendChild(ta); ta.select();
    try { document.execCommand('copy'); } catch (e) {}
    ta.remove();
    return Promise.resolve();
  };

  AF.countChars = function (s) {
    // Count grapheme-ish: spread handles astral planes correctly.
    return Array.from(String(s || '')).length;
  };

  AF.timeNow = function () { return new Date().toLocaleTimeString(); };

  /* ---------------------------------------------------- global handlers --- */
  document.addEventListener('DOMContentLoaded', function () {

    // Auto-grow textareas marked .chat-input
    AF.$$('.chat-input').forEach(function (ta) {
      ta.addEventListener('input', function () {
        ta.style.height = 'auto';
        ta.style.height = Math.min(ta.scrollHeight, 160) + 'px';
      });
    });

    // Any element marked .run-flow anywhere in the app.
    document.addEventListener('click', function (e) {
      const btn = e.target.closest('.run-flow');
      if (!btn) return;
      e.preventDefault();
      const id = parseInt(btn.dataset.id, 10);
      if (!id) return;

      const original = btn.innerHTML;
      btn.disabled = true;
      btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';
      AF.toast('Flow #' + id + ' started — follow it on the agent console.', 'info', '▶');

      AF.stream('api/flow/run', { id: id }, {
        onEvent: function (ev) {
          if (ev.type === 'done') {
            AF.toast('Flow finished: ' + (ev.outputs || 0) + ' item(s) generated.', 'success', '✓');
          }
        },
        onDone: function (res) {
          btn.disabled = false;
          btn.innerHTML = original;
          if (res && res.ok === false && res.error) {
            AF.toast(res.error, 'danger', '✕');
          }
        },
        onError: function (msg) {
          btn.disabled = false;
          btn.innerHTML = original;
          AF.toast(msg, 'danger', '✕');
        }
      });
    });
  });
})();
