<?php
/**
 * Build in Lombok — Remote SQL Console API (machine-to-machine)
 *
 * A single authenticated door that lets a TRUSTED operator (you, or Claude Code
 * running on your machine) execute SQL against the biltest database over HTTPS,
 * because HostPapa shared hosting offers no SSH and may firewall remote MySQL
 * (port 3306). Modelled directly on api/listing_ingest.php — same shared secret
 * + hash_equals door, same _sec.php hardening.
 *
 * Place at: /api/db_console.php   (deploys live on push to main)
 * Requires: PHP 7.4.
 *
 * ── SECURITY MODEL ──────────────────────────────────────────────────────────
 * This endpoint runs arbitrary SQL, so it is locked down in depth:
 *   1. Shared-secret token   — SQL_CONSOLE_KEY in the private config, compared
 *                              with hash_equals (constant time). No key = 401.
 *   2. Optional IP allowlist — SQL_CONSOLE_ALLOW_IPS (comma-separated). If set,
 *                              REMOTE_ADDR must match or the call is 403'd. Empty
 *                              / undefined = token-only (set it once your home IP
 *                              is known for defense-in-depth).
 *   3. Read-only by default  — SELECT/SHOW/DESCRIBE/EXPLAIN run freely. Any write
 *                              (INSERT/UPDATE/DELETE/DDL/…) is BLOCKED unless the
 *                              request carries "allow_write": true.
 *   4. Dry-run for writes    — "dry_run": true runs a write inside a transaction
 *                              and ROLLS BACK, so you can preview affected rows
 *                              without committing. (DDL auto-commits in MySQL —
 *                              dry_run cannot undo CREATE/ALTER/DROP.)
 *   5. Single statement only — stacked queries (a second ';'-separated statement)
 *                              are rejected.
 *   6. Rate limited + audited — every attempt is logged (SQL truncated, never the
 *                              param VALUES) to SQL_CONSOLE_LOG and the error log.
 *   7. HTTPS + no-store, generic errors for unexpected fatals. SQL errors ARE
 *      returned to the (already-authenticated, allowlisted) caller so you can
 *      debug — that detail never reaches an anonymous visitor.
 *
 * ── PRIVATE CONFIG (add to /home/rovin629/config/biltest_config.php) ─────────
 *   define('SQL_CONSOLE_KEY', '<64 hex chars — see README>');   // REQUIRED
 *   define('SQL_CONSOLE_ALLOW_IPS', '');                        // e.g. '203.0.113.5'
 *   define('SQL_CONSOLE_LOG', '/home/rovin629/logs/sql_console.log'); // optional
 *
 * ── REQUEST (POST, JSON body) ────────────────────────────────────────────────
 *   ?action=ping            -> auth/header/IP check only, no DB, no side effects
 *   (no action / =query)    -> run one statement:
 *     {
 *       "sql":         "SELECT * FROM users WHERE id = ?",
 *       "params":      [123],            // optional, bound positionally
 *       "allow_write": false,            // required true to run a write
 *       "dry_run":     false,            // write preview (transaction + rollback)
 *       "max_rows":    1000              // cap on returned rows (1..5000)
 *     }
 *   Auth header (preferred):  X-Console-Key: <secret>
 *   Header-strip fallback:    add "console_key": "<secret>" to the JSON body.
 *
 * ── RESPONSE ─────────────────────────────────────────────────────────────────
 *   read : { ok, mode:"read",  row_count, truncated, columns:[...], rows:[...] }
 *   write: { ok, mode:"write", affected_rows, last_insert_id, dry_run, committed }
 *   error: { ok:false, error:"...", detail?:"<sql error for debugging>" }
 */

require_once(__DIR__ . '/_sec.php');                         // error hardening (SEC-055)
require_once('/home/rovin629/config/biltest_config.php');

header('Content-Type: application/json; charset=utf-8');
sec_api_headers(true);                                        // nosniff + no-store (SEC-056)
sec_install_json_exception_handler();                        // generic 500 on unexpected fatals (SEC-023)

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

define('SQLC_MAX_SQL_BYTES', 100 * 1024);   // 100 KB statement cap
define('SQLC_DEFAULT_ROWS', 1000);
define('SQLC_MAX_ROWS', 5000);

function get_db() {
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO('mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4', DB_USER, DB_PASS, array(
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,             // real prepares: driver won't run stacked queries
        ));
    }
    return $pdo;
}
function json_out($data, $status = 200) {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function json_error($status, $message, $detail = null) {
    $out = array('ok' => false, 'error' => $message);
    if ($detail !== null) $out['detail'] = $detail;
    json_out($out, $status);
}

function raw_post_body() {
    static $raw = null;
    if ($raw === null) $raw = file_get_contents('php://input');
    return $raw;
}
function get_post_data() {
    $ct = isset($_SERVER['CONTENT_TYPE']) ? $_SERVER['CONTENT_TYPE'] : '';
    if (stripos($ct, 'application/json') !== false) {
        $d = json_decode(raw_post_body(), true);
        return is_array($d) ? $d : array();
    }
    return $_POST;
}

// ── Auth: shared secret in X-Console-Key, with a JSON-body fallback for hosts
//    that strip custom request headers (mirrors listing_ingest.php). ──────────
function read_console_key() {
    if (function_exists('getallheaders')) {
        foreach (getallheaders() as $k => $v) if (strcasecmp($k, 'X-Console-Key') === 0) return trim($v);
    }
    if (isset($_SERVER['HTTP_X_CONSOLE_KEY']) && $_SERVER['HTTP_X_CONSOLE_KEY'] !== '') return trim($_SERVER['HTTP_X_CONSOLE_KEY']);
    $ct = isset($_SERVER['CONTENT_TYPE']) ? $_SERVER['CONTENT_TYPE'] : '';
    if (stripos($ct, 'application/json') !== false) {
        $d = json_decode(raw_post_body(), true);
        if (is_array($d) && !empty($d['console_key'])) return trim((string)$d['console_key']);
    }
    if (!empty($_POST['console_key'])) return trim((string)$_POST['console_key']);
    return '';
}
function require_console_auth() {
    if (!defined('SQL_CONSOLE_KEY') || SQL_CONSOLE_KEY === '') json_error(500, 'SQL console key not configured.');
    $p = read_console_key();
    if ($p === '' || !hash_equals(SQL_CONSOLE_KEY, $p)) json_error(401, 'Unauthorized.');
}
function console_ip_ok() {
    if (!defined('SQL_CONSOLE_ALLOW_IPS') || trim((string)SQL_CONSOLE_ALLOW_IPS) === '') return true; // token-only
    $allow = array_filter(array_map('trim', explode(',', (string)SQL_CONSOLE_ALLOW_IPS)));
    return in_array(sec_client_ip(), $allow, true);
}

// ── Audit log: one JSON line per attempt. SQL is truncated; param VALUES are
//    never logged (only the count) so secrets in bound params don't land in logs.
function console_log(array $entry) {
    $entry = array('ts' => date('c'), 'ip' => sec_client_ip()) + $entry;
    $line = json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (defined('SQL_CONSOLE_LOG') && SQL_CONSOLE_LOG) {
        @file_put_contents(SQL_CONSOLE_LOG, $line . "\n", FILE_APPEND | LOCK_EX);
    }
    error_log('[biltest] db_console ' . $line);
}

// ── SQL classification + safety guards ───────────────────────────────────────
function strip_leading_comments($s) {
    $s = ltrim($s);
    while ($s !== '') {
        if (substr($s, 0, 2) === '--' || substr($s, 0, 1) === '#') {
            $nl = strpos($s, "\n");
            $s = ($nl === false) ? '' : ltrim(substr($s, $nl + 1));
            continue;
        }
        if (substr($s, 0, 2) === '/*') {
            $end = strpos($s, '*/');
            $s = ($end === false) ? '' : ltrim(substr($s, $end + 2));
            continue;
        }
        break;
    }
    return $s;
}
/** 'read' for SELECT/SHOW/DESCRIBE/DESC/EXPLAIN; everything else (incl. WITH) is 'write'. */
function classify_sql($sql) {
    $s = strip_leading_comments($sql);
    return preg_match('/^\s*(SELECT|SHOW|DESCRIBE|DESC|EXPLAIN)\b/i', $s) ? 'read' : 'write';
}
/** True if more than one statement is present (a ';' beyond an optional trailing one). */
function has_multiple_statements($sql) {
    $t = rtrim($sql);
    $t = rtrim($t, ';');           // drop a single trailing terminator
    return strpos($t, ';') !== false;
}

// ── Entry point ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_error(405, 'POST required.');

if (!sec_rate_ok('sqlconsole', sec_client_ip(), 240, 60)) json_error(429, 'Rate limit exceeded.');

require_console_auth();
if (!console_ip_ok()) { console_log(array('event' => 'ip_blocked')); json_error(403, 'Forbidden (IP not allowed).'); }

$action = isset($_GET['action']) ? $_GET['action'] : 'query';
if ($action === 'ping') json_out(array('ok' => true, 'pong' => true));
if ($action !== 'query') json_error(400, 'Unknown action.');

$body = get_post_data();
$sql  = isset($body['sql']) ? (string)$body['sql'] : '';
if (trim($sql) === '') json_error(400, 'Missing "sql".');
if (strlen($sql) > SQLC_MAX_SQL_BYTES) json_error(413, 'SQL too large.');

$params = isset($body['params']) && is_array($body['params']) ? array_values($body['params']) : array();
foreach ($params as $p) {
    if ($p !== null && !is_scalar($p)) json_error(400, 'Each param must be a scalar or null.');
}
$allow_write = !empty($body['allow_write']);
$dry_run     = !empty($body['dry_run']);
$max_rows    = isset($body['max_rows']) ? (int)$body['max_rows'] : SQLC_DEFAULT_ROWS;
if ($max_rows < 1) $max_rows = 1;
if ($max_rows > SQLC_MAX_ROWS) $max_rows = SQLC_MAX_ROWS;

if (has_multiple_statements($sql)) {
    console_log(array('event' => 'rejected', 'reason' => 'multi_statement', 'sql' => substr($sql, 0, 2000)));
    json_error(400, 'Only a single statement is allowed.');
}

$mode = classify_sql($sql);

if ($mode === 'write' && !$allow_write) {
    console_log(array('event' => 'write_blocked', 'sql' => substr($sql, 0, 2000)));
    json_error(403, 'Write blocked. Resend with "allow_write": true to run a write statement.');
}

try {
    $pdo = get_db();

    if ($mode === 'read') {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
        $truncated = false;
        if (count($rows) > $max_rows) { $rows = array_slice($rows, 0, $max_rows); $truncated = true; }
        $columns = !empty($rows) ? array_keys($rows[0]) : array();
        console_log(array('event' => 'read_ok', 'rows' => count($rows), 'truncated' => $truncated, 'sql' => substr($sql, 0, 2000)));
        json_out(array(
            'ok' => true, 'mode' => 'read',
            'row_count' => count($rows), 'truncated' => $truncated,
            'columns' => $columns, 'rows' => $rows,
        ));
    }

    // write
    $committed = false;
    if ($dry_run) {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $affected = $stmt->rowCount();
        if ($pdo->inTransaction()) $pdo->rollBack();   // DDL may have auto-committed; rollBack is then a no-op
        $committed = false;
    } else {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $affected = $stmt->rowCount();
        $committed = true;
    }
    $last_id = $pdo->lastInsertId();
    console_log(array('event' => 'write_ok', 'affected' => $affected, 'dry_run' => $dry_run, 'committed' => $committed, 'sql' => substr($sql, 0, 2000)));
    json_out(array(
        'ok' => true, 'mode' => 'write',
        'affected_rows' => $affected,
        'last_insert_id' => ($last_id === '0' || $last_id === 0) ? null : $last_id,
        'dry_run' => $dry_run, 'committed' => $committed,
    ));

} catch (PDOException $e) {
    // Authenticated + allowlisted caller — return the DB error so you can debug.
    console_log(array('event' => 'sql_error', 'mode' => $mode, 'detail' => $e->getMessage(), 'sql' => substr($sql, 0, 2000)));
    json_error(400, 'sql_error', $e->getMessage());
}
