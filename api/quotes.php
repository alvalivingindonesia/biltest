<?php
/**
 * Build in Lombok — Quote Engine User API (the producer side)
 *
 * Session-authed, paywalled endpoints that a paying user drives from the SPA.
 * Creates Quote Requests + items + Vendor Chats + the initial queued outbound
 * messages that api/quote_worker.php (the agent) later drains.
 *
 * Place at: /api/quotes.php
 * Requires: PHP 7.4, MySQL 5.7+ / MariaDB 10.2+
 *
 * Endpoints (?action=):
 *   POST create_request   — gate + quota, build message, fan out to vendors
 *   GET  my_requests      — list the user's Quote Requests (dashboard)
 *   GET  request_detail&id=X — items, vendor chats, threads, comparison matrix
 *   POST close_request    — close a request (owner only)
 *   GET  price_history    — curated cross-vendor price trends (Premium gate)
 */

require_once(__DIR__ . '/_sec.php');                         // SEC-008/011/021-024/055/056
require_once('/home/rovin629/config/biltest_config.php');
sec_session_start();
sec_install_json_exception_handler();

header('Content-Type: application/json; charset=utf-8');
// Same-origin SPA: no wildcard CORS + credentials combo (SEC-037).
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token');
sec_api_headers(true);

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
sec_require_same_origin();   // reject cross-site state-changing requests (SEC-008)

// ─── DB / helpers (house conventions) ────────────────────────────
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
function json_error($status, $message, $extra = array()) {
    json_out(array_merge(array('error' => $message), $extra), $status);
}
function get_current_user_id() { return isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null; }
function require_auth() {
    $uid = get_current_user_id();
    if (!$uid) json_error(401, 'Please log in to continue.');
    return $uid;
}
function get_post_data() {
    $ct = isset($_SERVER['CONTENT_TYPE']) ? $_SERVER['CONTENT_TYPE'] : '';
    if (stripos($ct, 'application/json') !== false) {
        $decoded = json_decode(file_get_contents('php://input'), true);
        return $decoded ? $decoded : array();
    }
    return $_POST;
}

// ─── Tier / feature gating (same model as rab_api.php) ───────────
function get_user_tier($uid) {
    if (!$uid) return 'guest';
    $db = get_db();
    $stmt = $db->prepare("SELECT subscription_tier, subscription_expires_at FROM users WHERE id=? AND is_active=1");
    $stmt->execute(array($uid));
    $u = $stmt->fetch();
    if (!$u) return 'guest';
    $tier = $u['subscription_tier'] ? $u['subscription_tier'] : 'free';
    if ($tier !== 'free' && $u['subscription_expires_at'] && strtotime($u['subscription_expires_at']) < time()) {
        return 'free';
    }
    return $tier;
}
function check_feature_access($feature_key, $uid) {
    $db = get_db();
    $stmt = $db->prepare("SELECT * FROM feature_access WHERE feature_key=? AND is_active=1");
    $stmt->execute(array($feature_key));
    $f = $stmt->fetch();
    if (!$f) return array('allowed' => false, 'reason' => 'feature_not_found');
    if ($f['require_login'] && !$uid) return array('allowed' => false, 'reason' => 'login_required');
    $tier = get_user_tier($uid);
    $allowed = false;
    if ($tier === 'guest')        $allowed = !$f['require_login'] && $f['tier_free'];
    elseif ($tier === 'free')     $allowed = (bool)$f['tier_free'];
    elseif ($tier === 'basic')    $allowed = (bool)$f['tier_basic'];
    elseif ($tier === 'premium')  $allowed = (bool)$f['tier_premium'];
    if (!$allowed) {
        // Smallest paid tier that unlocks it — used to sell the upgrade.
        $needed = $f['tier_basic'] ? 'basic' : ($f['tier_premium'] ? 'premium' : 'premium');
        return array('allowed' => false, 'reason' => 'tier_insufficient', 'required_tier' => $needed,
                     'feature_label' => $f['feature_label']);
    }
    return array('allowed' => true);
}
function get_plan_limit($tier, $limit_key, $default) {
    $db = get_db();
    $stmt = $db->prepare("SELECT limit_value FROM plan_limits WHERE tier=? AND limit_key=?");
    $stmt->execute(array($tier, $limit_key));
    $v = $stmt->fetchColumn();
    return ($v === false || $v === null) ? $default : (int)$v;
}

// ─── Misc helpers ────────────────────────────────────────────────
function normalize_wa($raw) {
    $d = preg_replace('/\D+/', '', (string)$raw);
    if ($d === '') return '';
    if (substr($d, 0, 1) === '0')       $d = '62' . substr($d, 1);
    elseif (substr($d, 0, 2) !== '62' && substr($d, 0, 1) === '8') $d = '62' . $d;
    return $d;
}

// Server-side twin of app.js buildMessage() — keep wording in sync.
function build_quote_message($items, $delivery, $lang) {
    $lines = array();
    foreach ($items as $it) {
        $mat = trim(isset($it['material']) ? $it['material'] : '');
        if ($mat === '') continue;
        $line = '* ' . $mat;
        $qty = trim(isset($it['quantity']) ? $it['quantity'] : '');
        $info = trim(isset($it['info']) ? $it['info'] : '');
        if ($qty !== '')  $line .= '  --  Qty : ' . $qty;
        if ($info !== '') $line .= '  --  ' . $info;
        $lines[] = $line;
    }
    $body = implode("\n", $lines);
    $loc = trim(isset($delivery['location']) ? $delivery['location'] : '');
    $maps = trim(isset($delivery['maps_url']) ? $delivery['maps_url'] : '');
    $want_delivery = !empty($delivery['required']) && $loc !== '';

    if ($lang === 'id') {
        $msg = "Halo, mohon dapat memberikan penawaran harga untuk barang-barang berikut:\n\n" . $body;
        if ($want_delivery) {
            $msg .= "\n\nMohon informasikan apakah barang-barang tersebut dapat dikirim ke \"" . $loc . "\"";
            if ($maps !== '') $msg .= "\nLokasi Google Maps: " . $maps;
            $msg .= "\nJika iya, mohon informasikan biaya pengiriman (jika ada)";
        }
        $msg .= "\n\nTerima kasih.";
    } else {
        $msg = "Hello, please can you provide a quote for the following items:\n\n" . $body;
        if ($want_delivery) {
            $msg .= "\n\nPlease advise if the items can be delivered to \"" . $loc . "\"";
            if ($maps !== '') $msg .= "\nGoogle Maps location: " . $maps;
            $msg .= "\nIf yes, please advise on the delivery fee (if any)";
        }
        $msg .= "\n\nThank you.";
    }
    return $msg;
}

function own_request_or_404($db, $request_id, $uid) {
    $s = $db->prepare("SELECT * FROM quote_requests WHERE id=? AND user_id=? AND is_active=1");
    $s->execute(array($request_id, $uid));
    $r = $s->fetch();
    if (!$r) json_error(404, 'Request not found.');
    return $r;
}

// ─── Routing ─────────────────────────────────────────────────────
$action = isset($_GET['action']) ? $_GET['action'] : '';
switch ($action) {
    case 'create_request': handle_create_request(); break;
    case 'my_requests':    handle_my_requests(); break;
    case 'request_detail': handle_request_detail(); break;
    case 'close_request':  handle_close_request(); break;
    case 'price_history':  handle_price_history(); break;
    default:               json_error(400, 'Unknown action.');
}

// =================================================================
function handle_create_request() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_error(405, 'POST required.');
    $uid = require_auth();
    $db  = get_db();

    // Gate (Q13/Q14) — return a benefit-selling payload, never a raw 403.
    $access = check_feature_access('quote_engine', $uid);
    if (!$access['allowed']) {
        json_error(403, 'upgrade_required', array(
            'reason'        => $access['reason'],
            'required_tier' => isset($access['required_tier']) ? $access['required_tier'] : 'basic',
            'feature'       => 'quote_engine',
        ));
    }
    $tier = get_user_tier($uid);

    $data      = get_post_data();
    $items_in  = isset($data['items']) && is_array($data['items']) ? $data['items'] : array();
    $providers = isset($data['provider_ids']) && is_array($data['provider_ids']) ? $data['provider_ids'] : array();
    $delivery  = isset($data['delivery']) && is_array($data['delivery']) ? $data['delivery'] : array();
    $lang      = (isset($data['lang']) && $data['lang'] === 'id') ? 'id' : 'en';

    // Validate items.
    $items = array();
    foreach ($items_in as $it) {
        if (trim(isset($it['material']) ? $it['material'] : '') !== '') $items[] = $it;
    }
    if (!$items)     json_error(400, 'Add at least one item.');
    if (!$providers) json_error(400, 'Select at least one vendor.');

    // Quotas (Q11/Q14, tunable via plan_limits).
    $max_vendors = get_plan_limit($tier, 'vendors_per_request', 12);
    if (count($providers) > $max_vendors) {
        json_error(403, 'quota_exceeded', array('scope' => 'vendors_per_request', 'limit' => $max_vendors));
    }
    $max_req = get_plan_limit($tier, 'quote_requests_per_30d', 5);
    $cnt = $db->prepare("SELECT COUNT(*) FROM quote_requests WHERE user_id=? AND is_active=1 AND created_at >= (NOW() - INTERVAL 30 DAY)");
    $cnt->execute(array($uid));
    if ((int)$cnt->fetchColumn() >= $max_req) {
        json_error(403, 'quota_exceeded', array('scope' => 'quote_requests_per_30d', 'limit' => $max_req));
    }

    $message_body = build_quote_message($items, $delivery, $lang);

    $db->beginTransaction();

    $db->prepare(
        "INSERT INTO quote_requests (user_id, status, delivery_required, delivery_location, delivery_maps_url, message_lang, message_body)
         VALUES (?, 'open', ?, ?, ?, ?, ?)"
    )->execute(array(
        $uid,
        !empty($delivery['required']) ? 1 : 0,
        isset($delivery['location']) ? substr(trim($delivery['location']), 0, 255) : null,
        isset($delivery['maps_url']) ? substr(trim($delivery['maps_url']), 0, 500) : null,
        $lang, $message_body
    ));
    $request_id = (int)$db->lastInsertId();

    // Items.
    $ins_item = $db->prepare("INSERT INTO quote_request_items (request_id, material, quantity, info, sort_order) VALUES (?,?,?,?,?)");
    $i = 0;
    foreach ($items as $it) {
        $ins_item->execute(array(
            $request_id,
            substr(trim($it['material']), 0, 255),
            isset($it['quantity']) ? substr(trim($it['quantity']), 0, 100) : null,
            isset($it['info']) ? substr(trim($it['info']), 0, 255) : null,
            $i++
        ));
    }

    // Vendors -> chats + initial queued outbound message.
    $ph = implode(',', array_fill(0, count($providers), '?'));
    $prov_stmt = $db->prepare("SELECT id, name, whatsapp_number, phone FROM providers WHERE id IN ($ph) AND is_active=1");
    $prov_stmt->execute(array_map('intval', $providers));
    $found = $prov_stmt->fetchAll();

    $ins_chat = $db->prepare(
        "INSERT INTO quote_vendor_chats (request_id, provider_id, vendor_phone, state)
         VALUES (?,?,?, 'queued')"
    );
    $ins_msg = $db->prepare(
        "INSERT INTO quote_messages (chat_id, direction, sender_kind, status, parse_status, body_raw)
         VALUES (?, 'outbound', 'user', 'queued', 'na', ?)"
    );

    $queued = 0; $skipped = array();
    foreach ($found as $p) {
        $wa = normalize_wa($p['whatsapp_number'] ? $p['whatsapp_number'] : $p['phone']);
        if (strlen($wa) < 8) { $skipped[] = $p['name']; continue; }
        $ins_chat->execute(array($request_id, (int)$p['id'], $wa));
        $chat_id = (int)$db->lastInsertId();
        $ins_msg->execute(array($chat_id, $message_body));
        $queued++;
    }

    if ($queued === 0) {
        $db->rollBack();
        json_error(400, 'None of the selected vendors have a usable WhatsApp number.');
    }

    $db->commit();
    json_out(array('ok' => true, 'request_id' => $request_id, 'vendors_queued' => $queued, 'skipped' => $skipped));
}

// =================================================================
function handle_my_requests() {
    $uid = require_auth();
    $db  = get_db();
    $rows = $db->prepare(
        "SELECT r.id, r.status, r.message_lang, r.created_at, r.updated_at, r.closed_at,
                (SELECT COUNT(*) FROM quote_request_items i WHERE i.request_id=r.id) AS item_count,
                (SELECT COUNT(*) FROM quote_vendor_chats c WHERE c.request_id=r.id) AS vendor_count,
                (SELECT COUNT(*) FROM quote_vendor_chats c WHERE c.request_id=r.id AND c.state='info_received') AS replied_count,
                (SELECT COUNT(*) FROM quote_vendor_chats c WHERE c.request_id=r.id AND c.admin_intervention=1) AS attention_count
           FROM quote_requests r
          WHERE r.user_id=? AND r.is_active=1
          ORDER BY r.created_at DESC"
    );
    $rows->execute(array($uid));
    // First item as a label.
    $list = $rows->fetchAll();
    foreach ($list as &$r) {
        $li = $db->prepare("SELECT material FROM quote_request_items WHERE request_id=? ORDER BY sort_order, id LIMIT 1");
        $li->execute(array($r['id']));
        $r['first_item'] = $li->fetchColumn();
    }
    json_out(array('ok' => true, 'data' => $list));
}

// =================================================================
function handle_request_detail() {
    $uid = require_auth();
    $db  = get_db();
    $rid = (int)($_GET['id'] ?? 0);
    if (!$rid) json_error(400, 'id required.');
    $req = own_request_or_404($db, $rid, $uid);

    // Items.
    $items = $db->prepare("SELECT id, material, quantity, info, rab_material_id FROM quote_request_items WHERE request_id=? ORDER BY sort_order, id");
    $items->execute(array($rid));
    $items = $items->fetchAll();

    // Vendor chats (+ provider display info).
    $chats = $db->prepare(
        "SELECT c.id, c.provider_id, c.vendor_phone, c.state, c.stock_status, c.follow_up_count,
                c.admin_intervention, c.admin_intervention_reason, c.last_inbound_at, c.last_outbound_at,
                p.name AS provider_name, p.slug AS provider_slug
           FROM quote_vendor_chats c
           JOIN providers p ON p.id = c.provider_id
          WHERE c.request_id=? ORDER BY c.id"
    );
    $chats->execute(array($rid));
    $chats = $chats->fetchAll();

    // Messages per chat (thread view: raw / clean ID / EN).
    $msg_stmt = $db->prepare(
        "SELECT id, chat_id, direction, sender_kind, status, parse_status,
                body_raw, body_expanded_id, body_translated_en, intent, created_at
           FROM quote_messages WHERE chat_id=? ORDER BY id"
    );
    // Price points keyed by (request_item_id, chat_id) for the matrix.
    $price_stmt = $db->prepare(
        "SELECT request_item_id, chat_id, vendor_item_label, unit_price, unit, currency, price_includes_delivery, quoted_at
           FROM historical_material_prices WHERE chat_id=? ORDER BY id"
    );

    $matrix = array(); // matrix[request_item_id][chat_id] = price cell
    foreach ($chats as &$c) {
        $msg_stmt->execute(array($c['id']));
        $c['messages'] = $msg_stmt->fetchAll();
        $price_stmt->execute(array($c['id']));
        foreach ($price_stmt->fetchAll() as $pp) {
            $k = $pp['request_item_id'] ? (int)$pp['request_item_id'] : 0;
            $matrix[$k][(int)$c['id']] = $pp; // last write wins = latest insert order
        }
    }

    json_out(array(
        'ok' => true,
        'request' => array(
            'id' => (int)$req['id'], 'status' => $req['status'], 'message_lang' => $req['message_lang'],
            'delivery_required' => (int)$req['delivery_required'], 'delivery_location' => $req['delivery_location'],
            'message_body' => $req['message_body'], 'created_at' => $req['created_at'],
        ),
        'items'  => $items,
        'chats'  => $chats,
        'matrix' => $matrix,
    ));
}

// =================================================================
function handle_close_request() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_error(405, 'POST required.');
    $uid = require_auth();
    $db  = get_db();
    $data = get_post_data();
    $rid = (int)($data['id'] ?? 0);
    if (!$rid) json_error(400, 'id required.');
    own_request_or_404($db, $rid, $uid);

    $db->prepare("UPDATE quote_requests SET status='closed', closed_at=NOW() WHERE id=?")->execute(array($rid));
    $db->prepare("UPDATE quote_vendor_chats SET state='closed' WHERE request_id=? AND state NOT IN ('closed','needs_admin')")->execute(array($rid));
    json_out(array('ok' => true));
}

// =================================================================
// Curated cross-vendor price trends (Premium gate, Q14).
function handle_price_history() {
    $uid = require_auth();
    $access = check_feature_access('quote_price_history', $uid);
    if (!$access['allowed']) {
        json_error(403, 'upgrade_required', array(
            'reason'        => $access['reason'],
            'required_tier' => isset($access['required_tier']) ? $access['required_tier'] : 'premium',
            'feature'       => 'quote_price_history',
        ));
    }
    $db = get_db();
    $rab_material_id = (int)($_GET['rab_material_id'] ?? 0);
    if (!$rab_material_id) json_error(400, 'rab_material_id required.');

    // Curated only: rab_material_id NOT NULL (ADR 0003).
    $stmt = $db->prepare(
        "SELECT h.unit_price, h.unit, h.currency, h.price_includes_delivery, h.quoted_at,
                p.name AS provider_name, p.slug AS provider_slug
           FROM historical_material_prices h
           JOIN providers p ON p.id = h.provider_id
          WHERE h.rab_material_id=? AND h.unit_price IS NOT NULL
          ORDER BY h.quoted_at DESC
          LIMIT 200"
    );
    $stmt->execute(array($rab_material_id));
    $rows = $stmt->fetchAll();

    $mat = $db->prepare("SELECT id, name FROM rab_materials WHERE id=?");
    $mat->execute(array($rab_material_id));

    json_out(array('ok' => true, 'material' => $mat->fetch(), 'prices' => $rows));
}
