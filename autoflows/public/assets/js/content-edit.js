/* ==========================================================================
   AutoFlows — content editor: live preview, counters, save, regenerate
   Reads the item snapshot injected as window.__ITEM__.
   ========================================================================== */
(function () {
  'use strict';

  const item = window.__ITEM__;
  const form = AF.$('#itemForm');
  if (!item || !form) return;

  const title = AF.$('#iTitle'), body = AF.$('#iBody');
  const slug = AF.$('#iSlug'), excerpt = AF.$('#iExcerpt');
  const hashtags = AF.$('#iHashtags');
  const preheader = AF.$('#iPreheader'), cta = AF.$('#iCta'), headline = AF.$('#iHeadline');
  const charCount = AF.$('#charCount');
  const msg = AF.$('#itemMsg');

  const render = AF.$('#previewRender');
  const raw    = AF.$('#previewRaw');
  const emailFrame = AF.$('#emailFrame');

  /* ---------------------------------------------------------- preview ---- */
  function emailHtml() {
    const link = 'https://example.com';
    const esc = AF.esc;
    return '<!doctype html><meta charset="utf-8">' +
      '<meta name="viewport" content="width=device-width,initial-scale=1">' +
      '<body style="margin:0;background:#f4f5f7;font-family:Arial,Helvetica,sans-serif;color:#1a1a1a">' +
      '<div style="max-width:600px;margin:0 auto;padding:20px">' +
      '<p style="display:none;max-height:0;overflow:hidden">' + esc((preheader && preheader.value) || '') + '</p>' +
      '<div style="background:#fff;border-radius:12px;padding:28px">' +
      '<p style="font-size:11px;letter-spacing:.08em;text-transform:uppercase;color:#6b7280;margin:0 0 14px">' +
      esc(item.brand || 'AutoFlows') + '</p>' +
      '<h1 style="font-size:24px;line-height:1.25;margin:0 0 18px;color:#111">' +
      esc((headline && headline.value) || title.value) + '</h1>' +
      '<div style="font-size:15px;line-height:1.65;color:#374151">' +
      AF.esc(body.value).replace(/\n/g, '<br>') + '</div>' +
      '<p style="margin:26px 0 0"><a href="' + link + '" style="display:inline-block;background:#6d28d9;' +
      'color:#fff;text-decoration:none;padding:11px 20px;border-radius:8px;font-weight:700">' +
      esc((cta && cta.value) || 'Read more') + '</a></p></div>' +
      '<p style="font-size:11px;color:#9ca3af;text-align:center;margin-top:14px">AutoFlows · unsubscribe</p>' +
      '</div></body>';
  }

  function paint() {
    const text = body.value;

    // counters
    const n = AF.countChars(text);
    if (charCount) {
      const total = item.limit ? n + AF.countChars(hashtags ? hashtags.value : '') : n;
      charCount.textContent = total + (item.limit ? ' / ' + item.limit : '');
      charCount.className = 'badge ' + (item.limit && total > item.limit ? 'text-bg-danger' : 'text-bg-dark');
    }
    const sc = AF.$('#subjectCount');
    if (sc) sc.textContent = AF.countChars(title.value) + ' / 45';
    const ec = AF.$('#excerptCount');
    if (ec) ec.textContent = AF.countChars(excerpt ? excerpt.value : '') + ' / 160';

    // preview
    if (item.isEmail) {
      if (raw && !raw.classList.contains('d-none')) raw.textContent = emailHtml();
      if (emailFrame) {
        if (!emailFrame.firstElementChild) {
          const f = document.createElement('iframe');
          f.setAttribute('sandbox', 'allow-same-origin');
          f.title = 'Email preview';
          emailFrame.appendChild(f);
        }
        emailFrame.firstElementChild.srcdoc = emailHtml();
      }
    } else {
      if (render) render.innerHTML = AF.md(text);
      if (raw && !raw.classList.contains('d-none')) raw.textContent = text;
    }
  }

  /* ----------------------------------------------------- preview modes --- */
  AF.$$('#previewMode [data-mode]').forEach(function (b) {
    b.addEventListener('click', function () {
      AF.$$('#previewMode .btn').forEach(x => x.classList.remove('active'));
      b.classList.add('active');
      const showRaw = b.dataset.mode !== 'render';
      if (render) render.classList.toggle('d-none', showRaw);
      if (raw) raw.classList.toggle('d-none', !showRaw);
      paint();
    });
  });

  [title, body, slug, excerpt, hashtags, preheader, cta, headline].forEach(function (el) {
    if (el) el.addEventListener('input', paint);
  });

  // Auto-slug from the title for blog posts.
  if (slug && title) title.addEventListener('input', function () {
    if (slug.dataset.touched === '1') return;
    slug.value = title.value.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');
  });
  if (slug) slug.addEventListener('input', function () { slug.dataset.touched = '1'; });

  /* -------------------------------------------------------------- save --- */
  form.addEventListener('submit', function (e) {
    e.preventDefault();
    const btn = AF.$('#iSave');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Saving…';

    AF.api('api/content/save', {
      id: item.id,
      title: title.value,
      body: body.value,
      slug: slug ? slug.value : '',
      excerpt: excerpt ? excerpt.value : '',
      hashtags: hashtags ? hashtags.value : '',
      status: AF.$('#iStatus').value,
      score: parseInt(AF.$('#iScore').value, 10) || 0,
      publish_at: (AF.$('#iPublish').value || '').replace('T', ' '),
      preheader: preheader ? preheader.value : undefined,
      cta: cta ? cta.value : undefined,
      headline: headline ? headline.value : undefined
    }).then(function (r) {
      btn.disabled = false;
      btn.innerHTML = '<i class="bi bi-check2 me-1"></i>Save';
      if (!r.ok) { msg.innerHTML = '<span class="text-danger">' + AF.esc(r.error) + '</span>'; return; }
      msg.innerHTML = '<span class="text-success">Saved ✓ · ' + r.chars + ' chars</span>';
      AF.toast('Content saved.', 'success', '✓');
      setTimeout(function () { msg.innerHTML = ''; }, 4000);
    });
  });

  /* -------------------------------------------------------- regenerate --- */
  const regen = AF.$('#btnRegen');
  if (regen) regen.addEventListener('click', function () {
    if (!confirm('Replace the current copy with a fresh generation?')) return;
    regen.disabled = true;
    regen.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Writing…';
    let acc = '';
    body.value = '';

    AF.stream('api/content/regenerate', { id: item.id }, {
      onEvent: function (ev) {
        if (ev.type === 'delta') {
          acc += ev.t;
          body.value = acc;
          paint();
        }
        if (ev.type === 'done' && ev.item) {
          title.value = ev.item.title || title.value;
          if (slug) slug.value = ev.item.slug || slug.value;
          if (excerpt) excerpt.value = ev.item.excerpt || excerpt.value;
          if (hashtags) hashtags.value = ev.item.hashtags || '';
          body.value = ev.item.body || acc;
          paint();
          AF.toast('Regenerated ✓', 'success', '↻');
        }
      },
      onDone: function () {
        regen.disabled = false;
        regen.innerHTML = '<i class="bi bi-arrow-repeat me-1"></i>Regenerate';
        paint();
      },
      onError: function (m) {
        regen.disabled = false;
        regen.innerHTML = '<i class="bi bi-arrow-repeat me-1"></i>Regenerate';
        AF.toast(m, 'danger', '✕');
        paint();
      }
    });
  });

  /* ------------------------------------------------------------ delete --- */
  const del = AF.$('#iDelete');
  if (del) del.addEventListener('click', function () {
    if (!confirm('Delete content #' + item.id + '?')) return;
    AF.api('api/content/delete', { id: item.id }).then(function (r) {
      if (!r.ok) { AF.toast(r.error, 'danger', '✕'); return; }
      AF.toast('Deleted.', 'success', '✓');
      setTimeout(function () { location.href = AF.url('content'); }, 350);
    });
  });

  /* ----------------------------------------------------------- publish --- */
  function doPublish(payload, btn, label) {
    const out = AF.$('#pubMsg');
    if (btn) { btn.disabled = true; btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Publishing…'; }
    if (out) out.innerHTML = '<span class="text-secondary">Publishing…</span>';
    AF.api('api/content/publish', Object.assign({ id: item.id }, payload || {})).then(function (r) {
      if (btn) { btn.disabled = false; btn.innerHTML = label; }
      if (!r.ok) {
        if (out) out.innerHTML = '<span class="text-danger">' + AF.esc(r.error || 'Publish failed') + '</span>';
        AF.toast(r.error || 'Publish failed', 'danger', '✕');
        return;
      }
      const sim = r.simulated ? ' (simulated)' : '';
      const extra = r.url ? ' <a href="' + AF.esc(r.url) + '" target="_blank" rel="noopener">View post</a>'
        : (r.message_id ? ' · Gmail id ' + AF.esc(r.message_id) : '');
      if (out) out.innerHTML = '<span class="text-success">Published ✓' + AF.esc(sim) + '</span>' + extra;
      AF.toast('Published ✓' + sim, 'success', '✓');
      setTimeout(function () { location.reload(); }, 900);
    });
  }

  const btnGmail = AF.$('#btnPublishGmail');
  if (btnGmail) {
    const label = btnGmail.innerHTML;
    btnGmail.addEventListener('click', function () {
      const to = (AF.$('#pubTo') || {}).value || '';
      if (!to || !/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(to)) {
        AF.toast('Enter a valid recipient email first.', 'warning', '⚠');
        return;
      }
      doPublish({ to: to }, btnGmail, label);
    });
  }
  const btnFb = AF.$('#btnPublishFb');
  if (btnFb) {
    const label = btnFb.innerHTML;
    btnFb.addEventListener('click', function () {
      if (!confirm('Post this to the connected Facebook Page / profile?')) return;
      doPublish({}, btnFb, label);
    });
  }
  const btnGen = AF.$('#btnPublishGeneric');
  if (btnGen) {
    const label = btnGen.innerHTML;
    btnGen.addEventListener('click', function () { doPublish({}, btnGen, label); });
  }

  paint();
})();
