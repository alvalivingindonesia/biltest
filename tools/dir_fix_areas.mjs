#!/usr/bin/env node
/**
 * dir_fix_areas — correct provider area_key when coordinates clearly contradict it.
 *
 * For every active provider that has coordinates AND whose stored area_key maps to
 * a known bounding box, check whether the coordinates actually fall inside that box.
 * If they don't (a definite mislabel), recompute the area from the coordinates and
 * (with --write) UPDATE it. Providers stored as 'other_lombok' or in box-less areas
 * are left alone — we only correct unambiguous errors, never reshuffle judgement calls.
 *
 * USAGE: node tools/dir_fix_areas.mjs [--write]
 */
import { readFileSync } from 'node:fs';
import { spawn } from 'node:child_process';

const ENDPOINT = process.env.DBQ_ENDPOINT || 'https://biltest.roving-i.com.au/api/db_console.php';
const KEY = (process.env.SQL_CONSOLE_KEY
  || (() => { try { return readFileSync(new URL('../config/sql_console.key', import.meta.url), 'utf8'); } catch { return ''; } })()).trim();
if (!KEY) { console.error('No console key.'); process.exit(2); }
const WRITE = process.argv.includes('--write');

const AREA_BOXES = {
  kuta:[-8.95,-8.87,116.18,116.30], selong_belanak:[-8.92,-8.85,116.05,116.15], mawi:[-8.91,-8.88,116.02,116.06],
  mawun:[-8.92,-8.89,116.07,116.11], are_guling:[-8.92,-8.89,116.14,116.18], gerupuk:[-8.93,-8.89,116.30,116.38],
  tanjung_aan:[-8.93,-8.90,116.27,116.32], ekas:[-8.88,-8.78,116.38,116.55], senggigi:[-8.55,-8.45,116.02,116.12],
  gili_islands:[-8.38,-8.32,116.02,116.10], senaru:[-8.35,-8.28,116.35,116.45], tanjung:[-8.42,-8.35,116.10,116.22],
  bangsal:[-8.40,-8.35,116.05,116.12], north_lombok:[-8.42,-8.28,116.10,116.45], mataram:[-8.65,-8.52,116.05,116.18],
  sekotong:[-8.82,-8.68,115.75,116.05], lembar:[-8.75,-8.68,116.05,116.12], gerung:[-8.70,-8.60,116.05,116.13],
  praya:[-8.78,-8.66,116.20,116.35], jonggat:[-8.66,-8.58,116.16,116.26], batukliang:[-8.66,-8.55,116.26,116.38],
  pujut:[-8.87,-8.78,116.20,116.40], mertak:[-8.93,-8.86,116.18,116.28], labuhan_lombok:[-8.58,-8.45,116.55,116.72],
  selong:[-8.70,-8.58,116.45,116.58],
};
const inBox = (lat, lng, b) => lat >= b[0] && lat <= b[1] && lng >= b[2] && lng <= b[3];
function detectArea(lat, lng) {
  for (const [k, b] of Object.entries(AREA_BOXES)) if (inBox(lat, lng, b)) return k;
  return 'other_lombok';
}

function curlPost(url, body) {
  return new Promise((resolve, reject) => {
    const a = ['-sS','-m','60']; if (process.platform === 'win32') a.push('--ssl-revoke-best-effort');
    a.push('-X','POST','-H','Content-Type: application/json','--data-binary','@-', url);
    const cp = spawn('curl', a); let o = '', e = '';
    cp.stdout.on('data', d => o += d); cp.stderr.on('data', d => e += d);
    cp.on('error', reject); cp.on('close', () => resolve(o));
    cp.stdin.end(JSON.stringify(body));
  });
}
const sql = async (statement, write = false) => {
  const r = await curlPost(`${ENDPOINT}?action=query`, { sql: statement, params: [], allow_write: write, console_key: KEY, max_rows: 5000 });
  const j = JSON.parse(r); if (!j.ok) throw new Error(JSON.stringify(j)); return j;
};

const LOMBOK_BBOX = [-9.10, -8.10, 115.72, 116.85];
const inLombok = (lat, lng) => lat >= LOMBOK_BBOX[0] && lat <= LOMBOK_BBOX[1] && lng >= LOMBOK_BBOX[2] && lng <= LOMBOK_BBOX[3];
// how far (deg) the point sits outside a box, 0 if inside
function distOutside(lat, lng, b) {
  const dLat = Math.max(b[0] - lat, lat - b[1], 0);
  const dLng = Math.max(b[2] - lng, lng - b[3], 0);
  return Math.max(dLat, dLng);
}

const rows = (await sql("SELECT id, name, area_key, latitude, longitude FROM providers WHERE is_active=1 AND latitude IS NOT NULL AND longitude IS NOT NULL")).rows;
const fixes = [], offIsland = [], skipped = [];
for (const p of rows) {
  const lat = parseFloat(p.latitude), lng = parseFloat(p.longitude);
  if (!inLombok(lat, lng)) { offIsland.push({ id: p.id, area: p.area_key, name: p.name, lat, lng }); continue; }
  const box = AREA_BOXES[p.area_key];
  if (!box) continue;                 // box-less / other_lombok — leave alone
  if (inBox(lat, lng, box)) continue; // already correct
  const fixed = detectArea(lat, lng);
  if (fixed === p.area_key) continue;
  // Demoting to the vague 'other_lombok' only when the point is clearly far from the
  // stored box (>0.02° ≈ 2km) — protects edge-of-box localities from being blurred.
  if (fixed === 'other_lombok' && distOutside(lat, lng, box) < 0.02) { skipped.push({ id: p.id, area: p.area_key, name: p.name, lat, lng }); continue; }
  fixes.push({ id: p.id, from: p.area_key, to: fixed, name: p.name, lat, lng });
}
console.log(`In-Lombok area corrections: ${fixes.length}`);
for (const f of fixes) console.log(`  ${String(f.id).padStart(4)}  ${f.from.padEnd(14)} -> ${f.to.padEnd(14)}  (${f.lat.toFixed(3)},${f.lng.toFixed(3)})  ${f.name.slice(0,42)}`);
console.log(`\nEdge cases left as-is (near their box): ${skipped.length}`);
for (const f of skipped) console.log(`  ${String(f.id).padStart(4)}  ${f.area.padEnd(14)} (${f.lat.toFixed(3)},${f.lng.toFixed(3)})  ${f.name.slice(0,42)}`);
console.log(`\nOFF-ISLAND providers (not in Lombok — review/deactivate): ${offIsland.length}`);
for (const f of offIsland) console.log(`  ${String(f.id).padStart(4)}  area='${f.area}'  (${f.lat.toFixed(3)},${f.lng.toFixed(3)})  ${f.name.slice(0,46)}`);
if (!WRITE) { console.log('\n(preview — pass --write to apply the area corrections only)'); process.exit(0); }
for (const f of fixes) await sql(`UPDATE providers SET area_key='${f.to}' WHERE id=${f.id}`, true);
console.log(`\nUpdated ${fixes.length} providers' area_key. (off-island providers untouched — handle separately)`);
