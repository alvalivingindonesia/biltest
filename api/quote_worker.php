<?php
/**
 * Build in Lombok — Quote Engine Worker API (machine-to-machine)
 *
 * The single authenticated door for the home worker agent (ADR 0002, Q4/Q5).
 * Everything is home-originated: the agent POSTs here over HTTPS to pull work
 * and post results. HostPapa never reaches into the home box.
 *
 * Place at: /api/quote_worker.php
 * Requires: PHP 7.4 (no match/enum/named-args/fn), MySQL 5.7+ / MariaDB 10.2+
 *
 * Auth: every request must carry the shared secret in a CUSTOM header
 *   X-Worker-Key: <secret>
 * defined as WORKER_API_KEY in /home/rovin629/config/biltest_config.php.
 * (Authorization: Bearer is avoided — shared hosting strips it; Q5.)
 *
 * Endpoints (all POST, JSON body, ?action=):
 *   claim_outbound      -> reclaim stale sends, then claim queued outbound rows
 *   mark_sent           -> report the result of an outbound send
 *   post_inbound        -> ingest a vendor reply; layered routing (Q9); enqueue parse
 *   claim_parse         -> fetch unparsed inbound + request-item context (Q7)
 *   post_parse_result   -> store structured parse, Price Points, guarded follow-up (Q10)
 */

require_once('/home/rovin629/config/biltest_config.php');

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

// ─── Tunables (move to config later if needed) ───────────────────
define('STALE_SENDING_MINUTES', 5);   // a 'sending' row older than this is reclaimed
define('CLAIM_LIMIT_DEFAULT', 5);     // max rows handed out per claim call
define('FOLLOWUP_CAP', 2);            // max auto-follow-ups per Vendor Chat (ADR 0004)

// ─── DB / helpers (same conventions as the rest of /api) ─────────
function get_db() {
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO('mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4', DB_USER, DB_PASS, array(
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ));
    }
    return $pdo;
}

function json_out($data, $status = 200) {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function json_error($status, $message) { json_out(array('error' => $message), $status); }

function get_post_data() {
    $ct = isset($_SERVER['CONTENT_TYPE']) ? $_SERVER['CONTENT_TYPE'] : '';
    if (stripos($ct, 'application/json') !== false) {
        $decoded = json_decode(file_get_contents('php://input'), true);
        return $decoded ? $decoded : array();
    }
    return $_POST;
}

// Read the worker key tolerantly: getallheaders(), then $_SERVER fallback.
function read_worker_key() {
    if (function_exists('getallheaders')) {
        foreach (getallheaders() as $k => $v) {
            if (strcasecmp($k, 'X-Worker-Key') === 0) return trim($v);
        }
    }
    if (isset($_SERVER['HTTP_X_WORKER_KEY'])) return trim($_SERVER['HTTP_X_WORKER_KEY']);
    return '';
}

function require_worker_auth() {
    if (!defined('WORKER_API_KEY') || WORKER_API_KEY === '') {
        json_error(500, 'Worker API key not configured on server.');
    }
    $provided = read_worker_key();
    if ($provided === '' || !hash_equals(WORKER_API_KEY, $provided)) {
        json_error(401, 'Unauthorized.');
    }
}

// Normalise a free-text material label for alias lookup (Q8/ADR 0003).
function normalize_label($s) {
    $s = mb_strtolower(trim((string)$s), 'UTF-8');
    $s = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $s); // drop punctuation
    $s = preg_replace('/\s+/u', ' ', $s);
    return trim($s);
}
// Digits only, for phone comparison.
function phone_digits($s) { return preg_replace('/\D+/', '', (string)$s); }
// Money string/number -> int (whole units in its currency).
function to_amount($v) {
    if ($v === null || $v === '' || $v === false) return null;
    $d = preg_replace('/[^\d]/', '', (string)$v);
    return $d === '' ? null : (int)$d;
}

// Any uncaught DB/logic error -> JSON 500 (the agent parses JSON, not HTML).
set_exception_handler(function ($e) {
    if (!headers_sent()) { http_response_code(500); }
    echo json_encode(array('error' => 'server_error', 'detail' => $e->getMessage()), JSON_UNESCAPED_UNICODE);
});

// ─── Routing (POST only, authenticated) ──────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_error(405, 'POST required.');
require_worker_auth();

$action = isset($_GET['action']) ? $_GET['action'] : '';
switch ($action) {
    case 'ping':              json_out(array('ok' => true, 'pong' => true)); break; // auth/header check, no side effects
    case 'claim_outbound':    handle_claim_outbound(); break;
    case 'mark_sent':         handle_mark_sent(); break;
    case 'post_inbound':      handle_post_inbound(); break;
    case 'claim_parse':       handle_claim_parse(); break;
    case 'post_parse_result': handle_post_parse_result(); break;
    default:                  json_error(400, 'Unknown action.');
}

// =================================================================
// OUTBOUND: claim queued messages to send
// =================================================================
function handle_claim_outbound() {
    $db = get_db();
    $data = get_post_data();
    $limit = isset($data['limit']) ? max(1, min(20, (int)$data['limit'])) : CLAIM_LIMIT_DEFAULT;

    // 1) Reclaim crashed sends: stale 'sending' rows -> back to 'queued' (ADR 0002).
    $db->prepare(
        "UPDATE quote_messages
            SET status='queued'
          WHERE direction='outbound' AND status='sending'
            AND updated_at < (NOW() - INTERVAL ".STALE_SENDING_MINUTES." MINUTE)"
    )->execute();

    // 2) Atomically claim queued rows (single agent: FOR UPDATE is sufficient).
    $db->beginTransaction();
    $sel = $db->prepare(
        "SELECT m.id, m.chat_id, m.body_raw, c.vendor_phone
           FROM quote_messages m
           JOIN quote_vendor_chats c ON c.id = m.chat_id
          WHERE m.direction='outbound' AND m.status='queued'
          ORDER BY m.id ASC
          LIMIT $limit
          FOR UPDATE"
    );
    $sel->execute();
    $rows = $sel->fetchAll();

    if ($rows) {
        $ids = array_column($rows, 'id');
        $ph  = implode(',', array_fill(0, count($ids), '?'));
        $db->prepare("UPDATE quote_messages SET status='sending' WHERE id IN ($ph)")->execute($ids);
    }
    $db->commit();

    $out = array();
    foreach ($rows as $r) {
        $out[] = array(
            'message_id'   => (int)$r['id'],
            'chat_id'      => (int)$r['chat_id'],
            'vendor_phone' => $r['vendor_phone'],
            'body'         => $r['body_raw'],
        );
    }
    json_out(array('ok' => true, 'messages' => $out));
}

// =================================================================
// OUTBOUND: report send result (idempotent)
// =================================================================
function handle_mark_sent() {
    $db = get_db();
    $data = get_post_data();
    $mid = (int)($data['message_id'] ?? 0);
    if (!$mid) json_error(400, 'message_id required.');

    $s = $db->prepare("SELECT id, chat_id, status FROM quote_messages WHERE id=? AND direction='outbound'");
    $s->execute(array($mid));
    $msg = $s->fetch();
    if (!$msg) json_error(404, 'Outbound message not found.');
    if ($msg['status'] === 'sent') json_out(array('ok' => true, 'already' => true)); // idempotent

    $ok = !empty($data['ok']);
    if ($ok) {
        $wa = isset($data['wa_message_id']) ? (string)$data['wa_message_id'] : null;
        $db->prepare("UPDATE quote_messages SET status='sent', wa_message_id=?, sent_at=NOW() WHERE id=?")
           ->execute(array($wa, $mid));
        // Awaiting a reply now.
        $db->prepare(
            "UPDATE quote_vendor_chats
                SET last_outbound_at=NOW(),
                    state = CASE WHEN state='queued' THEN 'awaiting_reply' ELSE state END
              WHERE id=?"
        )->execute(array($msg['chat_id']));
    } else {
        $db->prepare("UPDATE quote_messages SET status='failed' WHERE id=?")->execute(array($mid));
    }
    json_out(array('ok' => true));
}

// =================================================================
// INBOUND: ingest a vendor reply + layered routing (Q9)
// =================================================================
function handle_post_inbound() {
    $db = get_db();
    $data = get_post_data();
    $phone = isset($data['vendor_phone']) ? phone_digits($data['vendor_phone']) : '';
    $body  = isset($data['body']) ? (string)$data['body'] : '';
    $wa    = isset($data['wa_message_id']) ? (string)$data['wa_message_id'] : null;
    $reply = isset($data['reply_to_wa_message_id']) ? (string)$data['reply_to_wa_message_id'] : null;
    if ($phone === '' || $body === '') json_error(400, 'vendor_phone and body required.');

    // Idempotency: if we've already stored this inbound wa_message_id, no-op.
    if ($wa) {
        $dup = $db->prepare("SELECT id, chat_id FROM quote_messages WHERE direction='inbound' AND wa_message_id=?");
        $dup->execute(array($wa));
        $existing = $dup->fetch();
        if ($existing) json_out(array('ok' => true, 'routed' => true, 'message_id' => (int)$existing['id'], 'chat_id' => (int)$existing['chat_id'], 'duplicate' => true));
    }

    $chat_id = null;
    $needs_admin = false;
    $reason = null;

    // (1) Reply-quote match — highest confidence.
    if ($reply) {
        $q = $db->prepare("SELECT chat_id FROM quote_messages WHERE direction='outbound' AND wa_message_id=? ORDER BY id DESC LIMIT 1");
        $q->execute(array($reply));
        $hit = $q->fetch();
        if ($hit) $chat_id = (int)$hit['chat_id'];
    }

    // (2) Single open chat for this phone.
    if ($chat_id === null) {
        $q = $db->prepare(
            "SELECT id FROM quote_vendor_chats
              WHERE state IN ('queued','awaiting_reply','info_received')
                AND REPLACE(REPLACE(REPLACE(vendor_phone,'+',''),' ',''),'-','') LIKE ?
              ORDER BY last_outbound_at DESC, id DESC"
        );
        $q->execute(array('%'.$phone));
        $open = $q->fetchAll();
        if (count($open) === 1) {
            $chat_id = (int)$open[0]['id'];
        } elseif (count($open) > 1) {
            // (3) Ambiguous -> newest, but flag for a human (Q9).
            $chat_id = (int)$open[0]['id'];
            $needs_admin = true;
            $reason = 'Ambiguous inbound: multiple open chats for this number.';
        }
    }

    // (4) No open chat -> most recent chat of any state for this phone (late reply).
    if ($chat_id === null) {
        $q = $db->prepare(
            "SELECT id FROM quote_vendor_chats
              WHERE REPLACE(REPLACE(REPLACE(vendor_phone,'+',''),' ',''),'-','') LIKE ?
              ORDER BY last_outbound_at DESC, id DESC LIMIT 1"
        );
        $q->execute(array('%'.$phone));
        $any = $q->fetch();
        if ($any) {
            $chat_id = (int)$any['id'];
            $needs_admin = true;
            $reason = 'Late/unsolicited reply: no open chat for this number.';
        }
    }

    // Truly unmatched -> not an engine message. Don't store; agent logs it.
    if ($chat_id === null) {
        json_out(array('ok' => true, 'routed' => false, 'reason' => 'no_matching_chat'));
    }

    $ins = $db->prepare(
        "INSERT INTO quote_messages
            (chat_id, direction, sender_kind, status, parse_status, wa_message_id, reply_to_wa_message_id, body_raw, received_at)
         VALUES (?, 'inbound', 'vendor', 'received', 'pending', ?, ?, ?, NOW())"
    );
    $ins->execute(array($chat_id, $wa, $reply, $body));
    $new_id = (int)$db->lastInsertId();

    $db->prepare("UPDATE quote_vendor_chats SET last_inbound_at=NOW() WHERE id=?")->execute(array($chat_id));
    if ($needs_admin) {
        $db->prepare("UPDATE quote_vendor_chats SET admin_intervention=1, admin_intervention_reason=?, state='needs_admin' WHERE id=?")
           ->execute(array($reason, $chat_id));
    }

    json_out(array('ok' => true, 'routed' => true, 'message_id' => $new_id, 'chat_id' => $chat_id, 'needs_admin' => $needs_admin));
}

// =================================================================
// PARSE: hand out unparsed inbound + request-item context (Q7)
// =================================================================
function handle_claim_parse() {
    $db = get_db();
    $data = get_post_data();
    $limit = isset($data['limit']) ? max(1, min(10, (int)$data['limit'])) : 3;

    // Single agent: returning pending rows without a transient 'parsing' state
    // is safe (post_parse_result is idempotent). For multi-agent, add a claim state.
    $sel = $db->query(
        "SELECT m.id AS message_id, m.body_raw, m.chat_id,
                c.request_id, r.delivery_required, r.message_lang
           FROM quote_messages m
           JOIN quote_vendor_chats c ON c.id = m.chat_id
           JOIN quote_requests r     ON r.id = c.request_id
          WHERE m.direction='inbound' AND m.parse_status='pending'
          ORDER BY m.id ASC
          LIMIT $limit"
    );
    $rows = $sel->fetchAll();

    $items_stmt = $db->prepare(
        "SELECT id AS request_item_id, material, quantity, info
           FROM quote_request_items WHERE request_id=? ORDER BY sort_order, id"
    );

    $out = array();
    foreach ($rows as $r) {
        $items_stmt->execute(array($r['request_id']));
        $out[] = array(
            'message_id'       => (int)$r['message_id'],
            'chat_id'          => (int)$r['chat_id'],
            'body'             => $r['body_raw'],
            'delivery_required'=> (int)$r['delivery_required'],
            'lang'             => $r['message_lang'],
            // Context so the model can map each line_item to a request_item_id (Q7).
            'request_items'    => $items_stmt->fetchAll(),
        );
    }
    json_out(array('ok' => true, 'messages' => $out));
}

// =================================================================
// PARSE: store structured result, Price Points, guarded follow-up
// =================================================================
function handle_post_parse_result() {
    $db = get_db();
    $data = get_post_data();
    $mid = (int)($data['message_id'] ?? 0);
    if (!$mid) json_error(400, 'message_id required.');

    $s = $db->prepare(
        "SELECT m.id, m.chat_id, m.parse_status, c.request_id, c.provider_id, c.follow_up_count, r.delivery_required, r.message_lang
           FROM quote_messages m
           JOIN quote_vendor_chats c ON c.id = m.chat_id
           JOIN quote_requests r     ON r.id = c.request_id
          WHERE m.id=? AND m.direction='inbound'"
    );
    $s->execute(array($mid));
    $msg = $s->fetch();
    if (!$msg) json_error(404, 'Inbound message not found.');
    if ($msg['parse_status'] === 'parsed') json_out(array('ok' => true, 'already' => true)); // idempotent

    // Agent reports the model could not produce valid JSON (Q12 fallback).
    if (!empty($data['error'])) {
        $db->prepare("UPDATE quote_messages SET parse_status='failed', parsed_at=NOW() WHERE id=?")->execute(array($mid));
        $db->prepare("UPDATE quote_vendor_chats SET admin_intervention=1, admin_intervention_reason=?, state='needs_admin' WHERE id=?")
           ->execute(array('Parse failed: '.substr((string)$data['error'], 0, 200), $msg['chat_id']));
        json_out(array('ok' => true, 'parse_failed' => true));
    }

    $p = isset($data['payload']) && is_array($data['payload']) ? $data['payload'] : array();
    $chat_id    = (int)$msg['chat_id'];
    $provider_id= (int)$msg['provider_id'];
    $request_id = (int)$msg['request_id'];

    $intent      = isset($p['intent']) ? substr((string)$p['intent'], 0, 40) : null;
    $expanded    = isset($p['message_expanded_indonesian']) ? (string)$p['message_expanded_indonesian'] : null;
    $translated  = isset($p['message_translated_english']) ? (string)$p['message_translated_english'] : null;
    $suggested   = isset($p['response']) ? (string)$p['response'] : null;
    $stock       = isset($p['stock_status']) ? (string)$p['stock_status'] : 'unknown';
    if (!in_array($stock, array('available','out_of_stock','unknown'), true)) $stock = 'unknown';
    $admin_req   = !empty($p['admin_intervention_required']);
    $admin_reason= $admin_req && isset($p['admin_intervention_reason']) ? substr((string)$p['admin_intervention_reason'], 0, 255) : null;

    $db->beginTransaction();

    // Store the parsed message.
    $db->prepare(
        "UPDATE quote_messages
            SET parse_status='parsed', parsed_at=NOW(),
                intent=?, body_expanded_id=?, body_translated_en=?,
                ai_suggested_response=?, ai_payload=?
          WHERE id=?"
    )->execute(array(
        $intent, $expanded, $translated, $suggested,
        json_encode($p, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $mid
    ));

    // Re-ingestible: clear any prior Price Points for this message, then insert.
    $db->prepare("DELETE FROM historical_material_prices WHERE message_id=?")->execute(array($mid));

    $pricing = isset($p['pricing_extracted']) && is_array($p['pricing_extracted']) ? $p['pricing_extracted'] : array();
    $currency = isset($pricing['currency']) && $pricing['currency'] ? substr((string)$pricing['currency'], 0, 3) : 'IDR';
    $lines = isset($pricing['line_items']) && is_array($pricing['line_items']) ? $pricing['line_items'] : array();

    $alias_stmt = $db->prepare("SELECT rab_material_id, unit_id FROM material_aliases WHERE alias_text=? LIMIT 1");
    $item_chk   = $db->prepare("SELECT id FROM quote_request_items WHERE id=? AND request_id=?");
    $price_ins  = $db->prepare(
        "INSERT INTO historical_material_prices
            (message_id, chat_id, request_item_id, provider_id, rab_material_id,
             vendor_item_label, unit_price, unit, quantity, currency, price_includes_delivery, quoted_at)
         VALUES (?,?,?,?,?,?,?,?,?,?,?, NOW())"
    );

    $price_count = 0;
    foreach ($lines as $li) {
        $label = isset($li['item_label']) ? trim((string)$li['item_label']) : '';
        if ($label === '') continue;

        // Validate the AI's request_item mapping belongs to this request.
        $rii = null;
        if (isset($li['request_item_id']) && $li['request_item_id']) {
            $item_chk->execute(array((int)$li['request_item_id'], $request_id));
            if ($item_chk->fetch()) $rii = (int)$li['request_item_id'];
        }

        // Auto-link to the catalog via a known alias (ADR 0003) — else NULL.
        $rab_material_id = null;
        $alias_stmt->execute(array(normalize_label($label)));
        $alias = $alias_stmt->fetch();
        if ($alias) $rab_material_id = (int)$alias['rab_material_id'];

        $incl = null;
        if (array_key_exists('price_includes_delivery', $li) && $li['price_includes_delivery'] !== null) {
            $incl = !empty($li['price_includes_delivery']) ? 1 : 0;
        }

        $price_ins->execute(array(
            $mid, $chat_id, $rii, $provider_id, $rab_material_id,
            substr($label, 0, 255),
            to_amount(isset($li['unit_price']) ? $li['unit_price'] : null),
            isset($li['unit']) ? substr((string)$li['unit'], 0, 40) : null,
            isset($li['quantity']) ? substr((string)$li['quantity'], 0, 100) : null,
            $currency, $incl
        ));
        $price_count++;
    }

    // Update the chat's state + stock.
    $new_state = $admin_req ? 'needs_admin' : ($price_count > 0 ? 'info_received' : 'awaiting_reply');
    $db->prepare(
        "UPDATE quote_vendor_chats
            SET stock_status=?, state=?,
                admin_intervention = CASE WHEN ? THEN 1 ELSE admin_intervention END,
                admin_intervention_reason = CASE WHEN ? THEN ? ELSE admin_intervention_reason END
          WHERE id=?"
    )->execute(array($stock, $new_state, $admin_req ? 1 : 0, $admin_req ? 1 : 0, $admin_reason, $chat_id));

    // Guarded auto-follow-up (ADR 0004): templated, capped, never the raw LLM text.
    $followed_up = false;
    if (!empty($p['follow_up_required']) && !$admin_req && (int)$msg['follow_up_count'] < FOLLOWUP_CAP) {
        $template = build_followup_template($p, (int)$msg['delivery_required'], $msg['message_lang']);
        if ($template !== null) {
            $db->prepare(
                "INSERT INTO quote_messages (chat_id, direction, sender_kind, status, parse_status, body_raw)
                 VALUES (?, 'outbound', 'agent_auto', 'queued', 'na', ?)"
            )->execute(array($chat_id, $template));
            $db->prepare("UPDATE quote_vendor_chats SET follow_up_count = follow_up_count + 1 WHERE id=?")->execute(array($chat_id));
            $followed_up = true;
        }
    }

    $db->commit();
    json_out(array('ok' => true, 'price_points' => $price_count, 'auto_followup' => $followed_up));
}

/**
 * Build a vetted, language-matched follow-up from the *structured* missing
 * fields — never the raw LLM `response` (ADR 0004). Returns null if nothing
 * is actually missing.
 */
function build_followup_template($p, $delivery_required, $lang) {
    $pricing = isset($p['pricing_extracted']) && is_array($p['pricing_extracted']) ? $p['pricing_extracted'] : array();
    $has_price = !empty($pricing['pricing_available'])
                 && isset($pricing['line_items']) && is_array($pricing['line_items']) && count($pricing['line_items']) > 0;

    $delivery = isset($p['delivery_logistics']) && is_array($p['delivery_logistics']) ? $p['delivery_logistics'] : array();
    $delivery_unknown = $delivery_required && (!array_key_exists('delivery_possible', $delivery) || $delivery['delivery_possible'] === null);

    $use_id = (isset($p['original_language']) ? $p['original_language'] : $lang) !== 'en';

    $needs = array();
    if (!$has_price)        $needs[] = $use_id ? 'harga per item' : 'the price per item';
    if ($delivery_unknown)  $needs[] = $use_id ? 'apakah bisa dikirim dan biaya pengirimannya' : 'whether delivery is possible and the delivery cost';
    if (empty($needs)) return null;

    if ($use_id) {
        return 'Terima kasih atas balasannya. Mohon dibantu informasi: ' . implode(', ', $needs) . '. Terima kasih.';
    }
    return 'Thank you for your reply. Could you please share: ' . implode(', ', $needs) . '. Thank you.';
}
