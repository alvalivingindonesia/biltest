#!/usr/bin/env node
/**
 * dbq — Build in Lombok remote SQL console client.
 *
 * Sends one SQL statement to /api/db_console.php over HTTPS and prints the JSON
 * result. The endpoint enforces auth, IP allowlist, read-only-by-default and
 * audit logging — this is just an ergonomic wrapper.
 *
 * TRANSPORT: shells out to `curl` rather than Node's fetch. On this Windows box
 * the HostPapa cert chain is missing its intermediate, which Node/OpenSSL won't
 * auto-fetch (UNABLE_TO_VERIFY_LEAF_SIGNATURE), and the revocation server is
 * unreachable (CRYPT_E_NO_REVOCATION_CHECK). curl validates the chain via the
 * Windows cert store (which fetches the intermediate) and we only soft-fail the
 * revocation check — TLS chain + leaf verification stay ON. The auth key is sent
 * in the JSON body (an endpoint-supported fallback), never on the command line.
 *
 * KEY RESOLUTION (first that exists):
 *   --key <k>  |  $SQL_CONSOLE_KEY  |  config/sql_console.key (gitignored)
 * ENDPOINT:    --endpoint <url>  |  $DBQ_ENDPOINT  |  default below.
 *
 * USAGE:
 *   node tools/dbq.mjs "SELECT id,name FROM developers LIMIT 5"
 *   node tools/dbq.mjs --param 123 "SELECT * FROM listings WHERE id = ?"
 *   node tools/dbq.mjs --write --dry-run "DELETE FROM listings WHERE is_active = 0"
 *   node tools/dbq.mjs --write "UPDATE feature_access SET allowed = 1 WHERE id = 7"
 *   node tools/dbq.mjs --ping
 *
 * FLAGS:
 *   --write           allow a write statement (else writes are 403'd)
 *   --dry-run         run the write in a transaction and roll back (preview)
 *   --param <v>       bind one positional ? param (repeatable, in order)
 *   --max-rows <n>    cap returned rows (1..5000, default 1000)
 *   --ping            connectivity/auth check only
 *   --endpoint <url>  override endpoint
 *   --key <k>         override key
 */
import { readFileSync } from 'node:fs';
import { spawn } from 'node:child_process';

const DEFAULT_ENDPOINT = 'https://biltest.roving-i.com.au/api/db_console.php';

function parseArgs(argv) {
  const o = { params: [], write: false, dryRun: false, ping: false, maxRows: null, endpoint: null, key: null };
  const sql = [];
  for (let i = 0; i < argv.length; i++) {
    const a = argv[i];
    switch (a) {
      case '--write':    o.write = true; break;
      case '--dry-run':  o.dryRun = true; o.write = true; break;
      case '--ping':     o.ping = true; break;
      case '--param':    o.params.push(argv[++i]); break;
      case '--max-rows': o.maxRows = parseInt(argv[++i], 10); break;
      case '--endpoint': o.endpoint = argv[++i]; break;
      case '--key':      o.key = argv[++i]; break;
      default:           sql.push(a);
    }
  }
  o.sql = sql.join(' ');
  return o;
}

function resolveKey(o) {
  if (o.key) return o.key.trim();
  if (process.env.SQL_CONSOLE_KEY) return process.env.SQL_CONSOLE_KEY.trim();
  try { return readFileSync(new URL('../config/sql_console.key', import.meta.url), 'utf8').trim(); }
  catch { return ''; }
}

// POST a JSON body to `url` via curl. Returns { status, bodyText }.
function curlPost(url, bodyObj) {
  return new Promise((resolve, reject) => {
    const args = ['-sS', '-m', '30'];
    if (process.platform === 'win32') args.push('--ssl-revoke-best-effort'); // schannel can't reach OCSP/CRL here
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

const o = parseArgs(process.argv.slice(2));
const endpoint = o.endpoint || process.env.DBQ_ENDPOINT || DEFAULT_ENDPOINT;
const key = resolveKey(o);

if (!key) {
  console.error('No console key. Pass --key, set $SQL_CONSOLE_KEY, or create config/sql_console.key');
  process.exit(2);
}
if (!o.ping && !o.sql) {
  console.error('No SQL given. Quote it: node tools/dbq.mjs "SELECT 1"');
  process.exit(2);
}

const url = o.ping ? `${endpoint}?action=ping` : `${endpoint}?action=query`;
const body = o.ping
  ? { console_key: key }
  : {
      sql: o.sql,
      params: o.params,
      allow_write: o.write,
      dry_run: o.dryRun,
      console_key: key,
      ...(o.maxRows ? { max_rows: o.maxRows } : {}),
    };

try {
  const { status, bodyText } = await curlPost(url, body);
  let json;
  try { json = JSON.parse(bodyText); }
  catch { json = { ok: false, error: 'non_json_response', http: status, detail: bodyText.slice(0, 500) }; }
  console.log(JSON.stringify(json, null, 2));
  process.exit(json.ok ? 0 : 1);
} catch (e) {
  console.error(JSON.stringify({ ok: false, error: 'request_failed', detail: String(e.message || e) }, null, 2));
  process.exit(1);
}
