/* =====================================================================
 * zoning.js — Zoning & Land Check frontend (ADR 0013)
 * Build in Lombok · vanilla JS · no framework · no build step · PHP 7.4 backend
 *
 * Backend contract: api/zoning_api.php   (base = ZONING_API below)
 * Map: its OWN Leaflet map (Esri satellite + OSM/Photon geocode), entirely
 * separate from the listings SVG region map (ADR 0005).
 *
 * Exposes the global render functions the app.js SPA router calls:
 *   renderZoningCheck(view, params)         -> route #zoning
 *   renderZoningReport(view, params)        -> route #zoning-report?id=..&token=..
 *
 * Conventions mirrored from app.js / drab.js: escHtml / navigate / showToast /
 * t / getCurrentLang / UserAuth / showAuthModal are app.js globals; local
 * fallbacks are defined defensively.
 * ===================================================================== */

var ZONING_API = '/api/zoning_api.php';
var ZState = { meta: null, map: null, marker: null, parcelLayer: null, lat: null, lng: null,
               label: null, nib: null, lastTriage: null, csrf: '', geoTimer: null,
               overlay: null, overlayOn: true, overlayTimer: null };

/* Per-class fill colours for the zoning overlay. Temperature follows buildability
 * (greens = permitted, yellows/olives = restricted, reds = prohibited) while each
 * class is still visually distinct. Zona Hijau / forest / parks read RED (no-build),
 * deliberately overriding the "green = nature" intuition (the colour-trap rule). */
var ZCLASS_COLORS = {
  pariwisata: '#17a89a', permukiman: '#3f9b4f', perdagangan_jasa: '#b8902b',
  pertanian: '#c7b13e', perkebunan: '#7e8b2c', industri: '#7a7a85',
  fasilitas: '#5b7a99', rawan_bencana: '#d2762a',
  sempadan: '#d0592e', hutan_lindung: '#b3261e', hutan_produksi: '#9a3b2e',
  hijau: '#b3261e', rth: '#cf6a5a', konservasi: '#8a1e1e', badan_air: '#3a6ea5',
  unknown: '#9a9a9a'
};
function zClassColor(k) { return ZCLASS_COLORS[k] || '#9a9a9a'; }

/* ---- defensive helpers (reuse app.js globals when present) ---- */
function zEsc(s){ if (typeof escHtml==='function') return escHtml(s==null?'':s); var d=document.createElement('div'); d.textContent=(s==null?'':String(s)); return d.innerHTML; }
function zNav(h){ if (typeof navigate==='function') return navigate(h); window.location.hash=h; }
function zToast(m,t){ if (typeof showToast==='function') return showToast(m,t); if(t==='error')console.error(m);else console.log(m); }
function zT(k,f){ if (typeof t==='function') return t(k,f); return f!==undefined?f:k; }
function zLang(){ if (typeof getCurrentLang==='function') return getCurrentLang(); return 'en'; }
function zLoggedIn(){ return !!(typeof UserAuth!=='undefined' && UserAuth && UserAuth.user); }
function zLogin(){ if (typeof showAuthModal==='function') showAuthModal('login'); else zNav('account'); }
function zIDR(v){ if (typeof fmtIDR==='function') return fmtIDR(v); if(!v&&v!==0) return 'Rp 0'; return 'Rp '+Math.round(Number(v)).toLocaleString('id-ID'); }
function zName(row, key){ key=key||'name'; var lang=zLang(); var en=row[key+'_en']||'', id=row[key+'_id']||''; if(lang==='id') return id||en; if(lang==='both') return (id&&en&&id!==en)?(en+' / '+id):(en||id); return en||id; }
function zSpinner(){ return '<div class="page-loading"><div class="page-loading-spinner"></div></div>'; }

function zGet(action, params){
  var url = ZONING_API + '?action=' + encodeURIComponent(action);
  if (params) Object.keys(params).forEach(function(k){ var v=params[k]; if(v!==undefined&&v!==null&&v!=='') url+='&'+encodeURIComponent(k)+'='+encodeURIComponent(v); });
  return fetch(url, { credentials:'include' }).then(function(r){ return r.json().then(function(j){return {status:r.status,json:j};}).catch(function(){return {status:r.status,json:null};}); });
}
function zPost(action, body){
  return fetch(ZONING_API + '?action=' + encodeURIComponent(action), {
    method:'POST', credentials:'include',
    headers:{ 'Content-Type':'application/json', 'X-CSRF-Token': ZState.csrf || '' },
    body: JSON.stringify(body||{})
  }).then(function(r){ return r.json().then(function(j){return {status:r.status,json:j};}).catch(function(){return {status:r.status,json:null};}); });
}
function zUpload(form){
  return fetch(ZONING_API + '?action=upload_cert', {
    method:'POST', credentials:'include', headers:{ 'X-CSRF-Token': ZState.csrf || '' }, body: form
  }).then(function(r){ return r.json().then(function(j){return {status:r.status,json:j};}).catch(function(){return {status:r.status,json:null};}); });
}

function zEnsureMeta(){
  if (ZState.meta) return Promise.resolve(ZState.meta);
  return zGet('meta').then(function(res){ if(res.json){ ZState.meta=res.json; ZState.csrf=res.json.csrf||''; } return ZState.meta; });
}

/* status -> colour family (drives the traffic light; Zona Hijau is 'prohibited' => red) */
function zStatusInfo(status){
  switch(status){
    case 'permitted':  return { cls:'ok',   dot:'#2e7d32', en:'Permitted', id:'Diizinkan' };
    case 'restricted': return { cls:'warn', dot:'#c77700', en:'Restricted', id:'Dibatasi' };
    case 'prohibited': return { cls:'bad',  dot:'#b3261e', en:'Prohibited', id:'Dilarang' };
    default:           return { cls:'unk',  dot:'#777',    en:'Not Yet Mapped', id:'Belum Terpetakan' };
  }
}
function zStatusLabel(status){ var i=zStatusInfo(status); return zLang()==='id'?i.id:i.en; }

/* =====================================================================
 * ROUTE: #zoning  — Map Input + Triage
 * ===================================================================== */
function renderZoningCheck(view, params){
  view.innerHTML = zSpinner();
  return zEnsureMeta().then(function(meta){
    if (!meta){ view.innerHTML = '<div class="page-view"><p style="padding:40px;text-align:center">'+zEsc(zT('common.error','Something went wrong.'))+'</p></div>'; return; }
    var lang = zLang();
    var note = lang==='id' ? meta.coverage_note_id : meta.coverage_note_en;
    view.innerHTML =
      '<section class="zlc-hero">' +
        '<div class="zlc-hero-inner">' +
          '<p class="zlc-kicker">'+zEsc(zT('zoning.kicker','LAND INTELLIGENCE'))+'</p>' +
          '<h1 class="zlc-title">'+zEsc(zT('zoning.title','Zoning & Land Check'))+'</h1>' +
          '<p class="zlc-sub">'+zEsc(zT('zoning.sub','Drop a pin, search a landmark, or enter coordinates to see instantly whether a Lombok plot is legally buildable.'))+'</p>' +
        '</div>' +
      '</section>' +
      '<section class="zlc-stage">' +
        '<div class="zlc-mapwrap">' +
          '<div class="zlc-searchbar">' +
            '<input id="zlc-search" type="text" autocomplete="off" placeholder="'+zEsc(zT('zoning.search_ph','Search a place or landmark (e.g. Villa Ellya, Kuta)'))+'">' +
            '<button id="zlc-locate" class="zlc-iconbtn" title="'+zEsc(zT('zoning.use_location','Use my location'))+'" aria-label="'+zEsc(zT('zoning.use_location','Use my location'))+'">◎</button>' +
            '<div id="zlc-suggest" class="zlc-suggest" hidden></div>' +
          '</div>' +
          '<div id="zlc-map" class="zlc-map"></div>' +
          '<div id="zlc-legend" class="zlc-legend"></div>' +
          '<div class="zlc-maptools">' +
            '<label class="zlc-chip zlc-toggle"><input type="checkbox" id="zlc-overlay-toggle" checked> '+zEsc(zT('zoning.overlay','Zoning colours'))+'</label>' +
            '<button id="zlc-coordbtn" class="zlc-chip">'+zEsc(zT('zoning.enter_coords','Enter coordinates'))+'</button>' +
            (meta.map.bhumi_wms_url ? '<label class="zlc-chip zlc-toggle"><input type="checkbox" id="zlc-parcels"> '+zEsc(zT('zoning.show_parcels','Show land parcels'))+'</label>' : '') +
          '</div>' +
        '</div>' +
        '<aside id="zlc-panel" class="zlc-panel">' +
          '<div class="zlc-panel-empty">' +
            '<div class="zlc-bigdot zlc-bigdot-unk"></div>' +
            '<p class="zlc-empty-lead">'+zEsc(zT('zoning.empty_lead','No location selected yet.'))+'</p>' +
            '<p class="zlc-empty-sub">'+zEsc(zT('zoning.empty_sub','Tap the map, search above, or use your location to get an instant buildability check.'))+'</p>' +
            (note ? '<p class="zlc-coverage">'+zEsc(note)+'</p>' : '') +
          '</div>' +
        '</aside>' +
      '</section>';

    // The SPA router appends this `view` to the DOM only AFTER renderZoningCheck
    // resolves, so #zlc-map is detached right now. Defer init until it is attached
    // and has a height, otherwise Leaflet initialises on a zero-size node (blank
    // map, no tiles) and the input handlers bind to nothing.
    zDeferMapInit(meta, params, 0);
  });
}

function zDeferMapInit(meta, params, tries){
  var el = document.getElementById('zlc-map');
  if (el && el.isConnected && el.offsetHeight > 0) {
    zInitMap(meta);
    zWireInputs(meta);
    zBuildLegend();
    zSettleMap();
    if (params && params.lat && params.lng) {
      zSelectPoint(parseFloat(params.lat), parseFloat(params.lng), params.label||null, true);
    }
    return;
  }
  if (tries > 60) { // ~1s elapsed — init anyway so the page is not dead
    zInitMap(meta); zWireInputs(meta); zBuildLegend(); zSettleMap();
    return;
  }
  requestAnimationFrame(function(){ zDeferMapInit(meta, params, tries + 1); });
}

function zInitMap(meta){
  var el = document.getElementById('zlc-map');
  if (!el || typeof L === 'undefined') { if(el) el.innerHTML='<p style="padding:20px">Map unavailable.</p>'; return; }
  // fadeAnimation:false — the tile fade-in can stick at opacity:0 when the map is
  // initialised via the deferred/invalidateSize path (SPA), leaving tiles loaded
  // but invisible. Disabling the fade paints them at full opacity immediately.
  var map = L.map(el, { zoomControl:true, attributionControl:true, fadeAnimation:false }).setView(meta.map.center, meta.map.zoom);
  ZState.map = map;
  if (meta.map.satellite_url) {
    L.tileLayer(meta.map.satellite_url, { maxZoom: meta.map.max_zoom||19, attribution: meta.map.satellite_attr||'' }).addTo(map);
  } else {
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom:19, attribution:'© OpenStreetMap' }).addTo(map);
  }
  if (meta.map.labels_url) {
    L.tileLayer(meta.map.labels_url, { maxZoom: meta.map.max_zoom||19, opacity:0.9 }).addTo(map);
  }
  map.on('click', function(e){ zSelectPoint(e.latlng.lat, e.latlng.lng, null, false); });
  map.on('moveend', function(){ if (!ZState.overlayOn) return; clearTimeout(ZState.overlayTimer); ZState.overlayTimer = setTimeout(zLoadOverlay, 400); });
  // The container reaches its final size only after layout/fonts settle; Leaflet
  // caches an intermediate size at init, which mis-projects the vector overlay.
  // A ResizeObserver re-invalidates on every size change (its moveend reloads the
  // overlay aligned) — robust against layout settle, font load, rotate, resize.
  if (typeof ResizeObserver !== 'undefined') {
    var ro = new ResizeObserver(function(){ try { map.invalidateSize(); } catch(e){} });
    ro.observe(el);
  }
}

/* Re-sync map size + overlay across the load window. The container only reaches
 * its final size after layout/fonts/infobars settle; Leaflet caches an earlier
 * size, which mis-projects the vector overlay. Staggered invalidateSize + reload
 * passes guarantee the overlay lands aligned (the ResizeObserver covers later). */
function zSettleMap(){
  // Watchdog: the container reaches its final size at an unpredictable moment
  // after load (viewport/infobar/font settle), and Leaflet caches the earlier
  // size, mis-projecting the overlay. Poll briefly and, whenever Leaflet's cached
  // size disagrees with the real container size, invalidateSize + reload aligned.
  try { ZState.map.invalidateSize(); if (ZState.overlayOn) zLoadOverlay(); } catch(e){}
  var tries = 0;
  var iv = setInterval(function(){
    tries++;
    if (!ZState.map) { clearInterval(iv); return; }
    var el = document.getElementById('zlc-map');
    if (!el) { clearInterval(iv); return; }
    if (ZState.map.getSize().y !== el.clientHeight || ZState.map.getSize().x !== el.clientWidth) {
      try { ZState.map.invalidateSize(); if (ZState.overlayOn) zLoadOverlay(); } catch(e){}
    } else if (ZState.overlayOn && (!ZState.overlay || !ZState.overlay.getLayers().length)) {
      try { zLoadOverlay(); } catch(e){}
    }
    if (tries >= 28) clearInterval(iv); // ~7s safety window
  }, 250);
}

/* Fetch + render the colour overlay for the current map view. */
function zLoadOverlay(){
  if (!ZState.map || !ZState.overlayOn || typeof L === 'undefined') return;
  var b = ZState.map.getBounds();
  zGet('overlay', { w: b.getWest(), s: b.getSouth(), e: b.getEast(), n: b.getNorth() }).then(function(res){
    if (!ZState.overlayOn) return;
    if (ZState.overlay) { ZState.map.removeLayer(ZState.overlay); ZState.overlay = null; }
    var data = res.json;
    if (!data || !data.features || !data.features.length) return;
    var lang = zLang();
    ZState.overlay = L.geoJSON(data, {
      style: function(f){ var c = zClassColor(f.properties.class_key); return { fillColor: c, fillOpacity: 0.42, color: c, weight: 1, opacity: 0.55 }; },
      onEachFeature: function(f, layer){
        var nm = lang === 'id' ? f.properties.name_id : f.properties.name_en;
        layer.bindTooltip(nm, { sticky: true, className: 'zlc-zone-tip', direction: 'top' });
        layer.on('click', function(e){ if (L.DomEvent) L.DomEvent.stopPropagation(e); zSelectPoint(e.latlng.lat, e.latlng.lng, null, false); });
      }
    }).addTo(ZState.map);
    if (ZState.marker && ZState.marker.setZIndexOffset) ZState.marker.setZIndexOffset(1000);
  });
}

/* Build the collapsible colour legend from the class taxonomy. */
function zBuildLegend(){
  var el = document.getElementById('zlc-legend');
  if (!el || !ZState.meta || !ZState.meta.classes) return;
  var lang = zLang();
  var rows = ZState.meta.classes.filter(function(c){ return c.class_key !== 'unknown'; }).map(function(c){
    var nm = lang === 'id' ? c.name_id : c.name_en;
    return '<div class="zlc-leg-row"><span class="zlc-leg-sw" style="background:' + zClassColor(c.class_key) + '"></span><span>' + zEsc(nm) + '</span></div>';
  }).join('');
  el.innerHTML = '<button type="button" class="zlc-leg-head" id="zlc-leg-head">'
    + zEsc(zT('zoning.legend', 'Zoning legend')) + ' <span class="zlc-leg-caret">▾</span></button>'
    + '<div class="zlc-leg-body">' + rows + '</div>';
  document.getElementById('zlc-leg-head').addEventListener('click', function(){ el.classList.toggle('zlc-leg-collapsed'); });
  if (window.innerWidth < 760) el.classList.add('zlc-leg-collapsed');
  el.style.display = ZState.overlayOn ? '' : 'none';
}

function zWireInputs(meta){
  var search = document.getElementById('zlc-search');
  var sug = document.getElementById('zlc-suggest');
  if (search) {
    search.addEventListener('input', function(){
      var q = search.value.trim();
      if (ZState.geoTimer) clearTimeout(ZState.geoTimer);
      if (q.length < 2) { sug.hidden = true; sug.innerHTML=''; return; }
      ZState.geoTimer = setTimeout(function(){ zRunGeocode(q); }, 320);
    });
    search.addEventListener('keydown', function(e){ if (e.key==='Enter'){ e.preventDefault(); var q=search.value.trim(); if(q) zRunGeocode(q); } });
  }
  document.addEventListener('click', function(e){ if (sug && !sug.contains(e.target) && e.target!==search) sug.hidden = true; });

  var locate = document.getElementById('zlc-locate');
  if (locate) locate.addEventListener('click', function(){
    if (!navigator.geolocation) { zToast(zT('zoning.no_geo','Geolocation not available'),'error'); return; }
    locate.disabled = true;
    navigator.geolocation.getCurrentPosition(function(p){
      locate.disabled=false; zSelectPoint(p.coords.latitude, p.coords.longitude, zT('zoning.your_location','Your location'), true);
    }, function(){ locate.disabled=false; zToast(zT('zoning.geo_denied','Could not get your location'),'error'); }, { enableHighAccuracy:true, timeout:8000 });
  });

  var coordBtn = document.getElementById('zlc-coordbtn');
  if (coordBtn) coordBtn.addEventListener('click', function(){
    var v = window.prompt(zT('zoning.coord_prompt','Enter coordinates as "lat, lng" (e.g. -8.890, 116.300)'));
    if (!v) return;
    var m = v.split(/[, ]+/).filter(Boolean);
    if (m.length<2) { zToast(zT('zoning.coord_bad','Could not read those coordinates'),'error'); return; }
    var lat=parseFloat(m[0]), lng=parseFloat(m[1]);
    if (!isFinite(lat)||!isFinite(lng)) { zToast(zT('zoning.coord_bad','Could not read those coordinates'),'error'); return; }
    zSelectPoint(lat, lng, null, true);
  });

  var ov = document.getElementById('zlc-overlay-toggle');
  if (ov) ov.addEventListener('change', function(){
    ZState.overlayOn = ov.checked;
    var leg = document.getElementById('zlc-legend');
    if (leg) leg.style.display = ov.checked ? '' : 'none';
    if (ov.checked) { zLoadOverlay(); }
    else if (ZState.overlay) { ZState.map.removeLayer(ZState.overlay); ZState.overlay = null; }
  });

  var parcels = document.getElementById('zlc-parcels');
  if (parcels) parcels.addEventListener('change', function(){
    if (!ZState.map) return;
    if (parcels.checked) {
      ZState.parcelLayer = L.tileLayer.wms(meta.map.bhumi_wms_url, {
        layers: meta.map.bhumi_wms_layers||'', format:'image/png', transparent:true, opacity:0.7, version:'1.1.1'
      }).addTo(ZState.map);
    } else if (ZState.parcelLayer) { ZState.map.removeLayer(ZState.parcelLayer); ZState.parcelLayer=null; }
  });
}

function zRunGeocode(q){
  var sug = document.getElementById('zlc-suggest');
  zGet('geocode', { q:q }).then(function(res){
    var rows = (res.json && res.json.results) ? res.json.results : [];
    if (!rows.length) { sug.hidden=true; sug.innerHTML=''; return; }
    sug.innerHTML = rows.map(function(r,i){
      return '<button type="button" class="zlc-sug-item" data-i="'+i+'">'+zEsc(r.label)+'</button>';
    }).join('');
    sug.hidden=false;
    sug.querySelectorAll('.zlc-sug-item').forEach(function(btn){
      btn.addEventListener('click', function(){
        var r = rows[parseInt(btn.getAttribute('data-i'),10)];
        sug.hidden=true; document.getElementById('zlc-search').value = r.label;
        zSelectPoint(r.lat, r.lng, r.label, true);
      });
    });
  });
}

function zSelectPoint(lat, lng, label, fly){
  if (!isFinite(lat)||!isFinite(lng)) return;
  ZState.lat=lat; ZState.lng=lng; ZState.label=label||null;
  if (ZState.map){
    if (ZState.marker) ZState.marker.setLatLng([lat,lng]);
    else {
      var icon = L.divIcon({ className:'zlc-pin-wrap', html:'<span class="zlc-pin"></span>', iconSize:[28,28], iconAnchor:[14,28] });
      ZState.marker = L.marker([lat,lng], { icon:icon }).addTo(ZState.map);
    }
    if (fly) ZState.map.flyTo([lat,lng], Math.max(ZState.map.getZoom(), 16), { duration:0.6 });
  }
  zRunCheck();
}

function zRunCheck(){
  var panel = document.getElementById('zlc-panel');
  if (!panel) return;
  panel.innerHTML = zSpinner();
  zGet('check', { lat: ZState.lat, lng: ZState.lng }).then(function(res){
    if (!res.json || !res.json.ok){ panel.innerHTML='<div class="zlc-panel-empty"><p>'+zEsc(zT('common.error','Something went wrong.'))+'</p></div>'; return; }
    ZState.lastTriage = res.json.triage;
    panel.innerHTML = zRenderTriage(res.json.triage);
    zWireTriageActions();
    // Best-effort parcel profile.
    zGet('plot_profile', { lat: ZState.lat, lng: ZState.lng }).then(function(pr){
      if (pr.json && pr.json.available && pr.json.profile) zRenderParcelInto(pr.json.profile);
    });
  });
}

function zRenderTriage(tr){
  var lang=zLang(), meta=ZState.meta;
  var info = zStatusInfo(tr.buildability);
  var name = lang==='id' ? tr.name_id : tr.name_en;
  var summary = lang==='id' ? tr.summary_id : tr.summary_en;
  var prov = tr.provenance || {};
  var disc = lang==='id' ? (meta.disclaimer_id||'') : (meta.disclaimer_en||'');
  var html = '<div class="zlc-result zlc-'+info.cls+'">';
  html += '<div class="zlc-light">' +
            '<span class="zlc-bigdot zlc-bigdot-'+info.cls+'"></span>' +
            '<div><p class="zlc-status">'+zEsc(zStatusLabel(tr.buildability))+'</p>' +
            '<p class="zlc-class">'+zEsc(name)+(tr.raw_zona?(' <span class="zlc-raw">· '+zEsc(tr.raw_zona)+'</span>'):'')+'</p></div>' +
          '</div>';
  if (summary) html += '<p class="zlc-summary">'+zEsc(summary)+'</p>';

  if (tr.metrics) {
    var m = tr.metrics, bits=[];
    if (m.kdb) bits.push('<div class="zlc-metric"><span>'+zEsc(zT('zoning.kdb','Max footprint (KDB)'))+'</span><strong>'+zEsc(m.kdb)+'%</strong></div>');
    if (m.max_floors) bits.push('<div class="zlc-metric"><span>'+zEsc(zT('zoning.floors','Max floors'))+'</span><strong>'+zEsc(m.max_floors)+'</strong></div>');
    else if (m.klb) bits.push('<div class="zlc-metric"><span>'+zEsc(zT('zoning.klb','Floor-area ratio (KLB)'))+'</span><strong>'+zEsc(m.klb)+'</strong></div>');
    if (m.kkb) bits.push('<div class="zlc-metric"><span>'+zEsc(zT('zoning.kkb','Max height'))+'</span><strong>'+zEsc(m.kkb)+' m</strong></div>');
    if (bits.length) html += '<div class="zlc-metrics">'+bits.join('')+'</div>';
  }

  // Provenance / confidence
  if (tr.covered) {
    html += '<div class="zlc-prov">' +
      '<span class="zlc-badge zlc-badge-ind">'+zEsc(zT('zoning.indicative','Indicative'))+'</span>' +
      (prov.source?('<span class="zlc-prov-src">'+zEsc(zT('zoning.source','Source'))+': '+zEsc(prov.source)+(prov.date?(' · '+zEsc(prov.date)):'')+'</span>'):'') +
    '</div>';
  } else {
    var cnote = lang==='id' ? meta.coverage_note_id : meta.coverage_note_en;
    if (cnote) html += '<p class="zlc-coverage">'+zEsc(cnote)+'</p>';
  }

  // Parcel slot (filled async)
  html += '<div id="zlc-parcel-slot"></div>';

  // CTAs
  html += '<div class="zlc-cta">' +
            '<button id="zlc-getreport" class="zlc-btn zlc-btn-primary">'+zEsc(zT('zoning.get_report','Get verified Site Suitability Report'))+'</button>' +
            (zLoggedIn()?'<button id="zlc-save" class="zlc-btn zlc-btn-ghost">'+zEsc(zT('zoning.save_plot','Save this plot'))+'</button>':'') +
          '</div>';
  if (meta.report_price_label) html += '<p class="zlc-price-note">'+zEsc(zT('zoning.from','From'))+' '+zEsc(meta.report_price_label)+' · '+zEsc(zT('zoning.verified_note','notary-verified, delivered as a downloadable report'))+'</p>';

  if (disc) html += '<p class="zlc-disclaimer">'+zEsc(disc)+'</p>';
  html += '</div>';
  return html;
}

function zRenderParcelInto(profile){
  var slot = document.getElementById('zlc-parcel-slot');
  if (!slot) return;
  var rows=[];
  if (profile.nib) rows.push(['NIB', profile.nib]);
  if (profile.area_m2) rows.push([zT('zoning.area','Area'), profile.area_m2+' m²']);
  if (profile.right_type) rows.push([zT('zoning.right_type','Right type'), profile.right_type]);
  if (profile.registered_status) rows.push([zT('zoning.reg_status','Registration'), profile.registered_status]);
  if (!rows.length) return;
  slot.innerHTML = '<div class="zlc-parcel"><p class="zlc-parcel-h">'+zEsc(zT('zoning.plot_profile','Plot profile'))+'</p>' +
    rows.map(function(r){return '<div class="zlc-metric"><span>'+zEsc(r[0])+'</span><strong>'+zEsc(r[1])+'</strong></div>';}).join('') +
    '<p class="zlc-parcel-note">'+zEsc(zT('zoning.parcel_note','Indicative parcel data — confirm with a notary certificate check.'))+'</p></div>';
}

function zWireTriageActions(){
  var rep = document.getElementById('zlc-getreport');
  if (rep) rep.addEventListener('click', zOpenReportForm);
  var save = document.getElementById('zlc-save');
  if (save) save.addEventListener('click', function(){
    save.disabled=true;
    zPost('save_plot', { lat:ZState.lat, lng:ZState.lng, label:ZState.label, nib:ZState.nib }).then(function(res){
      save.disabled=false;
      if (res.json && res.json.ok){ save.textContent=zT('zoning.saved','Saved ✓'); }
      else if (res.status===401){ zLogin(); }
      else zToast(zT('common.error','Something went wrong.'),'error');
    });
  });
}

/* ---- Report request modal ---- */
function zOpenReportForm(){
  var meta = ZState.meta, lang=zLang();
  var tr = ZState.lastTriage || {};
  var wrap = document.createElement('div');
  wrap.className = 'zlc-modal-overlay';
  wrap.innerHTML =
    '<div class="zlc-modal" role="dialog" aria-modal="true">' +
      '<button class="zlc-modal-x" aria-label="Close">×</button>' +
      '<h2 class="zlc-modal-title">'+zEsc(zT('zoning.report_title','Site Suitability Report'))+'</h2>' +
      '<p class="zlc-modal-lead">'+zEsc(zT('zoning.report_lead','Our team verifies the official zoning and certificate for this exact plot and sends you a clean, downloadable report.'))+'</p>' +
      '<ul class="zlc-modal-list">' +
        '<li>'+zEsc(zT('zoning.rb1','Verified zoning + development limits (footprint, height)'))+'</li>' +
        '<li>'+zEsc(zT('zoning.rb2','Parcel & certificate check via a licensed notary'))+'</li>' +
        '<li>'+zEsc(zT('zoning.rb3','Infrastructure warnings + a step-by-step permit checklist'))+'</li>' +
      '</ul>' +
      (meta.report_price_label?('<p class="zlc-modal-price">'+zEsc(zT('zoning.from','From'))+' <strong>'+zEsc(meta.report_price_label)+'</strong></p>'):'') +
      '<form id="zlc-reqform" class="zlc-form">' +
        '<input name="contact_name" type="text" placeholder="'+zEsc(zT('zoning.name','Your name'))+'">' +
        '<input name="contact_email" type="email" placeholder="'+zEsc(zT('zoning.email','Email'))+'">' +
        '<input name="contact_whatsapp" type="text" placeholder="'+zEsc(zT('zoning.whatsapp','WhatsApp number'))+'">' +
        '<input name="nib" type="text" placeholder="'+zEsc(zT('zoning.nib','Certificate number / NIB (optional)'))+'">' +
        '<textarea name="message" rows="2" placeholder="'+zEsc(zT('zoning.message','Anything else we should know? (optional)'))+'"></textarea>' +
        '<p class="zlc-form-hint">'+zEsc(zT('zoning.contact_hint','Enter an email or WhatsApp so we can send your report and invoice.'))+'</p>' +
        '<button type="submit" class="zlc-btn zlc-btn-primary">'+zEsc(zT('zoning.submit_request','Request my report'))+'</button>' +
      '</form>' +
      '<div id="zlc-reqdone" hidden></div>' +
    '</div>';
  document.body.appendChild(wrap);
  function close(){ if (wrap.parentNode) wrap.parentNode.removeChild(wrap); }
  wrap.querySelector('.zlc-modal-x').addEventListener('click', close);
  wrap.addEventListener('click', function(e){ if (e.target===wrap) close(); });

  wrap.querySelector('#zlc-reqform').addEventListener('submit', function(e){
    e.preventDefault();
    var f = e.target;
    var body = {
      lat: ZState.lat, lng: ZState.lng, label: ZState.label,
      contact_name: f.contact_name.value, contact_email: f.contact_email.value,
      contact_whatsapp: f.contact_whatsapp.value, nib: f.nib.value, message: f.message.value
    };
    if (!body.contact_email && !body.contact_whatsapp){ zToast(zT('zoning.contact_required','Please add an email or WhatsApp.'),'error'); return; }
    var btn = f.querySelector('button[type=submit]'); btn.disabled=true; btn.textContent=zT('common.sending','Sending…');
    zPost('request_report', body).then(function(res){
      if (res.json && res.json.ok){ zReportSubmitted(wrap, res.json, body.nib); }
      else { btn.disabled=false; btn.textContent=zT('zoning.submit_request','Request my report'); zToast(res.json&&res.json.error?res.json.error:zT('common.error','Something went wrong.'),'error'); }
    });
  });
}

function zReportSubmitted(wrap, data, nib){
  var form = wrap.querySelector('#zlc-reqform'); if (form) form.hidden=true;
  var done = wrap.querySelector('#zlc-reqdone'); done.hidden=false;
  var viewUrl = '#zoning-report?id='+encodeURIComponent(data.report_id)+'&token='+encodeURIComponent(data.token);
  done.innerHTML =
    '<div class="zlc-done">' +
      '<p class="zlc-done-tick">✓</p>' +
      '<h3>'+zEsc(zT('zoning.req_done_h','Request received'))+'</h3>' +
      '<p>'+zEsc(zT('zoning.req_done_p','Your reference is'))+' <strong>'+zEsc(data.ref)+'</strong>. '+zEsc(zT('zoning.req_done_p2','We will verify the plot and send your report and invoice. Typical turnaround is 24-48 hours.'))+'</p>' +
      (data.wa_link?('<a class="zlc-btn zlc-btn-primary" href="'+zEsc(data.wa_link)+'" target="_blank" rel="noopener">'+zEsc(zT('zoning.wa_now','Message us on WhatsApp now'))+'</a>'):'') +
      '<div class="zlc-upload">' +
        '<p class="zlc-upload-h">'+zEsc(zT('zoning.upload_h','Have the land certificate? Attach a scan (optional)'))+'</p>' +
        '<input type="file" id="zlc-certfile" accept=".pdf,.jpg,.jpeg,.png">' +
        '<button id="zlc-certbtn" class="zlc-btn zlc-btn-ghost">'+zEsc(zT('zoning.upload_btn','Attach certificate'))+'</button>' +
        '<p class="zlc-upload-note">'+zEsc(zT('zoning.upload_note','PDF/JPG/PNG up to 8MB. Stored privately for the notary check only.'))+'</p>' +
      '</div>' +
      '<a class="zlc-link" href="'+zEsc(viewUrl)+'">'+zEsc(zT('zoning.view_preview','View report preview'))+' →</a>' +
    '</div>';
  var certBtn = done.querySelector('#zlc-certbtn');
  certBtn.addEventListener('click', function(){
    var fi = done.querySelector('#zlc-certfile');
    if (!fi.files || !fi.files[0]){ zToast(zT('zoning.pick_file','Choose a file first'),'error'); return; }
    var fd = new FormData();
    fd.append('report_id', data.report_id); fd.append('token', data.token); fd.append('file', fi.files[0]);
    certBtn.disabled=true; certBtn.textContent=zT('common.sending','Sending…');
    zUpload(fd).then(function(res){
      certBtn.disabled=false;
      if (res.json && res.json.ok){ certBtn.textContent=zT('zoning.uploaded','Attached ✓'); }
      else { certBtn.textContent=zT('zoning.upload_btn','Attach certificate'); zToast(res.json&&res.json.error?res.json.error:zT('common.error','Something went wrong.'),'error'); }
    });
  });
}

/* =====================================================================
 * ROUTE: #zoning-report?id=..&token=..  — printable report
 * ===================================================================== */
function renderZoningReport(view, params){
  view.innerHTML = zSpinner();
  var id = params && params.id, token = params && params.token;
  if (!id){ view.innerHTML='<p style="padding:40px;text-align:center">'+zEsc(zT('zoning.no_report','Report not found.'))+'</p>'; return Promise.resolve(); }
  return zEnsureMeta().then(function(){
    return zGet('report', { id:id, token:token });
  }).then(function(res){
    if (!res.json || !res.json.ok){ view.innerHTML='<div class="page-view"><p style="padding:60px;text-align:center">'+zEsc(zT('zoning.no_report','Report not found or access denied.'))+'</p></div>'; return; }
    view.innerHTML = zRenderReportDoc(res.json.report);
    var pb = document.getElementById('zlc-print'); if (pb) pb.addEventListener('click', function(){ window.print(); });
    var back = document.getElementById('zlc-back'); if (back) back.addEventListener('click', function(){ zNav('zoning'); });
  });
}

function zRenderReportDoc(rep){
  var lang=zLang(), c=rep.content||{};
  var bld=c.buildability||{}, info=zStatusInfo(bld.status);
  var name = lang==='id'?bld.name_id:bld.name_en;
  var summary = lang==='id'?bld.summary_id:bld.summary_en;
  var metricsPlain = lang==='id'?(c.metrics_plain_id||''):(c.metrics_plain_en||'');
  var disc = lang==='id'?(c.disclaimer_id||''):(c.disclaimer_en||'');
  var plot=c.plot||{};
  var preview = rep.is_preview;

  var html = '<div class="zlc-doc-wrap '+(preview?'zlc-doc-preview':'')+'">';
  html += '<div class="zlc-doc-bar"><button id="zlc-back" class="zlc-btn zlc-btn-ghost">← '+zEsc(zT('zoning.back','Back to map'))+'</button>' +
          '<button id="zlc-print" class="zlc-btn zlc-btn-primary">'+zEsc(zT('zoning.print','Print / Save as PDF'))+'</button></div>';
  html += '<article class="zlc-doc">';
  html += '<header class="zlc-doc-head">' +
            '<div><p class="zlc-doc-kicker">SITE SUITABILITY REPORT</p>' +
            '<h1>Build in Lombok</h1></div>' +
            '<div class="zlc-doc-ref"><p>'+zEsc(rep.ref)+'</p><p class="zlc-doc-date">'+zEsc((rep.created_at||'').substring(0,10))+'</p>' +
            '<span class="zlc-badge '+(rep.confidence==='confirmed'?'zlc-badge-conf':'zlc-badge-ind')+'">'+zEsc(rep.confidence==='confirmed'?zT('zoning.confirmed','Confirmed'):zT('zoning.indicative','Indicative'))+'</span></div>' +
          '</header>';

  // Verification statement (Confirmed reports)
  if (c.verified_note) {
    html += '<div class="zlc-doc-verified"><span class="zlc-badge zlc-badge-conf">'+zEsc(zT('zoning.confirmed','Confirmed'))+'</span><p>'+zEsc(c.verified_note)+'</p></div>';
  }

  // 1. Plot
  html += '<section class="zlc-doc-sec"><h2>1 · '+zEsc(zT('zoning.r_plot','Plot'))+'</h2>' +
    '<div class="zlc-doc-grid">' +
      '<div><span>'+zEsc(zT('zoning.coords','Coordinates'))+'</span><strong>'+zEsc((plot.lat||'')+', '+(plot.lng||''))+'</strong></div>' +
      (plot.label?('<div><span>'+zEsc(zT('zoning.label','Location'))+'</span><strong>'+zEsc(plot.label)+'</strong></div>'):'') +
      (plot.nib?('<div><span>NIB</span><strong>'+zEsc(plot.nib)+'</strong></div>'):'') +
    '</div></section>';

  // 2. Buildability
  html += '<section class="zlc-doc-sec"><h2>2 · '+zEsc(zT('zoning.r_buildability','Buildability'))+'</h2>' +
    '<div class="zlc-doc-verdict zlc-'+info.cls+'"><span class="zlc-bigdot zlc-bigdot-'+info.cls+'"></span>' +
    '<div><strong>'+zEsc(zStatusLabel(bld.status))+'</strong> — '+zEsc(name||'')+'</div></div>' +
    (summary?('<p>'+zEsc(summary)+'</p>'):'') + '</section>';

  // 3. Development metrics
  if (metricsPlain || c.metrics) {
    html += '<section class="zlc-doc-sec"><h2>3 · '+zEsc(zT('zoning.r_metrics','Development limits'))+'</h2>';
    if (metricsPlain) html += '<p>'+zEsc(metricsPlain)+'</p>';
    html += '</section>';
  }

  // 4. Warnings
  if (c.warnings && c.warnings.length) {
    html += '<section class="zlc-doc-sec"><h2>4 · '+zEsc(zT('zoning.r_warnings','Infrastructure & access warnings'))+'</h2><ul class="zlc-doc-ul">';
    c.warnings.forEach(function(w){ html += '<li>'+zEsc(lang==='id'?w.id:w.en)+'</li>'; });
    html += '</ul></section>';
  }

  // 5. Checklist
  if (c.checklist && c.checklist.length) {
    html += '<section class="zlc-doc-sec"><h2>5 · '+zEsc(zT('zoning.r_checklist','Regulatory checklist'))+'</h2><ol class="zlc-doc-ol">';
    c.checklist.forEach(function(s){ html += '<li>'+zEsc(lang==='id'?s.id:s.en)+'</li>'; });
    html += '</ol></section>';
  }

  // Provenance + disclaimer
  var prov=c.provenance||{};
  html += '<footer class="zlc-doc-foot">' +
    (prov.source?('<p>'+zEsc(zT('zoning.source','Source'))+': '+zEsc(prov.source)+(prov.date?(' · '+zEsc(prov.date)):'')+'</p>'):'') +
    (disc?('<p class="zlc-doc-disc">'+zEsc(disc)+'</p>'):'') +
  '</footer>';

  if (preview) html += '<div class="zlc-doc-watermark">'+zEsc(zT('zoning.preview_wm','INDICATIVE PREVIEW'))+'</div>';
  html += '</article></div>';
  return html;
}
