#!/usr/bin/env node
/**
 * dir_pack — assemble a dir_ingest batch from raw Google-Maps harvest files.
 *
 * Each raw file is the verbatim output of the in-page harvester: an array of
 *   { n:name, r:rating, v:reviews, lat, lng, t:gmaps_type, ph:phone, w?:website }
 * A manifest maps each raw file (by basename without .json) to the group + the
 * category_keys every row in it should carry:
 *   { "paint": { "group":"suppliers_materials", "cats":["paint_supplier"] }, ... }
 * Per-row category overrides (for a megastore that spans categories) go in an
 * optional `overrides` object keyed by a case-insensitive name substring:
 *   { "mitra10": { "cats":["building_materials_store","tiles_stone_supplier"] } }
 *
 * USAGE: node tools/dir_pack.mjs <raw_dir> <manifest.json> <out_batch.json>
 */
import { readFileSync, writeFileSync, readdirSync } from 'node:fs';
import { join, basename } from 'node:path';

const [rawDir, manifestPath, outPath] = process.argv.slice(2);
if (!rawDir || !manifestPath || !outPath) {
  console.error('Usage: node tools/dir_pack.mjs <raw_dir> <manifest.json> <out_batch.json>');
  process.exit(2);
}
const manifest = JSON.parse(readFileSync(manifestPath, 'utf8'));
const overrides = manifest._overrides || {};
const out = [];
let files = 0, rowsIn = 0;

// Match a raw filename to a manifest entry: exact key, else the LONGEST manifest
// key K such that the filename is "K_<something>" (e.g. paint_selong -> paint,
// painter_kuta -> painter). Lets one category be harvested at many town centres.
const manifestKeys = Object.keys(manifest).filter(k => !k.startsWith('_'));
function resolveSpec(name) {
  if (manifest[name]) return manifest[name];
  let best = null;
  for (const k of manifestKeys) {
    if (name.startsWith(k + '_') && (!best || k.length > best.length)) best = k;
  }
  return best ? manifest[best] : null;
}

for (const f of readdirSync(rawDir)) {
  if (!f.endsWith('.json')) continue;
  const key = basename(f, '.json');
  const spec = resolveSpec(key);
  if (!spec) { console.error(`! no manifest entry for ${key} — skipped`); continue; }
  files++;
  const rows = JSON.parse(readFileSync(join(rawDir, f), 'utf8'));
  for (const r of rows) {
    rowsIn++;
    let cats = spec.cats.slice();
    const nameL = String(r.n || '').toLowerCase();
    for (const [sub, ov] of Object.entries(overrides)) {
      if (sub.startsWith('_')) continue;
      if (nameL.includes(sub.toLowerCase())) cats = ov.cats.slice();
    }
    // Canonical Maps link: prefer the place's cid (from the harvested href's
    // feature id) -> opens the real listing (reviews/photos). Fall back to a
    // name+coords search (resolves for uniquely-named shops), never a bare pin.
    let gmapsUrl = '';
    const href = r.href || r.h || '';
    const fm = href.match(/!1s0x[0-9a-f]+:0x([0-9a-f]+)/i);
    if (fm) { try { gmapsUrl = 'https://maps.google.com/?cid=' + BigInt('0x' + fm[1]).toString(); } catch {} }
    if (!gmapsUrl && r.lat != null && r.n) {
      gmapsUrl = `https://www.google.com/maps/search/${encodeURIComponent(r.n)}/@${r.lat},${r.lng},17z`;
    }
    out.push({
      name: r.n, gmaps_type: r.t || '', address: r.a || '', phone: r.ph || '',
      website: r.w || '', lat: r.lat, lng: r.lng, rating: r.r, reviews: r.v,
      group_key: spec.group, category_keys: cats, gmaps_url: gmapsUrl,
    });
  }
}

// Merge the same business appearing across several category searches: same
// normalised name AND ~same location (3dp ≈ 100m) -> union category_keys. Keyed
// on location too so different businesses sharing a generic name ("Sumur bor")
// stay distinct.
const norm = s => String(s || '').toLowerCase().replace(/[^a-z0-9]/g, '');
const merged = new Map();
for (const r of out) {
  const loc = (r.lat != null && r.lng != null) ? `${r.lat.toFixed(3)},${r.lng.toFixed(3)}` : 'na';
  const key = norm(r.name) + '|' + loc;
  const prev = merged.get(key);
  if (prev) {
    for (const c of r.category_keys) if (!prev.category_keys.includes(c)) prev.category_keys.push(c);
    if (!prev.phone && r.phone) prev.phone = r.phone;
  } else {
    merged.set(key, r);
  }
}
const finalRows = [...merged.values()];
writeFileSync(outPath, JSON.stringify(finalRows, null, 0));
console.log(`Packed ${rowsIn} rows from ${files} raw files -> ${outPath} (${finalRows.length} ingest rows after merge)`);
