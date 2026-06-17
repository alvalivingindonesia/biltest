/* =====================================================================
 * drab.js — Detailed RAB Generator frontend (ADR 0012)
 * Build in Lombok · vanilla JS · no framework · no build step · PHP 7.4 backend
 *
 * Backend contract: api/drab_api.php   (base = DRAB_API below)
 * Exposes the global render functions the app.js SPA router calls:
 *   renderDrabHome, renderDrabWizard, renderDrabDevelopments,
 *   renderDrabDevelopment, renderDrabEditor, renderDrabCatalog
 *
 * Conventions mirrored from app.js:
 *   - fetch(... , { credentials:'include' }) for GET; POST JSON for writes
 *   - UserAuth.user for login state; showAuthModal('login') to nudge
 *   - escHtml / navigate / showToast / t / getCurrentLang are global in app.js;
 *     local fallbacks are defined defensively if drab.js ever loads standalone.
 *   - Currency: fmtIDR (full thousands separators, id-ID) for ledger figures.
 *
 * Markup classes are the drab-* families defined in drab.css (choice, extra,
 * ballpark, summary, disc-tab, takeoff, slot, markups/ledger, upgrade, modal,
 * catalog, ahsp, badge). Generic surfaces reuse dir-hero, rab-card, rdtl, btn.
 * ===================================================================== */

var DRAB_API = '/api/drab_api.php';

/* ---------------------------------------------------------------------
 * Defensive local helpers — reuse app.js globals when present.
 * ------------------------------------------------------------------- */
function drabEsc(s) {
  if (typeof escHtml === 'function') return escHtml(s == null ? '' : s);
  var d = document.createElement('div');
  d.textContent = (s == null ? '' : String(s));
  return d.innerHTML;
}
function drabAttr(s) {
  // JS-escape for a single-quoted string, then HTML-escape so the value is also
  // safe inside a DOUBLE-quoted on*/value attribute and cannot break out
  // (SEC-005). Equivalent to drabOnclickArg.
  s = String(s == null ? '' : s).replace(/\\/g, '\\\\').replace(/'/g, "\\'");
  return s.replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}
function drabOnclickArg(s) {
  // A free-text value placed inside a single-quoted JS string that itself sits
  // inside a DOUBLE-quoted on* HTML attribute. JS-escape first, then HTML-escape,
  // so a name containing ' " < > & can neither break out of the handler nor the
  // attribute (HTML parser decodes the entities back before the JS runs).
  s = String(s == null ? '' : s).replace(/\\/g, '\\\\').replace(/'/g, "\\'");
  return s.replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}
function drabNav(hash) {
  if (typeof navigate === 'function') return navigate(hash);
  window.location.hash = hash;
}
function drabToast(msg, type) {
  if (typeof showToast === 'function') return showToast(msg, type);
  /* eslint-disable no-console */
  if (type === 'error') console.error(msg); else console.log(msg);
}
function drabIDR(val) {
  if (typeof fmtIDR === 'function') return fmtIDR(val);
  if (!val && val !== 0) return 'Rp 0';
  return 'Rp ' + Math.round(Number(val)).toLocaleString('id-ID');
}
function drabT(key, fallback) {
  if (typeof t === 'function') return t(key, fallback);
  return fallback !== undefined ? fallback : key;
}
function drabLang() {
  if (typeof getCurrentLang === 'function') return getCurrentLang();
  return 'en';
}
function drabLoggedIn() {
  return !!(typeof UserAuth !== 'undefined' && UserAuth && UserAuth.user);
}
function drabLogin() {
  if (typeof showAuthModal === 'function') showAuthModal('login');
  else drabNav('home');
}
function drabNum(v, dflt) {
  var n = parseFloat(v);
  return isFinite(n) ? n : (dflt === undefined ? 0 : dflt);
}
function drabQtyFmt(v) {
  if (v === null || v === undefined || v === '') return '—';
  return Number(v).toLocaleString('id-ID', { maximumFractionDigits: 2 });
}

/* Pick a bilingual label for a row carrying name_en / name_id (and any prefix). */
function drabName(row, lang, key) {
  key = key || 'name';
  lang = lang || drabLang();
  var en = row[key + '_en'] || '';
  var id = row[key + '_id'] || '';
  if (lang === 'id') return id || en;
  if (lang === 'both') return id && en && id !== en ? (en + ' / ' + id) : (en || id);
  return en || id;
}

/* GET helper -> { status, json }. */
function drabGet(action, params) {
  var url = DRAB_API + '?action=' + encodeURIComponent(action);
  if (params) {
    Object.keys(params).forEach(function (k) {
      var v = params[k];
      if (v !== undefined && v !== null && v !== '') {
        url += '&' + encodeURIComponent(k) + '=' + encodeURIComponent(v);
      }
    });
  }
  return fetch(url, { credentials: 'include' }).then(function (r) {
    return r.json().then(function (j) { return { status: r.status, json: j }; })
      .catch(function () { return { status: r.status, json: null }; });
  });
}
/* POST JSON helper -> { status, json }. */
function drabPost(action, body) {
  return fetch(DRAB_API + '?action=' + encodeURIComponent(action), {
    method: 'POST',
    credentials: 'include',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(body || {})
  }).then(function (r) {
    return r.json().then(function (j) { return { status: r.status, json: j }; })
      .catch(function () { return { status: r.status, json: null }; });
  });
}

/* Shared fragments. */
function drabSpinner() {
  return '<div class="page-loading"><div class="page-loading-spinner"></div></div>';
}
function drabHero(title, desc) {
  return ''
    + '<div class="dir-hero"><div class="container">'
    + '  <h1 class="dir-hero-title">' + drabEsc(title) + '</h1>'
    + (desc ? '  <p class="dir-hero-desc">' + drabEsc(desc) + '</p>' : '')
    + '</div></div>';
}
function drabErrorCard(msg) {
  return '<div class="rab-card" style="text-align:center;color:var(--color-text-faint);padding:var(--space-8)">'
    + drabEsc(msg || 'Something went wrong. Please try again.') + '</div>';
}

/* =====================================================================
 * UPGRADE PROMPT — tasteful, sells the benefit (never a raw error).
 * ===================================================================== */
var DRAB_UPGRADE_COPY = {
  drab_generate: {
    title: 'Unlock the Detailed RAB Generator',
    copy: 'Generate a full, itemised Bill of Quantities from your design in seconds.',
    benefits: ['Four-discipline breakdown', 'Editable line by line', 'Take-offs & rate transparency']
  },
  drab_save_multi: {
    title: 'Save your whole development',
    copy: 'Your free plan keeps one saved project. Upgrade to manage a whole development — multiple buildings with a roll-up total and version history.',
    benefits: ['Unlimited projects & buildings', 'Development roll-up totals', 'Keep every version']
  },
  drab_confirmed_pricing: {
    title: 'Reveal confirmed pricing',
    copy: 'Free estimates use indicative regional rates. Upgrade to reveal confirmed, project-calibrated rates sourced from real Lombok build data.',
    benefits: ['Confirmed line-item rates', 'Calibrated from real BoQ data', 'Tighter, defensible budgets']
  },
  drab_split_view: {
    title: 'Split material & labour',
    copy: 'Break every line into separate material and labour columns — the format quantity surveyors and contractors expect.',
    benefits: ['Material vs labour columns', 'Discipline sub-totals', 'Tender-ready layout']
  },
  drab_export_clean: {
    title: 'Clean, watermark-free exports',
    copy: 'Export a polished Excel, PDF or CSV bill of quantities ready to send to suppliers and contractors — no preview watermark.',
    benefits: ['Watermark-free Excel & PDF', 'Final-summary + discipline sheets', 'Ready to issue for tender']
  },
  drab_catalog_browse: {
    title: 'Browse the full work-item catalog',
    copy: 'Search every priced work item across Structure, Architecture and MEP, with rates and units.',
    benefits: ['200+ priced work items', 'Material & labour rates', 'Filter by discipline']
  },
  drab_templates: {
    title: 'Save your own templates',
    copy: 'Turn any RAB into a reusable template so your next project starts from your own proven spec.',
    benefits: ['Reusable spec templates', 'Faster project starts', 'Consistent estimating']
  }
};
function drabUpgradeHtml(featureKey, detail, inline) {
  var c = DRAB_UPGRADE_COPY[featureKey] || {
    title: 'Upgrade to unlock this',
    copy: 'This is a premium feature of the Detailed RAB Generator.',
    benefits: []
  };
  var extra = (detail && detail.message) ? detail.message : '';
  var benefits = (c.benefits || []).map(function (b) { return '<li>' + drabEsc(b) + '</li>'; }).join('');
  return ''
    + '<div class="drab-upgrade' + (inline ? ' drab-upgrade--inline' : '') + '">'
    + '  <div class="drab-upgrade-eyebrow">★ Premium</div>'
    + '  <h3 class="drab-upgrade-title">' + drabEsc(c.title) + '</h3>'
    + '  <p class="drab-upgrade-copy">' + drabEsc(c.copy) + (extra ? ' ' + drabEsc(extra) : '') + '</p>'
    + (benefits ? '  <ul class="drab-upgrade-benefits">' + benefits + '</ul>' : '')
    + '  <div class="drab-upgrade-actions">'
    + '    <button class="btn btn--primary" onclick="drabOpenUpgrade()">View plans</button>'
    + '    <button class="btn btn--ghost btn--sm" onclick="drabCloseModal()">Not now</button>'
    + '  </div>'
    + '</div>';
}
function drabOpenUpgrade() {
  drabCloseModal();
  drabNav('list-your-business'); // pricing / plans surface used elsewhere on the site
}

/* Modal shell. .drab-modal only sets radius in CSS, so give it a surface here. */
function drabShowModal(innerHtml, opts) {
  drabCloseModal();
  opts = opts || {};
  var overlay = document.createElement('div');
  overlay.className = 'drab-modal-overlay';
  overlay.id = 'drab-modal-overlay';
  var surfaceStyle = 'position:relative;background:var(--color-surface-2);border:1px solid var(--color-border);'
    + 'box-shadow:var(--shadow-lg);padding:var(--space-6);'
    + (opts.wide ? 'max-width:760px;' : '');
  overlay.innerHTML = ''
    + '<div class="drab-modal" role="dialog" aria-modal="true" style="' + surfaceStyle + '">'
    + '  <button class="drab-modal-close" aria-label="Close" onclick="drabCloseModal()">✕</button>'
    + '  <div class="drab-modal-body">' + innerHtml + '</div>'
    + '</div>';
  overlay.addEventListener('click', function (e) {
    if (e.target === overlay) drabCloseModal();
  });
  document.body.appendChild(overlay);
  document.addEventListener('keydown', drabModalEsc);
}
function drabModalEsc(e) { if (e.key === 'Escape') drabCloseModal(); }
function drabCloseModal() {
  var o = document.getElementById('drab-modal-overlay');
  if (o) o.remove();
  document.removeEventListener('keydown', drabModalEsc);
}
function drabShowUpgrade(featureKey, detail) {
  drabShowModal(drabUpgradeHtml(featureKey, detail, false));
}
/* If the response is an upgrade gate, show the prompt and return true. */
function drabHandledUpgrade(res) {
  if (res && res.json && res.json.error === 'upgrade_required') {
    drabShowUpgrade(res.json.feature, res.json.detail);
    return true;
  }
  return false;
}

/* =====================================================================
 * TOOLTIPS — any element with data-drab-tip="…" shows a floating bubble on
 * hover / focus / tap. The bubble lives on <body> so table overflow never
 * clips it. drabTipInit() is idempotent — one delegated listener serves the
 * whole SPA for the session.
 * ===================================================================== */
var _drabTipEl = null, _drabTipFor = null, _drabTipBound = false, _drabTipPinned = false;
function drabTipTarget(node) {
  while (node && node !== document) {
    if (node.nodeType === 1 && node.getAttribute && node.getAttribute('data-drab-tip')) return node;
    node = node.parentNode;
  }
  return null;
}
function drabTipShowFor(el) {
  var msg = el.getAttribute('data-drab-tip');
  if (!msg) return;
  if (!_drabTipEl) {
    _drabTipEl = document.createElement('div');
    _drabTipEl.className = 'drab-tip';
    _drabTipEl.setAttribute('role', 'tooltip');
    document.body.appendChild(_drabTipEl);
  }
  _drabTipEl.textContent = msg; // plain text — never inject HTML
  _drabTipEl.style.display = 'block';
  _drabTipEl.style.visibility = 'hidden';
  var r = el.getBoundingClientRect();
  var tr = _drabTipEl.getBoundingClientRect();
  var below = (r.top - tr.height - 10) < 8;
  var top = below ? r.bottom + 8 : r.top - tr.height - 8;
  var left = r.left + (r.width / 2) - (tr.width / 2);
  left = Math.max(8, Math.min(left, window.innerWidth - tr.width - 8));
  _drabTipEl.style.top = (top + window.pageYOffset) + 'px';
  _drabTipEl.style.left = (left + window.pageXOffset) + 'px';
  _drabTipEl.classList.toggle('drab-tip--below', below);
  _drabTipEl.style.visibility = 'visible';
}
function drabTipHide() { if (_drabTipEl) _drabTipEl.style.display = 'none'; _drabTipFor = null; _drabTipPinned = false; }
function drabTipInit() {
  if (_drabTipBound) return;
  _drabTipBound = true;
  // Hover / focus = transient preview. Tap on an info dot = pin (stays until
  // re-tapped or a tap elsewhere). Pinning by tap (not by display state) keeps the
  // first tap reliable on hybrid devices that also emit a synthetic mouseover.
  document.addEventListener('mouseover', function (e) {
    if (_drabTipPinned) return;
    var el = drabTipTarget(e.target);
    if (!el || el === _drabTipFor) return;
    _drabTipFor = el; drabTipShowFor(el);
  });
  document.addEventListener('mouseout', function (e) {
    if (_drabTipPinned || !_drabTipFor) return;
    var to = e.relatedTarget;
    if (to && _drabTipFor.contains && _drabTipFor.contains(to)) return;
    drabTipHide();
  });
  document.addEventListener('focusin', function (e) {
    if (_drabTipPinned) return;
    var el = drabTipTarget(e.target);
    if (el) { _drabTipFor = el; drabTipShowFor(el); }
  });
  document.addEventListener('focusout', function () { if (!_drabTipPinned) drabTipHide(); });
  document.addEventListener('click', function (e) {
    var el = drabTipTarget(e.target);
    // Pure info affordances (info dots, plain confidence dots) toggle a pinned tip
    // on tap. The locked confidence dot is excluded — it has its own upgrade action.
    var pinnable = el && el.classList && (el.classList.contains('drab-info')
      || (el.classList.contains('drab-conf-dot') && !el.classList.contains('drab-conf-dot--locked')));
    if (pinnable) {
      e.preventDefault();
      if (_drabTipPinned && _drabTipFor === el) { drabTipHide(); }
      else { _drabTipFor = el; drabTipShowFor(el); _drabTipPinned = true; }
      return;
    }
    drabTipHide();
  });
  window.addEventListener('scroll', function () { drabTipHide(); }, true);
}

/* =====================================================================
 * META cache — the wizard + editor forms + catalog need the lookups.
 * ===================================================================== */
var _drabMeta = null;
function drabLoadMeta() {
  if (_drabMeta) return Promise.resolve(_drabMeta);
  return drabGet('meta').then(function (res) {
    if (res.json && res.json.ok) { _drabMeta = res.json.meta; return _drabMeta; }
    throw new Error('Unable to load the generator catalog.');
  });
}

/* =====================================================================
 * 1) HOME / LANDING
 * ===================================================================== */
async function renderDrabHome(el) {
  el.innerHTML = ''
    + drabHero(
        drabT('drab.home_title', 'Detailed RAB Generator'),
        drabT('drab.home_desc', 'Turn your design brief into a full, itemised Bill of Quantities — calibrated for building in Lombok.'))
    + '<div class="section"><div class="container">'
    + '  <div class="drab-landing">'
    + '    <div class="drab-landing-lead" style="margin-bottom:var(--space-8)">'
    + '      <h2 style="font-family:var(--font-display);font-size:var(--text-2xl);margin-bottom:var(--space-3)">From design brief to a contractor-ready bill of quantities</h2>'
    + '      <p style="color:var(--color-text-muted);max-width:62ch;margin-bottom:var(--space-5)">Answer five quick steps — style, structure &amp; roof, size &amp; extras, finishes, and site — and the generator builds a complete Rencana Anggaran Biaya across Preliminaries, Structure, Architecture and MEP. Every choice is optional with sensible defaults. Then refine every line, take off quantities, swap specifications, and export.</p>'
    + '      <div style="display:flex;gap:var(--space-3);flex-wrap:wrap">'
    + '        <button class="btn btn--primary btn--lg" onclick="drabNav(\'drab-wizard\')">' + drabT('drab.start_new', 'Start a new RAB') + '</button>'
    + (drabLoggedIn()
        ? '        <button class="btn btn--outline" onclick="drabNav(\'drab-dashboard\')">' + drabT('drab.my_projects', 'My projects') + '</button>'
        : '')
    + '      </div>'
    + '    </div>'
    + '    <div class="drab-choice-grid">'
    + drabFeature('Itemised, not a ballpark', 'Real construction line items with quantities, units and rates — across four disciplines.')
    + drabFeature('Built for Lombok', 'Zone, distance and access factors adjust material and labour to your actual site.')
    + drabFeature('Editable & transparent', 'Adjust any line, break it into a take-off, and see exactly how each rate is built up.')
    + drabFeature('Export-ready', 'Send a clean Excel, PDF or CSV bill of quantities to suppliers and contractors.')
    + '    </div>'
    + '  </div>'
    + '  <div id="drab-home-saved" style="margin-top:var(--space-8)">' + (drabLoggedIn() ? drabSpinner() : drabHomeGuestNudge()) + '</div>'
    + '</div></div>';

  if (drabLoggedIn()) {
    try {
      var res = await drabGet('developments');
      var wrap = el.querySelector('#drab-home-saved');
      if (!wrap) return;
      if (res.json && res.json.ok) {
        var devs = res.json.developments || [];
        wrap.innerHTML = devs.length === 0 ? '' : drabSavedList(devs, res.json.can_save_multi);
      } else { wrap.innerHTML = ''; }
    } catch (e) {
      var w2 = el.querySelector('#drab-home-saved');
      if (w2) w2.innerHTML = '';
    }
  }
}
function drabFeature(title, body) {
  // styled as a static choice card (non-interactive)
  return ''
    + '<div class="drab-choice-card" style="cursor:default">'
    + '  <span class="drab-choice-name" style="padding-right:0">' + drabEsc(title) + '</span>'
    + '  <span class="drab-choice-desc">' + drabEsc(body) + '</span>'
    + '</div>';
}
function drabHomeGuestNudge() {
  return ''
    + '<div class="rab-card" style="padding:var(--space-5);display:flex;align-items:center;justify-content:space-between;gap:var(--space-4);flex-wrap:wrap">'
    + '  <p style="margin:0;color:var(--color-text-muted)">Create a free account to save your projects, keep version history, and pick up where you left off.</p>'
    + '  <button class="btn btn--outline btn--sm" onclick="drabLogin()">' + drabT('drab.sign_in', 'Sign in or register') + '</button>'
    + '</div>';
}
function drabSavedList(devs, canSaveMulti) {
  var rows = devs.map(function (d) { return drabDevCard(d); }).join('');
  return ''
    + '<div class="rdtl-toolbar"><h2 class="rdtl-section-title">' + drabT('drab.your_projects', 'Your projects') + '</h2>'
    + '<a href="#drab-dashboard" class="btn btn--ghost btn--sm" onclick="drabNav(\'drab-dashboard\');return false;">Open dashboard</a></div>'
    + '<div class="rdtl-projects-list">' + rows + '</div>'
    + (!canSaveMulti ? '<p style="margin-top:var(--space-3);color:var(--color-text-faint);font-size:var(--text-sm)">Free plan keeps one project. <a href="#" onclick="drabShowUpgrade(\'drab_save_multi\');return false;">Upgrade to save more</a>.</p>' : '');
}

/* =====================================================================
 * 2) THE 7-STEP WIZARD
 * ===================================================================== */
var _drabWiz = null; // { step, meta, state, developmentId }

async function renderDrabWizard(el) {
  if (!drabLoggedIn()) {
    el.innerHTML = drabHero(drabT('drab.home_title', 'Detailed RAB Generator'),
        'Sign in to generate and save your detailed bill of quantities.')
      + '<div class="section"><div class="container" style="text-align:center;padding:var(--space-12) 0;">'
      + '  <button class="btn btn--primary" onclick="drabLogin()">' + drabT('drab.sign_in', 'Sign in to continue') + '</button>'
      + '</div></div>';
    return;
  }

  // optional development_id (add a building to an existing development)
  var devId = 0;
  try {
    var hashQ = (window.location.hash.split('?')[1] || '');
    hashQ.split('&').forEach(function (p) {
      var kv = p.split('=');
      if (kv[0] === 'development_id') devId = parseInt(kv[1], 10) || 0;
    });
  } catch (e) {}

  el.innerHTML = drabHero(drabT('drab.wizard_title', 'New Detailed RAB'),
      drabT('drab.wizard_desc', 'Five quick steps to a full, itemised bill of quantities — every choice is optional, with sensible defaults pre-picked.'))
    + '<div class="section"><div class="container"><div id="drab-wiz-mount">' + drabSpinner() + '</div></div></div>';

  var meta;
  try { meta = await drabLoadMeta(); }
  catch (e) { el.querySelector('#drab-wiz-mount').innerHTML = drabErrorCard(e.message); return; }

  var firstStyle = (meta.styles && meta.styles[0]) ? meta.styles[0] : null;
  _drabWiz = {
    step: 1,
    meta: meta,
    developmentId: devId,
    state: {
      style_code: firstStyle ? firstStyle.code : '',
      structure_code: firstStyle ? firstStyle.default_structure : (meta.structures[0] ? meta.structures[0].code : ''),
      roof_code: firstStyle ? firstStyle.default_roof : (meta.roofs[0] ? meta.roofs[0].code : ''),
      finish_tier: (meta.tiers && meta.tiers.some(function (x) { return x.code === 'standard'; })) ? 'standard' : (meta.tiers[0] ? meta.tiers[0].code : ''),
      floor_code: '', // '' = Auto (match the finish tier)
      floors: 1,
      area_l1: '', area_l2: '', area_l3: '', area_other: '',
      footprint_m2: '',
      bedrooms: '', bathrooms: '',
      has_pool: 0, pool_area: '',
      has_rooftop: 0, rooftop_area: '',
      has_deck: 0, deck_area: '',
      has_pergola: 0, pergola_area: '',
      has_carport: 0, carport_area: '',
      boundary_len: '',
      site_mode: 'preset',
      zone_preset: (meta.zones && meta.zones[0]) ? meta.zones[0].code : '',
      base_zone: 'south', distance_band: 'near', access_level: 'easy',
      development_name: '', building_name: '',
      lang: drabLang()
    }
  };
  drabWizRender(el.querySelector('#drab-wiz-mount'));
}

var DRAB_WIZ_STEPS = [
  { n: 1, label: 'Style' }, { n: 2, label: 'Structure & roof' },
  { n: 3, label: 'Size & extras' }, { n: 4, label: 'Finishes' }, { n: 5, label: 'Site' }
];
var DRAB_WIZ_LAST = DRAB_WIZ_STEPS.length;

function drabWizRender(mount) {
  var w = _drabWiz, lang = w.state.lang;

  var stepper = '<div class="wizard-stepper" role="tablist">' + DRAB_WIZ_STEPS.map(function (st) {
    var cls = st.n === w.step ? 'is-active' : (st.n < w.step ? 'is-complete' : 'is-disabled');
    return '<button type="button" class="wizard-step-tab ' + cls + '" data-step="' + st.n + '" role="tab"'
      + ' aria-label="Step ' + st.n + ': ' + drabEsc(st.label) + '">'
      + '<span class="wizard-step-num">' + st.n + '</span>'
      + '<span class="wizard-step-label"><small>Step ' + st.n + '</small>' + drabEsc(st.label) + '</span></button>';
  }).join('') + '</div>';

  // Compact progress header for narrow screens (CSS shows this, hides the tab row).
  var curLabel = (DRAB_WIZ_STEPS[w.step - 1] || {}).label || '';
  var mProgress = ''
    + '<div class="drab-wiz-msteps" aria-hidden="true">'
    + '  <div class="drab-wiz-msteps-row"><span>Step ' + w.step + ' of ' + DRAB_WIZ_LAST + '</span><strong>' + drabEsc(curLabel) + '</strong></div>'
    + '  <div class="drab-wiz-mbar"><span style="width:' + Math.round((w.step / DRAB_WIZ_LAST) * 100) + '%"></span></div>'
    + '</div>';

  var panes = ''
    + drabWizPane(1, drabWizStepStyle(lang))
    + drabWizPane(2, drabWizStepStructureRoof(lang))
    + drabWizPane(3, drabWizStepSizeExtras())
    + drabWizPane(4, drabWizStepFinishes(lang))
    + drabWizPane(5, drabWizStepSite(lang));

  var isLast = w.step === DRAB_WIZ_LAST;
  var footer = ''
    + '<div class="wizard-footer">'
    + '  <div class="wizard-total drab-ballpark">'
    + '    <span class="drab-ballpark-label">' + drabT('drab.indicative_ballpark', 'Indicative ballpark') + '</span>'
    + '    <span class="drab-ballpark-value" id="drab-wiz-total">' + drabIDR(0) + '</span>'
    + '    <span class="drab-ballpark-foot">Rough order-of-magnitude — the generated RAB is itemised line-by-line.</span>'
    + '  </div>'
    + '  <div class="wizard-nav">'
    + '    <button type="button" class="btn btn--ghost btn--sm" id="drab-wiz-back"' + (w.step === 1 ? ' disabled' : '') + '>Back</button>'
    + (isLast
        ? '    <button type="button" class="btn btn--primary btn--lg" id="drab-wiz-generate">' + drabT('drab.generate', 'Generate RAB') + '</button>'
        : '    <button type="button" class="btn btn--primary" id="drab-wiz-next">Next</button>')
    + '  </div>'
    + '</div>';

  mount.innerHTML = '<form class="wizard drab-wizard" id="drab-wiz-form" autocomplete="off">'
    + stepper + mProgress + '<div class="wizard-body">' + panes + '</div>' + footer + '</form>';

  drabWizBind(mount);
  drabWizRecalc();
  drabTipInit();
}
function drabWizPane(n, inner) {
  return '<div class="wizard-pane' + (n === _drabWiz.step ? ' is-active' : '') + '" data-pane="' + n + '">' + inner + '</div>';
}

/* choice card with tick + optional status badge */
function drabChoiceCard(group, code, title, desc, active, statusBadge) {
  return ''
    + '<button type="button" class="drab-choice-card' + (active ? ' is-selected' : '') + '" data-' + group + '="' + drabEsc(code) + '">'
    + (statusBadge || '')
    + '  <span class="drab-choice-check" aria-hidden="true">✓</span>'
    + '  <span class="drab-choice-name">' + drabEsc(title) + '</span>'
    + (desc ? '  <span class="drab-choice-desc">' + drabEsc(desc) + '</span>' : '')
    + '</button>';
}
function drabStatusBadge(status) {
  if (status === 'calibrated') return '<span class="drab-badge drab-badge--calibrated">Calibrated</span>';
  return '<span class="drab-badge drab-badge--beta">Indicative beta</span>';
}

/* Consistent step/sub headings with an "Optional" pill + an info tooltip dot. */
function drabOptionalPill(optional) {
  return optional
    ? '<span class="drab-optional-pill" data-drab-tip="Optional — we pre-pick a sensible default. Change it or just continue.">Optional</span>'
    : '<span class="drab-required-pill" data-drab-tip="At least the ground-floor area is needed to generate a RAB.">Needed</span>';
}
function drabInfoDot(tip) {
  return tip ? '<span class="drab-info" tabindex="0" role="img" aria-label="More info" data-drab-tip="' + drabEsc(tip) + '">i</span>' : '';
}
function drabStepHead(title, optional, hint, tip) {
  return '<div class="drab-step-head"><h4>' + drabEsc(title) + '</h4>' + drabOptionalPill(optional) + ' ' + drabInfoDot(tip) + '</div>'
    + (hint ? '<p class="wizard-hint">' + hint + '</p>' : '');
}
function drabSubHead(title, optional, hint, tip) {
  return '<div class="drab-sub-head"><h5 class="drab-sub-title">' + drabEsc(title) + '</h5>' + drabOptionalPill(optional) + ' ' + drabInfoDot(tip) + '</div>'
    + (hint ? '<p class="wizard-hint" style="margin-bottom:var(--space-3)">' + hint + '</p>' : '');
}
function drabDefaultNote(label) {
  return label ? '<p class="drab-default-note">Your style suggests <strong>' + drabEsc(label) + '</strong> — tap any card to change.</p>' : '';
}

/* ---- Step 1: Style ---- */
function drabWizStepStyle(lang) {
  var s = _drabWiz.state;
  var cards = (_drabWiz.meta.styles || []).map(function (st) {
    return drabChoiceCard('style', st.code, drabName(st, lang), drabName(st, lang, 'description'), st.code === s.style_code, drabStatusBadge(st.status));
  }).join('');
  return ''
    + drabStepHead(drabT('drab.step_style', 'Architectural style'), true,
        'Sets the wall ratio and pre-selects a matching structure, roof and finish — all of which you can change. Not sure? Leave the default and continue.',
        'The style is a template: it controls the wall-to-floor ratio and which items appear. “Calibrated” styles are priced from real Lombok build data; “Indicative beta” are composed from regional references.')
    + '<div class="drab-choice-grid">' + cards + '</div>';
}

/* ---- Step 2: Structure & roof (two optional axes on one pane) ---- */
function drabWizStepStructureRoof(lang) {
  var s = _drabWiz.state, meta = _drabWiz.meta;
  var structCards = (meta.structures || []).map(function (st) {
    return drabChoiceCard('structure', st.code, drabName(st, lang), drabName(st, lang, 'description'), st.code === s.structure_code, '');
  }).join('');
  var roofCards = (meta.roofs || []).map(function (st) {
    return drabChoiceCard('roof', st.code, drabName(st, lang), drabName(st, lang, 'description'), st.code === s.roof_code, '');
  }).join('');
  var style = (meta.styles || []).filter(function (x) { return x.code === s.style_code; })[0];
  var defStruct = style ? (meta.structures || []).filter(function (x) { return x.code === style.default_structure; })[0] : null;
  var defRoof = style ? (meta.roofs || []).filter(function (x) { return x.code === style.default_roof; })[0] : null;
  return ''
    + drabStepHead('Structure & roof', true,
        'How the building stands up and what tops it. Both are optional — your style already picked a sensible pair.', '')
    + drabSubHead('Structural system', true, '', 'Frame, walls and foundations. Full RCC is a concrete frame throughout; batu-kali + masonry + light-steel is the common Lombok house build.')
    + drabDefaultNote(defStruct ? drabName(defStruct, lang) : '')
    + '<div class="drab-choice-grid">' + structCards + '</div>'
    + '<div style="height:var(--space-6)"></div>'
    + drabSubHead('Roof system', true, '', 'The roof structure and covering — tiles, a concrete flat dak, timber, thatch or metal.')
    + drabDefaultNote(defRoof ? drabName(defRoof, lang) : '')
    + '<div class="drab-choice-grid">' + roofCards + '</div>';
}

/* ---- Step 3: Size, floors & extras ---- */
function drabWizStepSizeExtras() {
  var s = _drabWiz.state;
  function fld(name, label, ph, show) {
    return '<div class="rab-field"' + (show ? '' : ' style="display:none"') + ' data-fld="' + name + '">'
      + '<label class="rab-label">' + drabEsc(label) + '</label>'
      + '<input type="number" class="rab-input" data-state="' + name + '" min="0" step="0.5" value="' + drabEsc(s[name]) + '" placeholder="' + drabEsc(ph) + '"></div>';
  }
  function extra(flag, areaKey, label, ph, isLen) {
    var on = parseInt(s[flag], 10) === 1 || (isLen && drabNum(s[areaKey]) > 0);
    return ''
      + '<label class="drab-extra">'
      + '  <input type="checkbox" data-extra="' + flag + '"' + (on ? ' checked' : '') + '>'
      + '  <span class="drab-extra-inner">'
      + '    <span class="drab-extra-switch" aria-hidden="true"></span>'
      + '    <span class="drab-extra-label">' + drabEsc(label) + '</span>'
      + '  </span>'
      + '  <span class="drab-extra-area">'
      + '    <input type="number" class="rab-input" data-state="' + areaKey + '" min="0" step="' + (isLen ? '1' : '0.5') + '" value="' + drabEsc(s[areaKey]) + '" placeholder="' + drabEsc(ph) + '">'
      + '  </span>'
      + '</label>';
  }
  var n = parseInt(s.floors, 10) || 1;
  return ''
    + drabStepHead('Size & extras', false,
        'The only inputs that really matter — enter at least the ground-floor area. Everything else is optional.',
        'Built area drives most of the cost. Leave an upper floor blank and we assume it matches the floor below.')
    + '<div class="rab-field" style="max-width:280px">'
    + '  <label class="rab-label">Number of floors ' + drabInfoDot('Storeys above ground. Structure is itemised per storey in the result.') + '</label>'
    + '  <select class="rab-select" data-state="floors">'
    + [1, 2, 3, 4].map(function (i) { return '<option value="' + i + '"' + (i === n ? ' selected' : '') + '>' + i + (i === 4 ? '+' : '') + ' floor' + (i === 1 ? '' : 's') + '</option>'; }).join('')
    + '  </select>'
    + '</div>'
    + '<div class="rab-fields-grid" style="margin-top:var(--space-3)">'
    + fld('area_l1', 'Ground floor (m²)', 'e.g. 150', true)
    + fld('area_l2', '1st floor (m²)', 'e.g. 120', n >= 2)
    + fld('area_l3', '2nd floor (m²)', 'e.g. 100', n >= 3)
    + fld('area_other', 'Upper floors (m²)', 'e.g. 80', n >= 4)
    + '</div>'
    + '<div class="rab-fields-grid" style="margin-top:var(--space-3)">'
    + '  <div class="rab-field"><label class="rab-label">Footprint (m²) <small style="color:var(--color-text-faint)">optional</small> ' + drabInfoDot('Ground-floor outline used for foundations, slab and roof area. Defaults to the ground-floor area.') + '</label>'
    + '    <input type="number" class="rab-input" data-state="footprint_m2" min="0" step="0.5" value="' + drabEsc(s.footprint_m2) + '" placeholder="defaults to ground floor"></div>'
    + '  <div class="rab-field"><label class="rab-label">Bedrooms</label>'
    + '    <input type="number" class="rab-input" data-state="bedrooms" min="0" step="1" value="' + drabEsc(s.bedrooms) + '" placeholder="e.g. 3"></div>'
    + '  <div class="rab-field"><label class="rab-label">Bathrooms ' + drabInfoDot('Drives sanitary, waterproofing and plumbing quantities.') + '</label>'
    + '    <input type="number" class="rab-input" data-state="bathrooms" min="0" step="1" value="' + drabEsc(s.bathrooms) + '" placeholder="e.g. 3"></div>'
    + '</div>'
    + '<hr style="border:none;border-top:1px solid var(--color-border);margin:var(--space-6) 0 var(--space-5)">'
    + drabSubHead('Outdoor & extras', true, 'Toggle what applies — each reveals its size.', 'Pools, decks, rooftops, pergolas, carports and boundary walls are added as their own costed items.')
    + '<div class="drab-extras-grid">'
    + extra('has_pool', 'pool_area', 'Swimming pool', 'Pool area (m²) e.g. 32', false)
    + extra('has_rooftop', 'rooftop_area', 'Walkable rooftop', 'Rooftop area (m²) e.g. 60', false)
    + extra('has_deck', 'deck_area', 'Decking / terrace', 'Deck area (m²) e.g. 40', false)
    + extra('has_pergola', 'pergola_area', 'Pergola', 'Pergola area (m²) e.g. 18', false)
    + extra('has_carport', 'carport_area', 'Carport', 'Carport area (m²) e.g. 24', false)
    + extra('has_boundary', 'boundary_len', 'Boundary wall', 'Wall length (m) e.g. 80', true)
    + '</div>';
}

/* ---- Step 4: Finishes (finish tier + optional floor type) ---- */
function drabWizStepFinishes(lang) {
  var s = _drabWiz.state, meta = _drabWiz.meta;
  var tierCards = (meta.tiers || []).map(function (ti) {
    return drabChoiceCard('tier', ti.code, drabName(ti, lang), drabName(ti, lang, 'description'), ti.code === s.finish_tier, '');
  }).join('');
  var floors = meta.floors || [];
  var floorHtml = '';
  if (floors.length) {
    var autoCard = drabChoiceCard('floor', '', 'Auto — match finish tier', 'Let the finish tier choose the floor for you.', s.floor_code === '', '');
    var floorCards = floors.map(function (f) {
      return drabChoiceCard('floor', f.code, drabName(f, lang), drabName(f, lang, 'description'), f.code === s.floor_code, drabStatusBadge(f.status));
    }).join('');
    floorHtml = ''
      + '<div style="height:var(--space-6)"></div>'
      + drabSubHead('Floor type', true, 'Pick a specific floor, or leave it on Auto to follow the finish tier.', 'Sets the indoor floor finish across the build. You can still swap any individual line later in the editor. Decking and rooftop floors are set by their own extras.')
      + '<div class="drab-choice-grid">' + autoCard + floorCards + '</div>';
  }
  return ''
    + drabStepHead('Finishes', true, 'How polished the result is — and what you walk on.', '')
    + drabSubHead('Finish tier', true, '', 'Budget → Signature. Sets the default specification (and price level) for walls, ceilings, paint, fixtures and — unless you choose one below — the floor.')
    + '<div class="drab-choice-grid">' + tierCards + '</div>'
    + floorHtml;
}

/* ---- Step 7: Site & logistics + naming + language ---- */
function drabWizStepSite(lang) {
  var s = _drabWiz.state;
  var zoneOpts = (_drabWiz.meta.zones || []).map(function (z) {
    return '<option value="' + drabEsc(z.code) + '"' + (z.code === s.zone_preset ? ' selected' : '') + '>' + drabEsc(drabName(z, lang)) + '</option>';
  }).join('');
  function sel(name, label, opts) {
    return '<div class="rab-field"><label class="rab-label">' + drabEsc(label) + '</label>'
      + '<select class="rab-select" data-state="' + name + '">' + opts + '</select></div>';
  }
  var distOpts = ['near', 'mid', 'far'].map(function (v) { return '<option value="' + v + '"' + (v === s.distance_band ? ' selected' : '') + '>' + v.charAt(0).toUpperCase() + v.slice(1) + '</option>'; }).join('');
  var accOpts = [['easy', 'Easy'], ['moderate', 'Moderate'], ['steep', 'Steep'], ['boat', 'Boat / island']].map(function (a) { return '<option value="' + a[0] + '"' + (a[0] === s.access_level ? ' selected' : '') + '>' + a[1] + '</option>'; }).join('');
  var zoneOpts2 = [['south', 'South Lombok (Kuta / Selong Belanak)'], ['mataram', 'Mataram / West Lombok']].map(function (z) { return '<option value="' + z[0] + '"' + (z[0] === s.base_zone ? ' selected' : '') + '>' + z[1] + '</option>'; }).join('');
  var langOpts = [['en', 'English'], ['id', 'Bahasa Indonesia'], ['both', 'Both (EN / ID)']].map(function (l) { return '<option value="' + l[0] + '"' + (l[0] === s.lang ? ' selected' : '') + '>' + l[1] + '</option>'; }).join('');

  return ''
    + drabStepHead(drabT('drab.step_site', 'Site & details'), true,
        'Where you build changes material delivery and labour costs. Names are optional — we fill defaults if you skip them.',
        'A Site Factor lifts material (freight) and labour (mobilisation) off the Mataram baseline, based on distance and access. Pick a preset or set it manually.')
    + '<div style="display:flex;gap:var(--space-4);flex-wrap:wrap;margin-bottom:var(--space-3)">'
    + '  <label class="rab-check-label"><input type="radio" name="drab-site-mode" value="preset"' + (s.site_mode !== 'advanced' ? ' checked' : '') + '><span>Quick — pick a zone preset</span></label>'
    + '  <label class="rab-check-label"><input type="radio" name="drab-site-mode" value="advanced"' + (s.site_mode === 'advanced' ? ' checked' : '') + '><span>Advanced — set distance & access</span></label>'
    + '</div>'
    + '<div data-site-pane="preset"' + (s.site_mode === 'advanced' ? ' style="display:none"' : '') + '>'
    + sel('zone_preset', 'Zone preset', zoneOpts)
    + '</div>'
    + '<div data-site-pane="advanced"' + (s.site_mode === 'advanced' ? '' : ' style="display:none"') + '>'
    + '  <div class="rab-fields-grid">'
    + sel('base_zone', 'Base zone', zoneOpts2)
    + sel('distance_band', 'Distance from supply', distOpts)
    + sel('access_level', 'Site access', accOpts)
    + '  </div>'
    + '</div>'
    + '<hr style="border:none;border-top:1px solid var(--color-border);margin:var(--space-5) 0">'
    + '<div class="rab-fields-grid">'
    + (_drabWiz.developmentId
        ? '  <input type="hidden" data-state="development_name" value="">'
        : '  <div class="rab-field"><label class="rab-label">Development / project name</label>'
          + '    <input type="text" class="rab-input" data-state="development_name" value="' + drabEsc(s.development_name) + '" placeholder="e.g. Villa Selong"></div>')
    + '  <div class="rab-field"><label class="rab-label">Building name</label>'
    + '    <input type="text" class="rab-input" data-state="building_name" value="' + drabEsc(s.building_name) + '" placeholder="e.g. Main villa"></div>'
    + sel('lang', 'Document language', langOpts)
    + '</div>'
    + (_drabWiz.developmentId ? '<p class="wizard-hint">Adding a building to your existing development.</p>' : '');
}

/* ---- Wizard binding ---- */
function drabWizBind(mount) {
  var form = mount.querySelector('#drab-wiz-form');
  if (!form) return;

  form.querySelectorAll('.wizard-step-tab').forEach(function (tab) {
    tab.addEventListener('click', function () {
      var step = parseInt(tab.dataset.step, 10);
      if (step <= _drabWiz.step || tab.classList.contains('is-complete')) drabWizGoto(step, mount);
    });
  });
  var back = form.querySelector('#drab-wiz-back');
  if (back) back.addEventListener('click', function () { drabWizGoto(_drabWiz.step - 1, mount); });
  var next = form.querySelector('#drab-wiz-next');
  if (next) next.addEventListener('click', function () { drabWizGoto(_drabWiz.step + 1, mount); });
  var gen = form.querySelector('#drab-wiz-generate');
  if (gen) gen.addEventListener('click', function () { drabWizGenerate(gen); });

  // style cards -> also set default structure/roof, then re-render
  form.querySelectorAll('[data-style]').forEach(function (b) {
    b.addEventListener('click', function () {
      var code = b.getAttribute('data-style');
      _drabWiz.state.style_code = code;
      var st = (_drabWiz.meta.styles || []).find(function (x) { return x.code === code; });
      if (st) {
        if (st.default_structure) _drabWiz.state.structure_code = st.default_structure;
        if (st.default_roof) _drabWiz.state.roof_code = st.default_roof;
      }
      drabWizRender(mount);
    });
  });
  ['structure', 'roof', 'tier', 'floor'].forEach(function (group) {
    form.querySelectorAll('[data-' + group + ']').forEach(function (b) {
      b.addEventListener('click', function () {
        var key = (group === 'tier') ? 'finish_tier' : (group === 'floor' ? 'floor_code' : group + '_code');
        _drabWiz.state[key] = b.getAttribute('data-' + group) || '';
        form.querySelectorAll('[data-' + group + ']').forEach(function (x) { x.classList.remove('is-selected'); });
        b.classList.add('is-selected');
        drabWizRecalc();
      });
    });
  });

  // text/number/select state inputs
  form.querySelectorAll('[data-state]').forEach(function (inp) {
    var ev = (inp.tagName === 'SELECT') ? 'change' : 'input';
    inp.addEventListener(ev, function () {
      var key = inp.getAttribute('data-state');
      _drabWiz.state[key] = inp.value;
      if (key === 'floors') { drabWizRender(mount); return; }
      drabWizRecalc();
    });
  });

  // extras toggles (CSS handles the reveal; we only track state + clear on un-toggle)
  form.querySelectorAll('[data-extra]').forEach(function (cb) {
    cb.addEventListener('change', function () {
      var flag = cb.getAttribute('data-extra');
      var on = cb.checked ? 1 : 0;
      if (flag !== 'has_boundary') _drabWiz.state[flag] = on;
      if (!on) {
        var ak = { has_pool: 'pool_area', has_rooftop: 'rooftop_area', has_deck: 'deck_area', has_pergola: 'pergola_area', has_carport: 'carport_area', has_boundary: 'boundary_len' }[flag];
        if (ak) {
          _drabWiz.state[ak] = '';
          var inp = cb.parentNode.querySelector('[data-state="' + ak + '"]');
          if (inp) inp.value = '';
        }
      }
      drabWizRecalc();
    });
  });

  // site mode radios
  form.querySelectorAll('input[name="drab-site-mode"]').forEach(function (r) {
    r.addEventListener('change', function () {
      _drabWiz.state.site_mode = r.value;
      var p = form.querySelector('[data-site-pane="preset"]');
      var a = form.querySelector('[data-site-pane="advanced"]');
      if (p) p.style.display = (r.value === 'advanced') ? 'none' : '';
      if (a) a.style.display = (r.value === 'advanced') ? '' : 'none';
    });
  });
}

function drabWizGoto(step, mount) {
  _drabWiz.step = Math.max(1, Math.min(DRAB_WIZ_LAST, step));
  drabWizRender(mount);
}

/* Local ballpark: rough Rp/m² by tier × floor area + extras. */
// Indicative direct-cost rates per m² (BEFORE overhead/contingency/PPN) — tuned to land near the
// generated RAB's direct total so the wizard headline and the editor agree. Markups are added later.
var DRAB_TIER_PSQM = { budget: 4200000, standard: 5500000, premium: 8000000, signature: 12000000 };
var DRAB_EXTRA_PSQM = { pool_area: 4000000, rooftop_area: 1800000, deck_area: 2200000, pergola_area: 1500000, carport_area: 1500000 };
// Effective floor areas. If the user picks multiple floors but leaves an upper level blank, assume it
// mirrors the ground floor rather than silently halving the build.
function drabEffArea(s) {
  var floors = parseInt(s.floors, 10) || 1;
  var a1 = drabNum(s.area_l1), a2 = drabNum(s.area_l2), a3 = drabNum(s.area_l3), ao = drabNum(s.area_other);
  if (floors >= 2 && a2 <= 0) a2 = a1;
  if (floors >= 3 && a3 <= 0) a3 = (a2 > 0 ? a2 : a1);
  if (floors < 2) a2 = 0;
  if (floors < 3) a3 = 0;
  return { a1: a1, a2: a2, a3: a3, ao: ao, total: a1 + a2 + a3 + ao };
}
function drabWizBallpark() {
  var s = _drabWiz.state;
  var base = DRAB_TIER_PSQM[s.finish_tier] || DRAB_TIER_PSQM.standard;
  var floorArea = drabEffArea(s).total;
  var total = floorArea * base;
  Object.keys(DRAB_EXTRA_PSQM).forEach(function (k) { total += drabNum(s[k]) * DRAB_EXTRA_PSQM[k]; });
  total += drabNum(s.boundary_len) * 650000;
  if (s.site_mode === 'advanced') {
    if (s.distance_band === 'mid') total *= 1.05;
    else if (s.distance_band === 'far') total *= 1.12;
    if (s.access_level === 'steep') total *= 1.04;
    else if (s.access_level === 'boat') total *= 1.1;
  }
  return total;
}
function drabWizRecalc() {
  var el = document.getElementById('drab-wiz-total');
  if (!el) return;
  var prev = el.textContent;
  var next = drabIDR(drabWizBallpark());
  el.textContent = next;
  if (prev !== next) {
    el.classList.remove('is-pulsing');
    void el.offsetWidth;
    el.classList.add('is-pulsing');
  }
}

function drabWizState() {
  var s = _drabWiz.state;
  var ea = drabEffArea(s);
  var floorArea = ea.total;
  var body = {
    building_name: (s.building_name || '').trim() || 'Building 1',
    style_code: s.style_code, structure_code: s.structure_code, roof_code: s.roof_code,
    finish_tier: s.finish_tier,
    floor_code: s.floor_code || '',
    floors: parseInt(s.floors, 10) || 1,
    area_l1: ea.a1, area_l2: ea.a2, area_l3: ea.a3, area_other: ea.ao,
    footprint_m2: drabNum(s.footprint_m2),
    bedrooms: parseInt(s.bedrooms, 10) || 0, bathrooms: parseInt(s.bathrooms, 10) || 0,
    has_pool: parseInt(s.has_pool, 10) ? 1 : 0, pool_area: drabNum(s.pool_area),
    has_rooftop: parseInt(s.has_rooftop, 10) ? 1 : 0, rooftop_area: drabNum(s.rooftop_area),
    has_deck: parseInt(s.has_deck, 10) ? 1 : 0, deck_area: drabNum(s.deck_area),
    has_pergola: parseInt(s.has_pergola, 10) ? 1 : 0, pergola_area: drabNum(s.pergola_area),
    has_carport: parseInt(s.has_carport, 10) ? 1 : 0, carport_area: drabNum(s.carport_area),
    boundary_len: drabNum(s.boundary_len),
    lang: s.lang
  };
  if (_drabWiz.developmentId) body.development_id = _drabWiz.developmentId;
  else body.development_name = (s.development_name || '').trim() || 'My project';
  if (s.site_mode === 'advanced') {
    body.base_zone = s.base_zone; body.distance_band = s.distance_band; body.access_level = s.access_level;
  } else {
    body.zone_preset = s.zone_preset;
  }
  body._floorArea = floorArea;
  return body;
}

async function drabWizGenerate(btn) {
  var body = drabWizState();
  if (body._floorArea <= 0) {
    drabToast('Please enter the built area for at least the ground floor.', 'error');
    drabWizGoto(3, document.getElementById('drab-wiz-mount'));
    return;
  }
  delete body._floorArea;
  var orig = btn.innerHTML;
  btn.disabled = true;
  btn.innerHTML = '<span class="rab-spinner"></span> Generating…';
  try {
    var res = await drabPost('generate', body);
    if (drabHandledUpgrade(res)) { btn.disabled = false; btn.innerHTML = orig; return; }
    if (res.json && res.json.ok && res.json.rab_id) {
      drabNav('drab-editor/' + res.json.rab_id);
    } else {
      throw new Error((res.json && res.json.error) || 'Generation failed.');
    }
  } catch (e) {
    drabToast(e.message || 'Generation failed.', 'error');
    btn.disabled = false;
    btn.innerHTML = orig;
  }
}

/* =====================================================================
 * 3) DEVELOPMENTS DASHBOARD
 * ===================================================================== */
async function renderDrabDevelopments(el) {
  if (!drabLoggedIn()) {
    el.innerHTML = drabHero(drabT('drab.my_projects', 'My RAB projects'), 'Sign in to view your saved developments.')
      + '<div class="section"><div class="container" style="text-align:center;padding:var(--space-12) 0;">'
      + '  <button class="btn btn--primary" onclick="drabLogin()">' + drabT('drab.sign_in', 'Sign in to continue') + '</button>'
      + '</div></div>';
    return;
  }
  el.innerHTML = drabHero(drabT('drab.my_projects', 'My RAB projects'), 'Your developments, buildings and bills of quantities.')
    + '<div class="section"><div class="container">'
    + '  <div class="rdtl-toolbar">'
    + '    <h2 class="rdtl-section-title">Developments</h2>'
    + '    <button class="btn btn--primary" onclick="drabNav(\'drab-wizard\')">+ New RAB</button>'
    + '  </div>'
    + '  <div id="drab-dev-list">' + drabSpinner() + '</div>'
    + '</div></div>';

  try {
    var res = await drabGet('developments');
    var list = el.querySelector('#drab-dev-list');
    if (!(res.json && res.json.ok)) throw new Error((res.json && res.json.error) || 'Failed to load.');
    var devs = res.json.developments || [];
    if (devs.length === 0) {
      list.innerHTML = ''
        + '<div class="rab-empty-state">'
        + '  <h3 style="font-family:var(--font-display);margin-bottom:var(--space-2)">No projects yet</h3>'
        + '  <p style="color:var(--color-text-faint);margin-bottom:var(--space-4)">Generate your first detailed RAB to get started.</p>'
        + '  <button class="btn btn--primary" onclick="drabNav(\'drab-wizard\')">Start a new RAB</button>'
        + '</div>';
      return;
    }
    list.innerHTML = '<div class="rdtl-projects-list">' + devs.map(function (d) { return drabDevCard(d); }).join('') + '</div>'
      + (!res.json.can_save_multi ? '<p style="margin-top:var(--space-3);color:var(--color-text-faint);font-size:var(--text-sm)">Free plan keeps one project. <a href="#" onclick="drabShowUpgrade(\'drab_save_multi\');return false;">Upgrade to manage more</a>.</p>' : '');
  } catch (e) {
    el.querySelector('#drab-dev-list').innerHTML = drabErrorCard(e.message);
  }
}

/* =====================================================================
 * 4) ONE DEVELOPMENT — buildings + roll-up
 * ===================================================================== */
async function renderDrabDevelopment(el, devId) {
  devId = parseInt(devId, 10);
  if (!drabLoggedIn()) { drabNav('drab'); return; }
  if (!devId) { drabNav('drab-dashboard'); return; }

  el.innerHTML = drabHero('Development', '')
    + '<div class="section"><div class="container"><div id="drab-dev-mount">' + drabSpinner() + '</div></div></div>';

  try {
    var res = await drabGet('development', { id: devId });
    var mount = el.querySelector('#drab-dev-mount');
    if (!(res.json && res.json.ok)) throw new Error((res.json && res.json.error) || 'Not found.');
    var dev = res.json.development;
    var buildings = res.json.buildings || [];
    var rollup = res.json.rollup || 0;

    var head = el.querySelector('.dir-hero-title');
    if (head) head.textContent = dev.name;

    var buildingsHtml = buildings.length === 0
      ? '<div class="rab-card" style="text-align:center;color:var(--color-text-faint);padding:var(--space-6)">No buildings yet.</div>'
      : buildings.map(function (b) {
          var rabId = b.current_rab_id;
          return ''
            + '<div class="rab-card" style="display:flex;align-items:center;gap:var(--space-4);padding:var(--space-4);margin-bottom:var(--space-2)">'
            + '  <div style="flex:1;min-width:0">'
            + '    <div style="font-weight:600">' + drabEsc(b.name) + '</div>'
            + '    <div style="font-size:var(--text-sm);color:var(--color-text-faint)">' + (b.style_code ? drabEsc(b.style_code) + ' · ' : '')
            + (b.floors ? b.floors + ' floor' + (b.floors == 1 ? '' : 's') : '') + '</div>'
            + '  </div>'
            + '  <div style="font-variant-numeric:tabular-nums;font-weight:600;color:var(--color-primary)">' + drabIDR(b.grand || 0) + '</div>'
            + '  <div style="display:flex;align-items:center;gap:var(--space-2)">'
            + (rabId
                ? '    <button class="btn btn--outline btn--sm" onclick="drabNav(\'drab-editor/' + rabId + '\')">Open</button>'
                : '    <span style="color:var(--color-text-faint);font-size:var(--text-sm)">no RAB</span>')
            + '    <button class="rdtl-btn-icon rdtl-btn-icon--danger" title="Delete building" onclick="drabDeleteBuilding(' + b.id + ',' + devId + ')">✕</button>'
            + '  </div>'
            + '</div>';
        }).join('');

    mount.innerHTML = ''
      + '<a href="#drab-dashboard" class="rab-back-link" onclick="drabNav(\'drab-dashboard\');return false;">← All developments</a>'
      + '<div class="rdtl-editor-header">'
      + '  <div>'
      + '    <h2 class="rdtl-proj-title">' + drabEsc(dev.name) + '</h2>'
      + '    <p class="rdtl-proj-meta">' + (dev.location_text ? drabEsc(dev.location_text) + ' · ' : '') + drabEsc(dev.base_zone || '') + '</p>'
      + '    <div class="drab-dev-toolbar">'
      + '      <button class="btn btn--ghost btn--sm" data-drab-tip="Rename this project" onclick="drabRenameDevelopment(' + devId + ',\'' + drabOnclickArg(dev.name) + '\')">✎ Rename</button>'
      + '      <button class="btn btn--ghost btn--sm drab-btn-danger" data-drab-tip="Delete this project and all its buildings &amp; RABs" onclick="drabDeleteDevelopment(' + devId + ',\'' + drabOnclickArg(dev.name) + '\',true)">✕ Delete project</button>'
      + '    </div>'
      + '  </div>'
      + '  <div class="drab-summary-cell drab-summary-cell--grand">'
      + '    <span class="drab-summary-k">Development total</span>'
      + '    <span class="drab-summary-v">' + drabIDR(rollup) + '</span>'
      + '  </div>'
      + '</div>'
      + '<div class="rdtl-toolbar" style="margin:var(--space-4) 0">'
      + '  <h3 class="rdtl-section-title">Buildings</h3>'
      + '  <button class="btn btn--primary btn--sm" onclick="drabNav(\'drab-wizard?development_id=' + devId + '\')">+ Add building</button>'
      + '</div>'
      + buildingsHtml;
  } catch (e) {
    el.querySelector('#drab-dev-mount').innerHTML = drabErrorCard(e.message);
  }
}
function drabDeleteBuilding(buildingId, devId) {
  if (!window.confirm('Delete this building and its RAB? This cannot be undone.')) return;
  drabPost('delete_building', { id: buildingId }).then(function (res) {
    if (res.json && res.json.ok) {
      drabToast('Building deleted.', 'success');
      drabNav('drab-dev/' + devId);
      if (typeof router === 'function') { try { router(); } catch (e) {} }
    } else drabToast('Could not delete the building.', 'error');
  });
}

/* Re-run the current route in place (used after rename/delete on a list view). */
function drabRerouteCurrent() {
  if (typeof router === 'function') { try { router(); return; } catch (e) {} }
  drabNav((window.location.hash || '#drab-dashboard').replace(/^#/, ''));
}
function drabRenameDevelopment(devId, currentName) {
  var name = window.prompt('Rename this project:', currentName || '');
  if (name === null) return;
  name = name.trim();
  if (!name) { drabToast('Project name cannot be empty.', 'error'); return; }
  drabPost('save_development', { id: devId, name: name }).then(function (res) {
    if (res.json && res.json.ok) { drabToast('Project renamed.', 'success'); drabRerouteCurrent(); }
    else drabToast('Could not rename the project.', 'error');
  });
}
function drabDeleteDevelopment(devId, name, fromDevPage) {
  var label = name ? '“' + name + '”' : 'this project';
  if (!window.confirm('Delete ' + label + ' and all of its buildings and RABs? This cannot be undone.')) return;
  drabPost('delete_development', { id: devId }).then(function (res) {
    if (res.json && res.json.ok) {
      drabToast('Project deleted.', 'success');
      if (fromDevPage) drabNav('drab-dashboard');
      else drabRerouteCurrent();
    } else drabToast('Could not delete the project.', 'error');
  });
}

/* One development row with open + rename + delete (not wrapped in <a> so the
   action buttons are valid and don't trigger navigation). */
function drabDevCard(d) {
  var meta = (d.location_text ? drabEsc(d.location_text) + ' · ' : '')
    + (d.building_count || 0) + ' building' + ((d.building_count || 0) === 1 ? '' : 's');
  return ''
    + '<div class="rdtl-project-card drab-dev-card">'
    + '  <div class="rdtl-project-info drab-dev-open" role="link" tabindex="0"'
    + '       onclick="drabNav(\'drab-dev/' + d.id + '\')" onkeydown="if(event.key===\'Enter\'){drabNav(\'drab-dev/' + d.id + '\')}">'
    + '    <h3 class="rdtl-project-name">' + drabEsc(d.name) + '</h3>'
    + '    <p class="rdtl-project-meta">' + meta + '</p>'
    + '  </div>'
    + '  <div class="drab-dev-actions">'
    + '    <button class="rdtl-btn-icon" title="Rename project" data-drab-tip="Rename this project" onclick="drabRenameDevelopment(' + d.id + ',\'' + drabOnclickArg(d.name) + '\')">✎</button>'
    + '    <button class="rdtl-btn-icon rdtl-btn-icon--danger" title="Delete project" data-drab-tip="Delete this project and all its buildings" onclick="drabDeleteDevelopment(' + d.id + ',\'' + drabOnclickArg(d.name) + '\',false)">✕</button>'
    + '    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="color:var(--color-text-faint);flex-shrink:0"><path d="M9 18l6-6-6-6"/></svg>'
    + '  </div>'
    + '</div>';
}

/* =====================================================================
 * 5) THE EDITOR
 * ===================================================================== */
var _drabEditor = { rabId: 0, rab: null, lang: 'en', disc: 'SUMMARY' };

async function renderDrabEditor(el, rabId) {
  rabId = parseInt(rabId, 10);
  if (!drabLoggedIn()) { drabNav('drab'); return; }
  if (!rabId) { drabNav('drab-dashboard'); return; }
  _drabEditor.rabId = rabId;
  _drabEditor.disc = 'SUMMARY';

  el.innerHTML = drabHero(drabT('drab.editor_title', 'Detailed RAB'),
      drabT('drab.editor_desc', 'Refine every line, take off quantities, and export.'))
    + '<div class="section"><div class="container"><div id="drab-editor-mount">' + drabSpinner() + '</div></div></div>';

  // The router only appends this view to the document AFTER renderDrabEditor resolves.
  // The editor's load+render uses document.getElementById (mount, disc-area, markups),
  // which would see a detached tree if run synchronously here — so defer past the append.
  setTimeout(function () {
    drabLoadMeta().catch(function () {}).then(function () { drabEditorLoad(); });
  }, 0);
}

async function drabEditorLoad() {
  var mount = document.getElementById('drab-editor-mount');
  if (!mount) return;
  try {
    var res = await drabGet('rab', { id: _drabEditor.rabId });
    if (res.status === 403) { mount.innerHTML = drabErrorCard('You do not have access to this RAB.'); return; }
    if (!(res.json && res.json.ok)) throw new Error((res.json && res.json.error) || 'Failed to load.');
    _drabEditor.rab = res.json.rab;
    var devLang = _drabEditor.rab.development && _drabEditor.rab.development.lang;
    _drabEditor.lang = devLang || drabLang();
    drabEditorRender(mount);
  } catch (e) {
    mount.innerHTML = drabErrorCard(e.message);
  }
}

function drabEditorCombined() {
  var rab = _drabEditor.rab;
  var caps = (rab && rab.caps) || {};
  var dev = (rab && rab.development) || {};
  if (!caps.split) return true; // free users forced combined
  return parseInt(dev.display_combined, 10) === 1;
}

function drabEditorRender(mount) {
  var rab = _drabEditor.rab;
  var caps = rab.caps || {};
  var t = rab.totals || {};
  var dev = rab.development || {};
  var b = rab.building || {};
  var lang = _drabEditor.lang;
  var combined = drabEditorCombined();

  var indoor = (rab.area_schedule && rab.area_schedule.indoor) || 0;
  var costPerM2 = indoor > 0 ? (t.grand / indoor) : 0;

  // ----- Header -----
  var header = ''
    + '<a href="#drab-dev/' + (dev.id || '') + '" class="rab-back-link" onclick="drabNav(\'drab-dev/' + (dev.id || '') + '\');return false;">← ' + drabEsc(dev.name || 'Development') + '</a>'
    + '<div class="rdtl-editor-header">'
    + '  <div>'
    + '    <h2 class="rdtl-proj-title">v' + drabEsc(rab.version) + ' — ' + drabEsc(b.name || rab.name || 'Building') + '</h2>'
    + '    <p class="rdtl-proj-meta">' + drabEsc(dev.name || '') + (dev.location_text ? ' · ' + drabEsc(dev.location_text) : '')
    + ' · <span class="drab-badge drab-badge--indicative drab-badge--plain">' + drabEsc(rab.status) + '</span></p>'
    + '  </div>'
    + '  <div style="display:flex;align-items:center;gap:var(--space-2);flex-wrap:wrap">'
    + drabLangToggle(lang)
    + drabSplitToggle(caps, combined)
    + '    <button class="btn btn--outline btn--sm" data-drab-tip="Re-run the generator from this building\'s inputs as a new version (replaces the current lines)." onclick="drabRegenerate()">Regenerate</button>'
    + drabExportMenu(rab, caps)
    + '    <button class="btn btn--ghost btn--sm" data-drab-tip="Save this RAB as a reusable template (premium)." onclick="drabShowUpgrade(\'drab_templates\')">Save as template</button>'
    + '  </div>'
    + '</div>';

  // ----- Summary strip -----
  function cell(k, v, id, extraClass, tip) {
    return '<div class="drab-summary-cell' + (extraClass || '') + '"' + (tip ? ' data-drab-tip="' + drabEsc(tip) + '"' : '') + '>'
      + '<span class="drab-summary-k">' + drabEsc(k) + '</span>'
      + '<span class="drab-summary-v" id="' + id + '">' + v + '</span></div>';
  }
  var summary = '<div class="drab-summary-strip">'
    + cell('Material', drabIDR(t.material), 'drab-sum-material', '', 'Total material cost across all four disciplines.')
    + cell('Labour', drabIDR(t.labour), 'drab-sum-labour', '', 'Total labour cost across all four disciplines.')
    + cell('Cost / m² (indoor)', (costPerM2 ? drabIDR(costPerM2) : '—'), 'drab-sum-perm2', '', 'Grand total ÷ indoor floor area — a quick benchmark.')
    + cell('Grand total', drabIDR(t.grand), 'drab-sum-grand', ' drab-summary-cell--grand', 'Direct construction cost, plus any markups & tax you switch on below.')
    + '</div>'
    + drabAreaSchedule(rab.area_schedule || {});

  // ----- Markups -----
  var markups = drabMarkupsPanel(t);

  // ----- Discipline tabs (with per-discipline sums) -----
  var d = t.disciplines || {};
  function discSum(dc) { return ((d[dc] && d[dc].m) || 0) + ((d[dc] && d[dc].l) || 0); }
  var tabsDef = [['SUMMARY', 'Summary', t.grand], ['PREP', 'Preliminaries', discSum('PREP')], ['STR', 'Structure', discSum('STR')], ['ARCH', 'Architecture', discSum('ARCH')], ['MEP', 'MEP', discSum('MEP')]];
  var tabsHtml = '<div class="drab-disc-tabs">' + tabsDef.map(function (tt) {
    var active = _drabEditor.disc === tt[0];
    return '<button class="drab-disc-tab' + (active ? ' is-active' : '') + '" data-disc="' + tt[0] + '" onclick="drabSwitchDisc(\'' + tt[0] + '\')">'
      + '<span class="drab-disc-tab-name">' + drabEsc(tt[1]) + '</span>'
      + '<span class="drab-disc-tab-sum">' + drabIDR(tt[2]) + '</span></button>';
  }).join('') + '</div>';

  mount.innerHTML = header + summary + tabsHtml + '<div id="drab-disc-area"></div>' + markups;
  drabRenderDiscArea(combined);
  drabBindMarkups();
  drabTipInit();
}

function drabLangToggle(lang) {
  function opt(v, label) { return '<button class="' + (lang === v ? 'is-active' : '') + '" onclick="drabSetEditorLang(\'' + v + '\')">' + label + '</button>'; }
  return '<div class="drab-lang-toggle" role="group" aria-label="Document language">'
    + opt('en', 'EN') + opt('id', 'ID') + opt('both', 'Both') + '</div>';
}
function drabSplitToggle(caps, combined) {
  if (!caps.split) {
    return '<button class="drab-badge drab-badge--locked" style="cursor:pointer" onclick="drabShowUpgrade(\'drab_split_view\')">Material + Labour ✦ Upgrade</button>';
  }
  return '<div class="drab-lang-toggle" role="group" aria-label="Column display">'
    + '<button class="' + (combined ? 'is-active' : '') + '" onclick="drabSetDisplay(1)">Combined</button>'
    + '<button class="' + (!combined ? 'is-active' : '') + '" onclick="drabSetDisplay(0)">Split</button>'
    + '</div>';
}
function drabExportMenu(rab, caps) {
  var base = DRAB_API + '?action=export&rab_id=' + rab.id + '&lang=' + encodeURIComponent(_drabEditor.lang);
  var clean = !!caps.export;
  return '<div style="position:relative;display:inline-block">'
    + '  <button class="btn btn--outline btn--sm" onclick="drabToggleExport(this)">' + (clean ? 'Export' : 'Export (preview)') + ' ▾</button>'
    + '  <div class="drab-slot-menu" style="min-width:240px">'
    + '    <div class="drab-slot-menu-head">Download</div>'
    + '    <a class="drab-slot-opt" href="' + base + '&format=xlsx" target="_blank" rel="noopener"><span class="drab-slot-opt-name">Excel (.xlsx)</span></a>'
    + '    <a class="drab-slot-opt" href="' + base + '&format=pdf" target="_blank" rel="noopener"><span class="drab-slot-opt-name">PDF (print)</span></a>'
    + '    <a class="drab-slot-opt" href="' + base + '&format=csv" target="_blank" rel="noopener"><span class="drab-slot-opt-name">CSV</span></a>'
    + (!clean ? '    <a class="drab-slot-opt" href="#" onclick="drabShowUpgrade(\'drab_export_clean\');return false;"><span class="drab-slot-opt-name" style="color:var(--color-accent)">Upgrade for clean export</span></a>' : '')
    + '  </div>'
    + '</div>';
}
function drabToggleExport(btn) {
  var wrap = btn.parentNode;
  // reuse .drab-slot.is-open mechanics by toggling a class on the wrapper
  var menu = wrap.querySelector('.drab-slot-menu');
  if (!menu) return;
  var open = wrap.classList.toggle('drab-export-open');
  menu.style.opacity = open ? '1' : '';
  menu.style.visibility = open ? 'visible' : '';
  menu.style.transform = open ? 'translateY(0)' : '';
  if (!open) { menu.style.opacity = ''; menu.style.visibility = ''; menu.style.transform = ''; }
}

function drabAreaSchedule(sch) {
  function c(label, v) {
    return '<span class="drab-permeter"><span>' + drabEsc(label) + '</span> <strong>' + (drabNum(v) ? drabNum(v).toLocaleString('id-ID') + ' m²' : '—') + '</strong></span>';
  }
  return '<div style="display:flex;gap:var(--space-2);flex-wrap:wrap;margin:0 0 var(--space-5)">'
    + c('Indoor', sch.indoor) + c('Rooftop', sch.rooftop) + c('Outdoor', sch.outdoor) + c('Pool', sch.pool)
    + '</div>';
}

function drabMarkupsPanel(t) {
  var on = parseInt(t.markups_on, 10) === 1;
  function field(label, id, val, tip) {
    return '<div class="drab-markup-field"><label class="rab-label">' + drabEsc(label) + ' ' + drabInfoDot(tip) + '</label>'
      + '<div class="drab-markup-input-wrap"><input type="number" class="rab-input" id="' + id + '" min="0" max="100" step="0.5" value="' + drabEsc(val) + '"></div></div>';
  }
  return ''
    + '<div class="drab-markups">'
    + '  <div class="drab-markups-head">'
    + '    <h3 class="drab-markups-title">Markups &amp; tax</h3>'
    + '    <label class="drab-switch" data-drab-tip="Off = pure direct cost (like the Villa BoQs). On adds overhead, contingency and PPN. Changes save automatically.">'
    + '<input type="checkbox" id="drab-mk-on"' + (on ? ' checked' : '') + '><span class="drab-switch-slider"></span></label>'
    + '  </div>'
    + '  <div class="drab-markups-body' + (on ? '' : ' is-off') + '" id="drab-mk-body">'
    + field('Overhead &amp; profit', 'drab-mk-oh', t.overhead_pct, 'Builder overhead &amp; profit (BUK), applied to the direct cost.')
    + field('Contingency', 'drab-mk-co', t.contingency_pct, 'Allowance for unforeseen work, applied to the direct cost.')
    + field('PPN', 'drab-mk-pp', t.ppn_pct, 'Indonesian VAT, applied to direct + overhead + contingency.')
    + '  </div>'
    + '  <div class="drab-totals-ledger" id="drab-mk-ledger">' + drabLedger(t) + '</div>'
    + '  <div class="drab-mk-foot"><span class="drab-mk-status" id="drab-mk-status" aria-live="polite"></span></div>'
    + '</div>';
}
function drabLedger(t) {
  var rows = [['Direct construction cost', t.direct]];
  if (parseInt(t.markups_on, 10) === 1) {
    rows.push(['Overhead & profit (' + (t.overhead_pct || 0) + '%)', t.overhead]);
    rows.push(['Contingency (' + (t.contingency_pct || 0) + '%)', t.contingency]);
    rows.push(['PPN (' + (t.ppn_pct || 0) + '%)', t.ppn]);
  }
  var out = rows.map(function (r) { return '<div class="drab-ledger-row"><span>' + drabEsc(r[0]) + '</span><span>' + drabIDR(r[1]) + '</span></div>'; }).join('');
  out += '<div class="drab-ledger-row drab-ledger-row--grand"><span>Grand total</span><span>' + drabIDR(t.grand) + '</span></div>';
  return out;
}
/* Markups are LIVE + auto-saved: toggling or editing a percentage updates the
   ledger and grand total instantly (client-side preview off the known direct
   cost), then persists via set_markups. The server stays the source of truth —
   its returned totals reconcile the preview. No separate "Apply" step (the old
   one was the source of the "applied but nothing happened / can't unapply"
   confusion). */
var _drabMkTimer = null, _drabMkSeq = 0;
function drabMarkupCollect() {
  var onCb = document.getElementById('drab-mk-on');
  // Clamp 0–100 here so the live preview matches what the server stores (the
  // server clamps too — the HTML max attribute alone is not authoritative).
  function v(id) { var e = document.getElementById(id); return Math.max(0, Math.min(100, e ? drabNum(e.value) : 0)); }
  return { on: onCb ? onCb.checked : false, oh: v('drab-mk-oh'), co: v('drab-mk-co'), pp: v('drab-mk-pp') };
}
function drabMarkupSetStatus(text, cls) {
  var el = document.getElementById('drab-mk-status');
  if (el) { el.textContent = text; el.className = 'drab-mk-status' + (cls ? ' ' + cls : ''); }
}
/* Recompute the ledger + grand from the current controls without a round-trip.
   Mirrors drab_compute_totals() exactly; markups never change material/labour. */
function drabMarkupPreview() {
  var st = drabMarkupCollect();
  var base = _drabEditor.rab.totals || {};
  var direct = drabNum(base.direct);
  var overhead = 0, contingency = 0, ppn = 0, grand = direct;
  if (st.on) {
    overhead = direct * (st.oh / 100);
    contingency = direct * (st.co / 100);
    var taxable = direct + overhead + contingency;
    ppn = taxable * (st.pp / 100);
    grand = taxable + ppn;
  }
  var totals = {};
  Object.keys(base).forEach(function (k) { totals[k] = base[k]; });
  totals.markups_on = st.on ? 1 : 0;
  totals.overhead_pct = st.oh; totals.contingency_pct = st.co; totals.ppn_pct = st.pp;
  totals.overhead = overhead; totals.contingency = contingency; totals.ppn = ppn; totals.grand = grand;
  drabUpdateSummary(totals);
}
function drabMarkupPersist() {
  var st = drabMarkupCollect();
  var seq = ++_drabMkSeq; // ignore responses superseded by a newer save (out-of-order guard)
  drabMarkupSetStatus('Saving…', 'is-saving');
  drabPost('set_markups', {
    rab_id: _drabEditor.rabId,
    markups_on: st.on ? 1 : 0, overhead_pct: st.oh, contingency_pct: st.co, ppn_pct: st.pp
  }).then(function (res) {
    if (seq !== _drabMkSeq) return; // a later save is in flight / has landed — drop this stale result
    if (res.json && res.json.ok) {
      drabUpdateSummary(res.json.totals); // authoritative server totals
      drabMarkupSetStatus('Saved', 'is-saved');
    } else { drabMarkupSetStatus('Not saved — please retry', 'is-error'); drabToast('Could not save markups.', 'error'); }
  }).catch(function () {
    if (seq !== _drabMkSeq) return;
    drabMarkupSetStatus('Not saved — please retry', 'is-error'); drabToast('Could not save markups.', 'error');
  });
}
function drabMarkupQueuePersist(delay) {
  if (_drabMkTimer) clearTimeout(_drabMkTimer);
  _drabMkTimer = setTimeout(function () { _drabMkTimer = null; drabMarkupPersist(); }, delay || 600);
}
function drabBindMarkups() {
  var onCb = document.getElementById('drab-mk-on');
  if (onCb) onCb.addEventListener('change', function () {
    var body = document.getElementById('drab-mk-body');
    if (body) body.classList.toggle('is-off', !onCb.checked);
    if (_drabMkTimer) { clearTimeout(_drabMkTimer); _drabMkTimer = null; } // supersede any queued save
    drabMarkupPreview();
    drabMarkupPersist(); // a toggle is a deliberate action — persist at once
  });
  ['drab-mk-oh', 'drab-mk-co', 'drab-mk-pp'].forEach(function (id) {
    var inp = document.getElementById(id);
    if (!inp) return;
    inp.addEventListener('input', function () { drabMarkupPreview(); drabMarkupQueuePersist(600); });
    inp.addEventListener('blur', function () {
      if (_drabMkTimer) { clearTimeout(_drabMkTimer); _drabMkTimer = null; }
      drabMarkupPersist();
    });
  });
}

function drabSwitchDisc(disc) {
  _drabEditor.disc = disc;
  document.querySelectorAll('.drab-disc-tabs .drab-disc-tab').forEach(function (b) {
    b.classList.toggle('is-active', b.getAttribute('data-disc') === disc);
  });
  drabRenderDiscArea(drabEditorCombined());
}

function drabRenderDiscArea(combined) {
  var area = document.getElementById('drab-disc-area');
  if (!area) return;
  var rab = _drabEditor.rab;
  var disc = _drabEditor.disc;

  if (disc === 'SUMMARY') { area.innerHTML = drabSummaryTab(rab); return; }

  var caps = rab.caps || {};
  var sections = (rab.sections || []).filter(function (s) { return s.discipline === disc; });
  if (sections.length === 0) {
    area.innerHTML = '<div class="rab-card" style="text-align:center;color:var(--color-text-faint);padding:var(--space-6)">No items in this discipline yet.</div>'
      + drabAddSectionBar(disc);
    return;
  }
  area.innerHTML = drabConfidenceLegend() + sections.map(function (sec) { return drabSectionCard(sec, combined, caps); }).join('') + drabAddSectionBar(disc);
}

function drabAddSectionBar(disc) {
  return '<div style="margin-top:var(--space-4)"><button class="btn btn--outline btn--sm" onclick="drabAddSection(\'' + disc + '\')">+ Add section</button></div>';
}

function drabSummaryTab(rab) {
  var t = rab.totals || {};
  var d = t.disciplines || {};
  var names = { PREP: 'Preliminaries', STR: 'Structure', ARCH: 'Architecture', MEP: 'MEP' };
  var rows = ['PREP', 'STR', 'ARCH', 'MEP'].map(function (dc, i) {
    var m = (d[dc] && d[dc].m) || 0, l = (d[dc] && d[dc].l) || 0;
    return '<tr>'
      + '<td>' + (i + 1) + '</td>'
      + '<td>' + drabEsc(names[dc]) + '</td>'
      + '<td class="drab-cat-mat">' + drabIDR(m) + '</td>'
      + '<td class="drab-cat-lab">' + drabIDR(l) + '</td>'
      + '<td class="drab-cat-rate">' + drabIDR(m + l) + '</td>'
      + '<td class="drab-cat-add"><button class="btn btn--ghost btn--sm" onclick="drabSwitchDisc(\'' + dc + '\')">Open</button></td>'
      + '</tr>';
  }).join('');
  var ledger = drabLedger(t);
  return ''
    + '<div class="drab-catalog">'
    + '  <div class="drab-catalog-table-wrap"><table class="drab-catalog-table">'
    + '    <thead><tr><th>No.</th><th>Discipline</th><th class="drab-cat-mat">Material</th><th class="drab-cat-lab">Labour</th><th class="drab-cat-rate">Total</th><th></th></tr></thead>'
    + '    <tbody>' + rows + '</tbody>'
    + '  </table></div>'
    + '  <div style="padding:var(--space-4) var(--space-5)"><div class="drab-totals-ledger" style="margin-top:0;border-top:none;padding-top:0">' + ledger + '</div></div>'
    + '</div>';
}

function drabTh(label, cls, tip) {
  return '<th' + (cls ? ' class="' + cls + '"' : '') + (tip ? ' data-drab-tip="' + drabEsc(tip) + '"' : '') + '>' + label + '</th>';
}
function drabSectionCard(sec, combined, caps) {
  var lang = _drabEditor.lang;
  var headCols = combined
    ? drabTh('Ref', '', 'Reference code (section.line).') + drabTh('Description') + drabTh('Qty', 'drab-cat-rate', 'Quantity — a single number, or the sum of its take-off rows.') + drabTh('Unit', '', 'Unit of measure.') + drabTh('Unit price', 'drab-cat-rate', 'Supply &amp; install rate per unit (material + labour combined).') + drabTh('Amount', 'drab-cat-rate', 'Quantity × unit price.') + drabTh('Pricing', 'drab-cat-conf', 'How reliable this rate is — Confirmed (real BoQ/contract) or Indicative (regional ball-park).') + '<th></th>'
    : drabTh('Ref', '', 'Reference code (section.line).') + drabTh('Description') + drabTh('Qty', 'drab-cat-rate', 'Quantity — a single number, or the sum of its take-off rows.') + drabTh('Unit', '', 'Unit of measure.') + drabTh('Material', 'drab-cat-mat', 'Material component of the unit rate.') + drabTh('Labour', 'drab-cat-lab', 'Labour component of the unit rate.') + drabTh('Amount', 'drab-cat-rate', 'Quantity × (material + labour).') + drabTh('Pricing', 'drab-cat-conf', 'How reliable this rate is — Confirmed (real BoQ/contract) or Indicative (regional ball-park).') + '<th></th>';
  var colspan = combined ? 8 : 9;
  var amountIdx = combined ? 6 : 7; // 1-based column of "Amount"

  var itemsHtml = (sec.items || []).map(function (it) { return drabItemRow(it, sec.id, combined, caps); }).join('');

  return ''
    + '<div class="drab-section" data-section-id="' + sec.id + '" style="margin-bottom:var(--space-5)">'
    + '  <div class="rdtl-section-header">'
    + '    <h4 class="rdtl-section-name"><span style="color:var(--color-text-faint);font-variant-numeric:tabular-nums;margin-right:var(--space-2)">' + drabEsc(sec.code) + '</span>'
    + '      <span id="drab-secname-' + sec.id + '">' + drabEsc(drabName(sec, lang)) + '</span>'
    + '      <button class="rdtl-btn-icon" title="Rename section" data-drab-tip="Rename this section." onclick="drabRenameSection(' + sec.id + ')">✎</button></h4>'
    + '    <div class="rdtl-section-actions">'
    + '      ' + drabSectionConfidenceChip(sec.items)
    + '      <span class="rdtl-section-total" data-drab-tip="Section sub-total (material + labour).">' + drabIDR(sec.total) + '</span>'
    + '      <button class="rdtl-btn-icon rdtl-btn-icon--danger" title="Delete section" data-drab-tip="Delete this section and all its items." onclick="drabDeleteSection(' + sec.id + ')">✕</button>'
    + '    </div>'
    + '  </div>'
    + '  <div class="rdtl-items-table-wrap"><table class="rdtl-items-table drab-catalog-table">'
    + '    <thead><tr>' + headCols + '</tr></thead>'
    + '    <tbody>' + itemsHtml + '</tbody>'
    + '    <tfoot><tr><td colspan="' + (amountIdx - 1) + '" style="text-align:right;font-weight:600">Sub-total</td>'
    + '      <td class="drab-cat-rate" style="font-weight:700">' + drabIDR(sec.total) + '</td>'
    + '      <td colspan="' + (colspan - amountIdx) + '"></td></tr></tfoot>'
    + '  </table></div>'
    + '  <div class="drab-add-item" style="margin-top:var(--space-2)"><button class="btn btn--ghost btn--sm" onclick="drabAddItem(' + sec.id + ')">+ Add item</button></div>'
    + '</div>';
}

function drabItemRow(it, sectionId, combined, caps) {
  var lang = _drabEditor.lang;
  var name = drabName(it, lang);
  var locked = parseInt(it.confirmed_locked, 10) === 1;

  var badges = '';
  if (parseInt(it.is_pc_sum, 10) === 1) badges += '<span class="drab-badge drab-badge--pc" data-drab-tip="Prime-Cost Sum — a provisional allowance to be confirmed later.">PC Sum</span>';

  var priceCells;
  if (locked) {
    var mask = '<td class="drab-cat-rate"><span class="drab-locked-veil" data-drab-tip="Confirmed contract-grade rate — upgrade to reveal." onclick="drabShowUpgrade(\'drab_confirmed_pricing\')"><span class="drab-locked-amount">Rp •••</span> ✦</span></td>';
    priceCells = combined ? mask : (mask + mask);
  } else if (combined) {
    priceCells = '<td class="drab-cat-rate">' + drabIDR(it.rate) + '</td>';
  } else {
    priceCells = '<td class="drab-cat-mat">' + drabIDR(it.material_rate) + '</td><td class="drab-cat-lab">' + drabIDR(it.labour_rate) + '</td>';
  }
  var amountCell = locked
    ? '<td class="drab-cat-rate"><span class="drab-locked-veil" data-drab-tip="Upgrade to reveal confirmed pricing." onclick="drabShowUpgrade(\'drab_confirmed_pricing\')"><span class="drab-locked-amount">Rp •••</span> ✦</span></td>'
    : '<td class="drab-cat-rate" style="font-weight:600">' + drabIDR(it.line_total) + '</td>';

  // The "how?" build-up link only appears where there is a real AHSP breakdown.
  var ahspLink = (it.work_item_id && parseInt(it.has_buildup, 10) === 1)
    ? ' <button class="drab-howbtn" data-drab-tip="See how this unit rate is built up from material, labour &amp; equipment coefficients." onclick="drabShowAhsp(' + it.work_item_id + ')">how?</button>'
    : '';
  var swapBtn = it.slot_code
    ? '<button class="rdtl-btn-icon" title="Swap specification" data-drab-tip="Swap this line to an alternative spec for the same slot (e.g. a different floor or wall finish)." onclick="drabShowSwap(' + it.id + ',\'' + drabAttr(it.slot_code) + '\')">⇄</button>'
    : '';
  var takeoffHint = parseInt(it.has_takeoff, 10) === 1
    ? '<span class="drab-takeoff-flag" data-drab-tip="Quantity is the sum of measured take-off rows. Click to view or edit." onclick="drabShowTakeoff(' + it.id + ')">≡ take-off</span>'
    : '';
  var subline = (badges || takeoffHint) ? '<div style="margin-top:2px">' + badges + (badges && takeoffHint ? ' ' : '') + takeoffHint + '</div>' : '';

  return ''
    + '<tr class="drab-item-row" data-item-id="' + it.id + '">'
    + '  <td class="drab-cat-code">' + drabEsc(it.ref_code) + '</td>'
    + '  <td><div>' + drabEsc(name) + ahspLink + '</div>'
    + subline
    + (it.remark ? '<div style="font-size:var(--text-xs);color:var(--color-text-faint)">' + drabEsc(it.remark) + '</div>' : '')
    + '  </td>'
    + '  <td class="drab-cat-rate">' + drabQtyFmt(it.quantity) + '</td>'
    + '  <td>' + drabEsc(it.unit_code || '') + '</td>'
    + priceCells
    + amountCell
    + '  <td class="drab-cat-conf">' + drabConfidenceDot(it.confidence, it.confirmed_locked) + '</td>'
    + '  <td class="drab-cat-add">'
    + swapBtn
    + '    <button class="rdtl-btn-icon" title="Break into take-off" data-drab-tip="Split the quantity into measured take-off rows." onclick="drabShowTakeoff(' + it.id + ')">≡</button>'
    + '    <button class="rdtl-btn-icon" title="Edit" data-drab-tip="Edit description, unit, quantity and rates." onclick="drabEditItem(' + it.id + ',' + sectionId + ')">✎</button>'
    + '    <button class="rdtl-btn-icon rdtl-btn-icon--danger" title="Delete" data-drab-tip="Delete this line." onclick="drabDeleteItem(' + it.id + ')">✕</button>'
    + '  </td>'
    + '</tr>';
}

/* Pricing confidence shown as a compact colour dot (instead of a word on every
   line). Hover/tap reveals the meaning; a legend + section roll-up carry the
   labels so the table stays scannable. Locked = a Confirmed rate a free user
   may not see — stays masked, opens the upgrade prompt. */
var DRAB_CONF_META = {
  confirmed:  { label: 'Confirmed',  tip: 'Confirmed — from a real BoQ, paid invoice or contractor agreement.' },
  derived:    { label: 'Derived',    tip: 'Derived — calculated from a confirmed base price (e.g. retail + freight).' },
  indicative: { label: 'Indicative', tip: 'Indicative — a regional ball-park, not yet confirmed by a real quote.' }
};
function drabConfClass(conf) { return DRAB_CONF_META[conf] ? conf : 'indicative'; }
function drabConfidenceDot(conf, locked) {
  if (parseInt(locked, 10) === 1) {
    return '<span class="drab-conf-dot drab-conf-dot--locked" role="button" tabindex="0" aria-label="Confirmed rate — upgrade to reveal"'
      + ' data-drab-tip="Confirmed contract-grade rate — upgrade to reveal the number."'
      + ' onclick="drabShowUpgrade(\'drab_confirmed_pricing\')"'
      + ' onkeydown="if(event.key===\'Enter\'||event.key===\' \'){event.preventDefault();drabShowUpgrade(\'drab_confirmed_pricing\')}">✦</span>';
  }
  var c = drabConfClass(conf), m = DRAB_CONF_META[c];
  return '<span class="drab-conf-dot drab-conf-dot--' + c + '" role="img" tabindex="0" aria-label="' + drabEsc(m.label) + '" data-drab-tip="' + drabEsc(m.tip) + '"></span>';
}
function drabConfidenceLegend() {
  function item(c) { return '<span class="drab-conf-legend-item"><span class="drab-conf-dot drab-conf-dot--' + c + '"></span>' + drabEsc(DRAB_CONF_META[c].label) + '</span>'; }
  return '<div class="drab-conf-legend" data-drab-tip="Every rate is tagged by how reliable it is. Confirmed comes from real Lombok build data; Indicative is a regional ball-park.">'
    + '<span class="drab-conf-legend-label">Pricing basis:</span>'
    + item('confirmed') + item('derived') + item('indicative') + '</div>';
}
/* Roll-up chip for a section header: one word for the whole block instead of a
   badge on every line. */
function drabSectionConfidenceChip(items) {
  var set = {};
  (items || []).forEach(function (it) {
    var c = parseInt(it.confirmed_locked, 10) === 1 ? 'confirmed' : drabConfClass(it.confidence);
    set[c] = true;
  });
  var keys = Object.keys(set);
  if (keys.length === 0) return '';
  if (keys.length === 1) {
    var c = keys[0], m = DRAB_CONF_META[c];
    return '<span class="drab-conf-chip drab-conf-chip--' + c + '" data-drab-tip="Every line in this section is ' + drabEsc(m.label.toLowerCase()) + '.">'
      + '<span class="drab-conf-dot drab-conf-dot--' + c + '"></span>' + drabEsc(m.label) + '</span>';
  }
  return '<span class="drab-conf-chip drab-conf-chip--mixed" data-drab-tip="This section mixes confirmed and indicative rates — see the dot on each line.">Mixed pricing</span>';
}

/* ----- Editor header actions ----- */
function drabSetEditorLang(lang) {
  if (_drabEditor.lang === lang) return;
  _drabEditor.lang = lang;
  var devId = _drabEditor.rab.development && _drabEditor.rab.development.id;
  if (devId) drabPost('set_display', { development_id: devId, lang: lang });
  drabEditorRender(document.getElementById('drab-editor-mount'));
}
function drabSetDisplay(combinedFlag) {
  var dev = _drabEditor.rab.development || {};
  if (!dev.id) return;
  dev.display_combined = combinedFlag ? 1 : 0;
  drabPost('set_display', { development_id: dev.id, display_combined: combinedFlag ? 1 : 0 }).then(function () {
    drabEditorRender(document.getElementById('drab-editor-mount'));
  });
}
function drabRegenerate() {
  if (!window.confirm('Regenerate this RAB from the current building inputs? This creates a new version and replaces the current lines.')) return;
  var building = _drabEditor.rab.building || {};
  drabPost('regenerate', { building_id: building.id }).then(function (res) {
    if (drabHandledUpgrade(res)) return;
    if (res.json && res.json.ok && res.json.rab_id) {
      drabToast('New version generated.', 'success');
      _drabEditor.rabId = res.json.rab_id;
      _drabEditor.disc = 'SUMMARY';
      drabNav('drab-editor/' + res.json.rab_id);
      drabEditorLoad();
    } else { drabToast('Could not regenerate.', 'error'); }
  });
}

/* ----- Live summary update after writes returning totals ----- */
function drabUpdateSummary(totals) {
  if (!totals) return;
  _drabEditor.rab.totals = totals;
  var setTxt = function (id, val) { var e = document.getElementById(id); if (e) e.textContent = drabIDR(val); };
  setTxt('drab-sum-material', totals.material);
  setTxt('drab-sum-labour', totals.labour);
  setTxt('drab-sum-grand', totals.grand);
  var indoor = (_drabEditor.rab.area_schedule && _drabEditor.rab.area_schedule.indoor) || 0;
  var cpm = indoor > 0 ? totals.grand / indoor : 0;
  var pe = document.getElementById('drab-sum-perm2');
  if (pe) pe.textContent = cpm ? drabIDR(cpm) : '—';
  var ledger = document.getElementById('drab-mk-ledger');
  if (ledger) ledger.innerHTML = drabLedger(totals);
  // refresh discipline tab sums
  var d = totals.disciplines || {};
  document.querySelectorAll('.drab-disc-tabs .drab-disc-tab').forEach(function (b) {
    var dc = b.getAttribute('data-disc');
    var sumEl = b.querySelector('.drab-disc-tab-sum');
    if (!sumEl) return;
    if (dc === 'SUMMARY') sumEl.textContent = drabIDR(totals.grand);
    else sumEl.textContent = drabIDR(((d[dc] && d[dc].m) || 0) + ((d[dc] && d[dc].l) || 0));
  });
}

/* Re-fetch + re-render after structural changes. */
function drabReloadEditor() { drabEditorLoad(); }

/* ----- Section CRUD ----- */
function drabAddSection(disc) {
  var name = window.prompt('New section name:', 'New section');
  if (name === null) return;
  drabPost('add_section', { rab_id: _drabEditor.rabId, discipline: disc, name_en: name, name_id: name }).then(function (res) {
    if (drabHandledUpgrade(res)) return;
    if (res.json && res.json.ok) drabReloadEditor();
    else drabToast('Could not add section.', 'error');
  });
}
function drabRenameSection(sectionId) {
  var span = document.getElementById('drab-secname-' + sectionId);
  var current = span ? span.textContent.trim() : '';
  var name = window.prompt('Rename section:', current);
  if (name === null) return;
  drabPost('save_section', { section_id: sectionId, name_en: name, name_id: name }).then(function (res) {
    if (res.json && res.json.ok) drabReloadEditor();
    else drabToast('Could not rename section.', 'error');
  });
}
function drabDeleteSection(sectionId) {
  if (!window.confirm('Delete this section and all its items?')) return;
  drabPost('delete_section', { section_id: sectionId }).then(function (res) {
    if (res.json && res.json.ok) { drabToast('Section deleted.', 'success'); if (res.json.totals) _drabEditor.rab.totals = res.json.totals; drabReloadEditor(); }
    else drabToast('Could not delete section.', 'error');
  });
}

/* ----- Item CRUD ----- */
function drabFindItem(itemId) {
  var found = null;
  (_drabEditor.rab.sections || []).some(function (s) {
    return (s.items || []).some(function (it) { if (it.id === itemId) { found = it; return true; } return false; });
  });
  return found;
}
function drabUnitOptions(selectedId) {
  if (!_drabMeta || !_drabMeta.units) return '<option value="' + (selectedId || '') + '">unit</option>';
  return _drabMeta.units.map(function (u) {
    return '<option value="' + u.id + '"' + (parseInt(selectedId, 10) === parseInt(u.id, 10) ? ' selected' : '') + '>' + drabEsc(u.code) + '</option>';
  }).join('');
}
function drabAddItem(sectionId) {
  drabLoadMeta().then(function () {
    var row = document.querySelector('.drab-section[data-section-id="' + sectionId + '"] .drab-add-item');
    if (!row) return;
    if (row.querySelector('.drab-item-form')) { row.innerHTML = '<button class="btn btn--ghost btn--sm" onclick="drabAddItem(' + sectionId + ')">+ Add item</button>'; return; }
    row.innerHTML = ''
      + '<div class="drab-item-form" style="background:var(--color-surface-offset);padding:var(--space-3);border-radius:var(--radius-md)">'
      + '  <div class="rab-fields-grid">'
      + '    <input type="text" class="rab-input" id="drab-new-name-' + sectionId + '" placeholder="Item description">'
      + '    <select class="rab-select" id="drab-new-unit-' + sectionId + '">' + drabUnitOptions(null) + '</select>'
      + '    <input type="number" class="rab-input" id="drab-new-qty-' + sectionId + '" placeholder="Qty" min="0" step="0.01">'
      + '    <input type="number" class="rab-input" id="drab-new-mat-' + sectionId + '" placeholder="Material rate" min="0" step="1">'
      + '    <input type="number" class="rab-input" id="drab-new-lab-' + sectionId + '" placeholder="Labour rate" min="0" step="1">'
      + '  </div>'
      + '  <div style="display:flex;gap:var(--space-2);margin-top:var(--space-2)">'
      + '    <button class="btn btn--primary btn--sm" onclick="drabSaveNewItem(' + sectionId + ')">Save</button>'
      + '    <button class="btn btn--ghost btn--sm" onclick="drabAddItem(' + sectionId + ')">Cancel</button>'
      + '  </div>'
      + '</div>';
    var ni = document.getElementById('drab-new-name-' + sectionId);
    if (ni) ni.focus();
  });
}
function drabSaveNewItem(sectionId) {
  var name = (document.getElementById('drab-new-name-' + sectionId).value || '').trim();
  var unit = document.getElementById('drab-new-unit-' + sectionId).value;
  var qty = drabNum(document.getElementById('drab-new-qty-' + sectionId).value);
  var mat = drabNum(document.getElementById('drab-new-mat-' + sectionId).value);
  var lab = drabNum(document.getElementById('drab-new-lab-' + sectionId).value);
  if (!name) { drabToast('Item description is required.', 'error'); return; }
  drabPost('add_item', { section_id: sectionId, name_en: name, name_id: name, unit_id: parseInt(unit, 10) || 1, quantity: qty, material_rate: mat, labour_rate: lab }).then(function (res) {
    if (drabHandledUpgrade(res)) return;
    if (res.json && res.json.ok) { if (res.json.totals) _drabEditor.rab.totals = res.json.totals; drabReloadEditor(); }
    else drabToast('Could not add item.', 'error');
  });
}
function drabEditItem(itemId, sectionId) {
  var it = drabFindItem(itemId);
  if (!it) return;
  drabLoadMeta().then(function () {
    var tr = document.querySelector('.drab-item-row[data-item-id="' + itemId + '"]');
    if (!tr) return;
    if (tr.nextSibling && tr.nextSibling.classList && tr.nextSibling.classList.contains('drab-item-edit-row')) return;
    var colspan = tr.children.length;
    var locked = parseInt(it.confirmed_locked, 10) === 1;
    var formRow = document.createElement('tr');
    formRow.className = 'drab-item-edit-row';
    formRow.innerHTML = '<td colspan="' + colspan + '" style="background:var(--color-surface-offset)">'
      + '<div class="drab-item-form" style="padding:var(--space-3)">'
      + '  <div class="rab-fields-grid">'
      + '    <input type="text" class="rab-input" id="drab-edit-name-' + itemId + '" value="' + drabEsc(_drabEditor.lang === 'id' ? it.name_id : it.name_en) + '" placeholder="Description">'
      + '    <select class="rab-select" id="drab-edit-unit-' + itemId + '">' + drabUnitOptions(it.unit_id) + '</select>'
      + '    <input type="number" class="rab-input" id="drab-edit-qty-' + itemId + '" value="' + (it.quantity == null ? '' : it.quantity) + '" placeholder="Qty" min="0" step="0.01">'
      + (locked
          ? '    <input type="text" class="rab-input" disabled value="Confirmed — upgrade to edit rate">'
          : '    <input type="number" class="rab-input" id="drab-edit-mat-' + itemId + '" value="' + it.material_rate + '" placeholder="Material rate" min="0" step="1">'
            + '    <input type="number" class="rab-input" id="drab-edit-lab-' + itemId + '" value="' + it.labour_rate + '" placeholder="Labour rate" min="0" step="1">')
      + '  </div>'
      + '  <div style="display:flex;align-items:center;gap:var(--space-3);margin-top:var(--space-2)">'
      + '    <label class="rab-check-label"><input type="checkbox" id="drab-edit-pc-' + itemId + '"' + (parseInt(it.is_pc_sum, 10) === 1 ? ' checked' : '') + '><span>PC Sum</span></label>'
      + '    <button class="btn btn--primary btn--sm" onclick="drabSaveEditItem(' + itemId + ')">Save</button>'
      + '    <button class="btn btn--ghost btn--sm" onclick="drabReloadEditor()">Cancel</button>'
      + '  </div>'
      + '</div></td>';
    tr.parentNode.insertBefore(formRow, tr.nextSibling);
    tr.style.display = 'none';
  });
}
function drabSaveEditItem(itemId) {
  var it = drabFindItem(itemId);
  var locked = it && parseInt(it.confirmed_locked, 10) === 1;
  var name = (document.getElementById('drab-edit-name-' + itemId).value || '').trim();
  var body = {
    item_id: itemId, name_en: name, name_id: name,
    unit_id: parseInt(document.getElementById('drab-edit-unit-' + itemId).value, 10) || 1,
    quantity: document.getElementById('drab-edit-qty-' + itemId).value,
    is_pc_sum: document.getElementById('drab-edit-pc-' + itemId).checked ? 1 : 0
  };
  if (!locked) {
    body.material_rate = drabNum(document.getElementById('drab-edit-mat-' + itemId).value);
    body.labour_rate = drabNum(document.getElementById('drab-edit-lab-' + itemId).value);
  }
  drabPost('save_item', body).then(function (res) {
    if (res.json && res.json.ok) { if (res.json.totals) _drabEditor.rab.totals = res.json.totals; drabReloadEditor(); }
    else drabToast('Could not save the item.', 'error');
  });
}
function drabDeleteItem(itemId) {
  if (!window.confirm('Delete this item?')) return;
  drabPost('delete_item', { item_id: itemId }).then(function (res) {
    if (res.json && res.json.ok) { if (res.json.totals) _drabEditor.rab.totals = res.json.totals; drabReloadEditor(); }
    else drabToast('Could not delete the item.', 'error');
  });
}

/* ----- Take-off editor (modal) ----- */
function drabShowTakeoff(itemId) {
  var it = drabFindItem(itemId);
  if (!it) return;
  var rows = (it.takeoffs && it.takeoffs.length) ? it.takeoffs.slice() : [{ label: '', quantity: '' }];
  var name = drabName(it, _drabEditor.lang);
  var body = ''
    + '<div class="drab-takeoff">'
    + '  <div class="drab-takeoff-title"><span>Break into take-off</span><span>' + drabEsc(it.unit_code || '') + '</span></div>'
    + '  <p style="font-size:var(--text-xs);color:var(--color-text-muted);margin-bottom:var(--space-2)">' + drabEsc(name) + ' — list measured quantities; they sum to the line quantity. Use the label as the measurement note (e.g. "Lounge 6.0 × 4.5").</p>'
    + '  <div class="drab-takeoff-rows" id="drab-takeoff-rows">' + rows.map(function (r) { return drabTakeoffRow(r); }).join('') + '</div>'
    + '  <button class="drab-takeoff-add" onclick="drabAddTakeoffRow()">+ Add row</button>'
    + '  <div class="drab-takeoff-foot">'
    + '    <span class="drab-takeoff-sum">Total: <span id="drab-takeoff-total">0</span> ' + drabEsc(it.unit_code || '') + '</span>'
    + '    <button class="btn btn--primary btn--sm" onclick="drabSaveTakeoff(' + itemId + ')">Save take-off</button>'
    + '  </div>'
    + '</div>';
  drabShowModal(body, { wide: true });
  drabRecalcTakeoff();
  var area = document.getElementById('drab-takeoff-rows');
  if (area) area.addEventListener('input', drabRecalcTakeoff);
}
function drabTakeoffRow(r) {
  return '<div class="drab-takeoff-row">'
    + '<input type="text" class="rab-input drab-tk-label" placeholder="Measurement note" value="' + drabEsc(r.label || '') + '">'
    + '<input type="number" class="rab-input rab-input--qty drab-tk-qty" placeholder="Qty" min="0" step="0.01" value="' + (r.quantity === '' || r.quantity == null ? '' : r.quantity) + '">'
    + '<button class="drab-takeoff-del" title="Remove" onclick="this.parentNode.remove();drabRecalcTakeoff()">✕</button>'
    + '</div>';
}
function drabAddTakeoffRow() {
  var area = document.getElementById('drab-takeoff-rows');
  if (!area) return;
  var div = document.createElement('div');
  div.innerHTML = drabTakeoffRow({ label: '', quantity: '' });
  area.appendChild(div.firstChild);
}
function drabRecalcTakeoff() {
  var sum = 0;
  document.querySelectorAll('#drab-takeoff-rows .drab-tk-qty').forEach(function (i) { sum += drabNum(i.value); });
  var el = document.getElementById('drab-takeoff-total');
  if (el) el.textContent = sum.toLocaleString('id-ID', { maximumFractionDigits: 2 });
}
function drabSaveTakeoff(itemId) {
  var rows = [];
  document.querySelectorAll('#drab-takeoff-rows .drab-takeoff-row').forEach(function (r) {
    var label = (r.querySelector('.drab-tk-label').value || '').trim();
    var qty = drabNum(r.querySelector('.drab-tk-qty').value);
    if (label || qty) rows.push({ label: label, quantity: qty });
  });
  drabPost('save_takeoff', { item_id: itemId, rows: rows }).then(function (res) {
    if (res.json && res.json.ok) {
      drabCloseModal();
      if (res.json.totals) _drabEditor.rab.totals = res.json.totals;
      drabToast('Take-off saved. Quantity = ' + Number(res.json.quantity).toLocaleString('id-ID', { maximumFractionDigits: 2 }), 'success');
      drabReloadEditor();
    } else drabToast('Could not save the take-off.', 'error');
  });
}

/* ----- Slot swap (modal) ----- */
function drabShowSwap(itemId, slot) {
  drabShowModal('<h3 class="drab-markups-title" style="margin-bottom:var(--space-3)">Swap specification</h3><div class="drab-slot-menu-head">Alternatives for this slot</div><div id="drab-swap-body">' + drabSpinner() + '</div>');
  drabGet('slot_alternatives', { slot: slot }).then(function (res) {
    var body = document.getElementById('drab-swap-body');
    if (!body) return;
    if (!(res.json && res.json.ok)) { body.innerHTML = drabErrorCard('Could not load alternatives.'); return; }
    var opts = res.json.options || [];
    if (opts.length === 0) { body.innerHTML = '<p style="color:var(--color-text-muted);font-size:var(--text-sm)">No alternative specifications for this slot.</p>'; return; }
    var lang = _drabEditor.lang;
    var current = drabFindItem(itemId);
    var currentWi = current ? current.work_item_id : null;
    body.innerHTML = opts.map(function (o) {
      var isCur = currentWi && parseInt(o.work_item_id, 10) === parseInt(currentWi, 10);
      return '<button class="drab-slot-opt' + (isCur ? ' is-current' : '') + '" onclick="drabDoSwap(' + itemId + ',' + o.work_item_id + ')">'
        + '<span class="drab-slot-opt-name">' + drabEsc(drabName(o, lang)) + (isCur ? ' (current)' : '') + '</span>'
        + '<span class="drab-slot-opt-rate">' + drabIDR(o.rate) + ' / ' + drabEsc(o.unit_code || '') + '</span>'
        + '</button>';
    }).join('');
  });
}
function drabDoSwap(itemId, workItemId) {
  drabPost('swap_slot', { item_id: itemId, work_item_id: workItemId }).then(function (res) {
    if (drabHandledUpgrade(res)) return;
    if (res.json && res.json.ok) {
      drabCloseModal();
      if (res.json.totals) _drabEditor.rab.totals = res.json.totals;
      drabToast('Specification swapped.', 'success');
      drabReloadEditor();
    } else drabToast('Could not swap the specification.', 'error');
  });
}

/* ----- AHSP rate build-up (modal) ----- */
function drabShowAhsp(workItemId) {
  var zone = (_drabEditor.rab.development && _drabEditor.rab.development.base_zone) || 'south';
  drabShowModal('<h3 class="drab-markups-title" style="margin-bottom:var(--space-3)">How this rate is built up</h3><div id="drab-ahsp-body">' + drabSpinner() + '</div>');
  drabGet('ahsp', { work_item_id: workItemId, zone: zone }).then(function (res) {
    var body = document.getElementById('drab-ahsp-body');
    if (!body) return;
    if (!(res.json && res.json.ok)) { body.innerHTML = drabErrorCard('Could not load the build-up.'); return; }
    if (!res.json.has_buildup || !(res.json.components || []).length) {
      body.innerHTML = '<p style="color:var(--color-text-muted);font-size:var(--text-sm)">This is a direct unit rate — no component build-up is recorded for it.</p>';
      return;
    }
    var lang = _drabEditor.lang;
    var typeName = { labour: 'Labour', material: 'Material', equipment: 'Equipment', overhead: 'Overhead' };
    var rows = res.json.components.map(function (c) {
      return '<tr>'
        + '<td><span class="drab-ahsp-type">' + drabEsc(typeName[c.type] || c.type) + '</span></td>'
        + '<td>' + drabEsc(drabName(c, lang)) + '</td>'
        + '<td>' + Number(c.coefficient).toLocaleString('id-ID', { maximumFractionDigits: 4 }) + '</td>'
        + '<td>' + drabEsc(c.unit || '') + '</td>'
        + '<td>' + drabIDR(c.price) + '</td>'
        + '<td>' + drabIDR(c.cost) + '</td>'
        + '</tr>';
    }).join('');
    body.innerHTML = ''
      + '<div class="drab-ahsp">'
      + '  <table class="drab-ahsp-table">'
      + '    <thead><tr><th>Type</th><th>Component</th><th>Coef.</th><th>Unit</th><th>Price</th><th>Cost</th></tr></thead>'
      + '    <tbody>' + rows + '</tbody>'
      + '  </table>'
      + '  <div class="drab-ahsp-foot"><span>Derived unit rate</span><span>' + drabIDR(res.json.derived_rate) + '</span></div>'
      + '</div>'
      + '<p style="font-size:var(--text-xs);color:var(--color-text-faint);margin-top:var(--space-2)">Site factors for distance and access are applied on top of this base rate.</p>';
  });
}

/* =====================================================================
 * 6) CATALOG BROWSER (premium)
 * ===================================================================== */
async function renderDrabCatalog(el) {
  if (!drabLoggedIn()) {
    el.innerHTML = drabHero('Work-item catalog', 'Sign in to browse the priced work-item catalog.')
      + '<div class="section"><div class="container" style="text-align:center;padding:var(--space-12) 0;">'
      + '  <button class="btn btn--primary" onclick="drabLogin()">' + drabT('drab.sign_in', 'Sign in to continue') + '</button>'
      + '</div></div>';
    return;
  }
  el.innerHTML = drabHero('Work-item catalog', 'Search every priced work item across all disciplines.')
    + '<div class="section"><div class="container">'
    + '  <div class="drab-catalog" style="margin-bottom:var(--space-4)">'
    + '    <div class="drab-catalog-toolbar">'
    + '      <div class="drab-catalog-search">'
    + '        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg>'
    + '        <input type="search" class="rab-input" id="drab-cat-q" placeholder="Search items, e.g. waterproofing, AC, marble…">'
    + '      </div>'
    + '      <select class="rab-select" id="drab-cat-disc">'
    + '        <option value="">All disciplines</option>'
    + '        <option value="PREP">Preliminaries</option>'
    + '        <option value="STR">Structure</option>'
    + '        <option value="ARCH">Architecture</option>'
    + '        <option value="MEP">MEP</option>'
    + '      </select>'
    + '      <button class="btn btn--primary btn--sm" id="drab-cat-search">Search</button>'
    + '    </div>'
    + '  </div>'
    + '  <div id="drab-cat-results">' + drabSpinner() + '</div>'
    + '</div></div>';

  var q = el.querySelector('#drab-cat-q');
  var disc = el.querySelector('#drab-cat-disc');
  var btn = el.querySelector('#drab-cat-search');
  var run = function () { drabCatalogSearch(q.value, disc.value); };
  btn.addEventListener('click', run);
  q.addEventListener('keydown', function (e) { if (e.key === 'Enter') run(); });
  disc.addEventListener('change', run);
  // Defer the initial search until the router has attached this view (drabCatalogSearch
  // resolves #drab-cat-results via document.getElementById, which is null while detached).
  setTimeout(function () { drabCatalogSearch('', ''); }, 0);
}
function drabCatalogSearch(q, disc) {
  var results = document.getElementById('drab-cat-results');
  if (!results) return;
  results.innerHTML = drabSpinner();
  drabGet('catalog', { q: q, discipline: disc }).then(function (res) {
    if (res.status === 403 || (res.json && res.json.error === 'upgrade_required')) {
      results.innerHTML = drabUpgradeHtml('drab_catalog_browse', res.json && res.json.detail, false);
      return;
    }
    if (!(res.json && res.json.ok)) { results.innerHTML = drabErrorCard('Search failed.'); return; }
    var items = res.json.items || [];
    if (items.length === 0) { results.innerHTML = '<div class="drab-catalog"><div class="drab-catalog-empty">No matching items.</div></div>'; return; }
    var lang = drabLang();
    var rows = items.map(function (it) {
      return '<tr>'
        + '<td class="drab-cat-code">' + drabEsc(it.code) + '</td>'
        + '<td><span class="drab-cat-disc">' + drabEsc(it.discipline) + '</span> ' + drabEsc(drabName(it, lang)) + '</td>'
        + '<td>' + drabEsc(it.unit_code || '') + '</td>'
        + '<td class="drab-cat-mat">' + drabIDR(it.material) + '</td>'
        + '<td class="drab-cat-lab">' + drabIDR(it.labour) + '</td>'
        + '<td class="drab-cat-rate">' + drabIDR(it.rate) + '</td>'
        + '</tr>';
    }).join('');
    results.innerHTML = '<div class="drab-catalog"><div class="drab-catalog-table-wrap"><table class="drab-catalog-table">'
      + '<thead><tr><th>Code</th><th>Item</th><th data-drab-tip="Unit of measure.">Unit</th>'
      + '<th class="drab-cat-mat" data-drab-tip="Material component of the supply &amp; install rate.">Material</th>'
      + '<th class="drab-cat-lab" data-drab-tip="Labour component of the supply &amp; install rate.">Labour</th>'
      + '<th class="drab-cat-rate" data-drab-tip="Combined supply &amp; install rate per unit.">Rate</th></tr></thead>'
      + '<tbody>' + rows + '</tbody></table></div></div>';
  }).catch(function () { results.innerHTML = drabErrorCard('Search failed.'); });
}

/* =====================================================================
 * Global exposure (router calls these by name).
 * ===================================================================== */
window.DRAB_API = DRAB_API;
window.renderDrabHome = renderDrabHome;
window.renderDrabWizard = renderDrabWizard;
window.renderDrabDevelopments = renderDrabDevelopments;
window.renderDrabDevelopment = renderDrabDevelopment;
window.renderDrabEditor = renderDrabEditor;
window.renderDrabCatalog = renderDrabCatalog;
/* helpers referenced by inline handlers */
window.drabNav = drabNav;
window.drabLogin = drabLogin;
window.drabShowUpgrade = drabShowUpgrade;
window.drabCloseModal = drabCloseModal;
window.drabOpenUpgrade = drabOpenUpgrade;
window.drabSwitchDisc = drabSwitchDisc;
window.drabSetEditorLang = drabSetEditorLang;
window.drabSetDisplay = drabSetDisplay;
window.drabRegenerate = drabRegenerate;
window.drabToggleExport = drabToggleExport;
window.drabAddSection = drabAddSection;
window.drabRenameSection = drabRenameSection;
window.drabDeleteSection = drabDeleteSection;
window.drabAddItem = drabAddItem;
window.drabSaveNewItem = drabSaveNewItem;
window.drabEditItem = drabEditItem;
window.drabSaveEditItem = drabSaveEditItem;
window.drabDeleteItem = drabDeleteItem;
window.drabShowTakeoff = drabShowTakeoff;
window.drabAddTakeoffRow = drabAddTakeoffRow;
window.drabRecalcTakeoff = drabRecalcTakeoff;
window.drabSaveTakeoff = drabSaveTakeoff;
window.drabShowSwap = drabShowSwap;
window.drabDoSwap = drabDoSwap;
window.drabShowAhsp = drabShowAhsp;
window.drabDeleteBuilding = drabDeleteBuilding;
window.drabRenameDevelopment = drabRenameDevelopment;
window.drabDeleteDevelopment = drabDeleteDevelopment;
window.drabReloadEditor = drabReloadEditor;

/* Activate tooltips for the whole SPA session (idempotent, delegated on document). */
if (typeof document !== 'undefined') { try { drabTipInit(); } catch (e) {} }
