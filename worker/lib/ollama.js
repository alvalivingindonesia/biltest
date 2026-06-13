// Build in Lombok — local Ollama Extractor (docs/adr/0009)
//
// The Extractor reads a listing's raw text/signals and returns structured JSON.
// It FINDS facts (prose verbatim, numbers normalised); the server's
// canonicalisation still DECIDES what is stored. Two scopes:
//   • extractLocationTags()  — Mode A: place/area/tags/certificate from stored
//     description only (no re-crawl). Fixes locations corpus-wide.
//   • extractListing()       — Mode B: full extraction from crawl-time signals
//     (price/size/title/desc/location), JSON-LD fed as hints.
//
// Enable with OLLAMA_LOCATION=1 in .env. Model via OLLAMA_LOCATION_MODEL.

const OLLAMA_URL = process.env.OLLAMA_URL || 'http://127.0.0.1:11434';
const MODEL = process.env.OLLAMA_LOCATION_MODEL || 'llama3.1:8b';
const ENABLED = process.env.OLLAMA_LOCATION === '1';
const TIMEOUT_MS = parseInt(process.env.OLLAMA_TIMEOUT_MS || '60000', 10);

const TAG_KEYS = ['beachfront', 'ocean_view', 'mountain_view', 'rice_field_view', 'cliff_top', 'near_airport', 'pool', 'furnished'];

export function ollamaEnabled() { return ENABLED; }

// Build the geography context block injected into every prompt.
function geoBlock(geo) {
  geo = geo || {};
  const areas = (geo.areas || []).map((a) => a.key);
  const places = (geo.places || []).map((p) => `${p.place_key} (in ${p.area_key})`);
  return `VALID AREA KEYS (choose one or "unknown"): ${areas.join(', ')}
VALID PLACE KEYS (each rolls up to its Area): ${places.join('; ') || '(none yet)'}`;
}

async function chat(prompt) {
  const ctrl = new AbortController();
  const timer = setTimeout(() => ctrl.abort(), TIMEOUT_MS);
  try {
    const res = await fetch(`${OLLAMA_URL}/api/chat`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      signal: ctrl.signal,
      body: JSON.stringify({
        model: MODEL,
        messages: [{ role: 'user', content: prompt }],
        stream: false,
        think: false,
        format: 'json',
        options: { temperature: 0, num_ctx: 8192 },
      }),
    });
    if (!res.ok) return null;
    const j = await res.json();
    const raw = j.message && j.message.content ? j.message.content : '';
    try { return JSON.parse(raw); } catch (_) { return null; }
  } catch (_) {
    return null; // timeout / Ollama down → caller falls back
  } finally {
    clearTimeout(timer);
  }
}

// Normalise the model's place/area/tags against the real taxonomy.
function cleanLocation(parsed, geo) {
  const areas = new Set((geo.areas || []).map((a) => a.key));
  let area = String(parsed.area_key || '').trim();
  if (area !== 'unknown' && !areas.has(area)) area = 'unknown';
  const place = String(parsed.place || '').trim();
  const confidence = typeof parsed.confidence === 'number' ? parsed.confidence : null;
  let tags = Array.isArray(parsed.tags) ? parsed.tags.map((t) => String(t).trim().toLowerCase().replace(/\s+/g, '_')) : [];
  tags = tags.filter((t) => TAG_KEYS.includes(t));
  const certificate = String(parsed.certificate || '').trim();
  return { area_key: area || 'unknown', place, tags, certificate, confidence };
}

// ── Mode A: location + tags + certificate from stored description ──────
export async function extractLocationTags(title, description, geo) {
  if (!ENABLED) return null;
  const text = String(description || '').slice(0, 2000);
  if (!String(title || '').trim() && !text.trim()) return null;
  const prompt =
`You are a Lombok, Indonesia real-estate LOCATION + FEATURES classifier.
Identify WHERE THE PROPERTY ACTUALLY IS. IGNORE nearby landmarks, beaches,
hotels, circuits, airports and travel-time references ("20 minutes to Kuta",
"view of Mandalika circuit") — those do NOT make it located there.

${geoBlock(geo)}
VALID FEATURE TAGS: ${TAG_KEYS.join(', ')}

Return ONLY JSON, no prose:
{"area_key":"<one valid area key or unknown>",
 "place":"<the specific village/desa/bay named as the location, else empty>",
 "certificate":"<SHM / HGB / AJB / leasehold / empty>",
 "tags":[<zero or more valid feature tags that the text clearly supports>],
 "confidence":<0.0-1.0>}
area_key MUST be exactly one valid key or "unknown" — never invent one.

TITLE: ${title}
DESCRIPTION: ${text}`;
  const parsed = await chat(prompt);
  if (!parsed) return null;
  return cleanLocation(parsed, geo || {});
}

// ── Mode B: full extraction from crawl-time signals (JSON-LD as hints) ──
export async function extractListing(signals, geo) {
  if (!ENABLED) return null;
  const s = signals || {};
  const hints = [];
  if (s.json_ld) hints.push('JSON-LD: ' + JSON.stringify(s.json_ld).slice(0, 800));
  if (s.dom_price_text) hints.push('Price text on page: ' + s.dom_price_text);
  const prompt =
`You are a Lombok, Indonesia real-estate listing EXTRACTOR. Read the listing and
return clean structured facts. Copy prose VERBATIM; normalise all numbers.

${geoBlock(geo)}
VALID FEATURE TAGS: ${TAG_KEYS.join(', ')}

Rules:
- price_amount: the TOTAL asking price as a number; price_unit one of "total",
  "per_are", "per_m2" (e.g. "65 juta per are" → 65000000 + "per_are"; "Hanya
  1,9 M" → 1900000000 + "total"). Indonesian: juta=1e6, M/miliar=1e9.
- land_size_sqm / building_size_sqm: integer m² (9.67 are → 967; 5,81 hektar →
  58100; "LT 6.000 m²" → 6000).
- location: the property's real Area/Place, ignoring landmark references.
- certificate: SHM / HGB / AJB / leasehold / empty.
- Prefer structured hints below when they agree with the text.

HINTS:
${hints.join('\n') || '(none)'}

Return ONLY JSON:
{"title":"","price_amount":<number or null>,"price_currency":"IDR",
 "price_unit":"total|per_are|per_m2","land_size_sqm":<int|null>,
 "building_size_sqm":<int|null>,"bedrooms":<int|null>,"bathrooms":<int|null>,
 "certificate":"","listing_type":"land|villa|house|apartment|commercial",
 "area_key":"<valid key or unknown>","place":"","description":"<verbatim>",
 "tags":[],"confidence":<0.0-1.0>}

TITLE: ${s.title || ''}
PAGE TEXT: ${String(s.visible_text || s.description || '').slice(0, 4000)}`;
  const parsed = await chat(prompt);
  if (!parsed) return null;
  const loc = cleanLocation(parsed, geo || {});
  const num = (v) => (v === null || v === undefined || v === '' ? null : parseInt(String(v).replace(/[^\d]/g, ''), 10) || null);
  const unit = ({ per_are: '/are', per_m2: '/m²', total: 'Total' })[parsed.price_unit] || 'Total';
  return {
    title: String(parsed.title || s.title || '').trim(),
    price_amount: num(parsed.price_amount),
    price_currency: String(parsed.price_currency || 'IDR').toUpperCase(),
    price_unit_label: unit,
    land_size_sqm: num(parsed.land_size_sqm),
    building_size_sqm: num(parsed.building_size_sqm),
    bedrooms: num(parsed.bedrooms),
    bathrooms: num(parsed.bathrooms),
    certificate_text: loc.certificate,
    listing_type: String(parsed.listing_type || '').trim(),
    llm_area_key: loc.area_key,
    llm_place: loc.place,
    description: String(parsed.description || s.description || '').trim(),
    tags: loc.tags,
    extraction_confidence: loc.confidence,
  };
}
