#!/usr/bin/env node
/**
 * dir_ingest — Build in Lombok directory (Provider) ingestion core.
 *
 * Takes a curated JSON batch of businesses harvested from Google Maps and
 * applies the SAME business rules as admin/import.php, then writes Providers via
 * the token-authed SQL console (api/db_console.php), reusing tools/dbq.mjs's curl
 * transport (Windows TLS workaround). This is the headless counterpart of the
 * interactive importer and the contract the weekly Cowork harvest task calls.
 *
 * Pipeline per row:
 *   1. Quality gate     reviews >= MIN_REVIEWS (3) AND rating >= MIN_RATING (4.0)
 *   2. Lombok gate      lat/lng inside the Lombok island bbox  -> excludes Bali
 *   3. Area mapping     lat/lng -> area_key via bounding boxes (import.php parity)
 *   4. Entity dedupe    skip if an existing Provider matches by phone OR name+area
 *   5. Slug + suffix    slugify(name), -2/-3 suffix on collision (import.php parity)
 *   6. Multi-category   providers.category_key = primary; provider_categories = all
 *
 * INPUT JSON: array of objects (curated by the harvester — category judgement is
 * made there, the deterministic rules live here):
 *   { name, gmaps_type, address, phone, website, lat, lng, rating, reviews,
 *     group_key, category_keys: ["primary", ...], gmaps_url? }
 *
 * USAGE:
 *   node tools/dir_ingest.mjs batch.json                 # PLAN (no write) — prints what would happen
 *   node tools/dir_ingest.mjs batch.json --write         # insert new providers + categories + tags
 *   node tools/dir_ingest.mjs batch.json --refresh --write  # also refresh ratings on matched existing
 *
 * Key resolution: --key | $SQL_CONSOLE_KEY | config/sql_console.key  (same as dbq.mjs)
 */
import { readFileSync } from 'node:fs';
import { spawn } from 'node:child_process';

const DEFAULT_ENDPOINT = 'https://biltest.roving-i.com.au/api/db_console.php';
const MIN_REVIEWS = 3;
const MIN_RATING = 4.0;
const CHUNK = 50;

// ── Lombok geography (parity with admin/import.php detect_area_from_coords) ──
const AREA_BOXES = {
  // [south_lat, north_lat, west_lng, east_lng]
  kuta:           [-8.95, -8.87, 116.18, 116.30],
  selong_belanak: [-8.92, -8.85, 116.05, 116.15],
  mawi:           [-8.91, -8.88, 116.02, 116.06],
  mawun:          [-8.92, -8.89, 116.07, 116.11],
  are_guling:     [-8.92, -8.89, 116.14, 116.18],
  gerupuk:        [-8.93, -8.89, 116.30, 116.38],
  tanjung_aan:    [-8.93, -8.90, 116.27, 116.32],
  ekas:           [-8.88, -8.78, 116.38, 116.55],
  senggigi:       [-8.55, -8.45, 116.02, 116.12],
  gili_islands:   [-8.38, -8.32, 116.02, 116.10],
  senaru:         [-8.35, -8.28, 116.35, 116.45],
  tanjung:        [-8.42, -8.35, 116.10, 116.22],
  bangsal:        [-8.40, -8.35, 116.05, 116.12],
  north_lombok:   [-8.42, -8.28, 116.10, 116.45],
  mataram:        [-8.65, -8.52, 116.05, 116.18],
  sekotong:       [-8.82, -8.68, 115.75, 116.05],
  lembar:         [-8.75, -8.68, 116.05, 116.12],
  gerung:         [-8.70, -8.60, 116.05, 116.13],
  praya:          [-8.78, -8.66, 116.20, 116.35],
  jonggat:        [-8.66, -8.58, 116.16, 116.26],
  batukliang:     [-8.66, -8.55, 116.26, 116.38],
  pujut:          [-8.87, -8.78, 116.20, 116.40],
  mertak:         [-8.93, -8.86, 116.18, 116.28],
  labuhan_lombok: [-8.58, -8.45, 116.55, 116.72],
  selong:         [-8.70, -8.58, 116.45, 116.58],
};
// Lombok island bounding box — anything outside is rejected (this excludes Bali,
// which sits west of ~115.7 lng, and Sumbawa, east of ~117.1).
const LOMBOK_BBOX = [-9.10, -8.10, 115.72, 116.85]; // [s_lat, n_lat, w_lng, e_lng]

function detectArea(lat, lng) {
  for (const [k, b] of Object.entries(AREA_BOXES)) {
    if (lat >= b[0] && lat <= b[1] && lng >= b[2] && lng <= b[3]) return k;
  }
  return 'other_lombok';
}
function inLombok(lat, lng) {
  if (lat == null || lng == null || Number.isNaN(lat) || Number.isNaN(lng)) return false;
  const b = LOMBOK_BBOX;
  return lat >= b[0] && lat <= b[1] && lng >= b[2] && lng <= b[3];
}

// ── string helpers (slugify parity with import.php) ──
function slugify(text) {
  return String(text).toLowerCase().trim()
    .replace(/[^a-z0-9\s-]/g, '')
    .replace(/[\s-]+/g, '-')
    .replace(/^-+|-+$/g, '');
}
function normName(s) { return String(s || '').toLowerCase().replace(/[^a-z0-9]/g, ''); }
function normPhone(s) {
  let d = String(s || '').replace(/[^\d]/g, '');
  if (!d) return '';
  if (d.startsWith('0')) d = '62' + d.slice(1);
  if (!d.startsWith('62') && d.length >= 9) d = '62' + d;
  return d.slice(-11); // last 11 digits as the comparison key
}
function waFromPhone(s) {
  const d = normPhone(s);
  return d.startsWith('628') ? d : ''; // only Indonesian mobiles get a WA number
}

// ── curl transport (copied from tools/dbq.mjs — Windows TLS workaround) ──
function resolveKey(argKey) {
  if (argKey) return argKey.trim();
  if (process.env.SQL_CONSOLE_KEY) return process.env.SQL_CONSOLE_KEY.trim();
  try { return readFileSync(new URL('../config/sql_console.key', import.meta.url), 'utf8').trim(); }
  catch { return ''; }
}
function curlPost(url, bodyObj) {
  return new Promise((resolve, reject) => {
    const args = ['-sS', '-m', '60'];
    if (process.platform === 'win32') args.push('--ssl-revoke-best-effort');
    args.push('-X', 'POST', '-H', 'Content-Type: application/json',
              '--data-binary', '@-', '-w', '\n__HTTP_STATUS__%{http_code}', url);
    const cp = spawn('curl', args, { stdio: ['pipe', 'pipe', 'pipe'] });
    let out = '', err = '';
    cp.stdout.on('data', d => (out += d));
    cp.stderr.on('data', d => (err += d));
    cp.on('error', e => reject(new Error(e.code === 'ENOENT' ? 'curl not found on PATH' : e.message)));
    cp.on('close', code => {
      const marker = '\n__HTTP_STATUS__';
      const idx = out.lastIndexOf(marker);
      let status = 0, bodyText = out;
      if (idx !== -1) { status = parseInt(out.slice(idx + marker.length), 10) || 0; bodyText = out.slice(0, idx); }
      if (code !== 0 && !bodyText) return reject(new Error(`curl exit ${code}: ${err.trim() || 'request failed'}`));
      resolve({ status, bodyText });
    });
    cp.stdin.write(JSON.stringify(bodyObj));
    cp.stdin.end();
  });
}
let ENDPOINT, KEY;
async function sql(statement, { write = false, dryRun = false, params = [] } = {}) {
  const url = `${ENDPOINT}?action=query`;
  const { status, bodyText } = await curlPost(url, {
    sql: statement, params, allow_write: write, dry_run: dryRun, console_key: KEY, max_rows: 5000,
  });
  let json; try { json = JSON.parse(bodyText); } catch { json = { ok: false, error: 'non_json', http: status, detail: bodyText.slice(0, 300) }; }
  if (!json.ok) throw new Error('SQL failed: ' + JSON.stringify(json));
  return json;
}

// ── main ──
const argv = process.argv.slice(2);
const opt = { write: false, refresh: false, key: null, file: null };
for (let i = 0; i < argv.length; i++) {
  const a = argv[i];
  if (a === '--write') opt.write = true;
  else if (a === '--refresh') opt.refresh = true;
  else if (a === '--key') opt.key = argv[++i];
  else if (!a.startsWith('--')) opt.file = a;
}
if (!opt.file) { console.error('Usage: node tools/dir_ingest.mjs <batch.json> [--write] [--refresh]'); process.exit(2); }
ENDPOINT = process.env.DBQ_ENDPOINT || DEFAULT_ENDPOINT;
KEY = resolveKey(opt.key);
if (!KEY) { console.error('No console key (config/sql_console.key / $SQL_CONSOLE_KEY).'); process.exit(2); }

const rows = JSON.parse(readFileSync(opt.file, 'utf8'));
if (!Array.isArray(rows)) { console.error('Batch must be a JSON array.'); process.exit(2); }

const main = async () => {
  // Reference data + existing providers for dedupe.
  const cats = (await sql('SELECT `key`, group_key, label FROM categories')).rows;
  const catByKey = Object.fromEntries(cats.map(c => [c.key, c]));
  const areas = (await sql('SELECT `key`, label FROM areas')).rows;
  const areaLabel = Object.fromEntries(areas.map(a => [a.key, a.label]));
  const existing = (await sql('SELECT id, slug, name, area_key, phone, whatsapp_number, google_maps_url FROM providers')).rows;

  const existingSlugs = new Set(existing.map(p => p.slug));
  const byPhone = new Map();   // normPhone -> id
  const byNameArea = new Map(); // normName|area -> id
  for (const p of existing) {
    for (const ph of [p.phone, p.whatsapp_number]) { const k = normPhone(ph); if (k) byPhone.set(k, p.id); }
    byNameArea.set(normName(p.name) + '|' + p.area_key, p.id);
  }

  const usedSlugs = new Set();
  const accepted = [];           // rows to INSERT
  const refresh = [];            // {id, rating, reviews} for matched existing (when --refresh)
  const enrich = [];             // {id, cats} — add category links to an existing matched provider
  const rejected = { low_rating: 0, too_few_reviews: 0, off_island: 0, duplicate_existing: 0, dup_in_batch: 0, bad_category: 0, no_coords: 0 };
  const seenInBatch = new Set();

  for (const r of rows) {
    const name = String(r.name || '').trim();
    if (!name) { rejected.bad_category++; continue; }
    const lat = r.lat == null ? null : parseFloat(r.lat);
    const lng = r.lng == null ? null : parseFloat(r.lng);
    const rating = r.rating == null ? 0 : parseFloat(r.rating);
    const reviews = parseInt(String(r.reviews ?? '0').replace(/[^\d]/g, ''), 10) || 0;
    const group = String(r.group_key || '').trim();
    const catKeys = (r.category_keys || []).filter(k => catByKey[k]);
    if (!group || !catKeys.length) { rejected.bad_category++; continue; }
    if (lat == null || lng == null || Number.isNaN(lat) || Number.isNaN(lng)) { rejected.no_coords++; continue; }
    if (!inLombok(lat, lng)) { rejected.off_island++; continue; }
    if (reviews < MIN_REVIEWS) { rejected.too_few_reviews++; continue; }
    if (rating < MIN_RATING) { rejected.low_rating++; continue; }

    const area = detectArea(lat, lng);

    // entity dedupe vs existing directory
    const phoneKey = normPhone(r.phone);
    const nameAreaKey = normName(name) + '|' + area;
    const matchId = (phoneKey && byPhone.get(phoneKey)) || byNameArea.get(nameAreaKey);
    if (matchId) {
      // Existing provider: don't duplicate it, but enrich it with this row's
      // categories (so an already-listed business that belongs to an empty/extra
      // category gets linked) and optionally refresh its rating.
      enrich.push({ id: matchId, cats: catKeys });
      if (opt.refresh && reviews) refresh.push({ id: matchId, rating, reviews });
      rejected.duplicate_existing++;
      continue;
    }
    // within-batch dedupe
    const batchKey = phoneKey || nameAreaKey;
    if (seenInBatch.has(batchKey)) { rejected.dup_in_batch++; continue; }
    seenInBatch.add(batchKey);

    // slug + collision suffix
    let slug = slugify(name) || ('vendor-' + normName(name).slice(0, 8));
    if (existingSlugs.has(slug) || usedSlugs.has(slug)) {
      let n = 2; while (existingSlugs.has(slug + '-' + n) || usedSlugs.has(slug + '-' + n)) n++;
      slug = slug + '-' + n;
    }
    usedSlugs.add(slug);

    const primary = catKeys[0];
    const label = catByKey[primary].label;
    const where = areaLabel[area] || 'Lombok';
    const short = `${label} in ${where}, Lombok.`;
    const gmapsUrl = (r.gmaps_url || '').slice(0, 500) || null;

    accepted.push({
      slug, name, group_key: group, category_key: primary, category_keys: catKeys,
      area_key: area, short, description: r.description ? String(r.description).trim() : short,
      address: (r.address || '').slice(0, 300) || null, lat, lng, gmaps_url: gmapsUrl,
      rating, reviews, phone: (r.phone || '').slice(0, 30) || null,
      whatsapp: waFromPhone(r.phone) || null, website: (r.website || '').slice(0, 500) || null,
      gmaps_type: (r.gmaps_type || '').slice(0, 80) || null,
    });
  }

  // ── report ──
  console.log(`\nBatch: ${opt.file}`);
  console.log(`Input rows: ${rows.length}`);
  console.log(`Accepted (new):       ${accepted.length}`);
  console.log(`Enrich existing cats: ${enrich.length}`);
  console.log(`Refresh existing:     ${refresh.length}` + (opt.refresh ? '' : ' (use --refresh to apply)'));
  console.log(`Rejected:`, rejected);
  console.log(`\nAccepted preview:`);
  for (const a of accepted.slice(0, 60)) {
    console.log(`  [${a.area_key.padEnd(14)}] ${String(a.rating)}★(${a.reviews})  ${a.category_keys.join('+').padEnd(28)} ${a.name}`);
  }
  if (accepted.length > 60) console.log(`  ... and ${accepted.length - 60} more`);

  if (!opt.write) { console.log('\n(plan only — pass --write to apply)'); return; }
  if (!accepted.length && !refresh.length && !enrich.length) { console.log('\nNothing to write.'); return; }

  // ── providers INSERT (chunked, parameterized) ──
  let inserted = 0;
  for (let i = 0; i < accepted.length; i += CHUNK) {
    const chunk = accepted.slice(i, i + CHUNK);
    const cols = `(slug,name,group_key,category_key,area_key,short_description,description,address,latitude,longitude,google_maps_url,google_rating,google_review_count,phone,whatsapp_number,website_url,languages,is_featured,is_trusted,is_active)`;
    const ph = chunk.map(() => '(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,0,0,1)').join(',');
    const params = [];
    for (const a of chunk) params.push(
      a.slug, a.name, a.group_key, a.category_key, a.area_key, a.short, a.description,
      a.address, a.lat, a.lng, a.gmaps_url, a.rating, a.reviews, a.phone, a.whatsapp, a.website,
      'Bahasa only'
    );
    const stmt = `INSERT INTO providers ${cols} VALUES ${ph}
      ON DUPLICATE KEY UPDATE google_rating=VALUES(google_rating), google_review_count=VALUES(google_review_count), updated_at=CURRENT_TIMESTAMP`;
    const res = await sql(stmt, { write: true, params });
    inserted += res.affected_rows || 0;
  }

  // ── provider_categories (multi-cat) via INSERT...SELECT join on slug ──
  const catPairs = [];
  for (const a of accepted) for (const ck of a.category_keys) catPairs.push([a.slug, ck]);
  for (let i = 0; i < catPairs.length; i += CHUNK) {
    const chunk = catPairs.slice(i, i + CHUNK);
    const union = chunk.map(() => 'SELECT ? AS slug, ? AS ck').join(' UNION ALL ');
    const params = chunk.flat();
    await sql(`INSERT IGNORE INTO provider_categories (provider_id, category_key)
               SELECT p.id, t.ck FROM providers p JOIN (${union}) t ON t.slug = p.slug`,
              { write: true, params });
  }

  // ── provider_tags (raw Maps place-type) via INSERT...SELECT join on slug ──
  const tagPairs = accepted.filter(a => a.gmaps_type).map(a => [a.slug, a.gmaps_type]);
  for (let i = 0; i < tagPairs.length; i += CHUNK) {
    const chunk = tagPairs.slice(i, i + CHUNK);
    const union = chunk.map(() => 'SELECT ? AS slug, ? AS tag').join(' UNION ALL ');
    const params = chunk.flat();
    await sql(`INSERT IGNORE INTO provider_tags (provider_id, tag)
               SELECT p.id, t.tag FROM providers p JOIN (${union}) t ON t.slug = p.slug`,
              { write: true, params });
  }

  // ── enrich existing matched providers with harvested category links ──
  const enrichPairs = [];
  for (const e of enrich) for (const ck of e.cats) enrichPairs.push([e.id, ck]);
  let enriched = 0;
  for (let i = 0; i < enrichPairs.length; i += CHUNK) {
    const chunk = enrichPairs.slice(i, i + CHUNK);
    const ph = chunk.map(() => '(?,?)').join(',');
    const res = await sql(`INSERT IGNORE INTO provider_categories (provider_id, category_key) VALUES ${ph}`,
                          { write: true, params: chunk.flat() });
    enriched += res.affected_rows || 0;
  }

  // ── optional rating refresh on matched existing ──
  let refreshed = 0;
  if (opt.refresh) {
    for (const x of refresh) {
      await sql('UPDATE providers SET google_rating=?, google_review_count=?, updated_at=CURRENT_TIMESTAMP WHERE id=?',
                { write: true, params: [x.rating, x.reviews, x.id] });
      refreshed++;
    }
  }

  console.log(`\nWROTE: providers affected=${inserted}, multi-cat linked, tags linked, existing-cat links added=${enriched}, refreshed=${refreshed}`);
};

main().catch(e => { console.error('FATAL:', e.message); process.exit(1); });
