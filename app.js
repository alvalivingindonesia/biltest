/* app.js — Build in Lombok: routing, data, filtering, rendering */

'use strict';

// =====================================================
// THEME TOGGLE
// =====================================================

(function() {
  const root = document.documentElement;
  let theme = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
  root.setAttribute('data-theme', theme);

  window.__setTheme = function(t) {
    theme = t;
    root.setAttribute('data-theme', theme);
    document.querySelectorAll('[data-theme-toggle]').forEach(btn => {
      btn.innerHTML = getThemeIcon(theme);
      btn.setAttribute('aria-label', (typeof t === 'function')
        ? t(theme === 'dark' ? 'theme.switch_light_aria' : 'theme.switch_dark_aria', `Switch to ${theme === 'dark' ? 'light' : 'dark'} mode`)
        : `Switch to ${theme === 'dark' ? 'light' : 'dark'} mode`);
    });
  };

  window.__toggleTheme = function() {
    window.__setTheme(theme === 'dark' ? 'light' : 'dark');
  };

  window.__getCurrentTheme = function() { return theme; };

  function getThemeIcon(t) {
    if (t === 'dark') {
      return `<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="5"/><path d="M12 1v2M12 21v2M4.22 4.22l1.42 1.42M18.36 18.36l1.42 1.42M1 12h2M21 12h2M4.22 19.78l1.42-1.42M18.36 5.64l1.42-1.42"/></svg>`;
    }
    return `<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>`;
  }
})();


// =====================================================
// i18n — English + Indonesian
// Dictionaries live in /i18n/en.js and /i18n/id.js and are loaded
// before app.js in index.html. Missing keys fall back to English,
// and missing dictionaries fall back to {} so nothing breaks.
// =====================================================
const I18N = {
  en: window.I18N_EN || {},
  id: window.I18N_ID || {},
};
let CURRENT_LANG = (function() {
  try {
    const stored = localStorage.getItem('bil_lang');
    if (stored === 'en' || stored === 'id') return stored;
  } catch (e) {}
  const nav = (navigator.language || '').toLowerCase();
  return nav.startsWith('id') ? 'id' : 'en';
})();

function t(key, fallback) {
  const dict = I18N[CURRENT_LANG] || I18N.en || {};
  if (Object.prototype.hasOwnProperty.call(dict, key)) return dict[key];
  // Fall back to English dict, then to the provided fallback, then the key itself
  if (I18N.en && Object.prototype.hasOwnProperty.call(I18N.en, key)) return I18N.en[key];
  return (fallback !== undefined) ? fallback : key;
}

function lookupLabel(row) {
  if (!row) return '';
  if (CURRENT_LANG === 'id' && row.label_id) return row.label_id;
  return row.label || '';
}

function applyStaticTranslations(root) {
  root = root || document;
  root.querySelectorAll('[data-i18n]').forEach(el => {
    el.textContent = t(el.getAttribute('data-i18n'), el.textContent);
  });
  root.querySelectorAll('[data-i18n-placeholder]').forEach(el => {
    el.placeholder = t(el.getAttribute('data-i18n-placeholder'), el.placeholder);
  });
  root.querySelectorAll('[data-i18n-aria]').forEach(el => {
    el.setAttribute('aria-label', t(el.getAttribute('data-i18n-aria'), el.getAttribute('aria-label') || ''));
  });
  root.querySelectorAll('[data-i18n-title]').forEach(el => {
    el.setAttribute('title', t(el.getAttribute('data-i18n-title'), el.getAttribute('title') || ''));
  });
  document.documentElement.lang = CURRENT_LANG;
}

function updateLangToggleUI() {
  document.querySelectorAll('.lang-toggle').forEach(tog => {
    tog.querySelectorAll('.lang-opt').forEach(opt => {
      const active = opt.getAttribute('data-lang') === CURRENT_LANG;
      opt.classList.toggle('active', active);
    });
  });
}

function setLanguage(lang) {
  if (lang !== 'en' && lang !== 'id') return;
  if (lang === CURRENT_LANG) return;
  CURRENT_LANG = lang;
  try { localStorage.setItem('bil_lang', lang); } catch (e) {}
  applyStaticTranslations();
  updateLangToggleUI();
  // Re-render the current SPA view so JS-generated strings refresh.
  if (typeof router === 'function') {
    try { router(); } catch (e) { /* swallow */ }
  }
}

window.setLanguage = setLanguage;
window.getCurrentLang = function() { return CURRENT_LANG; };


// =====================================================
// DATA LAYER — API-powered (MySQL via PHP)
// =====================================================

const DataLayer = (() => {
  const API_BASE = '/api';
  const cache = new Map();

  async function apiFetch(endpoint, params = {}) {
    const url = new URL(API_BASE + '/' + endpoint, window.location.origin);
    Object.entries(params).forEach(([k, v]) => {
      if (v !== undefined && v !== null && v !== '') url.searchParams.set(k, v);
    });
    const key = url.toString();
    if (cache.has(key)) return cache.get(key);

    // One silent retry — a single dropped request on a flaky connection
    // must not leave filters or grids empty.
    let res;
    try {
      res = await fetch(key);
      if (!res.ok) throw new Error(`API error ${res.status}`);
    } catch (e) {
      await new Promise(r => setTimeout(r, 700));
      res = await fetch(key);
      if (!res.ok) throw new Error(`API error ${res.status}`);
    }
    const data = await res.json();
    cache.set(key, data);
    return data;
  }

  function mapProvider(row) {
    return {
      ...row,
      group: row.group_key || row.group,
      category: row.category_key || row.category,
      categories: (row.categories || []).map(c => typeof c === 'object' ? c : {key: c, label: c}),
      area: row.area_key || row.area,
      short_description_en: row.short_description || row.short_description_en || '',
      description_en: row.description || row.description_en || '',
      tags: row.tags || [],
      google_rating: row.google_rating ? parseFloat(row.google_rating) : null,
      google_review_count: row.google_review_count ? parseInt(row.google_review_count) : 0,
      google_maps_url: row.google_maps_url || '',
      whatsapp_number: row.whatsapp_number || '',
      website_url: row.website_url || '',
      languages: row.languages || 'Bahasa only',
      is_featured: parseInt(row.is_featured) === 1,
      is_trusted: parseInt(row.is_trusted) === 1,
    };
  }

  function mapDeveloper(row) {
    return {
      ...row,
      short_description_en: row.short_description || row.short_description_en || '',
      description_en: row.description || row.description_en || '',
      areas_focus: row.areas ? row.areas.map(a => a.key || a) : (row.areas_focus || []),
      categories: (row.categories || []).map(c => typeof c === 'object' ? c : {key: c, label: c}),
      project_types: row.project_types ? row.project_types.map(pt => pt.key || pt) : (row.project_types || []),
      tags: row.tags || [],
      google_rating: row.google_rating ? parseFloat(row.google_rating) : null,
      google_review_count: row.google_review_count ? parseInt(row.google_review_count) : 0,
      languages: row.languages || 'Bahasa only',
      min_ticket_usd: row.min_ticket_usd ? parseInt(row.min_ticket_usd) : null,
      is_featured: parseInt(row.is_featured) === 1,
    };
  }

  function mapProject(row) {
    return {
      ...row,
      location_area: row.area_key || row.location_area,
      project_type: row.project_type_key || row.project_type,
      status: row.status_key || row.status,
      short_description_en: row.short_description || row.short_description_en || '',
      description_en: row.description || row.description_en || '',
      tags: row.tags || [],
      min_investment_usd: row.min_investment_usd ? parseInt(row.min_investment_usd) : null,
      is_featured: parseInt(row.is_featured) === 1,
    };
  }

  function mapListing(row) {
    return {
      ...row,
      listing_type: row.listing_type_key || row.listing_type,
      area: row.area_key || row.area,
      price_idr: row.price_idr ? parseInt(row.price_idr) : null,
      price_usd: row.price_usd ? parseInt(row.price_usd) : null,
      price_eur: row.price_eur ? parseInt(row.price_eur) : null,
      price_aud: row.price_aud ? parseInt(row.price_aud) : null,
      land_size_sqm: row.land_size_sqm ? parseInt(row.land_size_sqm) : null,
      land_size_are: row.land_size_are ? parseFloat(row.land_size_are) : null,
      building_size_sqm: row.building_size_sqm ? parseInt(row.building_size_sqm) : null,
      bedrooms: row.bedrooms ? parseInt(row.bedrooms) : null,
      bathrooms: row.bathrooms ? parseInt(row.bathrooms) : null,
      features: typeof row.features === 'string' ? JSON.parse(row.features || '[]') : (row.features || []),
      tags: row.tags || [],
      images: row.images || [],
      image: row.image || null,
      is_featured: parseInt(row.is_featured) === 1,
    };
  }

  function mapAgent(row) {
    return {
      ...row,
      google_rating: row.google_rating ? parseFloat(row.google_rating) : null,
      google_review_count: row.google_review_count ? parseInt(row.google_review_count) : 0,
      listing_count: row.listing_count ? parseInt(row.listing_count) : 0,
    };
  }

  return {
    async getProviders(filters = {}) {
      const res = await apiFetch('providers', filters);
      return { data: (res.data || []).map(mapProvider), meta: res.meta || { total: 0 } };
    },

    async getProvider(slug) {
      const res = await apiFetch('providers/' + slug);
      return mapProvider(res.data);
    },

    async getDevelopers(filters = {}) {
      const res = await apiFetch('developers', filters);
      return { data: (res.data || []).map(mapDeveloper), meta: res.meta || { total: 0 } };
    },

    async getDeveloper(slug) {
      const res = await apiFetch('developers/' + slug);
      return mapDeveloper(res.data);
    },

    async getProjects(filters = {}) {
      const res = await apiFetch('projects', filters);
      return { data: (res.data || []).map(mapProject), meta: res.meta || { total: 0 } };
    },

    async getProject(slug) {
      const res = await apiFetch('projects/' + slug);
      return mapProject(res.data);
    },

    async getListings(filters = {}) {
      const res = await apiFetch('listings', filters);
      return { data: (res.data || []).map(mapListing), meta: res.meta || { total: 0 } };
    },
    async getListingCounts(filters = {}) {
      return await apiFetch('listing_counts', filters);
    },
    async getListing(slug) {
      const res = await apiFetch('listings/' + slug);
      return mapListing(res.data);
    },
    async getAgents(filters = {}) {
      const res = await apiFetch('agents', filters);
      return { data: (res.data || []).map(mapAgent), meta: res.meta || { total: 0 } };
    },
    async getAgent(slug) {
      const res = await apiFetch('agents/' + slug);
      return mapAgent(res.data);
    },

    async getGuides() {
      const res = await apiFetch('guides');
      return res.data || [];
    },

    async getGuide(slug) {
      const res = await apiFetch('guides/' + slug);
      return res.data;
    },

    async search(q) {
      const res = await apiFetch('search', { q });
      return res.data || [];
    },

    async getFilters() {
      return await apiFetch('filters');
    },

    clearCache() { cache.clear(); },
  };
})();



// =====================================================
// UTILITY FUNCTIONS
// =====================================================

function isAdmin() {
  return !!(UserAuth.user && UserAuth.user.role === 'admin');
}

function formatUSD(amount) {
  if (!amount) return "TBC";
  if (amount >= 1000000) return `$${(amount / 1000000).toFixed(1)}M`;
  if (amount >= 1000) return `$${(amount / 1000).toFixed(0)}k`;
  return `$${amount}`;
}

function formatIDR(amount) {
  if (!amount) return null;
  if (amount >= 1000000000) return 'Rp ' + (amount / 1000000000).toFixed(1) + 'B';
  if (amount >= 1000000) return 'Rp ' + (amount / 1000000).toFixed(0) + 'M';
  if (amount >= 1000) return 'Rp ' + (amount / 1000).toFixed(0) + 'K';
  return 'Rp ' + amount;
}

function formatLandSize(sqm, are) {
  if (are) return `${are} are (${sqm ? sqm.toLocaleString() + ' m²' : ''})`;
  if (sqm) return `${sqm.toLocaleString()} m²`;
  return '';
}

// =====================================================
// DISPLAY CURRENCY — property listings & developments only.
// Presentation setting (see CONTEXT.md "Display Currency"):
// canonical price is IDR (docs/adr/0006); other currencies are
// converted client-side from currency_rates and marked ≈.
// Materials / RAB prices stay IDR and never use this.
// =====================================================
const Currency = {
  LIST: ['IDR', 'USD', 'EUR', 'AUD'],
  // Used only if the currency_rates table is missing/empty
  FALLBACK_IDR: { IDR: 1, USD: 16500, EUR: 17800, AUD: 10500 },
  _stored: (function() {
    try {
      var c = localStorage.getItem('bil_currency');
      if (c && ['IDR', 'USD', 'EUR', 'AUD'].indexOf(c) >= 0) return c;
    } catch (e) {}
    return null;
  })(),
  get() { return this._stored || (CURRENT_LANG === 'id' ? 'IDR' : 'USD'); },
  set(cur) {
    if (this.LIST.indexOf(cur) < 0) return;
    this._stored = cur;
    try { localStorage.setItem('bil_currency', cur); } catch (e) {}
  },
  rate(from, to) {
    if (from === to) return 1;
    var r = (FilterData.currency_rates || {})[from + '_' + to];
    if (r && r > 0) return r;
    return this.FALLBACK_IDR[from] / this.FALLBACK_IDR[to];
  },
  convert(amount, from, to) { return amount * this.rate(from, to); },
  _num(n) {
    var s = (Math.round(n * 10) / 10).toFixed(1).replace(/\.0$/, '');
    return CURRENT_LANG === 'id' ? s.replace('.', ',') : s;
  },
  format(amount, cur) {
    if (!amount && amount !== 0) return '';
    cur = cur || this.get();
    if (cur === 'IDR') {
      if (CURRENT_LANG === 'id') {
        if (amount >= 1e9) return 'Rp ' + this._num(amount / 1e9) + ' M';
        if (amount >= 1e6) return 'Rp ' + this._num(amount / 1e6) + ' Jt';
        return 'Rp ' + Math.round(amount).toLocaleString('id-ID');
      }
      if (amount >= 1e9) return 'Rp ' + this._num(amount / 1e9) + 'B';
      if (amount >= 1e6) return 'Rp ' + this._num(amount / 1e6) + 'M';
      return 'Rp ' + Math.round(amount).toLocaleString();
    }
    var sym = { USD: '$', EUR: '€', AUD: 'A$' }[cur] || '';
    if (amount >= 1e6) return sym + this._num(amount / 1e6) + 'M';
    if (amount >= 1e4) return sym + Math.round(amount / 1000) + 'k';
    return sym + Math.round(amount).toLocaleString();
  },
  /**
   * Price of a listing in the visitor's Display Currency.
   * Exact when the listing stores that currency; otherwise converted
   * from its canonical/native price and flagged approx.
   * Returns null for Price on Request listings.
   */
  listingPrice(l) {
    var cur = this.get();
    var exact = l['price_' + cur.toLowerCase()];
    if (exact && exact > 0) return { text: this.format(exact, cur), approx: false };
    var order = ['idr', 'usd', 'eur', 'aud'];
    for (var i = 0; i < order.length; i++) {
      var v = l['price_' + order[i]];
      if (v && v > 0) {
        return { text: this.format(this.convert(v, order[i].toUpperCase(), cur), cur), approx: true };
      }
    }
    return null;
  },
  priceHtml(l) {
    var p = this.listingPrice(l);
    if (!p) return t('listings.price_on_request', 'Price on request');
    if (!p.approx) return p.text;
    return '<span class="price-approx" title="' + t('currency.approx_title', 'Converted at today’s exchange rate') + '">≈</span>' + p.text;
  }
};

/** Development (project) min-investment in the Display Currency (USD-native). */
function projectPriceHtml(usd) {
  if (!usd) return 'TBC';
  var cur = Currency.get();
  if (cur === 'USD') return Currency.format(usd, 'USD');
  return '<span class="price-approx" title="' + t('currency.approx_title', 'Converted at today’s exchange rate') + '">≈</span>' + Currency.format(Currency.convert(usd, 'USD', cur), cur);
}

// ---- Dynamic filter data (loaded from DB) ----
const FilterData = {
  areas: [], regions: [], places: [], groups: [], categories: [], project_types: [], project_statuses: [], listing_types: [], land_certificate_types: [],
  feature_tags: [], currency_rates: {},
  _loaded: false,
  async load() {
    if (this._loaded) return;
    try {
      const f = await DataLayer.getFilters();
      this.areas = f.areas || [];
      this.regions = f.regions || [];
      this.places = f.places || [];
      this.groups = f.groups || [];
      this.categories = f.categories || [];
      this.project_types = f.project_types || [];
      this.project_statuses = f.project_statuses || [];
      this.listing_types = f.listing_types || [];
      this.land_certificate_types = f.land_certificate_types || [];
      this.feature_tags = f.feature_tags || [];
      this.currency_rates = f.currency_rates || {};
      this._loaded = true;
    } catch(e) { console.error('Failed to load filters:', e); }
  },
  labelMap(arr) {
    // Returns {key → localised label}. lookupLabel() picks label_id when
    // Indonesian is active and the row has one, else falls back to label.
    const m = {}; arr.forEach(i => { m[i.key || i.region_key] = lookupLabel(i); }); return m;
  }
};

/** Build area <option> tags grouped by region */
function buildAreaOptions(selectedValue) {
  const regions = FilterData.regions;
  const areas = FilterData.areas;
  if (!regions.length) {
    // Fallback: flat list if regions not loaded
    return areas.map(a => '<option value="' + (a.key) + '"' + (selectedValue === a.key ? ' selected' : '') + '>' + lookupLabel(a) + '</option>').join('');
  }
  let html = '';
  const regionMap = {};
  regions.forEach(r => { regionMap[r.region_key] = { label: lookupLabel(r), areas: [] }; });
  areas.forEach(a => {
    const rk = a.region_key || 'other';
    if (!regionMap[rk]) regionMap[rk] = { label: rk.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase()), areas: [] };
    regionMap[rk].areas.push(a);
  });
  // Also add region-level options for filtering
  for (const [rk, rd] of Object.entries(regionMap)) {
    if (!rd.areas.length) continue;
    html += '<optgroup label="' + rd.label + '">';
    html += '<option value="region:' + rk + '"' + (selectedValue === 'region:' + rk ? ' selected' : '') + '>▶ All ' + rd.label + '</option>';
    rd.areas.forEach(a => {
      html += '<option value="' + a.key + '"' + (selectedValue === a.key ? ' selected' : '') + '>' + lookupLabel(a) + '</option>';
    });
    html += '</optgroup>';
  }
  return html;
}

function formatAreaLabel(area) {
  const m = FilterData.labelMap(FilterData.areas);
  return m[area] || (area || '').replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase());
}

function renderBadge(badge) {
  if (!badge) return '';
  if (/^https?:\/\/.+\.(svg|png|jpg|jpeg|gif|webp)(\?.*)?$/i.test(badge)) {
    return '<img src="' + badge + '" alt="Badge" style="height:28px;width:auto;vertical-align:middle;">';
  }
  return badge;
}

function iconInstagram() { return '<svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zM12 0C8.741 0 8.333.014 7.053.072 2.695.272.273 2.69.073 7.052.014 8.333 0 8.741 0 12c0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98C8.333 23.986 8.741 24 12 24c3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98C15.668.014 15.259 0 12 0zm0 5.838a6.162 6.162 0 100 12.324 6.162 6.162 0 000-12.324zM12 16a4 4 0 110-8 4 4 0 010 8zm6.406-11.845a1.44 1.44 0 100 2.881 1.44 1.44 0 000-2.881z"/></svg>'; }
function iconFacebook() { return '<svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/></svg>'; }
function iconLinkedIn() { return '<svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><path d="M20.447 20.452h-3.554v-5.569c0-1.328-.027-3.037-1.852-3.037-1.853 0-2.136 1.445-2.136 2.939v5.667H9.351V9h3.414v1.561h.046c.477-.9 1.637-1.85 3.37-1.85 3.601 0 4.267 2.37 4.267 5.455v6.286zM5.337 7.433a2.062 2.062 0 01-2.063-2.065 2.064 2.064 0 112.063 2.065zm1.782 13.019H3.555V9h3.564v11.452zM22.225 0H1.771C.792 0 0 .774 0 1.729v20.542C0 23.227.792 24 1.771 24h20.451C23.2 24 24 23.227 24 22.271V1.729C24 .774 23.2 0 22.222 0h.003z"/></svg>'; }

function formatWhatsAppNumber(phone) {
  if (!phone) return '';
  var num = phone.replace(/[^0-9+]/g, '');
  if (num.startsWith('08')) num = '+62' + num.slice(1);
  else if (num.startsWith('8') && num.length >= 10) num = '+62' + num;
  else if (!num.startsWith('+')) num = '+' + num;
  return num;
}

function renderSocialLinks(entity) {
  const links = [];
  if (entity.instagram_url) links.push(`<a href="${entity.instagram_url}" target="_blank" rel="noopener noreferrer" class="social-link social-link--instagram" aria-label="Instagram" title="Instagram">${iconInstagram()}</a>`);
  if (entity.facebook_url) links.push(`<a href="${entity.facebook_url}" target="_blank" rel="noopener noreferrer" class="social-link social-link--facebook" aria-label="Facebook" title="Facebook">${iconFacebook()}</a>`);
  if (entity.linkedin_url) links.push(`<a href="${entity.linkedin_url}" target="_blank" rel="noopener noreferrer" class="social-link social-link--linkedin" aria-label="LinkedIn" title="LinkedIn">${iconLinkedIn()}</a>`);
  if (links.length === 0) return '';
  return `<div class="social-links" style="display:flex;gap:var(--space-3);margin-top:var(--space-3);">${links.join('')}</div>`;
}

function formatGroupLabel(group) {
  const m = FilterData.labelMap(FilterData.groups);
  return m[group] || (group || '').replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase());
}

function formatCategoryLabel(cat) {
  const m = FilterData.labelMap(FilterData.categories);
  return m[cat] || (cat || '').replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase());
}

function formatProjectType(type) {
  const m = FilterData.labelMap(FilterData.project_types);
  return m[type] || (type || '').replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase());
}

function formatProjectStatus(status) {
  const m = FilterData.labelMap(FilterData.project_statuses);
  return m[status] || (status || '').replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase());
}

function buildFilterOptions(items, selectedValue, filterByKey, filterByValue) {
  let list = items;
  if (filterByKey && filterByValue) list = items.filter(i => i[filterByKey] === filterByValue);
  return list.map(i => `<option value="${i.key}" ${selectedValue === i.key ? 'selected' : ''}>${lookupLabel(i)}</option>`).join('');
}

function getStatusBadgeClass(status) {
  if (status === "completed") return "badge--status-completed";
  if (status === "under_construction") return "badge--status-construction";
  return "badge--status-planning";
}

function getGroupBadgeClass(group) {
  if (group === "builders_trades") return "badge--group-builder";
  if (group === "professional_services") return "badge--group-professional";
  if (group === "specialist_contractors") return "badge--group-specialist";
  if (group === "suppliers_materials") return "badge--group-supplier";
  return "badge--group-specialist";
}

function confidenceScore(rating, count) {
  if (!rating || !count) return 0;
  return rating * Math.log(Math.max(count, 1) + 1);
}

// ---- Render Stars ----
function renderStars(rating) {
  if (!rating) return '';
  const full = Math.floor(rating);
  const half = (rating - full) >= 0.5 ? 1 : 0;
  const empty = 5 - full - half;
  let html = '<div class="stars" aria-hidden="true">';
  for (let i = 0; i < full; i++) {
    html += `<svg class="star-svg" viewBox="0 0 24 24" fill="#f59e0b"><polygon points="12,2 15.09,8.26 22,9.27 17,14.14 18.18,21.02 12,17.77 5.82,21.02 7,14.14 2,9.27 8.91,8.26"/></svg>`;
  }
  if (half) {
    html += `<svg class="star-svg" viewBox="0 0 24 24"><defs><linearGradient id="hg"><stop offset="50%" stop-color="#f59e0b"/><stop offset="50%" stop-color="var(--color-border)"/></linearGradient></defs><polygon points="12,2 15.09,8.26 22,9.27 17,14.14 18.18,21.02 12,17.77 5.82,21.02 7,14.14 2,9.27 8.91,8.26" fill="url(#hg)"/></svg>`;
  }
  for (let i = 0; i < empty; i++) {
    html += `<svg class="star-svg" viewBox="0 0 24 24" fill="var(--color-border)"><polygon points="12,2 15.09,8.26 22,9.27 17,14.14 18.18,21.02 12,17.77 5.82,21.02 7,14.14 2,9.27 8.91,8.26"/></svg>`;
  }
  html += '</div>';
  return html;
}

function renderGoogleRating(rating, count, size = 'card') {
  rating = rating ? parseFloat(rating) : null;
  count = count ? parseInt(count) : 0;
  if (!rating) {
    return `<div class="google-rating"><span class="rating-na">${t('detail.no_reviews', 'No Google reviews yet')}</span></div>`;
  }
  const cls = size === 'detail' ? 'detail-google-rating' : 'google-rating';
  const numCls = size === 'detail' ? 'detail-rating-number' : 'rating-number';
  return `
    <div class="${cls}">
      <span class="google-rating-logo">
        <svg width="14" height="14" viewBox="0 0 24 24" aria-hidden="true"><path d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z" fill="#4285F4"/><path d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z" fill="#34A853"/><path d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l3.66-2.84z" fill="#FBBC05"/><path d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z" fill="#EA4335"/></svg>
        Google
      </span>
      ${renderStars(rating)}
      <span class="${numCls}">${rating.toFixed(1)}</span>
      <span class="rating-count">(${count.toLocaleString()})</span>
    </div>
  `;
}

// WhatsApp icon
function iconWhatsApp() {
  return `<svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg>`;
}

function iconMapPin() {
  return `<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>`;
}

function iconClock() {
  return `<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>`;
}

function iconPhone() {
  return `<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.69 12a19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 3.6 1.26h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L7.91 8.88a16 16 0 0 0 6.06 6.06l.96-.96a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 21.7 16.4l.22.52z"/></svg>`;
}

function iconGlobe() {
  return `<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>`;
}

function iconLang() {
  return `<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M5 8l6 6M4 14l6-6 2-3"/><path d="M2 5h12M7 2v3"/><path d="M22 22l-5-10-5 10M14 18h6"/></svg>`;
}

function iconArrowRight() {
  return `<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M5 12h14M12 5l7 7-7 7"/></svg>`;
}

function iconExternalLink() {
  return `<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>`;
}

function iconSearch() {
  return `<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>`;
}

// =====================================================
// ROUTING
// =====================================================

let currentRoute = { page: 'home', params: {} };

function getHashParts() {
  const hash = window.location.hash.slice(1) || 'home';
  const [pathPart, queryPart] = hash.split('?');
  const segments = pathPart.split('/').filter(Boolean);
  const params = {};
  if (queryPart) {
    queryPart.split('&').forEach(p => {
      const [k, v] = p.split('=');
      if (k) params[decodeURIComponent(k)] = decodeURIComponent(v || '');
    });
  }
  return { segments, params };
}

function navigate(hash) {
  window.location.hash = hash;
}

function buildHash(page, params = {}) {
  const query = Object.entries(params)
    .filter(([, v]) => v !== '' && v !== null && v !== undefined)
    .map(([k, v]) => `${encodeURIComponent(k)}=${encodeURIComponent(v)}`)
    .join('&');
  return query ? `${page}?${query}` : page;
}

async function router() {
  const { segments, params } = getHashParts();
  const page = segments[0] || 'home';
  currentRoute = { page, segments, params };

  // Update nav active states
  var currentGroup = params.group || '';
  document.querySelectorAll('.nav-links a, .mobile-menu a').forEach(function(a) {
    a.classList.remove('active');
    var href = (a.getAttribute('href') || '').replace('#', '');
    var hrefPage = href.split('?')[0];
    var hrefParams = {};
    if (href.indexOf('?') !== -1) {
      href.split('?')[1].split('&').forEach(function(pair) {
        var kv = pair.split('=');
        hrefParams[kv[0]] = kv[1] || '';
      });
    }

    // Listings / Property & Agents
    if (hrefPage === 'listings' && (page === 'listings' || page === 'listing' || page === 'agents' || page === 'agent')) {
      a.classList.add('active');
    }
    // Developers & Investing
    if (hrefPage === 'developers' && (page === 'developers' || page === 'developer' || page === 'projects' || page === 'project')) {
      a.classList.add('active');
    }
    // Directory group links (builders_trades, professional_services, suppliers_materials)
    if (hrefPage === 'directory' && hrefParams.group && page === 'directory' && currentGroup === hrefParams.group) {
      a.classList.add('active');
    }
    // Provider detail inherits active from the matching group
    if (hrefPage === 'directory' && hrefParams.group && page === 'provider') {
      // Provider detail — highlight first directory link as fallback
      // (We don't know the provider's group on the nav, so skip)
    }
    // Guides
    if (hrefPage === 'guides' && (page === 'guides' || page === 'guide')) {
      a.classList.add('active');
    }
    // RAB Tools
    if (hrefPage === 'rab-calculator' && (page === 'rab-calculator' || page === 'rab-estimates' || page === 'rab-result' || page === 'rab-projects' || page === 'rab-project' || page === 'rab-editor')) {
      a.classList.add('active');
    }
    // About
    if (hrefPage === 'about' && page === 'about') {
      a.classList.add('active');
    }
    // Get Quotes
    if (hrefPage === 'get-quotes' && (page === 'get-quotes' || page === 'quotes' || page === 'quote')) {
      a.classList.add('active');
    }
    // Home
    if (hrefPage === 'home' && page === 'home') {
      a.classList.add('active');
    }
  });

  // Propagate active state up to .nav-dropdown parent <li>
  document.querySelectorAll('.nav-dropdown').forEach(function(dd) {
    var hasActive = !!dd.querySelector('.nav-dropdown-menu a.active');
    dd.classList.toggle('active', hasActive);
    var toggle = dd.querySelector('.nav-dropdown-toggle');
    if (toggle) toggle.classList.toggle('active', hasActive);
  });

  // Render
  const main = document.getElementById('main-content');
  if (!main) return;

  // Show loading spinner while page renders (delayed so fast pages skip it)
  main.innerHTML = '<div class="page-loading"><div class="page-loading-spinner"></div></div>';
  var view = document.createElement('div');
  view.className = 'page-view';

  // Tear down the hero reveal before any route renders; renderHome re-inits it.
  // Prevents the pinned ScrollTrigger from leaking onto replaced DOM.
  if (typeof HeroReveal !== 'undefined') HeroReveal.destroy();

  switch (page) {
    case 'home': await renderHome(view); break;
    case 'directory': await renderDirectory(view, params); break;
    case 'provider': await renderProviderDetail(view, segments[1]); break;
    case 'developers': await renderDevelopers(view, params); break;
    case 'developer': await renderDeveloperDetail(view, segments[1]); break;
    case 'projects': await renderProjects(view, params); break;
    case 'project': await renderProjectDetail(view, segments[1]); break;
    case 'guides': await renderGuides(view); break;
    case 'guide': await renderGuideDetail(view, segments[1]); break;
    case 'account': await renderAccount(view, params); break;
    case 'submit-listing': await renderSubmitListing(view); break;
    case 'verify-result': renderVerifyResult(view, params); break;
    case 'reset-password': renderResetPassword(view, params); break;
    case 'listings': await renderListings(view, params); break;
    case 'listing': await renderListingDetail(view, segments[1]); break;
    case 'agents': await renderAgents(view, params); break;
    case 'agent': await renderAgentDetail(view, segments[1]); break;
    case 'create-listing': await renderCreateListing(view); break;
    case 'agent-signup': await renderAgentSignup(view); break;
    case 'about': renderAbout(view); break;
    case 'rab-calculator': await renderRABCalculator(view); break;
    case 'rab-estimates': await renderRABEstimates(view); break;
    case 'rab-result': await renderRABResult(view, params); break;
    case 'rab-projects': await renderRABProjects(view); break;
    case 'rab-project': await renderRABProjectDetail(view, segments[1]); break;
    case 'rab-editor': await renderRABEditor(view, segments[1]); break;
    case 'get-quotes': await renderGetQuotes(view, params); break;
    case 'quotes': await renderQuotesDashboard(view); break;
    case 'quote': await renderQuoteDetail(view, segments[1]); break;
    case 'search': await renderSearch(view, params); break;
    case 'list-your-business': await renderListYourBusiness(view); break;
    default: await renderHome(view);
  }

  // Clear spinner, then show rendered page
  main.innerHTML = '';
  main.appendChild(view);
  window.scrollTo({ top: 0, behavior: 'instant' });

  // Mobile filter button availability depends on the route
  if (typeof MobileFilterDrawer !== 'undefined') {
    MobileFilterDrawer.updateAvailability(page, view);
  }
}

// =====================================================
// RENDER: HOME
// =====================================================

async function renderHome(el) {
  let trustedProviders = [], featuredDevs = [], featuredProjects = [], homeGuides = [];
  let totalProviders = 0, totalDevelopers = 0, totalProjects = 0;
  try {
    const [trustedRes, allProvRes, devRes, projRes, guidesData, _] = await Promise.all([
      DataLayer.getProviders({ trusted: '1', per_page: 50 }),
      DataLayer.getProviders({ per_page: 1 }),
      DataLayer.getDevelopers({ per_page: 100 }),
      DataLayer.getProjects({ featured: '1', per_page: 4 }),
      DataLayer.getGuides(),
      FilterData.load(),
    ]);
    trustedProviders = trustedRes.data;
    totalProviders = allProvRes.meta.total || trustedRes.meta.total || trustedProviders.length;
    totalDevelopers = devRes.meta.total || 0;
    _cachedDevelopers = devRes.data;
    featuredDevs = devRes.data.filter(d => d.is_featured);
    featuredProjects = projRes.data;
    totalProjects = projRes.meta.total || featuredProjects.length;
    homeGuides = guidesData || [];
  } catch(e) { console.error('Failed to load home data:', e); }

  // Random subset: show up to 6 trusted providers, 6 featured developers
  function shuffleAndSlice(arr, max) {
    const shuffled = [...arr].sort(() => Math.random() - 0.5);
    return shuffled.slice(0, max);
  }
  const displayTrusted = shuffleAndSlice(trustedProviders, 6);
  const displayFeaturedDevs = shuffleAndSlice(featuredDevs, 6);
  const totalTrusted = trustedProviders.length;
  const totalFeaturedDevs = featuredDevs.length;

  el.innerHTML = `
    <!-- HERO — Full bleed, X-Ray materialization reveal -->
    <section class="hero" id="hero-reveal" aria-label="Build in Lombok hero">
      <!-- Base layer: the finished, photorealistic villa (hero-main.webp). -->
      <div class="hero-bg" id="hero-bg"></div>
      <!-- Reveal layer: pixel-registered wireframe of the same frame (hero-wire.webp).
           Clipped away top-to-bottom on scroll to "materialize" the villa beneath.
           Hidden by default so no-JS / no-GSAP gracefully shows the finished photo. -->
      <div class="hero-wire" id="hero-wire" aria-hidden="true"></div>
      <!-- Razor-thin scanning line that tracks the clip edge. -->
      <div class="hero-scanline" id="hero-scanline" aria-hidden="true"></div>
      <div class="hero-overlay"></div>
      <div class="hero-inner">
        <div class="container">
          <h1 class="hero-title">${t('home.hero_title', 'BUILD IN LOMBOK')}</h1>
          <p class="hero-subtitle">${t('home.hero_subtitle', 'AI-powered tools to help you build & invest in Lombok')}</p>

          <button type="button" class="hero-search hero-search--trigger" data-cmd-trigger aria-label="${t('palette.open_aria', 'Open search')}">
            <svg class="hero-search-icon-left" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
            <span class="hero-search-trigger-label">${t('palette.placeholder', 'What are you looking for?')}</span>
          </button>
        </div>
      </div>
      <div class="hero-scroll-hint" aria-hidden="true">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M12 5v14M5 12l7 7 7-7"/></svg>
      </div>
    </section>

    <!-- CATEGORY CARDS — 5 cards, 3+2 layout -->
    <section class="category-section">
      <div class="container">
        <div class="category-cards">
          <a href="#listings" class="category-card" onclick="navigate('listings'); return false;">
            <div class="category-card-icon">
              <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
            </div>
            <h3 class="category-card-title">${t('home.find_property', 'Find Property')}</h3>
            <p class="category-card-desc">${t('home.find_property_desc', 'Browse properties and find local agents.')}</p>
            <span class="category-card-cta">${t('home.explore_cta', 'Explore')} ${iconArrowRight()}</span>
          </a>
          <a href="#developers" class="category-card" onclick="navigate('developers'); return false;">
            <div class="category-card-icon">
              <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M2 20h20"/><path d="M5 20V8l7-5 7 5v12"/><path d="M9 20v-4h6v4"/><path d="M9 12h.01"/><path d="M15 12h.01"/></svg>
            </div>
            <h3 class="category-card-title">${t('home.find_developers', 'Find Developers & Investments')}</h3>
            <p class="category-card-desc">${t('home.find_developers_desc', 'Discover development opportunities and partners.')}</p>
            <span class="category-card-cta">${t('home.explore_cta', 'Explore')} ${iconArrowRight()}</span>
          </a>
          <a href="#directory?group=builders_trades" class="category-card" onclick="navigate('directory?group=builders_trades'); return false;">
            <div class="category-card-icon">
              <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M2 18.5A2.5 2.5 0 0 1 4.5 16H20"/><path d="M2 7h16a2 2 0 0 1 2 2v9.5A2.5 2.5 0 0 1 17.5 21H4.5A2.5 2.5 0 0 1 2 18.5z"/><path d="M6 12h4"/><path d="M6 16h8"/><circle cx="18" cy="4" r="3"/></svg>
            </div>
            <h3 class="category-card-title">${t('home.find_builders', 'Find Builders & Trades')}</h3>
            <p class="category-card-desc">${t('home.find_builders_desc', 'Connect with skilled builders and trades.')}</p>
            <span class="category-card-cta">${t('home.explore_cta', 'Explore')} ${iconArrowRight()}</span>
          </a>
          <a href="#directory?group=professional_services" class="category-card" onclick="navigate('directory?group=professional_services'); return false;">
            <div class="category-card-icon">
              <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M12 2L2 7l10 5 10-5-10-5z"/><path d="M2 17l10 5 10-5"/><path d="M2 12l10 5 10-5"/></svg>
            </div>
            <h3 class="category-card-title">${t('home.find_professionals', 'Find Professional Services')}</h3>
            <p class="category-card-desc">${t('home.find_professionals_desc', 'Locate architects, lawyers, and consultants.')}</p>
            <span class="category-card-cta">${t('home.explore_cta', 'Explore')} ${iconArrowRight()}</span>
          </a>
          <a href="#directory?group=suppliers_materials" class="category-card" onclick="navigate('directory?group=suppliers_materials'); return false;">
            <div class="category-card-icon">
              <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg>
            </div>
            <h3 class="category-card-title">${t('home.find_materials', 'Find Materials & Suppliers')}</h3>
            <p class="category-card-desc">${t('home.find_materials_desc', 'Source quality materials and local suppliers.')}</p>
            <span class="category-card-cta">${t('home.explore_cta', 'Explore')} ${iconArrowRight()}</span>
          </a>
        </div>
      </div>
    </section>

    <!-- RAB CALCULATOR — two-card standalone section -->
    <section class="rab-tools-section">
      <div class="container">
        <div class="rab-tools-header">
          <h2 class="rab-tools-heading">${t('home.build_cost_tools', 'Build Cost Tools')}</h2>
          <p class="rab-tools-subline">${t('home.build_cost_tools_desc', 'Estimate your project cost before you build')}</p>
        </div>
        <div class="rab-tools-grid">
          <a href="#rab-calculator" class="rab-tool-card" onclick="navigate('rab-calculator');return false;">
            <div class="rab-tool-card-icon" aria-hidden="true">
              <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><rect x="4" y="2" width="16" height="20" rx="2"/><path d="M8 6h8M8 10h8M8 14h4"/></svg>
            </div>
            <h3 class="rab-tool-card-title">${t('home.quick_calc_title', 'Quick Calculator')}</h3>
            <p class="rab-tool-card-desc">${t('home.quick_calc_desc', 'Get a fast estimate of your build cost in minutes.')}</p>
            <span class="rab-tool-btn rab-tool-btn--outline">${t('home.quick_calc_cta', 'Start Calculating')}</span>
          </a>
          <a href="#rab-projects" class="rab-tool-card rab-tool-card--pro" onclick="navigate('rab-projects');return false;">
            <span class="rab-tool-pro-badge">${t('home.pro_badge', 'Pro')}</span>
            <div class="rab-tool-card-icon" aria-hidden="true">
              <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
            </div>
            <h3 class="rab-tool-card-title">${t('home.rab_gen_title', 'Detailed RAB Generator')}</h3>
            <p class="rab-tool-card-desc">${t('home.rab_gen_desc', 'Generate a full Rencana Anggaran Biaya breakdown for your project.')}</p>
            <span class="rab-tool-btn rab-tool-btn--filled">${t('home.rab_gen_cta', 'Unlock Feature')}</span>
          </a>
        </div>
      </div>
    </section>

    <!-- SECTION 1: FEATURED DEVELOPERS & PROJECTS -->
    <section class="section-animate editorial-projects-section">
      <div class="container">
        <div class="editorial-projects-header">
          <div class="editorial-header-left">
            <span class="editorial-eyebrow">Featured</span>
            <h2 class="editorial-section-title">Developers &amp; Projects</h2>
          </div>
          <a href="#developers" class="editorial-view-all-link editorial-view-all-link--desktop" onclick="navigate('developers'); return false;">
            View all developers &amp; projects
            <svg class="editorial-arrow-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
          </a>
        </div>
        <div class="editorial-projects-grid">
          ${([...displayFeaturedDevs.slice(0, 2), ...featuredProjects.slice(0, 2)]).slice(0, 2).map((item) => {
            const isProject = !!item.developer_id;
            const name = isProject ? item.project_name : (item.name || item.display_name || 'Unnamed');
            const photo = item.profile_photo_url || item.hero_image_url || '';
            const link = isProject ? '#project/' + (item.slug || item.id) : '#developer/' + (item.slug || item.id);
            const route = isProject ? 'project/' + (item.slug || item.id) : 'developer/' + (item.slug || item.id);
            const location = isProject
              ? formatAreaLabel(item.location_area || '')
              : ((item.areas_focus && item.areas_focus.length) ? formatAreaLabel(item.areas_focus[0]) : formatAreaLabel(item.location_area || ''));
            const type = isProject
              ? formatProjectType(item.project_type || '')
              : ((item.project_types && item.project_types.length) ? formatProjectType(item.project_types[0]) : '');
            const metaParts = [location, type].filter(Boolean);
            const meta = metaParts.join('  •  ').toUpperCase();
            const metaHtml = meta ? '<p class="editorial-project-meta">' + meta + '</p>' : '';
            return `
              <a href="${link}" class="editorial-project-card" onclick="navigate('${route}'); return false;">
                <div class="editorial-project-img-wrap">
                  <div class="editorial-project-img" style="background-image:url('${photo}');"></div>
                </div>
                <div class="editorial-project-info">
                  <h3 class="editorial-project-title">${escHtml(name)}</h3>
                  ${metaHtml}
                </div>
              </a>
            `;
          }).join('')}
        </div>
        <div class="editorial-projects-mobile-cta">
          <a href="#developers" class="editorial-view-all-link" onclick="navigate('developers'); return false;">
            View all developers &amp; projects
            <svg class="editorial-arrow-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
          </a>
        </div>
      </div>
    </section>

    <!-- SECTION 2: GUIDES & RESOURCES -->
    <section class="section-animate editorial-guides-section">
      <div class="container container--narrow">
        <div class="editorial-guides-header">
          <span class="editorial-eyebrow">Resources</span>
          <h2 class="editorial-section-title">Guides &amp; Resources</h2>
        </div>
        <ul class="editorial-guides-list">
          ${homeGuides.slice(0, 5).map((g, i) => {
            const idx = String(i + 1).padStart(2, '0');
            const readTime = g.read_time ? g.read_time + ' MIN READ' : (g.category ? g.category.toUpperCase() : '');
            return `
              <li class="editorial-guide-item">
                <a href="#guide/${g.slug}" class="editorial-guide-row" onclick="navigate('guide/${g.slug}'); return false;">
                  <span class="editorial-guide-index">${idx}</span>
                  <span class="editorial-guide-title">${escHtml(g.title)}</span>
                  <span class="editorial-guide-meta">${escHtml(readTime)}</span>
                  <svg class="editorial-guide-arrow" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
                </a>
              </li>
            `;
          }).join('')}
        </ul>
        <div class="editorial-guides-footer">
          <a href="#guides" class="editorial-view-all-link" onclick="navigate('guides'); return false;">
            All guides
            <svg class="editorial-arrow-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
          </a>
        </div>
      </div>
    </section>

    <!-- HELP CTA — Dark interstitial -->
    <section class="section section-animate">
      <div class="container container--narrow">
        <div class="help-cta">
          <div class="help-cta-icon" style="background:rgba(255,255,255,0.08);color:rgba(212,209,202,0.8);">
            ${iconWhatsApp()}
          </div>
          <h2 class="help-cta-title" style="color:#faf8f4;">${t('home.help_title', 'Need Help With Your Project?')}</h2>
          <p class="help-cta-desc" style="color:rgba(212,209,202,0.6);">${t('home.help_subtitle', "Not sure where to start? Drop your details via WhatsApp and we'll point you in the right direction.")}</p>
          <a href="https://wa.me/628123456789" target="_blank" rel="noopener noreferrer" class="btn btn--whatsapp">
            ${iconWhatsApp()} ${t('footer.whatsapp_help', 'Get in Touch on WhatsApp')}
          </a>
        </div>
      </div>
    </section>
  `;

  // Animate cards
  requestAnimationFrame(() => animateCards(el));

  // Wire the hero X-Ray materialization reveal (GSAP ScrollTrigger).
  requestAnimationFrame(() => initHeroReveal(el));

  // The hero search trigger is wired by CommandPalette.bindTriggers()
  // which runs on init() and after every hashchange.
}

// =====================================================
// HERO X-RAY MATERIALIZATION REVEAL
// =====================================================
// Layers hero-wire.webp (a pixel-registered wireframe of the same frame) over
// the finished hero-main.webp, then scrubs a clip-path wipe top->bottom while the
// hero is pinned for ~1 viewport. Above the moving edge the finished villa has
// "materialized"; below it the wireframe remains. A thin gold scan line rides
// the edge. Pixel-flawless because both layers are the same 2752px camera frame.
//
// Graceful degradation: if GSAP/ScrollTrigger failed to load, or the user
// prefers reduced motion, we skip the effect entirely — the default CSS clips
// the wireframe away, leaving the finished photo visible.

var HeroReveal = {
  st: null,
  tl: null,

  destroy: function () {
    if (this.tl) { this.tl.kill(); this.tl = null; }
    if (this.st) { this.st.kill(true); this.st = null; }
  },

  init: function (root) {
    // Always start clean so re-navigating to home never stacks triggers on
    // dead DOM (the SPA replaces #main-content on every route change).
    this.destroy();

    var scope = root || document;
    var hero = scope.querySelector('#hero-reveal');
    var wire = scope.querySelector('#hero-wire');
    var scan = scope.querySelector('#hero-scanline');
    if (!hero || !wire) return;

    var reduce = window.matchMedia &&
      window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    // No GSAP, or reduced motion → leave the finished photo showing.
    if (reduce || typeof window.gsap === 'undefined' ||
        typeof window.ScrollTrigger === 'undefined') {
      return;
    }

    var gsap = window.gsap;
    gsap.registerPlugin(window.ScrollTrigger);

    // Lock both image layers at identity scale so they stay pixel-registered
    // through the wipe (the base photo's Ken Burns zoom would otherwise drift
    // the revealed finish out of alignment with the wireframe seam).
    hero.classList.add('hero-xray');

    // Full-overlay initial state: the wireframe covers the ENTIRE frame (clip
    // fully open), so there's no seam between a "revealed" top band and the
    // wire. The wipe then sweeps the whole image from the very top.
    gsap.set(wire, { clipPath: 'inset(0% 0 0 0)', webkitClipPath: 'inset(0% 0 0 0)' });
    if (scan) gsap.set(scan, { top: '0%', opacity: 0 });

    // Wipe pace differs by device. Mobile feels right at a short window; on
    // desktop that same window is far too fast, so the reveal is stretched over
    // more scroll. Tune the two values independently.
    var isDesktop = !window.matchMedia ||
      window.matchMedia('(min-width: 768px)').matches;
    var endDistance = isDesktop ? '+=55%' : '+=20%';

    var tl = gsap.timeline({
      // duration:1 makes every primary tween span the full scrubbed timeline,
      // so the wipe completes exactly at the end of the scrub window.
      defaults: { ease: 'none', duration: 1 },
      scrollTrigger: {
        trigger: hero,
        start: 'top top',
        // No pin: the page scrolls naturally while the wipe scrubs against it,
        // so the hero scrolls up the screen as the wireframe dissolves away.
        end: endDistance,
        // scrub:true tracks scroll position 1:1 (no time-based catch-up) so the
        // wipe always reaches both ends exactly and fully reverses on scroll-up.
        scrub: true,
        invalidateOnRefresh: true,
        onLeave: function (self) {
          // Wipe fully completed (scrolled past the end) — lock the finished
          // photo and never replay the wireframe on scroll-up. A PARTIAL wipe
          // still reverses normally; only a completed one sticks.
          self.disable(false);
          gsap.set(wire, { clipPath: 'inset(100% 0 0 0)', webkitClipPath: 'inset(100% 0 0 0)' });
          if (scan) gsap.set(scan, { opacity: 0 });
        }
      }
    });

    // Wipe the wireframe away top->bottom, revealing the finished villa.
    tl.fromTo(wire,
      { clipPath: 'inset(0% 0 0 0)', webkitClipPath: 'inset(0% 0 0 0)' },
      { clipPath: 'inset(100% 0 0 0)', webkitClipPath: 'inset(100% 0 0 0)' }, 0);

    // Scan line rides the clip edge; fades in at the start, out at the finish.
    if (scan) {
      tl.fromTo(scan, { top: '0%' }, { top: '100%' }, 0);
      tl.to(scan, { opacity: 1, duration: 0.06 }, 0);
      tl.to(scan, { opacity: 0, duration: 0.08 }, 0.92);
    }

    // Drift the scroll hint away as the reveal begins.
    var hint = scope.querySelector('.hero-scroll-hint');
    if (hint) tl.to(hint, { opacity: 0, duration: 0.15 }, 0);

    this.tl = tl;
    this.st = tl.scrollTrigger;
  }
};

function initHeroReveal(root) {
  try { HeroReveal.init(root); } catch (e) { /* never block render */ }
}

// =====================================================
// UI HELPERS — verified badge, relative timestamp, skeletons
// =====================================================

function renderVerifiedBadge(label) {
  return '<span class="verified-badge" title="Verified by Build in Lombok">'
    + '<svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" aria-hidden="true"><polyline points="20 6 9 17 4 12"/></svg>'
    + (label || 'Verified')
    + '</span>';
}

function renderRelativeTimestamp(input) {
  if (!input) return '';
  let d;
  try { d = (input instanceof Date) ? input : new Date(input); } catch(_) { return ''; }
  if (!d || isNaN(d.getTime())) return '';
  const diffSec = Math.floor((Date.now() - d.getTime()) / 1000);
  let label;
  if (diffSec < 60)            label = 'just now';
  else if (diffSec < 3600)     label = Math.floor(diffSec / 60) + 'm ago';
  else if (diffSec < 86400)    label = Math.floor(diffSec / 3600) + 'h ago';
  else if (diffSec < 86400*7)  label = Math.floor(diffSec / 86400) + ' day' + (diffSec >= 86400*2 ? 's' : '') + ' ago';
  else if (diffSec < 86400*30) label = Math.floor(diffSec / (86400*7)) + ' week' + (diffSec >= 86400*14 ? 's' : '') + ' ago';
  else if (diffSec < 86400*365)label = Math.floor(diffSec / (86400*30)) + ' month' + (diffSec >= 86400*60 ? 's' : '') + ' ago';
  else                         label = Math.floor(diffSec / (86400*365)) + ' year' + (diffSec >= 86400*730 ? 's' : '') + ' ago';
  const iso = d.toISOString();
  return '<span class="ts-relative" title="' + iso + '">'
    + '<svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>'
    + 'Updated ' + label
    + '</span>';
}

function renderCardSkeletonGrid(count) {
  const n = count || 6;
  let html = '<div class="skeleton-grid">';
  for (let i = 0; i < n; i++) {
    html += '<div class="skeleton-card">'
      + '<div class="skeleton-row">'
      + '  <div class="skeleton skeleton-avatar"></div>'
      + '  <div style="flex:1;display:flex;flex-direction:column;gap:8px;">'
      + '    <div class="skeleton skeleton-line skeleton-line--title"></div>'
      + '    <div class="skeleton skeleton-line skeleton-line--meta"></div>'
      + '  </div>'
      + '</div>'
      + '<div class="skeleton skeleton-line skeleton-line--mid"></div>'
      + '<div class="skeleton skeleton-line skeleton-line--short"></div>'
      + '<div class="skeleton-row" style="margin-top:8px;">'
      + '  <div class="skeleton skeleton-line" style="width:32%;"></div>'
      + '  <div class="skeleton skeleton-line" style="width:24%;margin-left:auto;"></div>'
      + '</div>'
      + '</div>';
  }
  html += '</div>';
  return html;
}

// =====================================================
// RENDER: PROVIDER CARD
// =====================================================

function renderProviderCard(b, index = 0) {
  // Use whatsapp_number if available, otherwise fall back to phone
  const rawWa  = b.whatsapp_number || b.phone || '';
  const waNum  = formatWhatsAppNumber(rawWa);                            // normalised: +62xxxxxxxxxx
  const waHref = waNum ? 'https://wa.me/' + waNum.replace(/[^0-9]/g, '') : '';
  // Display format: +62 8xxxxxxxxx  (readable, still international)
  const waDisp = waNum.startsWith('+62') ? '+62\u00a0' + waNum.slice(3) : waNum;
  const isLoggedIn = !!UserAuth.user;
  const waBtn  = waHref
    ? `<a href="${waHref}" target="_blank" rel="noopener noreferrer" class="card-wa-btn" aria-label="WhatsApp ${b.name}"${isLoggedIn ? ` onclick="QuoteTracker.onWaClick(${b.id})"` : ''}>${iconWhatsApp()}<span class="card-wa-num">${waDisp}</span></a>`
    : '';
  // "Check Status" button — logged-in users only, only when WA number exists
  const checkBtn = (waHref && isLoggedIn)
    ? `<button class="card-check-btn" onclick="QuoteTracker.checkStatus(${b.id}, '${waHref}')" title="Check WhatsApp for a reply">${iconClock()} Check status</button>`
    : '';

  const badge = b.badge
    ? `<span class="card-badge">${renderBadge(b.badge)}</span>`
    : '';

  const trustedBadge = b.is_trusted ? renderVerifiedBadge('Verified') : '';
  const updatedTs = (b.updated_at || b.last_updated_at) ? renderRelativeTimestamp(b.updated_at || b.last_updated_at) : '';

  var langParts = (b.languages || '').split(/[,+]+/).map(function(s){ return s.trim(); }).filter(Boolean);
  var langShort = langParts.length === 0 ? 'Bahasa' : langParts.join(' · ');
  const ratingInline = b.google_rating
    ? `<span class="card-rating-inline"><span class="card-rating-star">★</span> ${b.google_rating.toFixed(1)} <span class="card-rating-count">(${b.google_review_count})</span></span>`
    : '';

  const thumbImg = b.logo_url || b.profile_photo_url;
  const hasPhoto = !!thumbImg;
  const categoryLabel = (b.categories && b.categories.length > 0) ? b.categories.map(c => formatCategoryLabel(c.key || c)).join(' · ') : formatCategoryLabel(b.category);

  const _provCard = `
    <article class="card card-animate" style="animation-delay: ${index * 50}ms" data-id="${b.id}">
      <div class="card-visual-header">
        ${hasPhoto ? `<img src="${thumbImg}" alt="${b.name}" class="card-avatar${b.logo_url ? ' card-avatar--logo' : ''}" loading="lazy" onerror="this.style.display='none'">` : `<div class="card-avatar card-avatar--placeholder"><span>${(b.name || 'B').charAt(0).toUpperCase()}</span></div>`}
        <div class="card-header-info">
          <span class="card-category-label">${categoryLabel}</span>
          <div class="card-header-badges">${trustedBadge}${badge}</div>
        </div>
        ${ratingInline}
      </div>
      <h3 class="card-name"><a href="#provider/${b.slug}" onclick="navigate('provider/${b.slug}');return false;">${b.name}</a></h3>
      <p class="card-desc">${b.short_description_en}</p>
      <div class="card-meta-line">
        <span class="card-meta-item">${iconMapPin()} ${formatAreaLabel(b.area)}</span>
        <span class="card-meta-sep"></span>
        <span class="card-meta-item">${langShort}</span>
        ${updatedTs ? '<span class="card-meta-sep"></span><span class="card-meta-item">' + updatedTs + '</span>' : ''}
      </div>
      <div class="card-tags-line">
        ${b.tags.slice(0, 3).map(t => `<span class="card-tag">${t}</span>`).join('<span class="card-tag-dot">·</span>')}
      </div>
      <div class="card-footer">
        <button class="card-view-btn" onclick="navigate('provider/${b.slug}')">
          View details ${iconArrowRight()}
        </button>
        <div class="card-footer-right">${renderFavBtn('provider', b.id)}${checkBtn}${waBtn}</div>
      </div>
    </article>
  `;
  if (!isAdmin()) return _provCard;
  return `<div class="admin-card-wrap" data-entity-id="${b.id}"><button class="admin-edit-card-btn" onclick="event.stopPropagation();adminProviderQuickEdit(${b.id},'${b.slug}')"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg> Edit</button>${_provCard}</div>`;
}

// =====================================================
// RENDER: DIRECTORY PAGE
// =====================================================

async function renderDirectory(el, params = {}) {
  await FilterData.load();
  const filters = {
    area: params.area || '',
    group: params.group || '',
    category: params.category || '',
    languages: params.languages || '',
    min_rating: params.min_rating || '',
    trusted: params.trusted || '',
    sort: params.sort || 'confidence',
    search: params.search || ''
  };

  async function applyFiltersAndRender() {
    const grid = el.querySelector('#provider-grid');
    const countEl = el.querySelector('#results-count');
    if (!grid) return;

    // Show skeleton placeholders while fetching
    grid.innerHTML = renderCardSkeletonGrid(6);
    if (countEl) countEl.innerHTML = '<span class="skeleton skeleton-line" style="display:inline-block;width:140px;height:14px;"></span>';

    // Build API params
    const apiParams = {};
    if (filters.area) {
      if (filters.area.startsWith('region:')) {
        apiParams.region = filters.area.replace('region:', '');
      } else {
        apiParams.area = filters.area;
      }
    }
    if (filters.group) apiParams.group = filters.group;
    if (filters.category) apiParams.category = filters.category;
    if (filters.trusted) apiParams.trusted = '1';
    if (filters.search) apiParams.q = filters.search;
    if (filters.sort === 'rating') { apiParams.sort = 'google_rating'; apiParams.dir = 'DESC'; }
    else if (filters.sort === 'alpha') { apiParams.sort = 'name'; }
    else { apiParams.sort = 'google_rating'; apiParams.dir = 'DESC'; }
    apiParams.per_page = 100;

    try {
      const res = await DataLayer.getProviders(apiParams);
      let results = res.data;

      // Client-side filters the API doesn't handle
      if (filters.languages === 'english') {
        results = results.filter(b => (b.languages || '').toLowerCase().includes('english'));
      }
      if (filters.languages === 'bahasa') {
        results = results.filter(b => (b.languages || '').toLowerCase().includes('bahasa'));
      }
      if (filters.min_rating) {
        const minR = parseFloat(filters.min_rating);
        results = results.filter(b => b.google_rating && b.google_rating >= minR);
      }
      // Re-sort by confidence if default
      if (!filters.sort || filters.sort === 'confidence') {
        results.sort((a, b_) => {
          if (b_.is_trusted && !a.is_trusted) return 1;
          if (a.is_trusted && !b_.is_trusted) return -1;
          if (b_.is_featured && !a.is_featured) return 1;
          if (a.is_featured && !b_.is_featured) return -1;
          return confidenceScore(b_.google_rating, b_.google_review_count) - confidenceScore(a.google_rating, a.google_review_count);
        });
      } else if (filters.sort === 'review_count') {
        results.sort((a, b_) => (b_.google_review_count || 0) - (a.google_review_count || 0));
      }

      if (countEl) countEl.innerHTML = `<strong>${results.length}</strong> provider${results.length !== 1 ? 's' : ''} found`;

      if (results.length === 0) {
        grid.innerHTML = `
          <div class="empty-state" style="grid-column: 1/-1;">
            <div class="empty-state-icon">${iconSearch()}</div>
            <h3 class="empty-state-title">${t('empty.no_providers_title', 'No providers found')}</h3>
            <p class="empty-state-desc">${t('empty.no_providers_desc', 'Try adjusting your filters or search terms.')}</p>
            <button class="btn btn--secondary btn--sm" onclick="clearDirectoryFilters()">${t('filter.clear_all', 'Clear all filters')}</button>
          </div>
        `;
      } else {
        grid.innerHTML = results.map((b, i) => renderProviderCard(b, i)).join('');
      }
    } catch(e) {
      console.error('Failed to load providers:', e);
      grid.innerHTML = '<div class="empty-state" style="grid-column:1/-1;"><p>Unable to load providers. Please try again.</p></div>';
    }
    requestAnimationFrame(() => animateCards(el));

    // Update URL
    navigate(buildHash('directory', Object.fromEntries(Object.entries(filters).filter(([,v]) => v !== '' && v !== 'confidence'))));
  }

  window.clearDirectoryFilters = function() {
    filters.area = ''; filters.group = ''; filters.category = '';
    filters.languages = ''; filters.min_rating = ''; filters.search = ''; filters.trusted = '';
    el.querySelectorAll('.filter-select').forEach(s => s.value = '');
    applyFiltersAndRender();
  };

  const activeCount = Object.entries(filters).filter(([k, v]) => k !== 'sort' && v !== '').length;

  const groupHeaders = {
    'builders_trades': {
      title: 'Builders & Tradespeople',
      desc: 'Skilled craftsmen bringing your architectural vision to life across Lombok.',
      icon: '🏗️'
    },
    'professional_services': {
      title: 'Professional Services',
      desc: 'Architects, engineers, and consultants to guide every phase of your project.',
      icon: '📐'
    },
    'specialist_contractors': {
      title: 'Specialist Contractors',
      desc: 'Pool builders, solar installers, landscaping experts — the finishing touches.',
      icon: '✨'
    },
    'suppliers_materials': {
      title: 'Materials & Suppliers',
      desc: 'Quality building materials and trusted suppliers across Lombok.',
      icon: '📦'
    },
    '': {
      title: 'The Directory',
      desc: 'Every contractor, specialist, and supplier you need to build in Lombok.',
      icon: ''
    }
  };
  const headerData = groupHeaders[filters.group] || groupHeaders[''];

  el.innerHTML = `
    <div class="dir-hero" data-group="${filters.group}">
      <div class="container">
        <h1 class="dir-hero-title">${headerData.title}</h1>
        <p class="dir-hero-desc">${headerData.desc}</p>
      </div>
    </div>
    <div class="section">
      <div class="container">
        <!-- Filters -->
        <div class="dir-filters">
          <div class="dir-primary-filters">
            <div class="dir-filter-pill">
              <label class="dir-filter-pill-label">${t('filter.where', 'Where in Lombok?')}</label>
              <select id="f-area" class="dir-filter-pill-select" onchange="updateDirectoryFilter('area', this.value)">
                <option value="">All Areas</option>
                ${buildAreaOptions(filters.area)}
              </select>
            </div>
            <div class="dir-filter-pill">
              <label class="dir-filter-pill-label">${t('filter.specialty', 'What specialty?')}</label>
              <select id="f-category" class="dir-filter-pill-select" onchange="updateDirectoryFilter('category', this.value)">
                <option value="">All Specialties</option>
                ${filters.group
                  ? buildFilterOptions(FilterData.categories, filters.category, 'group_key', filters.group)
                  : buildFilterOptions(FilterData.categories, filters.category)
                }
              </select>
            </div>
          </div>
          <div class="dir-secondary-filters">
            <button class="dir-more-btn" onclick="this.nextElementSibling.classList.toggle('open');this.classList.toggle('open')">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>
              More Filters
            </button>
            <div class="dir-more-body">
              <div class="dir-more-grid">
                <!-- hidden group filter to maintain functionality -->
                <select id="f-group" class="filter-select" style="display:none" onchange="updateGroupFilter(this.value)">
                  <option value="">All groups</option>
                  ${buildFilterOptions(FilterData.groups, filters.group)}
                </select>
                <div class="filter-group">
                  <label class="filter-label">${t('filter.language', 'Language')}</label>
                  <select id="f-lang" class="filter-select" onchange="updateDirectoryFilter('languages', this.value)">
                    <option value="">Any language</option>
                    <option value="english" ${filters.languages === 'english' ? 'selected' : ''}>English</option>
                    <option value="bahasa" ${filters.languages === 'bahasa' ? 'selected' : ''}>Bahasa</option>
                  </select>
                </div>
                <div class="filter-group">
                  <label class="filter-label">${t('filter.min_rating', 'Min Rating')}</label>
                  <select id="f-rating" class="filter-select" onchange="updateDirectoryFilter('min_rating', this.value)">
                    <option value="">Any</option>
                    <option value="4.0" ${filters.min_rating === '4.0' ? 'selected' : ''}>4.0+</option>
                    <option value="4.5" ${filters.min_rating === '4.5' ? 'selected' : ''}>4.5+</option>
                  </select>
                </div>
                <div class="filter-group">
                  <label class="filter-label">${t('filter.status', 'Status')}</label>
                  <select id="f-trusted" class="filter-select" onchange="updateDirectoryFilter('trusted', this.value)">
                    <option value="">All</option>
                    <option value="1" ${filters.trusted === '1' ? 'selected' : ''}>Trusted only</option>
                  </select>
                </div>
                <div class="filter-group">
                  <label class="filter-label">${t('filter.sort', 'Sort')}</label>
                  <select class="filter-select" onchange="updateDirectoryFilter('sort', this.value)">
                    <option value="confidence" ${filters.sort === 'confidence' ? 'selected' : ''}>Most Trusted</option>
                    <option value="rating" ${filters.sort === 'rating' ? 'selected' : ''}>Highest Rated</option>
                    <option value="review_count" ${filters.sort === 'review_count' ? 'selected' : ''}>Most Reviewed</option>
                    <option value="alpha" ${filters.sort === 'alpha' ? 'selected' : ''}>A–Z</option>
                  </select>
                </div>
              </div>
              ${activeCount > 0 ? `<div style="margin-top:var(--space-3);text-align:right;"><button class="btn btn--ghost btn--sm" onclick="clearDirectoryFilters()">${t('filter.clear_all', 'Clear all filters')}</button></div>` : ''}
            </div>
          </div>
        </div>

        <p class="results-count" id="results-count"></p>
        <div class="card-grid" id="provider-grid"></div>
      </div>
    </div>
  `;

  window.updateDirectoryFilter = function(key, value) {
    filters[key] = value;
    applyFiltersAndRender();
  };

  window.updateGroupFilter = function(value) {
    filters.group = value;
    filters.category = ''; // reset category when group changes
    // Re-render the full page to update the cascading category dropdown
    renderDirectory(el, filters);
  };

  applyFiltersAndRender();
}

// =====================================================
// RENDER: PROVIDER DETAIL
// =====================================================

async function renderProviderDetail(el, slug) {
  let b;
  try {
    b = await DataLayer.getProvider(slug);
  } catch(e) {
    console.error('Failed to load provider:', e);
  }
  if (!b) {
    el.innerHTML = renderNotFound('Provider');
    return;
  }

  const isStore = b.group === 'suppliers_materials';
  const groupLabel = formatGroupLabel(b.group);
  const specialties = (b.categories && b.categories.length > 0) ? b.categories.map(c => formatCategoryLabel(c.key || c)).join(', ') : formatCategoryLabel(b.category);

  const waNum = formatWhatsAppNumber(b.phone);
  const heroImg = b.hero_image_url || '';
  el.innerHTML = `
    ${isAdmin() ? `<div class="admin-detail-bar"><span class="admin-detail-bar-label">Admin</span><button class="btn btn--primary btn--sm" onclick="adminProviderDetailEdit(${b.id},'${slug}')"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="margin-right:4px"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>Edit this listing</button></div>` : ''}
    <div class="detail-hero">
      ${heroImg ? '<div class="detail-hero-bg" style="background-image:url(\'' + heroImg + '\');"></div>' : ''}
      <div class="container">
        <div class="detail-hero-inner">
          ${b.logo_url ? '<img src="'+b.logo_url+'" alt="'+b.name+'" class="detail-hero-logo" onerror="this.style.display=\'none\'">' : (b.profile_photo_url ? '<img src="'+b.profile_photo_url+'" alt="'+b.name+'" class="detail-hero-photo" onerror="this.style.display=\'none\'">' : '')}
          <div class="detail-hero-info">
            <div class="detail-hero-badges">
              <span class="badge badge--light">${groupLabel}</span>
              ${b.is_trusted ? '<span class="badge badge--trusted-light">\u2713 Trusted</span>' : ''}
              ${b.badge ? '<span class="badge badge--light">' + renderBadge(b.badge) + '</span>' : ''}
            </div>
            <h1 class="detail-hero-name">${b.name}</h1>
          </div>
        </div>
      </div>
    </div>
    <div class="section">
      <div class="container">
        <div class="detail-subheading">
          <p class="detail-subheading-specialty">${specialties}</p>
          <div class="detail-subheading-meta">
            <span>${iconMapPin()} ${formatAreaLabel(b.area)}</span>
            <span>${iconLang()} ${(b.languages || 'Bahasa').split(/[,+]+/).map(function(s){return s.trim();}).filter(Boolean).join(' \u00b7 ')}</span>
          </div>
        </div>
        <div class="detail-layout">
          <div class="detail-main">
            <div class="detail-rating-row">
              ${renderGoogleRating(b.google_rating, b.google_review_count, 'detail')}
              ${b.google_maps_url ? '<a href="'+b.google_maps_url+'" target="_blank" rel="noopener noreferrer" class="btn btn--ghost btn--sm" style="margin-left:var(--space-3);">'+iconMapPin()+' Google Maps</a>' : ''}
            </div>

            <h2 class="detail-section-title">About</h2>
            <p class="detail-description">${b.description_en}</p>

            <h2 class="detail-section-title">Specialties</h2>
            <div class="detail-tags mb-6">
              ${b.tags.map(t => '<span class="tag">'+t+'</span>').join('')}
            </div>

            <h2 class="detail-section-title">Contact</h2>
            <div class="info-list mb-6">
              ${b.address ? '<div class="info-row"><span class="info-icon">'+iconMapPin()+'</span><span class="info-label">Address</span><span class="info-value">'+b.address+'</span></div>' : ''}
              ${b.phone ? '<div class="info-row"><span class="info-icon">'+iconPhone()+'</span><span class="info-label">Phone</span><span class="info-value"><a href="tel:'+b.phone+'">'+b.phone+'</a></span></div>' : ''}
              ${b.phone ? '<div class="info-row"><span class="info-icon" style="color:#25D366;">'+iconWhatsApp()+'</span><span class="info-label">WhatsApp</span><span class="info-value"><a href="https://wa.me/'+waNum.replace('+','')+'" target="_blank" rel="noopener noreferrer">'+waNum+'</a></span></div>' : ''}
              ${b.website_url ? '<div class="info-row"><span class="info-icon">'+iconGlobe()+'</span><span class="info-label">Website</span><span class="info-value"><a href="'+b.website_url+'" target="_blank" rel="noopener noreferrer">Visit website '+iconExternalLink()+'</a></span></div>' : ''}
            </div>
            ${renderSocialLinks(b)}
          </div>

          <div class="detail-sidebar">
            <div class="detail-card detail-card--actions">
              ${b.whatsapp_number ? '<a href="https://wa.me/'+b.whatsapp_number+'" target="_blank" rel="noopener noreferrer" class="btn btn--whatsapp btn--full btn--lg">'+iconWhatsApp()+' WhatsApp</a>' : ''}
              ${b.phone ? '<a href="tel:'+b.phone+'" class="btn btn--ghost btn--full" style="margin-top:var(--space-2)">'+iconPhone()+' Call Now</a>' : ''}
              ${b.website_url ? '<a href="'+b.website_url+'" target="_blank" rel="noopener noreferrer" class="btn btn--primary btn--full" style="margin-top:var(--space-2)">'+iconGlobe()+' Visit Website</a>' : ''}
              ${isStore && b.tokopedia_url ? '<a href="'+b.tokopedia_url+'" target="_blank" rel="noopener noreferrer" class="btn btn--secondary btn--full" style="margin-top:var(--space-2)"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 0 1-8 0"/></svg> Shop on Tokopedia</a>' : ''}
              ${b.google_maps_url ? '<a href="'+b.google_maps_url+'" target="_blank" rel="noopener noreferrer" class="btn btn--ghost btn--full" style="margin-top:var(--space-2)">'+iconMapPin()+' View on Map</a>' : ''}
              <div style="margin-top:var(--space-3);display:flex;align-items:center;justify-content:center;gap:var(--space-2);">${renderFavBtn('provider', b.id)}<span style="font-size:var(--text-xs);color:var(--color-text-muted);">Save to favourites</span></div>
            </div>
            <div class="detail-card">
              <div class="detail-card-title">Quick Info</div>
              <div class="info-list">
                <div class="info-row"><span class="info-label">Type</span><span class="info-value">${groupLabel}</span></div>
                <div class="info-row"><span class="info-label">Speciality</span><span class="info-value">${specialties}</span></div>
                <div class="info-row"><span class="info-label">Area</span><span class="info-value">${formatAreaLabel(b.area)}</span></div>
                <div class="info-row"><span class="info-label">Languages</span><span class="info-value">${(b.languages || 'Bahasa').split(/[,+]+/).map(function(s){return s.trim();}).filter(Boolean).join(', ')}</span></div>
              </div>
            </div>
            <div class="detail-card claim-cta-card">
              <div class="detail-card-title">Is this your business?</div>
              <p style="font-size:var(--text-xs);color:var(--color-text-muted);margin:0 0 var(--space-3) 0;">Claim this listing to update your information and manage your profile.</p>
              <button class="btn btn--primary btn--sm" onclick="UserAuth.user ? showClaimModal(${b.id}, '${b.name.replace(/'/g, "\\'")}'): showAuthModal('login')">Claim this listing</button>
            </div>
          </div>
        </div>
      </div>
    </div>
  `;
}

// =====================================================
// RENDER: DEVELOPERS
// =====================================================

function renderDeveloperCard(dev, index = 0) {
  const badge = dev.badge ? `<span class="card-badge">${renderBadge(dev.badge)}</span>` : '';
  const featuredBadge = dev.is_featured ? '<span class="card-badge card-badge--featured">★ Featured</span>' : '';
  const ratingInline = dev.google_rating
    ? `<span class="card-rating-inline"><span class="card-rating-star">★</span> ${dev.google_rating.toFixed(1)} <span class="card-rating-count">(${dev.google_review_count})</span></span>`
    : '';
  const areas = dev.areas_focus.map(a => formatAreaLabel(a)).join(', ');
  const thumbImg = dev.logo_url || dev.profile_photo_url;
  const hasPhoto = !!thumbImg;
  const categoryLabel = (dev.categories && dev.categories.length > 0) ? dev.categories.map(c => formatCategoryLabel(c.key || c)).join(' · ') : 'Developer';
  const _devCard = `
    <article class="card card-animate" style="animation-delay:${index * 50}ms">
      <div class="card-visual-header">
        ${hasPhoto ? `<img src="${thumbImg}" alt="${dev.name}" class="card-avatar${dev.logo_url ? ' card-avatar--logo' : ''}" loading="lazy" onerror="this.style.display='none'">` : `<div class="card-avatar card-avatar--placeholder"><span>${(dev.name || 'D').charAt(0).toUpperCase()}</span></div>`}
        <div class="card-header-info">
          <span class="card-category-label">${categoryLabel}</span>
          <div class="card-header-badges">${featuredBadge}${badge}</div>
        </div>
        ${ratingInline}
      </div>
      <h3 class="card-name"><a href="#developer/${dev.slug}" onclick="navigate('developer/${dev.slug}');return false;">${dev.name}</a></h3>
      <p class="card-desc">${dev.short_description_en}</p>
      <div class="card-meta-line">
        <span class="card-meta-item">${iconMapPin()} ${areas}</span>
      </div>
      <div class="card-tags-line">
        ${dev.project_types.map(t => `<span class="card-tag">${formatProjectType(t)}</span>`).join('<span class="card-tag-dot">·</span>')}
        ${dev.min_ticket_usd ? `<span class="card-tag-dot">·</span><span class="card-tag">From ${formatUSD(dev.min_ticket_usd)}</span>` : ''}
      </div>
      <div class="card-footer">
        <button class="card-view-btn" onclick="navigate('developer/${dev.slug}')">
          View developer ${iconArrowRight()}
        </button>
        <div class="card-footer-right">${renderFavBtn('developer', dev.id)}${dev.whatsapp_number ? `<a href="https://wa.me/${dev.whatsapp_number}" target="_blank" rel="noopener noreferrer" class="card-wa-btn" aria-label="WhatsApp ${dev.name}">${iconWhatsApp()}</a>` : ''}</div>
      </div>
    </article>
  `;
  if (!isAdmin()) return _devCard;
  return `<div class="admin-card-wrap" data-entity-id="${dev.id}"><button class="admin-edit-card-btn" onclick="event.stopPropagation();adminDeveloperQuickEdit(${dev.id},'${dev.slug}')"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg> Edit</button>${_devCard}</div>`;
}

async function renderDevelopers(el, params = {}) {
  await FilterData.load();
  let devs = [];
  try {
    const apiParams = { per_page: 100 };
    if (params.area) apiParams.area = params.area;
    if (params.region) apiParams.region = params.region;
    if (params.q) apiParams.q = params.q;
    if (params.featured) apiParams.featured = '1';
    const res = await DataLayer.getDevelopers(apiParams);
    devs = res.data;
  } catch(e) { console.error('Failed to load developers:', e); }

  const areaOptions = buildAreaOptions(params.area || '');
  const isFeaturedFilter = params.featured === '1';

  el.innerHTML = `
    <div class="dir-hero">
      <div class="container">
        <h1 class="dir-hero-title">${isFeaturedFilter ? 'Featured ' : ''}Property Developers</h1>
        <p class="dir-hero-desc">Active developers building villas, apartments, and land projects across Lombok.</p>
      </div>
    </div>
    <div class="section">
      <div class="container">
        <div class="filters-bar">
          <button class="filters-toggle-btn" onclick="this.closest('.filters-bar').querySelector('.filters-body').classList.toggle('open')">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="4" y1="6" x2="20" y2="6"/><line x1="8" y1="12" x2="16" y2="12"/><line x1="11" y1="18" x2="13" y2="18"/></svg>
            Filters
          </button>
          <div class="filters-body${isFeaturedFilter ? ' open' : ''}">
            <div class="filters-grid">
              <div class="filter-group">
                <label class="filter-label">Area</label>
                <select id="fil-dev-area" class="filter-select" aria-label="Area">
                  <option value="">All areas</option>
                  ${areaOptions}
                </select>
              </div>
              <div class="filter-group">
                <label class="filter-label">Status</label>
                <select id="fil-dev-featured" class="filter-select" aria-label="Status">
                  <option value="">All developers</option>
                  <option value="1" ${isFeaturedFilter ? 'selected' : ''}>Featured only</option>
                </select>
              </div>
              <div class="filter-group">
                <label class="filter-label">Search</label>
                <input type="text" id="fil-dev-q" class="filter-select" placeholder="Search name..." value="${params.q || ''}" style="background-image:none;padding-right:var(--space-3);">
              </div>
            </div>
          </div>
        </div>
        <p class="results-count"><strong>${devs.length}</strong> developer${devs.length !== 1 ? 's' : ''} listed</p>
        <div class="card-grid">
          ${devs.map((d, i) => renderDeveloperCard(d, i)).join('')}
        </div>
      </div>
    </div>
  `;

  // Filter event listeners
  const filArea = el.querySelector('#fil-dev-area');
  const filFeatured = el.querySelector('#fil-dev-featured');
  const filQ = el.querySelector('#fil-dev-q');
  function applyDevFilters() {
    const p = {};
    if (filArea.value) {
      if (filArea.value.startsWith('region:')) p.region = filArea.value.replace('region:', '');
      else p.area = filArea.value;
    }
    if (filFeatured.value) p.featured = filFeatured.value;
    if (filQ.value) p.q = filQ.value;
    navigate('developers', p);
  }
  filArea.addEventListener('change', applyDevFilters);
  filFeatured.addEventListener('change', applyDevFilters);
  let debounce; filQ.addEventListener('input', () => { clearTimeout(debounce); debounce = setTimeout(applyDevFilters, 400); });

  requestAnimationFrame(() => animateCards(el));
}

async function renderDeveloperDetail(el, slug) {
  let dev;
  try {
    dev = await DataLayer.getDeveloper(slug);
  } catch(e) { console.error('Failed to load developer:', e); }
  if (!dev) { el.innerHTML = renderNotFound('Developer'); return; }

  const devProjects = dev.projects || [];
  const devSpecialties = (dev.categories && dev.categories.length > 0)
    ? dev.categories.map(c => formatCategoryLabel(c.key || c)).join(', ')
    : 'Property Developer';
  const devHeroImg = dev.hero_image_url || '';

  // Native inline Google rating \u2014 no boxed widget
  const googleRating = dev.google_rating ? parseFloat(dev.google_rating) : null;
  const reviewCount  = dev.google_review_count ? parseInt(dev.google_review_count) : 0;
  const ratingHtml   = googleRating
    ? '<div class="dev-rating-row">'
        + '<span class="dev-rating-score">' + googleRating.toFixed(1) + '</span>'
        + renderStars(googleRating)
        + '<span class="dev-rating-label">(' + reviewCount.toLocaleString() + ' Google Reviews)</span>'
      + '</div>'
    : '';

  // Focus tags: areas + custom tags combined
  const focusTags = [
    ...dev.areas_focus.map(a => formatAreaLabel(a)),
    ...dev.tags
  ].filter(Boolean);

  // Portfolio: editorial empty state if no projects
  const portfolioHtml = devProjects.length > 0
    ? '<div class="card-grid card-grid--2col">' + devProjects.map((p, i) => renderProjectCard(p, i)).join('') + '</div>'
    : '<div class="dev-portfolio-empty">'
        + '<p class="dev-portfolio-empty-desc">Inquire directly to receive private off-market availability, upcoming phase releases, and masterplan documentation for ' + escHtml(dev.name) + '.</p>'
        + (dev.whatsapp_number ? '<a href="https://wa.me/' + dev.whatsapp_number + '" target="_blank" rel="noopener noreferrer" class="dev-portfolio-cta-link">Request Masterplan Presentation</a>' : '')
      + '</div>';

  // Monochrome social links (no brand colours)
  const socialHtml = (dev.instagram_url || dev.facebook_url || dev.linkedin_url)
    ? '<div class="dev-social-row">'
        + (dev.instagram_url ? '<a href="' + dev.instagram_url + '" target="_blank" rel="noopener noreferrer" class="dev-social-link" aria-label="Instagram">' + iconInstagram() + '</a>' : '')
        + (dev.facebook_url  ? '<a href="' + dev.facebook_url  + '" target="_blank" rel="noopener noreferrer" class="dev-social-link" aria-label="Facebook">'  + iconFacebook()  + '</a>' : '')
        + (dev.linkedin_url  ? '<a href="' + dev.linkedin_url  + '" target="_blank" rel="noopener noreferrer" class="dev-social-link" aria-label="LinkedIn">'  + iconLinkedIn()  + '</a>' : '')
      + '</div>'
    : '';

  el.innerHTML = `
    ${isAdmin() ? `<div class="admin-detail-bar"><span class="admin-detail-bar-label">Admin</span><button class="btn btn--primary btn--sm" onclick="adminDeveloperDetailEdit(${dev.id},'${slug}')"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="margin-right:4px"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>Edit this listing</button></div>` : ''}

    <!-- CINEMATIC HERO BANNER -->
    <div class="dev-hero">
      <div class="dev-hero-bg" ${devHeroImg ? 'style="background-image:url(\'' + devHeroImg + '\')"' : ''}></div>
      <div class="dev-hero-gradient"></div>
      <div class="container dev-hero-container">
        <div class="dev-hero-content">
          <div class="dev-hero-badges">
            ${dev.is_featured ? '<span class="dev-hero-badge">\u2605 Featured</span>' : ''}
            ${dev.badge ? '<span class="dev-hero-badge">' + renderBadge(dev.badge) + '</span>' : ''}
            ${dev.project_types.map(t => '<span class="dev-hero-badge">' + formatProjectType(t) + '</span>').join('')}
          </div>
          <h1 class="dev-hero-name">${escHtml(dev.name)}</h1>
          <p class="dev-hero-specialty">${escHtml(devSpecialties)}</p>
          <div class="dev-hero-meta">
            ${dev.areas_focus.map(a => '<span>' + formatAreaLabel(a) + '</span>').join('<span class="dev-hero-meta-dot">&nbsp;&middot;&nbsp;</span>')}
            <span>${(dev.languages || 'Bahasa').split(/[,+]+/).map(function(s){return s.trim();}).filter(Boolean).join(' \u00b7 ')}</span>
          </div>
        </div>
      </div>
    </div>

    <!-- EDITORIAL BODY: 65 / 35 GRID -->
    <div class="dev-body">
      <div class="container">
        <div class="dev-layout">

          <!-- LEFT COLUMN: Core content -->
          <div class="dev-main">
            ${ratingHtml}
            <div class="dev-about">
              <h2 class="dev-section-title">About</h2>
              <div class="dev-description">${dev.description_en || ''}</div>
            </div>
            <div class="dev-focus-section">
              <h2 class="dev-section-title">Focus Areas</h2>
              <div class="dev-focus-tags">
                ${focusTags.map(t => '<span class="dev-focus-tag">' + escHtml(t) + '</span>').join('')}
              </div>
            </div>
          </div>

          <!-- RIGHT COLUMN: Sticky contact card -->
          <div class="dev-sidebar">
            <div class="dev-contact-card">
              <p class="dev-contact-card-title">Contact</p>
              <div class="dev-contact-list">
                ${dev.phone      ? '<div class="dev-contact-row"><svg class="dev-contact-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M22 16.92v3a2 2 0 01-2.18 2 19.79 19.79 0 01-8.63-3.07A19.5 19.5 0 013.47 8a19.79 19.79 0 01-3.07-8.67A2 2 0 012.38 0h3a2 2 0 012 1.72c.127.96.361 1.903.7 2.81a2 2 0 01-.45 2.11L6.91 7.91a16 16 0 006.29 6.29l1.28-1.27a2 2 0 012.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0122 16.92z"/></svg><a href="tel:' + dev.phone + '" class="dev-contact-link">' + escHtml(dev.phone) + '</a></div>' : ''}
                ${dev.website_url   ? '<div class="dev-contact-row"><svg class="dev-contact-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 014 10 15.3 15.3 0 01-4 10 15.3 15.3 0 01-4-10 15.3 15.3 0 014-10z"/></svg><a href="' + dev.website_url + '" target="_blank" rel="noopener noreferrer" class="dev-contact-link">Website</a></div>' : ''}
                ${dev.google_maps_url ? '<div class="dev-contact-row"><svg class="dev-contact-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0118 0z"/><circle cx="12" cy="10" r="3"/></svg><a href="' + dev.google_maps_url + '" target="_blank" rel="noopener noreferrer" class="dev-contact-link">View on Map</a></div>' : ''}
              </div>
              ${dev.min_ticket_usd ? '<div class="dev-min-invest"><span class="dev-min-invest-label">From</span><span class="dev-min-invest-value">' + formatUSD(dev.min_ticket_usd) + '</span></div>' : ''}
              ${dev.whatsapp_number ? '<a href="https://wa.me/' + dev.whatsapp_number + '" target="_blank" rel="noopener noreferrer" class="dev-wa-btn">' + iconWhatsApp() + '<span>Inquire via WhatsApp</span></a>' : ''}
              ${socialHtml}
              <div class="dev-fav-row">${renderFavBtn('developer', dev.id)}<span class="dev-fav-label">Save to favourites</span></div>
            </div>
          </div>

          <!-- PORTFOLIO ROW: spans left column below main content -->
          <div class="dev-portfolio-section">
            <h2 class="dev-section-title">Current Portfolio &amp; Masterplan</h2>
            ${portfolioHtml}
          </div>

        </div>
      </div>
    </div>
  `;
  requestAnimationFrame(() => animateCards(el));
}

// =====================================================
// RENDER: LISTING CARD
// =====================================================

function renderListingCard(l, index) {
  if (typeof index === 'undefined') index = 0;
  var imgUrl = l.image && l.image.url ? l.image.url : '';
  var priceStr = Currency.priceHtml(l);
  var sizeStr = formatLandSize(l.land_size_sqm, l.land_size_are);
  var typeLabel = l.listing_type_label || l.listing_type_key || '';
  var certLabel = l.certificate_type_label || '';
  var areaLabel = l.area_label || '';
  // Prefer the specific Place name ("Mertak", "Teluk Awang") for the meta line;
  // the broad Area still shows in the category tag and the map finds it (Place
  // tier, docs/adr/0010).
  var locationDetail = l.place_label || l.location_detail || '';

  // Editorial category tag: "TYPE • AREA" in card body (replaces image overlay badge)
  var categoryParts = [];
  if (typeLabel) categoryParts.push(typeLabel.toUpperCase());
  if (areaLabel) categoryParts.push(areaLabel.toUpperCase());

  // Single clean meta line: size • cert • specific location • beds • baths
  var metaParts = [];
  if (sizeStr) metaParts.push(sizeStr);
  if (certLabel) metaParts.push(certLabel);
  if (locationDetail) metaParts.push(locationDetail);
  if (l.bedrooms) metaParts.push(l.bedrooms + ' bed');
  if (l.bathrooms) metaParts.push(l.bathrooms + ' bath');

  // If listing has source_url, open external site in new tab; otherwise navigate internally
  var linkHref = l.source_url ? l.source_url : '#listing/' + l.slug;
  var linkTarget = l.source_url ? ' target="_blank" rel="noopener noreferrer"' : '';
  var linkOnclick = l.source_url ? '' : ' onclick="navigate(\'listing/' + l.slug + '\');return false;"';
  var sourceTag = l.source_site ? '<span class="listing-card-source">' + l.source_site + ' <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg></span>' : '';

  var adminEditBtn = isAdmin()
    ? '<button class="admin-edit-card-btn" onclick="event.preventDefault();event.stopPropagation();adminListingQuickEdit(' + l.id + ',\'' + (l.slug || '') + '\')" title="Edit listing"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg> Edit</button>'
    : '';

  return '<div class="listing-card-wrap" data-listing-id="' + (l.id || '') + '" style="animation-delay:' + (index * 60) + 'ms">'
    + adminEditBtn
    + '<a href="' + linkHref + '" class="listing-card card"' + linkTarget + linkOnclick + '>'
    + '<div class="listing-card-image">'
    + (imgUrl ? '<img src="' + imgUrl + '" alt="' + (l.title || '').replace(/"/g, '&quot;') + '" loading="lazy" onload="this.classList.add(\'loaded\')">' : '<div class="listing-card-noimg"><svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="m21 15-5-5L5 21"/></svg></div>')
    + (l.is_featured ? '<span class="listing-card-featured">Featured</span>' : '')
    + '</div>'
    + '<div class="listing-card-body">'
    + (categoryParts.length ? '<div class="listing-card-category">' + categoryParts.join(' • ') + '</div>' : '')
    + '<div class="listing-card-price">' + priceStr + '</div>'
    + '<h3 class="listing-card-title">' + (l.title || '') + '</h3>'
    + (metaParts.length ? '<div class="listing-card-meta-line">' + metaParts.join('  •  ') + '</div>' : '')
    + sourceTag
    + '</div>'
    + '</a>'
    + '</div>';
}

// =====================================================
// RENDER: LISTINGS (Find Land / Property)
// =====================================================

// =====================================================
// INTERACTIVE LOMBOK MAP — hand-drawn SVG market regions
// (docs/adr/0005). Regions are MARKET regions, not kabupaten;
// geometry is an owned design asset, deliberately stylised.
// =====================================================

const LOMBOK_MAP = {
  viewBox: [0, 0, 880, 640],
  terrain: 'images/lombok-terrain.webp',
  outline: 'M88.9,454.4 L91.5,458.1 L90.8,462.9 L92.0,468.9 L95.3,473.6 L95.5,475.7 L97.5,475.3 L98.2,477.0 L96.2,479.5 L95.6,483.9 L94.1,484.7 L94.9,486.2 L92.7,488.3 L95.9,488.5 L94.7,489.3 L94.7,491.9 L93.3,492.9 L97.0,491.5 L98.4,492.5 L98.4,494.5 L96.9,495.9 L99.3,497.6 L100.1,499.7 L97.4,502.0 L98.1,502.1 L96.8,504.3 L101.6,502.5 L104.9,503.2 L106.7,501.5 L110.2,502.5 L110.6,505.3 L107.4,508.2 L107.5,511.1 L108.6,512.5 L113.4,510.4 L116.3,510.4 L117.1,511.7 L121.3,509.5 L122.1,510.8 L123.8,507.2 L126.9,506.6 L128.7,507.7 L130.4,512.1 L133.4,512.4 L135.7,515.9 L136.9,515.4 L137.9,513.2 L140.1,512.5 L145.1,517.9 L147.8,516.2 L152.6,516.4 L155.6,520.7 L155.5,522.8 L159.3,523.7 L159.5,524.8 L161.7,524.7 L160.9,528.1 L162.8,527.2 L165.3,528.4 L167.0,526.4 L167.1,522.7 L169.8,519.8 L177.0,518.7 L179.1,521.2 L186.5,525.1 L188.2,527.3 L188.9,529.1 L187.5,532.0 L188.2,532.4 L187.1,533.1 L189.6,534.3 L190.9,533.8 L191.1,535.7 L192.5,535.8 L192.1,537.0 L194.7,536.9 L198.0,540.3 L200.7,540.7 L201.4,542.0 L199.6,543.8 L200.3,545.0 L199.0,545.3 L199.1,546.5 L199.7,546.0 L200.8,547.5 L202.8,546.5 L203.1,548.0 L206.2,546.6 L207.9,547.5 L207.9,549.8 L206.5,551.0 L207.6,551.3 L207.4,554.9 L209.4,553.5 L214.2,553.4 L214.6,557.1 L216.1,557.5 L217.3,556.0 L218.8,557.0 L218.7,558.2 L220.4,558.3 L220.1,559.6 L221.2,560.4 L220.1,562.2 L221.1,563.7 L221.9,564.1 L224.2,561.9 L226.7,561.9 L228.0,563.8 L228.1,566.0 L226.4,569.1 L229.0,568.8 L230.4,570.3 L231.4,569.7 L232.8,574.1 L234.7,573.7 L236.2,574.9 L242.8,570.9 L244.0,573.0 L247.7,574.1 L246.6,571.4 L242.2,570.1 L241.7,567.8 L244.1,563.9 L248.5,564.9 L248.3,563.4 L250.6,561.7 L251.3,558.9 L246.2,552.8 L245.0,553.0 L243.1,551.2 L237.2,550.9 L237.1,546.2 L239.6,542.2 L244.4,539.5 L246.4,540.6 L248.8,538.6 L252.5,537.6 L256.2,538.4 L256.3,541.9 L258.2,543.6 L259.6,541.9 L263.5,541.0 L265.3,536.5 L266.8,535.1 L271.2,534.7 L271.3,533.3 L274.2,533.2 L277.7,533.8 L280.1,536.5 L280.1,533.7 L281.9,533.0 L285.5,535.6 L285.8,537.2 L288.5,535.9 L290.6,536.7 L291.8,540.6 L290.4,540.3 L289.9,541.2 L290.3,543.9 L284.1,544.7 L276.6,543.5 L277.1,544.1 L276.3,544.2 L275.3,543.6 L276.5,543.4 L275.8,542.5 L271.0,543.8 L269.3,546.0 L268.2,545.5 L264.4,547.8 L265.0,550.9 L266.6,552.6 L266.1,555.1 L268.1,560.4 L270.2,560.4 L274.2,558.1 L285.9,559.7 L287.7,566.2 L290.6,568.0 L289.9,559.9 L291.8,558.3 L291.2,557.3 L292.2,553.9 L295.1,550.5 L312.3,547.1 L313.5,547.4 L313.8,549.8 L315.1,550.1 L316.8,549.2 L319.1,545.9 L320.9,545.4 L327.8,545.8 L333.4,547.8 L338.7,547.7 L344.9,544.8 L346.3,542.6 L350.8,543.0 L350.5,544.0 L351.1,543.5 L353.2,545.1 L356.3,550.5 L355.5,552.5 L353.1,553.5 L352.9,555.6 L355.8,560.5 L355.3,562.3 L354.3,562.4 L354.2,564.6 L352.6,564.8 L352.3,567.0 L354.8,567.8 L355.0,569.4 L355.4,568.5 L356.9,568.6 L356.5,571.2 L357.4,570.5 L358.6,572.0 L357.8,575.3 L356.7,575.3 L357.1,576.5 L355.9,576.8 L356.5,578.0 L357.8,578.3 L358.5,576.5 L359.3,577.1 L360.6,575.8 L362.1,577.9 L361.2,579.6 L364.2,579.9 L365.3,582.8 L371.6,580.6 L374.5,583.2 L376.1,582.3 L376.3,580.8 L377.1,581.8 L378.0,581.0 L378.5,582.3 L379.2,580.7 L379.4,581.8 L379.8,581.3 L382.0,582.4 L381.5,583.5 L382.5,585.6 L382.6,583.9 L385.9,581.0 L384.7,579.7 L384.9,576.1 L386.3,573.8 L391.7,572.5 L397.3,572.8 L399.0,573.6 L399.5,576.3 L401.3,578.5 L403.0,579.0 L403.8,578.1 L404.3,579.1 L405.8,579.1 L408.1,577.5 L408.1,576.0 L406.6,574.4 L409.1,571.9 L413.0,574.9 L412.2,576.8 L413.4,579.5 L413.0,583.7 L415.2,584.9 L416.9,584.5 L417.1,585.5 L419.3,584.7 L421.6,581.5 L420.0,578.2 L420.2,576.4 L425.1,573.8 L427.3,574.0 L430.0,576.6 L433.1,577.5 L434.1,576.5 L435.2,578.1 L436.8,577.3 L437.0,578.5 L439.6,578.9 L440.1,580.0 L442.7,579.2 L446.2,582.1 L446.2,581.1 L447.4,580.9 L445.8,579.7 L445.0,576.5 L444.8,574.2 L446.2,571.3 L445.0,568.5 L446.4,566.9 L448.9,566.5 L457.5,569.2 L458.5,570.2 L457.8,571.3 L460.7,573.1 L461.0,574.9 L462.2,574.8 L463.6,576.8 L463.7,575.2 L464.9,574.3 L464.5,572.5 L463.2,572.3 L462.9,571.1 L464.0,570.0 L466.6,573.1 L465.0,576.6 L462.6,576.8 L462.7,579.2 L465.0,580.2 L466.1,577.8 L467.7,577.4 L476.6,578.6 L480.6,586.2 L480.3,588.0 L483.8,585.3 L481.4,584.3 L480.4,581.1 L483.4,578.3 L486.7,578.5 L487.4,580.6 L488.5,579.9 L489.9,581.4 L489.7,584.3 L488.1,584.9 L488.0,586.4 L489.2,587.5 L491.1,584.0 L494.2,585.0 L498.5,589.4 L497.2,592.6 L501.6,596.1 L505.1,593.0 L503.9,590.5 L502.5,590.5 L501.9,588.5 L503.5,584.4 L501.7,583.8 L500.3,580.1 L495.1,574.8 L495.5,573.4 L497.7,571.3 L501.6,569.7 L502.2,570.9 L505.8,572.1 L505.2,575.8 L507.3,576.0 L508.2,574.5 L510.7,575.5 L512.0,579.9 L512.2,579.1 L514.5,579.7 L518.0,578.5 L520.9,575.5 L519.3,573.6 L520.4,574.5 L522.2,572.6 L522.5,573.6 L521.2,574.1 L521.3,574.8 L526.2,575.2 L528.4,577.7 L528.8,579.6 L524.4,585.1 L521.8,586.9 L520.3,589.7 L517.3,591.6 L517.7,595.9 L519.5,598.4 L518.9,601.6 L522.4,607.9 L518.6,612.1 L518.3,614.3 L519.2,614.6 L519.4,612.8 L521.7,611.8 L523.3,612.3 L524.5,615.8 L527.7,616.2 L528.9,613.8 L529.9,614.4 L530.3,611.3 L533.0,611.7 L533.8,609.7 L536.1,609.4 L538.6,613.5 L538.4,610.4 L539.6,609.5 L539.9,610.4 L540.9,609.2 L538.9,607.5 L540.6,606.5 L540.2,604.2 L541.8,604.5 L541.2,602.9 L540.3,603.2 L540.7,600.4 L541.8,600.8 L540.4,599.6 L538.4,592.5 L538.7,590.3 L539.6,586.6 L541.7,583.5 L542.7,576.5 L547.4,571.5 L545.1,567.0 L543.2,566.7 L542.0,564.5 L542.6,560.9 L539.8,560.1 L542.6,559.2 L542.2,557.9 L539.7,557.3 L539.8,551.9 L538.2,550.1 L535.6,549.3 L532.3,544.7 L531.6,544.9 L533.4,544.1 L534.2,541.5 L533.3,539.9 L534.2,541.0 L536.1,538.3 L539.1,536.6 L544.5,536.9 L546.1,540.5 L544.6,543.6 L546.9,544.2 L546.1,541.4 L550.3,533.1 L551.1,526.4 L553.9,525.1 L554.2,522.5 L556.2,522.4 L556.8,521.0 L555.2,519.5 L556.5,515.7 L559.3,516.2 L561.3,514.2 L562.7,514.8 L564.6,511.8 L568.5,518.3 L571.0,518.7 L571.3,522.5 L572.8,524.8 L574.3,523.9 L575.5,525.7 L576.6,523.2 L573.9,522.2 L573.9,520.7 L574.3,520.0 L576.2,520.6 L569.1,511.0 L571.0,510.9 L571.8,512.5 L573.2,512.9 L575.5,517.1 L575.1,515.0 L577.4,515.0 L574.6,510.9 L579.4,515.2 L581.3,513.1 L581.5,508.5 L582.1,511.4 L581.0,516.1 L585.3,517.8 L590.8,514.6 L591.4,509.6 L593.5,509.6 L592.7,507.0 L595.3,505.7 L595.8,509.9 L597.8,509.2 L601.9,513.4 L603.2,513.3 L601.6,516.8 L597.7,516.8 L597.3,517.9 L597.7,520.1 L601.0,520.0 L601.6,523.6 L598.4,529.4 L596.9,530.1 L595.1,529.3 L594.5,531.6 L591.9,531.6 L590.0,532.9 L591.1,534.2 L595.4,535.2 L595.4,536.0 L591.3,536.7 L590.9,537.7 L588.9,537.3 L591.2,542.6 L589.3,549.5 L590.6,550.5 L590.7,552.5 L589.1,550.4 L587.6,550.6 L588.8,549.3 L586.6,549.5 L583.8,561.7 L582.8,562.4 L581.4,571.6 L579.3,573.3 L576.4,573.7 L575.9,576.6 L573.2,578.3 L572.5,581.1 L568.0,582.3 L568.0,584.7 L570.2,585.4 L571.6,587.4 L575.1,586.8 L584.0,589.0 L587.9,588.6 L588.1,587.3 L590.7,587.0 L594.8,588.4 L600.6,588.7 L602.6,586.8 L611.4,583.0 L618.9,581.2 L621.5,578.5 L622.3,573.7 L627.1,567.4 L628.0,565.3 L627.5,562.5 L628.7,560.3 L627.3,561.9 L626.1,561.4 L625.2,562.2 L625.5,566.0 L621.7,569.4 L622.3,570.2 L621.2,571.5 L619.3,569.6 L617.2,569.2 L617.4,569.9 L616.5,570.1 L616.7,567.8 L614.6,564.1 L615.5,563.4 L615.3,560.4 L614.4,559.5 L617.5,562.3 L618.8,566.1 L619.5,564.8 L620.5,565.0 L620.4,568.0 L622.3,568.3 L624.5,565.4 L622.8,562.1 L621.0,561.6 L621.7,560.6 L620.6,559.4 L621.3,558.6 L620.4,556.2 L620.9,555.7 L623.4,557.8 L625.0,561.3 L626.0,560.7 L625.2,559.2 L626.3,558.5 L625.9,556.7 L627.5,557.8 L628.1,557.1 L629.5,561.0 L630.2,558.2 L627.9,554.6 L629.2,553.8 L630.6,555.2 L629.2,548.9 L631.0,546.8 L632.3,550.1 L631.4,552.5 L632.3,555.7 L635.0,556.1 L637.6,557.9 L638.4,561.7 L640.4,561.1 L640.1,562.7 L636.5,565.4 L638.1,567.3 L636.0,567.3 L634.7,569.6 L635.8,569.6 L636.5,571.3 L638.5,570.6 L638.4,571.7 L639.3,572.0 L641.8,570.7 L644.2,570.9 L637.6,575.1 L639.0,578.4 L636.2,577.3 L632.1,581.3 L629.4,580.9 L628.6,582.1 L629.8,583.8 L632.3,584.2 L638.4,579.3 L642.3,578.8 L644.5,577.0 L644.4,575.9 L646.3,576.6 L647.5,575.3 L649.9,576.2 L650.4,574.3 L651.7,573.7 L654.0,574.5 L655.0,570.8 L657.4,571.9 L658.8,570.3 L660.4,570.3 L660.5,572.0 L662.3,569.5 L665.4,570.7 L665.0,569.7 L667.0,567.6 L669.0,567.6 L670.0,568.7 L674.7,565.1 L677.7,565.3 L677.0,562.9 L677.8,561.5 L677.8,556.8 L678.7,556.5 L677.4,556.5 L677.9,554.0 L679.5,554.3 L680.0,553.0 L681.1,553.0 L681.0,549.7 L682.4,549.5 L683.8,547.8 L684.9,547.9 L685.1,549.0 L689.1,547.9 L689.4,548.6 L688.4,544.2 L690.2,542.6 L692.9,542.5 L694.2,544.5 L696.4,541.2 L694.7,539.0 L691.3,537.3 L689.4,539.6 L687.5,538.0 L685.6,537.9 L686.2,539.0 L684.4,539.7 L681.9,538.4 L682.1,540.3 L676.9,542.0 L676.5,544.3 L678.1,545.1 L674.4,546.4 L675.1,544.4 L673.5,543.9 L673.9,542.6 L672.5,542.8 L671.6,544.5 L670.1,543.2 L672.7,540.6 L672.0,538.4 L673.8,534.7 L672.3,534.1 L672.0,535.4 L669.5,536.7 L670.4,538.2 L668.2,538.1 L667.6,539.3 L666.5,537.4 L665.8,538.1 L662.4,536.7 L662.4,539.9 L660.3,536.9 L658.8,537.3 L657.9,538.9 L657.5,536.3 L655.1,538.3 L656.0,539.8 L655.2,540.3 L660.4,542.9 L660.6,543.9 L659.5,544.8 L657.1,543.4 L656.3,544.5 L657.0,546.3 L655.9,547.2 L654.6,546.5 L655.2,545.2 L653.1,544.8 L655.2,543.4 L655.3,542.3 L653.4,541.0 L652.6,541.4 L652.2,539.4 L650.7,541.7 L649.3,541.3 L648.7,539.6 L650.4,539.7 L651.4,537.0 L650.6,535.7 L653.3,536.5 L652.3,534.5 L655.3,534.7 L655.1,533.5 L651.0,531.9 L649.2,533.3 L648.6,532.4 L649.6,531.3 L647.3,531.7 L647.0,530.1 L649.8,529.5 L653.2,531.0 L653.9,529.8 L653.1,529.0 L654.5,528.1 L656.3,530.1 L657.6,529.3 L658.3,527.5 L655.2,524.9 L654.0,526.7 L651.5,524.8 L653.5,523.3 L654.3,521.2 L653.8,519.1 L654.7,518.4 L650.9,517.1 L649.7,514.8 L645.0,516.0 L641.3,512.6 L639.7,512.7 L640.1,516.1 L642.9,515.8 L643.4,517.0 L645.3,517.1 L646.3,518.4 L644.8,519.2 L642.1,517.8 L637.7,517.3 L638.9,518.7 L640.1,518.4 L642.0,520.2 L643.6,520.3 L643.7,521.4 L646.1,521.4 L647.4,520.3 L648.1,522.8 L644.4,523.0 L646.8,524.4 L644.7,525.4 L642.3,523.4 L642.3,522.0 L641.5,522.6 L639.1,521.6 L640.3,523.0 L640.1,524.4 L637.7,522.6 L635.6,523.1 L636.0,521.1 L637.3,520.5 L636.5,519.6 L635.7,520.3 L633.5,517.6 L633.3,518.8 L634.7,519.7 L634.8,520.9 L631.7,520.7 L632.6,522.5 L633.4,521.8 L634.1,523.0 L633.5,525.0 L634.6,524.5 L635.1,525.7 L636.0,524.9 L637.5,527.6 L635.2,527.9 L634.0,526.7 L634.0,527.7 L633.0,527.8 L630.1,524.6 L625.8,525.1 L624.5,528.3 L625.5,528.5 L625.7,530.8 L626.7,531.3 L626.0,532.3 L630.1,533.7 L629.9,538.0 L630.9,538.8 L629.6,537.5 L628.0,538.1 L626.5,537.2 L627.3,538.2 L626.8,539.3 L625.0,539.1 L620.4,536.3 L620.0,534.9 L617.7,534.4 L616.4,530.7 L619.8,526.1 L619.3,525.3 L620.6,525.8 L617.5,522.0 L619.9,519.2 L618.7,517.7 L619.0,516.3 L620.6,517.0 L621.8,516.1 L619.5,513.4 L622.0,511.6 L621.3,510.3 L622.7,509.3 L621.1,508.1 L622.4,507.8 L621.7,506.9 L622.4,503.6 L621.4,502.9 L622.9,501.1 L620.0,501.5 L618.3,503.9 L614.8,503.4 L610.9,504.7 L610.1,503.9 L608.9,507.5 L610.4,509.0 L610.1,509.6 L609.7,508.3 L608.0,509.5 L609.5,508.1 L607.9,507.2 L609.2,506.8 L609.7,504.9 L606.5,500.5 L606.8,499.3 L605.3,499.4 L602.0,497.2 L603.9,497.4 L605.7,499.3 L610.4,496.4 L614.3,498.5 L615.6,497.9 L616.4,500.2 L618.0,500.6 L620.9,498.7 L622.8,496.9 L622.7,493.9 L623.6,492.8 L624.4,493.4 L623.7,492.4 L625.7,491.1 L626.6,488.2 L626.0,485.4 L623.1,483.4 L626.2,484.9 L626.6,486.8 L627.1,486.2 L626.7,483.5 L629.0,478.2 L626.6,480.3 L629.5,474.5 L634.1,470.9 L633.4,471.9 L634.5,471.2 L636.3,472.0 L633.3,472.7 L629.6,475.9 L630.0,476.6 L633.2,473.7 L635.7,475.2 L637.4,474.8 L641.5,469.0 L651.2,462.0 L651.3,461.3 L646.9,460.0 L651.9,461.2 L653.2,459.0 L658.2,454.7 L658.0,453.6 L663.6,444.7 L664.1,441.1 L662.6,439.1 L663.8,433.6 L670.3,423.9 L677.6,415.9 L678.9,412.7 L680.4,413.6 L681.7,411.5 L680.2,410.5 L681.4,407.8 L680.4,409.2 L680.9,407.6 L679.7,407.4 L681.8,406.8 L681.6,407.5 L684.0,404.7 L685.1,397.6 L689.4,388.0 L699.1,379.9 L703.2,373.0 L710.8,364.6 L710.8,362.8 L716.7,355.2 L716.6,351.3 L719.7,343.9 L718.9,337.6 L719.9,335.3 L744.4,312.8 L753.0,301.0 L753.1,297.9 L750.8,296.6 L748.1,292.9 L745.3,284.6 L746.7,277.4 L749.4,273.7 L752.3,264.9 L761.8,257.2 L762.3,251.2 L760.0,244.6 L761.8,238.6 L760.7,238.4 L760.4,241.2 L758.6,243.2 L754.5,240.6 L755.1,242.6 L760.5,249.2 L760.4,252.8 L757.2,253.8 L756.6,256.7 L755.2,256.0 L756.0,255.4 L752.3,253.7 L754.2,252.1 L751.0,252.0 L749.4,250.6 L748.4,248.0 L750.0,247.5 L748.3,247.1 L748.3,246.3 L749.7,245.4 L749.0,244.0 L749.9,242.8 L754.2,239.6 L756.7,235.5 L761.3,232.7 L767.7,226.4 L770.3,222.7 L774.5,219.3 L776.2,216.2 L779.0,213.6 L782.6,212.6 L788.1,208.5 L786.5,197.0 L790.3,193.4 L787.7,187.9 L787.6,185.4 L796.9,171.8 L798.6,161.0 L797.9,157.2 L799.0,151.0 L798.5,148.2 L791.8,138.2 L793.5,133.1 L787.6,128.4 L786.0,129.2 L785.5,128.4 L786.4,128.0 L781.8,124.7 L770.6,121.5 L770.6,119.5 L772.8,121.7 L775.4,122.5 L773.1,121.7 L771.3,118.2 L758.9,104.7 L758.2,102.5 L752.6,99.3 L751.0,94.6 L747.8,92.0 L747.1,90.0 L741.2,86.2 L717.5,79.6 L707.3,81.5 L700.5,80.2 L695.4,77.8 L692.2,74.6 L684.5,70.9 L665.9,65.5 L661.7,65.6 L656.9,62.1 L647.5,58.7 L641.1,57.7 L630.3,59.8 L625.7,59.3 L618.4,53.9 L617.8,52.1 L614.2,48.7 L610.1,47.9 L606.9,44.0 L603.0,44.1 L593.4,39.5 L590.6,37.1 L576.4,38.1 L567.4,32.4 L562.2,33.0 L548.5,28.6 L541.8,29.7 L534.7,29.1 L526.5,27.2 L520.7,23.8 L510.0,26.7 L504.2,24.6 L494.1,27.5 L489.9,30.2 L480.2,33.3 L470.2,39.5 L464.5,40.9 L458.7,47.7 L453.9,46.0 L440.4,51.7 L428.6,58.9 L420.7,66.3 L414.3,69.2 L404.6,78.1 L401.6,84.2 L397.2,86.2 L388.7,97.6 L381.2,100.2 L377.6,105.6 L377.0,109.2 L375.6,111.1 L377.2,113.7 L376.5,115.7 L367.7,128.4 L358.7,131.2 L349.1,129.6 L345.2,133.7 L342.2,138.9 L336.8,141.1 L335.6,144.9 L330.8,146.9 L325.9,142.1 L324.2,145.6 L324.6,149.2 L322.8,150.7 L318.8,148.6 L315.5,144.5 L311.3,143.3 L307.4,143.8 L306.8,144.7 L307.8,148.4 L312.1,157.9 L312.3,161.4 L310.6,167.2 L308.0,169.5 L297.7,174.6 L294.3,176.0 L292.7,175.7 L291.6,178.6 L290.1,179.6 L286.4,179.7 L285.3,177.6 L282.6,176.0 L281.4,177.5 L278.5,177.5 L276.9,179.1 L273.9,177.8 L271.5,181.9 L270.4,188.7 L267.9,190.2 L265.7,189.4 L263.8,191.7 L262.2,191.7 L262.4,192.8 L265.1,193.6 L266.4,195.1 L265.6,200.1 L262.8,202.6 L257.4,202.8 L260.1,204.6 L260.1,206.2 L257.1,208.7 L255.6,208.0 L254.6,211.5 L257.7,219.5 L257.4,226.0 L258.7,229.7 L258.0,231.0 L258.8,238.7 L257.9,240.8 L255.7,241.8 L259.4,247.4 L260.2,251.0 L262.3,250.0 L264.6,251.9 L264.1,256.3 L267.2,254.8 L272.6,259.0 L273.7,262.3 L276.7,265.3 L276.4,267.2 L278.6,269.0 L281.2,275.0 L286.1,305.1 L285.1,318.0 L287.9,343.7 L287.4,354.4 L283.8,372.5 L283.1,380.4 L283.9,380.7 L282.7,381.3 L284.6,380.4 L282.8,381.4 L284.3,381.7 L286.1,387.6 L285.8,390.0 L282.8,395.6 L273.9,432.4 L273.0,443.0 L276.0,442.1 L273.4,442.8 L276.2,437.0 L275.5,434.8 L277.5,430.1 L277.4,432.9 L278.7,431.9 L277.2,427.7 L279.1,421.3 L278.4,419.1 L279.4,420.8 L279.2,423.9 L280.7,425.9 L278.1,426.8 L279.5,431.5 L276.2,434.8 L276.3,435.9 L277.5,435.3 L278.9,437.6 L284.0,435.4 L286.2,436.0 L286.0,437.2 L286.8,437.6 L288.1,436.2 L290.9,436.1 L294.1,434.1 L295.1,435.8 L286.5,443.1 L284.5,441.1 L282.1,444.7 L277.5,445.1 L279.3,447.4 L281.6,446.4 L281.3,447.7 L282.0,447.9 L280.0,451.1 L276.1,453.2 L275.5,455.6 L277.8,458.3 L277.3,463.0 L278.5,464.8 L280.1,465.0 L281.2,467.0 L282.5,467.2 L280.1,467.4 L280.6,470.8 L277.6,471.6 L276.3,470.3 L278.5,465.9 L274.2,466.9 L273.6,468.1 L273.6,466.5 L269.6,462.7 L268.6,460.5 L267.8,460.9 L268.2,465.2 L267.4,466.6 L267.0,459.8 L266.0,461.4 L266.6,463.3 L264.0,465.2 L261.7,465.1 L260.1,462.9 L261.4,460.0 L261.1,457.3 L258.9,455.3 L258.1,452.8 L260.2,451.6 L262.2,452.4 L265.6,449.1 L261.9,443.4 L263.0,441.8 L262.3,439.2 L265.7,436.1 L266.3,434.1 L265.0,431.6 L261.8,433.2 L256.8,432.7 L255.9,431.3 L256.8,429.8 L256.2,428.4 L254.0,433.3 L251.8,435.4 L253.3,441.8 L253.0,444.5 L247.5,446.0 L240.8,453.3 L237.5,454.0 L236.1,453.7 L230.2,445.2 L226.9,443.7 L221.6,437.5 L219.5,436.5 L217.7,437.1 L217.0,440.2 L214.1,441.4 L209.0,439.1 L204.5,439.9 L202.5,436.4 L203.1,441.5 L194.6,448.5 L188.7,449.4 L183.7,455.5 L184.6,458.8 L181.8,464.4 L178.8,466.6 L178.7,468.2 L177.1,467.5 L175.6,468.2 L176.9,469.1 L175.6,471.5 L174.5,471.8 L172.3,471.3 L172.6,470.3 L174.1,470.4 L174.5,467.6 L168.4,467.1 L166.3,467.7 L166.5,470.1 L165.0,472.6 L161.4,472.6 L154.4,469.5 L154.2,466.9 L149.7,462.9 L146.5,461.7 L145.2,463.0 L144.9,459.8 L146.2,460.2 L148.0,458.9 L149.1,457.6 L148.6,455.8 L150.6,453.5 L150.4,450.4 L148.8,449.1 L146.9,450.3 L147.2,453.0 L141.6,461.8 L141.6,463.2 L137.4,464.1 L136.2,462.4 L137.0,457.2 L135.4,454.7 L132.6,453.6 L131.3,451.8 L124.7,449.7 L123.1,446.7 L123.1,443.8 L126.5,436.2 L123.9,431.3 L124.1,429.5 L118.9,433.8 L113.6,429.0 L106.0,432.9 L102.5,436.1 L101.2,445.7 L96.0,448.2 L92.8,452.7 Z',
  regions: {
    north_lombok: { d: 'M262.8,202.6 L265.6,200.1 L266.4,195.1 L265.1,193.6 L262.4,192.8 L262.2,191.7 L263.8,191.7 L265.7,189.4 L267.9,190.2 L270.4,188.7 L271.5,181.9 L273.9,177.8 L276.9,179.1 L278.5,177.5 L281.4,177.5 L282.6,176.0 L285.3,177.6 L286.4,179.7 L290.1,179.6 L291.6,178.6 L292.7,175.7 L294.3,176.0 L297.7,174.6 L308.0,169.5 L310.6,167.2 L312.3,161.4 L312.1,157.9 L307.8,148.4 L306.8,144.7 L307.4,143.8 L311.3,143.3 L315.5,144.5 L318.8,148.6 L322.8,150.7 L324.6,149.2 L324.2,145.6 L325.9,142.1 L330.8,146.9 L335.6,144.9 L336.8,141.1 L342.2,138.9 L345.2,133.7 L349.1,129.6 L358.7,131.2 L367.7,128.4 L376.5,115.7 L377.2,113.7 L375.6,111.1 L377.0,109.2 L377.6,105.6 L381.2,100.2 L388.7,97.6 L397.2,86.2 L401.6,84.2 L404.6,78.1 L414.3,69.2 L420.7,66.3 L428.6,58.9 L440.4,51.7 L453.9,46.0 L458.7,47.7 L464.5,40.9 L470.2,39.5 L480.2,33.3 L489.9,30.2 L494.1,27.5 L504.2,24.6 L510.0,26.7 L520.7,23.8 L526.5,27.2 L534.7,29.1 L541.8,29.7 L548.5,28.6 L562.2,33.0 L567.4,32.4 L576.4,38.1 L590.6,37.1 L593.4,39.5 L603.0,44.1 L606.9,44.0 L610.1,47.9 L614.2,48.7 L617.8,52.1 L618.4,53.9 L625.7,59.3 L630.3,59.8 L641.1,57.7 L647.5,58.7 L656.9,62.1 L661.7,65.6 L665.9,65.5 L684.5,70.9 L692.2,74.6 L695.4,77.8 L700.5,80.2 L622.1,214.3 L527.9,246.0 L370.7,254.0 Z',
      bbox: [262, 24, 438, 230], label: [480.7, 130.8] },
    east_lombok: { d: 'M700.5,80.2 L707.3,81.5 L717.5,79.6 L741.2,86.2 L747.1,90.0 L747.8,92.0 L751.0,94.6 L752.6,99.3 L758.2,102.5 L758.9,104.7 L771.3,118.2 L773.1,121.7 L775.4,122.5 L772.8,121.7 L770.6,119.5 L770.6,121.5 L781.8,124.7 L786.4,128.0 L785.5,128.4 L786.0,129.2 L787.6,128.4 L793.5,133.1 L791.8,138.2 L798.5,148.2 L799.0,151.0 L797.9,157.2 L798.6,161.0 L796.9,171.8 L787.6,185.4 L787.7,187.9 L790.3,193.4 L786.5,197.0 L788.1,208.5 L782.6,212.6 L779.0,213.6 L776.2,216.2 L774.5,219.3 L770.3,222.7 L767.7,226.4 L761.3,232.7 L756.7,235.5 L754.2,239.6 L749.9,242.8 L749.0,244.0 L749.7,245.4 L748.3,246.3 L748.3,247.1 L750.0,247.5 L748.4,248.0 L749.4,250.6 L751.0,252.0 L754.2,252.1 L752.3,253.7 L756.0,255.4 L755.2,256.0 L756.6,256.7 L757.2,253.8 L760.4,252.8 L760.5,249.2 L755.1,242.6 L754.5,240.6 L758.6,243.2 L760.4,241.2 L760.7,238.4 L761.8,238.6 L760.0,244.6 L762.3,251.2 L761.8,257.2 L752.3,264.9 L749.4,273.7 L746.7,277.4 L745.3,284.6 L748.1,292.9 L750.8,296.6 L753.1,297.9 L753.0,301.0 L744.4,312.8 L719.9,335.3 L718.9,337.6 L719.7,343.9 L716.6,351.3 L716.7,355.2 L710.8,362.8 L710.8,364.6 L703.2,373.0 L699.1,379.9 L689.4,388.0 L685.1,397.6 L684.0,404.7 L681.6,407.5 L681.8,406.8 L679.7,407.4 L680.9,407.6 L680.4,409.2 L681.4,407.8 L680.2,410.5 L681.7,411.5 L680.4,413.6 L678.9,412.7 L677.6,415.9 L670.3,423.9 L663.8,433.6 L662.6,439.1 L575.0,500.3 L567.1,444.7 L559.3,349.3 L527.9,246.0 L622.1,214.3 Z',
      bbox: [528, 80, 271, 421], label: [637.8, 297.7] },
    central_lombok: { d: 'M262.8,202.6 L370.7,254.0 L527.9,246.0 L559.3,349.3 L567.1,444.7 L575.0,500.3 L488.6,524.1 L370.7,524.1 L291.8,540.6 L331.5,476.4 L370.7,397.0 L355.0,293.7 Z',
      bbox: [263, 203, 312, 338], label: [449.3, 369.2] },
    west_lombok: { d: 'M291.8,540.6 L290.6,536.7 L288.5,535.9 L285.8,537.2 L285.5,535.6 L281.9,533.0 L280.1,533.7 L280.1,536.5 L277.7,533.8 L274.2,533.2 L271.3,533.3 L271.2,534.7 L266.8,535.1 L265.3,536.5 L263.5,541.0 L259.6,541.9 L258.2,543.6 L256.3,541.9 L256.2,538.4 L252.5,537.6 L248.8,538.6 L246.4,540.6 L244.4,539.5 L239.6,542.2 L237.1,546.2 L237.2,550.9 L243.1,551.2 L245.0,553.0 L246.2,552.8 L251.3,558.9 L250.6,561.7 L248.3,563.4 L248.5,564.9 L244.1,563.9 L241.7,567.8 L242.2,570.1 L246.6,571.4 L247.7,574.1 L244.0,573.0 L242.8,570.9 L236.2,574.9 L234.7,573.7 L232.8,574.1 L231.4,569.7 L230.4,570.3 L229.0,568.8 L226.4,569.1 L228.1,566.0 L228.0,563.8 L226.7,561.9 L224.2,561.9 L221.9,564.1 L221.1,563.7 L220.1,562.2 L221.2,560.4 L220.1,559.6 L220.4,558.3 L218.7,558.2 L218.8,557.0 L217.3,556.0 L216.1,557.5 L214.6,557.1 L214.2,553.4 L209.4,553.5 L207.4,554.9 L207.6,551.3 L206.5,551.0 L207.9,549.8 L207.9,547.5 L206.2,546.6 L203.1,548.0 L202.8,546.5 L200.8,547.5 L199.7,546.0 L199.1,546.5 L199.0,545.3 L200.3,545.0 L199.6,543.8 L201.4,542.0 L200.7,540.7 L198.0,540.3 L194.7,536.9 L192.1,537.0 L192.5,535.8 L191.1,535.7 L190.9,533.8 L189.6,534.3 L187.1,533.1 L188.2,532.4 L187.5,532.0 L188.9,529.1 L188.2,527.3 L186.5,525.1 L179.1,521.2 L177.0,518.7 L169.8,519.8 L167.1,522.7 L167.0,526.4 L165.3,528.4 L162.8,527.2 L160.9,528.1 L161.7,524.7 L159.5,524.8 L159.3,523.7 L155.5,522.8 L155.6,520.7 L152.6,516.4 L147.8,516.2 L145.1,517.9 L140.1,512.5 L137.9,513.2 L136.9,515.4 L135.7,515.9 L133.4,512.4 L130.4,512.1 L128.7,507.7 L126.9,506.6 L123.8,507.2 L122.1,510.8 L121.3,509.5 L117.1,511.7 L116.3,510.4 L113.4,510.4 L108.6,512.5 L107.5,511.1 L107.4,508.2 L110.6,505.3 L110.2,502.5 L106.7,501.5 L104.9,503.2 L101.6,502.5 L96.8,504.3 L98.1,502.1 L97.4,502.0 L100.1,499.7 L99.3,497.6 L96.9,495.9 L98.4,494.5 L98.4,492.5 L97.0,491.5 L93.3,492.9 L94.7,491.9 L94.7,489.3 L95.9,488.5 L92.7,488.3 L94.9,486.2 L94.1,484.7 L95.6,483.9 L96.2,479.5 L98.2,477.0 L97.5,475.3 L95.5,475.7 L95.3,473.6 L92.0,468.9 L90.8,462.9 L91.5,458.1 L88.9,454.4 L92.8,452.7 L96.0,448.2 L101.2,445.7 L102.5,436.1 L106.0,432.9 L113.6,429.0 L118.9,433.8 L124.1,429.5 L123.9,431.3 L126.5,436.2 L123.1,443.8 L123.1,446.7 L124.7,449.7 L131.3,451.8 L132.6,453.6 L135.4,454.7 L137.0,457.2 L136.2,462.4 L137.4,464.1 L141.6,463.2 L141.6,461.8 L147.2,453.0 L146.9,450.3 L148.8,449.1 L150.4,450.4 L150.6,453.5 L148.6,455.8 L149.1,457.6 L148.0,458.9 L146.2,460.2 L144.9,459.8 L145.2,463.0 L146.5,461.7 L149.7,462.9 L154.2,466.9 L154.4,469.5 L161.4,472.6 L165.0,472.6 L166.5,470.1 L166.3,467.7 L168.4,467.1 L174.5,467.6 L174.1,470.4 L172.6,470.3 L172.3,471.3 L174.5,471.8 L175.6,471.5 L176.9,469.1 L175.6,468.2 L177.1,467.5 L178.7,468.2 L178.8,466.6 L181.8,464.4 L184.6,458.8 L183.7,455.5 L188.7,449.4 L194.6,448.5 L203.1,441.5 L202.5,436.4 L204.5,439.9 L209.0,439.1 L214.1,441.4 L217.0,440.2 L217.7,437.1 L219.5,436.5 L221.6,437.5 L226.9,443.7 L230.2,445.2 L236.1,453.7 L237.5,454.0 L240.8,453.3 L247.5,446.0 L253.0,444.5 L253.3,441.8 L251.8,435.4 L254.0,433.3 L256.2,428.4 L256.8,429.8 L255.9,431.3 L256.8,432.7 L261.8,433.2 L265.0,431.6 L266.3,434.1 L265.7,436.1 L262.3,439.2 L263.0,441.8 L261.9,443.4 L265.6,449.1 L262.2,452.4 L260.2,451.6 L258.1,452.8 L258.9,455.3 L261.1,457.3 L261.4,460.0 L260.1,462.9 L261.7,465.1 L264.0,465.2 L266.6,463.3 L266.0,461.4 L267.0,459.8 L267.4,466.6 L268.2,465.2 L267.8,460.9 L268.6,460.5 L269.6,462.7 L273.6,466.5 L273.6,468.1 L274.2,466.9 L278.5,465.9 L276.3,470.3 L277.6,471.6 L280.6,470.8 L280.1,467.4 L282.5,467.2 L281.2,467.0 L280.1,465.0 L278.5,464.8 L277.3,463.0 L277.8,458.3 L275.5,455.6 L276.1,453.2 L280.0,451.1 L282.0,447.9 L281.3,447.7 L281.6,446.4 L279.3,447.4 L277.5,445.1 L282.1,444.7 L284.5,441.1 L286.5,443.1 L295.1,435.8 L294.1,434.1 L290.9,436.1 L288.1,436.2 L286.8,437.6 L286.0,437.2 L286.2,436.0 L284.0,435.4 L278.9,437.6 L277.5,435.3 L276.3,435.9 L276.2,434.8 L279.5,431.5 L278.1,426.8 L280.7,425.9 L279.2,423.9 L279.4,420.8 L278.4,419.1 L279.1,421.3 L277.2,427.7 L278.7,431.9 L277.4,432.9 L277.5,430.1 L275.5,434.8 L276.2,437.0 L273.4,442.8 L276.0,442.1 L273.0,443.0 L273.9,432.4 L282.8,395.6 L285.8,390.0 L286.1,387.6 L284.3,381.7 L282.8,381.4 L284.6,380.4 L282.7,381.3 L283.9,380.7 L283.1,380.4 L283.8,372.5 L287.4,354.4 L287.9,343.7 L285.1,318.0 L286.1,305.1 L281.2,275.0 L278.6,269.0 L276.4,267.2 L276.7,265.3 L273.7,262.3 L272.6,259.0 L267.2,254.8 L264.1,256.3 L264.6,251.9 L262.3,250.0 L260.2,251.0 L259.4,247.4 L255.7,241.8 L257.9,240.8 L258.8,238.7 L258.0,231.0 L258.7,229.7 L257.4,226.0 L257.7,219.5 L254.6,211.5 L255.6,208.0 L257.1,208.7 L260.1,206.2 L260.1,204.6 L257.4,202.8 L262.8,202.6 L355.0,293.7 L370.7,397.0 L331.5,476.4 Z',
      bbox: [89, 203, 282, 372], label: [288.3, 333.4] },
    south_lombok: { d: 'M662.6,439.1 L664.1,441.1 L663.6,444.7 L658.0,453.6 L658.2,454.7 L653.2,459.0 L651.9,461.2 L646.9,460.0 L651.3,461.3 L651.2,462.0 L641.5,469.0 L637.4,474.8 L635.7,475.2 L633.2,473.7 L630.0,476.6 L629.6,475.9 L633.3,472.7 L636.3,472.0 L634.5,471.2 L633.4,471.9 L634.1,470.9 L629.5,474.5 L626.6,480.3 L629.0,478.2 L626.7,483.5 L627.1,486.2 L626.6,486.8 L626.2,484.9 L623.1,483.4 L626.0,485.4 L626.6,488.2 L625.7,491.1 L623.7,492.4 L624.4,493.4 L623.6,492.8 L622.7,493.9 L622.8,496.9 L620.9,498.7 L618.0,500.6 L616.4,500.2 L615.6,497.9 L614.3,498.5 L610.4,496.4 L605.7,499.3 L603.9,497.4 L602.0,497.2 L605.3,499.4 L606.8,499.3 L606.5,500.5 L609.7,504.9 L609.2,506.8 L607.9,507.2 L609.5,508.1 L608.0,509.5 L609.7,508.3 L610.1,509.6 L610.4,509.0 L608.9,507.5 L610.1,503.9 L610.9,504.7 L614.8,503.4 L618.3,503.9 L620.0,501.5 L622.9,501.1 L621.4,502.9 L622.4,503.6 L621.7,506.9 L622.4,507.8 L621.1,508.1 L622.7,509.3 L621.3,510.3 L622.0,511.6 L619.5,513.4 L621.8,516.1 L620.6,517.0 L619.0,516.3 L618.7,517.7 L619.9,519.2 L617.5,522.0 L620.6,525.8 L619.3,525.3 L619.8,526.1 L616.4,530.7 L617.7,534.4 L620.0,534.9 L620.4,536.3 L625.0,539.1 L626.8,539.3 L627.3,538.2 L626.5,537.2 L628.0,538.1 L629.6,537.5 L630.9,538.8 L629.9,538.0 L630.1,533.7 L626.0,532.3 L626.7,531.3 L625.7,530.8 L625.5,528.5 L624.5,528.3 L625.8,525.1 L630.1,524.6 L633.0,527.8 L634.0,527.7 L634.0,526.7 L635.2,527.9 L637.5,527.6 L636.0,524.9 L635.1,525.7 L634.6,524.5 L633.5,525.0 L634.1,523.0 L633.4,521.8 L632.6,522.5 L631.7,520.7 L634.8,520.9 L634.7,519.7 L633.3,518.8 L633.5,517.6 L635.7,520.3 L636.5,519.6 L637.3,520.5 L636.0,521.1 L635.6,523.1 L637.7,522.6 L640.1,524.4 L640.3,523.0 L639.1,521.6 L641.5,522.6 L642.3,522.0 L642.3,523.4 L644.7,525.4 L646.8,524.4 L644.4,523.0 L648.1,522.8 L647.4,520.3 L646.1,521.4 L643.7,521.4 L643.6,520.3 L642.0,520.2 L640.1,518.4 L638.9,518.7 L637.7,517.3 L642.1,517.8 L644.8,519.2 L646.3,518.4 L645.3,517.1 L643.4,517.0 L642.9,515.8 L640.1,516.1 L639.7,512.7 L641.3,512.6 L645.0,516.0 L649.7,514.8 L650.9,517.1 L654.7,518.4 L653.8,519.1 L654.3,521.2 L653.5,523.3 L651.5,524.8 L654.0,526.7 L655.2,524.9 L658.3,527.5 L657.6,529.3 L656.3,530.1 L654.5,528.1 L653.1,529.0 L653.9,529.8 L653.2,531.0 L649.8,529.5 L647.0,530.1 L647.3,531.7 L649.6,531.3 L648.6,532.4 L649.2,533.3 L651.0,531.9 L655.1,533.5 L655.3,534.7 L652.3,534.5 L653.3,536.5 L650.6,535.7 L651.4,537.0 L650.4,539.7 L648.7,539.6 L649.3,541.3 L650.7,541.7 L652.2,539.4 L652.6,541.4 L653.4,541.0 L655.3,542.3 L655.2,543.4 L653.1,544.8 L655.2,545.2 L654.6,546.5 L655.9,547.2 L657.0,546.3 L656.3,544.5 L657.1,543.4 L659.5,544.8 L660.6,543.9 L660.4,542.9 L655.2,540.3 L656.0,539.8 L655.1,538.3 L657.5,536.3 L657.9,538.9 L658.8,537.3 L660.3,536.9 L662.4,539.9 L662.4,536.7 L665.8,538.1 L666.5,537.4 L667.6,539.3 L668.2,538.1 L670.4,538.2 L669.5,536.7 L672.0,535.4 L672.3,534.1 L673.8,534.7 L672.0,538.4 L672.7,540.6 L670.1,543.2 L671.6,544.5 L672.5,542.8 L673.9,542.6 L673.5,543.9 L675.1,544.4 L674.4,546.4 L678.1,545.1 L676.5,544.3 L676.9,542.0 L682.1,540.3 L681.9,538.4 L684.4,539.7 L686.2,539.0 L685.6,537.9 L687.5,538.0 L689.4,539.6 L691.3,537.3 L694.7,539.0 L696.4,541.2 L694.2,544.5 L692.9,542.5 L690.2,542.6 L688.4,544.2 L689.4,548.6 L689.1,547.9 L685.1,549.0 L684.9,547.9 L683.8,547.8 L682.4,549.5 L681.0,549.7 L681.1,553.0 L680.0,553.0 L679.5,554.3 L677.9,554.0 L677.4,556.5 L678.7,556.5 L677.8,556.8 L677.8,561.5 L677.0,562.9 L677.7,565.3 L674.7,565.1 L670.0,568.7 L669.0,567.6 L667.0,567.6 L665.0,569.7 L665.4,570.7 L662.3,569.5 L660.5,572.0 L660.4,570.3 L658.8,570.3 L657.4,571.9 L655.0,570.8 L654.0,574.5 L651.7,573.7 L650.4,574.3 L649.9,576.2 L647.5,575.3 L646.3,576.6 L644.4,575.9 L644.5,577.0 L642.3,578.8 L638.4,579.3 L632.3,584.2 L629.8,583.8 L628.6,582.1 L629.4,580.9 L632.1,581.3 L636.2,577.3 L639.0,578.4 L637.6,575.1 L644.2,570.9 L641.8,570.7 L639.3,572.0 L638.4,571.7 L638.5,570.6 L636.5,571.3 L635.8,569.6 L634.7,569.6 L636.0,567.3 L638.1,567.3 L636.5,565.4 L640.1,562.7 L640.4,561.1 L638.4,561.7 L637.6,557.9 L635.0,556.1 L632.3,555.7 L631.4,552.5 L632.3,550.1 L631.0,546.8 L629.2,548.9 L630.6,555.2 L629.2,553.8 L627.9,554.6 L630.2,558.2 L629.5,561.0 L628.1,557.1 L627.5,557.8 L625.9,556.7 L626.3,558.5 L625.2,559.2 L626.0,560.7 L625.0,561.3 L623.4,557.8 L620.9,555.7 L620.4,556.2 L621.3,558.6 L620.6,559.4 L621.7,560.6 L621.0,561.6 L622.8,562.1 L624.5,565.4 L622.3,568.3 L620.4,568.0 L620.5,565.0 L619.5,564.8 L618.8,566.1 L617.5,562.3 L614.4,559.5 L615.3,560.4 L615.5,563.4 L614.6,564.1 L616.7,567.8 L616.5,570.1 L617.4,569.9 L617.2,569.2 L619.3,569.6 L621.2,571.5 L622.3,570.2 L621.7,569.4 L625.5,566.0 L625.2,562.2 L626.1,561.4 L627.3,561.9 L628.7,560.3 L627.5,562.5 L628.0,565.3 L627.1,567.4 L622.3,573.7 L621.5,578.5 L618.9,581.2 L611.4,583.0 L602.6,586.8 L600.6,588.7 L594.8,588.4 L590.7,587.0 L588.1,587.3 L587.9,588.6 L584.0,589.0 L575.1,586.8 L571.6,587.4 L570.2,585.4 L568.0,584.7 L568.0,582.3 L572.5,581.1 L573.2,578.3 L575.9,576.6 L576.4,573.7 L579.3,573.3 L581.4,571.6 L582.8,562.4 L583.8,561.7 L586.6,549.5 L588.8,549.3 L587.6,550.6 L589.1,550.4 L590.7,552.5 L590.6,550.5 L589.3,549.5 L591.2,542.6 L588.9,537.3 L590.9,537.7 L591.3,536.7 L595.4,536.0 L595.4,535.2 L591.1,534.2 L590.0,532.9 L591.9,531.6 L594.5,531.6 L595.1,529.3 L596.9,530.1 L598.4,529.4 L601.6,523.6 L601.0,520.0 L597.7,520.1 L597.3,517.9 L597.7,516.8 L601.6,516.8 L603.2,513.3 L601.9,513.4 L597.8,509.2 L595.8,509.9 L595.3,505.7 L592.7,507.0 L593.5,509.6 L591.4,509.6 L590.8,514.6 L585.3,517.8 L581.0,516.1 L582.1,511.4 L581.5,508.5 L581.3,513.1 L579.4,515.2 L574.6,510.9 L577.4,515.0 L575.1,515.0 L575.5,517.1 L573.2,512.9 L571.8,512.5 L571.0,510.9 L569.1,511.0 L576.2,520.6 L574.3,520.0 L573.9,520.7 L573.9,522.2 L576.6,523.2 L575.5,525.7 L574.3,523.9 L572.8,524.8 L571.3,522.5 L571.0,518.7 L568.5,518.3 L564.6,511.8 L562.7,514.8 L561.3,514.2 L559.3,516.2 L556.5,515.7 L555.2,519.5 L556.8,521.0 L556.2,522.4 L554.2,522.5 L553.9,525.1 L551.1,526.4 L550.3,533.1 L546.1,541.4 L546.9,544.2 L544.6,543.6 L546.1,540.5 L544.5,536.9 L539.1,536.6 L536.1,538.3 L534.2,541.0 L533.3,539.9 L534.2,541.5 L533.4,544.1 L531.6,544.9 L532.3,544.7 L535.6,549.3 L538.2,550.1 L539.8,551.9 L539.7,557.3 L542.2,557.9 L542.6,559.2 L539.8,560.1 L542.6,560.9 L542.0,564.5 L543.2,566.7 L545.1,567.0 L547.4,571.5 L542.7,576.5 L541.7,583.5 L539.6,586.6 L538.7,590.3 L538.4,592.5 L540.4,599.6 L541.8,600.8 L540.7,600.4 L540.3,603.2 L541.2,602.9 L541.8,604.5 L540.2,604.2 L540.6,606.5 L538.9,607.5 L540.9,609.2 L539.9,610.4 L539.6,609.5 L538.4,610.4 L538.6,613.5 L536.1,609.4 L533.8,609.7 L533.0,611.7 L530.3,611.3 L529.9,614.4 L528.9,613.8 L527.7,616.2 L524.5,615.8 L523.3,612.3 L521.7,611.8 L519.4,612.8 L519.2,614.6 L518.3,614.3 L518.6,612.1 L522.4,607.9 L518.9,601.6 L519.5,598.4 L517.7,595.9 L517.3,591.6 L520.3,589.7 L521.8,586.9 L524.4,585.1 L528.8,579.6 L528.4,577.7 L526.2,575.2 L521.3,574.8 L521.2,574.1 L522.5,573.6 L522.2,572.6 L520.4,574.5 L519.3,573.6 L520.9,575.5 L518.0,578.5 L514.5,579.7 L512.2,579.1 L512.0,579.9 L510.7,575.5 L508.2,574.5 L507.3,576.0 L505.2,575.8 L505.8,572.1 L502.2,570.9 L501.6,569.7 L497.7,571.3 L495.5,573.4 L495.1,574.8 L500.3,580.1 L501.7,583.8 L503.5,584.4 L501.9,588.5 L502.5,590.5 L503.9,590.5 L505.1,593.0 L501.6,596.1 L497.2,592.6 L498.5,589.4 L494.2,585.0 L491.1,584.0 L489.2,587.5 L488.0,586.4 L488.1,584.9 L489.7,584.3 L489.9,581.4 L488.5,579.9 L487.4,580.6 L486.7,578.5 L483.4,578.3 L480.4,581.1 L481.4,584.3 L483.8,585.3 L480.3,588.0 L480.6,586.2 L476.6,578.6 L467.7,577.4 L466.1,577.8 L465.0,580.2 L462.7,579.2 L462.6,576.8 L465.0,576.6 L466.6,573.1 L464.0,570.0 L462.9,571.1 L463.2,572.3 L464.5,572.5 L464.9,574.3 L463.7,575.2 L463.6,576.8 L462.2,574.8 L461.0,574.9 L460.7,573.1 L457.8,571.3 L458.5,570.2 L457.5,569.2 L448.9,566.5 L446.4,566.9 L445.0,568.5 L446.2,571.3 L444.8,574.2 L445.0,576.5 L445.8,579.7 L447.4,580.9 L446.2,581.1 L446.2,582.1 L442.7,579.2 L440.1,580.0 L439.6,578.9 L437.0,578.5 L436.8,577.3 L435.2,578.1 L434.1,576.5 L433.1,577.5 L430.0,576.6 L427.3,574.0 L425.1,573.8 L420.2,576.4 L420.0,578.2 L421.6,581.5 L419.3,584.7 L417.1,585.5 L416.9,584.5 L415.2,584.9 L413.0,583.7 L413.4,579.5 L412.2,576.8 L413.0,574.9 L409.1,571.9 L406.6,574.4 L408.1,576.0 L408.1,577.5 L405.8,579.1 L404.3,579.1 L403.8,578.1 L403.0,579.0 L401.3,578.5 L399.5,576.3 L399.0,573.6 L397.3,572.8 L391.7,572.5 L386.3,573.8 L384.9,576.1 L384.7,579.7 L385.9,581.0 L382.6,583.9 L382.5,585.6 L381.5,583.5 L382.0,582.4 L379.8,581.3 L379.4,581.8 L379.2,580.7 L378.5,582.3 L378.0,581.0 L377.1,581.8 L376.3,580.8 L376.1,582.3 L374.5,583.2 L371.6,580.6 L365.3,582.8 L364.2,579.9 L361.2,579.6 L362.1,577.9 L360.6,575.8 L359.3,577.1 L358.5,576.5 L357.8,578.3 L356.5,578.0 L355.9,576.8 L357.1,576.5 L356.7,575.3 L357.8,575.3 L358.6,572.0 L357.4,570.5 L356.5,571.2 L356.9,568.6 L355.4,568.5 L355.0,569.4 L354.8,567.8 L352.3,567.0 L352.6,564.8 L354.2,564.6 L354.3,562.4 L355.3,562.3 L355.8,560.5 L352.9,555.6 L353.1,553.5 L355.5,552.5 L356.3,550.5 L353.2,545.1 L351.1,543.5 L350.5,544.0 L350.8,543.0 L346.3,542.6 L344.9,544.8 L338.7,547.7 L333.4,547.8 L327.8,545.8 L320.9,545.4 L319.1,545.9 L316.8,549.2 L315.1,550.1 L313.8,549.8 L313.5,547.4 L312.3,547.1 L295.1,550.5 L292.2,553.9 L291.2,557.3 L291.8,558.3 L289.9,559.9 L290.6,568.0 L287.7,566.2 L285.9,559.7 L274.2,558.1 L270.2,560.4 L268.1,560.4 L266.1,555.1 L266.6,552.6 L265.0,550.9 L264.4,547.8 L268.2,545.5 L269.3,546.0 L271.0,543.8 L275.8,542.5 L276.5,543.4 L275.3,543.6 L276.3,544.2 L277.1,544.1 L276.6,543.5 L284.1,544.7 L290.3,543.9 L289.9,541.2 L290.4,540.3 L291.8,540.6 L370.7,524.1 L488.6,524.1 L575.0,500.3 Z',
      bbox: [264, 439, 432, 177], label: [449.3, 551.9],
      clusterBox: [298, 471, 347, 217],
      clusters: {
        selong_belanak_bays: { label: 'Selong Belanak', label_id: 'Selong Belanak',
          d: 'M391,572.7 L386.3,573.8 L384.9,576.1 L384.7,579.7 L385.9,581.0 L382.6,583.9 L382.5,585.6 L381.5,583.5 L382.0,582.4 L379.8,581.3 L379.4,581.8 L379.2,580.7 L378.5,582.3 L378.0,581.0 L377.1,581.8 L376.3,580.8 L376.1,582.3 L374.5,583.2 L371.6,580.6 L365.3,582.8 L364.2,579.9 L361.2,579.6 L362.1,577.9 L360.6,575.8 L359.3,577.1 L358.5,576.5 L357.8,578.3 L356.5,578.0 L355.9,576.8 L357.1,576.5 L356.7,575.3 L357.8,575.3 L358.6,572.0 L357.4,570.5 L356.5,571.2 L356.9,568.6 L355.4,568.5 L355.0,569.4 L354.8,567.8 L352.3,567.0 L352.6,564.8 L354.2,564.6 L354.3,562.4 L355.3,562.3 L355.8,560.5 L352.9,555.6 L353.1,553.5 L355.5,552.5 L356.3,550.5 L353.2,545.1 L351.1,543.5 L350.5,544.0 L350.8,543.0 L346.3,542.6 L344.9,544.8 L338.7,547.7 L333.4,547.8 L327.8,545.8 L320.9,545.4 L319.1,545.9 L316.8,549.2 L315.1,550.1 L313.8,549.8 L313.5,547.4 L312.3,547.1 L295.1,550.5 L292.2,553.9 L291.2,557.3 L291.8,558.3 L289.9,559.9 L290.6,568.0 L287.7,566.2 L285.9,559.7 L274.2,558.1 L270.2,560.4 L268.1,560.4 L266.1,555.1 L266.6,552.6 L265.0,550.9 L264.4,547.8 L268.2,545.5 L269.3,546.0 L271.0,543.8 L275.8,542.5 L276.5,543.4 L275.3,543.6 L276.3,544.2 L277.1,544.1 L276.6,543.5 L284.1,544.7 L290.3,543.9 L289.9,541.2 L290.4,540.3 L291.8,540.6 L370.7,524.1 L391,524.1 Z',
          areas: ['selong_belanak', 'mawi'],
          markers: ['selong_belanak', 'mawi'],
          zoom: [276, 469, 152, 150], label: [354.9, 498.9] },
        kuta_mandalika: { label: 'Kuta–Mandalika', label_id: 'Kuta–Mandalika',
          d: 'M512,579.9 L512.0,579.9 L510.7,575.5 L508.2,574.5 L507.3,576.0 L505.2,575.8 L505.8,572.1 L502.2,570.9 L501.6,569.7 L497.7,571.3 L495.5,573.4 L495.1,574.8 L500.3,580.1 L501.7,583.8 L503.5,584.4 L501.9,588.5 L502.5,590.5 L503.9,590.5 L505.1,593.0 L501.6,596.1 L497.2,592.6 L498.5,589.4 L494.2,585.0 L491.1,584.0 L489.2,587.5 L488.0,586.4 L488.1,584.9 L489.7,584.3 L489.9,581.4 L488.5,579.9 L487.4,580.6 L486.7,578.5 L483.4,578.3 L480.4,581.1 L481.4,584.3 L483.8,585.3 L480.3,588.0 L480.6,586.2 L476.6,578.6 L467.7,577.4 L466.1,577.8 L465.0,580.2 L462.7,579.2 L462.6,576.8 L465.0,576.6 L466.6,573.1 L464.0,570.0 L462.9,571.1 L463.2,572.3 L464.5,572.5 L464.9,574.3 L463.7,575.2 L463.6,576.8 L462.2,574.8 L461.0,574.9 L460.7,573.1 L457.8,571.3 L458.5,570.2 L457.5,569.2 L448.9,566.5 L446.4,566.9 L445.0,568.5 L446.2,571.3 L444.8,574.2 L445.0,576.5 L445.8,579.7 L447.4,580.9 L446.2,581.1 L446.2,582.1 L442.7,579.2 L440.1,580.0 L439.6,578.9 L437.0,578.5 L436.8,577.3 L435.2,578.1 L434.1,576.5 L433.1,577.5 L430.0,576.6 L427.3,574.0 L425.1,573.8 L420.2,576.4 L420.0,578.2 L421.6,581.5 L419.3,584.7 L417.1,585.5 L416.9,584.5 L415.2,584.9 L413.0,583.7 L413.4,579.5 L412.2,576.8 L413.0,574.9 L409.1,571.9 L406.6,574.4 L408.1,576.0 L408.1,577.5 L405.8,579.1 L404.3,579.1 L403.8,578.1 L403.0,579.0 L401.3,578.5 L399.5,576.3 L399.0,573.6 L397.3,572.8 L391.7,572.5 L391,572.7 L391,524.1 L488.6,524.1 L512,517.7 Z',
          areas: ['are_guling', 'mawun', 'kuta', 'gerupuk', 'tanjung_aan'],
          markers: ['are_guling', 'mawun', 'kuta', 'gerupuk'],
          zoom: [367, 490, 160, 132], label: [457, 519.5] },
        south_east: { label: 'South East', label_id: 'Tenggara',
          d: 'M662.6,439.1 L664.1,441.1 L663.6,444.7 L658.0,453.6 L658.2,454.7 L653.2,459.0 L651.9,461.2 L646.9,460.0 L651.3,461.3 L651.2,462.0 L641.5,469.0 L637.4,474.8 L635.7,475.2 L633.2,473.7 L630.0,476.6 L629.6,475.9 L633.3,472.7 L636.3,472.0 L634.5,471.2 L633.4,471.9 L634.1,470.9 L629.5,474.5 L626.6,480.3 L629.0,478.2 L626.7,483.5 L627.1,486.2 L626.6,486.8 L626.2,484.9 L623.1,483.4 L626.0,485.4 L626.6,488.2 L625.7,491.1 L623.7,492.4 L624.4,493.4 L623.6,492.8 L622.7,493.9 L622.8,496.9 L620.9,498.7 L618.0,500.6 L616.4,500.2 L615.6,497.9 L614.3,498.5 L610.4,496.4 L605.7,499.3 L603.9,497.4 L602.0,497.2 L605.3,499.4 L606.8,499.3 L606.5,500.5 L609.7,504.9 L609.2,506.8 L607.9,507.2 L609.5,508.1 L608.0,509.5 L609.7,508.3 L610.1,509.6 L610.4,509.0 L608.9,507.5 L610.1,503.9 L610.9,504.7 L614.8,503.4 L618.3,503.9 L620.0,501.5 L622.9,501.1 L621.4,502.9 L622.4,503.6 L621.7,506.9 L622.4,507.8 L621.1,508.1 L622.7,509.3 L621.3,510.3 L622.0,511.6 L619.5,513.4 L621.8,516.1 L620.6,517.0 L619.0,516.3 L618.7,517.7 L619.9,519.2 L617.5,522.0 L620.6,525.8 L619.3,525.3 L619.8,526.1 L616.4,530.7 L617.7,534.4 L620.0,534.9 L620.4,536.3 L625.0,539.1 L626.8,539.3 L627.3,538.2 L626.5,537.2 L628.0,538.1 L629.6,537.5 L630.9,538.8 L629.9,538.0 L630.1,533.7 L626.0,532.3 L626.7,531.3 L625.7,530.8 L625.5,528.5 L624.5,528.3 L625.8,525.1 L630.1,524.6 L633.0,527.8 L634.0,527.7 L634.0,526.7 L635.2,527.9 L637.5,527.6 L636.0,524.9 L635.1,525.7 L634.6,524.5 L633.5,525.0 L634.1,523.0 L633.4,521.8 L632.6,522.5 L631.7,520.7 L634.8,520.9 L634.7,519.7 L633.3,518.8 L633.5,517.6 L635.7,520.3 L636.5,519.6 L637.3,520.5 L636.0,521.1 L635.6,523.1 L637.7,522.6 L640.1,524.4 L640.3,523.0 L639.1,521.6 L641.5,522.6 L642.3,522.0 L642.3,523.4 L644.7,525.4 L646.8,524.4 L644.4,523.0 L648.1,522.8 L647.4,520.3 L646.1,521.4 L643.7,521.4 L643.6,520.3 L642.0,520.2 L640.1,518.4 L638.9,518.7 L637.7,517.3 L642.1,517.8 L644.8,519.2 L646.3,518.4 L645.3,517.1 L643.4,517.0 L642.9,515.8 L640.1,516.1 L639.7,512.7 L641.3,512.6 L645.0,516.0 L649.7,514.8 L650.9,517.1 L654.7,518.4 L653.8,519.1 L654.3,521.2 L653.5,523.3 L651.5,524.8 L654.0,526.7 L655.2,524.9 L658.3,527.5 L657.6,529.3 L656.3,530.1 L654.5,528.1 L653.1,529.0 L653.9,529.8 L653.2,531.0 L649.8,529.5 L647.0,530.1 L647.3,531.7 L649.6,531.3 L648.6,532.4 L649.2,533.3 L651.0,531.9 L655.1,533.5 L655.3,534.7 L652.3,534.5 L653.3,536.5 L650.6,535.7 L651.4,537.0 L650.4,539.7 L648.7,539.6 L649.3,541.3 L650.7,541.7 L652.2,539.4 L652.6,541.4 L653.4,541.0 L655.3,542.3 L655.2,543.4 L653.1,544.8 L655.2,545.2 L654.6,546.5 L655.9,547.2 L657.0,546.3 L656.3,544.5 L657.1,543.4 L659.5,544.8 L660.6,543.9 L660.4,542.9 L655.2,540.3 L656.0,539.8 L655.1,538.3 L657.5,536.3 L657.9,538.9 L658.8,537.3 L660.3,536.9 L662.4,539.9 L662.4,536.7 L665.8,538.1 L666.5,537.4 L667.6,539.3 L668.2,538.1 L670.4,538.2 L669.5,536.7 L672.0,535.4 L672.3,534.1 L673.8,534.7 L672.0,538.4 L672.7,540.6 L670.1,543.2 L671.6,544.5 L672.5,542.8 L673.9,542.6 L673.5,543.9 L675.1,544.4 L674.4,546.4 L678.1,545.1 L676.5,544.3 L676.9,542.0 L682.1,540.3 L681.9,538.4 L684.4,539.7 L686.2,539.0 L685.6,537.9 L687.5,538.0 L689.4,539.6 L691.3,537.3 L694.7,539.0 L696.4,541.2 L694.2,544.5 L692.9,542.5 L690.2,542.6 L688.4,544.2 L689.4,548.6 L689.1,547.9 L685.1,549.0 L684.9,547.9 L683.8,547.8 L682.4,549.5 L681.0,549.7 L681.1,553.0 L680.0,553.0 L679.5,554.3 L677.9,554.0 L677.4,556.5 L678.7,556.5 L677.8,556.8 L677.8,561.5 L677.0,562.9 L677.7,565.3 L674.7,565.1 L670.0,568.7 L669.0,567.6 L667.0,567.6 L665.0,569.7 L665.4,570.7 L662.3,569.5 L660.5,572.0 L660.4,570.3 L658.8,570.3 L657.4,571.9 L655.0,570.8 L654.0,574.5 L651.7,573.7 L650.4,574.3 L649.9,576.2 L647.5,575.3 L646.3,576.6 L644.4,575.9 L644.5,577.0 L642.3,578.8 L638.4,579.3 L632.3,584.2 L629.8,583.8 L628.6,582.1 L629.4,580.9 L632.1,581.3 L636.2,577.3 L639.0,578.4 L637.6,575.1 L644.2,570.9 L641.8,570.7 L639.3,572.0 L638.4,571.7 L638.5,570.6 L636.5,571.3 L635.8,569.6 L634.7,569.6 L636.0,567.3 L638.1,567.3 L636.5,565.4 L640.1,562.7 L640.4,561.1 L638.4,561.7 L637.6,557.9 L635.0,556.1 L632.3,555.7 L631.4,552.5 L632.3,550.1 L631.0,546.8 L629.2,548.9 L630.6,555.2 L629.2,553.8 L627.9,554.6 L630.2,558.2 L629.5,561.0 L628.1,557.1 L627.5,557.8 L625.9,556.7 L626.3,558.5 L625.2,559.2 L626.0,560.7 L625.0,561.3 L623.4,557.8 L620.9,555.7 L620.4,556.2 L621.3,558.6 L620.6,559.4 L621.7,560.6 L621.0,561.6 L622.8,562.1 L624.5,565.4 L622.3,568.3 L620.4,568.0 L620.5,565.0 L619.5,564.8 L618.8,566.1 L617.5,562.3 L614.4,559.5 L615.3,560.4 L615.5,563.4 L614.6,564.1 L616.7,567.8 L616.5,570.1 L617.4,569.9 L617.2,569.2 L619.3,569.6 L621.2,571.5 L622.3,570.2 L621.7,569.4 L625.5,566.0 L625.2,562.2 L626.1,561.4 L627.3,561.9 L628.7,560.3 L627.5,562.5 L628.0,565.3 L627.1,567.4 L622.3,573.7 L621.5,578.5 L618.9,581.2 L611.4,583.0 L602.6,586.8 L600.6,588.7 L594.8,588.4 L590.7,587.0 L588.1,587.3 L587.9,588.6 L584.0,589.0 L575.1,586.8 L571.6,587.4 L570.2,585.4 L568.0,584.7 L568.0,582.3 L572.5,581.1 L573.2,578.3 L575.9,576.6 L576.4,573.7 L579.3,573.3 L581.4,571.6 L582.8,562.4 L583.8,561.7 L586.6,549.5 L588.8,549.3 L587.6,550.6 L589.1,550.4 L590.7,552.5 L590.6,550.5 L589.3,549.5 L591.2,542.6 L588.9,537.3 L590.9,537.7 L591.3,536.7 L595.4,536.0 L595.4,535.2 L591.1,534.2 L590.0,532.9 L591.9,531.6 L594.5,531.6 L595.1,529.3 L596.9,530.1 L598.4,529.4 L601.6,523.6 L601.0,520.0 L597.7,520.1 L597.3,517.9 L597.7,516.8 L601.6,516.8 L603.2,513.3 L601.9,513.4 L597.8,509.2 L595.8,509.9 L595.3,505.7 L592.7,507.0 L593.5,509.6 L591.4,509.6 L590.8,514.6 L585.3,517.8 L581.0,516.1 L582.1,511.4 L581.5,508.5 L581.3,513.1 L579.4,515.2 L574.6,510.9 L577.4,515.0 L575.1,515.0 L575.5,517.1 L573.2,512.9 L571.8,512.5 L571.0,510.9 L569.1,511.0 L576.2,520.6 L574.3,520.0 L573.9,520.7 L573.9,522.2 L576.6,523.2 L575.5,525.7 L574.3,523.9 L572.8,524.8 L571.3,522.5 L571.0,518.7 L568.5,518.3 L564.6,511.8 L562.7,514.8 L561.3,514.2 L559.3,516.2 L556.5,515.7 L555.2,519.5 L556.8,521.0 L556.2,522.4 L554.2,522.5 L553.9,525.1 L551.1,526.4 L550.3,533.1 L546.1,541.4 L546.9,544.2 L544.6,543.6 L546.1,540.5 L544.5,536.9 L539.1,536.6 L536.1,538.3 L534.2,541.0 L533.3,539.9 L534.2,541.5 L533.4,544.1 L531.6,544.9 L532.3,544.7 L535.6,549.3 L538.2,550.1 L539.8,551.9 L539.7,557.3 L542.2,557.9 L542.6,559.2 L539.8,560.1 L542.6,560.9 L542.0,564.5 L543.2,566.7 L545.1,567.0 L547.4,571.5 L542.7,576.5 L541.7,583.5 L539.6,586.6 L538.7,590.3 L538.4,592.5 L540.4,599.6 L541.8,600.8 L540.7,600.4 L540.3,603.2 L541.2,602.9 L541.8,604.5 L540.2,604.2 L540.6,606.5 L538.9,607.5 L540.9,609.2 L539.9,610.4 L539.6,609.5 L538.4,610.4 L538.6,613.5 L536.1,609.4 L533.8,609.7 L533.0,611.7 L530.3,611.3 L529.9,614.4 L528.9,613.8 L527.7,616.2 L524.5,615.8 L523.3,612.3 L521.7,611.8 L519.4,612.8 L519.2,614.6 L518.3,614.3 L518.6,612.1 L522.4,607.9 L518.9,601.6 L519.5,598.4 L517.7,595.9 L517.3,591.6 L520.3,589.7 L521.8,586.9 L524.4,585.1 L528.8,579.6 L528.4,577.7 L526.2,575.2 L521.3,574.8 L521.2,574.1 L522.5,573.6 L522.2,572.6 L520.4,574.5 L519.3,573.6 L520.9,575.5 L518.0,578.5 L514.5,579.7 L512.2,579.1 L512.0,579.9 L512,579.9 L512,517.7 L575.0,500.3 Z',
          areas: ['awang', 'ekas'],
          markers: ['awang', 'ekas'],
          zoom: [498, 432, 196, 216], label: [604.8, 462.3] },
      } },
    gili_islands: { circles: [[260, 134.8, 11], [275.7, 136.4, 8], [294.5, 141.2, 8]],
      bbox: [230, 99, 94, 76], label: [277, 113] },
  },
  areas: {
    selong_belanak: { p: [353.5, 542.6], r: 'south_lombok', lp: 'diag' },
    mawi: { p: [380.2, 578.5], r: 'south_lombok', lp: 'diag2' },
    are_guling: { p: [400.6, 574.6], r: 'south_lombok', lp: 'diag' },
    mawun: { p: [420.2, 573.4], r: 'south_lombok', lp: 'diag2' },
    kuta: { p: [448.5, 563.6], r: 'south_lombok', lp: 'diag' },
    gerupuk: { p: [492.5, 581.5], r: 'south_lombok', lp: 'diag2' },
    awang: { p: [531.8, 608.5], r: 'south_lombok', lp: 'diag' },
    ekas: { p: [590.7, 541.4], r: 'south_lombok', lp: 'diag2' },
    senggigi: { p: [262.3, 248.4], r: 'west_lombok', lp: 'right' },
    mataram: { p: [313.4, 319.9], r: 'west_lombok', lp: 'right' },
    gerung: { p: [322, 401], r: 'west_lombok', lp: 'right' },
    lembar: { p: [286.7, 432.7], r: 'west_lombok', lp: 'bottom' },
    sekotong: { p: [209.7, 466.9], r: 'west_lombok', lp: 'bottom' },
    praya: { p: [441.5, 416.9], r: 'central_lombok', lp: 'right' },
    jonggat: { p: [402.2, 381.1], r: 'central_lombok', lp: 'top' },
    batukliang: { p: [472.9, 341.4], r: 'central_lombok', lp: 'right' },
    selong: { p: [646.5, 370.8], r: 'east_lombok', lp: 'left' },
    labuhan_lombok: { p: [746.3, 212.7], r: 'east_lombok', lp: 'left' },
    bangsal: { p: [309.5, 177.7], r: 'north_lombok', lp: 'top' },
    tanjung: { p: [352.7, 138.8], r: 'north_lombok', lp: 'top' },
    senaru: { p: [546.7, 99.1], r: 'north_lombok', lp: 'bottom' },
    gili_islands: { p: [275.7, 136.4], r: 'gili_islands', lp: 'bottom' },
  },
  // Place dots — finer localities under an Area (docs/adr/0010), shown at cluster zoom
  places: {
    torok: { p: [345.6, 540.7], a: 'selong_belanak', lp: 'diag' },
    tampah: { p: [337.8, 544.7], a: 'selong_belanak', lp: 'diag2' },
    serangan: { p: [322, 542.5], a: 'selong_belanak', lp: 'diag' },
    lancing: { p: [310.3, 544.5], a: 'selong_belanak', lp: 'diag2' },
    mekarsari: { p: [361.3, 528.9], a: 'selong_belanak', lp: 'diag' },
    semeti: { p: [388.8, 570.2], a: 'mawi', lp: 'diag2' },
    rowok: { p: [394.3, 569.6], a: 'mawi', lp: 'diag' },
    seger: { p: [459.5, 569.4], a: 'kuta', lp: 'diag2' },
    tanjung_aan: { p: [468.9, 574.6], a: 'kuta', lp: 'diag' },
    merese: { p: [475.2, 575.4], a: 'kuta', lp: 'diag2' },
    bumbang: { p: [482.3, 576.3], a: 'kuta', lp: 'diag' },
    mertak: { p: [465, 549.5], a: 'kuta', lp: 'diag2' },
    gunung_tunak: { p: [559.3, 513.2], a: 'awang', lp: 'diag' },
    pantai_surga: { p: [604.9, 582.8], a: 'ekas', lp: 'diag2' },
    kaliantan: { p: [626.1, 565.7], a: 'ekas', lp: 'diag' },
    tanjung_ringgit: { p: [659.8, 567.3], a: 'ekas', lp: 'diag2' },
    pink_beach: { p: [649.6, 573.1], a: 'ekas', lp: 'diag' },
    jerowaru: { p: [615.8, 492.3], a: 'ekas', lp: 'diag2' },
  }
};

function createLombokMap(wrapEl, opts) {
  opts = opts || {};
  var VB = LOMBOK_MAP.viewBox;
  var counts = { regions: {}, areas: {}, places: {} };
  var selRegion = '', selCluster = '', selArea = '';
  var animFrame = null;

  function regionHasClusters(rk) { var r = LOMBOK_MAP.regions[rk]; return !!(r && r.clusters); }
  function clusterOfArea(rk, ak) {
    var r = LOMBOK_MAP.regions[rk];
    if (!r || !r.clusters) return '';
    for (var ck in r.clusters) { if (r.clusters[ck].areas.indexOf(ak) >= 0) return ck; }
    return '';
  }
  function regionLabel(key) {
    var row = (FilterData.regions || []).find(function(r) { return r.region_key === key; });
    return (row ? lookupLabel(row) : key.replace(/_/g, ' ')).toUpperCase();
  }
  function areaLabel(key) {
    var row = (FilterData.areas || []).find(function(a) { return a.key === key; });
    var lbl = row ? lookupLabel(row) : key.replace(/_/g, ' ');
    return lbl.split(' / ')[0].toUpperCase(); // 'Kuta / Mandalika' → 'KUTA'
  }
  function clusterLabel(rk, ck) {
    var cl = LOMBOK_MAP.regions[rk].clusters[ck];
    var lbl = (CURRENT_LANG === 'id' && cl.label_id) ? cl.label_id : cl.label;
    return lbl.toUpperCase();
  }

  // ---- build SVG ----
  var regionsHtml = '';
  var labelsHtml = '';
  Object.keys(LOMBOK_MAP.regions).forEach(function(rk) {
    var rg = LOMBOK_MAP.regions[rk];
    if (rg.circles) {
      var circles = rg.circles.map(function(c) {
        return '<circle cx="' + c[0] + '" cy="' + c[1] + '" r="' + c[2] + '"/>';
      }).join('');
      regionsHtml += '<g class="lmap-region lmap-region--gili" data-region="' + rk + '">'
        + '<rect x="' + rg.bbox[0] + '" y="' + rg.bbox[1] + '" width="' + rg.bbox[2] + '" height="' + rg.bbox[3] + '" fill="transparent" stroke="none"/>'
        + circles + '</g>';
    } else {
      regionsHtml += '<path class="lmap-region" data-region="' + rk + '" d="' + rg.d + '"/>';
    }
    labelsHtml += '<g class="lmap-rlabel" data-region="' + rk + '" transform="translate(' + rg.label[0] + ',' + rg.label[1] + ')">'
      + '<text class="lmap-rlabel-name" text-anchor="middle">' + regionLabel(rk) + '</text>'
      + '<text class="lmap-rlabel-count" text-anchor="middle" dy="17"></text>'
      + '</g>';
  });

  // Diagonal-up-right (and other) label placement, shared by area + place dots.
  function labelAttrs(lp) {
    var lx = 9, ly = 3.5, anchor = 'start', rot = '';
    if (lp === 'left') { lx = -9; anchor = 'end'; }
    else if (lp === 'top') { lx = 0; ly = -10; anchor = 'middle'; }
    else if (lp === 'bottom') { lx = 0; ly = 16; anchor = 'middle'; }
    else if (lp === 'diag') { lx = 7; ly = -7; rot = ' transform="rotate(-34)"'; }
    else if (lp === 'diag2') { lx = 20; ly = -16; rot = ' transform="rotate(-34)"'; }
    return { lx: lx, ly: ly, anchor: anchor, rot: rot };
  }

  var areasHtml = '';
  Object.keys(LOMBOK_MAP.areas).forEach(function(ak) {
    var a = LOMBOK_MAP.areas[ak], la = labelAttrs(a.lp);
    areasHtml += '<g class="lmap-amark" data-area="' + ak + '" data-region="' + a.r + '" transform="translate(' + a.p[0] + ',' + a.p[1] + ')">'
      + '<circle class="lmap-amark-hit" r="13"/>'
      + '<circle class="lmap-amark-dot" r="3.5"/>'
      + '<text class="lmap-amark-label" x="' + la.lx + '" y="' + la.ly + '" text-anchor="' + la.anchor + '"' + la.rot + '>' + areaLabel(ak) + '</text>'
      + '</g>';
  });

  // Place dots — finer localities, shown at cluster zoom (docs/adr/0010 + 0011)
  var placesHtml = '';
  Object.keys(LOMBOK_MAP.places || {}).forEach(function(pk) {
    var pl = LOMBOK_MAP.places[pk], la = labelAttrs(pl.lp);
    placesHtml += '<g class="lmap-place" data-place="' + pk + '" data-area="' + pl.a + '" transform="translate(' + pl.p[0] + ',' + pl.p[1] + ')">'
      + '<circle class="lmap-place-hit" r="11"/>'
      + '<circle class="lmap-place-dot" r="2.6"/>'
      + '<text class="lmap-place-label" x="' + la.lx + '" y="' + la.ly + '" text-anchor="' + la.anchor + '"' + la.rot + '>' + placeLabel(pk) + '</text>'
      + '</g>';
  });

  // Cluster zones — clipped coastal strips with a border that highlights on
  // hover, like the main regions (docs/adr/0011). Diagonal name+count label.
  var zonesHtml = '', zlabelsHtml = '';
  Object.keys(LOMBOK_MAP.regions).forEach(function(rk) {
    var rg = LOMBOK_MAP.regions[rk];
    if (!rg.clusters) return;
    Object.keys(rg.clusters).forEach(function(ck) {
      var cl = rg.clusters[ck];
      zonesHtml += '<path class="lmap-czone" data-cluster="' + ck + '" data-region="' + rk + '" d="' + cl.d + '"/>';
      zlabelsHtml += '<text class="lmap-czlabel" data-cluster="' + ck + '" data-region="' + rk + '"'
        + ' x="' + cl.label[0] + '" y="' + cl.label[1] + '" text-anchor="start"'
        + ' transform="rotate(-32 ' + cl.label[0] + ' ' + cl.label[1] + ')"></text>';
    });
  });

  wrapEl.innerHTML =
    '<svg class="lmap" viewBox="' + VB.join(' ') + '" xmlns="http://www.w3.org/2000/svg" role="group" aria-label="' + t('map.aria', 'Lombok region map filter') + '">'
    // AI-painted terrain relief, aligned to the real OSM coastline geometry
    + '<image class="lmap-terrain-img" href="' + LOMBOK_MAP.terrain + '" x="0" y="0" width="880" height="640" preserveAspectRatio="xMidYMid slice"/>'
    + '<path class="lmap-outline" d="' + LOMBOK_MAP.outline + '"/>'
    + '<g class="lmap-regions">' + regionsHtml + '</g>'
    + '<g class="lmap-labels">' + labelsHtml + '</g>'
    + '<g class="lmap-czones">' + zonesHtml + '</g>'
    + '<g class="lmap-czlabels">' + zlabelsHtml + '</g>'
    + '<g class="lmap-places">' + placesHtml + '</g>'
    + '<g class="lmap-areas">' + areasHtml + '</g>'
    + '</svg>';

  var svg = wrapEl.querySelector('svg.lmap');

  // ---- viewBox zoom animation ----
  function fitBBox(b) {
    var pad = 0.16;
    var x = b[0] - b[2] * pad / 2, y = b[1] - b[3] * pad / 2;
    var w = b[2] * (1 + pad), h = b[3] * (1 + pad);
    var aspect = VB[2] / VB[3];
    if (w / h > aspect) { var nh = w / aspect; y -= (nh - h) / 2; h = nh; }
    else { var nw = h * aspect; x -= (nw - w) / 2; w = nw; }
    return [x, y, w, h];
  }
  function animateTo(target) {
    if (animFrame) cancelAnimationFrame(animFrame);
    var from = svg.getAttribute('viewBox').split(/[ ,]+/).map(Number);
    var start = null, dur = 650;
    function ease(p) { return p < 0.5 ? 4 * p * p * p : 1 - Math.pow(-2 * p + 2, 3) / 2; }
    function tick(ts) {
      if (!svg.isConnected) {
        // View not attached yet (initial route render) — snap to the end state
        svg.setAttribute('viewBox', target.join(' '));
        return;
      }
      if (start === null) start = ts;
      var p = Math.min(1, (ts - start) / dur);
      var e = ease(p);
      var cur = from.map(function(v, i) { return v + (target[i] - v) * e; });
      svg.setAttribute('viewBox', cur.join(' '));
      if (p < 1) animFrame = requestAnimationFrame(tick);
    }
    animFrame = requestAnimationFrame(tick);
  }

  // Size a pill's background rect to its label text (SVG user units). getBBox
  // returns 0 before the SVG is laid out (initial render / collapsed panel) —
  // retry once on the next frame so the pill isn't left at its default width.
  function sizePill(n, _retry) {
    var txt = n.querySelector('.lmap-cluster-name');
    var bg = n.querySelector('.lmap-cluster-bg');
    if (!txt || !bg) return;
    var b;
    try { b = txt.getBBox(); } catch (e) { return; }
    if (!b.width) {
      if (!_retry) requestAnimationFrame(function() { sizePill(n, true); });
      return;
    }
    var px = 14, py = 8;
    bg.setAttribute('x', (b.x - px).toFixed(1));
    bg.setAttribute('y', (b.y - py).toFixed(1));
    bg.setAttribute('width', (b.width + px * 2).toFixed(1));
    bg.setAttribute('height', (b.height + py * 2).toFixed(1));
    bg.setAttribute('rx', ((b.height + py * 2) / 2).toFixed(1));
  }

  // ---- state rendering ----
  function applySelection() {
    var clustered = regionHasClusters(selRegion);
    svg.classList.toggle('lmap--zoomed', !!selRegion);
    svg.querySelectorAll('.lmap-region').forEach(function(n) {
      n.classList.toggle('sel', n.getAttribute('data-region') === selRegion);
    });
    svg.querySelectorAll('.lmap-rlabel').forEach(function(n) {
      n.classList.toggle('sel', n.getAttribute('data-region') === selRegion);
    });
    // Cluster pills: shown when their region is selected but no cluster picked yet
    svg.querySelectorAll('.lmap-cluster').forEach(function(n) {
      var show = clustered && !selCluster && n.getAttribute('data-region') === selRegion;
      n.classList.toggle('visible', !!show);
    });
    // Area markers: shown for the selected cluster, or for a non-clustered region
    svg.querySelectorAll('.lmap-amark').forEach(function(n) {
      var ak = n.getAttribute('data-area'), ark = n.getAttribute('data-region'), show = false;
      if (selCluster && clustered) {
        var cl = LOMBOK_MAP.regions[selRegion].clusters[selCluster];
        show = !!(cl && cl.areas.indexOf(ak) >= 0);
      } else if (selRegion && !clustered) {
        show = ark === selRegion;
      }
      n.classList.toggle('visible', show);
      n.classList.toggle('active', ak === selArea);
    });
    // Zoom target: cluster box → cluster-overview box (frames the pills) →
    // region box → whole island.
    var target, rg = LOMBOK_MAP.regions[selRegion];
    if (selCluster && clustered && rg.clusters[selCluster]) {
      target = fitBBox(rg.clusters[selCluster].bbox);
    } else if (selRegion && clustered && rg.clusterBox) {
      target = fitBBox(rg.clusterBox);
    } else if (selRegion && rg) {
      target = fitBBox(rg.bbox);
    } else {
      target = VB.slice();
    }
    animateTo(target);
  }

  function applyCounts() {
    Object.keys(LOMBOK_MAP.regions).forEach(function(rk) {
      var n = counts.regions[rk] || 0;
      var empty = n === 0;
      svg.querySelectorAll('[data-region="' + rk + '"]').forEach(function(node) {
        node.classList.toggle('lmap-region--empty', empty && node.classList.contains('lmap-region'));
      });
      var lbl = svg.querySelector('.lmap-rlabel[data-region="' + rk + '"]');
      if (lbl) {
        lbl.classList.toggle('empty', empty);
        lbl.querySelector('.lmap-rlabel-count').textContent = empty
          ? t('map.no_listings', 'No listings yet')
          : n + ' ' + (n === 1 ? t('map.property', 'property') : t('map.properties', 'properties'));
      }
    });
    Object.keys(LOMBOK_MAP.areas).forEach(function(ak) {
      var mark = svg.querySelector('.lmap-amark[data-area="' + ak + '"]');
      if (!mark) return;
      var n = counts.areas[ak] || 0;
      mark.classList.toggle('empty', n === 0);
      var txt = mark.querySelector('.lmap-amark-label');
      var base = areaLabel(ak);
      txt.textContent = n > 0 ? base + ' · ' + n : base;
    });
    // Cluster counts = sum of member Area counts (client-side, docs/adr/0011)
    svg.querySelectorAll('.lmap-cluster').forEach(function(n) {
      var rk = n.getAttribute('data-region'), ck = n.getAttribute('data-cluster');
      var cl = LOMBOK_MAP.regions[rk].clusters[ck];
      var sum = cl.areas.reduce(function(s, ak) { return s + (counts.areas[ak] || 0); }, 0);
      var empty = sum === 0;
      n.classList.toggle('lmap-cluster--empty', empty);
      n.querySelector('.lmap-cluster-name').textContent = empty
        ? clusterLabel(rk, ck)
        : clusterLabel(rk, ck) + '  ·  ' + sum;
      sizePill(n);
    });
  }

  // ---- events ----
  // Click in SVG user coordinates (independent of zoom/pan).
  function clickToSvg(e) {
    var m = svg.getScreenCTM();
    if (!m) return null;
    var pt = svg.createSVGPoint();
    pt.x = e.clientX; pt.y = e.clientY;
    var p = pt.matrixTransform(m.inverse());
    return { x: p.x, y: p.y };
  }
  // Pick the visible Area marker nearest the click — robust when dense south-
  // coast markers overlap (label/hit-area collisions picked the wrong one).
  function nearestMarker(sp) {
    if (!sp) return null;
    var vb = svg.getAttribute('viewBox').split(/[ ,]+/).map(Number);
    var thresh = vb[2] * 0.085; // ~constant tap radius in screen px across zooms
    var best = null, bestD = thresh * thresh;
    svg.querySelectorAll('.lmap-amark.visible').forEach(function(mk) {
      var a = LOMBOK_MAP.areas[mk.getAttribute('data-area')];
      if (!a) return;
      var dx = a.p[0] - sp.x, dy = a.p[1] - sp.y, d = dx * dx + dy * dy;
      if (d < bestD) { bestD = d; best = mk; }
    });
    return best;
  }

  svg.addEventListener('click', function(e) {
    var pill = e.target.closest('.lmap-cluster');
    if (pill && pill.classList.contains('visible') && !pill.classList.contains('lmap-cluster--empty')) {
      if (opts.onSelect) opts.onSelect({ region: pill.getAttribute('data-region'), cluster: pill.getAttribute('data-cluster'), area: '' });
      return;
    }
    // Nearest-marker wins over raw hit-testing so overlapping dots/labels still
    // select the intended Area.
    var amark = nearestMarker(clickToSvg(e)) || (function() {
      var hit = e.target.closest('.lmap-amark');
      return hit && hit.classList.contains('visible') ? hit : null;
    })();
    if (amark) {
      var ak = amark.getAttribute('data-area'), ark = amark.getAttribute('data-region');
      if (opts.onSelect) opts.onSelect({ region: ark, cluster: clusterOfArea(ark, ak), area: ak === selArea ? '' : ak });
      return;
    }
    var region = e.target.closest('.lmap-region');
    if (region && !region.classList.contains('lmap-region--empty')) {
      var rk = region.getAttribute('data-region');
      var deselect = rk === selRegion && !selCluster && !selArea;
      if (opts.onSelect) opts.onSelect(deselect ? { region: '', cluster: '', area: '' } : { region: rk, cluster: '', area: '' });
    }
  });

  applySelection();

  return {
    setCounts: function(c) {
      counts.regions = (c && c.regions) || {};
      counts.areas = (c && c.areas) || {};
      counts.places = (c && c.places) || {};
      applyCounts();
    },
    relayout: function() { applyCounts(); },
    setSelection: function(region, cluster, area) {
      selRegion = region || '';
      selCluster = cluster || '';
      selArea = area || '';
      applySelection();
    }
  };
}

// =====================================================
// RENDER: LISTINGS (Find Land / Property)
// Dynamic filter engine: filter changes re-render the grid
// in place (no page re-render); state mirrored to the URL.
// =====================================================

// Per-type filter matrix — which filters make sense for which listing type.
// Hidden filters are CLEARED from the active query, never silently applied.
const LISTING_FILTER_MATRIX = {
  land:             { beds: false, baths: false, building: false },
  villa:            { beds: true,  baths: true,  building: true  },
  house:            { beds: true,  baths: true,  building: true  },
  apartment:        { beds: true,  baths: true,  building: true  },
  long_term_rental: { beds: true,  baths: true,  building: true  },
  commercial:       { beds: false, baths: false, building: true  },
  warehouse:        { beds: false, baths: false, building: true  }
};
function listingTypeMatrix(type) {
  return LISTING_FILTER_MATRIX[type] || { beds: true, baths: true, building: true };
}
function visibleFeatureTags(type) {
  return (FilterData.feature_tags || []).filter(function(ft) {
    if (!type) return true;
    var ap = ft.applies_to || 'all';
    return ap === 'all' || (',' + ap + ',').indexOf(',' + type + ',') >= 0;
  });
}

// Price presets per Display Currency ([min, max] in that currency; 0 = open)
const PRICE_PRESETS = {
  IDR: [[0, 5e8], [5e8, 1e9], [1e9, 3e9], [3e9, 5e9], [5e9, 1e10], [1e10, 0]],
  USD: [[0, 5e4], [5e4, 1e5], [1e5, 25e4], [25e4, 5e5], [5e5, 1e6], [1e6, 0]],
  EUR: [[0, 5e4], [5e4, 1e5], [1e5, 25e4], [25e4, 5e5], [5e5, 1e6], [1e6, 0]],
  AUD: [[0, 75e3], [75e3, 15e4], [15e4, 4e5], [4e5, 75e4], [75e4, 15e5], [15e5, 0]]
};
function pricePresetLabel(min, max, cur) {
  if (!min) return t('filter.under', 'Under') + ' ' + Currency.format(max, cur);
  if (!max) return Currency.format(min, cur) + '+';
  return Currency.format(min, cur) + ' – ' + Currency.format(max, cur);
}

async function renderListings(el, params = {}) {
  await FilterData.load();

  // ---- state (parsed from URL params, incl. legacy param names) ----
  // Location is a Region → Cluster → Area → Place drill-down (docs/adr/0011);
  // Cluster is map-only, Area/Place are real filters.
  var state = {
    type: params.listing_type || '',
    region: '', cluster: '', area: '', place: params.place || '',
    price: params.price || '',          // "CUR:min-max" in display currency
    size: params.size || '',
    bsize: params.bsize || '',
    cert: params.certificate_type || '',
    beds: params.min_beds || '',
    baths: params.min_baths || '',
    tags: params.tags ? params.tags.split(',').filter(Boolean) : [],
    sort: params.sort || '',
    q: params.q || '',
    agent_id: params.agent_id || '',
    page: parseInt(params.page, 10) || 1
  };
  var areaParam = params.area || '';
  if (areaParam.indexOf('cluster:') === 0) {
    var cparts = areaParam.slice(8).split(':');
    state.region = cparts[0] || ''; state.cluster = cparts[1] || '';
  } else if (areaParam.indexOf('region:') === 0) {
    state.region = areaParam.slice(7);
  } else if (params.region) {
    state.region = params.region;
  } else {
    state.area = areaParam;
  }
  // Legacy deep links (pre-dynamic-engine URLs)
  if (!state.price && (params.min_price_idr || params.max_price_idr)) {
    state.price = 'IDR:' + (params.min_price_idr || 0) + '-' + (params.max_price_idr || 0);
  }
  if (!state.size && (params.min_size || params.max_size)) {
    state.size = (params.min_size || 0) + '-' + (params.max_size || 0);
  }

  var lastItems = [];
  var lastMeta = { total: 0, page: 1, per_page: 20, total_pages: 1 };
  var lastCounts = { regions: {}, areas: {}, places: {} };
  var map = null;
  var isMobile = function() { return window.matchMedia('(max-width: 1023px)').matches; };

  function areaRegionOf(areaKey) {
    var la = LOMBOK_MAP.areas[areaKey];
    if (la) return la.r;
    var row = (FilterData.areas || []).find(function(a) { return a.key === areaKey; });
    return row ? (row.region_key || '') : '';
  }
  function clusterOfArea(rk, ak) {
    var r = LOMBOK_MAP.regions[rk];
    if (!r || !r.clusters) return '';
    for (var ck in r.clusters) { if (r.clusters[ck].areas.indexOf(ak) >= 0) return ck; }
    return '';
  }
  function placeAreaOf(pk) {
    var row = (FilterData.places || []).find(function(p) { return p.place_key === pk; });
    return row ? row.area_key : '';
  }
  function placesForArea(ak) {
    return (FilterData.places || []).filter(function(p) { return p.area_key === ak; });
  }
  // Fill region/cluster from the deepest known location so the map zooms right.
  function normalizeLocation() {
    if (state.place && !state.area) state.area = placeAreaOf(state.place);
    if (state.area) {
      state.region = areaRegionOf(state.area) || state.region;
      state.cluster = clusterOfArea(state.region, state.area);
    }
  }
  normalizeLocation();

  function clusterMembers(rk, ck) {
    var r = LOMBOK_MAP.regions[rk];
    return (r && r.clusters && r.clusters[ck]) ? r.clusters[ck].areas : [];
  }
  function clusterDisplayLabel(rk, ck) {
    var r = LOMBOK_MAP.regions[rk];
    if (!r || !r.clusters || !r.clusters[ck]) return ck;
    var cl = r.clusters[ck];
    return (CURRENT_LANG === 'id' && cl.label_id) ? cl.label_id : cl.label;
  }
  function regionDisplayLabel(rk) {
    var row = (FilterData.regions || []).find(function(r) { return r.region_key === rk; });
    return row ? lookupLabel(row) : rk.replace(/_/g, ' ');
  }
  function areaDisplayLabel(ak) {
    var row = (FilterData.areas || []).find(function(a) { return a.key === ak; });
    return row ? lookupLabel(row) : ak.replace(/_/g, ' ');
  }
  function placeDisplayLabel(pk) {
    var row = (FilterData.places || []).find(function(p) { return p.place_key === pk; });
    return row ? lookupLabel(row) : pk.replace(/_/g, ' ');
  }

  // ---- option builders ----
  function typeOptionsHtml() {
    return FilterData.listing_types.map(function(o) {
      return '<option value="' + o.key + '"' + (state.type === o.key ? ' selected' : '') + '>' + lookupLabel(o) + '</option>';
    }).join('');
  }
  function certOptionsHtml() {
    return FilterData.land_certificate_types.map(function(o) {
      return '<option value="' + o.key + '"' + (state.cert === o.key ? ' selected' : '') + '>' + lookupLabel(o) + '</option>';
    }).join('');
  }
  function priceOptionsHtml() {
    var cur = Currency.get();
    var presets = PRICE_PRESETS[cur] || PRICE_PRESETS.USD;
    var matched = false;
    var html = '<option value="">' + t('filter.any_price', 'Any price') + '</option>';
    presets.forEach(function(p) {
      var token = cur + ':' + p[0] + '-' + p[1];
      var sel = state.price === token;
      if (sel) matched = true;
      html += '<option value="' + token + '"' + (sel ? ' selected' : '') + '>' + pricePresetLabel(p[0], p[1], cur) + '</option>';
    });
    // Active filter set in another currency → show it converted, keep it applied
    if (state.price && !matched) {
      var pr = parsePriceToken(state.price);
      if (pr) {
        var lbl = pricePresetLabel(
          pr.min ? Math.round(Currency.convert(pr.min, pr.cur, cur)) : 0,
          pr.max ? Math.round(Currency.convert(pr.max, pr.cur, cur)) : 0,
          cur
        );
        html += '<option value="' + state.price + '" selected>' + lbl + '</option>';
      }
    }
    return html;
  }
  function parsePriceToken(token) {
    var m = /^([A-Z]{3}):(\d+)-(\d+)$/.exec(token || '');
    if (!m) return null;
    return { cur: m[1], min: parseInt(m[2], 10), max: parseInt(m[3], 10) };
  }
  function sizeOptionsHtml(presets, sel, unit) {
    var html = '<option value="">' + t('filter.any_size', 'Any size') + '</option>';
    presets.forEach(function(p) {
      var v = p[0] + '-' + p[1];
      var lbl;
      if (!p[0]) lbl = t('filter.under', 'Under') + ' ' + p[1].toLocaleString() + ' ' + unit;
      else if (!p[1]) lbl = p[0].toLocaleString() + '+ ' + unit;
      else lbl = p[0].toLocaleString() + ' – ' + p[1].toLocaleString() + ' ' + unit;
      html += '<option value="' + v + '"' + (sel === v ? ' selected' : '') + '>' + lbl + '</option>';
    });
    return html;
  }
  function tagChipsHtml() {
    return visibleFeatureTags(state.type).map(function(ft) {
      var on = state.tags.indexOf(ft.key) >= 0;
      return '<button type="button" class="filter-tag' + (on ? ' active' : '') + '" data-tag="' + ft.key + '">' + lookupLabel(ft) + '</button>';
    }).join('');
  }
  function currencyPillsHtml() {
    var cur = Currency.get();
    return Currency.LIST.map(function(c) {
      return '<button type="button" class="cur-pill' + (c === cur ? ' active' : '') + '" data-cur="' + c + '" aria-pressed="' + (c === cur) + '">' + c + '</button>';
    }).join('');
  }
  function selectionSummary() {
    if (state.place) return placeDisplayLabel(state.place);
    if (state.area) return areaDisplayLabel(state.area);
    if (state.cluster) return clusterDisplayLabel(state.region, state.cluster);
    if (state.region) return regionDisplayLabel(state.region);
    return t('map.all_lombok', 'All Lombok');
  }

  // ---- URL + API param building ----
  function urlParams() {
    var p = {};
    if (state.type) p.listing_type = state.type;
    // Location encodes the deepest level; place keeps area so the map re-zooms.
    if (state.place) { p.area = state.area; p.place = state.place; }
    else if (state.area) p.area = state.area;
    else if (state.cluster) p.area = 'cluster:' + state.region + ':' + state.cluster;
    else if (state.region) p.area = 'region:' + state.region;
    if (state.price) p.price = state.price;
    if (state.size) p.size = state.size;
    if (state.bsize) p.bsize = state.bsize;
    if (state.cert) p.certificate_type = state.cert;
    if (state.beds) p.min_beds = state.beds;
    if (state.baths) p.min_baths = state.baths;
    if (state.tags.length) p.tags = state.tags.join(',');
    if (state.sort) p.sort = state.sort;
    if (state.q) p.q = state.q;
    if (state.agent_id) p.agent_id = state.agent_id;
    if (state.page > 1) p.page = state.page;
    return p;
  }
  function apiParams(skipLocation) {
    var p = {};
    if (state.type) p.listing_type = state.type;
    if (!skipLocation) {
      // Place narrows exactly; Area = single; Cluster = comma IN-list of member
      // Areas (docs/adr/0011); Region = whole region.
      if (state.place) p.place = state.place;
      else if (state.area) p.area = state.area;
      else if (state.cluster) p.area = clusterMembers(state.region, state.cluster).join(',');
      else if (state.region) p.region = state.region;
    }
    var pr = parsePriceToken(state.price);
    if (pr) {
      // Canonical-IDR filtering (docs/adr/0006): bounds convert to IDR
      var rate = Currency.rate(pr.cur, 'IDR');
      if (pr.min) p.min_price_idr = Math.round(pr.min * rate);
      if (pr.max) p.max_price_idr = Math.round(pr.max * rate);
    }
    if (state.size) {
      var sp = state.size.split('-');
      if (sp[0] && sp[0] !== '0') p.min_size = sp[0];
      if (sp[1] && sp[1] !== '0') p.max_size = sp[1];
    }
    var matrix = listingTypeMatrix(state.type);
    if (state.bsize && matrix.building) {
      var bp = state.bsize.split('-');
      if (bp[0] && bp[0] !== '0') p.min_building_size = bp[0];
      if (bp[1] && bp[1] !== '0') p.max_building_size = bp[1];
    }
    if (state.cert) p.certificate_type = state.cert;
    if (state.beds && matrix.beds) p.min_beds = state.beds;
    if (state.baths && matrix.baths) p.min_baths = state.baths;
    if (state.tags.length) p.tags = state.tags.join(',');
    if (state.q) p.q = state.q;
    if (state.agent_id) p.agent_id = state.agent_id;
    if (state.sort === 'price_asc') { p.sort = 'price_idr'; p.dir = 'ASC'; }
    else if (state.sort === 'price_desc') { p.sort = 'price_idr'; p.dir = 'DESC'; }
    else if (state.sort === 'size_desc') { p.sort = 'land_size_sqm'; p.dir = 'DESC'; }
    else { p.sort = 'created_at'; p.dir = 'DESC'; }
    if (state.page > 1) p.page = state.page;
    return p;
  }
  function syncUrl() {
    var hash = '#' + buildHash('listings', urlParams());
    if (window.location.hash !== hash) {
      history.replaceState(null, '', hash);
      if (typeof currentRoute === 'object' && currentRoute) currentRoute.params = urlParams();
    }
  }

  // ---- shell ----
  el.innerHTML = `
    <div class="dir-hero">
      <div class="container">
        <h1 class="dir-hero-title">${t('listings.hero_title', 'Find Land & Property')}</h1>
        <p class="dir-hero-desc">${t('listings.hero_desc', 'Discover your dream location across Lombok — land, villas, and investment properties.')}</p>
      </div>
    </div>
    <div class="section">
      <div class="container">
        <div class="listings-layout" id="listings-layout">
        <div class="split-view-map split-view-map--interactive lst-map" aria-label="${t('map.aria', 'Lombok region map filter')}">
          <div class="lmap-panel" id="lmap-panel">
            <button type="button" class="lmap-toggle" id="lmap-toggle" aria-expanded="false" aria-controls="lmap-body">
              <svg class="lmap-toggle-icon" viewBox="0 0 880 640" aria-hidden="true"><path d="${LOMBOK_MAP.outline}"/></svg>
              <span class="lmap-toggle-text">
                <span class="lmap-toggle-title">${t('map.explore', 'Explore by Map')}</span>
                <span class="lmap-toggle-sub" id="lmap-toggle-sub"></span>
              </span>
              <span class="lmap-toggle-clear" id="lmap-toggle-clear" hidden aria-label="${t('map.clear', 'Clear location')}">&times;</span>
              <svg class="lmap-toggle-chevron" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><polyline points="6 9 12 15 18 9"/></svg>
            </button>
            <div class="lmap-body" id="lmap-body">
              <div class="lmap-svg-wrap" id="lmap-svg-wrap"></div>
              <button type="button" class="lmap-reset" id="lmap-reset" hidden>&larr; ${t('map.all_lombok', 'All Lombok')}</button>
            </div>
          </div>
        </div>
        <div class="filters-bar lst-filters">
          <div class="filters-body open">
            <div class="filters-grid filters-grid--4">
              <div class="filter-group">
                <label class="filter-label" for="fil-area">${t('filter.area', 'Area')}</label>
                <select id="fil-area" class="filter-select" aria-label="${t('filter.area', 'Area')}">
                  <option value="">${t('filter.all_areas', 'All areas')}</option>
                  ${buildAreaOptions(state.area || (state.region ? 'region:' + state.region : ''))}
                </select>
              </div>
              <div class="filter-group">
                <label class="filter-label" for="fil-type">${t('filter.type', 'Type')}</label>
                <select id="fil-type" class="filter-select" aria-label="${t('filter.type', 'Type')}">
                  <option value="">${t('filter.all_types', 'All types')}</option>
                  ${typeOptionsHtml()}
                </select>
              </div>
              <div class="filter-group">
                <label class="filter-label" for="fil-price">${t('filter.price', 'Price')} <span class="filter-label-cur" id="fil-price-cur">(${Currency.get()})</span></label>
                <select id="fil-price" class="filter-select" aria-label="${t('filter.price', 'Price')}">
                  ${priceOptionsHtml()}
                </select>
              </div>
              <div class="filter-group">
                <label class="filter-label" for="fil-size">${t('filter.land_size', 'Land Size')}</label>
                <select id="fil-size" class="filter-select" aria-label="${t('filter.land_size', 'Land Size')}">
                  ${sizeOptionsHtml([[0, 100], [100, 500], [500, 1000], [1000, 5000], [5000, 10000], [10000, 0]], state.size, 'm²')}
                </select>
              </div>
            </div>
            <div class="filters-more-row">
              <button class="filters-more-btn" id="moreFiltersBtn" type="button">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><line x1="4" y1="6" x2="20" y2="6"/><line x1="8" y1="12" x2="16" y2="12"/><line x1="11" y1="18" x2="13" y2="18"/></svg>
                <span id="moreFiltersLabel">${t('filter.more_filters', 'More Filters')}</span>
              </button>
              <button class="btn btn--ghost btn--sm" type="button" id="clearAllFiltersBtn" style="margin-left:auto;color:var(--color-accent);" hidden>${t('filter.clear_all', 'Clear all')}</button>
            </div>
            <div class="filters-more-panel" id="moreFiltersPanel">
              <div class="filters-grid filters-grid--4">
                <div class="filter-group" id="fg-cert">
                  <label class="filter-label" for="fil-cert">${t('filter.certificate', 'Certificate')}</label>
                  <select id="fil-cert" class="filter-select" aria-label="${t('filter.certificate', 'Certificate')}">
                    <option value="">${t('filter.any_certificate', 'Any certificate')}</option>
                    ${certOptionsHtml()}
                  </select>
                </div>
                <div class="filter-group" id="fg-beds">
                  <label class="filter-label" for="fil-beds">${t('filter.bedrooms', 'Bedrooms')}</label>
                  <select id="fil-beds" class="filter-select" aria-label="${t('filter.bedrooms', 'Bedrooms')}">
                    <option value="">${t('filter.any', 'Any')}</option>
                    ${[1, 2, 3, 4, 5].map(function(n) { return '<option value="' + n + '"' + (state.beds === String(n) ? ' selected' : '') + '>' + n + '+</option>'; }).join('')}
                  </select>
                </div>
                <div class="filter-group" id="fg-baths">
                  <label class="filter-label" for="fil-baths">${t('filter.bathrooms', 'Bathrooms')}</label>
                  <select id="fil-baths" class="filter-select" aria-label="${t('filter.bathrooms', 'Bathrooms')}">
                    <option value="">${t('filter.any', 'Any')}</option>
                    ${[1, 2, 3, 4].map(function(n) { return '<option value="' + n + '"' + (state.baths === String(n) ? ' selected' : '') + '>' + n + '+</option>'; }).join('')}
                  </select>
                </div>
                <div class="filter-group" id="fg-building">
                  <label class="filter-label" for="fil-bsize">${t('filter.building_size', 'Building Size')}</label>
                  <select id="fil-bsize" class="filter-select" aria-label="${t('filter.building_size', 'Building Size')}">
                    ${sizeOptionsHtml([[0, 100], [100, 200], [200, 400], [400, 0]], state.bsize, 'm²')}
                  </select>
                </div>
              </div>
              <div class="filter-group" style="margin-top:var(--space-3)">
                <label class="filter-label">${t('filter.features', 'Features')}</label>
                <div class="filter-tags" id="fil-tags">${tagChipsHtml()}</div>
              </div>
            </div>
          </div>
        </div>

        <div class="listings-toolbar lst-toolbar">
          <div class="listings-count" id="lst-count" aria-live="polite"></div>
          <div class="listings-toolbar-right">
            <div class="cur-pills" id="cur-pills" role="group" aria-label="${t('currency.label', 'Display currency')}">${currencyPillsHtml()}</div>
            <select id="fil-sort" class="filter-select filter-select--sort" aria-label="${t('sort.label', 'Sort by')}">
              <option value="">${t('sort.newest', 'Newest')}</option>
              <option value="price_asc"${state.sort === 'price_asc' ? ' selected' : ''}>${t('sort.price_asc', 'Price: low to high')}</option>
              <option value="price_desc"${state.sort === 'price_desc' ? ' selected' : ''}>${t('sort.price_desc', 'Price: high to low')}</option>
              <option value="size_desc"${state.sort === 'size_desc' ? ' selected' : ''}>${t('sort.size_desc', 'Largest land')}</option>
            </select>
          </div>
        </div>

        <div class="place-chips lst-places" id="place-chips" hidden></div>
        <div class="split-view-list card-grid listings-grid lst-grid" id="listings-grid"></div>
        <div class="listings-pagination lst-pagination" id="lst-pagination"></div>
        </div>
      </div>
    </div>

    ${UserAuth.user ? `
    <div class="section" style="padding-top:0">
      <div class="container container--narrow" style="text-align:center">
        <div class="help-cta">
          <h3 class="help-cta-title">${t('listings.agent_cta_title', 'Are you an agent?')}</h3>
          <p class="help-cta-desc">${t('listings.agent_cta_desc', 'List your properties on Build in Lombok and reach foreign investors.')}</p>
          <button onclick="navigate('create-listing')" class="btn btn--primary">${t('listings.agent_cta_btn', 'Post a Listing')}</button>
        </div>
      </div>
    </div>
    ` : ''}
  `;

  var grid = el.querySelector('#listings-grid');
  var countEl = el.querySelector('#lst-count');
  var pagEl = el.querySelector('#lst-pagination');

  // Cache filter-bar nodes once: the mobile filter drawer re-parents the
  // filters out of `el` while open, so live el.querySelector would miss them.
  var nodes = {
    areaSel: el.querySelector('#fil-area'),
    priceSel: el.querySelector('#fil-price'),
    priceCur: el.querySelector('#fil-price-cur'),
    fgBeds: el.querySelector('#fg-beds'),
    fgBaths: el.querySelector('#fg-baths'),
    fgBuilding: el.querySelector('#fg-building'),
    tagWrap: el.querySelector('#fil-tags'),
    clearBtn: el.querySelector('#clearAllFiltersBtn'),
    moreLbl: el.querySelector('#moreFiltersLabel'),
    resetBtn: el.querySelector('#lmap-reset'),
    toggleSub: el.querySelector('#lmap-toggle-sub'),
    toggleClear: el.querySelector('#lmap-toggle-clear'),
    placeChips: el.querySelector('#place-chips')
  };

  // ---- map ----
  map = createLombokMap(el.querySelector('#lmap-svg-wrap'), {
    onSelect: function(sel) {
      // A map pick replaces the whole location selection (and any Place).
      state.region = sel.region || '';
      state.cluster = sel.cluster || '';
      state.area = sel.area || '';
      state.place = '';
      state.page = 1;
      syncAreaDropdown();
      // Picking a final Area on mobile: keep the map open, just scroll down to
      // the results so it's obvious the filter applied (don't collapse it).
      refresh(sel.area && isMobile());
    }
  });

  // The Area <select> can't express a Cluster — map it to the parent Region.
  function syncAreaDropdown() {
    if (!nodes.areaSel) return;
    nodes.areaSel.value = state.area ? state.area
      : (state.region ? 'region:' + state.region : '');
  }

  function hasLocation() {
    return !!(state.region || state.cluster || state.area || state.place);
  }

  // Context-aware back: one level up, broadening the filter (docs/adr/0011).
  function backTarget() {
    if (state.place) return { label: areaDisplayLabel(state.area) };
    if (state.area) return { label: state.cluster ? clusterDisplayLabel(state.region, state.cluster) : regionDisplayLabel(state.region) };
    if (state.cluster) return { label: regionDisplayLabel(state.region) };
    if (state.region) return { label: t('map.all_lombok', 'All Lombok') };
    return null;
  }
  function goBack() {
    if (state.place) state.place = '';
    else if (state.area) state.area = '';
    else if (state.cluster) state.cluster = '';
    else if (state.region) state.region = '';
    state.page = 1;
    syncAreaDropdown();
    refresh();
  }
  function clearLocation() {
    state.region = ''; state.cluster = ''; state.area = ''; state.place = '';
    state.page = 1;
    syncAreaDropdown();
    refresh();
  }

  function mapSelection() {
    map.setSelection(state.region, state.cluster, state.area);
    if (nodes.resetBtn) {
      var bt = backTarget();
      nodes.resetBtn.hidden = !bt;
      if (bt) nodes.resetBtn.innerHTML = '&larr; ' + escHtml(bt.label);
    }
    if (nodes.toggleSub) nodes.toggleSub.textContent = selectionSummary();
    if (nodes.toggleClear) nodes.toggleClear.hidden = !hasLocation();
  }

  // ---- Place filter chips (shown when an Area with Places is selected) ----
  function renderPlaceChips() {
    var wrap = nodes.placeChips;
    if (!wrap) return;
    if (!state.area) { wrap.innerHTML = ''; wrap.hidden = true; return; }
    var places = placesForArea(state.area);
    var chips = places.map(function(p) {
      var n = lastCounts.places[p.place_key] || 0;
      if (n === 0 && state.place !== p.place_key) return null; // hide dead chips
      var on = state.place === p.place_key;
      return '<button type="button" class="place-chip' + (on ? ' active' : '') + '" data-place="' + p.place_key + '">'
        + escHtml(lookupLabel(p)) + (n > 0 ? ' <span class="place-chip-n">' + n + '</span>' : '') + '</button>';
    }).filter(Boolean);
    if (!chips.length) { wrap.innerHTML = ''; wrap.hidden = true; return; }
    wrap.hidden = false;
    wrap.innerHTML = '<span class="place-chips-label">' + t('filter.places_in', 'Places in') + ' ' + escHtml(areaDisplayLabel(state.area)) + '</span>' + chips.join('');
  }

  // Mobile collapsible panel
  function setMapOpen(open) {
    var body = el.querySelector('#lmap-body');
    var tog = el.querySelector('#lmap-toggle');
    if (!body || !tog) return;
    body.classList.toggle('open', open);
    tog.setAttribute('aria-expanded', open ? 'true' : 'false');
    tog.classList.toggle('open', open);
    // Pills size via getBBox — re-measure once the panel is actually laid out.
    if (open && map) requestAnimationFrame(function() { map.relayout(); });
  }
  el.querySelector('#lmap-toggle').addEventListener('click', function(e) {
    if (e.target.closest('#lmap-toggle-clear')) { clearLocation(); return; }
    setMapOpen(!el.querySelector('#lmap-body').classList.contains('open'));
  });
  el.querySelector('#lmap-reset').addEventListener('click', goBack);

  // ---- per-type matrix ----
  function applyMatrix() {
    var m = listingTypeMatrix(state.type);
    var changed = false;
    var groups = { beds: nodes.fgBeds, baths: nodes.fgBaths, building: nodes.fgBuilding };
    Object.keys(groups).forEach(function(k) {
      if (!groups[k]) return;
      groups[k].classList.toggle('fg-hidden', !m[k]);
      if (!m[k]) {
        var stateKey = k === 'building' ? 'bsize' : k;
        if (state[stateKey]) { state[stateKey] = ''; changed = true; }
        var sel = groups[k].querySelector('select');
        if (sel) sel.value = '';
      }
    });
    // Rebuild tag chips for this type; drop now-invisible selected tags
    var visible = visibleFeatureTags(state.type).map(function(ft) { return ft.key; });
    var kept = state.tags.filter(function(tg) { return visible.indexOf(tg) >= 0; });
    if (kept.length !== state.tags.length) { state.tags = kept; changed = true; }
    if (nodes.tagWrap) nodes.tagWrap.innerHTML = tagChipsHtml();
    return changed;
  }

  // ---- grid rendering ----
  function renderGrid() {
    grid.innerHTML = lastItems.length > 0
      ? lastItems.map(function(l, i) { return renderListingCard(l, i); }).join('')
      : '<div class="empty-state"><h3 class="empty-state-title">' + t('empty.no_listings_title', 'No listings found') + '</h3><p class="empty-state-desc">' + t('empty.no_listings_desc', 'Try adjusting your filters or check back soon for new properties.') + '</p></div>';

    var total = lastMeta.total || 0;
    var from = total === 0 ? 0 : ((lastMeta.page - 1) * lastMeta.per_page) + 1;
    var to = Math.min(total, lastMeta.page * lastMeta.per_page);
    countEl.textContent = total === 0
      ? t('listings.none_found', 'No properties found')
      : t('listings.showing', 'Showing') + ' ' + from + '–' + to + ' ' + t('listings.of', 'of') + ' ' + total + ' ' + (total === 1 ? t('map.property', 'property') : t('map.properties', 'properties'));

    if ((lastMeta.total_pages || 1) > 1) {
      pagEl.innerHTML =
        '<button type="button" class="pagination-btn" id="pag-prev"' + (lastMeta.page <= 1 ? ' disabled' : '') + '>&larr;</button>'
        + '<span class="pagination-info">' + lastMeta.page + ' / ' + lastMeta.total_pages + '</span>'
        + '<button type="button" class="pagination-btn" id="pag-next"' + (lastMeta.page >= lastMeta.total_pages ? ' disabled' : '') + '>&rarr;</button>';
      var prev = pagEl.querySelector('#pag-prev'), next = pagEl.querySelector('#pag-next');
      if (prev) prev.addEventListener('click', function() { state.page = Math.max(1, state.page - 1); refresh(true); });
      if (next) next.addEventListener('click', function() { state.page = Math.min(lastMeta.total_pages, state.page + 1); refresh(true); });
    } else {
      pagEl.innerHTML = '';
    }

    var anyActive = state.type || state.area || state.region || state.cluster || state.place || state.price || state.size || state.bsize || state.cert || state.beds || state.baths || state.tags.length || state.q;
    if (nodes.clearBtn) nodes.clearBtn.hidden = !anyActive;
    var moreCount = [state.cert, state.beds, state.baths, state.bsize].filter(Boolean).length + state.tags.length;
    if (nodes.moreLbl) nodes.moreLbl.textContent = t('filter.more_filters', 'More Filters') + (moreCount > 0 ? ' (' + moreCount + ')' : '');

    requestAnimationFrame(function() { animateCards(grid); });
  }

  // ---- the dynamic refresh: grid + counts only, no page re-render ----
  var refreshSeq = 0;
  async function refresh(scrollToGrid) {
    applyMatrix();
    syncUrl();
    mapSelection();
    var seq = ++refreshSeq;
    grid.classList.add('grid-loading');
    try {
      var results = await Promise.all([
        DataLayer.getListings(apiParams(false)),
        DataLayer.getListingCounts(apiParams(true)).catch(function() { return null; })
      ]);
      if (seq !== refreshSeq) return; // superseded by a newer refresh
      lastItems = results[0].data;
      lastMeta = results[0].meta || lastMeta;
      renderGrid();
      if (results[1]) {
        lastCounts = {
          regions: results[1].regions || {},
          areas: results[1].areas || {},
          places: results[1].places || {}
        };
        map.setCounts(results[1]);
      }
      renderPlaceChips();
    } catch (e) {
      if (seq !== refreshSeq) return;
      console.error('Listings refresh failed:', e);
      grid.innerHTML = '<div class="empty-state"><h3 class="empty-state-title">' + t('empty.error_title', 'Something went wrong') + '</h3><p class="empty-state-desc">' + t('empty.error_desc', 'Please try again in a moment.') + '</p></div>';
    }
    grid.classList.remove('grid-loading');
    if (scrollToGrid) {
      var top = el.querySelector('.listings-toolbar');
      if (top) top.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
  }

  // ---- filter events ----
  function bindSelect(id, key) {
    var sel = el.querySelector('#' + id);
    if (!sel) return;
    sel.addEventListener('change', function() {
      state[key] = sel.value;
      state.page = 1;
      refresh();
    });
  }
  bindSelect('fil-price', 'price');
  bindSelect('fil-size', 'size');
  bindSelect('fil-bsize', 'bsize');
  bindSelect('fil-cert', 'cert');
  bindSelect('fil-beds', 'beds');
  bindSelect('fil-baths', 'baths');
  bindSelect('fil-sort', 'sort');

  el.querySelector('#fil-type').addEventListener('change', function() {
    state.type = this.value;
    state.page = 1;
    refresh();
  });
  el.querySelector('#fil-area').addEventListener('change', function() {
    var v = this.value;
    state.place = '';
    if (v.indexOf('region:') === 0) {
      state.region = v.slice(7); state.cluster = ''; state.area = '';
    } else if (v) {
      state.area = v;
      state.region = areaRegionOf(v);
      state.cluster = clusterOfArea(state.region, v); // zoom map to the cluster
    } else {
      state.region = ''; state.cluster = ''; state.area = '';
    }
    state.page = 1;
    refresh();
  });

  // Place filter chips — narrow to exactly one Place (toggle off to re-broaden)
  if (nodes.placeChips) {
    nodes.placeChips.addEventListener('click', function(e) {
      var chip = e.target.closest('.place-chip');
      if (!chip) return;
      var pk = chip.getAttribute('data-place');
      state.place = (state.place === pk) ? '' : pk;
      state.page = 1;
      refresh();
    });
  }

  el.querySelector('#fil-tags').addEventListener('click', function(e) {
    var btn = e.target.closest('.filter-tag');
    if (!btn) return;
    var tag = btn.getAttribute('data-tag');
    var i = state.tags.indexOf(tag);
    if (i >= 0) state.tags.splice(i, 1); else state.tags.push(tag);
    btn.classList.toggle('active', i < 0);
    state.page = 1;
    refresh();
  });

  // Currency pills — presentation only: re-render prices + presets, no refetch
  el.querySelector('#cur-pills').addEventListener('click', function(e) {
    var pill = e.target.closest('.cur-pill');
    if (!pill) return;
    Currency.set(pill.getAttribute('data-cur'));
    el.querySelectorAll('.cur-pill').forEach(function(p) {
      var on = p.getAttribute('data-cur') === Currency.get();
      p.classList.toggle('active', on);
      p.setAttribute('aria-pressed', on ? 'true' : 'false');
    });
    if (nodes.priceSel) nodes.priceSel.innerHTML = priceOptionsHtml();
    if (nodes.priceCur) nodes.priceCur.textContent = '(' + Currency.get() + ')';
    renderGrid();
  });

  // More Filters toggle
  var moreBtn = el.querySelector('#moreFiltersBtn');
  var morePanel = el.querySelector('#moreFiltersPanel');
  if ([state.cert, state.beds, state.baths, state.bsize].some(Boolean) || state.tags.length) {
    morePanel.classList.add('open');
    moreBtn.classList.add('active');
  }
  moreBtn.addEventListener('click', function() {
    morePanel.classList.toggle('open');
    moreBtn.classList.toggle('active');
  });

  el.querySelector('#clearAllFiltersBtn').addEventListener('click', function() {
    state = { type: '', region: '', cluster: '', area: '', place: '', price: '', size: '', bsize: '', cert: '', beds: '', baths: '', tags: [], sort: '', q: '', agent_id: '', page: 1 };
    ['fil-type', 'fil-area', 'fil-price', 'fil-size', 'fil-bsize', 'fil-cert', 'fil-beds', 'fil-baths', 'fil-sort'].forEach(function(id) {
      var sel = document.getElementById(id);
      if (sel) sel.value = '';
    });
    refresh();
  });

  // Desktop: map always open; mobile: open if a location filter arrived in the URL
  if (!isMobile() || hasLocation()) setMapOpen(true);

  // ---- initial load ----
  await refresh();
  // Cluster pills size via getBBox, which returns 0 while the view is still
  // detached during the initial render — re-measure once it's in the document.
  requestAnimationFrame(function() { if (map) map.relayout(); });
  setTimeout(function() { if (map) map.relayout(); }, 120);
}


// =====================================================
// RENDER: LISTING DETAIL
// =====================================================

async function renderListingDetail(el, slug) {
  if (!slug) { el.innerHTML = renderNotFound('listing'); return; }
  let listing;
  try { listing = await DataLayer.getListing(slug); }
  catch(e) { el.innerHTML = renderNotFound('listing'); return; }

  const images = listing.images || [];
  const primaryImg = images.find(i => i.is_primary) || images[0];
  const features = listing.features || [];
  const priceStr = Currency.priceHtml(listing);
  const sizeStr = formatLandSize(listing.land_size_sqm, listing.land_size_are);
  const typeLabel = listing.listing_type_label || '';
  const certLabel = listing.certificate_type_label || '';
  const wa = listing.contact_whatsapp || listing.agent_whatsapp || '';
  const admin = isAdmin();

  el.innerHTML = `
    <div class="page-header">
      <div class="container">
      </div>
    </div>
    ${admin ? `<div class="admin-detail-bar"><span class="admin-detail-bar-label">Admin</span> <button class="btn btn--primary btn--sm" onclick="adminListingDetailEdit(${listing.id}, '${slug}')"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="margin-right:4px"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>Edit this listing</button></div>` : ''}
    <div class="section">
      <div class="container">
        <div class="listing-detail-layout">
          <div class="listing-detail-main">
            ${images.length > 0 ? (
              '<div class="listing-gallery">' +
              '<div class="listing-gallery-main">' +
              '<img src="' + (primaryImg ? primaryImg.url : images[0].url) + '" alt="' + listing.title + '" id="gallery-main-img">' +
              '</div>' +
              (images.length > 1 ?
                '<div class="listing-gallery-thumbs">' +
                images.map((img, idx) =>
                  '<img src="' + img.url + '" alt="' + (img.alt_text || '') + '" class="listing-thumb ' + (idx === 0 ? 'active' : '') + '" onclick="var m=document.getElementById(\'gallery-main-img\');m.src=\'' + img.url + '\';document.querySelectorAll(\'.listing-thumb\').forEach(function(t){t.classList.remove(\'active\')});this.classList.add(\'active\');">'
                ).join('') +
                '</div>'
              : '') +
              '</div>'
            ) : ''}

            <h1 class="listing-detail-title" id="ldt-title">${listing.title}</h1>
            <div class="listing-detail-price" id="ldt-price">${priceStr}</div>

            <div class="listing-detail-tags">
              ${typeLabel ? '<span class="detail-tag">' + typeLabel + '</span>' : ''}
              ${certLabel ? '<span class="detail-tag">' + certLabel + '</span>' : ''}
              ${listing.area_label ? '<span class="detail-tag">' + listing.area_label + '</span>' : ''}
              ${listing.zoning ? '<span class="detail-tag">' + listing.zoning + '</span>' : ''}
            </div>

            <div class="listing-detail-specs">
              ${sizeStr ? '<div class="spec-item"><span class="spec-label">Land Size</span><span class="spec-value">' + sizeStr + '</span></div>' : ''}
              ${listing.building_size_sqm ? '<div class="spec-item"><span class="spec-label">Building</span><span class="spec-value">' + listing.building_size_sqm + ' m²</span></div>' : ''}
              ${listing.bedrooms ? '<div class="spec-item"><span class="spec-label">Bedrooms</span><span class="spec-value">' + listing.bedrooms + '</span></div>' : ''}
              ${listing.bathrooms ? '<div class="spec-item"><span class="spec-label">Bathrooms</span><span class="spec-value">' + listing.bathrooms + '</span></div>' : ''}
              ${listing.year_built ? '<div class="spec-item"><span class="spec-label">Year Built</span><span class="spec-value">' + listing.year_built + '</span></div>' : ''}
              ${listing.furnishing ? '<div class="spec-item"><span class="spec-label">Furnishing</span><span class="spec-value">' + listing.furnishing.replace(/_/g, ' ') + '</span></div>' : ''}
            </div>

            <div class="listing-detail-desc" id="ldt-desc">
              <h3>Description</h3>
              <p>${listing.short_description || ''}</p>
              ${listing.description ? '<div class="listing-full-desc">' + listing.description.replace(/\n/g, '<br>') + '</div>' : ''}
            </div>

            ${features.length > 0 ? '<div class="listing-features"><h3>Features</h3><div class="feature-list">' + features.map(f => '<span class="feature-tag">' + f + '</span>').join('') + '</div></div>' : ''}

            ${listing.google_maps_url ? '<div class="listing-map-link" style="margin-top:var(--space-6)"><a href="' + listing.google_maps_url + '" target="_blank" rel="noopener noreferrer" class="btn btn--ghost btn--sm"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg> View on Google Maps</a></div>' : ''}
          </div>

          <div class="listing-detail-sidebar">
            ${listing.agent_name ? (
              '<div class="card listing-agent-card">' +
              '<h3 style="font-family:var(--font-display);margin-bottom:var(--space-3)">Listed by</h3>' +
              '<a href="#agent/' + listing.agent_slug + '" onclick="navigate(\'agent/' + listing.agent_slug + '\');return false;" class="agent-card-link">' +
              '<div class="agent-card-name">' + listing.agent_name + '</div>' +
              (listing.agent_agency ? '<div class="agent-card-agency">' + listing.agent_agency + '</div>' : '') +
              '</a>' +
              (wa ? '<a href="https://wa.me/' + wa.replace(/[^0-9]/g, '') + '?text=' + encodeURIComponent('Hi, I\'m interested in: ' + listing.title) + '" target="_blank" rel="noopener noreferrer" class="btn btn--whatsapp btn--full" style="margin-top:var(--space-4)">' + iconWhatsApp() + ' WhatsApp Agent</a>' : '') +
              ((listing.contact_phone || listing.agent_phone) ? '<a href="tel:' + (listing.contact_phone || listing.agent_phone) + '" class="btn btn--ghost btn--full" style="margin-top:var(--space-2)"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"/></svg> Call Agent</a>' : '') +
              '</div>'
            ) : ''}

            ${renderFavBtn('listing', listing.id)}

            ${listing.address ? '<div class="card" style="margin-top:var(--space-4)"><h4 style="font-size:var(--text-sm);font-weight:600;margin-bottom:var(--space-2)">Location</h4><p style="font-size:var(--text-sm);color:var(--color-text-muted)">' + listing.address + '</p></div>' : ''}
          </div>
        </div>
      </div>
    </div>
  `;
}

// =====================================================
// RENDER: AGENTS
// =====================================================

// =====================================================
// ADMIN: LISTING INLINE EDIT
// =====================================================

async function adminListingQuickEdit(listingId, slug) {
  await FilterData.load();

  // Fetch full listing data
  let listing;
  try { listing = await DataLayer.getListing(slug); }
  catch(e) { alert('Could not load listing data.'); return; }

  const typeOptions = FilterData.listing_types.map(t =>
    '<option value="' + t.key + '"' + (listing.listing_type_key === t.key ? ' selected' : '') + '>' + t.label + '</option>'
  ).join('');
  const certOptions = FilterData.land_certificate_types.map(c =>
    '<option value="' + c.key + '"' + (listing.certificate_type_key === c.key ? ' selected' : '') + '>' + c.label + '</option>'
  ).join('');
  const areaOptions = FilterData.areas.map(a =>
    '<option value="' + a.key + '"' + ((listing.area_key || listing.area) === a.key ? ' selected' : '') + '>' + a.label + '</option>'
  ).join('');

  // Remove any existing modal
  var old = document.getElementById('admin-listing-modal');
  if (old) old.remove();

  var modal = document.createElement('div');
  modal.id = 'admin-listing-modal';
  modal.className = 'admin-modal-overlay';
  modal.innerHTML = `
    <div class="admin-modal-box">
      <div class="admin-modal-header">
        <h3>Edit Listing</h3>
        <button class="admin-modal-close" onclick="document.getElementById('admin-listing-modal').remove()">&times;</button>
      </div>
      <div class="admin-modal-body">
        <div class="admin-form-row">
          <label>Title</label>
          <input id="alm-title" type="text" value="${(listing.title || '').replace(/"/g, '&quot;')}">
        </div>
        <div class="admin-form-row admin-form-row--2col">
          <div>
            <label>Type</label>
            <select id="alm-type">${typeOptions}</select>
          </div>
          <div>
            <label>Area</label>
            <select id="alm-area"><option value="">— select —</option>${areaOptions}</select>
          </div>
        </div>
        <div class="admin-form-row">
          <label>Location detail</label>
          <input id="alm-location" type="text" value="${(listing.location_detail || '').replace(/"/g, '&quot;')}">
        </div>
        <div class="admin-form-row admin-form-row--2col">
          <div>
            <label>Price USD</label>
            <input id="alm-price-usd" type="number" value="${listing.price_usd || ''}">
          </div>
          <div>
            <label>Price IDR</label>
            <input id="alm-price-idr" type="number" value="${listing.price_idr || ''}">
          </div>
        </div>
        <div class="admin-form-row admin-form-row--3col">
          <div>
            <label>Land (sqm)</label>
            <input id="alm-land" type="number" value="${listing.land_size_sqm || ''}">
          </div>
          <div>
            <label>Building (sqm)</label>
            <input id="alm-building" type="number" value="${listing.building_size_sqm || ''}">
          </div>
          <div>
            <label>Certificate</label>
            <select id="alm-cert"><option value="">— none —</option>${certOptions}</select>
          </div>
        </div>
        <div class="admin-form-row admin-form-row--3col">
          <div>
            <label>Beds</label>
            <input id="alm-beds" type="number" min="0" value="${listing.bedrooms || ''}">
          </div>
          <div>
            <label>Baths</label>
            <input id="alm-baths" type="number" min="0" value="${listing.bathrooms || ''}">
          </div>
          <div>
            <label>Status</label>
            <select id="alm-status">
              <option value="active"${(listing.status||'active') === 'active' ? ' selected' : ''}>Active</option>
              <option value="expired"${listing.status === 'expired' ? ' selected' : ''}>Expired</option>
              <option value="draft"${listing.status === 'draft' ? ' selected' : ''}>Draft</option>
            </select>
          </div>
        </div>
        <div class="admin-form-row">
          <label class="admin-checkbox-label">
            <input id="alm-featured" type="checkbox"${listing.is_featured ? ' checked' : ''}> Featured listing
          </label>
        </div>
        <div id="alm-error" class="admin-form-error" style="display:none"></div>
      </div>
      <div class="admin-modal-footer">
        <button class="btn btn--ghost btn--sm" onclick="document.getElementById('admin-listing-modal').remove()">Cancel</button>
        <button class="btn btn--primary btn--sm" onclick="adminListingQuickSave(${listingId})">Save changes</button>
      </div>
    </div>
  `;
  modal.addEventListener('click', function(e) {
    if (e.target === modal) modal.remove();
  });
  document.body.appendChild(modal);
}

async function adminListingQuickSave(listingId) {
  var btn = document.querySelector('#admin-listing-modal .btn--primary');
  if (btn) { btn.disabled = true; btn.textContent = 'Saving…'; }
  var errEl = document.getElementById('alm-error');
  if (errEl) errEl.style.display = 'none';

  var payload = {
    listing_id:          listingId,
    title:               document.getElementById('alm-title').value,
    listing_type_key:    document.getElementById('alm-type').value,
    area_key:            document.getElementById('alm-area').value,
    location_detail:     document.getElementById('alm-location').value,
    price_usd:           document.getElementById('alm-price-usd').value,
    price_idr:           document.getElementById('alm-price-idr').value,
    land_size_sqm:       document.getElementById('alm-land').value,
    building_size_sqm:   document.getElementById('alm-building').value,
    certificate_type_key:document.getElementById('alm-cert').value,
    bedrooms:            document.getElementById('alm-beds').value,
    bathrooms:           document.getElementById('alm-baths').value,
    status:              document.getElementById('alm-status').value,
    is_featured:         document.getElementById('alm-featured').checked,
  };

  try {
    await UserAuth.apiCall('admin_update_listing', payload);
    document.getElementById('admin-listing-modal').remove();
    DataLayer.clearCache && DataLayer.clearCache();
    // Refresh the card in place by re-fetching if possible, otherwise notify
    var wrap = document.querySelector('[data-listing-id="' + listingId + '"]');
    if (wrap) {
      wrap.querySelector('.listing-card-title') && (wrap.querySelector('.listing-card-title').textContent = payload.title);
    }
    // Show toast
    showToast('Listing updated', 'success');
  } catch(err) {
    if (errEl) { errEl.textContent = err.message || 'Save failed.'; errEl.style.display = 'block'; }
    if (btn) { btn.disabled = false; btn.textContent = 'Save changes'; }
  }
}

// Full-detail inline edit — replaces the detail page content with an edit form
async function adminListingDetailEdit(listingId, slug) {
  await FilterData.load();
  let listing;
  try { listing = await DataLayer.getListing(slug); }
  catch(e) { showToast('Could not load listing.', 'error'); return; }

  const typeOptions = FilterData.listing_types.map(t =>
    '<option value="' + t.key + '"' + (listing.listing_type_key === t.key ? ' selected' : '') + '>' + t.label + '</option>'
  ).join('');
  const certOptions = FilterData.land_certificate_types.map(c =>
    '<option value="' + c.key + '"' + (listing.certificate_type_key === c.key ? ' selected' : '') + '>' + c.label + '</option>'
  ).join('');
  const areaOptions = FilterData.areas.map(a =>
    '<option value="' + a.key + '"' + ((listing.area_key || listing.area) === a.key ? ' selected' : '') + '>' + a.label + '</option>'
  ).join('');

  var mainEl = document.querySelector('.listing-detail-main');
  if (!mainEl) return;

  mainEl.innerHTML = `
    <div class="admin-inline-form">
      <h2 style="margin-bottom:var(--space-5)">Editing listing</h2>
      <div class="admin-form-row">
        <label>Title</label>
        <input id="ald-title" type="text" class="admin-input" value="${(listing.title || '').replace(/"/g, '&quot;')}">
      </div>
      <div class="admin-form-row admin-form-row--2col">
        <div>
          <label>Type</label>
          <select id="ald-type" class="admin-select">${typeOptions}</select>
        </div>
        <div>
          <label>Area</label>
          <select id="ald-area" class="admin-select"><option value="">— select —</option>${areaOptions}</select>
        </div>
      </div>
      <div class="admin-form-row">
        <label>Location detail</label>
        <input id="ald-location" type="text" class="admin-input" value="${(listing.location_detail || '').replace(/"/g, '&quot;')}">
      </div>
      <div class="admin-form-row admin-form-row--2col">
        <div>
          <label>Price USD</label>
          <input id="ald-price-usd" type="number" class="admin-input" value="${listing.price_usd || ''}">
        </div>
        <div>
          <label>Price IDR</label>
          <input id="ald-price-idr" type="number" class="admin-input" value="${listing.price_idr || ''}">
        </div>
      </div>
      <div class="admin-form-row admin-form-row--3col">
        <div>
          <label>Land size (sqm)</label>
          <input id="ald-land" type="number" class="admin-input" value="${listing.land_size_sqm || ''}">
        </div>
        <div>
          <label>Land size (are)</label>
          <input id="ald-land-are" type="number" step="0.01" class="admin-input" value="${listing.land_size_are || ''}">
        </div>
        <div>
          <label>Building (sqm)</label>
          <input id="ald-building" type="number" class="admin-input" value="${listing.building_size_sqm || ''}">
        </div>
      </div>
      <div class="admin-form-row admin-form-row--3col">
        <div>
          <label>Beds</label>
          <input id="ald-beds" type="number" min="0" class="admin-input" value="${listing.bedrooms || ''}">
        </div>
        <div>
          <label>Baths</label>
          <input id="ald-baths" type="number" min="0" class="admin-input" value="${listing.bathrooms || ''}">
        </div>
        <div>
          <label>Certificate</label>
          <select id="ald-cert" class="admin-select"><option value="">— none —</option>${certOptions}</select>
        </div>
      </div>
      <div class="admin-form-row admin-form-row--2col">
        <div>
          <label>Status</label>
          <select id="ald-status" class="admin-select">
            <option value="active"${(listing.status||'active')==='active'?' selected':''}>Active</option>
            <option value="expired"${listing.status==='expired'?' selected':''}>Expired</option>
            <option value="draft"${listing.status==='draft'?' selected':''}>Draft</option>
          </select>
        </div>
        <div>
          <label>Price label</label>
          <input id="ald-price-label" type="text" class="admin-input" placeholder="e.g. negotiable" value="${(listing.price_label || '').replace(/"/g, '&quot;')}">
        </div>
      </div>
      <div class="admin-form-row">
        <label>Short description</label>
        <textarea id="ald-short-desc" class="admin-textarea" rows="3">${(listing.short_description || '').replace(/</g, '&lt;')}</textarea>
      </div>
      <div class="admin-form-row">
        <label>Full description</label>
        <textarea id="ald-desc" class="admin-textarea" rows="8">${(listing.description || '').replace(/</g, '&lt;')}</textarea>
      </div>
      <div class="admin-form-row">
        <label>Google Maps URL</label>
        <input id="ald-maps" type="url" class="admin-input" value="${(listing.google_maps_url || '').replace(/"/g, '&quot;')}">
      </div>
      <div class="admin-form-row">
        <label class="admin-checkbox-label">
          <input id="ald-featured" type="checkbox"${listing.is_featured ? ' checked' : ''}> Featured listing
        </label>
      </div>
      <div id="ald-error" class="admin-form-error" style="display:none"></div>
      <div class="admin-form-actions">
        <button class="btn btn--ghost" onclick="navigate('listing/${slug}')">Cancel</button>
        <button class="btn btn--primary" id="ald-save-btn" onclick="adminListingDetailSave(${listingId}, '${slug}')">Save changes</button>
      </div>
    </div>
  `;
}

async function adminListingDetailSave(listingId, slug) {
  var btn = document.getElementById('ald-save-btn');
  var errEl = document.getElementById('ald-error');
  if (btn) { btn.disabled = true; btn.textContent = 'Saving…'; }
  if (errEl) errEl.style.display = 'none';

  var payload = {
    listing_id:           listingId,
    title:                document.getElementById('ald-title').value,
    listing_type_key:     document.getElementById('ald-type').value,
    area_key:             document.getElementById('ald-area').value,
    location_detail:      document.getElementById('ald-location').value,
    price_usd:            document.getElementById('ald-price-usd').value,
    price_idr:            document.getElementById('ald-price-idr').value,
    price_label:          document.getElementById('ald-price-label').value,
    land_size_sqm:        document.getElementById('ald-land').value,
    land_size_are:        document.getElementById('ald-land-are').value,
    building_size_sqm:    document.getElementById('ald-building').value,
    certificate_type_key: document.getElementById('ald-cert').value,
    bedrooms:             document.getElementById('ald-beds').value,
    bathrooms:            document.getElementById('ald-baths').value,
    status:               document.getElementById('ald-status').value,
    short_description:    document.getElementById('ald-short-desc').value,
    description:          document.getElementById('ald-desc').value,
    google_maps_url:      document.getElementById('ald-maps').value,
    is_featured:          document.getElementById('ald-featured').checked,
  };

  try {
    await UserAuth.apiCall('admin_update_listing', payload);
    DataLayer.clearCache && DataLayer.clearCache();
    showToast('Listing saved', 'success');
    navigate('listing/' + slug);
  } catch(err) {
    if (errEl) { errEl.textContent = err.message || 'Save failed.'; errEl.style.display = 'block'; }
    if (btn) { btn.disabled = false; btn.textContent = 'Save changes'; }
  }
}

// =====================================================
// ADMIN: SHARED HELPERS FOR DIRECTORY EDITING
// =====================================================

function _adminEditIcon() {
  return '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="margin-right:4px"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>';
}

function _adminModalOpen(id, title, bodyHtml, onSave) {
  var old = document.getElementById('admin-dir-modal');
  if (old) old.remove();
  var modal = document.createElement('div');
  modal.id = 'admin-dir-modal';
  modal.className = 'admin-modal-overlay';
  modal.innerHTML = `
    <div class="admin-modal-box">
      <div class="admin-modal-header">
        <h3>${title}</h3>
        <button class="admin-modal-close" onclick="document.getElementById('admin-dir-modal').remove()">&times;</button>
      </div>
      <div class="admin-modal-body">${bodyHtml}</div>
      <div class="admin-modal-footer">
        <button class="btn btn--ghost btn--sm" onclick="document.getElementById('admin-dir-modal').remove()">Cancel</button>
        <button class="btn btn--primary btn--sm" id="adm-save-btn" onclick="${onSave}">Save changes</button>
      </div>
    </div>`;
  modal.addEventListener('click', function(e) { if (e.target === modal) modal.remove(); });
  document.body.appendChild(modal);
}

async function _adminModalSave(action, payload, reloadSlug, reloadPage) {
  var btn = document.getElementById('adm-save-btn');
  var errEl = document.getElementById('adm-error');
  if (btn) { btn.disabled = true; btn.textContent = 'Saving…'; }
  if (errEl) errEl.style.display = 'none';
  try {
    await UserAuth.apiCall(action, payload);
    document.getElementById('admin-dir-modal') && document.getElementById('admin-dir-modal').remove();
    DataLayer.clearCache && DataLayer.clearCache();
    showToast('Saved', 'success');
    if (reloadSlug) navigate(reloadPage + '/' + reloadSlug);
  } catch(err) {
    if (errEl) { errEl.textContent = err.message || 'Save failed.'; errEl.style.display = 'block'; }
    if (btn) { btn.disabled = false; btn.textContent = 'Save changes'; }
  }
}

function _adminInlineFormReplace(mainSel, formHtml) {
  var mainEl = document.querySelector(mainSel);
  if (!mainEl) { showToast('Could not find page section to edit', 'error'); return false; }
  mainEl.innerHTML = `<div class="admin-inline-form">${formHtml}</div>`;
  return true;
}

function _adminV(id) {
  var el = document.getElementById(id);
  return el ? el.value : '';
}
function _adminC(id) {
  var el = document.getElementById(id);
  return el ? el.checked : false;
}

// =====================================================
// ADMIN: PROVIDER EDIT
// =====================================================

async function adminProviderQuickEdit(id, slug) {
  await FilterData.load();
  let b;
  try { b = await DataLayer.getProvider(slug); } catch(e) { showToast('Could not load provider', 'error'); return; }
  const areaOptions = FilterData.areas.map(a => `<option value="${a.key}"${(b.area_key||b.area)===a.key?' selected':''}>${a.label}</option>`).join('');
  _adminModalOpen(id, 'Edit Provider', `
    <div class="admin-form-row"><label>Name</label><input id="adm-name" class="admin-input" type="text" value="${(b.name||'').replace(/"/g,'&quot;')}"></div>
    <div class="admin-form-row"><label>Area</label><select id="adm-area" class="admin-select"><option value="">—</option>${areaOptions}</select></div>
    <div class="admin-form-row"><label>Phone / WhatsApp</label>
      <div class="admin-form-row--2col">
        <input id="adm-phone" class="admin-input" type="text" value="${(b.phone||'').replace(/"/g,'&quot;')}" placeholder="Phone">
        <input id="adm-wa" class="admin-input" type="text" value="${(b.whatsapp_number||'').replace(/"/g,'&quot;')}" placeholder="WhatsApp">
      </div>
    </div>
    <div class="admin-form-row"><label>Short description</label><textarea id="adm-short" class="admin-textarea" rows="2">${(b.short_description_en||b.short_description||'').replace(/</g,'&lt;')}</textarea></div>
    <div class="admin-form-row"><label>Profile photo URL</label><input id="adm-photo" class="admin-input" type="url" value="${(b.profile_photo_url||'').replace(/"/g,'&quot;')}"></div>
    <div class="admin-form-row"><label>Logo URL</label><input id="adm-logo" class="admin-input" type="url" value="${(b.logo_url||'').replace(/"/g,'&quot;')}"></div>
    <div class="admin-form-row admin-form-row--2col">
      <label class="admin-checkbox-label"><input id="adm-trusted" type="checkbox"${b.is_trusted?' checked':''}> Trusted</label>
      <label class="admin-checkbox-label"><input id="adm-active" type="checkbox"${b.is_active!==false&&b.is_active!=0?' checked':''}> Active</label>
    </div>
    <div id="adm-error" class="admin-form-error" style="display:none"></div>
  `, `adminProviderQuickSave(${id},'${slug}')`);
}

async function adminProviderQuickSave(id, slug) {
  await _adminModalSave('admin_update_provider', {
    id, name: _adminV('adm-name'), area_key: _adminV('adm-area'),
    phone: _adminV('adm-phone'), whatsapp_number: _adminV('adm-wa'),
    short_description: _adminV('adm-short'),
    profile_photo_url: _adminV('adm-photo'), logo_url: _adminV('adm-logo'),
    is_trusted: _adminC('adm-trusted'), is_active: _adminC('adm-active'),
  }, slug, 'provider');
}

async function adminProviderDetailEdit(id, slug) {
  await FilterData.load();
  let b;
  try { b = await DataLayer.getProvider(slug); } catch(e) { showToast('Could not load provider', 'error'); return; }
  const areaOptions = FilterData.areas.map(a => `<option value="${a.key}"${(b.area_key||b.area)===a.key?' selected':''}>${a.label}</option>`).join('');
  const ok = _adminInlineFormReplace('.detail-main', `
    <h2 style="margin-bottom:var(--space-5)">Editing: ${(b.name||'').replace(/</g,'&lt;')}</h2>
    <div class="admin-form-row"><label>Name</label><input id="ade-name" class="admin-input" type="text" value="${(b.name||'').replace(/"/g,'&quot;')}"></div>
    <div class="admin-form-row admin-form-row--2col">
      <div><label>Area</label><select id="ade-area" class="admin-select"><option value="">—</option>${areaOptions}</select></div>
      <div><label>Website</label><input id="ade-web" class="admin-input" type="url" value="${(b.website_url||'').replace(/"/g,'&quot;')}"></div>
    </div>
    <div class="admin-form-row admin-form-row--2col">
      <div><label>Phone</label><input id="ade-phone" class="admin-input" type="text" value="${(b.phone||'').replace(/"/g,'&quot;')}"></div>
      <div><label>WhatsApp</label><input id="ade-wa" class="admin-input" type="text" value="${(b.whatsapp_number||'').replace(/"/g,'&quot;')}"></div>
    </div>
    <div class="admin-form-row"><label>Short description</label><textarea id="ade-short" class="admin-textarea" rows="3">${(b.short_description_en||b.short_description||'').replace(/</g,'&lt;')}</textarea></div>
    <div class="admin-form-row"><label>Full description</label><textarea id="ade-desc" class="admin-textarea" rows="7">${(b.description||'').replace(/</g,'&lt;')}</textarea></div>
    <div class="admin-form-row"><label>Address</label><input id="ade-address" class="admin-input" type="text" value="${(b.address||'').replace(/"/g,'&quot;')}"></div>
    <div class="admin-form-row"><label>Google Maps URL</label><input id="ade-maps" class="admin-input" type="url" value="${(b.google_maps_url||'').replace(/"/g,'&quot;')}"></div>
    <div class="admin-form-row"><label>Profile photo URL</label><input id="ade-photo" class="admin-input" type="url" value="${(b.profile_photo_url||'').replace(/"/g,'&quot;')}"></div>
    <div class="admin-form-row"><label>Logo URL</label><input id="ade-logo" class="admin-input" type="url" value="${(b.logo_url||'').replace(/"/g,'&quot;')}"></div>
    <div class="admin-form-row"><label>Hero image URL</label><input id="ade-hero" class="admin-input" type="url" value="${(b.hero_image_url||'').replace(/"/g,'&quot;')}"></div>
    <div class="admin-form-row admin-form-row--3col">
      <label class="admin-checkbox-label"><input id="ade-trusted" type="checkbox"${b.is_trusted?' checked':''}> Trusted</label>
      <label class="admin-checkbox-label"><input id="ade-featured" type="checkbox"${b.is_featured?' checked':''}> Featured</label>
      <label class="admin-checkbox-label"><input id="ade-active" type="checkbox"${b.is_active!==false&&b.is_active!=0?' checked':''}> Active</label>
    </div>
    <div id="ade-error" class="admin-form-error" style="display:none"></div>
    <div class="admin-form-actions">
      <button class="btn btn--ghost" onclick="navigate('provider/${slug}')">Cancel</button>
      <button class="btn btn--primary" id="ade-save-btn" onclick="adminProviderDetailSave(${id},'${slug}')">Save changes</button>
    </div>
  `);
  if (!ok) return;
}

async function adminProviderDetailSave(id, slug) {
  var btn = document.getElementById('ade-save-btn');
  var errEl = document.getElementById('ade-error');
  if (btn) { btn.disabled = true; btn.textContent = 'Saving…'; }
  if (errEl) errEl.style.display = 'none';
  try {
    await UserAuth.apiCall('admin_update_provider', {
      id, name: _adminV('ade-name'), area_key: _adminV('ade-area'),
      phone: _adminV('ade-phone'), whatsapp_number: _adminV('ade-wa'),
      website_url: _adminV('ade-web'), address: _adminV('ade-address'),
      google_maps_url: _adminV('ade-maps'),
      short_description: _adminV('ade-short'), description: _adminV('ade-desc'),
      profile_photo_url: _adminV('ade-photo'), logo_url: _adminV('ade-logo'), hero_image_url: _adminV('ade-hero'),
      is_trusted: _adminC('ade-trusted'), is_featured: _adminC('ade-featured'), is_active: _adminC('ade-active'),
    });
    DataLayer.clearCache && DataLayer.clearCache();
    showToast('Provider saved', 'success');
    navigate('provider/' + slug);
  } catch(err) {
    if (errEl) { errEl.textContent = err.message || 'Save failed.'; errEl.style.display = 'block'; }
    if (btn) { btn.disabled = false; btn.textContent = 'Save changes'; }
  }
}

// =====================================================
// ADMIN: DEVELOPER EDIT
// =====================================================

async function adminDeveloperQuickEdit(id, slug) {
  await FilterData.load();
  let dev;
  try { dev = await DataLayer.getDeveloper(slug); } catch(e) { showToast('Could not load developer', 'error'); return; }
  const areaOptions = FilterData.areas.map(a => `<option value="${a.key}"${(dev.areas_focus||[]).includes(a.key)?' selected':''}>${a.label}</option>`).join('');
  _adminModalOpen(id, 'Edit Developer', `
    <div class="admin-form-row"><label>Name</label><input id="adm-name" class="admin-input" type="text" value="${(dev.name||'').replace(/"/g,'&quot;')}"></div>
    <div class="admin-form-row admin-form-row--2col">
      <div><label>Phone</label><input id="adm-phone" class="admin-input" type="text" value="${(dev.phone||'').replace(/"/g,'&quot;')}"></div>
      <div><label>WhatsApp</label><input id="adm-wa" class="admin-input" type="text" value="${(dev.whatsapp_number||'').replace(/"/g,'&quot;')}"></div>
    </div>
    <div class="admin-form-row"><label>Short description</label><textarea id="adm-short" class="admin-textarea" rows="2">${(dev.short_description_en||dev.short_description||'').replace(/</g,'&lt;')}</textarea></div>
    <div class="admin-form-row"><label>Profile photo URL</label><input id="adm-photo" class="admin-input" type="url" value="${(dev.profile_photo_url||'').replace(/"/g,'&quot;')}"></div>
    <div class="admin-form-row"><label>Logo URL</label><input id="adm-logo" class="admin-input" type="url" value="${(dev.logo_url||'').replace(/"/g,'&quot;')}"></div>
    <div class="admin-form-row admin-form-row--2col">
      <label class="admin-checkbox-label"><input id="adm-featured" type="checkbox"${dev.is_featured?' checked':''}> Featured</label>
      <label class="admin-checkbox-label"><input id="adm-active" type="checkbox"${dev.is_active!==false&&dev.is_active!=0?' checked':''}> Active</label>
    </div>
    <div id="adm-error" class="admin-form-error" style="display:none"></div>
  `, `adminDeveloperQuickSave(${id},'${slug}')`);
}

async function adminDeveloperQuickSave(id, slug) {
  await _adminModalSave('admin_update_developer', {
    id, name: _adminV('adm-name'),
    phone: _adminV('adm-phone'), whatsapp_number: _adminV('adm-wa'),
    short_description: _adminV('adm-short'),
    profile_photo_url: _adminV('adm-photo'), logo_url: _adminV('adm-logo'),
    is_featured: _adminC('adm-featured'), is_active: _adminC('adm-active'),
  }, slug, 'developer');
}

async function adminDeveloperDetailEdit(id, slug) {
  await FilterData.load();
  let dev;
  try { dev = await DataLayer.getDeveloper(slug); } catch(e) { showToast('Could not load developer', 'error'); return; }
  const ok = _adminInlineFormReplace('.detail-main', `
    <h2 style="margin-bottom:var(--space-5)">Editing: ${(dev.name||'').replace(/</g,'&lt;')}</h2>
    <div class="admin-form-row"><label>Name</label><input id="ade-name" class="admin-input" type="text" value="${(dev.name||'').replace(/"/g,'&quot;')}"></div>
    <div class="admin-form-row admin-form-row--2col">
      <div><label>Phone</label><input id="ade-phone" class="admin-input" type="text" value="${(dev.phone||'').replace(/"/g,'&quot;')}"></div>
      <div><label>WhatsApp</label><input id="ade-wa" class="admin-input" type="text" value="${(dev.whatsapp_number||'').replace(/"/g,'&quot;')}"></div>
    </div>
    <div class="admin-form-row admin-form-row--2col">
      <div><label>Website</label><input id="ade-web" class="admin-input" type="url" value="${(dev.website_url||'').replace(/"/g,'&quot;')}"></div>
      <div><label>Min investment (USD)</label><input id="ade-ticket" class="admin-input" type="number" value="${dev.min_ticket_usd||''}"></div>
    </div>
    <div class="admin-form-row"><label>Short description</label><textarea id="ade-short" class="admin-textarea" rows="3">${(dev.short_description_en||dev.short_description||'').replace(/</g,'&lt;')}</textarea></div>
    <div class="admin-form-row"><label>Full description</label><textarea id="ade-desc" class="admin-textarea" rows="7">${(dev.description||'').replace(/</g,'&lt;')}</textarea></div>
    <div class="admin-form-row"><label>Google Maps URL</label><input id="ade-maps" class="admin-input" type="url" value="${(dev.google_maps_url||'').replace(/"/g,'&quot;')}"></div>
    <div class="admin-form-row"><label>Profile photo URL</label><input id="ade-photo" class="admin-input" type="url" value="${(dev.profile_photo_url||'').replace(/"/g,'&quot;')}"></div>
    <div class="admin-form-row"><label>Logo URL</label><input id="ade-logo" class="admin-input" type="url" value="${(dev.logo_url||'').replace(/"/g,'&quot;')}"></div>
    <div class="admin-form-row"><label>Hero image URL</label><input id="ade-hero" class="admin-input" type="url" value="${(dev.hero_image_url||'').replace(/"/g,'&quot;')}"></div>
    <div class="admin-form-row admin-form-row--2col">
      <label class="admin-checkbox-label"><input id="ade-featured" type="checkbox"${dev.is_featured?' checked':''}> Featured</label>
      <label class="admin-checkbox-label"><input id="ade-active" type="checkbox"${dev.is_active!==false&&dev.is_active!=0?' checked':''}> Active</label>
    </div>
    <div id="ade-error" class="admin-form-error" style="display:none"></div>
    <div class="admin-form-actions">
      <button class="btn btn--ghost" onclick="navigate('developer/${slug}')">Cancel</button>
      <button class="btn btn--primary" id="ade-save-btn" onclick="adminDeveloperDetailSave(${id},'${slug}')">Save changes</button>
    </div>
  `);
  if (!ok) return;
}

async function adminDeveloperDetailSave(id, slug) {
  var btn = document.getElementById('ade-save-btn');
  var errEl = document.getElementById('ade-error');
  if (btn) { btn.disabled = true; btn.textContent = 'Saving…'; }
  if (errEl) errEl.style.display = 'none';
  try {
    await UserAuth.apiCall('admin_update_developer', {
      id, name: _adminV('ade-name'),
      phone: _adminV('ade-phone'), whatsapp_number: _adminV('ade-wa'),
      website_url: _adminV('ade-web'), google_maps_url: _adminV('ade-maps'),
      min_ticket_usd: _adminV('ade-ticket'),
      short_description: _adminV('ade-short'), description: _adminV('ade-desc'),
      profile_photo_url: _adminV('ade-photo'), logo_url: _adminV('ade-logo'), hero_image_url: _adminV('ade-hero'),
      is_featured: _adminC('ade-featured'), is_active: _adminC('ade-active'),
    });
    DataLayer.clearCache && DataLayer.clearCache();
    showToast('Developer saved', 'success');
    navigate('developer/' + slug);
  } catch(err) {
    if (errEl) { errEl.textContent = err.message || 'Save failed.'; errEl.style.display = 'block'; }
    if (btn) { btn.disabled = false; btn.textContent = 'Save changes'; }
  }
}

// =====================================================
// ADMIN: PROJECT EDIT
// =====================================================

async function adminProjectQuickEdit(id, slug) {
  await FilterData.load();
  let p;
  try { p = await DataLayer.getProject(slug); } catch(e) { showToast('Could not load project', 'error'); return; }
  const areaOptions = FilterData.areas.map(a => `<option value="${a.key}"${p.area_key===a.key||p.location_area===a.key?' selected':''}>${a.label}</option>`).join('');
  const statusOptions = FilterData.project_statuses.map(s => `<option value="${s.key}"${p.status===s.key?' selected':''}>${s.label}</option>`).join('');
  _adminModalOpen(id, 'Edit Project', `
    <div class="admin-form-row"><label>Name</label><input id="adm-name" class="admin-input" type="text" value="${(p.name||'').replace(/"/g,'&quot;')}"></div>
    <div class="admin-form-row admin-form-row--2col">
      <div><label>Area</label><select id="adm-area" class="admin-select"><option value="">—</option>${areaOptions}</select></div>
      <div><label>Status</label><select id="adm-status" class="admin-select">${statusOptions}</select></div>
    </div>
    <div class="admin-form-row admin-form-row--2col">
      <div><label>Min investment (USD)</label><input id="adm-ticket" class="admin-input" type="number" value="${p.min_investment_usd||''}"></div>
      <div><label>Yield range</label><input id="adm-yield" class="admin-input" type="text" value="${(p.expected_yield_range||'').replace(/"/g,'&quot;')}"></div>
    </div>
    <div class="admin-form-row"><label>Short description</label><textarea id="adm-short" class="admin-textarea" rows="2">${(p.short_description_en||p.short_description||'').replace(/</g,'&lt;')}</textarea></div>
    <div class="admin-form-row"><label>Logo URL</label><input id="adm-logo" class="admin-input" type="url" value="${(p.logo_url||'').replace(/"/g,'&quot;')}"></div>
    <div class="admin-form-row admin-form-row--2col">
      <label class="admin-checkbox-label"><input id="adm-featured" type="checkbox"${p.is_featured?' checked':''}> Featured</label>
      <label class="admin-checkbox-label"><input id="adm-active" type="checkbox"${p.is_active!==false&&p.is_active!=0?' checked':''}> Active</label>
    </div>
    <div id="adm-error" class="admin-form-error" style="display:none"></div>
  `, `adminProjectQuickSave(${id},'${slug}')`);
}

async function adminProjectQuickSave(id, slug) {
  await _adminModalSave('admin_update_project', {
    id, name: _adminV('adm-name'), area_key: _adminV('adm-area'),
    status_key: _adminV('adm-status'),
    min_investment_usd: _adminV('adm-ticket'), expected_yield_range: _adminV('adm-yield'),
    short_description: _adminV('adm-short'), logo_url: _adminV('adm-logo'),
    is_featured: _adminC('adm-featured'), is_active: _adminC('adm-active'),
  }, slug, 'project');
}

async function adminProjectDetailEdit(id, slug) {
  await FilterData.load();
  let p;
  try { p = await DataLayer.getProject(slug); } catch(e) { showToast('Could not load project', 'error'); return; }
  const areaOptions = FilterData.areas.map(a => `<option value="${a.key}"${p.area_key===a.key||p.location_area===a.key?' selected':''}>${a.label}</option>`).join('');
  const statusOptions = FilterData.project_statuses.map(s => `<option value="${s.key}"${p.status===s.key?' selected':''}>${s.label}</option>`).join('');
  const typeOptions = FilterData.project_types.map(t => `<option value="${t.key}"${p.project_type===t.key?' selected':''}>${t.label}</option>`).join('');
  const ok = _adminInlineFormReplace('.detail-main', `
    <h2 style="margin-bottom:var(--space-5)">Editing: ${(p.name||'').replace(/</g,'&lt;')}</h2>
    <div class="admin-form-row"><label>Name</label><input id="ade-name" class="admin-input" type="text" value="${(p.name||'').replace(/"/g,'&quot;')}"></div>
    <div class="admin-form-row admin-form-row--3col">
      <div><label>Area</label><select id="ade-area" class="admin-select"><option value="">—</option>${areaOptions}</select></div>
      <div><label>Status</label><select id="ade-status" class="admin-select">${statusOptions}</select></div>
      <div><label>Type</label><select id="ade-type" class="admin-select">${typeOptions}</select></div>
    </div>
    <div class="admin-form-row admin-form-row--2col">
      <div><label>Min investment (USD)</label><input id="ade-ticket" class="admin-input" type="number" value="${p.min_investment_usd||''}"></div>
      <div><label>Yield range</label><input id="ade-yield" class="admin-input" type="text" value="${(p.expected_yield_range||'').replace(/"/g,'&quot;')}"></div>
    </div>
    <div class="admin-form-row"><label>Timeline summary</label><input id="ade-timeline" class="admin-input" type="text" value="${(p.timeline_summary||'').replace(/"/g,'&quot;')}"></div>
    <div class="admin-form-row"><label>Short description</label><textarea id="ade-short" class="admin-textarea" rows="3">${(p.short_description_en||p.short_description||'').replace(/</g,'&lt;')}</textarea></div>
    <div class="admin-form-row"><label>Full description</label><textarea id="ade-desc" class="admin-textarea" rows="7">${(p.description||'').replace(/</g,'&lt;')}</textarea></div>
    <div class="admin-form-row admin-form-row--2col">
      <div><label>Website</label><input id="ade-web" class="admin-input" type="url" value="${(p.website_url||'').replace(/"/g,'&quot;')}"></div>
      <div><label>Contact WhatsApp</label><input id="ade-wa" class="admin-input" type="text" value="${(p.info_contact_whatsapp||'').replace(/"/g,'&quot;')}"></div>
    </div>
    <div class="admin-form-row"><label>Logo URL</label><input id="ade-logo" class="admin-input" type="url" value="${(p.logo_url||'').replace(/"/g,'&quot;')}"></div>
    <div class="admin-form-row"><label>Hero image URL</label><input id="ade-hero" class="admin-input" type="url" value="${(p.hero_image_url||'').replace(/"/g,'&quot;')}"></div>
    <div class="admin-form-row admin-form-row--2col">
      <label class="admin-checkbox-label"><input id="ade-featured" type="checkbox"${p.is_featured?' checked':''}> Featured</label>
      <label class="admin-checkbox-label"><input id="ade-active" type="checkbox"${p.is_active!==false&&p.is_active!=0?' checked':''}> Active</label>
    </div>
    <div id="ade-error" class="admin-form-error" style="display:none"></div>
    <div class="admin-form-actions">
      <button class="btn btn--ghost" onclick="navigate('project/${slug}')">Cancel</button>
      <button class="btn btn--primary" id="ade-save-btn" onclick="adminProjectDetailSave(${id},'${slug}')">Save changes</button>
    </div>
  `);
  if (!ok) return;
}

async function adminProjectDetailSave(id, slug) {
  var btn = document.getElementById('ade-save-btn');
  var errEl = document.getElementById('ade-error');
  if (btn) { btn.disabled = true; btn.textContent = 'Saving…'; }
  if (errEl) errEl.style.display = 'none';
  try {
    await UserAuth.apiCall('admin_update_project', {
      id, name: _adminV('ade-name'), area_key: _adminV('ade-area'),
      status_key: _adminV('ade-status'), project_type_key: _adminV('ade-type'),
      min_investment_usd: _adminV('ade-ticket'), expected_yield_range: _adminV('ade-yield'),
      timeline_summary: _adminV('ade-timeline'),
      short_description: _adminV('ade-short'), description: _adminV('ade-desc'),
      website_url: _adminV('ade-web'), info_contact_whatsapp: _adminV('ade-wa'),
      logo_url: _adminV('ade-logo'), hero_image_url: _adminV('ade-hero'),
      is_featured: _adminC('ade-featured'), is_active: _adminC('ade-active'),
    });
    DataLayer.clearCache && DataLayer.clearCache();
    showToast('Project saved', 'success');
    navigate('project/' + slug);
  } catch(err) {
    if (errEl) { errEl.textContent = err.message || 'Save failed.'; errEl.style.display = 'block'; }
    if (btn) { btn.disabled = false; btn.textContent = 'Save changes'; }
  }
}

// =====================================================
// ADMIN: AGENT EDIT
// =====================================================

async function adminAgentQuickEdit(id, slug) {
  let agent;
  try { agent = await DataLayer.getAgent(slug); } catch(e) { showToast('Could not load agent', 'error'); return; }
  _adminModalOpen(id, 'Edit Agent', `
    <div class="admin-form-row"><label>Display name</label><input id="adm-name" class="admin-input" type="text" value="${(agent.display_name||'').replace(/"/g,'&quot;')}"></div>
    <div class="admin-form-row"><label>Agency name</label><input id="adm-agency" class="admin-input" type="text" value="${(agent.agency_name||'').replace(/"/g,'&quot;')}"></div>
    <div class="admin-form-row admin-form-row--2col">
      <div><label>Phone</label><input id="adm-phone" class="admin-input" type="text" value="${(agent.phone||'').replace(/"/g,'&quot;')}"></div>
      <div><label>WhatsApp</label><input id="adm-wa" class="admin-input" type="text" value="${(agent.whatsapp_number||'').replace(/"/g,'&quot;')}"></div>
    </div>
    <div class="admin-form-row"><label>Profile photo URL</label><input id="adm-photo" class="admin-input" type="url" value="${(agent.profile_photo_url||'').replace(/"/g,'&quot;')}"></div>
    <div class="admin-form-row"><label>Bio</label><textarea id="adm-bio" class="admin-textarea" rows="3">${(agent.bio||'').replace(/</g,'&lt;')}</textarea></div>
    <div class="admin-form-row admin-form-row--2col">
      <label class="admin-checkbox-label"><input id="adm-verified" type="checkbox"${agent.is_verified?' checked':''}> Verified</label>
      <label class="admin-checkbox-label"><input id="adm-active" type="checkbox"${agent.is_active!==false&&agent.is_active!=0?' checked':''}> Active</label>
    </div>
    <div id="adm-error" class="admin-form-error" style="display:none"></div>
  `, `adminAgentQuickSave(${id},'${slug}')`);
}

async function adminAgentQuickSave(id, slug) {
  await _adminModalSave('admin_update_agent', {
    id, display_name: _adminV('adm-name'), agency_name: _adminV('adm-agency'),
    phone: _adminV('adm-phone'), whatsapp_number: _adminV('adm-wa'),
    profile_photo_url: _adminV('adm-photo'), bio: _adminV('adm-bio'),
    is_verified: _adminC('adm-verified'), is_active: _adminC('adm-active'),
  }, slug, 'agent');
}

async function adminAgentDetailEdit(id, slug) {
  let agent;
  try { agent = await DataLayer.getAgent(slug); } catch(e) { showToast('Could not load agent', 'error'); return; }
  const ok = _adminInlineFormReplace('.detail-main', `
    <h2 style="margin-bottom:var(--space-5)">Editing: ${(agent.display_name||'').replace(/</g,'&lt;')}</h2>
    <div class="admin-form-row admin-form-row--2col">
      <div><label>Display name</label><input id="ade-name" class="admin-input" type="text" value="${(agent.display_name||'').replace(/"/g,'&quot;')}"></div>
      <div><label>Agency name</label><input id="ade-agency" class="admin-input" type="text" value="${(agent.agency_name||'').replace(/"/g,'&quot;')}"></div>
    </div>
    <div class="admin-form-row admin-form-row--2col">
      <div><label>Phone</label><input id="ade-phone" class="admin-input" type="text" value="${(agent.phone||'').replace(/"/g,'&quot;')}"></div>
      <div><label>WhatsApp</label><input id="ade-wa" class="admin-input" type="text" value="${(agent.whatsapp_number||'').replace(/"/g,'&quot;')}"></div>
    </div>
    <div class="admin-form-row admin-form-row--2col">
      <div><label>Email</label><input id="ade-email" class="admin-input" type="email" value="${(agent.email||'').replace(/"/g,'&quot;')}"></div>
      <div><label>Website</label><input id="ade-web" class="admin-input" type="url" value="${(agent.website_url||'').replace(/"/g,'&quot;')}"></div>
    </div>
    <div class="admin-form-row"><label>Profile photo URL</label><input id="ade-photo" class="admin-input" type="url" value="${(agent.profile_photo_url||'').replace(/"/g,'&quot;')}"></div>
    <div class="admin-form-row"><label>Bio</label><textarea id="ade-bio" class="admin-textarea" rows="5">${(agent.bio||'').replace(/</g,'&lt;')}</textarea></div>
    <div class="admin-form-row"><label>Areas served</label><input id="ade-areas" class="admin-input" type="text" value="${(agent.areas_served||'').replace(/"/g,'&quot;')}" placeholder="e.g. kuta_lombok,senggigi"></div>
    <div class="admin-form-row"><label>Languages</label><input id="ade-langs" class="admin-input" type="text" value="${(agent.languages||'').replace(/"/g,'&quot;')}" placeholder="e.g. Bahasa, English"></div>
    <div class="admin-form-row"><label>Google Maps URL</label><input id="ade-maps" class="admin-input" type="url" value="${(agent.google_maps_url||'').replace(/"/g,'&quot;')}"></div>
    <div class="admin-form-row admin-form-row--2col">
      <label class="admin-checkbox-label"><input id="ade-verified" type="checkbox"${agent.is_verified?' checked':''}> Verified</label>
      <label class="admin-checkbox-label"><input id="ade-active" type="checkbox"${agent.is_active!==false&&agent.is_active!=0?' checked':''}> Active</label>
    </div>
    <div id="ade-error" class="admin-form-error" style="display:none"></div>
    <div class="admin-form-actions">
      <button class="btn btn--ghost" onclick="navigate('agent/${slug}')">Cancel</button>
      <button class="btn btn--primary" id="ade-save-btn" onclick="adminAgentDetailSave(${id},'${slug}')">Save changes</button>
    </div>
  `);
  if (!ok) return;
}

async function adminAgentDetailSave(id, slug) {
  var btn = document.getElementById('ade-save-btn');
  var errEl = document.getElementById('ade-error');
  if (btn) { btn.disabled = true; btn.textContent = 'Saving…'; }
  if (errEl) errEl.style.display = 'none';
  try {
    await UserAuth.apiCall('admin_update_agent', {
      id, display_name: _adminV('ade-name'), agency_name: _adminV('ade-agency'),
      phone: _adminV('ade-phone'), whatsapp_number: _adminV('ade-wa'),
      email: _adminV('ade-email'), website_url: _adminV('ade-web'),
      profile_photo_url: _adminV('ade-photo'), bio: _adminV('ade-bio'),
      areas_served: _adminV('ade-areas'), languages: _adminV('ade-langs'),
      google_maps_url: _adminV('ade-maps'),
      is_verified: _adminC('ade-verified'), is_active: _adminC('ade-active'),
    });
    DataLayer.clearCache && DataLayer.clearCache();
    showToast('Agent saved', 'success');
    navigate('agent/' + slug);
  } catch(err) {
    if (errEl) { errEl.textContent = err.message || 'Save failed.'; errEl.style.display = 'block'; }
    if (btn) { btn.disabled = false; btn.textContent = 'Save changes'; }
  }
}

// Earned Reputation tier badge (ADR 0008). 'new' shows nothing to avoid clutter.
function renderReputationBadge(tier) {
  if (!tier || tier === 'new') return '';
  const label = tier === 'top' ? 'Top Agent' : 'Established';
  return '<span class="agent-rep agent-rep--' + tier + '">' + label + '</span>';
}

function renderAgentCard(agent, index = 0) {
  const _agentCard = `
    <a href="#agent/${agent.slug}" class="card agent-card" onclick="navigate('agent/${agent.slug}');return false;" style="animation-delay:${index * 60}ms">
      <div class="agent-card-avatar">
        ${agent.profile_photo_url
          ? '<img src="' + agent.profile_photo_url + '" alt="' + agent.display_name + '" onerror="this.style.display=\'none\'">'
          : '<div class="agent-avatar-placeholder">' + agent.display_name.charAt(0).toUpperCase() + '</div>'}
      </div>
      <div class="agent-card-info">
        <h3 class="agent-card-name">${agent.display_name}</h3>
        ${agent.agency_name ? '<div class="agent-card-agency">' + agent.agency_name + '</div>' : ''}
        ${agent.google_rating ? renderGoogleRating(agent.google_rating, agent.google_review_count, 'card') : ''}
        <div class="agent-card-meta">
          ${agent.listing_count ? '<span>' + agent.listing_count + ' listing' + (agent.listing_count > 1 ? 's' : '') + '</span>' : ''}
          ${agent.is_verified ? '<span class="agent-verified">Verified</span>' : ''}
          ${renderReputationBadge(agent.reputation_tier)}
        </div>
        ${agent.bio ? '<p class="agent-card-bio">' + (agent.bio.length > 120 ? agent.bio.substring(0, 120) + '...' : agent.bio) + '</p>' : ''}
      </div>
    </a>
  `;
  if (!isAdmin()) return _agentCard;
  return `<div class="admin-card-wrap" data-entity-id="${agent.id}"><button class="admin-edit-card-btn" onclick="event.stopPropagation();adminAgentQuickEdit(${agent.id},'${agent.slug}')"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg> Edit</button>${_agentCard}</div>`;
}

async function renderAgents(el, params = {}) {
  await FilterData.load();
  const agentRes = await DataLayer.getAgents(params);
  const agents = agentRes.data;
  const total = agentRes.meta.total;

  const areaOptions = buildAreaOptions(params.area || '');

  el.innerHTML = `
    <div class="dir-hero">
      <div class="container">
        <h1 class="dir-hero-title">Property Agents</h1>
        <p class="dir-hero-desc">Trusted advisors to guide you through every property decision in Lombok.</p>
      </div>
    </div>
    <div class="section">
      <div class="container">
        <div class="filters-bar">
          <div class="filter-group">
            <select id="fil-agent-area" class="filter-select" aria-label="Area">
              <option value="">All areas</option>
              ${areaOptions}
            </select>
          </div>
          <div class="filter-group">
            <select id="fil-agent-verified" class="filter-select" aria-label="Verification">
              <option value="">All agents</option>
              <option value="1" ${params.verified === '1' ? 'selected' : ''}>Verified only</option>
            </select>
          </div>
        </div>
        <div class="agent-grid" id="agents-grid">
          ${agents.length > 0
            ? agents.map((a, i) => renderAgentCard(a, i)).join('')
            : '<div class="empty-state"><h3 class="empty-state-title">No agents found</h3><p class="empty-state-desc">Check back soon as more agents join the platform.</p></div>'}
        </div>
      </div>
    </div>

    <div class="section" style="padding-top:0">
      <div class="container container--narrow" style="text-align:center">
        <div class="help-cta">
          <h3 class="help-cta-title">Are you a property agent?</h3>
          <p class="help-cta-desc">Sign up as an agent and list properties on Build in Lombok.</p>
          <button onclick="navigate('agent-signup')" class="btn btn--primary">Register as Agent</button>
        </div>
      </div>
    </div>
  `;

  el.querySelector('#fil-agent-area') && el.querySelector('#fil-agent-area').addEventListener('change', () => {
    const p = {};
    const area = el.querySelector('#fil-agent-area').value;
    const verified = el.querySelector('#fil-agent-verified').value;
    if (area) p.area = area;
    if (verified) p.verified = verified;
    navigate(buildHash('agents', p));
  });
  el.querySelector('#fil-agent-verified') && el.querySelector('#fil-agent-verified').addEventListener('change', () => {
    const p = {};
    const area = el.querySelector('#fil-agent-area').value;
    const verified = el.querySelector('#fil-agent-verified').value;
    if (area) p.area = area;
    if (verified) p.verified = verified;
    navigate(buildHash('agents', p));
  });

  requestAnimationFrame(() => animateCards(el));
}

// =====================================================
// RENDER: AGENT DETAIL
// =====================================================

async function renderAgentDetail(el, slug) {
  if (!slug) { el.innerHTML = renderNotFound('Agent'); return; }
  let agent;
  try { agent = await DataLayer.getAgent(slug); }
  catch(e) { el.innerHTML = renderNotFound('Agent'); return; }

  let agentListings = [];
  try {
    const res = await DataLayer.getListings({ agent_slug: slug });
    agentListings = res.data;
  } catch(e) { /* silent */ }

  const wa = agent.whatsapp_number || '';

  el.innerHTML = `
    ${isAdmin() ? `<div class="admin-detail-bar"><span class="admin-detail-bar-label">Admin</span><button class="btn btn--primary btn--sm" onclick="adminAgentDetailEdit(${agent.id},'${slug}')"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="margin-right:4px"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>Edit this listing</button></div>` : ''}
    <div class="detail-hero">
      <div class="container">
        <div class="detail-hero-inner">
          ${agent.profile_photo_url ? '<img src="' + agent.profile_photo_url + '" alt="' + agent.display_name + '" class="detail-hero-photo" style="border-radius:50%;" onerror="this.style.display=\'none\'">' : '<div style="width:100px;height:100px;border-radius:50%;background:rgba(12,124,132,0.5);display:flex;align-items:center;justify-content:center;font-size:2.5rem;font-weight:700;color:#fff;flex-shrink:0;">' + agent.display_name.charAt(0).toUpperCase() + '</div>'}
          <div class="detail-hero-info">
            <div class="detail-hero-badges">
              ${agent.is_verified ? '<span class="badge badge--trusted-light">\u2713 Verified Agent</span>' : ''}
              ${agent.reputation_tier && agent.reputation_tier !== 'new' ? '<span class="badge badge--light">' + (agent.reputation_tier === 'top' ? 'Top Agent' : 'Established') + '</span>' : ''}
              ${agent.agency_name ? '<span class="badge badge--light">' + agent.agency_name + '</span>' : ''}
            </div>
            <h1 class="detail-hero-name">${agent.display_name}</h1>
          </div>
        </div>
      </div>
    </div>
    <div class="section">
      <div class="container">
        <div class="detail-subheading">
          <p class="detail-subheading-specialty">Property Agent</p>
          <div class="detail-subheading-meta">
            ${agent.area_label ? '<span>'+iconMapPin()+' '+agent.area_label+'</span>' : ''}
            ${agent.listing_count ? '<span>'+agent.listing_count+' listing'+(agent.listing_count > 1 ? 's' : '')+'</span>' : ''}
          </div>
        </div>
        <div class="detail-layout">
          <div class="detail-main">
            ${agent.google_rating ? '<div class="detail-rating-row">' + renderGoogleRating(agent.google_rating, agent.google_review_count, 'detail') + '</div>' : ''}
            ${agent.bio ? '<h2 class="detail-section-title">About</h2><p class="detail-description">' + agent.bio + '</p>' : ''}
            ${agentListings.length > 0 ? '<h2 class="detail-section-title" style="margin-bottom:var(--space-5)">Listings by ' + agent.display_name + '</h2><div class="card-grid listings-grid">' + agentListings.map((l, i) => renderListingCard(l, i)).join('') + '</div>' : '<p class="text-muted" style="color:var(--color-text-muted)">No active listings yet.</p>'}
          </div>
          <div class="detail-sidebar">
            <div class="detail-card">
              <div class="detail-card-title">Contact</div>
              <div class="info-list mb-4">
                ${agent.phone ? '<div class="info-row"><span class="info-icon">' + iconPhone() + '</span><span class="info-value"><a href="tel:' + agent.phone + '">' + agent.phone + '</a></span></div>' : ''}
                ${agent.email ? '<div class="info-row"><span class="info-icon">' + iconGlobe() + '</span><span class="info-value">' + agent.email + '</span></div>' : ''}
                ${agent.area_label ? '<div class="info-row"><span class="info-icon">' + iconMapPin() + '</span><span class="info-value">' + agent.area_label + '</span></div>' : ''}
              </div>
              ${wa ? '<a href="https://wa.me/' + wa.replace(/[^0-9]/g, '') + '" target="_blank" rel="noopener noreferrer" class="btn btn--whatsapp btn--full">' + iconWhatsApp() + ' WhatsApp</a>' : ''}
              <button onclick="checkReviewUpdates('agent', ${agent.id})" class="btn btn--ghost btn--full" style="margin-top:var(--space-2)">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg>
                Check for Review Updates
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>
  `;
  requestAnimationFrame(() => animateCards(el));
}

// =====================================================
// RENDER: AGENT SIGNUP
// =====================================================

async function renderAgentSignup(el) {
  if (!UserAuth.user) {
    showAuthModal('login');
    navigate('home');
    return;
  }

  await FilterData.load();
  const areaOptions = buildAreaOptions('');

  el.innerHTML = `
    <div class="page-header">
      <div class="container">
        <h1 class="page-title">Register as a Property Agent</h1>
        <p class="page-desc">Create your agent profile and start listing properties on Build in Lombok.</p>
      </div>
    </div>
    <div class="section">
      <div class="container container--narrow">
        <div class="auth-error" id="agent-signup-error"></div>
        <div class="auth-success" id="agent-signup-success"></div>
        <form class="submit-form" id="agent-signup-form">
          <div class="auth-field">
            <label>Display Name *</label>
            <input type="text" name="display_name" required value="${UserAuth.user.display_name || ''}">
          </div>
          <div class="auth-field">
            <label>Agency / Company Name</label>
            <input type="text" name="agency_name" placeholder="Your agency name (optional)">
          </div>
          <div class="auth-field">
            <label>Phone *</label>
            <input type="text" name="phone" required placeholder="+62...">
          </div>
          <div class="auth-field">
            <label>WhatsApp Number *</label>
            <input type="text" name="whatsapp_number" required placeholder="628...">
          </div>
          <div class="auth-field">
            <label>Primary Area</label>
            <select name="area_key">
              <option value="">Select area...</option>
              ${areaOptions}
            </select>
          </div>
          <div class="auth-field">
            <label>Bio</label>
            <textarea name="bio" maxlength="1000" placeholder="Describe your experience and specialties..."></textarea>
          </div>
          <div class="auth-field">
            <label>Google Maps / Business Link</label>
            <input type="url" name="google_maps_url" placeholder="https://maps.google.com/...">
          </div>
          <button type="submit" class="auth-submit" style="align-self:flex-start">Register as Agent</button>
        </form>
      </div>
    </div>
  `;

  el.querySelector('#agent-signup-form').addEventListener('submit', async (e) => {
    e.preventDefault();
    const form = e.target;
    const btn = form.querySelector('.auth-submit');
    const errEl = document.getElementById('agent-signup-error');
    const sucEl = document.getElementById('agent-signup-success');
    btn.disabled = true; btn.textContent = 'Submitting...';
    errEl.classList.remove('visible'); sucEl.classList.remove('visible');
    const fd = new FormData(form);
    const data = {};
    fd.forEach((v, k) => { data[k] = v; });
    try {
      const res = await UserAuth.apiCall('agent_signup', data);
      sucEl.textContent = res.message || 'Your agent profile has been submitted for review.';
      sucEl.classList.add('visible');
      form.style.display = 'none';
    } catch(err) {
      errEl.textContent = err.message;
      errEl.classList.add('visible');
      btn.disabled = false; btn.textContent = 'Register as Agent';
    }
  });
}

// =====================================================
// RENDER: CREATE LISTING
// =====================================================

async function renderCreateListing(el) {
  if (!UserAuth.user) {
    showAuthModal('login');
    navigate('home');
    return;
  }

  await FilterData.load();
  const areaOptions = buildAreaOptions('');
  const typeOptions = FilterData.listing_types.map(t => '<option value="' + t.key + '">' + t.label + '</option>').join('');
  const certOptions = FilterData.land_certificate_types.map(c => '<option value="' + c.key + '">' + c.label + '</option>').join('');

  el.innerHTML = `
    <div class="page-header">
      <div class="container">
        <h1 class="page-title">Post a Property Listing</h1>
        <p class="page-desc">Create a listing to reach foreign investors and buyers across Lombok.</p>
      </div>
    </div>
    <div class="section">
      <div class="container container--narrow">
        <div class="auth-error" id="cl-error"></div>
        <div class="auth-success" id="cl-success"></div>
        <form class="submit-form" id="create-listing-form" enctype="multipart/form-data">
          <div class="auth-field">
            <label>Listing Title *</label>
            <input type="text" name="title" required placeholder="e.g. 5 Are Freehold Land, Kuta Lombok">
          </div>
          <div class="form-row">
            <div class="auth-field">
              <label>Listing Type *</label>
              <select name="listing_type_key" required>
                <option value="">Select type...</option>
                ${typeOptions}
              </select>
            </div>
            <div class="auth-field">
              <label>Area *</label>
              <select name="area_key" required>
                <option value="">Select area...</option>
                ${areaOptions}
              </select>
            </div>
          </div>
          <div class="form-row">
            <div class="auth-field">
              <label>Price (USD)</label>
              <input type="number" name="price_usd" min="0" placeholder="e.g. 120000">
            </div>
            <div class="auth-field">
              <label>Price (IDR)</label>
              <input type="number" name="price_idr" min="0" placeholder="e.g. 1800000000">
            </div>
          </div>
          <div class="auth-field">
            <label>Price Label / Notes</label>
            <input type="text" name="price_label" placeholder="e.g. negotiable, per are">
          </div>
          <div class="form-row">
            <div class="auth-field">
              <label>Land Size (sqm)</label>
              <input type="number" name="land_size_sqm" min="0">
            </div>
            <div class="auth-field">
              <label>Land Size (are)</label>
              <input type="number" name="land_size_are" min="0" step="0.01">
            </div>
          </div>
          <div class="form-row">
            <div class="auth-field">
              <label>Building Size (sqm)</label>
              <input type="number" name="building_size_sqm" min="0">
            </div>
            <div class="auth-field">
              <label>Certificate Type</label>
              <select name="certificate_type_key">
                <option value="">Select...</option>
                ${certOptions}
              </select>
            </div>
          </div>
          <div class="form-row">
            <div class="auth-field">
              <label>Bedrooms</label>
              <input type="number" name="bedrooms" min="0">
            </div>
            <div class="auth-field">
              <label>Bathrooms</label>
              <input type="number" name="bathrooms" min="0">
            </div>
          </div>
          <div class="auth-field">
            <label>Short Description *</label>
            <textarea name="short_description" required maxlength="500" placeholder="Brief summary of the listing..."></textarea>
          </div>
          <div class="auth-field">
            <label>Full Description</label>
            <textarea name="description" maxlength="5000" style="min-height:120px" placeholder="Detailed description, location details, access, views, etc."></textarea>
          </div>
          <div class="auth-field">
            <label>Address</label>
            <input type="text" name="address" placeholder="Street address or landmark">
          </div>
          <div class="auth-field">
            <label>Google Maps URL</label>
            <input type="url" name="google_maps_url" placeholder="https://maps.google.com/...">
          </div>
          <div class="form-row">
            <div class="auth-field">
              <label>Contact WhatsApp</label>
              <input type="text" name="contact_whatsapp" placeholder="628...">
            </div>
            <div class="auth-field">
              <label>Contact Phone</label>
              <input type="text" name="contact_phone" placeholder="+62...">
            </div>
          </div>
          <div class="auth-field">
            <label>Images</label>
            <div class="image-drop-zone" id="image-drop-zone" style="border:2px dashed var(--color-border);border-radius:var(--radius-md);padding:var(--space-8);text-align:center;cursor:pointer;transition:border-color var(--transition-interactive);">
              <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="margin:0 auto var(--space-3);display:block;opacity:0.4"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="m21 15-5-5L5 21"/></svg>
              <p style="margin:0;font-size:var(--text-sm);color:var(--color-text-muted)">Drag & drop images here, or <label for="listing-images" style="color:var(--color-primary);cursor:pointer;font-weight:600">browse files</label></p>
              <input type="file" id="listing-images" name="images" multiple accept="image/*" style="display:none">
            </div>
            <div id="image-preview" style="display:flex;flex-wrap:wrap;gap:var(--space-2);margin-top:var(--space-3);"></div>
          </div>
          <button type="submit" class="auth-submit" style="align-self:flex-start">Submit Listing for Review</button>
        </form>
      </div>
    </div>
  `;

  // Image drag & drop
  const dropZone = el.querySelector('#image-drop-zone');
  const fileInput = el.querySelector('#listing-images');
  const preview = el.querySelector('#image-preview');

  function showPreviews(files) {
    preview.innerHTML = '';
    Array.from(files).forEach(file => {
      const reader = new FileReader();
      reader.onload = (e) => {
        const img = document.createElement('img');
        img.src = e.target.result;
        img.style.cssText = 'width:80px;height:80px;object-fit:cover;border-radius:var(--radius-sm);';
        preview.appendChild(img);
      };
      reader.readAsDataURL(file);
    });
  }

  dropZone.addEventListener('dragover', (e) => { e.preventDefault(); dropZone.style.borderColor = 'var(--color-primary)'; });
  dropZone.addEventListener('dragleave', () => { dropZone.style.borderColor = 'var(--color-border)'; });
  dropZone.addEventListener('drop', (e) => {
    e.preventDefault();
    dropZone.style.borderColor = 'var(--color-border)';
    fileInput.files = e.dataTransfer.files;
    showPreviews(e.dataTransfer.files);
  });
  dropZone.addEventListener('click', () => fileInput.click());
  fileInput.addEventListener('change', () => showPreviews(fileInput.files));

  // Submit
  el.querySelector('#create-listing-form').addEventListener('submit', async (e) => {
    e.preventDefault();
    const form = e.target;
    const btn = form.querySelector('.auth-submit');
    const errEl = document.getElementById('cl-error');
    const sucEl = document.getElementById('cl-success');
    btn.disabled = true; btn.textContent = 'Submitting...';
    errEl.classList.remove('visible'); sucEl.classList.remove('visible');
    const fd = new FormData(form);
    const data = {};
    fd.forEach((v, k) => { if (k !== 'images') data[k] = v; });
    try {
      const res = await UserAuth.apiCall('create_listing', data);
      sucEl.textContent = res.message || 'Your listing has been submitted for review.';
      sucEl.classList.add('visible');
      form.style.display = 'none';
    } catch(err) {
      errEl.textContent = err.message;
      errEl.classList.add('visible');
      btn.disabled = false; btn.textContent = 'Submit Listing for Review';
    }
  });
}

// =====================================================
// RENDER: PROJECT CARD
// =====================================================

// Cache for developer lookups in project cards
let _cachedDevelopers = [];

function renderProjectCard(p, index = 0) {
  const dev = _cachedDevelopers.find(d => d.id === p.developer_id);
  const badge = p.badge ? `<span class="card-badge">${renderBadge(p.badge)}</span>` : '';
  const statusLabel = formatProjectStatus(p.status);

  const _projCard = `
    <article class="card card-animate" style="animation-delay:${index * 50}ms">
      <div class="card-top">
        <div class="card-top-left">
          <span class="card-category-label">${formatProjectType(p.project_type)}</span>
          ${badge}
        </div>
        <span class="card-status card-status--${p.status}">${statusLabel}</span>
      </div>
      <h3 class="card-name"><a href="#project/${p.slug}" onclick="navigate('project/${p.slug}');return false;">${p.name}</a></h3>
      <p class="card-desc">${p.short_description_en}</p>
      <div class="card-meta-line">
        <span class="card-meta-item">${iconMapPin()} ${formatAreaLabel(p.location_area)}</span>
      </div>
      <div class="card-facts-row">
        <div class="card-fact">
          <span class="card-fact-label">From</span>
          <span class="card-fact-value">${projectPriceHtml(p.min_investment_usd)}</span>
        </div>
        <div class="card-fact">
          <span class="card-fact-label">Yield</span>
          <span class="card-fact-value">${p.expected_yield_range}</span>
        </div>
      </div>
      ${dev ? `
        <div class="card-developer-line">
          <span>by</span>
          <button onclick="navigate('developer/${dev.slug}')" class="card-developer-link">${dev.name}</button>
          ${dev.google_rating ? `<span class="card-rating-inline card-rating-inline--sm"><span class="card-rating-star">★</span> ${dev.google_rating.toFixed(1)}</span>` : ''}
        </div>
      ` : ''}
      <div class="card-footer">
        <button class="card-view-btn" onclick="navigate('project/${p.slug}')">
          View project ${iconArrowRight()}
        </button>
        <div class="card-footer-right">${renderFavBtn('project', p.id)}${p.info_contact_whatsapp ? `<a href="https://wa.me/${p.info_contact_whatsapp}" target="_blank" rel="noopener noreferrer" class="card-wa-btn" aria-label="Request info">${iconWhatsApp()}</a>` : ''}</div>
      </div>
    </article>
  `;
  if (!isAdmin()) return _projCard;
  return `<div class="admin-card-wrap" data-entity-id="${p.id}"><button class="admin-edit-card-btn" onclick="event.stopPropagation();adminProjectQuickEdit(${p.id},'${p.slug}')"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg> Edit</button>${_projCard}</div>`;
}

// =====================================================
// RENDER: PROJECTS PAGE
// =====================================================

async function renderProjects(el, params = {}) {
  await FilterData.load();
  const filters = {
    area: params.area || '',
    project_type: params.project_type || '',
    status: params.status || '',
    sort: params.sort || 'featured'
  };

  async function applyAndRender() {
    const grid = el.querySelector('#project-grid');
    const countEl = el.querySelector('#proj-count');
    if (!grid) return;

    const apiParams = { per_page: 100 };
    // Ensure developer cache is loaded for project cards
    if (_cachedDevelopers.length === 0) {
      try { const dr = await DataLayer.getDevelopers({ per_page: 100 }); _cachedDevelopers = dr.data; } catch(e) {}
    }
    if (filters.area) {
      if (filters.area.startsWith('region:')) {
        apiParams.region = filters.area.replace('region:', '');
      } else {
        apiParams.area = filters.area;
      }
    }
    if (filters.project_type) apiParams.type = filters.project_type;
    if (filters.status) apiParams.status = filters.status;
    if (filters.sort === 'investment_low') { apiParams.sort = 'min_investment_usd'; apiParams.dir = 'ASC'; }
    else if (filters.sort === 'investment_high') { apiParams.sort = 'min_investment_usd'; apiParams.dir = 'DESC'; }

    try {
      const res = await DataLayer.getProjects(apiParams);
      const results = res.data;

      if (countEl) countEl.innerHTML = `<strong>${results.length}</strong> project${results.length !== 1 ? 's' : ''} found`;

      if (results.length === 0) {
        grid.innerHTML = `<div class="empty-state" style="grid-column:1/-1;"><div class="empty-state-icon">${iconSearch()}</div><h3 class="empty-state-title">No projects match your filters</h3><p class="empty-state-desc">Try widening your criteria.</p><button class="btn btn--secondary btn--sm" onclick="clearProjectFilters()">Clear filters</button></div>`;
      } else {
        grid.innerHTML = results.map((p, i) => renderProjectCard(p, i)).join('');
      }
    } catch(e) {
      console.error('Failed to load projects:', e);
      grid.innerHTML = '<div class="empty-state" style="grid-column:1/-1;"><p>Unable to load projects.</p></div>';
    }
    requestAnimationFrame(() => animateCards(el));
    navigate(buildHash('projects', Object.fromEntries(Object.entries(filters).filter(([,v]) => v !== '' && v !== 'featured'))));
  }

  window.clearProjectFilters = function() {
    filters.area = ''; filters.project_type = ''; filters.status = '';
    el.querySelectorAll('.filter-select').forEach(s => s.value = '');
    applyAndRender();
  };

  const activeCount = Object.entries(filters).filter(([k, v]) => k !== 'sort' && v !== '').length;

  el.innerHTML = `
    <div class="dir-hero">
      <div class="container">
        <h1 class="dir-hero-title">Investment Projects</h1>
        <p class="dir-hero-desc">Active villa, apartment, land, and mixed-use developments from verified Lombok developers.</p>
      </div>
    </div>
    <div class="section">
      <div class="container">
        <div class="filters-bar">
          <button class="filters-toggle-btn" onclick="this.closest('.filters-bar').querySelector('.filters-body').classList.toggle('open')">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>
            Filters ${activeCount > 0 ? `<span class="badge badge--verified">${activeCount}</span>` : ''}
          </button>
          <div class="filters-body ${activeCount > 0 ? 'open' : ''}">
            <div class="filters-grid">
              <div class="filter-group">
                <label class="filter-label" for="pf-area">Area</label>
                <select id="pf-area" class="filter-select" onchange="updateProjectFilter('area', this.value)">
                  <option value="">All areas</option>
                  ${buildAreaOptions(filters.area)}
                </select>
              </div>
              <div class="filter-group">
                <label class="filter-label" for="pf-type">Type</label>
                <select id="pf-type" class="filter-select" onchange="updateProjectFilter('project_type', this.value)">
                  <option value="">All types</option>
                  ${buildFilterOptions(FilterData.project_types, filters.project_type)}
                </select>
              </div>
              <div class="filter-group">
                <label class="filter-label" for="pf-status">Status</label>
                <select id="pf-status" class="filter-select" onchange="updateProjectFilter('status', this.value)">
                  <option value="">Any status</option>
                  ${buildFilterOptions(FilterData.project_statuses, filters.status)}
                </select>
              </div>
              <div class="filter-group">
                <label class="filter-label" for="pf-sort">Sort by</label>
                <select id="pf-sort" class="filter-select" onchange="updateProjectFilter('sort', this.value)">
                  <option value="featured" ${filters.sort === 'featured' ? 'selected' : ''}>Featured first</option>
                  <option value="investment_low" ${filters.sort === 'investment_low' ? 'selected' : ''}>Lowest investment</option>
                  <option value="investment_high" ${filters.sort === 'investment_high' ? 'selected' : ''}>Highest investment</option>
                </select>
              </div>
            </div>
            <div class="filters-footer">
              <p class="filters-active-count">${activeCount > 0 ? `<strong>${activeCount}</strong> filter${activeCount !== 1 ? 's' : ''} active` : 'No filters active'}</p>
              ${activeCount > 0 ? `<button class="btn btn--secondary btn--sm" onclick="clearProjectFilters()">Clear all</button>` : ''}
            </div>
          </div>
        </div>
        <div class="listings-toolbar">
          <p class="results-count" id="proj-count" style="margin:0"></p>
          <div class="cur-pills" id="proj-cur-pills" role="group" aria-label="${t('currency.label', 'Display currency')}">
            ${Currency.LIST.map(c => `<button type="button" class="cur-pill${c === Currency.get() ? ' active' : ''}" data-cur="${c}" aria-pressed="${c === Currency.get()}">${c}</button>`).join('')}
          </div>
        </div>
        <div class="card-grid" id="project-grid"></div>
      </div>
    </div>
  `;

  window.updateProjectFilter = function(key, value) {
    filters[key] = value;
    applyAndRender();
  };

  el.querySelector('#proj-cur-pills').addEventListener('click', function(e) {
    const pill = e.target.closest('.cur-pill');
    if (!pill) return;
    Currency.set(pill.getAttribute('data-cur'));
    el.querySelectorAll('#proj-cur-pills .cur-pill').forEach(p => {
      const on = p.getAttribute('data-cur') === Currency.get();
      p.classList.toggle('active', on);
      p.setAttribute('aria-pressed', on ? 'true' : 'false');
    });
    applyAndRender();
  });

  applyAndRender();
}

// =====================================================
// RENDER: PROJECT DETAIL
// =====================================================

async function renderProjectDetail(el, slug) {
  let p;
  try {
    p = await DataLayer.getProject(slug);
  } catch(e) { console.error('Failed to load project:', e); }
  if (!p) { el.innerHTML = renderNotFound('Project'); return; }
  const dev = p.developer_name ? { name: p.developer_name, slug: p.developer_slug } : null;

  el.innerHTML = `
    ${isAdmin() ? `<div class="admin-detail-bar"><span class="admin-detail-bar-label">Admin</span><button class="btn btn--primary btn--sm" onclick="adminProjectDetailEdit(${p.id},'${slug}')"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="margin-right:4px"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>Edit this listing</button></div>` : ''}
    <div class="page-header">
      <div class="container">
        <div class="card-meta mb-3">
          <span class="badge ${getStatusBadgeClass(p.status)}">${formatProjectStatus(p.status)}</span>
          <span class="badge badge--project-type">${formatProjectType(p.project_type)}</span>
          ${p.badge ? `<span class="badge badge--new">${renderBadge(p.badge)}</span>` : ''}
        </div>
        <h1 class="page-title">${p.name}</h1>
        <div class="card-meta">
          <span class="meta-chip">${iconMapPin()} ${formatAreaLabel(p.location_area)}</span>
          ${dev ? `<span class="meta-chip">by <button onclick="navigate('developer/${dev.slug}')" style="color:var(--color-primary);font-weight:600;background:none;border:none;cursor:pointer;padding:0;font-size:inherit;">${dev.name}</button></span>` : ''}
        </div>
      </div>
    </div>
    <div class="section">
      <div class="container">
        <div class="key-facts" style="margin-bottom:var(--space-8);">
          <div class="key-fact">
            <div class="key-fact-label">Min Investment</div>
            <div class="key-fact-value">${projectPriceHtml(p.min_investment_usd)}</div>
          </div>
          <div class="key-fact">
            <div class="key-fact-label">Expected Yield</div>
            <div class="key-fact-value" style="font-size:var(--text-base);">${p.expected_yield_range}</div>
          </div>
          <div class="key-fact">
            <div class="key-fact-label">Timeline</div>
            <div class="key-fact-value" style="font-size:var(--text-base);">${p.timeline_summary}</div>
          </div>
          <div class="key-fact">
            <div class="key-fact-label">Type</div>
            <div class="key-fact-value" style="font-size:var(--text-base);">${formatProjectType(p.project_type)}</div>
          </div>
          <div class="key-fact">
            <div class="key-fact-label">Location</div>
            <div class="key-fact-value" style="font-size:var(--text-base);">${formatAreaLabel(p.location_area)}</div>
          </div>
          <div class="key-fact">
            <div class="key-fact-label">Status</div>
            <div class="key-fact-value" style="font-size:var(--text-base);">${formatProjectStatus(p.status)}</div>
          </div>
        </div>

        <div class="detail-layout">
          <div class="detail-main">
            <h2 class="detail-section-title">About This Project</h2>
            <p class="detail-description">${p.description_en}</p>

            <h2 class="detail-section-title">Tags</h2>
            <div class="detail-tags mb-6">
              ${p.tags.map(t => `<span class="tag">${t}</span>`).join('')}
            </div>

            ${dev ? `
              <h2 class="detail-section-title">Developer</h2>
              <div class="card" style="max-width:480px;margin-bottom:var(--space-6);">
                <div class="card-header">
                  <h3 class="card-name">${dev.name}</h3>
                  ${dev.badge ? `<span class="badge badge--featured">${renderBadge(dev.badge)}</span>` : ''}
                </div>
                ${renderGoogleRating(dev.google_rating, dev.google_review_count)}
                <p class="card-desc">${dev.short_description_en}</p>
                <div class="card-actions">
                  <button class="btn btn--secondary btn--sm" onclick="navigate('developer/${dev.slug}')">View Developer</button>
                </div>
              </div>
            ` : ''}
          </div>

          <div class="detail-sidebar">
            <div class="detail-card">
              <div class="detail-card-title">Request Information</div>
              <p style="font-size:var(--text-sm);color:var(--color-text-muted);margin-bottom:var(--space-4);max-width:none;">Get full project details, floor plans, and pricing direct from the developer.</p>
              ${p.info_contact_whatsapp ? `<a href="https://wa.me/${p.info_contact_whatsapp}" target="_blank" rel="noopener noreferrer" class="btn btn--whatsapp btn--full">${iconWhatsApp()} Request Info via WhatsApp</a>` : ''}
              ${p.website_url ? `<a href="${p.website_url}" target="_blank" rel="noopener noreferrer" class="btn btn--secondary btn--full" style="margin-top:var(--space-2);">${iconGlobe()} Project Website ${iconExternalLink()}</a>` : ''}
            </div>
          </div>
        </div>
      </div>
    </div>
  `;
}

// =====================================================
// RENDER: GUIDES
// =====================================================

function renderGuideCard(g, index = 0) {
  return `
    <article class="guide-card card-animate" style="animation-delay:${index * 50}ms" onclick="navigate('guide/${g.slug}')" tabindex="0" role="button" aria-label="Read: ${g.title}" onkeydown="if(event.key==='Enter')navigate('guide/${g.slug}')">
      <div class="guide-category">${g.category}</div>
      <h3 class="guide-title">${g.title}</h3>
      <p class="guide-excerpt">${g.excerpt}</p>
      <div class="guide-meta">
        <span>${g.read_time}</span>
        <span style="margin-left:auto;color:var(--color-primary);font-weight:500;display:flex;align-items:center;gap:var(--space-1);">Read ${iconArrowRight()}</span>
      </div>
    </article>
  `;
}

async function renderGuides(el) {
  let guides = [];
  try {
    guides = await DataLayer.getGuides();
  } catch(e) { console.error('Failed to load guides:', e); }

  el.innerHTML = `
    <div class="page-header">
      <div class="container">
        <h1 class="page-title">Building & Investment Guides</h1>
        <p class="page-desc">Practical guidance for foreign investors and builders navigating Lombok's property and construction landscape.</p>
      </div>
    </div>
    <div class="section">
      <div class="container">
        <div class="card-grid">
          ${guides.map((g, i) => renderGuideCard(g, i)).join('')}
        </div>
      </div>
    </div>
  `;
  requestAnimationFrame(() => animateCards(el));
}

async function renderGuideDetail(el, slug) {
  let g;
  try {
    g = await DataLayer.getGuide(slug);
  } catch(e) { console.error('Failed to load guide:', e); }
  if (!g) { el.innerHTML = renderNotFound('Guide'); return; }

  let otherGuides = [];
  try {
    const all = await DataLayer.getGuides();
    otherGuides = all.filter(og => og.slug !== g.slug);
  } catch(e) {}

  el.innerHTML = `
    <div class="page-header">
      <div class="container">
        <div class="guide-category mb-3">${g.category} · ${g.read_time}</div>
        <h1 class="page-title">${g.title}</h1>
      </div>
    </div>
    <div class="section">
      <div class="container">
        <div class="detail-layout">
          <article class="guide-article">
            ${g.content}
          </article>
          <div class="detail-sidebar">
            <div class="detail-card">
              <div class="detail-card-title">More Guides</div>
              <div style="display:flex;flex-direction:column;gap:var(--space-3);">
                ${otherGuides.map(og => `
                  <button onclick="navigate('guide/${og.slug}')" style="text-align:left;padding:var(--space-3);border-radius:var(--radius-md);background:var(--color-surface-offset);border:1px solid var(--color-border);cursor:pointer;transition:background var(--transition-interactive);" onmouseover="this.style.background='var(--color-surface-dynamic)'" onmouseout="this.style.background='var(--color-surface-offset)'">
                    <div style="font-size:var(--text-xs);color:var(--color-primary);font-weight:600;margin-bottom:var(--space-1);">${og.category}</div>
                    <div style="font-size:var(--text-sm);font-family:var(--font-display);color:var(--color-text);line-height:1.3;">${og.title}</div>
                  </button>
                `).join('')}
              </div>
            </div>
            <div class="help-cta" style="padding:var(--space-5);">
              <h3 class="help-cta-title" style="font-size:var(--text-base);">Find the right builder</h3>
              <p class="help-cta-desc" style="font-size:var(--text-xs);">Browse our directory of verified contractors, architects, and specialists.</p>
              <button onclick="navigate('directory')" class="btn btn--primary btn--sm">Browse Directory</button>
            </div>
          </div>
        </div>
      </div>
    </div>
  `;
}

// =====================================================
// UTILITY RENDERS
// =====================================================

function renderNotFound(type) {
  return `
    <div class="section">
      <div class="container">
        <div class="empty-state">
          <div class="empty-state-icon">
            <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
          </div>
          <h2 class="empty-state-title">${type} not found</h2>
          <p class="empty-state-desc">The page you're looking for doesn't exist or has been removed.</p>
          <button onclick="navigate('home')" class="btn btn--primary">Back to home</button>
        </div>
      </div>
    </div>
  `;
}

function animateCards(container) {
  container.querySelectorAll('.card-animate').forEach((card, i) => {
    card.style.animationDelay = `${i * 45}ms`;
    card.classList.remove('card-animate');
    void card.offsetWidth;
    card.classList.add('card-animate');
  });

  // Initialize hero background parallax/animation
  var heroBg = container.querySelector('#hero-bg') || document.querySelector('#hero-bg');
  if (heroBg) {
    requestAnimationFrame(function() {
      heroBg.classList.add('loaded');
    });
  }

  // Section scroll-in animations
  initSectionAnimations(container);
}

function initSectionAnimations(container) {
  if (!window.IntersectionObserver) return;
  var sections = (container || document).querySelectorAll('.section-animate');
  if (!sections.length) return;

  var observer = new IntersectionObserver(function(entries) {
    entries.forEach(function(entry) {
      if (entry.isIntersecting) {
        entry.target.classList.add('visible');
        observer.unobserve(entry.target);
      }
    });
  }, { threshold: 0.08, rootMargin: '0px 0px -40px 0px' });

  sections.forEach(function(section) {
    observer.observe(section);
  });
}

// =====================================================
// GLOBAL SEARCH — see CommandPalette (ADR-0001) at the bottom of this file.
// The legacy initSearch() was retired when the command palette shipped.
// =====================================================

// =====================================================
// USER AUTH LAYER
// =====================================================

const UserAuth = (() => {
  const API = '/api/user.php';
  let currentUser = null;
  let userFavs = new Set(); // 'provider:5', 'developer:3', etc.

  async function apiCall(action, data = null) {
    const url = `${API}?action=${action}`;
    const opts = { credentials: 'include' };
    if (data) {
      opts.method = 'POST';
      opts.headers = { 'Content-Type': 'application/json' };
      opts.body = JSON.stringify(data);
    }
    const res = await fetch(url, opts);
    const json = await res.json();
    if (!res.ok) throw new Error(json.error || 'Request failed');
    return json;
  }

  async function checkSession() {
    try {
      const res = await apiCall('me');
      currentUser = res.user;
      if (currentUser) {
        await loadFavorites();
        QuoteTracker.load().catch(() => {});
      }
      updateUI();
    } catch(e) { currentUser = null; updateUI(); }
  }

  async function loadFavorites() {
    if (!currentUser) return;
    try {
      const res = await apiCall('favorites');
      userFavs.clear();
      (res.data || []).forEach(f => userFavs.add(`${f.entity_type}:${f.entity_id}`));
    } catch(e) { /* silent */ }
  }

  function isFavorited(type, id) {
    return userFavs.has(`${type}:${id}`);
  }

  async function toggleFavorite(type, id) {
    if (!currentUser) { showAuthModal('login'); return; }
    try {
      const res = await apiCall('toggle_fav', { entity_type: type, entity_id: id });
      if (res.favorited) { userFavs.add(`${type}:${id}`); }
      else { userFavs.delete(`${type}:${id}`); }
      // Update any visible fav buttons
      document.querySelectorAll(`[data-fav="${type}:${id}"]`).forEach(btn => {
        btn.classList.toggle('favorited', res.favorited);
        btn.innerHTML = res.favorited ? iconHeartFilled() : iconHeartOutline();
      });
    } catch(e) { alert(e.message); }
  }

  function updateUI() {
    const loginArea = document.getElementById('nav-login-area');
    if (!loginArea) return;
    // Clear existing login/user elements
    loginArea.innerHTML = '';

    if (currentUser) {
      const initials = currentUser.display_name.split(' ').map(w => w[0]).join('').slice(0,2).toUpperCase();
      const wrap = document.createElement('div');
      wrap.className = 'user-menu-wrap';
      wrap.innerHTML = `
        <button class="user-avatar-btn" aria-label="User menu">${initials}</button>
        <div class="user-dropdown" id="user-dropdown">
          <div class="user-dropdown-header">
            <div class="user-dropdown-name">${currentUser.display_name}</div>
            <div class="user-dropdown-email">${currentUser.email}</div>
          </div>
          <button class="user-dropdown-item" onclick="navigate('account');document.getElementById('user-dropdown').classList.remove('open');">My Account</button>
          <button class="user-dropdown-item" onclick="navigate('account?tab=favorites');document.getElementById('user-dropdown').classList.remove('open');">My Favorites</button>
          <button class="user-dropdown-item" onclick="navigate('account?tab=quotes');document.getElementById('user-dropdown').classList.remove('open');">My Quotes</button>
          <button class="user-dropdown-item" onclick="navigate('quotes');document.getElementById('user-dropdown').classList.remove('open');">My Quote Requests</button>
          <button class="user-dropdown-item" onclick="navigate('submit-listing');document.getElementById('user-dropdown').classList.remove('open');">Submit a Listing</button>
          <button class="user-dropdown-item" onclick="navigate('create-listing');document.getElementById('user-dropdown').classList.remove('open');">Post a Property</button>
          <button class="user-dropdown-item user-dropdown-item--danger" onclick="UserAuth.logout()">Log Out</button>
        </div>
      `;
      loginArea.appendChild(wrap);
      // Toggle dropdown
      wrap.querySelector('.user-avatar-btn').addEventListener('click', (e) => {
        e.stopPropagation();
        document.getElementById('user-dropdown').classList.toggle('open');
      });
      document.addEventListener('click', () => {
        const dd = document.getElementById('user-dropdown');
        if (dd) dd.classList.remove('open');
      });
    } else {
      const btn = document.createElement('button');
      btn.className = 'btn-icon login-btn';
      btn.setAttribute('aria-label', 'Sign in');
      btn.title = 'Sign in';
      btn.innerHTML = `<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>`;
      btn.addEventListener('click', () => showAuthModal('login'));
      loginArea.appendChild(btn);
    }

    // Update mobile menu
    const mobileMenu = document.getElementById('mobile-menu');
    if (mobileMenu) {
      mobileMenu.querySelectorAll('.mobile-auth-link').forEach(el => el.remove());
      const lastDiv = mobileMenu.querySelector('div[style*="margin-top:auto"]');
      if (currentUser) {
        const links = document.createElement('div');
        links.className = 'mobile-auth-link';
        links.innerHTML = `
          <a href="#account" onclick="navigate('account');return false;">My Account</a>
          <a href="#account?tab=favorites" onclick="navigate('account?tab=favorites');return false;">My Favorites</a>
          <a href="#submit-listing" onclick="navigate('submit-listing');return false;">Submit a Listing</a>
        `;
        if (lastDiv) mobileMenu.insertBefore(links, lastDiv);
      } else {
        const link = document.createElement('div');
        link.className = 'mobile-auth-link';
        link.innerHTML = `<a href="#" onclick="showAuthModal('login');return false;">Sign In / Register</a>`;
        if (lastDiv) mobileMenu.insertBefore(link, lastDiv);
      }
    }
  }

  return {
    get user() { return currentUser; },
    checkSession,
    loadFavorites,
    isFavorited,
    toggleFavorite,
    apiCall,
    updateUI,
    async login(email, password) {
      const res = await apiCall('login', { email, password });
      currentUser = res.user;
      await loadFavorites();
      QuoteTracker.load().catch(() => {});
      updateUI();
      return res;
    },
    async register(email, password, display_name) {
      return await apiCall('register', { email, password, display_name });
    },
    async logout() {
      try { await apiCall('logout'); } catch(e) {}
      currentUser = null;
      userFavs.clear();
      updateUI();
      navigate('home');
    },
    async forgotPassword(email) {
      return await apiCall('forgot_password', { email });
    },
    async resetPassword(token, password) {
      return await apiCall('reset_password', { token, password });
    },
    async socialLogin(provider, data) {
      const res = await apiCall('social_login', { provider, ...data });
      currentUser = res.user;
      await loadFavorites();
      updateUI();
      return res;
    },
  };
})();

// Icons for favorites
function iconHeartOutline() {
  return `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>`;
}
function iconHeartFilled() {
  return `<svg viewBox="0 0 24 24" fill="#ef4444" stroke="#ef4444" stroke-width="2"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>`;
}

// =====================================================
// AUTH MODAL
// =====================================================

function showAuthModal(mode = 'login') {
  // Remove existing
  document.querySelectorAll('.auth-overlay').forEach(el => el.remove());

  const overlay = document.createElement('div');
  overlay.className = 'auth-overlay';

  function renderLogin() {
    overlay.innerHTML = `
      <div class="auth-modal">
        <button class="auth-modal-close" onclick="this.closest('.auth-overlay').classList.remove('visible');setTimeout(()=>this.closest('.auth-overlay').remove(),200)">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
        </button>
        <h2>Sign In</h2>
        <p class="auth-subtitle">Access your favorites, claim listings, and more.</p>
        <div class="auth-social-btns">
          <button class="auth-social-btn auth-social-btn--google" id="btn-google-signin" type="button">
            <svg width="16" height="16" viewBox="0 0 24 24"><path d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z" fill="#4285F4"/><path d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z" fill="#34A853"/><path d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l3.66-2.84z" fill="#FBBC05"/><path d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z" fill="#EA4335"/></svg>
            Continue with Google
          </button>
          <button class="auth-social-btn auth-social-btn--facebook" id="btn-facebook-signin" type="button">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="#1877F2"><path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/></svg>
            Continue with Facebook
          </button>
        </div>
        <div class="auth-divider"><span>or</span></div>
        <div class="auth-error" id="auth-error"></div>
        <form class="auth-form" id="auth-form">
          <div class="auth-field">
            <label for="auth-email">Email</label>
            <input type="email" id="auth-email" required autocomplete="email">
          </div>
          <div class="auth-field">
            <label for="auth-pass">Password</label>
            <input type="password" id="auth-pass" required autocomplete="current-password">
          </div>
          <button type="submit" class="auth-submit">Sign In</button>
        </form>
        <div style="text-align:center;margin-top:var(--space-3)">
          <button onclick="authForgotPassword()" style="background:none;border:none;color:var(--color-primary);font-size:var(--text-xs);cursor:pointer;font-family:var(--font-body)">Forgot password?</button>
        </div>
        <div class="auth-switch">Don't have an account? <button onclick="authSwitchToRegister()">Create one</button></div>
      </div>
    `;
    // Social login handlers
    overlay.querySelector('#btn-google-signin').addEventListener('click', () => {
      if (window.google && window.google.accounts) {
        google.accounts.id.initialize({
          client_id: '',
          callback: async (response) => {
            try {
              await UserAuth.socialLogin('google', { credential: response.credential });
              overlay.classList.remove('visible');
              setTimeout(() => overlay.remove(), 200);
              router();
            } catch(err) {
              overlay.querySelector('#auth-error').textContent = err.message;
              overlay.querySelector('#auth-error').classList.add('visible');
            }
          },
        });
        google.accounts.id.prompt();
      } else {
        overlay.querySelector('#auth-error').textContent = 'Google Sign-In is not available right now.';
        overlay.querySelector('#auth-error').classList.add('visible');
      }
    });
    overlay.querySelector('#btn-facebook-signin').addEventListener('click', () => {
      if (window.FB) {
        FB.login(async (response) => {
          if (response.authResponse) {
            try {
              await UserAuth.socialLogin('facebook', { access_token: response.authResponse.accessToken, user_id: response.authResponse.userID });
              overlay.classList.remove('visible');
              setTimeout(() => overlay.remove(), 200);
              router();
            } catch(err) {
              overlay.querySelector('#auth-error').textContent = err.message;
              overlay.querySelector('#auth-error').classList.add('visible');
            }
          }
        }, { scope: 'email,public_profile' });
      } else {
        overlay.querySelector('#auth-error').textContent = 'Facebook Sign-In is not available right now.';
        overlay.querySelector('#auth-error').classList.add('visible');
      }
    });
    overlay.querySelector('#auth-form').addEventListener('submit', async (e) => {
      e.preventDefault();
      const errEl = overlay.querySelector('#auth-error');
      const btn = overlay.querySelector('.auth-submit');
      btn.disabled = true; btn.textContent = 'Signing in...';
      errEl.classList.remove('visible');
      try {
        await UserAuth.login(
          overlay.querySelector('#auth-email').value,
          overlay.querySelector('#auth-pass').value
        );
        overlay.classList.remove('visible');
        setTimeout(() => overlay.remove(), 200);
        // Refresh current page to show favorites, etc.
        router();
      } catch(err) {
        errEl.textContent = err.message;
        errEl.classList.add('visible');
        btn.disabled = false; btn.textContent = 'Sign In';
      }
    });
  }

  function renderRegister() {
    overlay.innerHTML = `
      <div class="auth-modal">
        <button class="auth-modal-close" onclick="this.closest('.auth-overlay').classList.remove('visible');setTimeout(()=>this.closest('.auth-overlay').remove(),200)">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
        </button>
        <h2>Create Account</h2>
        <p class="auth-subtitle">Join Build in Lombok to save favorites and manage listings.</p>
        <div class="auth-social-btns">
          <button class="auth-social-btn auth-social-btn--google" id="btn-reg-google" type="button">
            <svg width="16" height="16" viewBox="0 0 24 24"><path d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z" fill="#4285F4"/><path d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z" fill="#34A853"/><path d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l3.66-2.84z" fill="#FBBC05"/><path d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z" fill="#EA4335"/></svg>
            Sign up with Google
          </button>
          <button class="auth-social-btn auth-social-btn--facebook" id="btn-reg-facebook" type="button">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="#1877F2"><path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/></svg>
            Sign up with Facebook
          </button>
        </div>
        <div class="auth-divider"><span>or</span></div>
        <div class="auth-error" id="auth-error"></div>
        <div class="auth-success" id="auth-success"></div>
        <form class="auth-form" id="auth-form">
          <div class="auth-field">
            <label for="auth-name">Display Name</label>
            <input type="text" id="auth-name" required minlength="2" autocomplete="name">
          </div>
          <div class="auth-field">
            <label for="auth-email">Email</label>
            <input type="email" id="auth-email" required autocomplete="email">
          </div>
          <div class="auth-field">
            <label for="auth-pass">Password</label>
            <input type="password" id="auth-pass" required minlength="8" autocomplete="new-password">
          </div>
          <button type="submit" class="auth-submit">Create Account</button>
        </form>
        <div class="auth-switch">Already have an account? <button onclick="authSwitchToLogin()">Sign in</button></div>
      </div>
    `;
    // Social handlers for register — reuse same flow
    const handleSocialReg = (provider, getTokenData) => {
      getTokenData().then(async (data) => {
        if (!data) return;
        try {
          await UserAuth.socialLogin(provider, data);
          overlay.classList.remove('visible');
          setTimeout(() => overlay.remove(), 200);
          router();
        } catch(err) {
          overlay.querySelector('#auth-error').textContent = err.message;
          overlay.querySelector('#auth-error').classList.add('visible');
        }
      });
    };
    overlay.querySelector('#btn-reg-google').addEventListener('click', () => {
      if (window.google && window.google.accounts) {
        google.accounts.id.initialize({
          client_id: '',
          callback: async (response) => {
            try {
              await UserAuth.socialLogin('google', { credential: response.credential });
              overlay.classList.remove('visible');
              setTimeout(() => overlay.remove(), 200);
              router();
            } catch(err) {
              overlay.querySelector('#auth-error').textContent = err.message;
              overlay.querySelector('#auth-error').classList.add('visible');
            }
          },
        });
        google.accounts.id.prompt();
      } else {
        overlay.querySelector('#auth-error').textContent = 'Google Sign-In is not available right now.';
        overlay.querySelector('#auth-error').classList.add('visible');
      }
    });
    overlay.querySelector('#btn-reg-facebook').addEventListener('click', () => {
      if (window.FB) {
        FB.login(async (response) => {
          if (response.authResponse) {
            try {
              await UserAuth.socialLogin('facebook', { access_token: response.authResponse.accessToken, user_id: response.authResponse.userID });
              overlay.classList.remove('visible');
              setTimeout(() => overlay.remove(), 200);
              router();
            } catch(err) {
              overlay.querySelector('#auth-error').textContent = err.message;
              overlay.querySelector('#auth-error').classList.add('visible');
            }
          }
        }, { scope: 'email,public_profile' });
      } else {
        overlay.querySelector('#auth-error').textContent = 'Facebook Sign-In is not available right now.';
        overlay.querySelector('#auth-error').classList.add('visible');
      }
    });
    overlay.querySelector('#auth-form').addEventListener('submit', async (e) => {
      e.preventDefault();
      const errEl = overlay.querySelector('#auth-error');
      const sucEl = overlay.querySelector('#auth-success');
      const btn = overlay.querySelector('.auth-submit');
      btn.disabled = true; btn.textContent = 'Creating...';
      errEl.classList.remove('visible'); sucEl.classList.remove('visible');
      try {
        const res = await UserAuth.register(
          overlay.querySelector('#auth-email').value,
          overlay.querySelector('#auth-pass').value,
          overlay.querySelector('#auth-name').value
        );
        sucEl.textContent = res.message;
        sucEl.classList.add('visible');
        overlay.querySelector('#auth-form').style.display = 'none';
      } catch(err) {
        errEl.textContent = err.message;
        errEl.classList.add('visible');
        btn.disabled = false; btn.textContent = 'Create Account';
      }
    });
  }

  window.authSwitchToRegister = function() { renderRegister(); };
  window.authSwitchToLogin = function() { renderLogin(); };
  window.authForgotPassword = function() {
    overlay.innerHTML = `
      <div class="auth-modal">
        <button class="auth-modal-close" onclick="this.closest('.auth-overlay').classList.remove('visible');setTimeout(()=>this.closest('.auth-overlay').remove(),200)">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
        </button>
        <h2>Reset Password</h2>
        <p class="auth-subtitle">Enter your email and we'll send a reset link.</p>
        <div class="auth-error" id="auth-error"></div>
        <div class="auth-success" id="auth-success"></div>
        <form class="auth-form" id="auth-form">
          <div class="auth-field">
            <label for="auth-email">Email</label>
            <input type="email" id="auth-email" required autocomplete="email">
          </div>
          <button type="submit" class="auth-submit">Send Reset Link</button>
        </form>
        <div class="auth-switch"><button onclick="authSwitchToLogin()">Back to sign in</button></div>
      </div>
    `;
    overlay.querySelector('#auth-form').addEventListener('submit', async (e) => {
      e.preventDefault();
      const btn = overlay.querySelector('.auth-submit');
      const sucEl = overlay.querySelector('#auth-success');
      btn.disabled = true;
      try {
        const res = await UserAuth.forgotPassword(overlay.querySelector('#auth-email').value);
        sucEl.textContent = res.message;
        sucEl.classList.add('visible');
        overlay.querySelector('#auth-form').style.display = 'none';
      } catch(err) {
        overlay.querySelector('#auth-error').textContent = err.message;
        overlay.querySelector('#auth-error').classList.add('visible');
        btn.disabled = false;
      }
    });
  };

  if (mode === 'register') renderRegister();
  else renderLogin();

  document.body.appendChild(overlay);
  requestAnimationFrame(() => overlay.classList.add('visible'));

  // Close on overlay click
  overlay.addEventListener('click', (e) => {
    if (e.target === overlay) {
      overlay.classList.remove('visible');
      setTimeout(() => overlay.remove(), 200);
    }
  });
}

// =====================================================
// FAVORITE BUTTON HELPER
// =====================================================

function renderFavBtn(entityType, entityId) {
  const faved = UserAuth.isFavorited(entityType, entityId);
  return `<button class="fav-btn ${faved ? 'favorited' : ''}" data-fav="${entityType}:${entityId}" onclick="UserAuth.toggleFavorite('${entityType}', ${entityId})" aria-label="${faved ? 'Remove from' : 'Add to'} favorites" title="${faved ? 'Remove from' : 'Add to'} favorites">${faved ? iconHeartFilled() : iconHeartOutline()}</button>`;
}

// =====================================================
// QUOTE TRACKER
// =====================================================

const QuoteTracker = (() => {
  let savedSet = new Set(); // provider IDs with a saved quote

  async function load() {
    if (!UserAuth.user) { savedSet.clear(); return; }
    try {
      const res = await UserAuth.apiCall('my_quotes');
      savedSet.clear();
      (res.data || []).forEach(q => savedSet.add(parseInt(q.provider_id)));
    } catch(e) { /* silent */ }
  }

  function hasSaved(providerId) {
    return savedSet.has(parseInt(providerId));
  }

  // Called when user clicks the main WA button (non-blocking — link still opens)
  function onWaClick(providerId) {
    if (!UserAuth.user) return;
    UserAuth.apiCall('save_quote', { provider_id: providerId })
      .then(() => savedSet.add(parseInt(providerId)))
      .catch(() => {});
  }

  // Called when user clicks "Check Status" — opens WA without pre-filled message + logs
  async function checkStatus(providerId, waHref) {
    window.open(waHref, '_blank', 'noopener,noreferrer');
    if (!UserAuth.user) return;
    try {
      await UserAuth.apiCall('check_quote', { provider_id: providerId });
      savedSet.add(parseInt(providerId));
      showToast('Checked for updates');
    } catch(e) { /* silent */ }
  }

  function showToast(msg) {
    // Remove any existing toast
    document.querySelectorAll('.quote-toast').forEach(el => el.remove());
    const t = document.createElement('div');
    t.className = 'quote-toast';
    t.textContent = msg;
    document.body.appendChild(t);
    requestAnimationFrame(() => {
      requestAnimationFrame(() => t.classList.add('quote-toast--show'));
    });
    setTimeout(() => {
      t.classList.remove('quote-toast--show');
      setTimeout(() => t.remove(), 300);
    }, 3000);
  }

  return { load, hasSaved, onWaClick, checkStatus };
})();

// Google Review link helper
function renderGoogleReviewBtn(google_maps_url) {
  if (!google_maps_url) return '';
  // Google review URL: append /review to the maps URL or use direct link
  const reviewUrl = google_maps_url;
  return `<a href="${reviewUrl}" target="_blank" rel="noopener noreferrer" class="review-google-btn">
    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="12,2 15.09,8.26 22,9.27 17,14.14 18.18,21.02 12,17.77 5.82,21.02 7,14.14 2,9.27 8.91,8.26"/></svg>
    Write a Review on Google
  </a>`;
}

function showToast(message, type) {
  const existing = document.querySelector('.app-toast');
  if (existing) existing.remove();
  const toast = document.createElement('div');
  toast.className = 'app-toast app-toast--' + (type || 'info');
  toast.textContent = message;
  toast.style.cssText = 'position:fixed;bottom:var(--space-6);left:50%;transform:translateX(-50%);background:var(--color-surface);border:1px solid var(--color-border);padding:var(--space-3) var(--space-5);border-radius:var(--radius-md);font-size:var(--text-sm);z-index:9999;box-shadow:0 4px 16px rgba(0,0,0,.15);transition:opacity .3s;';
  document.body.appendChild(toast);
  setTimeout(() => { toast.style.opacity = '0'; setTimeout(() => toast.remove(), 300); }, 3000);
}

async function checkReviewUpdates(entityType, entityId) {
  try {
    await UserAuth.apiCall('check_reviews', { entity_type: entityType, entity_id: entityId });
    showToast('Review update check submitted. Ratings will refresh shortly.', 'success');
  } catch(e) {
    showToast(e.message || 'Unable to check for review updates.', 'error');
  }
}


// =====================================================
// RENDER: RAB CALCULATOR (Frontend)
// =====================================================

var RAB_API = '/api/rab_api.php';

function fmtIDR(val) {
  if (!val && val !== 0) return 'Rp 0';
  return 'Rp ' + Math.round(Number(val)).toLocaleString('id-ID');
}

async function renderRABCalculator(el) {
  var presets = [];
  try {
    var res = await fetch(RAB_API + '?action=presets', { credentials: 'include' });
    var json = await res.json();
    presets = json.data || [];
  } catch(e) { presets = []; }

  var defaultPreset = presets.find(function(p) { return p.is_default == 1; }) || presets[0];

  el.innerHTML = ''
    + '<div class="dir-hero">'
    + '  <div class="container">'
    + '    <h1 class="dir-hero-title">RAB Cost Tools</h1>'
    + '    <p class="dir-hero-desc">Estimate building costs for your Lombok villa or home — from a quick calculation to a full bill of quantities.</p>'
    + '    <div class="rab-hero-tabs">'
    + '      <a href="#rab-calculator" class="rab-tab active">Calculator</a>'
    + '      <a href="#rab-estimates" class="rab-tab" onclick="navigate(\'rab-estimates\');return false;">My Saved Estimates</a>'
    + '    </div>'
    + '  </div>'
    + '</div>'
    + '<div class="section">'
    + '  <div class="container">'
    + (presets.length === 0
       ? '<div class="card" style="padding:var(--space-8);text-align:center;"><p style="color:var(--color-text-faint)">No calculator presets available yet. Please check back soon.</p></div>'
       : '<div class="rab-calc-layout">' + buildWizardShell(presets, defaultPreset) + buildPresetSidebar(defaultPreset) + '</div>')
    + '  </div>'
    + '</div>';

  if (presets.length === 0) return;

  initRABWizard(el, presets);
}

// --- RAB Wizard: shell, state, reactive total ---

function buildWizardShell(presets, dp) {
  var defaultPresetId = dp ? dp.id : (presets[0] ? presets[0].id : '');

  return ''
    + '<div class="rab-calc-main">'
    + '<form id="rab-calc-form" class="wizard" autocomplete="off">'
    + '  <div class="wizard-stepper" role="tablist">'
    + '    <button type="button" class="wizard-step-tab is-active" data-step="1" role="tab"><span class="wizard-step-num">1</span><span class="wizard-step-label"><small>Step 1</small>Finish Quality</span></button>'
    + '    <button type="button" class="wizard-step-tab is-disabled" data-step="2" role="tab"><span class="wizard-step-num">2</span><span class="wizard-step-label"><small>Step 2</small>Size &amp; dimensions</span></button>'
    + '    <button type="button" class="wizard-step-tab is-disabled" data-step="3" role="tab"><span class="wizard-step-num">3</span><span class="wizard-step-label"><small>Step 3</small>Material tier</span></button>'
    + '  </div>'
    + '  <div class="wizard-body">'
    /* STEP 1 */
    + '    <div class="wizard-pane is-active" data-pane="1">'
    + '      <h4>Select Your Finish Quality</h4>'
    + '      <p class="wizard-hint">Select a baseline material and finishing tier to calibrate your real-time build estimate.</p>'
    + '      <div class="rab-field">'
    + '        <label class="rab-label">Material Grade</label>'
    + '        <select id="rab-quality-sel" name="quality" class="rab-select">'
    + '          <option value="economy">Economy</option>'
    + '          <option value="architectural" selected>Architectural</option>'
    + '          <option value="premium">Premium</option>'
    + '          <option value="signature">Signature</option>'
    + '        </select>'
    + '        <p class="rab-tier-desc" id="rab-tier-desc">Standard quality regional materials, crisp structural concrete, and premium plaster work.</p>'
    + '      </div>'
    + '      <input type="hidden" name="preset_id" id="rab-preset-hidden" value="' + defaultPresetId + '">'
    + '    </div>'
    /* STEP 2 */
    + '    <div class="wizard-pane" data-pane="2">'
    + '      <h4>How big is the build?</h4>'
    + '      <p class="wizard-hint">Tell us how many storeys and the floor area for each level.</p>'
    + '      <div class="rab-field">'
    + '        <label class="rab-label">Number of Storeys</label>'
    + '        <select id="rab-storeys-sel" name="num_storeys" class="rab-select">'
    + '          <option value="1">1 Storey</option>'
    + '          <option value="2">2 Storeys</option>'
    + '          <option value="3">3 Storeys</option>'
    + '          <option value="4">4+ Storeys</option>'
    + '        </select>'
    + '      </div>'
    + '      <div class="rab-fields-grid">'
    + '        <div class="rab-field" id="rab-fa-1"><label class="rab-label">Ground Floor (m²)</label><input type="number" name="floor_area_1" class="rab-input" min="0" step="0.5" placeholder="e.g. 150"></div>'
    + '        <div class="rab-field" id="rab-fa-2" style="display:none"><label class="rab-label">1st Floor (m²)</label><input type="number" name="floor_area_2" class="rab-input" min="0" step="0.5" placeholder="e.g. 120" value="0"></div>'
    + '        <div class="rab-field" id="rab-fa-3" style="display:none"><label class="rab-label">2nd Floor (m²)</label><input type="number" name="floor_area_3" class="rab-input" min="0" step="0.5" placeholder="e.g. 100" value="0"></div>'
    + '        <div class="rab-field" id="rab-fa-4" style="display:none"><label class="rab-label">Other Levels (m²)</label><input type="number" name="floor_area_4" class="rab-input" min="0" step="0.5" placeholder="e.g. 80" value="0"></div>'
    + '      </div>'
    + '    </div>'
    /* STEP 3 */
    + '    <div class="wizard-pane" data-pane="3">'
    + '      <h4>Optional Extras</h4>'
    + '      <p class="wizard-hint">Add optional extras to refine your estimate.</p>'
    + '      <label class="rab-check-label" style="margin-top:var(--space-2)"><input type="checkbox" name="walkable_rooftop" id="rab-ck-rooftop"><span>Walkable Rooftop / Roof Deck</span></label>'
    + '      <div id="rab-rooftop-wrap" style="display:none;margin-left:28px;margin-bottom:var(--space-4)">'
    + '        <div class="rab-field"><label class="rab-label">Rooftop Area (m²)</label><input type="number" name="rooftop_area" class="rab-input" min="0" step="0.5" placeholder="e.g. 80"></div>'
    + '      </div>'
    + '      <label class="rab-check-label"><input type="checkbox" name="has_pool" id="rab-ck-pool"><span>Swimming Pool</span></label>'
    + '      <div id="rab-pool-wrap" style="display:none;margin-left:28px;margin-bottom:var(--space-4)">'
    + '        <div class="rab-fields-grid">'
    + '          <div class="rab-field"><label class="rab-label">Pool Area (m²)</label><input type="number" name="pool_area" class="rab-input" min="0" step="0.5" placeholder="e.g. 30"></div>'
    + '          <div class="rab-field"><label class="rab-label">Pool Type</label><div class="rab-radio-row"><label class="rab-check-label"><input type="radio" name="pool_type" value="standard" checked><span>Standard</span></label><label class="rab-check-label"><input type="radio" name="pool_type" value="infinity"><span>Infinity</span></label></div></div>'
    + '        </div>'
    + '      </div>'
    + '      <div class="rab-field" style="margin-top:var(--space-3)"><label class="rab-label">Deck / Terrace Area (m²)</label><input type="number" name="deck_area" class="rab-input" min="0" step="0.5" placeholder="e.g. 40"></div>'
    + '    </div>'
    + '  </div>'
    + '  <div class="wizard-footer">'
    + '    <div class="wizard-total">'
    + '      <span class="wizard-total-label">Live estimate</span>'
    + '      <span class="wizard-total-value" id="rab-live-total">' + fmtIDR(0) + '</span>'
    + '    </div>'
    + '    <div class="wizard-nav">'
    + '      <button type="button" class="btn btn--ghost btn--sm" id="rab-wiz-back" disabled>Back</button>'
    + '      <button type="button" class="btn btn--primary" id="rab-wiz-next">Next</button>'
    + '      <button type="submit" class="btn btn--primary btn--lg" id="rab-calc-submit" style="display:none;">'
    + '        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="4" y="2" width="16" height="20" rx="2"/><path d="M8 6h8M8 10h8M8 14h4"/></svg>'
    + '        Calculate Estimate'
    + '      </button>'
    + '    </div>'
    + '  </div>'
    + '</form>'
    + '<div id="rab-result-area" style="display:none;margin-top:var(--space-6);"></div>'
    + '</div>';
}

function initRABWizard(el, presets) {
  var form = el.querySelector('#rab-calc-form');
  if (!form) return;
  var TOTAL_STEPS = 3;
  var currentStep = 1;

  var tabs    = form.querySelectorAll('.wizard-step-tab');
  var panes   = form.querySelectorAll('.wizard-pane');
  var backBtn = form.querySelector('#rab-wiz-back');
  var nextBtn = form.querySelector('#rab-wiz-next');
  var submit  = form.querySelector('#rab-calc-submit');
  var totalEl = form.querySelector('#rab-live-total');

  function setStep(n) {
    currentStep = Math.max(1, Math.min(TOTAL_STEPS, n));
    tabs.forEach(function(t) {
      var step = parseInt(t.dataset.step);
      t.classList.remove('is-active', 'is-disabled', 'is-complete');
      if (step === currentStep) t.classList.add('is-active');
      else if (step < currentStep) t.classList.add('is-complete');
      else t.classList.add('is-disabled');
    });
    panes.forEach(function(p) {
      p.classList.toggle('is-active', parseInt(p.dataset.pane) === currentStep);
    });
    backBtn.disabled = (currentStep === 1);
    if (currentStep === TOTAL_STEPS) {
      nextBtn.style.display = 'none';
      submit.style.display = '';
    } else {
      nextBtn.style.display = '';
      submit.style.display = 'none';
    }
  }

  tabs.forEach(function(t) {
    t.addEventListener('click', function() {
      var step = parseInt(t.dataset.step);
      // Allow clicking already-completed or current step (not future steps unless via Next)
      if (step <= currentStep || t.classList.contains('is-complete')) setStep(step);
    });
  });
  nextBtn.addEventListener('click', function() { setStep(currentStep + 1); });
  backBtn.addEventListener('click', function() { setStep(currentStep - 1); });

  // Quality selector: update tier desc, highlight rate row, recompute
  var TIER_DESCS = {
    economy:      'Simple local materials, basic tile options, and straightforward functional finishes.',
    architectural: 'Standard quality regional materials, crisp structural concrete, and premium plaster work.',
    premium:      'Bespoke seamless surfaces (micro-cement/terrazzo), artisan built-ins, and premium hardware.',
    signature:    'Elite international standards, top-tier imported natural stone, and master-artisan finishes.'
  };
  function updateQualityUI(val) {
    var descEl = document.getElementById('rab-tier-desc');
    if (descEl) descEl.textContent = TIER_DESCS[val] || '';
    document.querySelectorAll('#rab-rates-list .rab-rate-row').forEach(function(row) {
      row.classList.toggle('is-active', row.dataset.tier === val);
    });
  }
  var qualitySel = form.querySelector('#rab-quality-sel');
  if (qualitySel) {
    qualitySel.addEventListener('change', function() {
      updateQualityUI(this.value);
      recomputeTotal();
    });
    updateQualityUI(qualitySel.value);
  }

  // Storeys: show/hide floor inputs
  var storeysSel = form.querySelector('#rab-storeys-sel');
  if (storeysSel) {
    storeysSel.addEventListener('change', function() {
      var n = parseInt(this.value);
      for (var i = 2; i <= 4; i++) {
        var wrap = form.querySelector('#rab-fa-' + i);
        if (wrap) wrap.style.display = i <= n ? '' : 'none';
      }
      recomputeTotal();
    });
  }

  // Optional extras toggles
  var ckRooftop = form.querySelector('#rab-ck-rooftop');
  if (ckRooftop) ckRooftop.addEventListener('change', function() {
    form.querySelector('#rab-rooftop-wrap').style.display = this.checked ? '' : 'none';
    recomputeTotal();
  });
  var ckPool = form.querySelector('#rab-ck-pool');
  if (ckPool) ckPool.addEventListener('change', function() {
    form.querySelector('#rab-pool-wrap').style.display = this.checked ? '' : 'none';
    recomputeTotal();
  });


  // Recompute on any number input change
  form.querySelectorAll('input[type=number], input[type=radio]').forEach(function(inp) {
    inp.addEventListener('input', recomputeTotal);
    inp.addEventListener('change', recomputeTotal);
  });

  // Reactive total — local estimate so we don't hammer the API
  function recomputeTotal() {
    var presetId = parseInt(form.querySelector('[name=preset_id]').value);
    var p = presets.find(function(x) { return x.id == presetId; }) || presets[0];
    if (!p) return;
    var qualityEl = form.querySelector('[name=quality]');
    var quality = qualityEl ? qualityEl.value : 'architectural';
    var rate = quality === 'economy'   ? Number(p.base_cost_per_m2_low)
             : quality === 'premium'   ? Number(p.base_cost_per_m2_high)
             : quality === 'signature' ? Math.round(Number(p.base_cost_per_m2_high) * 1.5)
             :                           Number(p.base_cost_per_m2_mid);
    var storeys = parseInt(form.querySelector('[name=num_storeys]').value) || 1;
    var floorArea = 0;
    for (var i = 1; i <= storeys; i++) {
      var v = parseFloat((form.querySelector('[name=floor_area_' + i + ']') || {}).value) || 0;
      floorArea += v;
    }
    var building = floorArea * rate;
    var roof = 0;
    if (form.querySelector('[name=walkable_rooftop]').checked) {
      var rA = parseFloat(form.querySelector('[name=rooftop_area]').value) || 0;
      roof = rA * Number(p.rooftop_cost_per_m2 || 0);
    }
    var pool = 0;
    if (form.querySelector('[name=has_pool]').checked) {
      var pA = parseFloat(form.querySelector('[name=pool_area]').value) || 0;
      var poolType = (form.querySelector('input[name=pool_type]:checked') || { value: 'standard' }).value;
      var poolRate = poolType === 'infinity'
        ? Number(p.pool_cost_per_m2_infinity || 0)
        : Number(p.pool_cost_per_m2_standard || 0);
      pool = pA * poolRate;
    }
    var deck = (parseFloat(form.querySelector('[name=deck_area]').value) || 0) * Number(p.deck_cost_per_m2 || 0);
    var subtotal = (building + roof + pool + deck) * Number(p.location_factor || 1);
    var contingency = subtotal * (Number(p.contingency_percent || 0) / 100);
    var total = subtotal + contingency;

    var prev = totalEl.textContent;
    var next = fmtIDR(total);
    totalEl.textContent = next;
    if (prev !== next) {
      totalEl.classList.remove('is-pulsing');
      // Force reflow to restart animation
      void totalEl.offsetWidth;
      totalEl.classList.add('is-pulsing');
    }
  }

  // Submit — same as before, calls server for authoritative number
  form.addEventListener('submit', function(e) {
    e.preventDefault();
    runRABCalculation(el, form, presets);
  });

  // Initial paint
  setStep(1);
  recomputeTotal();
}

/* legacy single-page form removed — see buildWizardShell()
function buildCalcForm__legacy(presets, dp) {
  var presetOpts = presets.map(function(p) {
    return '<option value="' + p.id + '"' + (p.is_default == 1 ? ' selected' : '') + '>' + escHtml(p.name) + (p.description ? ' — ' + escHtml(p.description.substring(0, 60)) : '') + '</option>';
  }).join('');

  return ''
    + '<div class="rab-calc-main">'
    + '<form id="rab-calc-form">'
    + '<div class="rab-card">'
    + '  <h3 class="rab-card-title"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 21h18M3 10h18M3 7l9-4 9 4M4 10v11M20 10v11"/></svg> Building Type &amp; Quality</h3>'
    + '  <div class="rab-field">'
    + '    <label class="rab-label">Rate Preset</label>'
    + '    <select id="rab-preset-sel" name="preset_id" class="rab-select">' + presetOpts + '</select>'
    + '  </div>'
    + '  <label class="rab-label" style="margin-bottom:var(--space-2)">Quality Level</label>'
    + '  <div class="rab-quality-row">'
    + '    <label class="rab-quality-opt"><input type="radio" name="quality" value="low"><span class="rab-quality-inner"><span class="rab-quality-name">Economy</span><span class="rab-quality-desc">Budget finish</span></span></label>'
    + '    <label class="rab-quality-opt selected"><input type="radio" name="quality" value="mid" checked><span class="rab-quality-inner"><span class="rab-quality-name">Standard</span><span class="rab-quality-desc">Mid-range</span></span></label>'
    + '    <label class="rab-quality-opt"><input type="radio" name="quality" value="high"><span class="rab-quality-inner"><span class="rab-quality-name">Premium</span><span class="rab-quality-desc">High-end finish</span></span></label>'
    + '  </div>'
    + '</div>'
    + '<div class="rab-card">'
    + '  <h3 class="rab-card-title"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18M9 21V9"/></svg> Building Floors</h3>'
    + '  <div class="rab-field">'
    + '    <label class="rab-label">Number of Storeys</label>'
    + '    <select id="rab-storeys-sel" name="num_storeys" class="rab-select">'
    + '      <option value="1">1 Storey</option>'
    + '      <option value="2">2 Storeys</option>'
    + '      <option value="3">3 Storeys</option>'
    + '      <option value="4">4+ Storeys</option>'
    + '    </select>'
    + '  </div>'
    + '  <div class="rab-fields-grid">'
    + '    <div class="rab-field" id="rab-fa-1"><label class="rab-label">Ground Floor (m\u00B2)</label><input type="number" name="floor_area_1" class="rab-input" min="0" step="0.5" placeholder="e.g. 150"></div>'
    + '    <div class="rab-field" id="rab-fa-2" style="display:none"><label class="rab-label">1st Floor (m\u00B2)</label><input type="number" name="floor_area_2" class="rab-input" min="0" step="0.5" placeholder="e.g. 120" value="0"></div>'
    + '    <div class="rab-field" id="rab-fa-3" style="display:none"><label class="rab-label">2nd Floor (m\u00B2)</label><input type="number" name="floor_area_3" class="rab-input" min="0" step="0.5" placeholder="e.g. 100" value="0"></div>'
    + '    <div class="rab-field" id="rab-fa-4" style="display:none"><label class="rab-label">Other Levels (m\u00B2)</label><input type="number" name="floor_area_4" class="rab-input" min="0" step="0.5" placeholder="e.g. 80" value="0"></div>'
    + '  </div>'
    + '</div>'
    + '<div class="rab-card">'
    + '  <h3 class="rab-card-title"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2L2 7l10 5 10-5-10-5z"/><path d="M2 17l10 5 10-5"/><path d="M2 12l10 5 10-5"/></svg> Optional Extras</h3>'
    + '  <label class="rab-check-label"><input type="checkbox" name="walkable_rooftop" id="rab-ck-rooftop"><span>Walkable Rooftop / Roof Deck</span></label>'
    + '  <div id="rab-rooftop-wrap" style="display:none;margin-left:28px;margin-bottom:var(--space-4)">'
    + '    <div class="rab-field"><label class="rab-label">Rooftop Area (m\u00B2)</label><input type="number" name="rooftop_area" class="rab-input" min="0" step="0.5" placeholder="e.g. 80"></div>'
    + '  </div>'
    + '  <label class="rab-check-label"><input type="checkbox" name="has_pool" id="rab-ck-pool"><span>Swimming Pool</span></label>'
    + '  <div id="rab-pool-wrap" style="display:none;margin-left:28px;margin-bottom:var(--space-4)">'
    + '    <div class="rab-fields-grid">'
    + '      <div class="rab-field"><label class="rab-label">Pool Area (m\u00B2)</label><input type="number" name="pool_area" class="rab-input" min="0" step="0.5" placeholder="e.g. 30"></div>'
    + '      <div class="rab-field"><label class="rab-label">Pool Type</label><div class="rab-radio-row"><label class="rab-check-label"><input type="radio" name="pool_type" value="standard" checked><span>Standard</span></label><label class="rab-check-label"><input type="radio" name="pool_type" value="infinity"><span>Infinity</span></label></div></div>'
    + '    </div>'
    + '  </div>'
    + '  <div class="rab-field" style="margin-top:var(--space-3)"><label class="rab-label">Deck / Terrace Area (m\u00B2)</label><input type="number" name="deck_area" class="rab-input" min="0" step="0.5" placeholder="e.g. 40"></div>'
    + '</div>'
    + '<button type="submit" class="rab-submit-btn" id="rab-calc-submit">'
    + '  <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="4" y="2" width="16" height="20" rx="2"/><path d="M8 6h8M8 10h8M8 14h4"/></svg>'
    + '  Calculate Estimate'
    + '</button>'
    + '</form>'
    + '<div id="rab-result-area" style="display:none"></div>'
    + '</div>';
}
*/

function buildPresetSidebar(dp) {
  if (!dp) return '';
  return ''
    + '<div class="rab-calc-sidebar">'
    + '<div class="rab-card rab-preset-card" id="rab-preset-info">'
    + '  <h3 class="rab-card-title" style="font-size:var(--text-base)"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg> Rate Card</h3>'
    + '  <div class="rab-rates" id="rab-rates-list">'
    + buildRatesList(dp)
    + '  </div>'
    + '</div>'
    + '<div class="rab-card rab-info-card">'
    + '  <h4 style="font-family:var(--font-display);font-size:var(--text-base);margin-bottom:var(--space-2);color:var(--color-heading)">How it works</h4>'
    + '  <ol class="rab-howto">'
    + '    <li>Choose a building preset and quality level</li>'
    + '    <li>Enter floor areas for each storey</li>'
    + '    <li>Add optional extras (pool, rooftop, deck)</li>'
    + '    <li>Hit Calculate for your instant estimate</li>'
    + '    <li>Save estimates to revisit later</li>'
    + '  </ol>'
    + '  <p class="rab-disclaimer">Estimates are indicative only and based on current Lombok market rates. Final costs may vary based on site conditions, materials, and design complexity.</p>'
    + '</div>'
    + '</div>';
}

function buildRatesList(dp) {
  return ''
    + '<div class="rab-rate-row" data-tier="economy"><span>Economy (m\u00B2)</span><span class="rab-rate-val">' + fmtIDR(dp.base_cost_per_m2_low) + '</span></div>'
    + '<div class="rab-rate-row" data-tier="architectural"><span>Architectural (m\u00B2)</span><span class="rab-rate-val">' + fmtIDR(dp.base_cost_per_m2_mid) + '</span></div>'
    + '<div class="rab-rate-row" data-tier="premium"><span>Premium (m\u00B2)</span><span class="rab-rate-val">' + fmtIDR(dp.base_cost_per_m2_high) + '</span></div>'
    + '<div class="rab-rate-row" data-tier="signature"><span>Signature (m\u00B2)</span><span class="rab-rate-val">' + fmtIDR(Math.round(Number(dp.base_cost_per_m2_high) * 1.5)) + '</span></div>'
    + '<div class="rab-rate-row"><span>Standard Pool (m\u00B2)</span><span class="rab-rate-val">' + fmtIDR(dp.pool_cost_per_m2_standard) + '</span></div>'
    + '<div class="rab-rate-row"><span>Infinity Pool (m\u00B2)</span><span class="rab-rate-val">' + fmtIDR(dp.pool_cost_per_m2_infinity) + '</span></div>'
    + '<div class="rab-rate-row"><span>Deck (m\u00B2)</span><span class="rab-rate-val">' + fmtIDR(dp.deck_cost_per_m2) + '</span></div>'
    + '<div class="rab-rate-row"><span>Rooftop (m\u00B2)</span><span class="rab-rate-val">' + fmtIDR(dp.rooftop_cost_per_m2) + '</span></div>'
    + '<div class="rab-rate-row"><span>Location Factor</span><span class="rab-rate-val">' + Number(dp.location_factor).toFixed(3) + '\u00D7</span></div>'
    + '<div class="rab-rate-row"><span>Contingency</span><span class="rab-rate-val">' + Number(dp.contingency_percent).toFixed(1) + '%</span></div>';
}

function updatePresetSidebar(p) {
  var ratesEl = document.getElementById('rab-rates-list');
  if (ratesEl) ratesEl.innerHTML = buildRatesList(p);
}

function escHtml(s) {
  var d = document.createElement('div');
  d.textContent = s;
  return d.innerHTML;
}

async function runRABCalculation(el, form, presets) {
  var btn = el.querySelector('#rab-calc-submit');
  var origText = btn.innerHTML;
  btn.disabled = true;
  btn.innerHTML = '<span class="rab-spinner"></span> Calculating...';

  var fd = new FormData(form);
  var data = {};
  fd.forEach(function(value, key) { data[key] = value; });
  // Checkboxes
  data.walkable_rooftop = form.querySelector('[name=walkable_rooftop]').checked ? 1 : 0;
  data.has_pool = form.querySelector('[name=has_pool]').checked ? 1 : 0;

  try {
    var res = await fetch(RAB_API + '?action=calculate', {
      method: 'POST',
      credentials: 'include',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(data)
    });
    var json = await res.json();
    if (!res.ok) throw new Error(json.error || 'Calculation failed');

    // Show result inline
    showCalcResult(el, json.result, json.run_id);
  } catch(e) {
    showToast(e.message || 'Unable to calculate. Please try again.', 'error');
  } finally {
    btn.disabled = false;
    btn.innerHTML = origText;
  }
}

function showCalcResult(el, r, runId) {
  var area = el.querySelector('#rab-result-area');
  if (!area) return;
  area.style.display = 'block';
  area.scrollIntoView({ behavior: 'smooth', block: 'start' });

  var breakdown = ''
    + '<div class="rab-result-breakdown">'
    + '  <div class="rab-result-header">'
    + '    <h2 class="rab-result-title">Your Cost Estimate</h2>'
    + '    <p class="rab-result-subtitle">' + escHtml(r.preset_name) + ' \u2022 ' + escHtml(r.quality_label) + ' Quality \u2022 ' + r.num_storeys + ' Storey' + (r.num_storeys > 1 ? 's' : '') + '</p>'
    + '  </div>'
    + '  <div class="rab-result-grand">'
    + '    <span class="rab-result-grand-label">Estimated Total Cost</span>'
    + '    <span class="rab-result-grand-val">' + fmtIDR(r.grand_total) + '</span>'
    + '  </div>'
    + '  <div class="rab-result-rows">'
    + '    <div class="rab-result-row"><span>Building (' + r.total_floor_area.toFixed(1) + ' m\u00B2 @ ' + fmtIDR(r.building_rate) + '/m\u00B2)</span><span>' + fmtIDR(r.building_cost) + '</span></div>';

  if (r.walkable_rooftop && r.rooftop_cost > 0) {
    breakdown += '    <div class="rab-result-row"><span>Rooftop Deck (' + r.rooftop_area.toFixed(1) + ' m\u00B2)</span><span>' + fmtIDR(r.rooftop_cost) + '</span></div>';
  }
  if (r.has_pool && r.pool_cost > 0) {
    breakdown += '    <div class="rab-result-row"><span>' + (r.pool_is_infinity ? 'Infinity' : 'Standard') + ' Pool (' + r.pool_area.toFixed(1) + ' m\u00B2)</span><span>' + fmtIDR(r.pool_cost) + '</span></div>';
  }
  if (r.deck_cost > 0) {
    breakdown += '    <div class="rab-result-row"><span>Deck / Terrace (' + r.deck_area.toFixed(1) + ' m\u00B2)</span><span>' + fmtIDR(r.deck_cost) + '</span></div>';
  }

  breakdown += ''
    + '    <div class="rab-result-row rab-result-row--sub"><span>Subtotal</span><span>' + fmtIDR(r.subtotal) + '</span></div>'
    + '    <div class="rab-result-row"><span>Contingency (' + r.contingency_pct.toFixed(1) + '%)</span><span>' + fmtIDR(r.contingency) + '</span></div>'
    + '    <div class="rab-result-row rab-result-row--total"><span>Grand Total</span><span>' + fmtIDR(r.grand_total) + '</span></div>'
    + '  </div>'
    + '  <div class="rab-result-actions">'
    + '    <button class="btn btn--primary" id="rab-save-btn" onclick="saveRABEstimate(' + runId + ')"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 21H5a2 2 0 01-2-2V5a2 2 0 012-2h11l5 5v11a2 2 0 01-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg> Save Estimate</button>'
    + '    <button class="btn btn--outline" onclick="navigate(\'rab-calculator\');return false;"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 4v6h6"/><path d="M3.51 15a9 9 0 102.13-9.36L1 10"/></svg> New Calculation</button>'
    + '  </div>'
    + '</div>';

  area.innerHTML = breakdown;
}

function saveRABEstimate(runId) {
  if (!UserAuth.user) {
    showAuthModal('login');
    return;
  }
  // Show name prompt
  var name = prompt('Give your estimate a name (optional):');
  if (name === null) return; // cancelled

  var btn = document.getElementById('rab-save-btn');
  if (btn) { btn.disabled = true; btn.innerHTML = 'Saving...'; }

  fetch(RAB_API + '?action=save_estimate', {
    method: 'POST',
    credentials: 'include',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ run_id: runId, name: name || '' })
  })
  .then(function(res) { return res.json().then(function(j) { return { ok: res.ok, json: j }; }); })
  .then(function(out) {
    if (!out.ok) throw new Error(out.json.error || 'Save failed');
    showToast('Estimate saved. View it in My Saved Estimates.', 'success');
    if (btn) { btn.innerHTML = '\u2713 Saved'; btn.disabled = true; }
  })
  .catch(function(e) {
    showToast(e.message || 'Unable to save estimate.', 'error');
    if (btn) { btn.disabled = false; btn.innerHTML = 'Save Estimate'; }
  });
}


// =====================================================
// RENDER: RAB SAVED ESTIMATES
// =====================================================

async function renderRABEstimates(el) {
  if (!UserAuth.user) {
    el.innerHTML = ''
      + '<div class="dir-hero">'
      + '  <div class="container">'
      + '    <h1 class="dir-hero-title">My Saved Estimates</h1>'
      + '    <p class="dir-hero-desc">Sign in to view and manage your saved cost estimates.</p>'
      + '  </div>'
      + '</div>'
      + '<div class="section"><div class="container" style="text-align:center;padding:var(--space-12) 0;">'
      + '  <button class="btn btn--primary" onclick="showAuthModal(\'login\')">Sign In to View Estimates</button>'
      + '</div></div>';
    return;
  }

  el.innerHTML = ''
    + '<div class="dir-hero">'
    + '  <div class="container">'
    + '    <h1 class="dir-hero-title">My Saved Estimates</h1>'
    + '    <p class="dir-hero-desc">View and manage your saved building cost estimates.</p>'
    + '    <div class="rab-hero-tabs">'
    + '      <a href="#rab-calculator" class="rab-tab" onclick="navigate(\'rab-calculator\');return false;">Calculator</a>'
    + '      <a href="#rab-estimates" class="rab-tab active">My Saved Estimates</a>'
    + '    </div>'
    + '  </div>'
    + '</div>'
    + '<div class="section"><div class="container"><div id="rab-estimates-list"><div class="page-loading"><div class="page-loading-spinner"></div></div></div></div></div>';

  try {
    var res = await fetch(RAB_API + '?action=my_estimates', { credentials: 'include' });
    var json = await res.json();
    if (!res.ok) throw new Error(json.error || 'Failed to load estimates');
    var estimates = json.data || [];
    var listEl = el.querySelector('#rab-estimates-list');

    if (estimates.length === 0) {
      listEl.innerHTML = ''
        + '<div class="rab-empty-state">'
        + '  <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="var(--color-accent)" stroke-width="1.5" style="margin-bottom:var(--space-4)"><rect x="4" y="2" width="16" height="20" rx="2"/><path d="M8 6h8M8 10h8M8 14h4"/></svg>'
        + '  <h3 style="font-family:var(--font-display);margin-bottom:var(--space-2)">No saved estimates yet</h3>'
        + '  <p style="color:var(--color-text-faint);margin-bottom:var(--space-4)">Run a calculation and save it to see it here.</p>'
        + '  <a href="#rab-calculator" class="btn btn--primary" onclick="navigate(\'rab-calculator\');return false;">Go to Calculator</a>'
        + '</div>';
      return;
    }

    var ql_map = { economy: 'Economy', architectural: 'Architectural', premium: 'Premium', signature: 'Signature', low: 'Economy', mid: 'Architectural', high: 'Premium' };
    var cards = estimates.map(function(est) {
      var dateStr = est.created_at ? new Date(est.created_at).toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' }) : '';
      return ''
        + '<div class="rab-estimate-card">'
        + '  <div class="rab-estimate-header">'
        + '    <div>'
        + '      <h3 class="rab-estimate-name">' + escHtml(est.name || 'Untitled Estimate') + '</h3>'
        + '      <p class="rab-estimate-meta">' + escHtml(est.preset_name || '') + ' \u2022 ' + escHtml(est.quality_label || ql_map[est.quality_level] || '') + ' \u2022 ' + est.num_storeys + ' storey' + (est.num_storeys > 1 ? 's' : '') + ' \u2022 ' + Number(est.total_floor_area_m2).toFixed(0) + ' m\u00B2</p>'
        + '      <p class="rab-estimate-date">' + dateStr + '</p>'
        + '    </div>'
        + '    <div class="rab-estimate-total">' + fmtIDR(est.grand_total_cost) + '</div>'
        + '  </div>'
        + '  <div class="rab-estimate-actions">'
        + '    <button class="btn btn--sm btn--outline" onclick="navigate(\'rab-result?id=' + est.id + '\');return false;">View Details</button>'
        + '    <button class="btn btn--sm btn--danger-outline" onclick="deleteRABEstimate(' + est.id + ', this)">Delete</button>'
        + '  </div>'
        + '</div>';
    }).join('');

    listEl.innerHTML = cards;
  } catch(e) {
    el.querySelector('#rab-estimates-list').innerHTML = '<div class="rab-card" style="text-align:center;color:var(--color-text-faint);padding:var(--space-8)">' + escHtml(e.message) + '</div>';
  }
}

function deleteRABEstimate(runId, btnEl) {
  if (!confirm('Remove this saved estimate?')) return;
  if (btnEl) { btnEl.disabled = true; btnEl.textContent = 'Removing...'; }

  fetch(RAB_API + '?action=delete_estimate', {
    method: 'POST',
    credentials: 'include',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ run_id: runId })
  })
  .then(function(res) { return res.json().then(function(j) { return { ok: res.ok, json: j }; }); })
  .then(function(out) {
    if (!out.ok) throw new Error(out.json.error || 'Delete failed');
    showToast('Estimate removed.', 'success');
    // Refresh the list
    var main = document.getElementById('main-content');
    if (main) {
      var view = document.createElement('div');
      view.className = 'page-view';
      renderRABEstimates(view).then(function() {
        main.innerHTML = '';
        main.appendChild(view);
      });
    }
  })
  .catch(function(e) {
    showToast(e.message || 'Unable to delete.', 'error');
    if (btnEl) { btnEl.disabled = false; btnEl.textContent = 'Delete'; }
  });
}


// =====================================================
// RENDER: RAB RESULT DETAIL
// =====================================================

async function renderRABResult(el, params) {
  var id = params.id || 0;
  if (!id) { navigate('rab-calculator'); return; }

  el.innerHTML = ''
    + '<div class="dir-hero">'
    + '  <div class="container">'
    + '    <h1 class="dir-hero-title">Estimate Detail</h1>'
    + '    <p class="dir-hero-desc">Review your saved building cost estimate.</p>'
    + '  </div>'
    + '</div>'
    + '<div class="section"><div class="container"><div id="rab-detail-area"><div class="page-loading"><div class="page-loading-spinner"></div></div></div></div></div>';

  try {
    var res = await fetch(RAB_API + '?action=estimate&id=' + id, { credentials: 'include' });
    var json = await res.json();
    if (!res.ok) throw new Error(json.error || 'Not found');
    var r = json.data;

    var ql_map = { economy: 'Economy', architectural: 'Architectural', premium: 'Premium', signature: 'Signature', low: 'Economy', mid: 'Architectural', high: 'Premium' };
    var ql = ql_map[r.quality_level] || r.quality_level;
    var detailEl = el.querySelector('#rab-detail-area');

    var html = ''
      + '<a href="#rab-estimates" class="rab-back-link" onclick="navigate(\'rab-estimates\');return false;">\u2190 Back to Estimates</a>'
      + '<div class="rab-detail-grid">'
      + '<div class="rab-card">'
      + '  <h3 class="rab-card-title">' + escHtml(r.name || 'Untitled Estimate') + '</h3>'
      + '  <p class="rab-estimate-meta" style="margin-bottom:var(--space-4)">' + escHtml(r.preset_name || '') + ' \u2022 ' + escHtml(ql) + ' \u2022 ' + r.num_storeys + ' storey' + (r.num_storeys > 1 ? 's' : '') + '</p>'
      + '  <div class="rab-result-rows">'
      + '    <div class="rab-result-row"><span>Ground Floor</span><span>' + Number(r.floor_area_level1_m2).toFixed(1) + ' m\u00B2</span></div>';

    if (Number(r.floor_area_level2_m2) > 0) {
      html += '    <div class="rab-result-row"><span>1st Floor</span><span>' + Number(r.floor_area_level2_m2).toFixed(1) + ' m\u00B2</span></div>';
    }
    if (Number(r.floor_area_level3_m2) > 0) {
      html += '    <div class="rab-result-row"><span>2nd Floor</span><span>' + Number(r.floor_area_level3_m2).toFixed(1) + ' m\u00B2</span></div>';
    }
    if (Number(r.floor_area_other_m2) > 0) {
      html += '    <div class="rab-result-row"><span>Other Levels</span><span>' + Number(r.floor_area_other_m2).toFixed(1) + ' m\u00B2</span></div>';
    }

    html += '    <div class="rab-result-row rab-result-row--sub"><span>Total Floor Area</span><span>' + Number(r.total_floor_area_m2).toFixed(1) + ' m\u00B2</span></div>';

    if (Number(r.rooftop_walkable)) {
      html += '    <div class="rab-result-row"><span>Rooftop Area</span><span>' + Number(r.rooftop_area_m2).toFixed(1) + ' m\u00B2</span></div>';
    }
    if (Number(r.pool_has_pool)) {
      html += '    <div class="rab-result-row"><span>' + (Number(r.pool_is_infinity) ? 'Infinity' : 'Standard') + ' Pool</span><span>' + Number(r.pool_area_m2).toFixed(1) + ' m\u00B2</span></div>';
    }
    if (Number(r.deck_area_m2) > 0) {
      html += '    <div class="rab-result-row"><span>Deck / Terrace</span><span>' + Number(r.deck_area_m2).toFixed(1) + ' m\u00B2</span></div>';
    }

    html += '  </div></div>';

    html += ''
      + '<div class="rab-card">'
      + '  <h3 class="rab-card-title">Cost Breakdown</h3>'
      + '  <div class="rab-result-rows">'
      + '    <div class="rab-result-row"><span>Building Cost</span><span>' + fmtIDR(r.building_cost) + '</span></div>';
    if (Number(r.rooftop_cost) > 0) {
      html += '    <div class="rab-result-row"><span>Rooftop</span><span>' + fmtIDR(r.rooftop_cost) + '</span></div>';
    }
    if (Number(r.pool_cost) > 0) {
      html += '    <div class="rab-result-row"><span>Pool</span><span>' + fmtIDR(r.pool_cost) + '</span></div>';
    }
    if (Number(r.deck_cost) > 0) {
      html += '    <div class="rab-result-row"><span>Deck / Terrace</span><span>' + fmtIDR(r.deck_cost) + '</span></div>';
    }

    html += ''
      + '    <div class="rab-result-row rab-result-row--sub"><span>Subtotal</span><span>' + fmtIDR(r.subtotal_cost) + '</span></div>'
      + '    <div class="rab-result-row"><span>Contingency (' + Number(r.contingency_amount > 0 && r.subtotal_cost > 0 ? (r.contingency_amount / r.subtotal_cost * 100) : 0).toFixed(1) + '%)</span><span>' + fmtIDR(r.contingency_amount) + '</span></div>'
      + '    <div class="rab-result-row rab-result-row--total"><span>Grand Total</span><span>' + fmtIDR(r.grand_total_cost) + '</span></div>'
      + '  </div>'
      + '</div>'
      + '</div>'
      + '<div class="rab-result-actions" style="margin-top:var(--space-4)">'
      + '  <a href="#rab-calculator" class="btn btn--primary" onclick="navigate(\'rab-calculator\');return false;">New Calculation</a>'
      + '  <a href="#rab-estimates" class="btn btn--outline" onclick="navigate(\'rab-estimates\');return false;">All Estimates</a>'
      + '</div>';

    detailEl.innerHTML = html;
  } catch(e) {
    el.querySelector('#rab-detail-area').innerHTML = '<div class="rab-card" style="text-align:center;color:var(--color-text-faint);padding:var(--space-8)">'
      + '<p>' + escHtml(e.message) + '</p>'
      + '<a href="#rab-calculator" class="btn btn--primary" style="margin-top:var(--space-4)" onclick="navigate(\'rab-calculator\');return false;">Back to Calculator</a>'
      + '</div>';
  }
}


// =====================================================
// RENDER: RAB PROJECTS LIST
// =====================================================

var _rabUnitsCache = null;

async function rabLoadUnits() {
  if (_rabUnitsCache) return _rabUnitsCache;
  try {
    var res = await fetch(RAB_API + '?action=units', { credentials: 'include' });
    var json = await res.json();
    _rabUnitsCache = json.data || [];
  } catch(e) { _rabUnitsCache = []; }
  return _rabUnitsCache;
}

async function renderRABProjects(el) {
  if (!UserAuth.user) {
    el.innerHTML = ''
      + '<div class="dir-hero"><div class="container">'
      + '  <h1 class="dir-hero-title">Detailed RAB Tool</h1>'
      + '  <p class="dir-hero-desc">Sign in to create and manage your detailed bill of quantities.</p>'
      + '</div></div>'
      + '<div class="section"><div class="container" style="text-align:center;padding:var(--space-12) 0;">'
      + '  <button class="btn btn--primary" onclick="showAuthModal(\'login\')">Sign In to Continue</button>'
      + '</div></div>';
    return;
  }

  el.innerHTML = ''
    + '<div class="dir-hero"><div class="container">'
    + '  <h1 class="dir-hero-title">Detailed RAB Tool</h1>'
    + '  <p class="dir-hero-desc">Create projects and manage full bills of quantities with Architecture, MEP, and Structure disciplines.</p>'
    + '  <div class="rab-hero-tabs">'
    + '    <a href="#rab-calculator" class="rab-tab" onclick="navigate(\'rab-calculator\');return false;">Calculator</a>'
    + '    <a href="#rab-projects" class="rab-tab active">My RAB Projects</a>'
    + '    <a href="#rab-estimates" class="rab-tab" onclick="navigate(\'rab-estimates\');return false;">Saved Estimates</a>'
    + '  </div>'
    + '</div></div>'
    + '<div class="section"><div class="container">'
    + '  <div class="rdtl-toolbar">'
    + '    <h2 class="rdtl-section-title">My Projects</h2>'
    + '    <button class="btn btn--primary" id="rdtl-new-proj-btn" onclick="rdtlShowNewProject()">+ New Project</button>'
    + '  </div>'
    + '  <div id="rdtl-new-project-form" style="display:none"></div>'
    + '  <div id="rdtl-projects-list"><div class="page-loading"><div class="page-loading-spinner"></div></div></div>'
    + '</div></div>';

  try {
    var res = await fetch(RAB_API + '?action=projects', { credentials: 'include' });
    var json = await res.json();
    if (!res.ok) throw new Error(json.error || 'Failed to load projects');
    var projects = json.data || [];
    var listEl = el.querySelector('#rdtl-projects-list');

    if (projects.length === 0) {
      listEl.innerHTML = ''
        + '<div class="rab-empty-state">'
        + '  <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="var(--color-accent)" stroke-width="1.5" style="margin-bottom:var(--space-4)"><path d="M3 3h18v18H3z"/><path d="M3 9h18M9 3v18"/></svg>'
        + '  <h3 style="font-family:var(--font-display);margin-bottom:var(--space-2)">No projects yet</h3>'
        + '  <p style="color:var(--color-text-faint);margin-bottom:var(--space-4)">Create your first project to start building a detailed RAB.</p>'
        + '</div>';
      return;
    }

    var cards = projects.map(function(p) {
      var statusBadge = '<span class="rdtl-status rdtl-status--' + escHtml(p.status || 'draft') + '">' + escHtml(p.status || 'draft') + '</span>';
      return ''
        + '<a href="#rab-project/' + p.id + '" class="rdtl-project-card" onclick="navigate(\'rab-project/' + p.id + '\');return false;">'
        + '  <div class="rdtl-project-info">'
        + '    <h3 class="rdtl-project-name">' + escHtml(p.name) + '</h3>'
        + '    <p class="rdtl-project-meta">'
        + (p.location ? escHtml(p.location) + ' \u2022 ' : '')
        + (p.gross_floor_area_m2 ? Number(p.gross_floor_area_m2).toFixed(0) + ' m\u00B2 \u2022 ' : '')
        + p.rab_count + ' RAB version' + (p.rab_count != 1 ? 's' : '')
        + '    </p>'
        + '  </div>'
        + '  <div class="rdtl-project-right">'
        + statusBadge
        + '    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="color:var(--color-text-faint)"><path d="M9 18l6-6-6-6"/></svg>'
        + '  </div>'
        + '</a>';
    }).join('');

    listEl.innerHTML = cards;
  } catch(e) {
    el.querySelector('#rdtl-projects-list').innerHTML = '<div class="rab-card" style="text-align:center;color:var(--color-text-faint);padding:var(--space-8)">' + escHtml(e.message) + '</div>';
  }
}

function rdtlShowNewProject() {
  var formEl = document.getElementById('rdtl-new-project-form');
  if (!formEl) return;
  if (formEl.style.display !== 'none') { formEl.style.display = 'none'; return; }
  formEl.style.display = 'block';
  formEl.innerHTML = ''
    + '<div class="rab-card" style="margin-bottom:var(--space-5)">'
    + '  <h3 class="rab-card-title">New Project</h3>'
    + '  <div class="rab-field"><label class="rab-label">Project Name *</label><input type="text" id="rdtl-proj-name" class="rab-input" placeholder="e.g. Villa Sengigi"></div>'
    + '  <div class="rab-fields-grid">'
    + '    <div class="rab-field"><label class="rab-label">Location</label><input type="text" id="rdtl-proj-loc" class="rab-input" placeholder="e.g. Senggigi, Lombok"></div>'
    + '    <div class="rab-field"><label class="rab-label">Gross Floor Area (m\u00B2)</label><input type="number" id="rdtl-proj-area" class="rab-input" min="0" step="0.5" placeholder="e.g. 250"></div>'
    + '  </div>'
    + '  <div class="rab-field"><label class="rab-label">Description</label><textarea id="rdtl-proj-desc" class="rab-input" rows="2" placeholder="Brief project description"></textarea></div>'
    + '  <div style="display:flex;gap:var(--space-3)">'
    + '    <button class="btn btn--primary" onclick="rdtlSaveProject()">Create Project</button>'
    + '    <button class="btn btn--outline" onclick="document.getElementById(\'rdtl-new-project-form\').style.display=\'none\'">Cancel</button>'
    + '  </div>'
    + '</div>';
  var nameInput = document.getElementById('rdtl-proj-name');
  if (nameInput) nameInput.focus();
}

function rdtlSaveProject(projId) {
  var name = document.getElementById('rdtl-proj-name').value.trim();
  if (!name) { showToast('Project name is required.', 'error'); return; }
  var data = {
    name: name,
    location: document.getElementById('rdtl-proj-loc').value.trim(),
    description: document.getElementById('rdtl-proj-desc').value.trim(),
    gross_floor_area_m2: parseFloat(document.getElementById('rdtl-proj-area').value) || null
  };
  if (projId) data.id = projId;

  fetch(RAB_API + '?action=save_project', {
    method: 'POST', credentials: 'include',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(data)
  })
  .then(function(res) { return res.json().then(function(j) { return { ok: res.ok, json: j }; }); })
  .then(function(out) {
    if (!out.ok) throw new Error(out.json.error || 'Save failed');
    showToast('Project saved.', 'success');
    if (projId) { navigate('rab-project/' + projId); }
    else { navigate('rab-project/' + out.json.project_id); }
  })
  .catch(function(e) { showToast(e.message, 'error'); });
}


// =====================================================
// RENDER: RAB PROJECT DETAIL
// =====================================================

async function renderRABProjectDetail(el, projectId) {
  if (!UserAuth.user) { navigate('rab-projects'); return; }
  if (!projectId) { navigate('rab-projects'); return; }

  el.innerHTML = ''
    + '<div class="dir-hero"><div class="container">'
    + '  <h1 class="dir-hero-title">Project Detail</h1>'
    + '  <p class="dir-hero-desc">Manage RAB versions for this project.</p>'
    + '</div></div>'
    + '<div class="section"><div class="container"><div id="rdtl-proj-content"><div class="page-loading"><div class="page-loading-spinner"></div></div></div></div></div>';

  try {
    var res = await fetch(RAB_API + '?action=project_detail&id=' + projectId, { credentials: 'include' });
    var json = await res.json();
    if (!res.ok) throw new Error(json.error || 'Not found');
    var p = json.data;
    var contentEl = el.querySelector('#rdtl-proj-content');

    var html = ''
      + '<a href="#rab-projects" class="rab-back-link" onclick="navigate(\'rab-projects\');return false;">\u2190 All Projects</a>'
      + '<div class="rdtl-proj-header">'
      + '  <div>'
      + '    <h2 class="rdtl-proj-title">' + escHtml(p.name) + '</h2>'
      + '    <p class="rdtl-proj-meta">';
    if (p.location) html += escHtml(p.location) + ' \u2022 ';
    if (p.gross_floor_area_m2) html += Number(p.gross_floor_area_m2).toFixed(0) + ' m\u00B2 \u2022 ';
    html += '<span class="rdtl-status rdtl-status--' + escHtml(p.status) + '">' + escHtml(p.status) + '</span>';
    html += '</p>';
    if (p.description) html += '<p style="color:var(--color-text-faint);font-size:var(--text-sm);margin-top:var(--space-1)">' + escHtml(p.description) + '</p>';
    html += '  </div>'
      + '  <div class="rdtl-proj-actions">'
      + '    <button class="btn btn--outline btn--sm" onclick="rdtlEditProject(' + p.id + ')">Edit</button>'
      + '    <button class="btn btn--danger-outline btn--sm" onclick="rdtlDeleteProject(' + p.id + ')">Delete</button>'
      + '  </div>'
      + '</div>';

    // RAB versions
    html += '<div class="rdtl-toolbar" style="margin-top:var(--space-6)">'
      + '  <h3 class="rdtl-section-title">RAB Versions</h3>'
      + '  <button class="btn btn--primary btn--sm" onclick="rdtlCreateRab(' + p.id + ')">+ New RAB Version</button>'
      + '</div>';

    var rabs = p.rabs || [];
    if (rabs.length === 0) {
      html += '<div class="rab-card" style="text-align:center;color:var(--color-text-faint);padding:var(--space-6)">No RAB versions yet. Create one to get started.</div>';
    } else {
      html += '<div class="rdtl-rab-list">';
      rabs.forEach(function(r) {
        html += ''
          + '<div class="rdtl-rab-card">'
          + '  <div class="rdtl-rab-info">'
          + '    <a href="#rab-editor/' + r.id + '" class="rdtl-rab-name" onclick="navigate(\'rab-editor/' + r.id + '\');return false;">v' + r.version + ' \u2014 ' + escHtml(r.name) + '</a>'
          + '    <p class="rdtl-rab-meta">'
          + (r.grand_total ? 'Total: ' + fmtIDR(r.grand_total) : 'No items yet')
          + (r.cost_per_m2 ? ' \u2022 ' + fmtIDR(r.cost_per_m2) + '/m\u00B2' : '')
          + '    </p>'
          + '  </div>'
          + '  <div class="rdtl-rab-actions">'
          + '    <button class="btn btn--primary btn--sm" onclick="navigate(\'rab-editor/' + r.id + '\')">Edit</button>'
          + '    <button class="btn btn--outline btn--sm" onclick="rdtlCloneRab(' + r.id + ',' + p.id + ')">Clone</button>'
          + '    <a href="' + RAB_API + '?action=export_excel&id=' + r.id + '" class="btn btn--outline btn--sm">Excel</a>'
          + '    <button class="btn btn--danger-outline btn--sm" onclick="rdtlDeleteRab(' + r.id + ',' + p.id + ')">Delete</button>'
          + '  </div>'
          + '</div>';
      });
      html += '</div>';
    }

    contentEl.innerHTML = html;
  } catch(e) {
    el.querySelector('#rdtl-proj-content').innerHTML = '<div class="rab-card" style="text-align:center;padding:var(--space-8)"><p style="color:var(--color-text-faint)">' + escHtml(e.message) + '</p></div>';
  }
}

function rdtlEditProject(projId) {
  // Navigate to a simple edit view — reuse inline approach
  fetch(RAB_API + '?action=project_detail&id=' + projId, { credentials: 'include' })
  .then(function(res) { return res.json(); })
  .then(function(json) {
    var p = json.data;
    var html = ''
      + '<div class="rab-card" style="margin-top:var(--space-4)">'
      + '  <h3 class="rab-card-title">Edit Project</h3>'
      + '  <div class="rab-field"><label class="rab-label">Project Name *</label><input type="text" id="rdtl-proj-name" class="rab-input" value="' + escHtml(p.name) + '"></div>'
      + '  <div class="rab-fields-grid">'
      + '    <div class="rab-field"><label class="rab-label">Location</label><input type="text" id="rdtl-proj-loc" class="rab-input" value="' + escHtml(p.location || '') + '"></div>'
      + '    <div class="rab-field"><label class="rab-label">Gross Floor Area (m\u00B2)</label><input type="number" id="rdtl-proj-area" class="rab-input" min="0" step="0.5" value="' + (p.gross_floor_area_m2 || '') + '"></div>'
      + '  </div>'
      + '  <div class="rab-field"><label class="rab-label">Description</label><textarea id="rdtl-proj-desc" class="rab-input" rows="2">' + escHtml(p.description || '') + '</textarea></div>'
      + '  <div class="rab-field"><label class="rab-label">Status</label><select id="rdtl-proj-status" class="rab-select"><option value="draft"' + (p.status === 'draft' ? ' selected' : '') + '>Draft</option><option value="active"' + (p.status === 'active' ? ' selected' : '') + '>Active</option><option value="archived"' + (p.status === 'archived' ? ' selected' : '') + '>Archived</option></select></div>'
      + '  <div style="display:flex;gap:var(--space-3)">'
      + '    <button class="btn btn--primary" onclick="rdtlSaveProjectEdit(' + projId + ')">Save Changes</button>'
      + '    <button class="btn btn--outline" onclick="navigate(\'rab-project/' + projId + '\')">Cancel</button>'
      + '  </div>'
      + '</div>';
    var content = document.getElementById('rdtl-proj-content');
    if (content) content.innerHTML = html;
  });
}

function rdtlSaveProjectEdit(projId) {
  var data = {
    id: projId,
    name: document.getElementById('rdtl-proj-name').value.trim(),
    location: document.getElementById('rdtl-proj-loc').value.trim(),
    description: document.getElementById('rdtl-proj-desc').value.trim(),
    gross_floor_area_m2: parseFloat(document.getElementById('rdtl-proj-area').value) || null,
    status: document.getElementById('rdtl-proj-status').value
  };
  if (!data.name) { showToast('Project name is required.', 'error'); return; }
  fetch(RAB_API + '?action=save_project', {
    method: 'POST', credentials: 'include',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(data)
  })
  .then(function(res) { return res.json().then(function(j) { return { ok: res.ok, json: j }; }); })
  .then(function(out) {
    if (!out.ok) throw new Error(out.json.error || 'Save failed');
    showToast('Project updated.', 'success');
    navigate('rab-project/' + projId);
  })
  .catch(function(e) { showToast(e.message, 'error'); });
}

function rdtlDeleteProject(projId) {
  if (!confirm('Delete this project and all its RAB data? This cannot be undone.')) return;
  fetch(RAB_API + '?action=delete_project', {
    method: 'POST', credentials: 'include',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ id: projId })
  })
  .then(function(res) { return res.json().then(function(j) { return { ok: res.ok, json: j }; }); })
  .then(function(out) {
    if (!out.ok) throw new Error(out.json.error || 'Delete failed');
    showToast('Project deleted.', 'success');
    navigate('rab-projects');
  })
  .catch(function(e) { showToast(e.message, 'error'); });
}

function rdtlCreateRab(projId) {
  var name = prompt('RAB version name (optional):');
  if (name === null) return;
  fetch(RAB_API + '?action=create_rab', {
    method: 'POST', credentials: 'include',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ project_id: projId, name: name || '' })
  })
  .then(function(res) { return res.json().then(function(j) { return { ok: res.ok, json: j }; }); })
  .then(function(out) {
    if (!out.ok) throw new Error(out.json.error || 'Create failed');
    showToast('RAB version created.', 'success');
    navigate('rab-editor/' + out.json.rab_id);
  })
  .catch(function(e) { showToast(e.message, 'error'); });
}

function rdtlCloneRab(rabId, projId) {
  if (!confirm('Clone this RAB version?')) return;
  fetch(RAB_API + '?action=clone_rab', {
    method: 'POST', credentials: 'include',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ rab_id: rabId })
  })
  .then(function(res) { return res.json().then(function(j) { return { ok: res.ok, json: j }; }); })
  .then(function(out) {
    if (!out.ok) throw new Error(out.json.error || 'Clone failed');
    showToast('RAB cloned.', 'success');
    navigate('rab-project/' + projId);
  })
  .catch(function(e) { showToast(e.message, 'error'); });
}

function rdtlDeleteRab(rabId, projId) {
  if (!confirm('Delete this RAB version? This cannot be undone.')) return;
  fetch(RAB_API + '?action=delete_rab', {
    method: 'POST', credentials: 'include',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ rab_id: rabId })
  })
  .then(function(res) { return res.json().then(function(j) { return { ok: res.ok, json: j }; }); })
  .then(function(out) {
    if (!out.ok) throw new Error(out.json.error || 'Delete failed');
    showToast('RAB deleted.', 'success');
    navigate('rab-project/' + projId);
  })
  .catch(function(e) { showToast(e.message, 'error'); });
}


// =====================================================
// RENDER: RAB EDITOR (full discipline/section/item editor)
// =====================================================

var _rabEditorState = { rabId: 0, rab: null, activeDiscId: 0, units: [] };

async function renderRABEditor(el, rabId) {
  if (!UserAuth.user) { navigate('rab-projects'); return; }
  if (!rabId) { navigate('rab-projects'); return; }
  _rabEditorState.rabId = parseInt(rabId);

  el.innerHTML = ''
    + '<div class="dir-hero"><div class="container">'
    + '  <h1 class="dir-hero-title">RAB Editor</h1>'
    + '  <p class="dir-hero-desc">Edit your bill of quantities line by line.</p>'
    + '</div></div>'
    + '<div class="section"><div class="container"><div id="rdtl-editor-content"><div class="page-loading"><div class="page-loading-spinner"></div></div></div></div></div>';

  try {
    var reqs = await Promise.all([
      fetch(RAB_API + '?action=rab_detail&id=' + rabId, { credentials: 'include' }).then(function(r) { return r.json(); }),
      rabLoadUnits()
    ]);
    var rabJson = reqs[0];
    var units = reqs[1];
    if (rabJson.error) throw new Error(rabJson.error);
    var rab = rabJson.data;
    _rabEditorState.rab = rab;
    _rabEditorState.units = units;

    var disciplines = rab.disciplines || [];
    if (disciplines.length > 0 && !_rabEditorState.activeDiscId) {
      _rabEditorState.activeDiscId = parseInt(disciplines[0].id);
    }

    var contentEl = el.querySelector('#rdtl-editor-content');
    rdtlRenderEditorChrome(contentEl, rab, disciplines);
    rdtlLoadSections();
  } catch(e) {
    el.querySelector('#rdtl-editor-content').innerHTML = '<div class="rab-card" style="text-align:center;padding:var(--space-8)"><p style="color:var(--color-text-faint)">' + escHtml(e.message) + '</p></div>';
  }
}

function rdtlRenderEditorChrome(contentEl, rab, disciplines) {
  var t = rab.totals || {};
  var html = ''
    + '<a href="#rab-project/' + rab.project_id + '" class="rab-back-link" onclick="navigate(\'rab-project/' + rab.project_id + '\');return false;">\u2190 ' + escHtml(rab.project_name) + '</a>'
    + '<div class="rdtl-editor-header">'
    + '  <div>'
    + '    <h2 class="rdtl-proj-title">v' + rab.version + ' \u2014 ' + escHtml(rab.name) + '</h2>'
    + '    <p class="rdtl-proj-meta">' + escHtml(rab.project_name) + (rab.location ? ' \u2022 ' + escHtml(rab.location) : '') + '</p>'
    + '  </div>'
    + '  <a href="' + RAB_API + '?action=export_excel&id=' + rab.id + '" class="btn btn--outline btn--sm">Export Excel</a>'
    + '</div>';

  // Summary bar
  html += '<div class="rdtl-summary-bar">'
    + '  <div class="rdtl-summary-item"><span class="rdtl-summary-label">Architecture</span><span class="rdtl-summary-val" id="rdtl-total-arch">' + fmtIDR(t.architecture_total || 0) + '</span></div>'
    + '  <div class="rdtl-summary-item"><span class="rdtl-summary-label">MEP</span><span class="rdtl-summary-val" id="rdtl-total-mep">' + fmtIDR(t.mep_total || 0) + '</span></div>'
    + '  <div class="rdtl-summary-item"><span class="rdtl-summary-label">Structure</span><span class="rdtl-summary-val" id="rdtl-total-str">' + fmtIDR(t.structure_total || 0) + '</span></div>'
    + '  <div class="rdtl-summary-item rdtl-summary-item--grand"><span class="rdtl-summary-label">Grand Total</span><span class="rdtl-summary-val" id="rdtl-total-grand">' + fmtIDR(t.grand_total || 0) + '</span></div>'
    + '  <div class="rdtl-summary-item"><span class="rdtl-summary-label">Area (m\u00B2)</span>'
    + '    <input type="number" class="rdtl-area-input" id="rdtl-area-input" value="' + (t.house_area_m2 || '') + '" min="0" step="0.5" onchange="rdtlUpdateArea()" title="House area for cost/m\u00B2 calculation">'
    + '  </div>'
    + '  <div class="rdtl-summary-item"><span class="rdtl-summary-label">Cost/m\u00B2</span><span class="rdtl-summary-val" id="rdtl-total-perm2">' + (t.cost_per_m2 ? fmtIDR(t.cost_per_m2) : '\u2014') + '</span></div>'
    + '</div>';

  // Discipline tabs
  html += '<div class="rdtl-disc-tabs">';
  disciplines.forEach(function(d) {
    var isActive = parseInt(d.id) === _rabEditorState.activeDiscId;
    html += '<button class="rdtl-disc-tab' + (isActive ? ' active' : '') + '" data-disc-id="' + d.id + '" onclick="rdtlSwitchDisc(' + d.id + ')">' + escHtml(d.name) + '</button>';
  });
  html += '</div>';

  // Sections container
  html += '<div id="rdtl-sections-area"><div class="page-loading"><div class="page-loading-spinner"></div></div></div>';

  // Add section button
  html += '<div style="margin-top:var(--space-4)">'
    + '  <button class="btn btn--outline btn--sm" onclick="rdtlAddSection()">+ Add Section</button>'
    + '</div>';

  contentEl.innerHTML = html;
}

function rdtlSwitchDisc(discId) {
  _rabEditorState.activeDiscId = parseInt(discId);
  // Update tab styling
  document.querySelectorAll('.rdtl-disc-tab').forEach(function(btn) {
    btn.classList.toggle('active', parseInt(btn.getAttribute('data-disc-id')) === _rabEditorState.activeDiscId);
  });
  rdtlLoadSections();
}

function rdtlLoadSections() {
  var area = document.getElementById('rdtl-sections-area');
  if (!area) return;
  area.innerHTML = '<div class="page-loading"><div class="page-loading-spinner"></div></div>';

  fetch(RAB_API + '?action=get_sections&rab_id=' + _rabEditorState.rabId + '&disc_id=' + _rabEditorState.activeDiscId, { credentials: 'include' })
  .then(function(res) { return res.json(); })
  .then(function(json) {
    if (!json.ok) throw new Error('Failed to load sections');
    var sections = json.sections || [];
    rdtlRenderSections(area, sections);
  })
  .catch(function(e) {
    area.innerHTML = '<div class="rab-card" style="color:var(--color-text-faint);text-align:center;padding:var(--space-6)">' + escHtml(e.message) + '</div>';
  });
}

function rdtlRenderSections(area, sections) {
  if (sections.length === 0) {
    area.innerHTML = '<div class="rab-card" style="text-align:center;color:var(--color-text-faint);padding:var(--space-6)">No sections yet. Add a section to start.</div>';
    return;
  }

  var units = _rabEditorState.units;
  var unitOpts = units.map(function(u) { return '<option value="' + u.id + '">' + escHtml(u.code) + ' \u2014 ' + escHtml(u.name) + '</option>'; }).join('');

  var html = '';
  sections.forEach(function(sect) {
    var sectionTotal = 0;
    var itemsHtml = '';

    (sect.items || []).forEach(function(it) {
      var total = Number(it.quantity) * Number(it.rate);
      sectionTotal += total;
      itemsHtml += ''
        + '<tr class="rdtl-item-row" data-item-id="' + it.id + '">'
        + '  <td class="rdtl-item-name">' + escHtml(it.name) + '</td>'
        + '  <td class="rdtl-item-unit">' + escHtml(it.unit_code || '') + '</td>'
        + '  <td class="rdtl-item-qty">' + Number(it.quantity).toFixed(3) + '</td>'
        + '  <td class="rdtl-item-rate">' + fmtIDR(it.rate) + '</td>'
        + '  <td class="rdtl-item-total">' + fmtIDR(total) + '</td>'
        + '  <td class="rdtl-item-actions">'
        + '    <button class="rdtl-btn-icon" onclick="rdtlEditItem(' + it.id + ',' + sect.id + ')" title="Edit">\u270E</button>'
        + '    <button class="rdtl-btn-icon rdtl-btn-icon--danger" onclick="rdtlDeleteItem(' + it.id + ')" title="Delete">\u2715</button>'
        + '  </td>'
        + '</tr>';
    });

    html += ''
      + '<div class="rdtl-section" data-section-id="' + sect.id + '">'
      + '  <div class="rdtl-section-header">'
      + '    <h4 class="rdtl-section-name">' + escHtml(sect.name) + '</h4>'
      + '    <div class="rdtl-section-actions">'
      + '      <span class="rdtl-section-total">Subtotal: ' + fmtIDR(sectionTotal) + '</span>'
      + '      <button class="rdtl-btn-icon" onclick="rdtlRenameSection(' + sect.id + ',\'' + escHtml(sect.name).replace(/'/g, "\\'") + '\')" title="Rename">\u270E</button>'
      + '      <button class="rdtl-btn-icon rdtl-btn-icon--danger" onclick="rdtlDeleteSection(' + sect.id + ')" title="Delete">\u2715</button>'
      + '    </div>'
      + '  </div>'
      + '  <div class="rdtl-items-table-wrap">'
      + '    <table class="rdtl-items-table">'
      + '      <thead><tr><th>Description</th><th>Unit</th><th>Qty</th><th>Rate (IDR)</th><th>Total (IDR)</th><th></th></tr></thead>'
      + '      <tbody>' + itemsHtml + '</tbody>'
      + '    </table>'
      + '  </div>'
      + '  <div class="rdtl-add-item-area" id="rdtl-add-item-' + sect.id + '">'
      + '    <button class="btn btn--outline btn--sm" onclick="rdtlShowAddItem(' + sect.id + ')">+ Add Item</button>'
      + '  </div>'
      + '</div>';
  });

  area.innerHTML = html;
}

function rdtlShowAddItem(sectionId) {
  var area = document.getElementById('rdtl-add-item-' + sectionId);
  if (!area) return;
  // If form already shown, hide it
  if (area.querySelector('.rdtl-item-form')) { area.innerHTML = '<button class="btn btn--outline btn--sm" onclick="rdtlShowAddItem(' + sectionId + ')">+ Add Item</button>'; return; }

  var units = _rabEditorState.units;
  var unitOpts = '<option value="">Select unit...</option>' + units.map(function(u) { return '<option value="' + u.id + '">' + escHtml(u.code) + '</option>'; }).join('');

  area.innerHTML = ''
    + '<div class="rdtl-item-form">'
    + '  <div class="rdtl-item-form-grid">'
    + '    <input type="text" class="rab-input" id="rdtl-new-name-' + sectionId + '" placeholder="Item description">'
    + '    <select class="rab-select" id="rdtl-new-unit-' + sectionId + '">' + unitOpts + '</select>'
    + '    <input type="number" class="rab-input" id="rdtl-new-qty-' + sectionId + '" placeholder="Qty" min="0" step="0.001">'
    + '    <input type="number" class="rab-input" id="rdtl-new-rate-' + sectionId + '" placeholder="Rate (IDR)" min="0" step="1">'
    + '  </div>'
    + '  <div style="display:flex;gap:var(--space-2);margin-top:var(--space-2)">'
    + '    <button class="btn btn--primary btn--sm" onclick="rdtlSaveNewItem(' + sectionId + ')">Save</button>'
    + '    <button class="btn btn--outline btn--sm" onclick="rdtlShowAddItem(' + sectionId + ')">Cancel</button>'
    + '  </div>'
    + '</div>';
  var nameInput = document.getElementById('rdtl-new-name-' + sectionId);
  if (nameInput) nameInput.focus();
}

function rdtlSaveNewItem(sectionId) {
  var name = document.getElementById('rdtl-new-name-' + sectionId).value.trim();
  var unitId = document.getElementById('rdtl-new-unit-' + sectionId).value;
  var qty = parseFloat(document.getElementById('rdtl-new-qty-' + sectionId).value) || 0;
  var rate = parseFloat(document.getElementById('rdtl-new-rate-' + sectionId).value) || 0;
  if (!name) { showToast('Item description is required.', 'error'); return; }
  if (!unitId) { showToast('Please select a unit.', 'error'); return; }

  fetch(RAB_API + '?action=save_item', {
    method: 'POST', credentials: 'include',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ section_id: sectionId, name: name, unit_id: parseInt(unitId), quantity: qty, rate: rate })
  })
  .then(function(res) { return res.json(); })
  .then(function(json) {
    if (!json.ok) throw new Error('Save failed');
    rdtlUpdateTotals(json.totals);
    rdtlLoadSections();
  })
  .catch(function(e) { showToast(e.message, 'error'); });
}

function rdtlEditItem(itemId, sectionId) {
  // Find the item row and replace with inline edit form
  var row = document.querySelector('tr[data-item-id="' + itemId + '"]');
  if (!row) return;

  var name = row.querySelector('.rdtl-item-name').textContent;
  var qtyText = row.querySelector('.rdtl-item-qty').textContent;
  var rateText = row.querySelector('.rdtl-item-rate').textContent.replace(/[^\d]/g, '');
  var unitCode = row.querySelector('.rdtl-item-unit').textContent;

  var units = _rabEditorState.units;
  var unitOpts = units.map(function(u) {
    var sel = (u.code === unitCode) ? ' selected' : '';
    return '<option value="' + u.id + '"' + sel + '>' + escHtml(u.code) + '</option>';
  }).join('');

  row.innerHTML = ''
    + '<td colspan="5"><div class="rdtl-item-form-grid">'
    + '  <input type="text" class="rab-input" id="rdtl-edit-name-' + itemId + '" value="' + escHtml(name) + '">'
    + '  <select class="rab-select" id="rdtl-edit-unit-' + itemId + '">' + unitOpts + '</select>'
    + '  <input type="number" class="rab-input" id="rdtl-edit-qty-' + itemId + '" value="' + qtyText + '" min="0" step="0.001">'
    + '  <input type="number" class="rab-input" id="rdtl-edit-rate-' + itemId + '" value="' + rateText + '" min="0" step="1">'
    + '</div></td>'
    + '<td><div style="display:flex;gap:4px;flex-direction:column">'
    + '  <button class="btn btn--primary btn--sm" onclick="rdtlSaveEditItem(' + itemId + ',' + sectionId + ')">Save</button>'
    + '  <button class="btn btn--outline btn--sm" onclick="rdtlLoadSections()">Cancel</button>'
    + '</div></td>';
}

function rdtlSaveEditItem(itemId, sectionId) {
  var name = document.getElementById('rdtl-edit-name-' + itemId).value.trim();
  var unitId = document.getElementById('rdtl-edit-unit-' + itemId).value;
  var qty = parseFloat(document.getElementById('rdtl-edit-qty-' + itemId).value) || 0;
  var rate = parseFloat(document.getElementById('rdtl-edit-rate-' + itemId).value) || 0;
  if (!name) { showToast('Item description is required.', 'error'); return; }

  fetch(RAB_API + '?action=save_item', {
    method: 'POST', credentials: 'include',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ item_id: itemId, section_id: sectionId, name: name, unit_id: parseInt(unitId), quantity: qty, rate: rate })
  })
  .then(function(res) { return res.json(); })
  .then(function(json) {
    if (!json.ok) throw new Error('Save failed');
    rdtlUpdateTotals(json.totals);
    rdtlLoadSections();
  })
  .catch(function(e) { showToast(e.message, 'error'); });
}

function rdtlDeleteItem(itemId) {
  if (!confirm('Delete this item?')) return;
  fetch(RAB_API + '?action=delete_item', {
    method: 'POST', credentials: 'include',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ item_id: itemId })
  })
  .then(function(res) { return res.json(); })
  .then(function(json) {
    if (!json.ok) throw new Error('Delete failed');
    rdtlUpdateTotals(json.totals);
    rdtlLoadSections();
  })
  .catch(function(e) { showToast(e.message, 'error'); });
}

function rdtlAddSection() {
  var name = prompt('New section name:');
  if (!name || !name.trim()) return;
  fetch(RAB_API + '?action=save_section', {
    method: 'POST', credentials: 'include',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ rab_id: _rabEditorState.rabId, disc_id: _rabEditorState.activeDiscId, name: name.trim() })
  })
  .then(function(res) { return res.json(); })
  .then(function(json) {
    if (!json.ok) throw new Error('Create failed');
    rdtlLoadSections();
  })
  .catch(function(e) { showToast(e.message, 'error'); });
}

function rdtlRenameSection(sectionId, currentName) {
  var name = prompt('Rename section:', currentName);
  if (!name || !name.trim()) return;
  fetch(RAB_API + '?action=save_section', {
    method: 'POST', credentials: 'include',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ section_id: sectionId, name: name.trim() })
  })
  .then(function(res) { return res.json(); })
  .then(function(json) {
    if (!json.ok) throw new Error('Rename failed');
    rdtlLoadSections();
  })
  .catch(function(e) { showToast(e.message, 'error'); });
}

function rdtlDeleteSection(sectionId) {
  if (!confirm('Delete this section? It must have no items.')) return;
  fetch(RAB_API + '?action=delete_section', {
    method: 'POST', credentials: 'include',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ section_id: sectionId })
  })
  .then(function(res) { return res.json(); })
  .then(function(json) {
    if (!json.ok) throw new Error(json.error || 'Delete failed');
    rdtlLoadSections();
  })
  .catch(function(e) { showToast(e.message, 'error'); });
}

function rdtlUpdateArea() {
  var area = parseFloat(document.getElementById('rdtl-area-input').value) || 0;
  fetch(RAB_API + '?action=update_area', {
    method: 'POST', credentials: 'include',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ rab_id: _rabEditorState.rabId, area: area })
  })
  .then(function(res) { return res.json(); })
  .then(function(json) {
    if (!json.ok) throw new Error('Update failed');
    rdtlUpdateTotals(json.totals);
  })
  .catch(function(e) { showToast(e.message, 'error'); });
}

function rdtlUpdateTotals(totals) {
  if (!totals) return;
  var el;
  el = document.getElementById('rdtl-total-arch');
  if (el) el.textContent = fmtIDR(totals.arch || 0);
  el = document.getElementById('rdtl-total-mep');
  if (el) el.textContent = fmtIDR(totals.mep || 0);
  el = document.getElementById('rdtl-total-str');
  if (el) el.textContent = fmtIDR(totals.str || 0);
  el = document.getElementById('rdtl-total-grand');
  if (el) el.textContent = fmtIDR(totals.grand || 0);
  el = document.getElementById('rdtl-total-perm2');
  if (el) el.textContent = totals.cost_per_m2 ? fmtIDR(totals.cost_per_m2) : '\u2014';
}


// =====================================================
// RENDER: ABOUT
// =====================================================

function renderAbout(el) {
  el.innerHTML = '\n'
    + '<div class="dir-hero">\n'
    + '  <div class="container">\n'
    + '    <h1 class="dir-hero-title">About Build in Lombok</h1>\n'
    + '    <p class="dir-hero-desc">Connecting foreign investors and builders with trusted local professionals across Lombok, Indonesia.</p>\n'
    + '  </div>\n'
    + '</div>\n'
    + '<div class="section">\n'
    + '  <div class="container" style="max-width:720px">\n'
    + '    <div style="display:flex;flex-direction:column;gap:var(--space-8)">\n'
    + '      <div>\n'
    + '        <h2 style="font-family:var(--font-display);font-size:var(--text-2xl);margin-bottom:var(--space-4)">Our Mission</h2>\n'
    + '        <p style="color:var(--color-text-muted);line-height:1.7">Build in Lombok is a comprehensive directory and resource platform designed to help foreign investors, expats, and property buyers navigate the construction and real estate landscape in Lombok. We connect you with verified builders, architects, engineers, developers, and material suppliers so you can build with confidence.</p>\n'
    + '      </div>\n'
    + '      <div>\n'
    + '        <h2 style="font-family:var(--font-display);font-size:var(--text-2xl);margin-bottom:var(--space-4)">What We Offer</h2>\n'
    + '        <ul style="color:var(--color-text-muted);line-height:2;padding-left:var(--space-5)">\n'
    + '          <li>A curated directory of trusted builders, tradespeople, and professionals</li>\n'
    + '          <li>Property and land listings across Lombok</li>\n'
    + '          <li>Developer profiles and investment project showcases</li>\n'
    + '          <li>Guides on building, land titles, and investment yields</li>\n'
    + '          <li>Agent connections for buying and selling property</li>\n'
    + '        </ul>\n'
    + '      </div>\n'
    + '      <div>\n'
    + '        <h2 style="font-family:var(--font-display);font-size:var(--text-2xl);margin-bottom:var(--space-4)">Get in Touch</h2>\n'
    + '        <p style="color:var(--color-text-muted);line-height:1.7;margin-bottom:var(--space-4)">Have questions or want to list your business? Reach out to us via WhatsApp.</p>\n'
    + '        <a href="https://wa.me/628123456789" target="_blank" rel="noopener noreferrer" class="btn btn--whatsapp">\n'
    + '          <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg>\n'
    + '          WhatsApp Us\n'
    + '        </a>\n'
    + '      </div>\n'
    + '    </div>\n'
    + '  </div>\n'
    + '</div>';
}

// =====================================================
// RENDER: MY ACCOUNT PAGE
// =====================================================

async function renderAccount(el, params = {}) {
  if (!UserAuth.user) {
    showAuthModal('login');
    navigate('home');
    return;
  }
  const user = UserAuth.user;
  const activeTab = params.tab || 'favorites';

  let favsHtml = '<p style="color:var(--color-text-muted);font-size:var(--text-sm)">Loading...</p>';
  let claimsHtml = favsHtml;
  let subsHtml = favsHtml;

  el.innerHTML = `
    <div class="page-header">
      <div class="container">
        <h1 class="page-title">Welcome, ${user.display_name}</h1>
      </div>
    </div>
    <div class="section">
      <div class="container">
        <div class="account-tabs">
          <button class="account-tab ${activeTab==='favorites'?'active':''}" onclick="switchAccountTab('favorites')">Favorites</button>
          <button class="account-tab ${activeTab==='claims'?'active':''}" onclick="switchAccountTab('claims')">My Claims</button>
          <button class="account-tab ${activeTab==='submissions'?'active':''}" onclick="switchAccountTab('submissions')">My Submissions</button>
          <button class="account-tab ${activeTab==='profile'?'active':''}" onclick="switchAccountTab('profile')">Profile</button>
        </div>
        <div class="account-section ${activeTab==='favorites'?'active':''}" id="tab-favorites"></div>
        <div class="account-section ${activeTab==='claims'?'active':''}" id="tab-claims"></div>
        <div class="account-section ${activeTab==='submissions'?'active':''}" id="tab-submissions"></div>
        <div class="account-section ${activeTab==='profile'?'active':''}" id="tab-profile">
          <div class="card" style="max-width:480px;">
            <h3 style="font-family:var(--font-display);margin-bottom:var(--space-4)">Profile</h3>
            <div class="info-list">
              <div class="info-row"><span class="info-label">Email</span><span class="info-value">${user.email}</span></div>
              <div class="info-row"><span class="info-label">Name</span><span class="info-value">${user.display_name}</span></div>
              <div class="info-row"><span class="info-label">Member since</span><span class="info-value">${new Date(user.created_at).toLocaleDateString()}</span></div>
            </div>
          </div>
        </div>
      </div>
    </div>
  `;

  window.switchAccountTab = function(tab) {
    el.querySelectorAll('.account-tab').forEach(t => t.classList.toggle('active', t.textContent.toLowerCase().includes(tab)));
    el.querySelectorAll('.account-section').forEach(s => s.classList.remove('active'));
    const target = document.getElementById('tab-' + tab);
    if (target) target.classList.add('active');
  };

  // Load favorites
  try {
    const res = await UserAuth.apiCall('favorites');
    const favs = res.data || [];
    const favEl = document.getElementById('tab-favorites');
    if (favEl) {
      if (favs.length === 0) {
        favEl.innerHTML = `<div class="empty-state"><div class="empty-state-icon">${iconHeartOutline()}</div><h3 class="empty-state-title">No favorites yet</h3><p class="empty-state-desc">Browse the directory and tap the heart icon to save listings.</p><button class="btn btn--primary btn--sm" onclick="navigate('directory')">Browse Directory</button></div>`;
      } else {
        favEl.innerHTML = favs.map(f => {
          const route = f.entity_type === 'provider' ? 'provider' : f.entity_type === 'developer' ? 'developer' : 'project';
          return `<div class="fav-list-item">
            <span class="fav-type">${f.entity_type}</span>
            <a href="#${route}/${f.entity_slug}" onclick="navigate('${route}/${f.entity_slug}');return false;" style="flex:1;font-weight:500">${f.entity_name || 'Unknown'}</a>
            <button class="fav-btn favorited" onclick="UserAuth.toggleFavorite('${f.entity_type}', ${f.entity_id});this.closest('.fav-list-item').remove();" title="Remove">${iconHeartFilled()}</button>
          </div>`;
        }).join('');
      }
    }
  } catch(e) { /* silent */ }

  // Load claims
  try {
    const res = await UserAuth.apiCall('my_claims');
    const claims = res.data || [];
    const clEl = document.getElementById('tab-claims');
    if (clEl) {
      if (claims.length === 0) {
        clEl.innerHTML = '<p style="color:var(--color-text-muted);font-size:var(--text-sm)">You haven\'t claimed any listings yet. Visit a provider page to claim it.</p>';
      } else {
        clEl.innerHTML = claims.map(c => `
          <div class="card" style="margin-bottom:var(--space-3)">
            <div style="display:flex;justify-content:space-between;align-items:center">
              <strong>${c.provider_name}</strong>
              <span class="claim-status claim-status--${c.status}">${c.status}</span>
            </div>
            <p style="font-size:var(--text-xs);color:var(--color-text-muted);margin-top:var(--space-1)">Submitted: ${new Date(c.created_at).toLocaleDateString()} &middot; Role: ${c.business_role}</p>
            ${c.admin_notes ? `<p style="font-size:var(--text-xs);margin-top:var(--space-1)"><strong>Admin:</strong> ${c.admin_notes}</p>` : ''}
          </div>
        `).join('');
      }
    }
  } catch(e) { /* silent */ }

  // Load submissions
  try {
    const res = await UserAuth.apiCall('my_submissions');
    const subs = res.data || [];
    const subEl = document.getElementById('tab-submissions');
    if (subEl) {
      if (subs.length === 0) {
        subEl.innerHTML = `<p style="color:var(--color-text-muted);font-size:var(--text-sm)">No submissions yet. <button onclick="navigate('submit-listing')" style="color:var(--color-primary);font-weight:600;background:none;border:none;cursor:pointer;font-size:inherit;font-family:inherit">Submit a new listing</button></p>`;
      } else {
        subEl.innerHTML = subs.map(s => `
          <div class="card" style="margin-bottom:var(--space-3)">
            <div style="display:flex;justify-content:space-between;align-items:center">
              <strong>${s.business_name}</strong>
              <span class="claim-status claim-status--${s.status}">${s.status}</span>
            </div>
            <p style="font-size:var(--text-xs);color:var(--color-text-muted);margin-top:var(--space-1)">Submitted: ${new Date(s.created_at).toLocaleDateString()}</p>
            ${s.admin_notes ? `<p style="font-size:var(--text-xs);margin-top:var(--space-1)"><strong>Admin:</strong> ${s.admin_notes}</p>` : ''}
          </div>
        `).join('');
      }
    }
  } catch(e) { /* silent */ }
}

// =====================================================
// RENDER: SUBMIT LISTING PAGE
// =====================================================

async function renderSubmitListing(el) {
  if (!UserAuth.user) {
    showAuthModal('login');
    navigate('home');
    return;
  }

  const categoryTree = {
    builders_trades: [
      { key: 'general_contractor', label: 'General Contractor' },
      { key: 'carpenter', label: 'Carpenter / Joiner' },
      { key: 'mason', label: 'Mason / Concrete Worker' },
      { key: 'plumber', label: 'Plumber' },
      { key: 'electrician', label: 'Electrician' },
      { key: 'painter', label: 'Painter / Finisher' },
      { key: 'tiler', label: 'Tiler' },
      { key: 'roofer', label: 'Roofer' },
    ],
    professional_services: [
      { key: 'architect', label: 'Architect' },
      { key: 'interior_designer', label: 'Interior Designer' },
      { key: 'structural_engineer', label: 'Structural Engineer' },
      { key: 'project_manager', label: 'Project Manager' },
    ],
    specialist_contractors: [
      { key: 'pool_contractor', label: 'Pool Builder' },
      { key: 'solar_installer', label: 'Solar Installer' },
      { key: 'waterproofing', label: 'Waterproofing' },
      { key: 'landscaping_contractor', label: 'Landscaping' },
    ],
    suppliers_materials: [
      { key: 'building_materials_store', label: 'Building Materials' },
      { key: 'timber_workshop', label: 'Timber Workshop' },
    ],
  };

  el.innerHTML = `
    <div class="page-header">
      <div class="container">
        <h1 class="page-title">Submit Your Business</h1>
        <p class="page-desc">Add your company to the Build in Lombok directory. Submissions are reviewed before going live.</p>
      </div>
    </div>
    <div class="section">
      <div class="container container--narrow">
        <div class="auth-error" id="submit-error"></div>
        <div class="auth-success" id="submit-success"></div>
        <form class="submit-form" id="submit-form">
          <div class="auth-field">
            <label>Business Name *</label>
            <input type="text" name="business_name" required>
          </div>
          <div class="form-row">
            <div class="auth-field">
              <label>Group *</label>
              <select name="group_key" id="sl-group" required>
                <option value="">Select group...</option>
                <option value="builders_trades">Builders & Trades</option>
                <option value="professional_services">Professional Services</option>
                <option value="specialist_contractors">Specialist Contractors</option>
                <option value="suppliers_materials">Suppliers & Materials</option>
              </select>
            </div>
            <div class="auth-field">
              <label>Area *</label>
              <select name="area_key" required>
                <option value="">Select area...</option>
                <option value="kuta">Kuta / Mandalika</option>
                <option value="selong_belanak">Selong Belanak</option>
                <option value="senggigi">Senggigi</option>
                <option value="ekas">Ekas Bay</option>
                <option value="mataram">Mataram</option>
                <option value="other_lombok">Other Lombok</option>
              </select>
            </div>
          </div>
          <div class="auth-field">
            <label>Specialties * (hold Ctrl/Cmd for multiple)</label>
            <select name="category_keys" id="sl-cats" multiple required style="min-height:120px"></select>
          </div>
          <div class="auth-field">
            <label>Short Description *</label>
            <textarea name="short_description" required maxlength="500" placeholder="Briefly describe what your business does..."></textarea>
          </div>
          <div class="form-row">
            <div class="auth-field">
              <label>Phone</label>
              <input type="text" name="phone" placeholder="+62...">
            </div>
            <div class="auth-field">
              <label>WhatsApp Number</label>
              <input type="text" name="whatsapp_number" placeholder="628...">
            </div>
          </div>
          <div class="form-row">
            <div class="auth-field">
              <label>Website</label>
              <input type="url" name="website_url" placeholder="https://...">
            </div>
            <div class="auth-field">
              <label>Google Maps Link</label>
              <input type="url" name="google_maps_url" placeholder="https://maps.google.com/...">
            </div>
          </div>
          <div class="auth-field">
            <label>Address</label>
            <input type="text" name="address" placeholder="Street address...">
          </div>
          <div class="auth-field">
            <label>Languages</label>
            <div style="display:flex;gap:16px;padding:6px 0">
              <label style="font-weight:400;display:flex;align-items:center;gap:4px;cursor:pointer"><input type="checkbox" name="lang_bahasa" value="Bahasa" checked> Bahasa</label>
              <label style="font-weight:400;display:flex;align-items:center;gap:4px;cursor:pointer"><input type="checkbox" name="lang_english" value="English"> English</label>
            </div>
            <input type="hidden" name="languages" value="Bahasa">
          </div>
          <button type="submit" class="auth-submit" style="align-self:flex-start">Submit Listing for Review</button>
        </form>
      </div>
    </div>
  `;

  // Cascading category dropdown
  const groupSel = el.querySelector('#sl-group');
  const catSel = el.querySelector('#sl-cats');
  function updateCats() {
    const group = groupSel.value;
    catSel.innerHTML = '';
    const cats = group && categoryTree[group] ? categoryTree[group] : Object.values(categoryTree).flat();
    cats.forEach(c => {
      const opt = document.createElement('option');
      opt.value = c.key; opt.textContent = c.label;
      catSel.appendChild(opt);
    });
  }
  groupSel.addEventListener('change', updateCats);
  updateCats();

  // Submit
  el.querySelector('#submit-form').addEventListener('submit', async (e) => {
    e.preventDefault();
    const form = e.target;
    const btn = form.querySelector('.auth-submit');
    const errEl = document.getElementById('submit-error');
    const sucEl = document.getElementById('submit-success');
    btn.disabled = true; btn.textContent = 'Submitting...';
    errEl.classList.remove('visible'); sucEl.classList.remove('visible');

    const fd = new FormData(form);
    const data = {};
    fd.forEach((v, k) => {
      if (k === 'category_keys') {
        if (!data[k]) data[k] = [];
        data[k].push(v);
      } else {
        data[k] = v;
      }
    });
    // Combine language checkboxes
    const langArr = [];
    if (form.querySelector('[name=lang_bahasa]') && form.querySelector('[name=lang_bahasa]').checked) langArr.push('Bahasa');
    if (form.querySelector('[name=lang_english]') && form.querySelector('[name=lang_english]').checked) langArr.push('English');
    data.languages = langArr.join(', ') || 'Bahasa';
    // Get all selected options for multi-select
    const selectedCats = [...catSel.selectedOptions].map(o => o.value);
    data.category_keys = selectedCats;

    try {
      const res = await UserAuth.apiCall('submit_listing', data);
      sucEl.textContent = res.message;
      sucEl.classList.add('visible');
      form.style.display = 'none';
    } catch(err) {
      errEl.textContent = err.message;
      errEl.classList.add('visible');
      btn.disabled = false; btn.textContent = 'Submit Listing for Review';
    }
  });
}

// =====================================================
// RENDER: CLAIM LISTING MODAL
// =====================================================

function showClaimModal(providerId, providerName) {
  if (!UserAuth.user) { showAuthModal('login'); return; }

  const overlay = document.createElement('div');
  overlay.className = 'auth-overlay';
  overlay.innerHTML = `
    <div class="auth-modal">
      <button class="auth-modal-close" onclick="this.closest('.auth-overlay').classList.remove('visible');setTimeout(()=>this.closest('.auth-overlay').remove(),200)">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
      </button>
      <h2>Claim This Listing</h2>
      <p class="auth-subtitle">Prove you own or manage <strong>${providerName}</strong> to take control of this listing.</p>
      <div class="auth-error" id="claim-error"></div>
      <div class="auth-success" id="claim-success"></div>
      <form class="auth-form" id="claim-form">
        <div class="auth-field">
          <label>Your Role at This Business *</label>
          <input type="text" id="claim-role" required placeholder="e.g. Owner, Manager, Director">
        </div>
        <div class="auth-field">
          <label>How Can You Prove Ownership? *</label>
          <textarea id="claim-proof" required style="min-height:80px;resize:vertical;padding:var(--space-3) var(--space-4);border:1px solid var(--color-border);border-radius:var(--radius-md);font-size:var(--text-sm);font-family:var(--font-body)" placeholder="e.g. I can provide business registration docs, Google My Business access, etc."></textarea>
        </div>
        <div class="auth-field">
          <label>Contact Phone (optional)</label>
          <input type="text" id="claim-phone" placeholder="+62...">
        </div>
        <button type="submit" class="auth-submit">Submit Claim</button>
      </form>
    </div>
  `;

  overlay.querySelector('#claim-form').addEventListener('submit', async (e) => {
    e.preventDefault();
    const btn = overlay.querySelector('.auth-submit');
    const errEl = overlay.querySelector('#claim-error');
    const sucEl = overlay.querySelector('#claim-success');
    btn.disabled = true; btn.textContent = 'Submitting...';
    errEl.classList.remove('visible');
    try {
      const res = await UserAuth.apiCall('claim_listing', {
        provider_id: providerId,
        business_role: overlay.querySelector('#claim-role').value,
        proof_description: overlay.querySelector('#claim-proof').value,
        contact_phone: overlay.querySelector('#claim-phone').value,
      });
      sucEl.textContent = res.message;
      sucEl.classList.add('visible');
      overlay.querySelector('#claim-form').style.display = 'none';
    } catch(err) {
      errEl.textContent = err.message;
      errEl.classList.add('visible');
      btn.disabled = false; btn.textContent = 'Submit Claim';
    }
  });

  document.body.appendChild(overlay);
  requestAnimationFrame(() => overlay.classList.add('visible'));
  overlay.addEventListener('click', (e) => {
    if (e.target === overlay) { overlay.classList.remove('visible'); setTimeout(() => overlay.remove(), 200); }
  });
}


// =====================================================
// RENDER: VERIFY RESULT / RESET PASSWORD PAGES
// =====================================================

function renderVerifyResult(el, params) {
  const status = params.status || 'unknown';
  const messages = {
    success: { title: 'Email Verified', desc: 'Your email has been verified. You can now sign in.', ok: true },
    already: { title: 'Already Verified', desc: 'This email was already verified. You can sign in.', ok: true },
    expired: { title: 'Link Expired', desc: 'The verification link has expired. Please register again or contact support.', ok: false },
    invalid: { title: 'Invalid Link', desc: 'This verification link is invalid. Please check your email or register again.', ok: false },
  };
  const m = messages[status] || { title: 'Verification', desc: 'Unknown status.', ok: false };
  el.innerHTML = `
    <div class="section">
      <div class="container">
        <div class="empty-state">
          <div class="empty-state-icon" style="color:${m.ok ? '#16a34a' : '#dc2626'}">
            ${m.ok ? '<svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>' : '<svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>'}
          </div>
          <h2 class="empty-state-title">${m.title}</h2>
          <p class="empty-state-desc">${m.desc}</p>
          ${m.ok ? '<button onclick="showAuthModal(\'login\')" class="btn btn--primary">Sign In</button>' : '<button onclick="navigate(\'home\')" class="btn btn--primary">Back to Home</button>'}
        </div>
      </div>
    </div>
  `;
}

// =====================================================
// RENDER: SEARCH RESULTS
// =====================================================

async function renderSearch(el, params = {}) {
  await FilterData.load();
  const initialQuery = params.q ? decodeURIComponent(params.q) : '';
  let debounceTimer = null;
  let allResults = [];           // raw API results, updated on each search
  let activeType     = params.type || '';   // 'provider' | 'developer' | 'project' | 'listing' | 'agent' | 'guide' | ''
  let activeArea     = '';       // selected area_key / region:key filter

  // Areas only matter for types that have area data. Hide the area
  // dropdown when the active chip is Guides (no area), Projects, or All.
  function typeSupportsArea(type) {
    return type === 'listing' || type === 'provider' || type === 'agent';
  }

  function renderGuideCard(item, index) {
    var nav = 'guide/' + item.slug;
    var meta = [];
    if (item.category)  meta.push(escHtml(item.category));
    if (item.read_time) meta.push(item.read_time + ' min read');
    return '<article class="card card-animate search-card-guide" style="animation-delay:' + ((index || 0) * 50) + 'ms">'
      + '<div class="card-header-info" style="margin-bottom:var(--space-2);">'
      + '<span class="card-category-label">' + escHtml(t('palette.type.guide', 'Guide')) + '</span>'
      + '</div>'
      + '<h3 class="card-name"><a href="#' + nav + '" onclick="navigate(\'' + nav + '\');return false;">' + escHtml(item.name) + '</a></h3>'
      + (item.excerpt ? '<p class="card-desc">' + escHtml(item.excerpt) + '</p>' : '')
      + (meta.length ? '<div class="card-meta-line"><span class="card-meta-item">' + meta.join(' · ') + '</span></div>' : '')
      + '<div class="card-footer"><button class="card-view-btn" onclick="navigate(\'' + nav + '\')">'
      + escHtml(t('search.read_guide', 'Read guide')) + ' ' + iconArrowRight()
      + '</button></div>'
      + '</article>';
  }

  function renderSearchCard(item, index) {
    if (item.type === 'guide') return renderGuideCard(item, index);
    if (item.type === 'listing') {
      // Normalise: search returns title as `name`; renderListingCard expects `title`
      if (!item.title && item.name) item.title = item.name;
      return renderListingCard(item, index);
    }

    var typeMap = {
      provider:  { label: 'Provider',  nav: 'provider/'  + item.slug },
      developer: { label: 'Developer', nav: 'developer/' + item.slug },
      project:   { label: 'Project',   nav: 'project/'   + item.slug },
      listing:   { label: 'Property',  nav: 'listing/'   + item.slug },
      agent:     { label: 'Agent',     nav: 'agent/'     + item.slug },
    };
    var cfg = typeMap[item.type] || { label: item.type, nav: item.type + '/' + item.slug };

    // Avatar / photo
    var thumbImg = item.profile_photo_url || item.logo_url || '';
    var avatarHtml = thumbImg
      ? '<img src="' + thumbImg + '" alt="' + escHtml(item.name) + '" class="card-avatar' + (item.logo_url && !item.profile_photo_url ? ' card-avatar--logo' : '') + '" loading="lazy" onerror="this.style.display=\'none\'">'
      : '<div class="card-avatar card-avatar--placeholder"><span>' + (item.name || 'B').charAt(0).toUpperCase() + '</span></div>';

    // Category label: use actual categories if available, else type label
    var categoryLabel = cfg.label;
    if (item.categories && item.categories.length > 0) {
      categoryLabel = item.categories.map(function(c) { return formatCategoryLabel(c.key || c); }).join(' · ');
    }

    // Badges
    var trustedBadge = item.is_trusted ? '<span class="card-badge card-badge--trusted">\u2713 Trusted</span>' : '';
    var badge = item.badge ? '<span class="card-badge">' + escHtml(item.badge) + '</span>' : '';

    // Rating
    var ratingHtml = item.google_rating
      ? '<span class="card-rating-inline"><span class="card-rating-star">\u2605</span> '
        + parseFloat(item.google_rating).toFixed(1)
        + (item.google_review_count ? ' <span class="card-rating-count">(' + item.google_review_count + ')</span>' : '')
        + '</span>'
      : '';

    // Meta line (area + language)
    var areaLabel = item.area_label || (item.area ? formatAreaLabel(item.area) : '');
    var langParts = (item.languages || '').split(/[,+]+/).map(function(s) { return s.trim(); }).filter(Boolean);
    var langShort = langParts.length ? langParts.join(' \u00b7 ') : '';
    var metaHtml = (areaLabel || langShort)
      ? '<div class="card-meta-line">'
        + (areaLabel ? '<span class="card-meta-item">' + iconMapPin() + ' ' + areaLabel + '</span>' : '')
        + (areaLabel && langShort ? '<span class="card-meta-sep"></span>' : '')
        + (langShort ? '<span class="card-meta-item">' + langShort + '</span>' : '')
        + '</div>'
      : '';

    // Tags
    var tags = item.tags || [];
    var tagsHtml = tags.length
      ? '<div class="card-tags-line">'
        + tags.slice(0, 3).map(function(t) { return '<span class="card-tag">' + escHtml(t) + '</span>'; }).join('<span class="card-tag-dot">\u00b7</span>')
        + '</div>'
      : '';

    // WhatsApp button
    var rawWa = item.whatsapp_number || item.phone || '';
    var waNum = formatWhatsAppNumber(rawWa);
    var waHref = waNum ? 'https://wa.me/' + waNum.replace(/[^0-9]/g, '') : '';
    var waBtn = waHref
      ? '<a href="' + waHref + '" target="_blank" rel="noopener noreferrer" class="card-wa-btn" aria-label="WhatsApp ' + escHtml(item.name) + '">' + iconWhatsApp() + '</a>'
      : '';

    // Fav button
    var favHtml = item.id ? renderFavBtn(item.type, item.id) : '';

    return '<article class="card card-animate" style="animation-delay:' + ((index || 0) * 50) + 'ms">'
      + '<div class="card-visual-header">'
      + avatarHtml
      + '<div class="card-header-info">'
      + '<span class="card-category-label">' + categoryLabel + '</span>'
      + '<div class="card-header-badges">' + trustedBadge + badge + '</div>'
      + '</div>'
      + ratingHtml
      + '</div>'
      + '<h3 class="card-name"><a href="#' + cfg.nav + '" onclick="navigate(\'' + cfg.nav + '\');return false;">' + escHtml(item.name) + '</a></h3>'
      + '<p class="card-desc">' + (item.excerpt ? escHtml(item.excerpt) : '') + '</p>'
      + metaHtml
      + tagsHtml
      + '<div class="card-footer">'
      + '<button class="card-view-btn" onclick="navigate(\'' + cfg.nav + '\')">'
      + 'View details ' + iconArrowRight()
      + '</button>'
      + '<div class="card-footer-right">' + favHtml + waBtn + '</div>'
      + '</div>'
      + '</article>';
  }

  // Section configuration matches the command palette (same order).
  var SEARCH_TYPES = [
    { key: '',          label: 'All' },
    { key: 'listing',   label: 'Properties' },
    { key: 'provider',  label: 'Vendors' },
    { key: 'agent',     label: 'Agents' },
    { key: 'developer', label: 'Developers' },
    { key: 'project',   label: 'Projects' },
    { key: 'guide',     label: 'Guides' },
  ];

  function sectionLabelFor(typeKey) {
    var labels = {
      listing:   'Properties',
      provider:  'Vendors',
      agent:     'Agents',
      developer: 'Developers',
      project:   'Projects',
      guide:     'Guides',
    };
    return t('palette.section.' + typeKey, labels[typeKey] || typeKey);
  }

  // ── Client-side filter + render ───────────────────────────────────────
  function applyAndRender(currentQuery) {
    var resultsEl = el.querySelector('#search-results');
    var countEl   = el.querySelector('#search-count');
    if (!resultsEl) return;

    // Helper: does an item's area match the active area filter?
    function areaMatches(item) {
      if (!activeArea) return true;
      var itemArea = item.area || '';
      if (activeArea.startsWith('region:')) {
        var regionKey = activeArea.replace('region:', '');
        // look up area's region_key in FilterData
        var areaObj = FilterData.areas.find(function(a) { return a.key === itemArea; });
        return areaObj ? areaObj.region_key === regionKey : false;
      }
      return itemArea === activeArea;
    }

    var filtered = allResults.filter(function(r) {
      if (activeType && r.type !== activeType) return false;
      if (!typeSupportsArea(r.type)) return true;  // guides/projects/developers ignore area
      return areaMatches(r);
    });

    var bucketsByType = { listing: [], provider: [], agent: [], developer: [], project: [], guide: [] };
    filtered.forEach(function(r) {
      if (bucketsByType[r.type]) bucketsByType[r.type].push(r);
    });
    var total = filtered.length;

    // Update chip counts
    var chipsEl = el.querySelector('#search-type-chips');
    if (chipsEl) {
      SEARCH_TYPES.forEach(function(typ) {
        var chip = chipsEl.querySelector('[data-chip-type="' + typ.key + '"]');
        if (!chip) return;
        var n = (typ.key === '')
          ? allResults.length
          : (allResults.filter(function(r) { return r.type === typ.key; }).length);
        var countSpan = chip.querySelector('.search-chip-count');
        if (countSpan) countSpan.textContent = n;
        chip.classList.toggle('is-active', activeType === typ.key);
      });
    }

    if (countEl) {
      countEl.innerHTML = total > 0
        ? '<strong>' + total + '</strong> result' + (total !== 1 ? 's' : '') + ' for \u201c' + escHtml(currentQuery) + '\u201d'
        : 'No results for \u201c' + escHtml(currentQuery) + '\u201d';
    }

    // Area dropdown visibility: only for area-aware types
    var areaWrap = el.querySelector('#sf-area-wrap');
    if (areaWrap) areaWrap.style.display = (activeType && typeSupportsArea(activeType)) ? '' : 'none';

    if (total === 0) {
      resultsEl.innerHTML = '<div class="empty-state">'
        + '<div class="empty-state-icon">' + iconSearch() + '</div>'
        + '<h3 class="empty-state-title">' + escHtml(t('search.no_results_title', 'No results found')) + '</h3>'
        + '<p class="empty-state-desc">' + escHtml(t('search.no_results_desc', 'Try different keywords or switch type.')) + '</p>'
        + '<a href="#directory" class="btn btn--primary btn--sm" onclick="navigate(\'directory\');return false;">' + escHtml(t('search.browse_directory', 'Browse Directory')) + '</a>'
        + '</div>';
      return;
    }

    function renderGroup(typeKey) {
      var items = bucketsByType[typeKey] || [];
      if (!items.length) return '';
      var label = sectionLabelFor(typeKey);
      return '<div class="search-group">'
        + '<h2 class="search-group-title">' + escHtml(label) + ' <span class="search-group-count">' + items.length + '</span></h2>'
        + '<div class="card-grid">'
        + items.map(renderSearchCard).join('')
        + '</div>'
        + '</div>';
    }

    resultsEl.innerHTML = ''
      + renderGroup('listing')
      + renderGroup('provider')
      + renderGroup('agent')
      + renderGroup('developer')
      + renderGroup('project')
      + renderGroup('guide');
  }

  // ── Fetch from API, store raw results, then apply filters ─────────────
  async function doSearch(q) {
    var resultsEl = el.querySelector('#search-results');
    var countEl   = el.querySelector('#search-count');
    if (!resultsEl) return;

    if (!q || q.length < 2) {
      allResults = [];
      resultsEl.innerHTML = '<div class="search-hint"><p>'
        + escHtml(t('search.type_hint', 'Type at least 2 characters to search across properties, builders, agents, developers, projects, and guides.'))
        + '</p></div>';
      if (countEl) countEl.innerHTML = '';
      return;
    }

    resultsEl.innerHTML = '<div class="gq-loading"><div class="page-loading-spinner" style="width:22px;height:22px;border-width:3px;margin:0;"></div><span>'
      + escHtml(t('search.searching', 'Searching')) + '\u2026</span></div>';
    if (countEl) countEl.innerHTML = '';

    try {
      // Full results (no palette cap) for the /search page
      var res = await fetch('/api/search?q=' + encodeURIComponent(q));
      var data = await res.json();
      allResults = (data && data.data) || [];
      applyAndRender(q);
    } catch(e) {
      console.error('Search error:', e);
      resultsEl.innerHTML = '<div class="empty-state"><p>' + escHtml(t('search.error', 'Search failed. Please try again.')) + '</p></div>';
    }
  }

  // ── Build type-chip strip ─────────────────────────────────────────────
  var chipHtml = SEARCH_TYPES.map(function(typ) {
    var lbl = (typ.key === '') ? t('search.chip_all', 'All') : sectionLabelFor(typ.key);
    return '<button type="button" class="search-chip' + (activeType === typ.key ? ' is-active' : '') + '" data-chip-type="' + escHtml(typ.key) + '">'
      + '<span class="search-chip-label">' + escHtml(lbl) + '</span>'
      + '<span class="search-chip-count">0</span>'
      + '</button>';
  }).join('');

  el.innerHTML = `
    <div class="section section--search-page">
      <div class="container">
        <div class="search-page-bar">
          <div class="search-page-input-wrap">
            <svg class="search-page-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
            <input type="search" id="search-page-input" class="search-page-input"
                   placeholder="${escHtml(t('palette.placeholder', 'What are you looking for?'))}"
                   value="${escHtml(initialQuery)}" autocomplete="off" spellcheck="false">
          </div>
        </div>

        <div class="search-chips-row" id="search-type-chips" role="tablist" aria-label="${escHtml(t('search.filter_by_type', 'Filter by type'))}" style="margin-top:var(--space-5);">
          ${chipHtml}
        </div>

        <div class="search-secondary-filters" style="margin-top:var(--space-4);">
          <div class="dir-filter-pill" id="sf-area-wrap" style="display:none;">
            <label class="dir-filter-pill-label" for="sf-area">${t('filter.where', 'Where in Lombok?')}</label>
            <select id="sf-area" class="dir-filter-pill-select">
              <option value="">${escHtml(t('filter.all_areas', 'All Areas'))}</option>
              ${buildAreaOptions('')}
            </select>
          </div>
        </div>

        <p class="results-count" id="search-count" style="margin-top:var(--space-5);margin-bottom:var(--space-5);min-height:1.4em;"></p>
        <div id="search-results"></div>
      </div>
    </div>
  `;

  // ── Wire up input ─────────────────────────────────────────────────────
  var input = el.querySelector('#search-page-input');
  if (input) {
    input.addEventListener('input', function() {
      var q = this.value.trim();
      clearTimeout(debounceTimer);
      debounceTimer = setTimeout(function() { doSearch(q); }, 300);
    });
    setTimeout(function() { if (input) input.focus(); }, 80);
  }

  // ── Wire up type chips ────────────────────────────────────────────────
  el.querySelectorAll('.search-chip').forEach(function(chip) {
    chip.addEventListener('click', function() {
      activeType = chip.getAttribute('data-chip-type') || '';
      activeArea = '';  // reset area when type changes
      var areaSelEl = el.querySelector('#sf-area');
      if (areaSelEl) areaSelEl.value = '';
      var q = (input ? input.value.trim() : initialQuery);
      applyAndRender(q);
    });
  });

  // ── Wire up area dropdown ─────────────────────────────────────────────
  var areaSel = el.querySelector('#sf-area');
  if (areaSel) {
    areaSel.addEventListener('change', function() {
      activeArea = this.value;
      var q = (input ? input.value.trim() : initialQuery);
      if (allResults.length) applyAndRender(q);
    });
  }

  doSearch(initialQuery);
}

// =====================================================
// QUOTE ENGINE (automated) — API + dashboard + detail
// =====================================================

async function qApi(action, opts) {
  opts = opts || {};
  var url = '/api/quotes.php?action=' + action;
  if (opts.params) { for (var k in opts.params) { url += '&' + encodeURIComponent(k) + '=' + encodeURIComponent(opts.params[k]); } }
  var init = { credentials: 'include' };
  if (opts.body) { init.method = 'POST'; init.headers = { 'Content-Type': 'application/json' }; init.body = JSON.stringify(opts.body); }
  var res = await fetch(url, init);
  var json = {};
  try { json = await res.json(); } catch (e) {}
  if (!res.ok) {
    var err = new Error(json.error || ('HTTP ' + res.status));
    err.status = res.status; err.data = json;
    err.upgrade = (json.error === 'upgrade_required');
    err.required_tier = json.required_tier;
    throw err;
  }
  return json;
}

// Benefit-selling upgrade prompt (freemium rule #4 — never a raw error).
function showQuoteUpgrade(tier) {
  tier = tier || 'basic';
  var tierName = tier.charAt(0).toUpperCase() + tier.slice(1);
  var old = document.getElementById('q-upsell'); if (old) old.remove();
  var ov = document.createElement('div');
  ov.id = 'q-upsell';
  ov.className = 'q-upsell-overlay';
  ov.innerHTML =
    '<div class="q-upsell-card">'
    + '<button class="q-upsell-close" aria-label="Close" onclick="document.getElementById(\'q-upsell\').remove()">&times;</button>'
    + '<h3 class="q-upsell-title">Let us chase the quotes for you</h3>'
    + '<p class="q-upsell-desc">Auto-Quote messages every supplier you pick, translates their replies in real time, '
    + 'and lays every price out side-by-side so you can choose in seconds &mdash; no more juggling WhatsApp chats.</p>'
    + '<ul class="q-upsell-list">'
    + '<li>Automatic WhatsApp outreach to all your picks</li>'
    + '<li>Messy Bahasa replies translated &amp; structured for you</li>'
    + '<li>A live price-comparison dashboard</li>'
    + '</ul>'
    + '<p class="q-upsell-note">Included with <strong>' + tierName + '</strong>.</p>'
    + '<div class="q-upsell-actions">'
    + '<button class="btn btn--primary" onclick="navigate(\'account?tab=subscription\');document.getElementById(\'q-upsell\').remove()">Upgrade to ' + tierName + '</button>'
    + '<button class="btn btn--ghost" onclick="document.getElementById(\'q-upsell\').remove()">Keep sending manually</button>'
    + '</div>'
    + '<p class="q-upsell-foot">Or keep using the free manual WhatsApp buttons &mdash; they always work.</p>'
    + '</div>';
  document.body.appendChild(ov);
  ov.addEventListener('click', function (e) { if (e.target === ov) ov.remove(); });
}

async function renderQuotesDashboard(el) {
  el.innerHTML = '<div class="section"><div class="container"><div class="page-loading"><div class="page-loading-spinner"></div></div></div></div>';
  var res;
  try { res = await qApi('my_requests'); }
  catch (e) {
    if (e.status === 401) showAuthModal('login');
    el.innerHTML = '<div class="section"><div class="container"><div class="empty-state">'
      + '<h3 class="empty-state-title">Sign in to see your quote requests</h3>'
      + '<button class="btn btn--primary" onclick="navigate(\'get-quotes\')">Start a quote request</button></div></div></div>';
    return;
  }
  var rows = res.data || [];
  var list;
  if (!rows.length) {
    list = '<div class="empty-state"><h3 class="empty-state-title">No quote requests yet</h3>'
      + '<p class="empty-state-desc">Pick some suppliers and let us gather the prices for you.</p>'
      + '<button class="btn btn--primary" onclick="navigate(\'get-quotes\')">New quote request</button></div>';
  } else {
    list = '<div class="qd-list">' + rows.map(function (r) {
      var statusCls = r.status === 'open' ? 'qd-pill--open' : 'qd-pill--closed';
      return '<a class="qd-card" href="#quote/' + r.id + '" onclick="navigate(\'quote/' + r.id + '\');return false;">'
        + '<div class="qd-card-main">'
        + '<div class="qd-card-title">' + escHtml(r.first_item || 'Quote request') + (r.item_count > 1 ? ' <span class="qd-muted">+' + (r.item_count - 1) + ' more</span>' : '') + '</div>'
        + '<div class="qd-card-meta">' + r.vendor_count + ' vendor' + (r.vendor_count != 1 ? 's' : '') + ' &middot; ' + r.replied_count + ' replied'
        + (r.attention_count > 0 ? ' &middot; <span class="qd-attn">' + r.attention_count + ' need attention</span>' : '') + '</div>'
        + '</div>'
        + '<span class="qd-pill ' + statusCls + '">' + escHtml(r.status) + '</span></a>';
    }).join('') + '</div>';
  }
  el.innerHTML = '<div class="dir-hero"><div class="container"><h1 class="dir-hero-title">My Quote Requests</h1>'
    + '<p class="dir-hero-desc">Track the prices coming back from your suppliers.</p></div></div>'
    + '<div class="section"><div class="container">'
    + '<div style="margin-bottom:var(--space-5)"><button class="btn btn--primary" onclick="navigate(\'get-quotes\')">+ New quote request</button></div>'
    + list + '</div></div>';
}

var _quotePollToken = 0;
async function renderQuoteDetail(el, id) {
  id = parseInt(id, 10);
  var token = ++_quotePollToken;
  el.innerHTML = '<div class="section"><div class="container"><div class="page-loading"><div class="page-loading-spinner"></div></div></div></div>';

  function priceCell(cell) {
    if (!cell) return '<span class="qd-muted">&mdash;</span>';
    var cur = (cell.currency && cell.currency !== 'IDR') ? (escHtml(cell.currency) + ' ' + escHtml(cell.unit_price)) : formatIDR(cell.unit_price);
    var u = cell.unit ? ' <span class="qd-unit">/ ' + escHtml(cell.unit) + '</span>' : '';
    var d = cell.price_includes_delivery == 1 ? ' <span class="qd-incl">incl. delivery</span>' : '';
    return '<span class="qd-price">' + cur + '</span>' + u + d;
  }
  function stateBadge(c) {
    var label = c.state, cls = 'qd-st';
    if (c.admin_intervention == 1 || c.state === 'needs_admin') { label = 'needs attention'; cls += ' qd-st--attn'; }
    else if (c.state === 'info_received') { label = 'replied'; cls += ' qd-st--ok'; }
    else if (c.state === 'awaiting_reply') { label = 'awaiting reply'; cls += ' qd-st--wait'; }
    else if (c.state === 'queued') { label = 'sending…'; cls += ' qd-st--wait'; }
    else { cls += ' qd-st--done'; }
    return '<span class="' + cls + '">' + escHtml(label) + '</span>';
  }
  function paint(res) {
    var items = res.items || [], chats = res.chats || [], matrix = res.matrix || {};
    var head = '<th class="qd-corner">Item</th>' + chats.map(function (c) {
      return '<th><a href="#provider/' + escHtml(c.provider_slug) + '" onclick="navigate(\'provider/' + escHtml(c.provider_slug) + '\');return false;">' + escHtml(c.provider_name) + '</a>'
        + '<div class="qd-th-meta">' + stateBadge(c) + (c.stock_status && c.stock_status !== 'unknown' ? ' &middot; ' + escHtml(c.stock_status.replace('_', ' ')) : '') + '</div></th>';
    }).join('');
    var body = items.map(function (it) {
      var cells = chats.map(function (c) {
        var cell = (matrix[it.id] && matrix[it.id][c.id]) ? matrix[it.id][c.id] : null;
        return '<td>' + priceCell(cell) + '</td>';
      }).join('');
      var label = escHtml(it.material) + (it.quantity ? ' <span class="qd-muted">(' + escHtml(it.quantity) + ')</span>' : '');
      return '<tr><th class="qd-row-h">' + label + '</th>' + cells + '</tr>';
    }).join('');
    var matrixHtml = chats.length
      ? '<div class="qd-matrix-wrap"><table class="qd-matrix"><thead><tr>' + head + '</tr></thead><tbody>' + body + '</tbody></table></div>'
      : '<p class="qd-muted">No vendors on this request.</p>';

    var threads = chats.map(function (c) {
      var msgs = (c.messages || []).map(function (m) {
        var who = m.direction === 'inbound' ? 'in' : 'out';
        var sub = (m.direction === 'inbound' && m.body_translated_en) ? '<div class="qd-msg-tr">' + escHtml(m.body_translated_en) + '</div>' : '';
        var tag = m.sender_kind === 'agent_auto' ? '<span class="qd-auto">auto</span>' : '';
        return '<div class="qd-msg qd-msg--' + who + '">' + tag + '<div class="qd-msg-raw">' + escHtml(m.body_raw || '') + '</div>' + sub + '</div>';
      }).join('');
      return '<details class="qd-thread"><summary>' + escHtml(c.provider_name) + ' ' + stateBadge(c) + '</summary>'
        + '<div class="qd-msgs">' + (msgs || '<p class="qd-muted">No messages yet.</p>') + '</div></details>';
    }).join('');

    var statusLine = res.request.status === 'open'
      ? '<span class="qd-live">&#9679; live</span> updating automatically'
      : 'Request ' + escHtml(res.request.status);

    el.innerHTML = '<div class="dir-hero"><div class="container">'
      + '<a class="qd-back" href="#quotes" onclick="navigate(\'quotes\');return false;">&larr; My requests</a>'
      + '<h1 class="dir-hero-title">Quote comparison</h1>'
      + '<p class="dir-hero-desc">' + statusLine + '</p></div></div>'
      + '<div class="section"><div class="container">'
      + matrixHtml
      + '<h2 class="qd-h2">Conversations</h2>' + threads
      + (res.request.status === 'open' ? '<div style="margin-top:var(--space-6)"><button class="btn btn--ghost btn--sm" onclick="qCloseRequest(' + id + ')">Close this request</button></div>' : '')
      + '</div></div>';
  }
  async function load(firstRender) {
    var res;
    try { res = await qApi('request_detail', { params: { id: id } }); }
    catch (e) {
      if (firstRender) {
        if (e.status === 401) showAuthModal('login');
        el.innerHTML = '<div class="section"><div class="container"><div class="empty-state">'
          + '<h3 class="empty-state-title">Couldn\'t load this request</h3>'
          + '<button class="btn btn--secondary" onclick="navigate(\'quotes\')">Back to my requests</button></div></div></div>';
      }
      return;
    }
    if (token !== _quotePollToken || currentRoute.page !== 'quote') return; // navigated away
    paint(res);
    if (res.request.status === 'open') {
      setTimeout(function () { if (token === _quotePollToken && currentRoute.page === 'quote') load(false); }, 7000);
    }
  }
  window.qCloseRequest = async function (rid) {
    if (!confirm('Close this request? Vendors will no longer be tracked.')) return;
    try { await qApi('close_request', { body: { id: rid } }); load(false); } catch (e) { alert(e.message); }
  };
  load(true);
}

// =====================================================
// RENDER: GET QUOTES
// =====================================================

async function renderGetQuotes(el, params = {}) {
  await FilterData.load();

  let quoteItems = [{ id: 1, material: '', quantity: '', info: '' }];
  let nextId = 2;
  const contactedStores = new Set(JSON.parse(localStorage.getItem('gq_contacted') || '[]'));
  const selectedAuto = new Set(); // provider ids ticked for Auto-Quote
  let useBahasa = false;
  let delivery = { enabled: false, location: '', mapsLink: '' };

  // Smart, varied placeholder examples (static — cycled by row index, not animated)
  const GQ_MATERIAL_EG = [
    'e.g. MU-445 Silatama Screed',
    'e.g. Mapei Mapeflex 45',
    'e.g. Dulux Weathershield, white',
    'e.g. Holcim Serba Guna cement',
    'e.g. Onduline corrugated roofing'
  ];
  const GQ_INFO_EG = [
    'e.g. White base, coarse grain, 10mm',
    'e.g. 25kg bags, grey',
    'e.g. Specific Dulux paint code',
    'e.g. Grade A, kiln-dried',
    'e.g. Delivered to site'
  ];

  const filters = {
    area: params.area || '',
    group: params.group || 'suppliers_materials',
    category: params.category || '',
    languages: params.languages || '',
    min_rating: params.min_rating || '',
    trusted: params.trusted || '',
    sort: params.sort || 'confidence',
  };

  function buildMessage() {
    const valid = quoteItems.filter(function(i) { return i.material.trim(); });
    if (!valid.length) return '';

    const lines = valid.map(function(item) {
      let line = '* ' + item.material.trim();
      if (item.quantity.trim()) line += '  --  Qty : ' + item.quantity.trim();
      if (item.info.trim()) line += '  --  ' + item.info.trim();
      return line;
    }).join('\n');

    var msg;
    if (useBahasa) {
      msg = 'Halo, mohon dapat memberikan penawaran harga untuk barang-barang berikut:\n\n' + lines;
      if (delivery.enabled && delivery.location.trim()) {
        msg += '\n\nMohon informasikan apakah barang-barang tersebut dapat dikirim ke "' + delivery.location.trim() + '"';
        if (delivery.mapsLink.trim()) msg += '\nLokasi Google Maps: ' + delivery.mapsLink.trim();
        msg += '\nJika iya, mohon informasikan biaya pengiriman (jika ada)';
      }
      msg += '\n\nTerima kasih.';
    } else {
      msg = 'Hello, please can you provide a quote for the following items:\n\n' + lines;
      if (delivery.enabled && delivery.location.trim()) {
        msg += '\n\nPlease advise if the items can be delivered to "' + delivery.location.trim() + '"';
        if (delivery.mapsLink.trim()) msg += '\nGoogle Maps location: ' + delivery.mapsLink.trim();
        msg += '\nIf yes, please advise on the delivery fee (if any)';
      }
      msg += '\n\nThank you.';
    }
    return msg;
  }

  function refreshGhost() {
    const ta = el.querySelector('#gq-message');
    const ghost = el.querySelector('#gq-preview-ghost');
    if (!ta || !ghost) return;
    ghost.style.display = ta.value.trim() ? 'none' : '';
  }

  function syncMessage() {
    const ta = el.querySelector('#gq-message');
    if (ta && !ta.dataset.manualEdit) ta.value = buildMessage();
    refreshGhost();
  }

  function syncDeliveryFields() {
    const fields = el.querySelector('#gq-delivery-fields');
    if (fields) fields.style.display = delivery.enabled ? '' : 'none';
  }

  function renderItems() {
    const container = el.querySelector('#gq-items');
    if (!container) return;
    container.innerHTML = quoteItems.map(function(item, idx) {
      return '<div class="gq-item-row" data-id="' + item.id + '">'
        + '<span class="gq-item-num">' + (idx + 1) + '</span>'
        + '<input type="text" class="gq-input gq-input--material" placeholder="' + escHtml(GQ_MATERIAL_EG[idx % GQ_MATERIAL_EG.length]) + '"'
        + ' value="' + escHtml(item.material) + '"'
        + ' oninput="gqUpdateItem(' + item.id + ', \'material\', this.value)">'
        + '<input type="text" class="gq-input gq-input--qty" placeholder="Qty"'
        + ' value="' + escHtml(item.quantity) + '"'
        + ' oninput="gqUpdateItem(' + item.id + ', \'quantity\', this.value)">'
        + '<input type="text" class="gq-input gq-input--info" placeholder="' + escHtml(GQ_INFO_EG[idx % GQ_INFO_EG.length]) + '"'
        + ' value="' + escHtml(item.info) + '"'
        + ' oninput="gqUpdateItem(' + item.id + ', \'info\', this.value)">'
        + (quoteItems.length > 1
          ? '<button class="gq-item-remove" onclick="gqRemoveItem(' + item.id + ')" aria-label="Remove item">'
            + '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>'
            + '</button>'
          : '<div class="gq-item-remove-placeholder"></div>'
        )
        + '</div>';
    }).join('');
  }

  function renderStores(stores) {
    // Only show stores that have a usable WhatsApp/phone number
    stores = stores.filter(function(b) {
      const raw = b.whatsapp_number || b.phone || '';
      return formatWhatsAppNumber(raw).replace(/[^0-9]/g, '').length >= 8;
    });

    const listEl = el.querySelector('#gq-store-list');
    const countEl = el.querySelector('#gq-results-count');
    if (!listEl) return;
    if (countEl) countEl.innerHTML = '<strong>' + stores.length + '</strong> supplier' + (stores.length !== 1 ? 's' : '') + ' found';
    if (stores.length === 0) {
      listEl.innerHTML = '<div class="empty-state"><div class="empty-state-icon">' + iconSearch() + '</div>'
        + '<h3 class="empty-state-title">No suppliers found</h3>'
        + '<p class="empty-state-desc">Try adjusting your filters.</p>'
        + '<button class="btn btn--secondary btn--sm" onclick="gqClearFilters()">Clear filters</button></div>';
      return;
    }
    listEl.innerHTML = stores.map(function(b) {
      const rawWa = b.whatsapp_number || b.phone || '';
      const waNum = formatWhatsAppNumber(rawWa).replace(/[^0-9]/g, '');
      const contacted = contactedStores.has(String(b.id));
      const ratingHtml = b.google_rating
        ? '<span class="gq-star">\u2605</span>'
          + '<span class="gq-rating-val">' + parseFloat(b.google_rating).toFixed(1) + '</span>'
          + '<span class="gq-review-count">(' + (b.google_review_count || 0) + ' reviews)</span>'
        : '<span class="gq-no-rating">No rating yet</span>';
      const catLabel = (b.categories && b.categories.length > 0)
        ? b.categories.map(function(c) { return formatCategoryLabel(c.key || c); }).join(' \u00b7 ')
        : formatCategoryLabel(b.category);
      const thumb = b.logo_url || b.profile_photo_url;
      const trustedBadge = b.is_trusted ? '<span class="card-badge card-badge--trusted">\u2713 Trusted</span>' : '';
      return '<div class="gq-store-row' + (contacted ? ' gq-store-row--contacted' : '') + '" data-id="' + b.id + '">'
        + '<div class="gq-store-avatar">'
        + (thumb
          ? '<img src="' + thumb + '" alt="' + escHtml(b.name) + '" loading="lazy" onerror="this.style.display=\'none\';this.nextElementSibling.style.display=\'flex\'">'
            + '<div class="gq-store-initials" style="display:none">' + (b.name || 'S').charAt(0).toUpperCase() + '</div>'
          : '<div class="gq-store-initials">' + (b.name || 'S').charAt(0).toUpperCase() + '</div>'
        )
        + '</div>'
        + '<div class="gq-store-info">'
        + '<div class="gq-store-name">' + trustedBadge
        + '<a href="#provider/' + b.slug + '" onclick="navigate(\'provider/' + b.slug + '\');return false;">' + escHtml(b.name) + '</a>'
        + '</div>'
        + '<div class="gq-store-meta">' + iconMapPin() + ' ' + formatAreaLabel(b.area) + (catLabel ? ' \u00b7 ' + catLabel : '') + '</div>'
        + '<div class="gq-store-rating">' + ratingHtml + '</div>'
        + '</div>'
        + '<div class="gq-store-actions">'
        + (contacted ? '<span class="gq-sent-badge">\u2713 Sent</span>' : '')
        + '<button class="gq-wa-btn' + (contacted ? ' gq-wa-btn--contacted' : '') + '"'
        + ' data-wa="' + waNum + '" data-store-id="' + b.id + '"'
        + ' onclick="gqOpenWhatsApp(this)"'
        + ' title="Open WhatsApp with your message ready to send" aria-label="WhatsApp ' + escHtml(b.name) + '">'
        + iconWhatsApp() + '<span class="gq-wa-btn-label">WhatsApp</span>'
        + '</button>'
        + '</div>'
        + '</div>';
    }).join('');
  }

  async function applyFiltersAndRender() {
    const listEl = el.querySelector('#gq-store-list');
    if (listEl) listEl.innerHTML = '<div class="gq-loading"><div class="page-loading-spinner" style="width:22px;height:22px;border-width:3px;margin:0;"></div><span>Loading suppliers\u2026</span></div>';

    const apiParams = { per_page: 100 };
    if (filters.area) {
      if (filters.area.startsWith('region:')) apiParams.region = filters.area.replace('region:', '');
      else apiParams.area = filters.area;
    }
    if (filters.group) apiParams.group = filters.group;
    if (filters.category) apiParams.category = filters.category;
    if (filters.trusted) apiParams.trusted = '1';
    if (filters.sort === 'rating') { apiParams.sort = 'google_rating'; apiParams.dir = 'DESC'; }
    else if (filters.sort === 'alpha') { apiParams.sort = 'name'; }
    else { apiParams.sort = 'google_rating'; apiParams.dir = 'DESC'; }

    try {
      const res = await DataLayer.getProviders(apiParams);
      let results = res.data;
      if (filters.languages === 'english') results = results.filter(function(b) { return (b.languages || '').toLowerCase().includes('english'); });
      if (filters.languages === 'bahasa') results = results.filter(function(b) { return (b.languages || '').toLowerCase().includes('bahasa'); });
      if (filters.min_rating) {
        const minR = parseFloat(filters.min_rating);
        results = results.filter(function(b) { return b.google_rating && b.google_rating >= minR; });
      }
      if (!filters.sort || filters.sort === 'confidence') {
        results.sort(function(a, b_) {
          if (b_.is_trusted && !a.is_trusted) return 1;
          if (a.is_trusted && !b_.is_trusted) return -1;
          if (b_.is_featured && !a.is_featured) return 1;
          if (a.is_featured && !b_.is_featured) return -1;
          return confidenceScore(b_.google_rating, b_.google_review_count) - confidenceScore(a.google_rating, a.google_review_count);
        });
      } else if (filters.sort === 'review_count') {
        results.sort(function(a, b_) { return (b_.google_review_count || 0) - (a.google_review_count || 0); });
      }
      renderStores(results);
    } catch(e) {
      const listEl2 = el.querySelector('#gq-store-list');
      if (listEl2) listEl2.innerHTML = '<div class="empty-state"><p>Unable to load suppliers. Please try again.</p></div>';
    }
  }

  el.innerHTML = `
    <div class="dir-hero" data-group="suppliers_materials">
      <div class="container">
        <h1 class="dir-hero-title">Get Quotes</h1>
        <p class="dir-hero-desc">Compose one materials list, then message Lombok suppliers directly on WhatsApp &mdash; and compare their replies.</p>
        <div class="gq-stepper" role="list" aria-label="How it works">
          <div class="gq-stepper-item" role="listitem">
            <span class="gq-stepper-num">01</span>
            <span class="gq-stepper-label">Compose</span>
            <span class="gq-stepper-text">List your materials, specs &amp; delivery.</span>
          </div>
          <span class="gq-stepper-divider" aria-hidden="true"></span>
          <div class="gq-stepper-item" role="listitem">
            <span class="gq-stepper-num">02</span>
            <span class="gq-stepper-label">Generate</span>
            <span class="gq-stepper-text">We format a clean, vendor-ready inquiry.</span>
          </div>
          <span class="gq-stepper-divider" aria-hidden="true"></span>
          <div class="gq-stepper-item" role="listitem">
            <span class="gq-stepper-num">03</span>
            <span class="gq-stepper-label">Connect</span>
            <span class="gq-stepper-text">Launch WhatsApp to message local stores.</span>
          </div>
        </div>
      </div>
    </div>
    <div class="section">
      <div class="container">

        <!-- Step 1: Compose (items + delivery) -->
        <div class="gq-step">
          <div class="gq-step-header">
            <div class="gq-step-num">1</div>
            <div>
              <h2 class="gq-step-title">Compose your list</h2>
              <p class="gq-step-desc">Add the materials or products you want a quote for &mdash; the more precise, the better.</p>
            </div>
          </div>
          <div class="gq-items-cols">
            <span></span>
            <span class="gq-col-label">Material / Product</span>
            <span class="gq-col-label">Quantity</span>
            <span class="gq-col-label">Additional Info</span>
            <span></span>
          </div>
          <div id="gq-items"></div>
          <button class="gq-add-btn" onclick="gqAddItem()">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            Add item
          </button>

          <div class="gq-note">
            <span class="gq-note-label">Concierge Note</span>
            <p>Local vendors in Lombok respond fastest to precise item specs and brand names. Providing exact variants ensures highly accurate quotes.</p>
          </div>

          <div class="gq-subsection">
            <label class="gq-checkbox-label">
              <input type="checkbox" id="gq-delivery-toggle" onchange="gqToggleDelivery(this.checked)">
              <span>Request delivery to a specific location</span>
            </label>
            <div id="gq-delivery-fields" style="display:none;">
              <div class="gq-delivery-grid">
                <div class="gq-field">
                  <label class="gq-field-label" for="gq-delivery-location">Delivery location</label>
                  <input type="text" id="gq-delivery-location" class="gq-input" placeholder="e.g. Kuta, Lombok"
                         oninput="gqUpdateDelivery('location', this.value)">
                </div>
                <div class="gq-field">
                  <label class="gq-field-label" for="gq-delivery-maps">Google Maps link <span class="gq-field-optional">(optional)</span></label>
                  <input type="url" id="gq-delivery-maps" class="gq-input" placeholder="https://maps.google.com/..."
                         oninput="gqUpdateDelivery('mapsLink', this.value)">
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Step 2: Generate -->
        <div class="gq-step">
          <div class="gq-step-header">
            <div class="gq-step-num">2</div>
            <div>
              <h2 class="gq-step-title">Your quote message</h2>
              <p class="gq-step-desc">Formatted from your list into a clean, vendor-ready inquiry &mdash; review and edit freely before you send.</p>
            </div>
          </div>
          <div class="gq-preview-bar">
            <span class="gq-preview-tag">Live Preview</span>
            <div class="gq-lang-badge" role="group" aria-label="Message language">
              <button type="button" class="gq-lang-opt is-active" data-lang="en" onclick="gqSetLang('en')">EN</button>
              <span class="gq-lang-sep" aria-hidden="true">&#8646;</span>
              <button type="button" class="gq-lang-opt" data-lang="id" onclick="gqSetLang('id')">ID</button>
            </div>
          </div>
          <div class="gq-message-wrap">
            <div class="gq-textarea-box">
              <textarea id="gq-message" class="gq-textarea" rows="10"
                        oninput="this.dataset.manualEdit='1';gqRefreshGhost()"></textarea>
              <div class="gq-preview-ghost" id="gq-preview-ghost" aria-hidden="true">
                <div class="gq-preview-ghost-head">Your formatted inquiry</div>
                <div class="gq-preview-ghost-body">Hello, please can you provide a quote for the following items:

&#8226;  [ your first material ]  &mdash;  Qty : &hellip;
&#8226;  &hellip;</div>
                <div class="gq-preview-ghost-foot">&lsaquo; add items above to generate your message &rsaquo;</div>
              </div>
            </div>
            <div class="gq-message-actions">
              <button class="btn btn--ghost btn--sm" onclick="gqResetMessage()">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg>
                Regenerate from items
              </button>
            </div>
          </div>
        </div>

        <!-- Step 3: Connect -->
        <div class="gq-step">
          <div class="gq-step-header">
            <div class="gq-step-num">3</div>
            <div>
              <h2 class="gq-step-title">Connect with suppliers</h2>
              <p class="gq-step-desc">Browse local stores below and tap WhatsApp on any one to open a chat with your message ready to send. Message as many as you like, then compare their replies.</p>
            </div>
          </div>

          <div class="dir-filters" style="margin-bottom:var(--space-5);">
            <div class="dir-primary-filters">
              <div class="dir-filter-pill">
                <label class="dir-filter-pill-label">${t('filter.where', 'Where in Lombok?')}</label>
                <select class="dir-filter-pill-select" onchange="gqUpdateFilter('area', this.value)">
                  <option value="">All Areas</option>
                  ${buildAreaOptions(filters.area)}
                </select>
              </div>
              <div class="dir-filter-pill">
                <label class="dir-filter-pill-label">Store type</label>
                <select class="dir-filter-pill-select" onchange="gqUpdateFilter('group', this.value)">
                  <option value="">All Types</option>
                  ${buildFilterOptions(FilterData.groups, filters.group)}
                </select>
              </div>
              <div class="dir-filter-pill">
                <label class="dir-filter-pill-label">Specialty</label>
                <select class="dir-filter-pill-select" onchange="gqUpdateFilter('category', this.value)">
                  <option value="">All Specialties</option>
                  ${filters.group
                    ? buildFilterOptions(FilterData.categories, filters.category, 'group_key', filters.group)
                    : buildFilterOptions(FilterData.categories, filters.category)
                  }
                </select>
              </div>
            </div>
            <div class="dir-secondary-filters">
              <button class="dir-more-btn" onclick="this.nextElementSibling.classList.toggle('open');this.classList.toggle('open')">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>
                More Filters
              </button>
              <div class="dir-more-body">
                <div class="dir-more-grid">
                  <div class="filter-group">
                    <label class="filter-label">Language</label>
                    <select class="filter-select" onchange="gqUpdateFilter('languages', this.value)">
                      <option value="">Any language</option>
                      <option value="english" ${filters.languages === 'english' ? 'selected' : ''}>English</option>
                      <option value="bahasa" ${filters.languages === 'bahasa' ? 'selected' : ''}>Bahasa</option>
                    </select>
                  </div>
                  <div class="filter-group">
                    <label class="filter-label">Min Rating</label>
                    <select class="filter-select" onchange="gqUpdateFilter('min_rating', this.value)">
                      <option value="">Any</option>
                      <option value="4.0" ${filters.min_rating === '4.0' ? 'selected' : ''}>4.0+</option>
                      <option value="4.5" ${filters.min_rating === '4.5' ? 'selected' : ''}>4.5+</option>
                    </select>
                  </div>
                  <div class="filter-group">
                    <label class="filter-label">Status</label>
                    <select class="filter-select" onchange="gqUpdateFilter('trusted', this.value)">
                      <option value="">All</option>
                      <option value="1" ${filters.trusted === '1' ? 'selected' : ''}>Trusted only</option>
                    </select>
                  </div>
                  <div class="filter-group">
                    <label class="filter-label">Sort by</label>
                    <select class="filter-select" onchange="gqUpdateFilter('sort', this.value)">
                      <option value="confidence" ${filters.sort === 'confidence' ? 'selected' : ''}>Most Trusted</option>
                      <option value="rating" ${filters.sort === 'rating' ? 'selected' : ''}>Highest Rated</option>
                      <option value="review_count" ${filters.sort === 'review_count' ? 'selected' : ''}>Most Reviewed</option>
                      <option value="alpha" ${filters.sort === 'alpha' ? 'selected' : ''}>A&ndash;Z</option>
                    </select>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <p class="results-count" id="gq-results-count"></p>
          <div id="gq-store-list" class="gq-store-list"></div>
          <!-- Auto-Quote (paid automated engine) temporarily disabled — feature-flagged off.
               Markup removed from the page; gqSendAuto/gqTogglePick handlers retained below for easy revival. -->
          <!--
          <div class="gq-auto-bar" id="gq-auto-bar">
            <div class="gq-auto-info"><strong>&#10024; Auto-Quote</strong> &mdash; tick suppliers above and we'll message them, translate the replies, and compare prices for you.</div>
            <button class="btn btn--primary" id="gq-auto-send" onclick="gqSendAuto()" disabled>Send to <span id="gq-auto-count">0</span> selected</button>
          </div>
          -->
        </div>

      </div>
    </div>
  `;

  renderItems();
  syncMessage();

  window.gqUpdateItem = function(id, field, value) {
    const item = quoteItems.find(function(i) { return i.id === id; });
    if (item) { item[field] = value; syncMessage(); }
  };

  window.gqAddItem = function() {
    quoteItems.push({ id: nextId++, material: '', quantity: '', info: '' });
    renderItems();
  };

  window.gqRemoveItem = function(id) {
    quoteItems = quoteItems.filter(function(i) { return i.id !== id; });
    renderItems();
    syncMessage();
  };

  window.gqToggleBahasa = function(checked) {
    useBahasa = checked;
    const ta = el.querySelector('#gq-message');
    if (ta) { delete ta.dataset.manualEdit; }
    syncMessage();
  };

  window.gqSetLang = function(lang) {
    useBahasa = (lang === 'id');
    el.querySelectorAll('.gq-lang-opt').forEach(function(btn) {
      btn.classList.toggle('is-active', btn.dataset.lang === lang);
    });
    const ta = el.querySelector('#gq-message');
    if (ta) delete ta.dataset.manualEdit;
    syncMessage();
  };

  window.gqRefreshGhost = refreshGhost;

  window.gqToggleDelivery = function(checked) {
    delivery.enabled = checked;
    syncDeliveryFields();
    syncMessage();
  };

  window.gqUpdateDelivery = function(field, value) {
    delivery[field] = value;
    syncMessage();
  };

  window.gqResetMessage = function() {
    const ta = el.querySelector('#gq-message');
    if (ta) { delete ta.dataset.manualEdit; syncMessage(); }
  };

  window.gqOpenWhatsApp = function(btn) {
    const waNum = btn.dataset.wa;
    const storeId = btn.dataset.storeId;
    const msg = (el.querySelector('#gq-message') || {}).value || buildMessage();
    window.open('https://wa.me/' + waNum + '?text=' + encodeURIComponent(msg), '_blank', 'noopener,noreferrer');
    gqMarkContacted(storeId);
  };

  window.gqMarkContacted = function(storeId) {
    contactedStores.add(String(storeId));
    localStorage.setItem('gq_contacted', JSON.stringify([...contactedStores]));
    const row = el.querySelector('.gq-store-row[data-id="' + storeId + '"]');
    if (row) {
      row.classList.add('gq-store-row--contacted');
      const actions = row.querySelector('.gq-store-actions');
      if (actions && !actions.querySelector('.gq-sent-badge')) {
        const badge = document.createElement('span');
        badge.className = 'gq-sent-badge';
        badge.textContent = '\u2713 Sent';
        actions.insertBefore(badge, actions.firstChild);
      }
      const waBtn = row.querySelector('.gq-wa-btn');
      if (waBtn) waBtn.classList.add('gq-wa-btn--contacted');
    }
  };

  window.gqUpdateFilter = function(key, value) {
    filters[key] = value;
    applyFiltersAndRender();
  };

  window.gqClearFilters = function() {
    filters.area = ''; filters.group = 'suppliers_materials'; filters.category = '';
    filters.languages = ''; filters.min_rating = ''; filters.trusted = '';
    el.querySelectorAll('.dir-filter-pill-select, .filter-select').forEach(function(s) { s.value = ''; });
    applyFiltersAndRender();
  };

  function updateAutoBar() {
    var n = selectedAuto.size;
    var b = el.querySelector('#gq-auto-send');
    if (b) { b.disabled = (n === 0); b.innerHTML = 'Send to <span id="gq-auto-count">' + n + '</span> selected'; }
  }

  window.gqTogglePick = function(id, checked) {
    if (checked) selectedAuto.add(String(id)); else selectedAuto.delete(String(id));
    updateAutoBar();
  };

  window.gqSendAuto = async function() {
    var items = quoteItems.filter(function(i){ return i.material.trim(); })
      .map(function(i){ return { material: i.material, quantity: i.quantity, info: i.info }; });
    if (!items.length) { alert('Add at least one item first (Step 1).'); return; }
    var ids = [];
    selectedAuto.forEach(function(v){ ids.push(v); });
    if (!ids.length) { alert('Tick at least one supplier.'); return; }
    var btn = el.querySelector('#gq-auto-send');
    if (btn) { btn.disabled = true; btn.innerHTML = 'Sending…'; }
    try {
      var res = await qApi('create_request', { body: {
        items: items, provider_ids: ids, lang: useBahasa ? 'id' : 'en',
        delivery: { required: delivery.enabled, location: delivery.location, maps_url: delivery.mapsLink }
      }});
      navigate('quote/' + res.request_id);
    } catch (e) {
      if (e.status === 401) showAuthModal('login');
      else if (e.upgrade) showQuoteUpgrade(e.required_tier);
      else if (e.data && e.data.error === 'quota_exceeded') {
        alert(e.data.scope === 'vendors_per_request'
          ? 'Your plan allows up to ' + e.data.limit + ' vendors per request.'
          : 'You\'ve used all ' + e.data.limit + ' quote requests for this 30-day period.');
      } else alert(e.message || 'Could not send.');
      updateAutoBar();
    }
  };

  applyFiltersAndRender();
}

function renderResetPassword(el, params) {
  const token = params.token || '';
  el.innerHTML = `
    <div class="section">
      <div class="container container--narrow">
        <div class="card" style="max-width:420px;margin:0 auto;padding:var(--space-8)">
          <h2 style="font-family:var(--font-display);margin-bottom:var(--space-4)">Set New Password</h2>
          <div class="auth-error" id="rp-error"></div>
          <div class="auth-success" id="rp-success"></div>
          <form class="auth-form" id="rp-form">
            <div class="auth-field">
              <label>New Password</label>
              <input type="password" id="rp-pass" required minlength="8">
            </div>
            <button type="submit" class="auth-submit">Reset Password</button>
          </form>
        </div>
      </div>
    </div>
  `;
  el.querySelector('#rp-form').addEventListener('submit', async (e) => {
    e.preventDefault();
    const btn = el.querySelector('.auth-submit');
    btn.disabled = true;
    try {
      const res = await UserAuth.resetPassword(token, el.querySelector('#rp-pass').value);
      document.getElementById('rp-success').textContent = res.message;
      document.getElementById('rp-success').classList.add('visible');
      el.querySelector('#rp-form').style.display = 'none';
    } catch(err) {
      document.getElementById('rp-error').textContent = err.message;
      document.getElementById('rp-error').classList.add('visible');
      btn.disabled = false;
    }
  });
}


// =====================================================
// INIT
// =====================================================

document.addEventListener('DOMContentLoaded', () => {
  initApp();
});

// Also handle case where DOMContentLoaded already fired
if (document.readyState === 'interactive' || document.readyState === 'complete') {
  initApp();
}

function initApp() {
  if (initApp._done) return;
  initApp._done = true;

  // Apply the initial language to all static markup + sync <html lang>
  applyStaticTranslations();
  updateLangToggleUI();

  // Language toggle: each `.lang-toggle` contains two `.lang-opt` spans
  // (one per language). Delegated click switches language.
  document.querySelectorAll('.lang-toggle').forEach(tog => {
    tog.addEventListener('click', e => {
      const opt = e.target.closest('.lang-opt');
      if (!opt) return;
      const lang = opt.getAttribute('data-lang');
      if (lang) setLanguage(lang);
    });
  });

  // Theme toggle
  document.querySelectorAll('[data-theme-toggle]').forEach(btn => {
    const t = window.__getCurrentTheme();
    btn.innerHTML = t === 'dark'
      ? `<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="5"/><path d="M12 1v2M12 21v2M4.22 4.22l1.42 1.42M18.36 18.36l1.42 1.42M1 12h2M21 12h2M4.22 19.78l1.42-1.42M18.36 5.64l1.42-1.42"/></svg>`
      : `<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>`;
    btn.addEventListener('click', window.__toggleTheme);
  });

  // Hamburger
  const hamburger = document.getElementById('hamburger-btn');
  const mobileMenu = document.getElementById('mobile-menu');
  if (hamburger && mobileMenu) {
    hamburger.addEventListener('click', () => {
      const open = mobileMenu.classList.toggle('open');
      hamburger.setAttribute('aria-expanded', open);
    });
    mobileMenu.querySelectorAll('a').forEach(a => {
      a.addEventListener('click', () => {
        mobileMenu.classList.remove('open');
        hamburger.setAttribute('aria-expanded', 'false');
      });
    });
  }

  // Command palette (global search)
  CommandPalette.init();

  // Check user session
  UserAuth.checkSession();

  // Router
  window.addEventListener('hashchange', router);
  router();
}

// =====================================================
// MOBILE FILTER DRAWER — slide-up filter UI on phones
// =====================================================

var MobileFilterDrawer = (function() {
  var FILTER_PAGES = { directory: true, listings: true, developers: true, agents: true };
  var filterEl = null;       // The original filter element we'll re-parent
  var placeholder = null;    // Marker node so we can put it back
  var drawer, panel, body, btn;

  function $() {
    drawer = document.getElementById('mobile-filter-drawer');
    panel  = drawer && drawer.querySelector('.mobile-filter-drawer-panel');
    body   = document.getElementById('mobile-filter-drawer-body');
    btn    = document.getElementById('mobile-filter-btn');
  }

  function updateAvailability(page, view) {
    $();
    if (!btn) return;
    var show = !!FILTER_PAGES[page];
    btn.hidden = !show;
    document.body.classList.toggle('has-mobile-filter', show);
    // Look for known filter containers in the rendered view
    filterEl = view && (view.querySelector('.dir-filters') || view.querySelector('.filters-bar'));
  }

  function open() {
    $();
    if (!drawer || !filterEl) return;
    // Replace the filter element with a placeholder, move it into the drawer
    placeholder = document.createComment('mobile-filter-placeholder');
    filterEl.parentNode.replaceChild(placeholder, filterEl);
    body.innerHTML = '';
    body.appendChild(filterEl);
    drawer.classList.add('open');
    drawer.setAttribute('aria-hidden', 'false');
    document.body.classList.add('drawer-open');
  }

  function close() {
    $();
    if (!drawer || !filterEl || !placeholder) {
      if (drawer) {
        drawer.classList.remove('open');
        drawer.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('drawer-open');
      }
      return;
    }
    placeholder.parentNode.replaceChild(filterEl, placeholder);
    placeholder = null;
    drawer.classList.remove('open');
    drawer.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('drawer-open');
  }

  function clearAll() {
    if (typeof window.clearDirectoryFilters === 'function') window.clearDirectoryFilters();
    else if (typeof window.applyListingFilters === 'function') window.location.hash = '#listings';
  }

  // Init once on first call
  function init() {
    $();
    if (btn && !btn._bound) {
      btn.addEventListener('click', open);
      btn._bound = true;
    }
  }

  return {
    updateAvailability: function(page, view) { init(); updateAvailability(page, view); },
    open: open,
    close: close,
    clearAll: clearAll
  };
})();

window.openMobileFilterDrawer  = function() { MobileFilterDrawer.open(); };
window.closeMobileFilterDrawer = function() { MobileFilterDrawer.close(); };
window.clearMobileFilters      = function() { MobileFilterDrawer.clearAll(); MobileFilterDrawer.close(); };

// =====================================================
// PAGE: List Your Business (placeholder funnel target)
// =====================================================

async function renderListYourBusiness(el) {
  el.innerHTML = ''
    + '<div class="dir-hero">'
    + '  <div class="container">'
    + '    <h1 class="dir-hero-title">List Your Business</h1>'
    + '    <p class="dir-hero-desc">Get in front of buyers, builders, and investors searching for trades, services, and suppliers across Lombok.</p>'
    + '  </div>'
    + '</div>'
    + '<div class="section"><div class="container container--narrow list-your-biz-page">'
    + '  <p style="color:var(--color-text-muted);margin-bottom:var(--space-6);max-width:54ch;margin-inline:auto;">Tell us about your business and we’ll get you live in the directory. The fastest way is a WhatsApp introduction — we’ll do the rest.</p>'
    + '  <div style="display:flex;gap:var(--space-3);justify-content:center;flex-wrap:wrap;">'
    + '    <a href="https://wa.me/628123456789?text=' + encodeURIComponent("Hi, I'd like to list my business on Build in Lombok.") + '" target="_blank" rel="noopener noreferrer" class="btn btn--whatsapp btn--lg">'
    + '      <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg>'
    + '      Get listed via WhatsApp'
    + '    </a>'
    + '    <a href="#agent-signup" onclick="navigate(\'agent-signup\');return false;" class="btn btn--ghost btn--lg">Sign up as an agent</a>'
    + '  </div>'
    + '</div></div>';
}

// =====================================================
// COMMAND PALETTE — global search overlay (ADR-0001)
//
// Single overlay component mounted in document.body. Summoned from:
//   - any element with data-cmd-trigger (hero, nav pill, mobile menu)
//   - ⌘K / Ctrl+K from anywhere
//   - "/" key when no editable element has focus
//
// Renders empty state (Recent + Browse), debounced live search results
// (sectioned top-3 desktop / top-2 mobile, with "View all N →" per
// section that deep-links to /search), or "No results" state.
//
// Backend: /api/index.php?action=search&palette=1&q=...
// Cache: in-memory LRU, 30 entries, per-session.
// =====================================================

var CommandPalette = (function() {

  // Section configuration — top-down order matches Q6 spec
  var SECTIONS = [
    { type: 'listing',   label: 'Properties',   labelSing: 'Property',  route: function(it){ return 'listing/'  + it.slug; }, viewAll: 'listing'   },
    { type: 'provider',  label: 'Vendors',      labelSing: 'Vendor',    route: function(it){ return 'provider/' + it.slug; }, viewAll: 'provider'  },
    { type: 'agent',     label: 'Agents',       labelSing: 'Agent',     route: function(it){ return 'agent/'    + it.slug; }, viewAll: 'agent'     },
    { type: 'developer', label: 'Developers',   labelSing: 'Developer', route: function(it){ return 'developer/'+ it.slug; }, viewAll: 'developer' },
    { type: 'project',   label: 'Projects',     labelSing: 'Project',   route: function(it){ return 'project/'  + it.slug; }, viewAll: 'project'   },
    { type: 'guide',     label: 'Guides',       labelSing: 'Guide',     route: function(it){ return 'guide/'    + it.slug; }, viewAll: 'guide'     },
  ];

  // Browse cards (empty-state fallback) — same 5 as home category section
  var BROWSE_CARDS = [
    { route: 'listings',                            labelKey: 'home.find_property',      label: 'Find Property',                icon: 'home'     },
    { route: 'developers',                          labelKey: 'home.find_developers',    label: 'Find Developers & Investments',icon: 'building' },
    { route: 'directory?group=builders_trades',     labelKey: 'home.find_builders',      label: 'Find Builders & Trades',       icon: 'tools'    },
    { route: 'directory?group=professional_services',labelKey: 'home.find_professionals',label: 'Find Professional Services',   icon: 'layers'   },
    { route: 'directory?group=suppliers_materials', labelKey: 'home.find_materials',     label: 'Find Materials & Suppliers',   icon: 'box'      },
  ];

  var RECENT_KEY  = 'bil_recent_searches';
  var RECENT_MAX  = 5;
  var DEBOUNCE_MS = 220;
  var MIN_CHARS   = 2;
  var CACHE_MAX   = 30;

  // State
  var overlay, panel, input, body, hintBar;
  var isOpen = false;
  var debounceTimer = null;
  var abortCtl = null;
  var queryCache = new Map();
  var selectableEls = [];
  var selectionIndex = -1;
  var currentQuery = '';
  var isMac = false;

  // ── Recent searches (localStorage) ───────────────────────────────

  function getRecent() {
    try {
      var raw = localStorage.getItem(RECENT_KEY);
      if (!raw) return [];
      var arr = JSON.parse(raw);
      return Array.isArray(arr) ? arr.slice(0, RECENT_MAX) : [];
    } catch(e) { return []; }
  }
  function pushRecent(q) {
    if (!q || q.length < MIN_CHARS) return;
    try {
      var arr = getRecent().filter(function(item) { return item !== q; });
      arr.unshift(q);
      arr = arr.slice(0, RECENT_MAX);
      localStorage.setItem(RECENT_KEY, JSON.stringify(arr));
    } catch(e) {}
  }
  function removeRecent(q) {
    try {
      var arr = getRecent().filter(function(item) { return item !== q; });
      localStorage.setItem(RECENT_KEY, JSON.stringify(arr));
    } catch(e) {}
  }
  function clearRecent() {
    try { localStorage.removeItem(RECENT_KEY); } catch(e) {}
  }

  // ── LRU query cache ───────────────────────────────────────────────

  function cacheGet(key) {
    if (!queryCache.has(key)) return null;
    var val = queryCache.get(key);
    queryCache.delete(key);
    queryCache.set(key, val);  // re-insert as newest
    return val;
  }
  function cacheSet(key, val) {
    if (queryCache.has(key)) queryCache.delete(key);
    queryCache.set(key, val);
    if (queryCache.size > CACHE_MAX) {
      var oldest = queryCache.keys().next().value;
      queryCache.delete(oldest);
    }
  }

  // ── Highlighting matched substrings ───────────────────────────────

  function highlight(text, query) {
    var safe = escHtml(text || '');
    if (!query) return safe;
    var tokens = query.trim().split(/\s+/).filter(function(t) { return t.length >= 2; });
    if (!tokens.length) return safe;
    var pattern = tokens.map(function(t) {
      return t.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    }).join('|');
    try {
      return safe.replace(new RegExp('(' + pattern + ')', 'gi'), '<mark>$1</mark>');
    } catch(e) {
      return safe;
    }
  }

  // ── Icons ─────────────────────────────────────────────────────────

  function browseIcon(name) {
    switch (name) {
      case 'home':     return '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>';
      case 'building': return '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path d="M2 20h20"/><path d="M5 20V8l7-5 7 5v12"/><path d="M9 20v-4h6v4"/></svg>';
      case 'tools':    return '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path d="M2 18.5A2.5 2.5 0 0 1 4.5 16H20"/><path d="M2 7h16a2 2 0 0 1 2 2v9.5A2.5 2.5 0 0 1 17.5 21H4.5A2.5 2.5 0 0 1 2 18.5z"/></svg>';
      case 'layers':   return '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path d="M12 2L2 7l10 5 10-5-10-5z"/><path d="M2 17l10 5 10-5"/><path d="M2 12l10 5 10-5"/></svg>';
      case 'box':      return '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg>';
    }
    return '';
  }

  function clockIcon() {
    return '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>';
  }

  // ── DOM mount ─────────────────────────────────────────────────────

  function mountOverlay() {
    if (overlay) return;
    overlay = document.createElement('div');
    overlay.className = 'cmd-palette';
    overlay.setAttribute('aria-hidden', 'true');
    overlay.innerHTML = ''
      + '<div class="cmd-palette-backdrop" data-cmd-action="close"></div>'
      + '<div class="cmd-palette-panel" role="dialog" aria-modal="true" aria-label="' + escHtml(t('palette.aria_label', 'Search Build in Lombok')) + '">'
      + '  <div class="cmd-palette-input-row">'
      + '    <svg class="cmd-palette-input-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>'
      + '    <input type="search" class="cmd-palette-input" id="cmd-palette-input" placeholder="' + escHtml(t('palette.placeholder', 'What are you looking for?')) + '" autocomplete="off" spellcheck="false" autocorrect="off" autocapitalize="off" aria-label="' + escHtml(t('palette.input_aria', 'Search')) + '">'
      + '    <button class="cmd-palette-close" type="button" data-cmd-action="close" aria-label="' + escHtml(t('palette.close_aria', 'Close search')) + '">'
      + '      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>'
      + '    </button>'
      + '  </div>'
      + '  <div class="cmd-palette-body" id="cmd-palette-body" role="listbox" aria-label="' + escHtml(t('palette.results_aria', 'Search results')) + '"></div>'
      + '  <div class="cmd-palette-hint" aria-hidden="true">'
      + '    <span class="cmd-hint-item"><kbd class="cmd-kbd">↑</kbd><kbd class="cmd-kbd">↓</kbd> ' + escHtml(t('palette.hint.navigate', 'navigate')) + '</span>'
      + '    <span class="cmd-hint-item"><kbd class="cmd-kbd">↵</kbd> ' + escHtml(t('palette.hint.select', 'select')) + '</span>'
      + '    <span class="cmd-hint-item"><kbd class="cmd-kbd">esc</kbd> ' + escHtml(t('palette.hint.close', 'close')) + '</span>'
      + '  </div>'
      + '</div>';
    document.body.appendChild(overlay);
    panel   = overlay.querySelector('.cmd-palette-panel');
    input   = overlay.querySelector('#cmd-palette-input');
    body    = overlay.querySelector('#cmd-palette-body');
    hintBar = overlay.querySelector('.cmd-palette-hint');

    // Wire events
    overlay.addEventListener('click', function(e) {
      // Use closest() so clicks land on inner SVG/line elements still resolve to the button/backdrop
      var actionEl = e.target.closest ? e.target.closest('[data-cmd-action="close"]') : null;
      if (actionEl) {
        e.preventDefault();
        close();
      }
    });
    input.addEventListener('input', onInput);
    input.addEventListener('keydown', onKeydown);
    body.addEventListener('click', onBodyClick);
    body.addEventListener('mouseover', function(e) {
      var row = e.target.closest('.cmd-row');
      if (!row) return;
      var idx = selectableEls.indexOf(row);
      if (idx >= 0 && idx !== selectionIndex) setSelection(idx);
    });
  }

  // ── Empty state ───────────────────────────────────────────────────

  function renderEmptyState() {
    var recent = getRecent();
    var html = '';

    if (recent.length) {
      html += '<div class="cmd-section cmd-section--recent">';
      html += '  <div class="cmd-section-head">';
      html += '    <span class="cmd-section-title">' + escHtml(t('palette.section.recent', 'Recent')) + '</span>';
      html += '    <button class="cmd-section-clear" type="button" data-cmd-action="clear-recent">' + escHtml(t('palette.clear_all', 'Clear all')) + '</button>';
      html += '  </div>';
      recent.forEach(function(q) {
        html += '<div class="cmd-row cmd-row--recent" data-cmd-action="repeat-search" data-q="' + escHtml(q) + '" role="option" tabindex="-1">';
        html += '  <span class="cmd-row-icon cmd-row-icon--recent" aria-hidden="true">' + clockIcon() + '</span>';
        html += '  <span class="cmd-row-main">' + escHtml(q) + '</span>';
        html += '  <button class="cmd-row-remove" type="button" data-cmd-action="remove-recent" data-q="' + escHtml(q) + '" aria-label="' + escHtml(t('palette.remove_aria', 'Remove')) + '">';
        html += '    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>';
        html += '  </button>';
        html += '</div>';
      });
      html += '</div>';
    }

    html += '<div class="cmd-section cmd-section--browse">';
    html += '  <div class="cmd-section-head"><span class="cmd-section-title">' + escHtml(t('palette.section.browse', 'Browse')) + '</span></div>';
    BROWSE_CARDS.forEach(function(card) {
      var label = t(card.labelKey, card.label);
      html += '<a class="cmd-row cmd-row--browse" data-nav="' + escHtml(card.route) + '" role="option" tabindex="-1">';
      html += '  <span class="cmd-row-icon cmd-row-icon--browse" aria-hidden="true">' + browseIcon(card.icon) + '</span>';
      html += '  <span class="cmd-row-main">' + escHtml(label) + '</span>';
      html += '  <span class="cmd-row-chev" aria-hidden="true"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6"/></svg></span>';
      html += '</a>';
    });
    html += '</div>';

    body.innerHTML = html;
    refreshSelectable();
  }

  // ── Loading state ─────────────────────────────────────────────────

  function renderLoading(q) {
    body.innerHTML = ''
      + '<div class="cmd-loading">'
      + '  <div class="cmd-spinner" aria-hidden="true"></div>'
      + '  <span>' + escHtml(t('palette.searching', 'Searching')) + ' &ldquo;' + escHtml(q) + '&rdquo;…</span>'
      + '</div>';
    refreshSelectable();
  }

  // ── Results ───────────────────────────────────────────────────────

  function renderResults(q, results) {
    var topN = (window.innerWidth < 768) ? 2 : 3;
    var buckets = {};
    SECTIONS.forEach(function(s) { buckets[s.type] = []; });
    results.forEach(function(r) {
      if (buckets[r.type]) buckets[r.type].push(r);
    });

    var total = 0;
    SECTIONS.forEach(function(s) { total += buckets[s.type].length; });

    if (total === 0) {
      body.innerHTML = ''
        + '<div class="cmd-empty">'
        + '  <div class="cmd-empty-icon" aria-hidden="true">'
        + '    <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>'
        + '  </div>'
        + '  <p class="cmd-empty-title">' + escHtml(t('palette.no_results_title', 'No matches for')) + ' &ldquo;' + escHtml(q) + '&rdquo;</p>'
        + '  <p class="cmd-empty-desc">' + escHtml(t('palette.no_results_desc', 'Try different keywords, or browse a category instead.')) + '</p>'
        + '</div>';
      refreshSelectable();
      return;
    }

    var html = '';
    SECTIONS.forEach(function(sec) {
      var items = buckets[sec.type];
      if (!items.length) return;
      var shown = items.slice(0, topN);
      var sectionLabel = t('palette.section.' + sec.type, sec.label);

      html += '<div class="cmd-section cmd-section--results">';
      html += '  <div class="cmd-section-head">';
      html += '    <span class="cmd-section-title">' + escHtml(sectionLabel) + '</span>';
      html += '    <span class="cmd-section-count">' + items.length + '</span>';
      html += '  </div>';

      shown.forEach(function(it) {
        var navHash = sec.route(it);
        var sub = buildSubtitle(it, sec.type, q);
        html += '<a class="cmd-row cmd-row--result" data-nav="' + escHtml(navHash) + '" data-q="' + escHtml(q) + '" role="option" tabindex="-1">';
        html += '  <span class="cmd-row-thumb" aria-hidden="true">' + buildThumb(it) + '</span>';
        html += '  <span class="cmd-row-body">';
        html += '    <span class="cmd-row-main">' + highlight(it.name || '', q) + '</span>';
        if (sub) html += '    <span class="cmd-row-meta">' + sub + '</span>';
        html += '  </span>';
        html += '  <span class="cmd-row-type-badge">' + escHtml(t('palette.type.' + sec.type, sec.labelSing)) + '</span>';
        html += '</a>';
      });

      if (items.length > topN) {
        html += '<a class="cmd-row cmd-row--viewall" data-nav="search?q=' + encodeURIComponent(q) + '&type=' + escHtml(sec.viewAll) + '" data-q="' + escHtml(q) + '" role="option" tabindex="-1">';
        html += '  <span class="cmd-row-viewall-label">' + escHtml(t('palette.view_all', 'View all') + ' ' + items.length + ' ' + sectionLabel.toLowerCase()) + ' →</span>';
        html += '</a>';
      }

      html += '</div>';
    });

    body.innerHTML = html;
    refreshSelectable();
  }

  function buildSubtitle(it, type, q) {
    if (type === 'guide') {
      var parts = [];
      if (it.category)  parts.push(escHtml(it.category));
      if (it.read_time) parts.push(it.read_time + ' ' + escHtml(t('palette.read_time_min', 'min read')));
      return parts.join(' · ');
    }
    var parts = [];
    if (it.area_label)                       parts.push(escHtml(it.area_label));
    if (type === 'agent' && it.agency_name)  parts.push(escHtml(it.agency_name));
    if (it.excerpt) {
      var trimmed = String(it.excerpt).substring(0, 80);
      if (it.excerpt.length > 80) trimmed += '…';
      parts.push(highlight(trimmed, q));
    }
    return parts.join(' · ');
  }

  function buildThumb(it) {
    var img = it.profile_photo_url || it.logo_url || '';
    if (img) return '<img src="' + escHtml(img) + '" alt="" loading="lazy" onerror="this.style.display=\'none\'">';
    var letter = (it.name || '?').charAt(0).toUpperCase();
    return '<span class="cmd-row-thumb-letter">' + escHtml(letter) + '</span>';
  }

  // ── Selection / keyboard nav ──────────────────────────────────────

  function refreshSelectable() {
    selectableEls = Array.prototype.slice.call(body.querySelectorAll('.cmd-row'));
    selectionIndex = -1;
    selectableEls.forEach(function(el) { el.classList.remove('cmd-row--selected'); });
    if (selectableEls.length) setSelection(0);  // pre-select first row
  }

  function moveSelection(delta) {
    if (!selectableEls.length) return;
    var next = selectionIndex + delta;
    if (next < 0) next = selectableEls.length - 1;
    if (next >= selectableEls.length) next = 0;
    setSelection(next);
  }

  function setSelection(idx) {
    selectableEls.forEach(function(el) { el.classList.remove('cmd-row--selected'); });
    selectionIndex = idx;
    var el = selectableEls[idx];
    if (!el) return;
    el.classList.add('cmd-row--selected');
    var rect = el.getBoundingClientRect();
    var bodyRect = body.getBoundingClientRect();
    if (rect.bottom > bodyRect.bottom) el.scrollIntoView({ block: 'end',   inline: 'nearest' });
    else if (rect.top < bodyRect.top)  el.scrollIntoView({ block: 'start', inline: 'nearest' });
  }

  function activateSelection() {
    if (selectionIndex < 0) return;
    var el = selectableEls[selectionIndex];
    if (el) activateRow(el);
  }

  function activateRow(el) {
    var action = el.getAttribute('data-cmd-action');
    var q = el.getAttribute('data-q') || '';

    if (action === 'clear-recent') { clearRecent(); renderEmptyState(); return; }
    if (action === 'remove-recent') { removeRecent(q); renderEmptyState(); return; }
    if (action === 'repeat-search') {
      input.value = q;
      currentQuery = q;
      pushRecent(q);
      fireSearch(q);
      input.focus();
      return;
    }

    var nav = el.getAttribute('data-nav');
    if (nav) {
      var ctxQ = el.getAttribute('data-q');
      if (ctxQ) pushRecent(ctxQ);
      close();
      navigate(nav);
    }
  }

  // ── Search execution ─────────────────────────────────────────────

  function onInput() {
    var q = input.value.trim();
    currentQuery = q;

    if (q.length < MIN_CHARS) {
      clearTimeout(debounceTimer);
      if (abortCtl) { try { abortCtl.abort(); } catch(e) {} }
      renderEmptyState();
      return;
    }

    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(function() { fireSearch(q); }, DEBOUNCE_MS);
  }

  function fireSearch(q) {
    if (!q || q.length < MIN_CHARS) return;

    var cached = cacheGet(q);
    if (cached) { renderResults(q, cached); return; }

    if (abortCtl) { try { abortCtl.abort(); } catch(e) {} }
    abortCtl = (typeof AbortController !== 'undefined') ? new AbortController() : null;

    renderLoading(q);

    var url  = '/api/search?palette=1&q=' + encodeURIComponent(q);
    var opts = abortCtl ? { signal: abortCtl.signal } : {};

    fetch(url, opts)
      .then(function(res) { return res.json(); })
      .then(function(data) {
        var rows = (data && data.data) || [];
        cacheSet(q, rows);
        if (q === currentQuery) renderResults(q, rows);
      })
      .catch(function(err) {
        if (err && err.name === 'AbortError') return;
        console.error('Palette search error:', err);
        if (q === currentQuery) {
          body.innerHTML = '<div class="cmd-empty"><p class="cmd-empty-title">' + escHtml(t('palette.error', 'Search failed. Please try again.')) + '</p></div>';
        }
      });
  }

  // ── Keyboard handlers ────────────────────────────────────────────

  function onKeydown(e) {
    if (e.key === 'ArrowDown') {
      e.preventDefault(); moveSelection(1);
    } else if (e.key === 'ArrowUp') {
      e.preventDefault(); moveSelection(-1);
    } else if (e.key === 'Tab') {
      e.preventDefault(); moveSelection(e.shiftKey ? -1 : 1);
    } else if (e.key === 'Enter') {
      e.preventDefault();
      if (selectionIndex >= 0) {
        activateSelection();
      } else if (currentQuery.length >= MIN_CHARS) {
        close();
        pushRecent(currentQuery);
        navigate('search?q=' + encodeURIComponent(currentQuery));
      }
    } else if (e.key === 'Escape') {
      e.preventDefault(); close();
    }
  }

  function onBodyClick(e) {
    var removeBtn = e.target.closest('.cmd-row-remove');
    if (removeBtn) {
      e.preventDefault();
      e.stopPropagation();
      removeRecent(removeBtn.getAttribute('data-q'));
      renderEmptyState();
      return;
    }
    var clearBtn = e.target.closest('.cmd-section-clear');
    if (clearBtn) {
      e.preventDefault();
      clearRecent();
      renderEmptyState();
      return;
    }
    var row = e.target.closest('.cmd-row');
    if (row) { e.preventDefault(); activateRow(row); }
  }

  // ── Lifecycle ────────────────────────────────────────────────────

  function open(prefill) {
    mountOverlay();
    if (isOpen) {
      if (typeof prefill === 'string' && prefill.length) {
        input.value = prefill;
        currentQuery = prefill;
        fireSearch(prefill);
      }
      try { input.focus(); } catch(e) {}
      return;
    }
    isOpen = true;
    overlay.classList.add('cmd-palette--open');
    overlay.setAttribute('aria-hidden', 'false');
    document.body.classList.add('cmd-palette-open');

    if (typeof prefill === 'string' && prefill.length) {
      input.value = prefill;
      currentQuery = prefill;
      renderEmptyState();
      fireSearch(prefill);
    } else {
      input.value = '';
      currentQuery = '';
      renderEmptyState();
    }

    // Defer focus so iOS reliably opens the keyboard
    setTimeout(function() { try { input.focus(); } catch(e) {} }, 30);
  }

  function close() {
    if (!isOpen) return;
    isOpen = false;
    if (overlay) {
      overlay.classList.remove('cmd-palette--open');
      overlay.setAttribute('aria-hidden', 'true');
    }
    document.body.classList.remove('cmd-palette-open');
    if (abortCtl) { try { abortCtl.abort(); } catch(e) {} }
    abortCtl = null;
    clearTimeout(debounceTimer);
    if (input) try { input.blur(); } catch(e) {}
  }

  // ── Global shortcut handler ──────────────────────────────────────

  function isEditable(el) {
    if (!el) return false;
    var tag = el.tagName;
    if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT') return true;
    if (el.isContentEditable) return true;
    return false;
  }

  function onGlobalKey(e) {
    // ⌘K / Ctrl+K toggle from anywhere
    if ((e.metaKey || e.ctrlKey) && (e.key === 'k' || e.key === 'K')) {
      e.preventDefault();
      if (isOpen) close(); else open();
      return;
    }
    // `/` opens, guarded against editable focus
    if (!isOpen && e.key === '/' && !isEditable(document.activeElement)) {
      e.preventDefault();
      open();
      return;
    }
  }

  // ── Trigger binding ──────────────────────────────────────────────
  // Delegated on document so it works for any [data-cmd-trigger] that
  // exists at boot OR gets injected later by async route renders.

  function bindTriggers() {
    if (bindTriggers._done) return;
    bindTriggers._done = true;
    document.addEventListener('click', function(e) {
      if (!e.target.closest) return;
      var trigger = e.target.closest('[data-cmd-trigger]');
      if (!trigger) return;
      e.preventDefault();
      open();
    });
    // Hijack focus on any input-style trigger (rare; legacy mobile pattern)
    document.addEventListener('focusin', function(e) {
      var el = e.target;
      if (!el || el.tagName !== 'INPUT') return;
      if (!el.matches || !el.matches('[data-cmd-trigger]')) return;
      try { el.blur(); } catch(err) {}
      open();
    });
  }

  function init() {
    isMac = /Mac|iPhone|iPad|iPod/i.test(navigator.platform || navigator.userAgent || '');
    document.body.classList.add(isMac ? 'platform-mac' : 'platform-not-mac');
    document.addEventListener('keydown', onGlobalKey);
    bindTriggers();
  }

  return {
    init:          init,
    open:          open,
    close:         close,
    bindTriggers:  bindTriggers,
    isMac:         function() { return isMac; },
  };
})();

window.CommandPalette    = CommandPalette;
window.openSearchPalette = function(prefill) { CommandPalette.open(prefill); };

