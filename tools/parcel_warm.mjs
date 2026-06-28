#!/usr/bin/env node
/**
 * parcel_warm — Build in Lombok · Zoning & Land Check (ADR 0013)
 *
 * Pre-warms the parcel-tile cache by requesting tiles through OUR OWN endpoint
 * (api/parcel_tile.php), which fetches each missing tile from BHUMI once and
 * caches it for 7 days. Run weekly (Windows Task Scheduler / cron / the home
 * worker) so common areas are always cached and BHUMI calls concentrate in this
 * scheduled job rather than on user views. We never call BHUMI directly here —
 * only our own caching proxy.
 *
 * TRANSPORT: curl (Windows cert store), like dbq.mjs / zoning_ingest.mjs.
 *
 * USAGE:
 *   node tools/parcel_warm.mjs                         # default hotspots, z15-17
 *   node tools/parcel_warm.mjs --bbox 116.20,-8.92,116.34,-8.84 --zooms 15,16,17,18
 *   node tools/parcel_warm.mjs --endpoint https://biltest.roving-i.com.au/api/parcel_tile.php --delay 120
 *
 * FLAGS:
 *   --bbox w,s,e,n   lng/lat box to warm (repeatable). Default: curated hotspots.
 *   --zooms a,b,c    zoom levels (default 15,16,17)
 *   --delay <ms>     pause between tile requests (default 100) — be a good citizen
 *   --endpoint <url> our tile endpoint (default biltest)
 */
import { spawn } from 'node:child_process';

const DEFAULT_ENDPOINT = 'https://biltest.roving-i.com.au/api/parcel_tile.php';
// Curated South-Lombok hotspots (lng,lat min/max). Edit/extend as coverage grows.
const DEFAULT_BBOXES = [
  [116.255, -8.915, 116.335, -8.865], // Kuta / Mandalika
  [116.150, -8.890, 116.210, -8.840], // Selong Belanak
  [116.250, -8.730, 116.300, -8.690], // Praya town
  [116.060, -8.760, 116.130, -8.700], // Sekotong (north stretch)
];

function parseArgs(argv) {
  const o = { bboxes: [], zooms: [15, 16, 17], delay: 100, endpoint: DEFAULT_ENDPOINT };
  for (let i = 0; i < argv.length; i++) {
    const a = argv[i];
    if (a === '--bbox') o.bboxes.push(argv[++i].split(',').map(Number));
    else if (a === '--zooms') o.zooms = argv[++i].split(',').map(n => parseInt(n, 10));
    else if (a === '--delay') o.delay = parseInt(argv[++i], 10);
    else if (a === '--endpoint') o.endpoint = argv[++i];
  }
  if (!o.bboxes.length) o.bboxes = DEFAULT_BBOXES;
  return o;
}
const lng2x = (lng, z) => Math.floor((lng + 180) / 360 * (1 << z));
const lat2y = (lat, z) => { const r = lat * Math.PI / 180; return Math.floor((1 - Math.log(Math.tan(r) + 1 / Math.cos(r)) / Math.PI) / 2 * (1 << z)); };
const sleep = ms => new Promise(r => setTimeout(r, ms));

function curlStatus(url) {
  return new Promise(resolve => {
    const args = ['-sS', '-m', '40', '-o', process.platform === 'win32' ? 'NUL' : '/dev/null', '-w', '%{http_code} %{size_download}'];
    if (process.platform === 'win32') args.push('--ssl-revoke-best-effort');
    args.push(url);
    const cp = spawn('curl', args, { stdio: ['ignore', 'pipe', 'pipe'] });
    let out = '';
    cp.stdout.on('data', d => out += d);
    cp.on('error', () => resolve({ code: 0, size: 0 }));
    cp.on('close', () => { const [c, s] = out.trim().split(/\s+/); resolve({ code: +c || 0, size: +s || 0 }); });
  });
}

async function main() {
  const o = parseArgs(process.argv.slice(2));
  let requested = 0, ok = 0, withData = 0;
  for (const bb of o.bboxes) {
    const [w, s, e, n] = bb;
    for (const z of o.zooms) {
      const x0 = lng2x(w, z), x1 = lng2x(e, z);
      const y0 = lat2y(n, z), y1 = lat2y(s, z); // n (north) -> smaller y
      for (let x = Math.min(x0, x1); x <= Math.max(x0, x1); x++) {
        for (let y = Math.min(y0, y1); y <= Math.max(y0, y1); y++) {
          const r = await curlStatus(`${o.endpoint}?z=${z}&x=${x}&y=${y}`);
          requested++;
          if (r.code === 200) ok++;
          if (r.size > 500) withData++; // >blank tile ~ has parcels
          await sleep(o.delay);
        }
      }
      process.stderr.write(`warmed bbox ${bb.join(',')} z${z} — total req ${requested}, withData ${withData}\n`);
    }
  }
  console.log(JSON.stringify({ requested, ok, tilesWithParcels: withData, bboxes: o.bboxes.length, zooms: o.zooms }, null, 2));
}
main().catch(e => { console.error(e); process.exit(1); });
