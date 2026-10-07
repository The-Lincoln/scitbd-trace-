/* ==========================================================================
   AutoFlows — settings: model list, save, connection test, brand preview
   ========================================================================== */
(function () {
  'use strict';

  const form = AF.$('#settingsForm');
  if (!form) return;

  const modelSel = AF.$('#sModel');
  const saveOut  = AF.$('#saveOut');
  const testOut  = AF.$('#testOut');

  /* ----------------------------------------------------- model options --- */
  function fillModels(models, selected) {
    if (!modelSel) return;
    const keep = selected || modelSel.value;
    const seen = {};
    modelSel.innerHTML = '';
    (models || []).forEach(function (m) {
      if (seen[m.name]) return;
      seen[m.name] = 1;
      const o = document.createElement('option');
      o.value = m.name;
      o.textContent = m.name + (m.desc ? ' — ' + m.desc : '');
      if (m.name === keep) o.selected = true;
      modelSel.appendChild(o);
    });
    if (!seen[keep] && keep) {
      const o = document.createElement('option');
      o.value = keep; o.textContent = keep; o.selected = true;
      modelSel.appendChild(o);
    }
  }

  fillModels(window.__MODELS__, modelSel.dataset.selected);

  /* ----------------------------------------------------------- preview --- */
  function brandPreview() {
    const el = AF.$('#brandPreview');
    if (!el) return;
    const g = id => (AF.$('#' + id) || {}).value || '';
    const emoji = AF.$('#brand_emoji').checked ? 'allowed sparingly' : 'do NOT use emoji';
    const tags  = AF.$('#brand_hashtags').checked ? 'include 3-5' : 'include none';
    el.textContent =
      '## Brand\n' +
      '- Name: ' + (g('brand_name') || '—') + '\n' +
      '- Voice: ' + (g('brand_voice') || '—') + '\n' +
      '- Audience: ' + (g('brand_audience') || '—') + '\n' +
      '- Product: ' + (g('brand_products') || '—') + '\n' +
      '- Link: ' + (g('brand_links') || '—') + '\n' +
      '- Call to action: ' + (g('brand_cta') || '—') + '\n' +
      '- Emoji: ' + emoji + '\n' +
      '- Hashtags: ' + tags + '\n';
  }

  ['brand_name', 'brand_voice', 'brand_audience', 'brand_products', 'brand_links', 'brand_cta']
    .forEach(function (id) {
      const el = AF.$('#' + id);
      if (el) el.addEventListener('input', brandPreview);
    });
  ['brand_emoji', 'brand_hashtags'].forEach(function (id) {
    const el = AF.$('#' + id);
    if (el) el.addEventListener('change', brandPreview);
  });
  brandPreview();

  /* --------------------------------------------------------- sliders ----- */
  [['#sTemp', '#sTempOut'], ['#sTokens', '#sTokensOut']].forEach(function (pair) {
    const r = AF.$(pair[0]), o = AF.$(pair[1]);
    if (r && o) r.addEventListener('input', function () { o.textContent = r.value; });
  });

  /* -------------------------------------------------------------- save --- */
  function collect() {
    const v = id => { const el = AF.$(id); return el ? el.value : ''; };
    return {
      chat_provider: AF.$('#sProvider').value,
      chat_endpoint: AF.$('#sEndpoint').value.trim(),
      chat_model: modelSel.value,
      chat_temperature: AF.$('#sTemp').value,
      chat_num_predict: AF.$('#sTokens').value,
      chat_system: AF.$('#sSystem').value,
      brand_name: AF.$('#brand_name').value,
      brand_voice: AF.$('#brand_voice').value,
      brand_audience: AF.$('#brand_audience').value,
      brand_products: AF.$('#brand_products').value,
      brand_links: AF.$('#brand_links').value.trim(),
      brand_cta: AF.$('#brand_cta').value,
      brand_emoji: AF.$('#brand_emoji').checked ? 1 : 0,
      brand_hashtags: AF.$('#brand_hashtags').checked ? 1 : 0,
      oauth_google_client_id: v('#oauth_google_client_id').trim(),
      oauth_google_client_secret: v('#oauth_google_client_secret'),
      oauth_facebook_app_id: v('#oauth_facebook_app_id').trim(),
      oauth_facebook_app_secret: v('#oauth_facebook_app_secret'),
      publish_gmail_to: v('#publish_gmail_to').trim()
    };
  }

  function save() {
    const btn = AF.$('#btnSave');
    if (btn) { btn.disabled = true; btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Saving…'; }
    if (saveOut) saveOut.textContent = 'Saving…';

    AF.api('settings/save', collect()).then(function (r) {
      if (btn) { btn.disabled = false; btn.innerHTML = '<i class="bi bi-check2 me-1"></i>Save settings'; }
      if (!r.ok) {
        if (saveOut) saveOut.innerHTML = '<span class="text-danger">' + AF.esc(r.error) + '</span>';
        AF.toast(r.error, 'danger', '✕');
        return;
      }
      if (saveOut) saveOut.innerHTML = '<span class="text-success">' + r.saved + ' keys saved ✓</span>';
      AF.toast('Settings saved.', 'success', '✓');
      if (r.llm) paintStatus(r.llm);
    });
  }

  form.addEventListener('submit', function (e) { e.preventDefault(); save(); });
  const top = AF.$('#btnSaveTop');
  if (top) top.addEventListener('click', function (e) { e.preventDefault(); save(); });

  /* -------------------------------------------------------------- test --- */
  const test = AF.$('#btnTest');
  if (test) test.addEventListener('click', function () {
    test.disabled = true;
    test.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Testing…';
    testOut.textContent = '';

    AF.api('api/settings/test').then(function (r) {
      test.disabled = false;
      test.innerHTML = '<i class="bi bi-plug me-1"></i>Test connection';
      const llm = r.llm || {};
      paintStatus(llm);
      fillModels(r.models, modelSel.value);
      testOut.innerHTML = llm.ok
        ? '<span class="text-success">✓ ' + AF.esc(llm.model) + ' in ' + llm.latency_ms + 'ms</span>'
        : '<span class="text-warning">✕ unreachable — template engine will be used</span>';
      AF.toast(llm.ok ? 'Model reachable.' : 'Model unreachable (fallback still works).',
        llm.ok ? 'success' : 'warning', llm.ok ? '✓' : '⚠');
    });
  });

  function paintStatus(llm) {
    const badge = AF.$('#setBadge');
    if (badge) {
      badge.textContent = llm.ok ? 'reachable' : 'offline';
      badge.className = 'badge ' + (llm.ok ? 'text-bg-success' : 'text-bg-warning');
    }
    const st = AF.$('#setStatus');
    if (st) {
      st.innerHTML = llm.ok
        ? '<span class="text-success">reachable · ' + llm.latency_ms + ' ms</span>'
        : '<span class="text-warning">unreachable — template engine active</span>';
    }
  }

  /* ------------------------------------------------------------- reset --- */
  const reset = AF.$('#btnReset');
  if (reset) reset.addEventListener('click', function (e) {
    e.preventDefault();
    if (!confirm('Clear every override and return to the file defaults?')) return;
    AF.api('settings/reset', {}).then(function (r) {
      if (!r.ok) { AF.toast(r.error, 'danger', '✕'); return; }
      AF.toast('Reset — reloading…', 'info', '↺');
      setTimeout(function () { location.reload(); }, 600);
    });
  });

  /* -------------------------------------------------------- disconnect --- */
  AF.$$(' [data-disconnect]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      const provider = btn.getAttribute('data-disconnect');
      if (!confirm('Disconnect ' + provider + '? Publishing will be simulated until you reconnect.')) return;
      btn.disabled = true;
      AF.api('auth/disconnect', { provider: provider }).then(function (r) {
        btn.disabled = false;
        if (!r.ok) { AF.toast(r.error || 'Disconnect failed', 'danger', '✕'); return; }
        AF.toast(provider + ' disconnected.', 'info', '↺');
        setTimeout(function () { location.reload(); }, 600);
      });
    });
  });
})();
