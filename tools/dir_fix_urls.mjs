#!/usr/bin/env node
/**
 * dir_fix_urls — repair provider google_maps_url to the canonical place page.
 *
 * The first harvest stored a useless coordinate-search URL
 * (`/maps/search/?api=1&query=lat,lng`) which opens a bare pin, not the business
 * listing. This tool reads re-harvested {lat,lng,cid} tuples (the place's feature
 * id), matches them to providers by EXACT coordinates (immune to name edits), and
 * rewrites the link to `https://maps.google.com/?cid=<cid>` — which opens the real
 * Google Maps entry (reviews, photos, address).
 *
 * Input: directory_seed/urls/*.json — each an array of [lat, lng, cid] (or
 *        {lat,lng,cid}). Build them by re-running the searches with the cid extractor.
 *
 * Only providers whose current URL is the bad coord-search form (or empty) are
 * touched; real `/maps/place/` URLs from the original import are left alone.
 *
 * USAGE: node tools/dir_fix_urls.mjs <urls_dir> [--write]
 */
import { readFileSync, readdirSync } from 'node:fs';
import { spawn } from 'node:child_process';
import { join } from 'node:path';

const ENDPOINT = process.env.DBQ_ENDPOINT || 'https://biltest.roving-i.com.au/api/db_console.php';
const KEY = (process.env.SQL_CONSOLE_KEY
  || (() => { try { return readFileSync(new URL('../config/sql_console.key', import.meta.url), 'utf8'); } catch { return ''; } })()).trim();
const [urlsDir] = process.argv.slice(2).filter(a => !a.startsWith('--'));
const WRITE = process.argv.includes('--write');
const FALLBACK = process.argv.includes('--fallback'); // name+coords search for unmatched
if (!KEY || !urlsDir) { console.error('Usage: node tools/dir_fix_urls.mjs <urls_dir> [--write]'); process.exit(2); }

function curlPost(url, body) {
  return new Promise((resolve, reject) => {
    const a = ['-sS','-m','60']; if (process.platform === 'win32') a.push('--ssl-revoke-best-effort');
    a.push('-X','POST','-H','Content-Type: application/json','--data-binary','@-', url);
    const cp = spawn('curl', a); let o = '';
    cp.stdout.on('data', d => o += d); cp.on('error', reject); cp.on('close', () => resolve(o));
    cp.stdin.end(JSON.stringify(body));
  });
}
const sql = async (statement, write = false) => {
  const r = await curlPost(`${ENDPOINT}?action=query`, { sql: statement, params: [], allow_write: write, console_key: KEY, max_rows: 5000 });
  const j = JSON.parse(r); if (!j.ok) throw new Error(JSON.stringify(j)); return j;
};
const key = (lat, lng) => Number(lat).toFixed(5) + ',' + Number(lng).toFixed(5);

// Load cid map from harvested url files.
const cidByCoord = new Map();
let tuples = 0;
for (const f of readdirSync(urlsDir)) {
  if (!f.endsWith('.json')) continue;
  for (const row of JSON.parse(readFileSync(join(urlsDir, f), 'utf8'))) {
    const lat = Array.isArray(row) ? row[0] : row.lat;
    const lng = Array.isArray(row) ? row[1] : row.lng;
    const raw = Array.isArray(row) ? row[2] : (row.cid ?? row.ftid);
    if (lat == null || lng == null || raw == null) continue;
    // Accept a decimal cid, a hex ftid "0x..:0x..", or a bare hex.
    let cid = '';
    const sv = String(raw);
    try {
      if (sv.includes(':')) cid = BigInt('0x' + sv.split(':')[1].replace(/^0x/i, '')).toString();
      else if (/^[0-9]+$/.test(sv)) cid = sv;
      else if (/^0x/i.test(sv)) cid = BigInt(sv).toString();
      else cid = BigInt('0x' + sv).toString();
    } catch { continue; }
    if (!cid) continue;
    cidByCoord.set(key(lat, lng), cid);
    tuples++;
  }
}
console.log(`Loaded ${tuples} cid tuples (${cidByCoord.size} unique coords).`);

const main = async () => {
  const rows = (await sql(
    "SELECT id, name, latitude, longitude, google_maps_url FROM providers WHERE is_active=1 AND latitude IS NOT NULL AND longitude IS NOT NULL"
  )).rows;
  const isBad = u => !u || u.includes('/maps/search/?api=1&query=') || (!u.includes('/maps/place/') && !u.includes('cid='));
  let need = 0, matched = 0, unmatched = 0, updated = 0, fellback = 0;
  const updates = [];
  for (const p of rows) {
    if (!isBad(p.google_maps_url)) continue;
    need++;
    const cid = cidByCoord.get(key(p.latitude, p.longitude));
    if (cid) { matched++; updates.push({ id: p.id, url: `https://maps.google.com/?cid=${cid}` }); continue; }
    unmatched++;
    // Fallback: a name+coords search resolves to the listing for uniquely-named
    // shops (verified) — never a bare pin. Weekly cid capture upgrades it later.
    if (FALLBACK && p.name) {
      updates.push({ id: p.id, url: `https://www.google.com/maps/search/${encodeURIComponent(p.name)}/@${p.latitude},${p.longitude},17z`, fb: true });
    }
  }
  console.log(`Providers needing a real URL: ${need}`);
  console.log(`  matched to a cid: ${matched}`);
  console.log(`  unmatched: ${unmatched}` + (FALLBACK ? ' (name+coords fallback applied)' : ' (use --fallback)'));
  if (!WRITE) { console.log('\n(preview — pass --write to apply)'); return; }
  for (const u of updates) {
    await sql(`UPDATE providers SET google_maps_url=${JSON.stringify(u.url)} WHERE id=${u.id}`, true);
    if (u.fb) fellback++; else updated++;
  }
  console.log(`\nUpdated ${updated} to cid links, ${fellback} to name+coords fallback.`);
};
main().catch(e => { console.error('FATAL:', e.message); process.exit(1); });
