#!/usr/bin/env node
/**
 * zoning_ingest — Build in Lombok · Zoning & Land Check ingest (ADR 0013)
 *
 * Pulls official "pola ruang" / RDTR polygons from an ArcGIS REST layer
 * (Satu Peta / SIMTARU / RTR Online), normalises the raw `zona` attribute to our
 * Land-Use Class taxonomy, and upserts them into zoning_landuse_polys via the
 * same db_console channel as tools/dbq.mjs. "Ingest-once, serve ourselves."
 *
 * TRANSPORT: shells out to `curl` (Windows cert-store + revoke-best-effort), like
 * dbq.mjs, because Node fetch fails here when AVG MITMs HTTPS.
 *
 * USAGE:
 *   node tools/zoning_ingest.mjs \
 *     --service "https://host/arcgis/rest/services/<svc>/MapServer/<layerId>" \
 *     --zona-field POLA_RUANG --plan-level rtrw --kabupaten lombok_tengah \
 *     --source "RTRW Lombok Tengah (Satu Peta)" --source-date 2024-01-01 \
 *     [--bbox 115.7,-9.1,116.9,-8.1] [--where "1=1"] [--kdb-field KDB --klb-field KLB --kkb-field KKB] \
 *     [--dry-run] [--limit 5000] [--page 500]
 *
 *   --dry-run prints normalisation stats + unmapped zona values and writes nothing.
 *
 * See docs/zoning-ingest.md for candidate sources and the full runbook.
 */
import { readFileSync } from 'node:fs';
import { spawn } from 'node:child_process';

const DB_ENDPOINT = process.env.DBQ_ENDPOINT || 'https://biltest.roving-i.com.au/api/db_console.php';

/* Raw zona substring (lowercase) -> our class_key. First match wins; order matters
 * (most specific first). Extend freely as new zona vocabularies appear. */
const ZONA_MAP = [
  ['hutan lindung', 'hutan_lindung'],
  ['hutan produksi', 'hutan_produksi'],
  ['suaka', 'konservasi'], ['cagar', 'konservasi'], ['taman nasional', 'konservasi'],
  ['konservasi', 'konservasi'], ['taman wisata alam', 'konservasi'], ['twa', 'konservasi'],
  ['sempadan', 'sempadan'], ['lindung setempat', 'sempadan'],
  ['ruang terbuka hijau', 'rth'], ['rth', 'rth'],
  ['lindung', 'hijau'], ['hijau', 'hijau'],
  ['pariwisata', 'pariwisata'], ['wisata', 'pariwisata'],
  ['permukiman', 'permukiman'], ['perumahan', 'permukiman'], ['pemukiman', 'permukiman'],
  ['perdagangan', 'perdagangan_jasa'], ['jasa', 'perdagangan_jasa'], ['komersial', 'perdagangan_jasa'],
  ['perkebunan', 'perkebunan'],
  ['pertanian', 'pertanian'], ['sawah', 'pertanian'], ['tanaman pangan', 'pertanian'], ['holtikultura', 'pertanian'], ['hortikultura', 'pertanian'], ['lp2b', 'pertanian'],
  ['industri', 'industri'], ['pergudangan', 'industri'],
  ['badan air', 'badan_air'], ['sungai', 'badan_air'], ['danau', 'badan_air'], ['waduk', 'badan_air'],
  ['rawan bencana', 'rawan_bencana'], ['rawan', 'rawan_bencana'],
];
function normaliseZona(raw) {
  if (!raw) return null;
  const s = String(raw).toLowerCase();
  for (const [needle, key] of ZONA_MAP) if (s.indexOf(needle) !== -1) return key;
  return null;
}

function parseArgs(argv) {
  const o = { bbox: '115.7,-9.1,116.9,-8.1', where: '1=1', planLevel: 'rtrw', page: 500, limit: 100000,
              dryRun: false, source: 'arcgis', sourceDate: null, kabupaten: null,
              zonaField: null, kdbField: null, klbField: null, kkbField: null, service: null, key: null };
  for (let i = 0; i < argv.length; i++) {
    const a = argv[i], n = () => argv[++i];
    switch (a) {
      case '--service': o.service = n(); break;
      case '--zona-field': o.zonaField = n(); break;
      case '--plan-level': o.planLevel = n(); break;
      case '--kabupaten': o.kabupaten = n(); break;
      case '--source': o.source = n(); break;
      case '--source-date': o.sourceDate = n(); break;
      case '--bbox': o.bbox = n(); break;
      case '--where': o.where = n(); break;
      case '--kdb-field': o.kdbField = n(); break;
      case '--klb-field': o.klbField = n(); break;
      case '--kkb-field': o.kkbField = n(); break;
      case '--page': o.page = parseInt(n(), 10); break;
      case '--limit': o.limit = parseInt(n(), 10); break;
      case '--dry-run': o.dryRun = true; break;
      case '--key': o.key = n(); break;
    }
  }
  return o;
}
function resolveKey(o) {
  if (o.key) return o.key.trim();
  if (process.env.SQL_CONSOLE_KEY) return process.env.SQL_CONSOLE_KEY.trim();
  try { return readFileSync(new URL('../config/sql_console.key', import.meta.url), 'utf8').trim(); } catch { return ''; }
}

function curl(args) {
  return new Promise((resolve, reject) => {
    const base = ['-sS', '-m', '60'];
    if (process.platform === 'win32') base.push('--ssl-revoke-best-effort');
    const cp = spawn('curl', base.concat(args), { stdio: ['pipe', 'pipe', 'pipe'] });
    let out = '', err = '';
    cp.stdout.on('data', d => out += d); cp.stderr.on('data', d => err += d);
    cp.on('error', e => reject(e));
    cp.on('close', () => resolve(out || err));
    if (args._stdin) { cp.stdin.write(args._stdin); }
    cp.stdin.end();
  });
}
async function curlGet(url) { return curl([url]); }
async function curlPostJson(url, obj) {
  const a = ['-X', 'POST', '-H', 'Content-Type: application/json', '--data-binary', '@-', url];
  a._stdin = JSON.stringify(obj);
  return curl(a);
}

/* GeoJSON / Esri ring coords -> WKT (SRID 0, X=lng Y=lat), precision 6. */
function ring(coords) { return coords.map(c => (+c[0]).toFixed(6) + ' ' + (+c[1]).toFixed(6)).join(','); }
function geojsonToWkt(geom) {
  if (!geom) return null;
  if (geom.type === 'Polygon') return 'POLYGON(' + geom.coordinates.map(r => '(' + ring(r) + ')').join(',') + ')';
  if (geom.type === 'MultiPolygon') return 'MULTIPOLYGON(' + geom.coordinates.map(p => '(' + p.map(r => '(' + ring(r) + ')').join(',') + ')').join(',') + ')';
  return null;
}
function esriRingsToWkt(geom) {
  if (!geom || !geom.rings) return null;
  // Esri: outer rings clockwise, holes counter-clockwise. We emit a MULTIPOLYGON of
  // each ring as its own polygon — coarse but valid for point-in-polygon containment.
  return 'MULTIPOLYGON(' + geom.rings.map(r => '((' + ring(r) + '))').join(',') + ')';
}

function sqlStr(s) { return s === null || s === undefined ? 'NULL' : "'" + String(s).replace(/'/g, "''") + "'"; }
function sqlNum(n) { return (n === null || n === undefined || n === '' || isNaN(+n)) ? 'NULL' : String(+n); }

async function main() {
  const o = parseArgs(process.argv.slice(2));
  if (!o.service || !o.zonaField) {
    console.error('Required: --service <ArcGIS layer URL> --zona-field <attr>. See docs/zoning-ingest.md');
    process.exit(2);
  }
  const key = resolveKey(o);
  if (!o.dryRun && !key) { console.error('No console key (config/sql_console.key or $SQL_CONSOLE_KEY).'); process.exit(2); }

  const [minx, miny, maxx, maxy] = o.bbox.split(',').map(Number);
  const queryBase = o.service.replace(/\/+$/, '') + '/query';
  const stats = { fetched: 0, mapped: 0, written: 0, skipped: 0, unmapped: {} };
  let offset = 0;

  while (stats.fetched < o.limit) {
    const params = new URLSearchParams({
      where: o.where, outFields: '*', returnGeometry: 'true', outSR: '4326', f: 'geojson',
      geometryType: 'esriGeometryEnvelope', inSR: '4326', spatialRel: 'esriSpatialRelIntersects',
      geometry: JSON.stringify({ xmin: minx, ymin: miny, xmax: maxx, ymax: maxy, spatialReference: { wkid: 4326 } }),
      resultOffset: String(offset), resultRecordCount: String(o.page),
    });
    const url = queryBase + '?' + params.toString();
    let body = await curlGet(url);
    let json; try { json = JSON.parse(body); } catch { console.error('Non-JSON from service:', body.slice(0, 300)); break; }

    let feats = [];
    if (json.features && json.type === 'FeatureCollection') feats = json.features.map(f => ({ props: f.properties || {}, wkt: geojsonToWkt(f.geometry) }));
    else if (json.features) feats = json.features.map(f => ({ props: f.attributes || {}, wkt: esriRingsToWkt(f.geometry) })); // Esri JSON
    if (json.error) { console.error('ArcGIS error:', JSON.stringify(json.error).slice(0, 300)); break; }
    if (!feats.length) break;

    for (const f of feats) {
      stats.fetched++;
      const raw = f.props[o.zonaField];
      const cls = normaliseZona(raw);
      if (!cls) { stats.skipped++; const k = String(raw || '(empty)'); stats.unmapped[k] = (stats.unmapped[k] || 0) + 1; continue; }
      if (!f.wkt) { stats.skipped++; continue; }
      stats.mapped++;
      if (o.dryRun) continue;
      const kdb = o.kdbField ? f.props[o.kdbField] : null;
      const klb = o.klbField ? f.props[o.klbField] : null;
      const kkb = o.kkbField ? f.props[o.kkbField] : null;
      const sql =
        "INSERT INTO zoning_landuse_polys (class_key, plan_level, kabupaten, raw_zona, geom, kdb, klb, kkb, source, source_date, confidence) VALUES (" +
        sqlStr(cls) + ", " + sqlStr(o.planLevel) + ", " + sqlStr(o.kabupaten) + ", " + sqlStr(String(raw).slice(0, 250)) + ", " +
        "ST_GeomFromText(" + sqlStr(f.wkt) + ", 0), " + sqlNum(kdb) + ", " + sqlNum(klb) + ", " + sqlNum(kkb) + ", " +
        sqlStr(o.source) + ", " + (o.sourceDate ? sqlStr(o.sourceDate) : 'NULL') + ", 'indicative')";
      const res = await curlPostJson(DB_ENDPOINT + '?action=query', { sql, allow_write: true, console_key: key });
      let r; try { r = JSON.parse(res); } catch { r = { ok: false, error: res.slice(0, 200) }; }
      if (r.ok) stats.written++; else { stats.skipped++; console.error('Write failed:', r.error); }
    }
    offset += feats.length;
    if (feats.length < o.page) break;
    process.stderr.write(`… fetched ${stats.fetched}, written ${stats.written}\n`);
  }

  console.log(JSON.stringify({
    service: o.service, dryRun: o.dryRun, fetched: stats.fetched, mapped: stats.mapped,
    written: stats.written, skipped: stats.skipped,
    unmapped_zona: Object.entries(stats.unmapped).sort((a, b) => b[1] - a[1]).slice(0, 40),
  }, null, 2));
}
main().catch(e => { console.error(e); process.exit(1); });
