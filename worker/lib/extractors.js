// Build in Lombok — per-site page extractors (docs/adr/0007).
//
// The Worker only EXTRACTS raw facts; HostPapa canonicalises (per-are math,
// area_key, IDR, dedupe). So these return raw, source-shaped values:
//   { source_site, source_listing_id, source_url, title,
//     price_amount, price_currency, price_unit_label,
//     land_size_sqm, building_size_sqm, bedrooms, bathrooms,
//     certificate_text, kecamatan, desa, district, listing_type,
//     photos[], agent:{ name, agency, phone, source_agent_id, profile_url, photo_url, verified } }
//
// JSON-LD is preferred (most stable); DOM is the fallback. Selectors marked
// // TUNE may need adjusting if a portal changes layout — that is the expected
// per-site maintenance surface called out in ADR 0007.

const GONE_MARKERS = [
  'tidak tersedia', 'tidak ditemukan', 'halaman tidak', 'sudah terjual',
  'no longer available', 'not available', 'page not found', 'listing has been removed',
  'iklan tidak ditemukan', '404',
  // inactive/expired ad banners (Rumah123: "Iklan ini sudah tidak aktif",
  // "Iklan ini tidak aktif dan belum diperbarui oleh pemilik iklan")
  'tidak aktif', 'sudah tidak aktif', 'belum diperbarui', 'iklan ini sudah',
  // OLX serves a not-found/search page (HTTP 200, same /item/ URL) for removed
  // ads: "tidak menemukan apa pun yang cocok dengan pencarian ini … cek
  // kesalahan ejaan".
  'menemukan apa pun yang cocok', 'cek kesalahan ejaan',
];

// Pull all JSON-LD blocks + a bit of context from any page.
async function readPage(page) {
  return await page.evaluate(() => {
    const ld = [];
    document.querySelectorAll('script[type="application/ld+json"]').forEach((s) => {
      try { ld.push(JSON.parse(s.textContent)); } catch (_) {}
    });
    const nextData = document.getElementById('__NEXT_DATA__');
    const text = (document.body.innerText || '').slice(0, 4000).toLowerCase();
    const waLink = (document.querySelector('a[href*="wa.me"], a[href*="whatsapp.com/send"]') || {}).href || '';
    const ogImages = Array.from(document.querySelectorAll('meta[property="og:image"]')).map((m) => m.content);
    return { ld, nextData: nextData ? nextData.textContent : null, text, waLink, ogImages, html: document.documentElement.outerHTML.length };
  });
}

// Flatten JSON-LD graphs and find the first node matching any @type.
function findLd(ldArray, types) {
  const out = [];
  const walk = (node) => {
    if (!node || typeof node !== 'object') return;
    if (Array.isArray(node)) return node.forEach(walk);
    if (node['@graph']) walk(node['@graph']);
    const t = node['@type'];
    const tt = Array.isArray(t) ? t : [t];
    if (tt.some((x) => types.includes(x))) out.push(node);
    Object.values(node).forEach((v) => { if (v && typeof v === 'object') walk(v); });
  };
  ldArray.forEach(walk);
  return out;
}

// schema.org "image" can be a URL string, an array, or ImageObject(s)
// ({"@type":"ImageObject","contentUrl":...}). Always emit plain URL strings so
// photo_urls never stores objects the frontend can't render.
function imageUrls(img) {
  const arr = Array.isArray(img) ? img : [img];
  return arr
    .map((x) => {
      if (!x) return '';
      if (typeof x === 'string') return x.trim();
      return String(x.contentUrl || x.url || x['@id'] || x.image || '').trim();
    })
    .filter(Boolean);
}

function phoneFromWa(href) {
  const m = (href || '').match(/(?:phone=|wa\.me\/)(\+?\d{6,})/);
  return m ? m[1] : '';
}
function numFrom(s) {
  if (s == null) return null;
  const d = String(s).replace(/[^\d]/g, '');
  return d ? parseInt(d, 10) : null;
}

// A breadcrumb/category title like "Tanah Dijual di Lombok Tengah" — never the
// real listing name. Prefer the actual heading over this.
const GENERIC_TITLE = /^\s*(tanah|rumah|vila|villa|apartemen|ruko|gudang|kavling|kapling|properti)\s+(dijual|disewa)\b/i;
function pickTitle(...cands) {
  const clean = cands.map((c) => (c == null ? '' : String(c)).trim()).filter(Boolean);
  return clean.find((c) => c.length > 6 && !GENERIC_TITLE.test(c)) || clean[0] || '';
}
function isGenericTitle(t) { t = String(t || '').trim(); return t.length <= 6 || GENERIC_TITLE.test(t); }
// Best full description: JSON-LD, then an on-page description block, then body text.
async function readDescription(page, productDescription, pageText) {
  let dom = '';
  try {
    dom = (await page.locator('[class*="escription"], [data-test*="escription"], #description').first().textContent({ timeout: 1500 }).catch(() => '')) || '';
  } catch (_) { dom = ''; }
  return String(productDescription || dom || pageText || '').replace(/\s+/g, ' ').trim();
}

// Generic liveness: response status + redirect-away + gone markers.
function detectGone(finalUrl, status, pageText, detailUrlPattern) {
  if (status && status >= 400) return true;
  if (detailUrlPattern && finalUrl && !detailUrlPattern.test(finalUrl)) return true; // bounced to search/home
  if (pageText && GONE_MARKERS.some((m) => pageText.includes(m))) return true;
  return false;
}

// ─────────────────────────────────────────────────────────────────────
// LAMUDI
// ─────────────────────────────────────────────────────────────────────
const lamudi = {
  detailUrlPattern: /lamudi\.co\.id\/properti\//i,
  idFromUrl: (url) => {
    const m = url.match(/-(\d{5,})\/?$/) || url.match(/\/([a-z0-9]+)\/?$/i);
    return m ? m[1] : url;
  },
  searchLinkPattern: /https?:\/\/www\.lamudi\.co\.id\/properti\/[^"']+/gi,
  async extractDetail(page, url) {
    const pg = await readPage(page);
    const product = findLd(pg.ld, ['Product', 'RealEstateListing', 'Residence', 'Place'])[0] || {};
    const offer = product.offers || {};
    const addr = product.address || {};
    // Real heading, never the breadcrumb "Tanah Dijual di Lombok Tengah".
    const h1 = (await page.locator('h1').first().textContent().catch(() => '')) || '';
    const title = pickTitle(product.name, h1);
    const description = await readDescription(page, product.description, pg.text);
    // Price: prefer DOM price (carries the /are unit), fall back to JSON-LD offer.
    const priceText = (await page.locator('[class*="Price"], [class*="price"]').first().textContent().catch(() => '')) || '';
    const unit = /\/?are/i.test(priceText) ? '/are' : (/\/?m²|\/?m2|per m/i.test(priceText) ? '/m²' : 'Total');
    const land = numFrom(product.floorSize?.value) // Lamudi sometimes uses floorSize for land
      || numFrom(await page.locator('[data-test="land-size"], [class*="landArea"]').first().textContent().catch(() => '')); // TUNE
    return {
      source_site: 'lamudi',
      source_listing_id: lamudi.idFromUrl(url),
      source_url: url,
      title: String(title).trim(),
      price_amount: numFrom(offer.price) || numFrom(priceText),
      price_currency: offer.priceCurrency || 'IDR',
      price_unit_label: unit,
      land_size_sqm: land,
      building_size_sqm: null,
      bedrooms: numFrom(product.numberOfBedrooms),
      bathrooms: numFrom(product.numberOfBathroomsTotal || product.numberOfBathrooms),
      certificate_text: description,
      description, // full text — carries the location + pricing guide ("KISARAN HARGA: …")
      kecamatan: addr.addressLocality || '',
      desa: addr.addressRegion || '',
      district: [addr.addressLocality, addr.addressRegion].filter(Boolean).join(', '),
      listing_type: '',
      photos: imageUrls(product.image).concat(pg.ogImages).slice(0, 5),
      agent: {
        name: product.broker?.name || '',
        agency: product.broker?.name || '',
        phone: phoneFromWa(pg.waLink),
        source_agent_id: '',
        profile_url: product.broker?.url || '',
        photo_url: '',
        verified: pg.text.includes('rekan lamudi') || pg.text.includes('verified'),
      },
    };
  },
};

// ─────────────────────────────────────────────────────────────────────
// RUMAH123 (Next.js — price.offer is already a TOTAL, label is display only)
// ─────────────────────────────────────────────────────────────────────
const rumah123 = {
  detailUrlPattern: /rumah123\.com\/properti\//i,
  idFromUrl: (url) => {
    const m = url.match(/-((?:hos|las)\d+)\/?/i) || url.match(/-(\d{5,})\/?$/);
    return m ? m[1] : url;
  },
  searchLinkPattern: /\/properti\/[^"']+-(?:hos|las)\d+\/?/gi,
  async extractDetail(page, url) {
    const pg = await readPage(page);
    const product = findLd(pg.ld, ['Product', 'RealEstateListing', 'Residence', 'Place', 'Offer'])[0] || {};
    const offer = product.offers || product || {};
    const addr = product.address || {};
    const h1 = (await page.locator('h1').first().textContent().catch(() => '')) || '';
    const title = pickTitle(product.name, h1);
    const description = await readDescription(page, product.description, pg.text);
    return {
      source_site: 'rumah123',
      source_listing_id: rumah123.idFromUrl(url),
      source_url: url,
      title: String(title).trim(),
      price_amount: numFrom(offer.price),
      price_currency: offer.priceCurrency || 'IDR',
      price_unit_label: 'Total', // offer.price is the total — never multiply (avoids double-count)
      land_size_sqm: numFrom(product.lotSize?.value || product.floorSize?.value),
      building_size_sqm: numFrom(product.floorSize?.value),
      bedrooms: numFrom(product.numberOfBedrooms),
      bathrooms: numFrom(product.numberOfBathroomsTotal || product.numberOfBathrooms),
      certificate_text: description,
      description,
      kecamatan: addr.addressLocality || '',
      desa: addr.addressRegion || '',
      district: [addr.streetAddress, addr.addressLocality].filter(Boolean).join(', '),
      listing_type: '',
      photos: imageUrls(product.image).slice(0, 5),
      agent: {
        name: product.broker?.name || product.seller?.name || '',
        agency: product.broker?.name || '',
        phone: phoneFromWa(pg.waLink),
        source_agent_id: '',
        profile_url: product.broker?.url || '',
        photo_url: '',
        verified: pg.text.includes('verified') || pg.text.includes('terverifikasi'),
      },
    };
  },
};

// ─────────────────────────────────────────────────────────────────────
// DOTPROPERTY
// ─────────────────────────────────────────────────────────────────────
const dotproperty = {
  detailUrlPattern: /dotproperty\.id\/.*\/ads\//i,
  idFromUrl: (url) => {
    const m = url.match(/-(\d{5,})\/?$/) || url.match(/\/ads\/[^/]*?(\d{5,})/);
    return m ? m[1] : url;
  },
  searchLinkPattern: /https?:\/\/www\.dotproperty\.id\/[a-z]{2}\/ads\/[^"']+/gi,
  async extractDetail(page, url) {
    const pg = await readPage(page);
    const product = findLd(pg.ld, ['Product', 'RealEstateListing', 'Residence', 'Place'])[0] || {};
    const offer = product.offers || {};
    const addr = product.address || {};
    const h1 = (await page.locator('h1').first().textContent().catch(() => '')) || '';
    const title = pickTitle(product.name, h1);
    const description = await readDescription(page, product.description, pg.text);
    return {
      source_site: 'dotproperty',
      source_listing_id: dotproperty.idFromUrl(url),
      source_url: url,
      title: String(title).trim(),
      price_amount: numFrom(offer.price),
      price_currency: offer.priceCurrency || 'IDR',
      price_unit_label: 'Total',
      land_size_sqm: numFrom(product.lotSize?.value || product.floorSize?.value),
      building_size_sqm: numFrom(product.floorSize?.value),
      bedrooms: numFrom(product.numberOfBedrooms),
      bathrooms: numFrom(product.numberOfBathroomsTotal || product.numberOfBathrooms),
      certificate_text: description,
      description,
      kecamatan: addr.addressLocality || '',
      desa: addr.addressRegion || '',
      district: [addr.addressLocality, addr.addressRegion].filter(Boolean).join(', '),
      listing_type: '',
      photos: imageUrls(product.image).slice(0, 5),
      agent: {
        name: product.broker?.name || '',
        agency: product.broker?.name || '',
        phone: phoneFromWa(pg.waLink),
        source_agent_id: '',
        profile_url: product.broker?.url || '',
        photo_url: '',
        verified: pg.text.includes('verified'),
      },
    };
  },
};

// ─────────────────────────────────────────────────────────────────────
// OLX — re-check only (NOT in discovery: OLX owns Lamudi → duplicates).
// Existing OLX listings from the paste importer still need liveness + refresh.
// ─────────────────────────────────────────────────────────────────────
const olx = {
  detailUrlPattern: /olx\.co\.id\/item\//i,
  idFromUrl: (url) => {
    const m = url.match(/-iid-(\d+)/i) || url.match(/(\d{7,})/);
    return m ? m[1] : url;
  },
  searchLinkPattern: /https?:\/\/www\.olx\.co\.id\/item\/[^"']+/gi,
  async extractDetail(page, url) {
    const pg = await readPage(page);
    const product = findLd(pg.ld, ['Product', 'RealEstateListing', 'Offer', 'Residence', 'Place'])[0] || {};
    const offer = product.offers || product || {};
    const addr = product.address || {};
    const h1 = (await page.locator('h1').first().textContent().catch(() => '')) || '';
    const title = pickTitle(product.name, h1);
    const description = await readDescription(page, product.description, pg.text);
    // OLX shows a TOTAL price; grab JSON-LD first, else the price element. // TUNE
    let priceText = '';
    try { priceText = (await page.locator('[data-aut-id="itemPrice"], span[data-aut-id="itemPrice"], [class*="rice"]').first().textContent({ timeout: 1500 }).catch(() => '')) || ''; } catch (_) { priceText = ''; }
    return {
      source_site: 'olx',
      source_listing_id: olx.idFromUrl(url),
      source_url: url,
      title: String(title).trim(),
      price_amount: numFrom(offer.price) || numFrom(priceText),
      price_currency: offer.priceCurrency || 'IDR',
      price_unit_label: 'Total', // OLX cards are totals; description fallback handles per-are notes server-side
      land_size_sqm: numFrom(product.floorSize?.value || product.lotSize?.value), // usually null → mined server-side
      building_size_sqm: null,
      bedrooms: numFrom(product.numberOfBedrooms),
      bathrooms: numFrom(product.numberOfBathroomsTotal || product.numberOfBathrooms),
      certificate_text: description,
      description,
      kecamatan: addr.addressLocality || '',
      desa: addr.addressRegion || '',
      district: [addr.addressLocality, addr.addressRegion].filter(Boolean).join(', '),
      listing_type: '',
      photos: imageUrls(product.image).concat(pg.ogImages).slice(0, 5),
      agent: {
        name: product.seller?.name || product.broker?.name || '',
        agency: '',
        phone: phoneFromWa(pg.waLink),
        source_agent_id: '',
        profile_url: '',
        photo_url: '',
        verified: false,
      },
    };
  },
};

const SITES = { lamudi, rumah123, dotproperty, olx };

// Extract candidate detail-page links from a search-results page.
async function extractSearchLinks(page, site) {
  const cfg = SITES[site];
  if (!cfg) return [];
  const html = await page.content();
  const found = new Set();
  let m;
  const re = new RegExp(cfg.searchLinkPattern.source, 'gi');
  while ((m = re.exec(html)) !== null) {
    let href = m[0];
    if (href.startsWith('/')) href = new URL(href, page.url()).href;
    if (cfg.detailUrlPattern.test(href)) found.add(href.split('?')[0]);
  }
  return [...found];
}

// Extract {detail-url, image} pairs from a SEARCH-RESULTS page — so we can
// backfill thumbnails without opening each detail page. Handles lazy-loaded imgs.
async function extractSearchCards(page, site) {
  const cfg = SITES[site];
  if (!cfg) return [];
  return await page.evaluate((patSrc) => {
    const re = new RegExp(patSrc, 'i');
    const seen = {}; const out = [];
    document.querySelectorAll('a[href]').forEach((a) => {
      let href = a.href; if (!re.test(href)) return;
      href = href.split('?')[0];
      if (seen[href]) return;
      // image inside the anchor, else within the surrounding card container
      let img = a.querySelector('img');
      if (!img) {
        const card = a.closest('article, li, [class*="card"], [class*="Card"], [class*="item"], [class*="listing"]') || a.parentElement;
        if (card) img = card.querySelector('img');
      }
      let src = '';
      if (img) {
        src = img.getAttribute('src') || '';
        if (!src || src.startsWith('data:')) src = img.getAttribute('data-src') || img.getAttribute('data-lazy-src') || img.getAttribute('data-original') || '';
        if ((!src || src.startsWith('data:')) && img.getAttribute('srcset')) src = img.getAttribute('srcset').split(',')[0].trim().split(' ')[0];
        if ((!src || src.startsWith('data:')) && img.currentSrc) src = img.currentSrc;
      }
      if (src && !src.startsWith('data:')) { seen[href] = 1; out.push({ url: href, img: src }); }
    });
    return out;
  }, cfg.detailUrlPattern.source);
}

export { SITES, readPage, detectGone, extractSearchLinks, extractSearchCards, isGenericTitle };
