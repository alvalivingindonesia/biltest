#!/usr/bin/env node
/**
 * dbq — Build in Lombok remote SQL console client.
 *
 * Sends one SQL statement to /api/db_console.php over HTTPS and prints the JSON
 * result. The endpoint enforces auth, IP allowlist, read-only-by-default and
 * audit logging — this is just an ergonomic wrapper (Node >=18, global fetch).
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
const body = o.ping ? {} : {
  sql: o.sql,
  params: o.params,
  allow_write: o.write,
  dry_run: o.dryRun,
  ...(o.maxRows ? { max_rows: o.maxRows } : {}),
};

try {
  const res = await fetch(url, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-Console-Key': key },
    body: JSON.stringify(body),
  });
  const text = await res.text();
  let json;
  try { json = JSON.parse(text); } catch { json = { ok: false, error: 'non_json_response', detail: text.slice(0, 500) }; }
  console.log(JSON.stringify(json, null, 2));
  process.exit(res.ok && json.ok ? 0 : 1);
} catch (e) {
  console.error(JSON.stringify({ ok: false, error: 'request_failed', detail: String(e.message || e) }, null, 2));
  process.exit(1);
}
