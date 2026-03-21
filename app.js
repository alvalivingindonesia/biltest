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
      btn.setAttribute('aria-label', `Switch to ${theme === 'dark' ? 'light' : 'dark'} mode`);
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

    const res = await fetch(key);
    if (!res.ok) throw new Error(`API error ${res.status}`);
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

// ---- Dynamic filter data (loaded from DB) ----
const FilterData = {
  areas: [], regions: [], groups: [], categories: [], project_types: [], project_statuses: [], listing_types: [], land_certificate_types: [],
  _loaded: false,
  async load() {
    if (this._loaded) return;
    try {
      const f = await DataLayer.getFilters();
      this.areas = f.areas || [];
      this.regions = f.regions || [];
      this.groups = f.groups || [];
      this.categories = f.categories || [];
      this.project_types = f.project_types || [];
      this.project_statuses = f.project_statuses || [];
      this.listing_types = f.listing_types || [];
      this.land_certificate_types = f.land_certificate_types || [];
      this._loaded = true;
    } catch(e) { console.error('Failed to load filters:', e); }
  },
  labelMap(arr) {
    const m = {}; arr.forEach(i => { m[i.key || i.region_key] = i.label; }); return m;
  }
};

/** Build area <option> tags grouped by region */
function buildAreaOptions(selectedValue) {
  const regions = FilterData.regions;
  const areas = FilterData.areas;
  if (!regions.length) {
    // Fallback: flat list if regions not loaded
    return areas.map(a => '<option value="' + (a.key) + '"' + (selectedValue === a.key ? ' selected' : '') + '>' + a.label + '</option>').join('');
  }
  let html = '';
  const regionMap = {};
  regions.forEach(r => { regionMap[r.region_key] = { label: r.label, areas: [] }; });
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
      html += '<option value="' + a.key + '"' + (selectedValue === a.key ? ' selected' : '') + '>' + a.label + '</option>';
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
  return list.map(i => `<option value="${i.key}" ${selectedValue === i.key ? 'selected' : ''}>${i.label}</option>`).join('');
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
    return `<div class="google-rating"><span class="rating-na">No Google reviews yet</span></div>`;
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
    if (hrefPage === 'rab-calculator' && (page === 'rab-calculator' || page === 'rab-estimates' || page === 'rab-result')) {
      a.classList.add('active');
    }
    // About
    if (hrefPage === 'about' && page === 'about') {
      a.classList.add('active');
    }
    // Home
    if (hrefPage === 'home' && page === 'home') {
      a.classList.add('active');
    }
  });

  // Render
  const main = document.getElementById('main-content');
  if (!main) return;

  // Show loading spinner while page renders (delayed so fast pages skip it)
  main.innerHTML = '<div class="page-loading"><div class="page-loading-spinner"></div></div>';
  var view = document.createElement('div');
  view.className = 'page-view';

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
    default: await renderHome(view);
  }

  // Clear spinner, then show rendered page
  main.innerHTML = '';
  main.appendChild(view);
  window.scrollTo({ top: 0, behavior: 'instant' });
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
    <!-- HERO — Full bleed, wireframe layout -->
    <section class="hero" aria-label="Build in Lombok hero">
      <div class="hero-bg" id="hero-bg"></div>
      <div class="hero-overlay"></div>
      <div class="hero-inner">
        <div class="container">
          <h1 class="hero-title">BUILD IN LOMBOK</h1>
          <p class="hero-subtitle">AI-powered tools to help you build &amp; invest in Lombok</p>
          <div class="hero-search">
            <svg class="hero-search-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
            <input type="search" class="hero-search-input" placeholder="Search providers, developers, projects..." autocomplete="off" id="hero-search-input">
          </div>
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
            <h3 class="category-card-title">Find Property &amp; Agents</h3>
            <p class="category-card-desc">Browse properties and find local agents.</p>
            <span class="category-card-cta">Explore ${iconArrowRight()}</span>
          </a>
          <a href="#developers" class="category-card" onclick="navigate('developers'); return false;">
            <div class="category-card-icon">
              <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M2 20h20"/><path d="M5 20V8l7-5 7 5v12"/><path d="M9 20v-4h6v4"/><path d="M9 12h.01"/><path d="M15 12h.01"/></svg>
            </div>
            <h3 class="category-card-title">Find Developers &amp; Investments</h3>
            <p class="category-card-desc">Discover development opportunities and partners.</p>
            <span class="category-card-cta">Explore ${iconArrowRight()}</span>
          </a>
          <a href="#directory?group=builders_trades" class="category-card" onclick="navigate('directory?group=builders_trades'); return false;">
            <div class="category-card-icon">
              <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M2 18.5A2.5 2.5 0 0 1 4.5 16H20"/><path d="M2 7h16a2 2 0 0 1 2 2v9.5A2.5 2.5 0 0 1 17.5 21H4.5A2.5 2.5 0 0 1 2 18.5z"/><path d="M6 12h4"/><path d="M6 16h8"/><circle cx="18" cy="4" r="3"/></svg>
            </div>
            <h3 class="category-card-title">Find Builders &amp; Trades</h3>
            <p class="category-card-desc">Connect with skilled builders and trades.</p>
            <span class="category-card-cta">Explore ${iconArrowRight()}</span>
          </a>
          <a href="#directory?group=professional_services" class="category-card" onclick="navigate('directory?group=professional_services'); return false;">
            <div class="category-card-icon">
              <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M12 2L2 7l10 5 10-5-10-5z"/><path d="M2 17l10 5 10-5"/><path d="M2 12l10 5 10-5"/></svg>
            </div>
            <h3 class="category-card-title">Find Professional Services</h3>
            <p class="category-card-desc">Locate architects, lawyers, and consultants.</p>
            <span class="category-card-cta">Explore ${iconArrowRight()}</span>
          </a>
          <a href="#directory?group=suppliers_materials" class="category-card" onclick="navigate('directory?group=suppliers_materials'); return false;">
            <div class="category-card-icon">
              <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg>
            </div>
            <h3 class="category-card-title">Find Materials &amp; Suppliers</h3>
            <p class="category-card-desc">Source quality materials and local suppliers.</p>
            <span class="category-card-cta">Explore ${iconArrowRight()}</span>
          </a>
        </div>
      </div>
    </section>

    <!-- FEATURED + GUIDES — Two-column below categories -->
    <section class="section section-animate" style="background:var(--color-surface-offset);">
      <div class="container">
        <div class="home-split">
          <!-- Left: Featured developers & projects -->
          <div class="home-split-main">
            <h2 class="home-split-heading">Featured developers &amp; projects</h2>
            <div class="home-featured-row">
              ${[...displayFeaturedDevs.slice(0, 2), ...featuredProjects.slice(0, 1)].map((item) => {
                const isProject = !!item.developer_id;
                const name = isProject ? item.project_name : (item.name || item.display_name || 'Unnamed');
                const photo = item.profile_photo_url || item.hero_image_url || '';
                const link = isProject ? '#project/' + (item.slug || item.id) : '#developer/' + (item.slug || item.id);
                const route = isProject ? 'project/' + (item.slug || item.id) : 'developer/' + (item.slug || item.id);
                return `
                  <a href="${link}" class="home-featured-thumb" onclick="navigate('${route}'); return false;">
                    <div class="home-featured-img" style="background-image:url('${photo}');"></div>
                    <div class="home-featured-label">${name}</div>
                  </a>
                `;
              }).join('')}
            </div>
            <div style="margin-top: var(--space-4);">
              <a href="#developers" class="home-split-link" onclick="navigate('developers'); return false;">View all developers &amp; projects ${iconArrowRight()}</a>
            </div>
          </div>
          <!-- Right: Guides & resources -->
          <div class="home-split-side">
            <h2 class="home-split-heading">Guides &amp; resources</h2>
            <ul class="home-guides-list">
              ${homeGuides.slice(0, 5).map((g) => `
                <li>
                  <a href="#guide/${g.slug}" onclick="navigate('guide/${g.slug}'); return false;">${g.title}</a>
                </li>
              `).join('')}
            </ul>
            <div style="margin-top: var(--space-4);">
              <a href="#guides" class="home-split-link" onclick="navigate('guides'); return false;">All guides ${iconArrowRight()}</a>
            </div>
          </div>
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
          <h2 class="help-cta-title" style="color:#faf8f4;">Need Help With Your Project?</h2>
          <p class="help-cta-desc" style="color:rgba(212,209,202,0.6);">Not sure where to start? Drop your details via WhatsApp and we'll point you in the right direction.</p>
          <a href="https://wa.me/628123456789" target="_blank" rel="noopener noreferrer" class="btn btn--whatsapp">
            ${iconWhatsApp()} Get in Touch on WhatsApp
          </a>
        </div>
      </div>
    </section>
  `;

  // Animate cards
  requestAnimationFrame(() => animateCards(el));

  // Hero search handler
  const heroInput = document.getElementById('hero-search-input');
  if (heroInput) {
    heroInput.addEventListener('keydown', (e) => {
      if (e.key === 'Enter' && heroInput.value.trim()) {
        navigate('directory?q=' + encodeURIComponent(heroInput.value.trim()));
      }
    });
  }
}

// =====================================================
// RENDER: PROVIDER CARD
// =====================================================

function renderProviderCard(b, index = 0) {
  const waBtn = b.whatsapp_number
    ? `<a href="https://wa.me/${b.whatsapp_number}" target="_blank" rel="noopener noreferrer" class="card-wa-btn" aria-label="WhatsApp ${b.name}">${iconWhatsApp()}</a>`
    : '';

  const badge = b.badge
    ? `<span class="card-badge">${renderBadge(b.badge)}</span>`
    : '';

  const trustedBadge = b.is_trusted ? '<span class="card-badge card-badge--trusted">✓ Trusted</span>' : '';

  var langParts = (b.languages || '').split(/[,+]+/).map(function(s){ return s.trim(); }).filter(Boolean);
  var langShort = langParts.length === 0 ? 'Bahasa' : langParts.join(' · ');
  const ratingInline = b.google_rating
    ? `<span class="card-rating-inline"><span class="card-rating-star">★</span> ${b.google_rating.toFixed(1)} <span class="card-rating-count">(${b.google_review_count})</span></span>`
    : '';

  const thumbImg = b.logo_url || b.profile_photo_url;
  const hasPhoto = !!thumbImg;
  const categoryLabel = (b.categories && b.categories.length > 0) ? b.categories.map(c => formatCategoryLabel(c.key || c)).join(' · ') : formatCategoryLabel(b.category);

  return `
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
      </div>
      <div class="card-tags-line">
        ${b.tags.slice(0, 3).map(t => `<span class="card-tag">${t}</span>`).join('<span class="card-tag-dot">·</span>')}
      </div>
      <div class="card-footer">
        <button class="card-view-btn" onclick="navigate('provider/${b.slug}')">
          View details ${iconArrowRight()}
        </button>
        <div class="card-footer-right">${renderFavBtn('provider', b.id)}${waBtn}</div>
      </div>
    </article>
  `;
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
            <h3 class="empty-state-title">No providers found</h3>
            <p class="empty-state-desc">Try adjusting your filters or search terms.</p>
            <button class="btn btn--secondary btn--sm" onclick="clearDirectoryFilters()">Clear all filters</button>
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
              <label class="dir-filter-pill-label">Where in Lombok?</label>
              <select id="f-area" class="dir-filter-pill-select" onchange="updateDirectoryFilter('area', this.value)">
                <option value="">All Areas</option>
                ${buildAreaOptions(filters.area)}
              </select>
            </div>
            <div class="dir-filter-pill">
              <label class="dir-filter-pill-label">What specialty?</label>
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
                  <label class="filter-label">Language</label>
                  <select id="f-lang" class="filter-select" onchange="updateDirectoryFilter('languages', this.value)">
                    <option value="">Any language</option>
                    <option value="english" ${filters.languages === 'english' ? 'selected' : ''}>English</option>
                    <option value="bahasa" ${filters.languages === 'bahasa' ? 'selected' : ''}>Bahasa</option>
                  </select>
                </div>
                <div class="filter-group">
                  <label class="filter-label">Min Rating</label>
                  <select id="f-rating" class="filter-select" onchange="updateDirectoryFilter('min_rating', this.value)">
                    <option value="">Any</option>
                    <option value="4.0" ${filters.min_rating === '4.0' ? 'selected' : ''}>4.0+</option>
                    <option value="4.5" ${filters.min_rating === '4.5' ? 'selected' : ''}>4.5+</option>
                  </select>
                </div>
                <div class="filter-group">
                  <label class="filter-label">Status</label>
                  <select id="f-trusted" class="filter-select" onchange="updateDirectoryFilter('trusted', this.value)">
                    <option value="">All</option>
                    <option value="1" ${filters.trusted === '1' ? 'selected' : ''}>Trusted only</option>
                  </select>
                </div>
                <div class="filter-group">
                  <label class="filter-label">Sort</label>
                  <select class="filter-select" onchange="updateDirectoryFilter('sort', this.value)">
                    <option value="confidence" ${filters.sort === 'confidence' ? 'selected' : ''}>Most Trusted</option>
                    <option value="rating" ${filters.sort === 'rating' ? 'selected' : ''}>Highest Rated</option>
                    <option value="review_count" ${filters.sort === 'review_count' ? 'selected' : ''}>Most Reviewed</option>
                    <option value="alpha" ${filters.sort === 'alpha' ? 'selected' : ''}>A–Z</option>
                  </select>
                </div>
              </div>
              ${activeCount > 0 ? '<div style="margin-top:var(--space-3);text-align:right;"><button class="btn btn--ghost btn--sm" onclick="clearDirectoryFilters()">Clear all filters</button></div>' : ''}
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
  return `
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

  const devSpecialties = (dev.categories && dev.categories.length > 0) ? dev.categories.map(c => formatCategoryLabel(c.key || c)).join(', ') : 'Property Developer';
  const devHeroImg = dev.hero_image_url || '';
  el.innerHTML = `
    <div class="detail-hero">
      ${devHeroImg ? '<div class="detail-hero-bg" style="background-image:url(\'' + devHeroImg + '\');"></div>' : ''}
      <div class="container">
        <div class="detail-hero-inner">
          ${dev.logo_url ? '<img src="'+dev.logo_url+'" alt="'+dev.name+'" class="detail-hero-logo" onerror="this.style.display=\'none\'">' : (dev.profile_photo_url ? '<img src="'+dev.profile_photo_url+'" alt="'+dev.name+'" class="detail-hero-photo" onerror="this.style.display=\'none\'">' : '')}
          <div class="detail-hero-info">
            <div class="detail-hero-badges">
              ${dev.is_featured ? '<span class="badge badge--light">\u2605 Featured</span>' : ''}
              ${dev.badge ? '<span class="badge badge--light">' + renderBadge(dev.badge) + '</span>' : ''}
              ${dev.project_types.map(t => '<span class="badge badge--light">'+formatProjectType(t)+'</span>').join('')}
            </div>
            <h1 class="detail-hero-name">${dev.name}</h1>
          </div>
        </div>
      </div>
    </div>
    <div class="section">
      <div class="container">
        <div class="detail-subheading">
          <p class="detail-subheading-specialty">${devSpecialties}</p>
          <div class="detail-subheading-meta">
            ${dev.areas_focus.map(a => '<span>'+iconMapPin()+' '+formatAreaLabel(a)+'</span>').join('')}
            <span>${iconLang()} ${(dev.languages || 'Bahasa').split(/[,+]+/).map(function(s){return s.trim();}).filter(Boolean).join(' \u00b7 ')}</span>
            ${dev.min_ticket_usd ? '<span>From '+formatUSD(dev.min_ticket_usd)+'</span>' : ''}
          </div>
        </div>
        <div class="detail-layout">
          <div class="detail-main">
            <div class="detail-rating-row">
              ${renderGoogleRating(dev.google_rating, dev.google_review_count, 'detail')}
            </div>
            <h2 class="detail-section-title">About</h2>
            ${(dev.logo_url && dev.profile_photo_url) ? `<img src="${dev.profile_photo_url}" alt="${dev.name}" style="float:right;width:120px;height:120px;border-radius:var(--radius-md);object-fit:cover;margin:0 0 var(--space-4) var(--space-4);box-shadow:0 2px 8px rgba(0,0,0,.1);">` : ''}
            <p class="detail-description">${dev.description_en}</p>

            <h2 class="detail-section-title">Focus Areas</h2>
            <div class="detail-tags mb-6">
              ${dev.areas_focus.map(a => `<span class="tag">${formatAreaLabel(a)}</span>`).join('')}
              ${dev.tags.map(t => `<span class="tag">${t}</span>`).join('')}
            </div>

            <h2 class="detail-section-title" style="margin-bottom:var(--space-5);">Projects by ${dev.name}</h2>
            ${devProjects.length > 0
              ? `<div class="card-grid card-grid--2col">${devProjects.map((p, i) => renderProjectCard(p, i)).join('')}</div>`
              : `<p class="text-muted">No active projects listed yet.</p>`
            }
          </div>
          <div class="detail-sidebar">
            <div class="detail-card">
              <div class="detail-card-title">Contact</div>
              <div class="info-list mb-4">
                ${dev.phone ? `<div class="info-row"><span class="info-icon">${iconPhone()}</span><span class="info-value"><a href="tel:${dev.phone}">${dev.phone}</a></span></div>` : ''}
                ${dev.website_url ? `<div class="info-row"><span class="info-icon">${iconGlobe()}</span><span class="info-value"><a href="${dev.website_url}" target="_blank" rel="noopener noreferrer">Website ${iconExternalLink()}</a></span></div>` : ''}
                ${dev.google_maps_url ? `<div class="info-row"><span class="info-icon">${iconMapPin()}</span><span class="info-value"><a href="${dev.google_maps_url}" target="_blank" rel="noopener noreferrer">View on map ${iconExternalLink()}</a></span></div>` : ''}
              </div>
              ${renderSocialLinks(dev)}
              ${dev.whatsapp_number ? `<div style="margin-top:var(--space-4);"><a href="https://wa.me/${dev.whatsapp_number}" target="_blank" rel="noopener noreferrer" class="btn btn--whatsapp btn--full">${iconWhatsApp()} WhatsApp</a></div>` : ''}
              <div style="margin-top:var(--space-3);display:flex;align-items:center;justify-content:center;gap:var(--space-2);">${renderFavBtn('developer', dev.id)}<span style="font-size:var(--text-xs);color:var(--color-text-muted);">Save to favourites</span></div>
            </div>
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
  var priceStr = l.price_usd ? formatUSD(l.price_usd) : (l.price_idr ? formatIDR(l.price_idr) : 'Price on request');
  var sizeStr = formatLandSize(l.land_size_sqm, l.land_size_are);
  var typeLabel = l.listing_type_label || l.listing_type_key || '';
  var certLabel = l.certificate_type_label || '';
  var locationStr = l.location_detail || l.area_label || '';

  // If listing has source_url, open external site in new tab; otherwise navigate internally
  var linkHref = l.source_url ? l.source_url : '#listing/' + l.slug;
  var linkTarget = l.source_url ? ' target="_blank" rel="noopener noreferrer"' : '';
  var linkOnclick = l.source_url ? '' : ' onclick="navigate(\'listing/' + l.slug + '\');return false;"';
  var sourceTag = l.source_site ? '<span class="listing-card-source">' + l.source_site + ' <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg></span>' : '';

  return '<a href="' + linkHref + '" class="listing-card card"' + linkTarget + linkOnclick + ' style="animation-delay:' + (index * 60) + 'ms">'
    + '<div class="listing-card-image">'
    + (imgUrl ? '<img src="' + imgUrl + '" alt="' + (l.title || '').replace(/"/g, '&quot;') + '" loading="lazy" onload="this.classList.add(\'loaded\')">' : '<div class="listing-card-noimg"><svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="m21 15-5-5L5 21"/></svg></div>')
    + '<span class="listing-card-type">' + typeLabel + '</span>'
    + (l.is_featured ? '<span class="listing-card-featured">Featured</span>' : '')
    + '</div>'
    + '<div class="listing-card-body">'
    + '<div class="listing-card-price">' + priceStr + (l.price_label ? ' <span class="listing-card-price-note">' + l.price_label + '</span>' : '') + '</div>'
    + '<h3 class="listing-card-title">' + (l.title || '') + '</h3>'
    + '<div class="listing-card-meta">'
    + (sizeStr ? '<span>' + sizeStr + '</span>' : '')
    + (certLabel ? '<span>' + certLabel + '</span>' : '')
    + (l.bedrooms ? '<span>' + l.bedrooms + ' bed</span>' : '')
    + (l.bathrooms ? '<span>' + l.bathrooms + ' bath</span>' : '')
    + '</div>'
    + '<div class="listing-card-location"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg> ' + locationStr + '</div>'
    + (l.agent_name ? '<div class="listing-card-agent">' + l.agent_name + '</div>' : '')
    + sourceTag
    + '</div>'
    + '</a>';
}

// =====================================================
// RENDER: LISTINGS (Find Land / Property)
// =====================================================

async function renderListings(el, params = {}) {
  await FilterData.load();

  const filters = { ...params };
  // Parse region from area value
  if (filters.area && filters.area.startsWith('region:')) {
    filters.region = filters.area.replace('region:', '');
    delete filters.area;
  }
  const [listRes] = await Promise.all([
    DataLayer.getListings(filters),
  ]);

  const listings = listRes.data;
  const total = listRes.meta.total;

  const areaOptions = buildAreaOptions(params.area || '');
  const typeOptions = FilterData.listing_types.map(t => '<option value="' + t.key + '" ' + (params.listing_type === t.key ? 'selected' : '') + '>' + t.label + '</option>').join('');
  const certOptions = FilterData.land_certificate_types.map(c => '<option value="' + c.key + '" ' + (params.certificate_type === c.key ? 'selected' : '') + '>' + c.label + '</option>').join('');

  // Count active "more" filters
  var moreActiveCount = 0;
  if (params.certificate_type) moreActiveCount++;
  if (params.min_beds) moreActiveCount++;
  if (params.min_baths) moreActiveCount++;
  if (params.q) moreActiveCount++;
  var anyFilterActive = params.listing_type || params.area || params.min_price_idr || params.max_price_idr || params.min_size || params.max_size || moreActiveCount > 0;

  el.innerHTML = `
    <div class="dir-hero">
      <div class="container">
        <h1 class="dir-hero-title">Find Land & Property</h1>
        <p class="dir-hero-desc">Discover your dream location across Lombok — land, villas, and investment properties.</p>
      </div>
    </div>
    <div class="section">
      <div class="container">
        <div class="filters-bar">
          <div class="filters-body open">
            <div class="filters-grid filters-grid--4">
              <div class="filter-group">
                <label class="filter-label">Type</label>
                <select id="fil-type" class="filter-select" aria-label="Property type">
                  <option value="">All types</option>
                  ${typeOptions}
                </select>
              </div>
              <div class="filter-group">
                <label class="filter-label">Area</label>
                <select id="fil-area" class="filter-select" aria-label="Area">
                  <option value="">All areas</option>
                  ${areaOptions}
                </select>
              </div>
              <div class="filter-group">
                <label class="filter-label">Price (IDR)</label>
                <select id="fil-price" class="filter-select" aria-label="Price range">
                  <option value="">Any price</option>
                  <option value="0-500000000" ${params.max_price_idr === '500000000' ? 'selected' : ''}>Under 500 Juta</option>
                  <option value="500000000-1000000000" ${params.min_price_idr === '500000000' && params.max_price_idr === '1000000000' ? 'selected' : ''}>500 Jt - 1 M</option>
                  <option value="1000000000-3000000000" ${params.min_price_idr === '1000000000' && params.max_price_idr === '3000000000' ? 'selected' : ''}>1 - 3 Miliar</option>
                  <option value="3000000000-5000000000" ${params.min_price_idr === '3000000000' && params.max_price_idr === '5000000000' ? 'selected' : ''}>3 - 5 Miliar</option>
                  <option value="5000000000-10000000000" ${params.min_price_idr === '5000000000' && params.max_price_idr === '10000000000' ? 'selected' : ''}>5 - 10 Miliar</option>
                  <option value="10000000000-0" ${params.min_price_idr === '10000000000' && !params.max_price_idr ? 'selected' : ''}>10 Miliar+</option>
                </select>
              </div>
              <div class="filter-group">
                <label class="filter-label">Land Size</label>
                <select id="fil-size" class="filter-select" aria-label="Land size">
                  <option value="">Any size</option>
                  <option value="0-100" ${params.max_size === '100' ? 'selected' : ''}>Under 100 m&sup2;</option>
                  <option value="100-500" ${params.min_size === '100' && params.max_size === '500' ? 'selected' : ''}>100 - 500 m&sup2;</option>
                  <option value="500-1000" ${params.min_size === '500' && params.max_size === '1000' ? 'selected' : ''}>500 - 1,000 m&sup2;</option>
                  <option value="1000-5000" ${params.min_size === '1000' && params.max_size === '5000' ? 'selected' : ''}>1,000 - 5,000 m&sup2;</option>
                  <option value="5000-10000" ${params.min_size === '5000' && params.max_size === '10000' ? 'selected' : ''}>5,000 - 10,000 m&sup2;</option>
                  <option value="10000-0" ${params.min_size === '10000' && !params.max_size ? 'selected' : ''}>10,000+ m&sup2;</option>
                </select>
              </div>
            </div>
            <div class="filters-more-row">
              <button class="btn btn--ghost btn--sm filters-more-btn" id="moreFiltersBtn" type="button">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="4" y1="6" x2="20" y2="6"/><line x1="8" y1="12" x2="16" y2="12"/><line x1="11" y1="18" x2="13" y2="18"/></svg>
                More Filters${moreActiveCount > 0 ? ' (' + moreActiveCount + ')' : ''}
              </button>
              ${anyFilterActive ? '<button class="btn btn--ghost btn--sm" type="button" id="clearAllFiltersBtn" style="margin-left:auto;color:var(--color-accent);">Clear all</button>' : ''}
            </div>
            <div class="filters-more-panel" id="moreFiltersPanel">
              <div class="filters-grid filters-grid--3">
                <div class="filter-group">
                  <label class="filter-label">Certificate</label>
                  <select id="fil-cert" class="filter-select" aria-label="Certificate">
                    <option value="">Any certificate</option>
                    ${certOptions}
                  </select>
                </div>
                <div class="filter-group">
                  <label class="filter-label">Bedrooms</label>
                  <select id="fil-beds" class="filter-select" aria-label="Bedrooms">
                    <option value="">Any</option>
                    <option value="1" ${params.min_beds === '1' ? 'selected' : ''}>1+</option>
                    <option value="2" ${params.min_beds === '2' ? 'selected' : ''}>2+</option>
                    <option value="3" ${params.min_beds === '3' ? 'selected' : ''}>3+</option>
                    <option value="4" ${params.min_beds === '4' ? 'selected' : ''}>4+</option>
                    <option value="5" ${params.min_beds === '5' ? 'selected' : ''}>5+</option>
                  </select>
                </div>
                <div class="filter-group">
                  <label class="filter-label">Bathrooms</label>
                  <select id="fil-baths" class="filter-select" aria-label="Bathrooms">
                    <option value="">Any</option>
                    <option value="1" ${params.min_baths === '1' ? 'selected' : ''}>1+</option>
                    <option value="2" ${params.min_baths === '2' ? 'selected' : ''}>2+</option>
                    <option value="3" ${params.min_baths === '3' ? 'selected' : ''}>3+</option>
                    <option value="4" ${params.min_baths === '4' ? 'selected' : ''}>4+</option>
                  </select>
                </div>
              </div>
              <div class="filter-group" style="margin-top:var(--space-3)">
                <label class="filter-label">Features</label>
                <div class="filter-tags" id="fil-tags">
                  ${['Ocean View','Beachfront','Pool','Furnished','Cliff Top','Rice Field View','Near Airport','Freehold (SHM)'].map(function(tag) {
                    var tagKey = tag.toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/_$/, '');
                    var isActive = params.q && params.q.toLowerCase().indexOf(tag.toLowerCase()) >= 0;
                    return '<button type="button" class="filter-tag' + (isActive ? ' active' : '') + '" data-tag="' + tag + '">' + tag + '</button>';
                  }).join('')}
                </div>
              </div>
            </div>
          </div>
        </div>
        <div class="card-grid listings-grid" id="listings-grid">
          ${listings.length > 0
            ? listings.map((l, i) => renderListingCard(l, i)).join('')
            : '<div class="empty-state"><h3 class="empty-state-title">No listings found</h3><p class="empty-state-desc">Try adjusting your filters or check back soon for new properties.</p></div>'}
        </div>
        ${total > (listRes.meta.per_page || 20) ? (
          '<div class="pagination" style="margin-top:var(--space-8);text-align:center;">' +
          Array.from({length: listRes.meta.total_pages || 1}, (_, i) =>
            '<button class="pagination-btn ' + ((listRes.meta.page || 1) === i+1 ? 'active' : '') + '" onclick="applyListingFilters(' + (i+1) + ')">' + (i+1) + '</button>'
          ).join('') +
          '</div>'
        ) : ''}
      </div>
    </div>

    ${UserAuth.user ? `
    <div class="section" style="padding-top:0">
      <div class="container container--narrow" style="text-align:center">
        <div class="help-cta">
          <h3 class="help-cta-title">Are you an agent?</h3>
          <p class="help-cta-desc">List your properties on Build in Lombok and reach foreign investors.</p>
          <button onclick="navigate('create-listing')" class="btn btn--primary">Post a Listing</button>
        </div>
      </div>
    </div>
    ` : ''}
  `;

  function applyFilters(page) {
    var type = el.querySelector('#fil-type').value;
    var area = el.querySelector('#fil-area').value;
    var cert = el.querySelector('#fil-cert') ? el.querySelector('#fil-cert').value : '';
    var beds = el.querySelector('#fil-beds') ? el.querySelector('#fil-beds').value : '';
    var baths = el.querySelector('#fil-baths') ? el.querySelector('#fil-baths').value : '';
    var priceRange = el.querySelector('#fil-price').value;
    var sizeRange = el.querySelector('#fil-size').value;

    // Collect active feature tags
    var activeTags = [];
    var tagBtns = el.querySelectorAll('#fil-tags .filter-tag.active');
    for (var t = 0; t < tagBtns.length; t++) {
      activeTags.push(tagBtns[t].getAttribute('data-tag'));
    }

    var p = {};
    if (type) p.listing_type = type;
    if (area) p.area = area;
    if (cert) p.certificate_type = cert;
    if (beds) p.min_beds = beds;
    if (baths) p.min_baths = baths;
    if (priceRange) {
      var pParts = priceRange.split('-');
      if (pParts[0] && pParts[0] !== '0') p.min_price_idr = pParts[0];
      if (pParts[1] && pParts[1] !== '0') p.max_price_idr = pParts[1];
    }
    if (sizeRange) {
      var sParts = sizeRange.split('-');
      if (sParts[0] && sParts[0] !== '0') p.min_size = sParts[0];
      if (sParts[1] && sParts[1] !== '0') p.max_size = sParts[1];
    }
    if (activeTags.length > 0) p.q = activeTags.join(' ');
    if (page && page > 1) p.page = page;
    navigate(buildHash('listings', p));
  }

  window.applyListingFilters = applyFilters;

  // Main filter selects
  ['fil-type', 'fil-area', 'fil-price', 'fil-size'].forEach(function(id) {
    var sel = el.querySelector('#' + id);
    if (sel) sel.addEventListener('change', function() { applyFilters(1); });
  });

  // More filters selects
  ['fil-cert', 'fil-beds', 'fil-baths'].forEach(function(id) {
    var sel = el.querySelector('#' + id);
    if (sel) sel.addEventListener('change', function() { applyFilters(1); });
  });

  // More Filters toggle
  var moreBtn = el.querySelector('#moreFiltersBtn');
  var morePanel = el.querySelector('#moreFiltersPanel');
  if (moreBtn && morePanel) {
    // Auto-open if more filters are active
    if (moreActiveCount > 0) morePanel.classList.add('open');
    moreBtn.addEventListener('click', function() {
      morePanel.classList.toggle('open');
      moreBtn.classList.toggle('active');
    });
  }

  // Clear all filters
  var clearBtn = el.querySelector('#clearAllFiltersBtn');
  if (clearBtn) {
    clearBtn.addEventListener('click', function() { navigate('listings'); });
  }

  // Feature tag toggles
  var tagContainer = el.querySelector('#fil-tags');
  if (tagContainer) {
    tagContainer.addEventListener('click', function(e) {
      var btn = e.target.closest('.filter-tag');
      if (!btn) return;
      btn.classList.toggle('active');
      applyFilters(1);
    });
  }

  requestAnimationFrame(() => animateCards(el));
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
  const priceStr = listing.price_usd ? formatUSD(listing.price_usd) : (listing.price_idr ? formatIDR(listing.price_idr) : 'Price on request');
  const sizeStr = formatLandSize(listing.land_size_sqm, listing.land_size_are);
  const typeLabel = listing.listing_type_label || '';
  const certLabel = listing.certificate_type_label || '';
  const wa = listing.contact_whatsapp || listing.agent_whatsapp || '';

  el.innerHTML = `
    <div class="page-header">
      <div class="container">
      </div>
    </div>
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

            <h1 class="listing-detail-title">${listing.title}</h1>
            <div class="listing-detail-price">${priceStr}${listing.price_label ? ' <span class="price-note">' + listing.price_label + '</span>' : ''}</div>

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

            <div class="listing-detail-desc">
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

function renderAgentCard(agent, index = 0) {
  return `
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
        </div>
        ${agent.bio ? '<p class="agent-card-bio">' + (agent.bio.length > 120 ? agent.bio.substring(0, 120) + '...' : agent.bio) + '</p>' : ''}
      </div>
    </a>
  `;
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
    <div class="detail-hero">
      <div class="container">
        <div class="detail-hero-inner">
          ${agent.profile_photo_url ? '<img src="' + agent.profile_photo_url + '" alt="' + agent.display_name + '" class="detail-hero-photo" style="border-radius:50%;" onerror="this.style.display=\'none\'">' : '<div style="width:100px;height:100px;border-radius:50%;background:rgba(12,124,132,0.5);display:flex;align-items:center;justify-content:center;font-size:2.5rem;font-weight:700;color:#fff;flex-shrink:0;">' + agent.display_name.charAt(0).toUpperCase() + '</div>'}
          <div class="detail-hero-info">
            <div class="detail-hero-badges">
              ${agent.is_verified ? '<span class="badge badge--trusted-light">\u2713 Verified Agent</span>' : ''}
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

  return `
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
          <span class="card-fact-value">${formatUSD(p.min_investment_usd)}</span>
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
        <p class="results-count" id="proj-count"></p>
        <div class="card-grid" id="project-grid"></div>
      </div>
    </div>
  `;

  window.updateProjectFilter = function(key, value) {
    filters[key] = value;
    applyAndRender();
  };

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
            <div class="key-fact-value">${formatUSD(p.min_investment_usd)}</div>
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
// GLOBAL SEARCH
// =====================================================

function initSearch() {
  const inputs = document.querySelectorAll('.nav-search-input, #hero-search');
  const wrapper = document.querySelector('.nav-search-wrapper');
  if (!wrapper) return;

  let dropdown = wrapper.querySelector('.search-dropdown');
  if (!dropdown) {
    dropdown = document.createElement('div');
    dropdown.className = 'search-dropdown';
    wrapper.appendChild(dropdown);
  }

  inputs.forEach(input => {
    input.addEventListener('input', async () => {
      const q = input.value.trim().toLowerCase();
      if (q.length < 2) { dropdown.classList.remove('visible'); return; }

      let provMatches = [], devMatches = [], projMatches = [];
      try {
        const results = await DataLayer.search(q);
        provMatches = results.filter(r => r.type === 'provider').slice(0, 3);
        devMatches = results.filter(r => r.type === 'developer').slice(0, 2);
        projMatches = results.filter(r => r.type === 'project').slice(0, 2);
      } catch(e) { console.error('Search error:', e); }

      const total = provMatches.length + devMatches.length + projMatches.length;

      if (total === 0) {
        dropdown.innerHTML = `<div class="search-no-results">No results for "<strong>${q}</strong>"</div>`;
      } else {
        let html = '';
        if (provMatches.length) {
          html += `<div class="search-group-label">Providers</div>`;
          html += provMatches.map(b => `
            <div class="search-result-item" data-nav="provider/${b.slug}" tabindex="0">
              <div>
                <div class="search-result-item-name">${b.name}</div>
                <div class="search-result-item-meta">${b.excerpt || ''}</div>
              </div>
            </div>
          `).join('');
        }
        if (devMatches.length) {
          html += `<div class="search-group-label">Developers</div>`;
          html += devMatches.map(d => `
            <div class="search-result-item" data-nav="developer/${d.slug}" tabindex="0">
              <div>
                <div class="search-result-item-name">${d.name}</div>
                <div class="search-result-item-meta">${d.excerpt || ''}</div>
              </div>
            </div>
          `).join('');
        }
        if (projMatches.length) {
          html += `<div class="search-group-label">Projects</div>`;
          html += projMatches.map(p => `
            <div class="search-result-item" data-nav="project/${p.slug}" tabindex="0">
              <div>
                <div class="search-result-item-name">${p.name}</div>
                <div class="search-result-item-meta">${p.excerpt || ''}</div>
              </div>
            </div>
          `).join('');
        }
        dropdown.innerHTML = html;
      }

      // Fix onclick references
      dropdown.querySelectorAll('.search-result-item').forEach(item => {
        item.addEventListener('click', function() {
          const nav = this.getAttribute('data-nav');
          if (nav) navigate(nav);
          inputs.forEach(i => i.value = '');
          if (dropdown) dropdown.classList.remove('visible');
        });
      });

      dropdown.classList.add('visible');
    });

    input.addEventListener('blur', () => {
      setTimeout(() => dropdown.classList.remove('visible'), 200);
    });
  });

  document.addEventListener('click', e => {
    if (!wrapper.contains(e.target)) dropdown.classList.remove('visible');
  });

  // Mobile search: Enter key navigates to directory with search
  const mobileSearch = document.getElementById('mobile-search');
  if (mobileSearch) {
    mobileSearch.addEventListener('keydown', (e) => {
      if (e.key === 'Enter') {
        const q = mobileSearch.value.trim();
        if (q.length >= 2) {
          // Close mobile menu
          const mobileMenu = document.getElementById('mobile-menu');
          const hamburger = document.getElementById('hamburger-btn');
          if (mobileMenu) mobileMenu.classList.remove('open');
          if (hamburger) hamburger.setAttribute('aria-expanded', 'false');
          // Navigate to directory with search
          window.location.hash = 'directory?search=' + encodeURIComponent(q);
          mobileSearch.value = '';
        }
      }
    });
  }
}

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
      if (currentUser) await loadFavorites();
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
      btn.innerHTML = `<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg> Sign In`;
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
    + '<div class="rab-hero">'
    + '  <div class="container">'
    + '    <div class="rab-hero-content">'
    + '      <p class="rab-hero-eyebrow">RAB Cost Tools</p>'
    + '      <h1 class="rab-hero-title">Building Cost Calculator</h1>'
    + '      <p class="rab-hero-subtitle">Get an instant cost estimate for your Lombok villa or home. Choose your building type, quality level, and optional extras to see an estimate in seconds.</p>'
    + '      <div class="rab-hero-tabs">'
    + '        <a href="#rab-calculator" class="rab-tab active">Calculator</a>'
    + '        <a href="#rab-estimates" class="rab-tab" onclick="navigate(\'rab-estimates\');return false;">My Saved Estimates</a>'
    + '      </div>'
    + '    </div>'
    + '  </div>'
    + '</div>'
    + '<div class="section">'
    + '  <div class="container">'
    + (presets.length === 0
       ? '<div class="card" style="padding:var(--space-8);text-align:center;"><p style="color:var(--color-text-faint)">No calculator presets available yet. Please check back soon.</p></div>'
       : '<div class="rab-calc-layout">' + buildCalcForm(presets, defaultPreset) + buildPresetSidebar(defaultPreset) + '</div>')
    + '  </div>'
    + '</div>';

  if (presets.length === 0) return;

  // Wire up events
  var form = el.querySelector('#rab-calc-form');
  var presetSel = el.querySelector('#rab-preset-sel');
  var storeysSel = el.querySelector('#rab-storeys-sel');

  if (presetSel) {
    presetSel.addEventListener('change', function() {
      var pid = parseInt(this.value);
      var p = presets.find(function(x) { return x.id == pid; });
      if (p) updatePresetSidebar(p);
    });
  }

  if (storeysSel) {
    storeysSel.addEventListener('change', function() {
      var n = parseInt(this.value);
      for (var i = 2; i <= 4; i++) {
        var wrap = el.querySelector('#rab-fa-' + i);
        if (wrap) wrap.style.display = i <= n ? '' : 'none';
      }
    });
  }

  // Toggle optional fields
  var ckRooftop = el.querySelector('#rab-ck-rooftop');
  if (ckRooftop) {
    ckRooftop.addEventListener('change', function() {
      el.querySelector('#rab-rooftop-wrap').style.display = this.checked ? '' : 'none';
    });
  }
  var ckPool = el.querySelector('#rab-ck-pool');
  if (ckPool) {
    ckPool.addEventListener('change', function() {
      el.querySelector('#rab-pool-wrap').style.display = this.checked ? '' : 'none';
    });
  }

  // Quality radio styling
  el.querySelectorAll('.rab-quality-opt input[type=radio]').forEach(function(r) {
    r.addEventListener('change', function() {
      el.querySelectorAll('.rab-quality-opt').forEach(function(o) { o.classList.remove('selected'); });
      if (r.checked) r.closest('.rab-quality-opt').classList.add('selected');
    });
    if (r.checked) r.closest('.rab-quality-opt').classList.add('selected');
  });

  // Form submit
  if (form) {
    form.addEventListener('submit', function(e) {
      e.preventDefault();
      runRABCalculation(el, form, presets);
    });
  }
}

function buildCalcForm(presets, dp) {
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
    + '<div class="rab-rate-row"><span>Economy (m\u00B2)</span><span class="rab-rate-val">' + fmtIDR(dp.base_cost_per_m2_low) + '</span></div>'
    + '<div class="rab-rate-row"><span>Standard (m\u00B2)</span><span class="rab-rate-val">' + fmtIDR(dp.base_cost_per_m2_mid) + '</span></div>'
    + '<div class="rab-rate-row"><span>Premium (m\u00B2)</span><span class="rab-rate-val">' + fmtIDR(dp.base_cost_per_m2_high) + '</span></div>'
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
      + '<div class="rab-hero">'
      + '  <div class="container">'
      + '    <div class="rab-hero-content">'
      + '      <p class="rab-hero-eyebrow">RAB Cost Tools</p>'
      + '      <h1 class="rab-hero-title">My Saved Estimates</h1>'
      + '      <p class="rab-hero-subtitle">Sign in to view and manage your saved cost estimates.</p>'
      + '    </div>'
      + '  </div>'
      + '</div>'
      + '<div class="section"><div class="container" style="text-align:center;padding:var(--space-12) 0;">'
      + '  <button class="btn btn--primary" onclick="showAuthModal(\'login\')">Sign In to View Estimates</button>'
      + '</div></div>';
    return;
  }

  el.innerHTML = ''
    + '<div class="rab-hero">'
    + '  <div class="container">'
    + '    <div class="rab-hero-content">'
    + '      <p class="rab-hero-eyebrow">RAB Cost Tools</p>'
    + '      <h1 class="rab-hero-title">My Saved Estimates</h1>'
    + '      <p class="rab-hero-subtitle">View and manage your saved building cost estimates.</p>'
    + '      <div class="rab-hero-tabs">'
    + '        <a href="#rab-calculator" class="rab-tab" onclick="navigate(\'rab-calculator\');return false;">Calculator</a>'
    + '        <a href="#rab-estimates" class="rab-tab active">My Saved Estimates</a>'
    + '      </div>'
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

    var ql_map = { low: 'Economy', mid: 'Standard', high: 'Premium' };
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
    + '<div class="rab-hero">'
    + '  <div class="container">'
    + '    <div class="rab-hero-content">'
    + '      <p class="rab-hero-eyebrow">RAB Cost Tools</p>'
    + '      <h1 class="rab-hero-title">Estimate Detail</h1>'
    + '    </div>'
    + '  </div>'
    + '</div>'
    + '<div class="section"><div class="container"><div id="rab-detail-area"><div class="page-loading"><div class="page-loading-spinner"></div></div></div></div></div>';

  try {
    var res = await fetch(RAB_API + '?action=estimate&id=' + id, { credentials: 'include' });
    var json = await res.json();
    if (!res.ok) throw new Error(json.error || 'Not found');
    var r = json.data;

    var ql_map = { low: 'Economy', mid: 'Standard', high: 'Premium' };
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

  // Search
  initSearch();

  // Check user session
  UserAuth.checkSession();

  // Router
  window.addEventListener('hashchange', router);
  router();
}
