<?php
/**
 * Build in Lombok — RAB API (Frontend)
 * Handles: calculator, save/load estimates, feature access checks
 *
 * Place at: /api/rab_api.php
 * Requires: PHP 7.x compatible — NO match(), NO fn(), NO named arguments
 *
 * Endpoints:
 *   GET  ?action=presets           — list calculator presets
 *   POST ?action=calculate         — run calculator, return result (optionally save)
 *   GET  ?action=my_estimates      — list saved estimates for current user
 *   POST ?action=save_estimate     — save/name a calculator run
 *   POST ?action=delete_estimate   — delete a saved estimate
 *   GET  ?action=estimate&id=X     — get single estimate detail
 *   GET  ?action=check_feature&key=X — check if current user can access a feature
 *   GET  ?action=feature_list      — list all features with access per tier
 */

session_start();
require_once('/home/rovin629/config/biltest_config.php');

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Credentials: true');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ─── DB ──────────────────────────────────────────────────────────
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

function json_error($status, $message) {
    json_out(array('error' => $message), $status);
}

function get_current_user_id() {
    return isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
}

function require_auth() {
    $uid = get_current_user_id();
    if (!$uid) { json_error(401, 'Please log in to continue.'); }
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

function get_user_tier($uid) {
    if (!$uid) return 'guest';
    $db = get_db();
    $stmt = $db->prepare("SELECT subscription_tier, subscription_expires_at FROM users WHERE id = ? AND is_active = 1");
    $stmt->execute(array($uid));
    $user = $stmt->fetch();
    if (!$user) return 'guest';

    // Check if subscription expired
    $tier = $user['subscription_tier'] ? $user['subscription_tier'] : 'free';
    if ($tier !== 'free' && $user['subscription_expires_at']) {
        if (strtotime($user['subscription_expires_at']) < time()) {
            return 'free'; // Expired, treat as free
        }
    }
    return $tier;
}

function check_feature_access($feature_key, $uid = null) {
    $db = get_db();
    $stmt = $db->prepare("SELECT * FROM feature_access WHERE feature_key = ? AND is_active = 1");
    $stmt->execute(array($feature_key));
    $feature = $stmt->fetch();
    if (!$feature) return array('allowed' => false, 'reason' => 'Feature not found');

    // Check require_login
    if ($feature['require_login'] && !$uid) {
        return array('allowed' => false, 'reason' => 'login_required');
    }

    $tier = get_user_tier($uid);
    $allowed = false;
    if ($tier === 'guest') {
        // Guests only get access if require_login is false AND tier_free is true
        $allowed = !$feature['require_login'] && $feature['tier_free'];
    } elseif ($tier === 'free') {
        $allowed = (bool)$feature['tier_free'];
    } elseif ($tier === 'basic') {
        $allowed = (bool)$feature['tier_basic'];
    } elseif ($tier === 'premium') {
        $allowed = (bool)$feature['tier_premium'];
    }

    if (!$allowed) {
        return array('allowed' => false, 'reason' => 'tier_insufficient', 'required_tier' => 'premium');
    }
    return array('allowed' => true);
}

function fmt_idr($val) {
    return 'Rp ' . number_format((float)$val, 0, ',', '.');
}

// ─── ROUTING ─────────────────────────────────────────────────────
$action = isset($_GET['action']) ? $_GET['action'] : '';

switch ($action) {
    case 'presets':         handle_presets(); break;
    case 'calculate':       handle_calculate(); break;
    case 'my_estimates':    handle_my_estimates(); break;
    case 'save_estimate':   handle_save_estimate(); break;
    case 'delete_estimate': handle_delete_estimate(); break;
    case 'estimate':        handle_estimate(); break;
    case 'check_feature':   handle_check_feature(); break;
    case 'feature_list':    handle_feature_list(); break;
    default:                json_error(400, 'Unknown action');
}


// =================================================================
// PRESETS — public, returns calculator presets
// =================================================================
function handle_presets() {
    $db = get_db();
    $presets = $db->query("SELECT id, name, description, is_default,
        base_cost_per_m2_low, base_cost_per_m2_mid, base_cost_per_m2_high,
        pool_cost_per_m2_standard, pool_cost_per_m2_infinity,
        deck_cost_per_m2, rooftop_cost_per_m2,
        location_factor, contingency_percent
        FROM rab_calculator_presets ORDER BY is_default DESC, name ASC")->fetchAll();
    json_out(array('data' => $presets));
}


// =================================================================
// CALCULATE — run the cost calculator
// =================================================================
function handle_calculate() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { json_error(405, 'POST required'); }
    $data = get_post_data();

    $preset_id    = (int)(isset($data['preset_id']) ? $data['preset_id'] : 0);
    $quality      = isset($data['quality']) ? $data['quality'] : 'mid';
    if (!in_array($quality, array('low','mid','high'))) $quality = 'mid';
    $num_storeys  = (int)(isset($data['num_storeys']) ? $data['num_storeys'] : 1);
    $fa1          = (float)(isset($data['floor_area_1']) ? $data['floor_area_1'] : 0);
    $fa2          = (float)(isset($data['floor_area_2']) ? $data['floor_area_2'] : 0);
    $fa3          = (float)(isset($data['floor_area_3']) ? $data['floor_area_3'] : 0);
    $fa4          = (float)(isset($data['floor_area_4']) ? $data['floor_area_4'] : 0);
    $walkable     = !empty($data['walkable_rooftop']) ? 1 : 0;
    $rooftop_area = (float)(isset($data['rooftop_area']) ? $data['rooftop_area'] : 0);
    $has_pool     = !empty($data['has_pool']) ? 1 : 0;
    $pool_inf     = (isset($data['pool_type']) && $data['pool_type'] === 'infinity') ? 1 : 0;
    $pool_area    = (float)(isset($data['pool_area']) ? $data['pool_area'] : 0);
    $deck_area    = (float)(isset($data['deck_area']) ? $data['deck_area'] : 0);
    $estimate_name = isset($data['name']) ? trim($data['name']) : '';
    $save_it       = !empty($data['save']);

    $db = get_db();
    $p_q = $db->prepare("SELECT * FROM rab_calculator_presets WHERE id=?");
    $p_q->execute(array($preset_id));
    $preset = $p_q->fetch();
    if (!$preset) { json_error(400, 'Preset not found.'); }

    $col = 'base_cost_per_m2_' . $quality;
    $building_rate  = (float)$preset[$col];
    $loc            = (float)$preset['location_factor'];
    $cont_pct       = (float)$preset['contingency_percent'];

    $total_floor    = $fa1 + $fa2 + $fa3 + $fa4;
    if ($total_floor <= 0) { json_error(400, 'Please enter at least one floor area.'); }

    $building_cost  = $total_floor * $building_rate * $loc;
    $rooftop_cost   = $walkable ? ($rooftop_area * (float)$preset['rooftop_cost_per_m2'] * $loc) : 0;
    $pool_rate_col  = $pool_inf ? 'pool_cost_per_m2_infinity' : 'pool_cost_per_m2_standard';
    $pool_cost      = $has_pool ? ($pool_area * (float)$preset[$pool_rate_col] * $loc) : 0;
    $deck_cost      = $deck_area * (float)$preset['deck_cost_per_m2'] * $loc;
    $subtotal       = $building_cost + $rooftop_cost + $pool_cost + $deck_cost;
    $contingency    = $subtotal * ($cont_pct / 100);
    $grand_total    = $subtotal + $contingency;

    // Determine user_id if logged in
    $uid = get_current_user_id();
    $is_saved = 0;
    if ($save_it && $uid) {
        // Check feature access for saving
        $access = check_feature_access('rab_calculator_save', $uid);
        if ($access['allowed']) {
            $is_saved = 1;
        }
    }

    $ins = $db->prepare("INSERT INTO rab_calculator_runs
        (user_id, name, is_saved, preset_id, quality_level, num_storeys,
         floor_area_level1_m2, floor_area_level2_m2, floor_area_level3_m2, floor_area_other_m2,
         rooftop_walkable, rooftop_area_m2,
         pool_has_pool, pool_is_infinity, pool_area_m2,
         deck_area_m2, total_floor_area_m2,
         building_cost, rooftop_cost, pool_cost, deck_cost,
         subtotal_cost, contingency_amount, grand_total_cost)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
    $ins->execute(array(
        $uid, $estimate_name ? $estimate_name : null, $is_saved,
        $preset_id, $quality, $num_storeys,
        $fa1, $fa2, $fa3, $fa4,
        $walkable, $rooftop_area,
        $has_pool, $pool_inf, $pool_area,
        $deck_area, $total_floor,
        $building_cost, $rooftop_cost, $pool_cost, $deck_cost,
        $subtotal, $contingency, $grand_total
    ));
    $run_id = (int)$db->lastInsertId();

    $ql_map = array('low' => 'Economy', 'mid' => 'Standard', 'high' => 'Premium');

    json_out(array(
        'success' => true,
        'run_id' => $run_id,
        'is_saved' => $is_saved,
        'result' => array(
            'id' => $run_id,
            'preset_name' => $preset['name'],
            'quality_label' => isset($ql_map[$quality]) ? $ql_map[$quality] : $quality,
            'quality_level' => $quality,
            'num_storeys' => $num_storeys,
            'floor_areas' => array($fa1, $fa2, $fa3, $fa4),
            'total_floor_area' => $total_floor,
            'walkable_rooftop' => $walkable,
            'rooftop_area' => $rooftop_area,
            'has_pool' => $has_pool,
            'pool_is_infinity' => $pool_inf,
            'pool_area' => $pool_area,
            'deck_area' => $deck_area,
            'building_cost' => round($building_cost, 2),
            'rooftop_cost' => round($rooftop_cost, 2),
            'pool_cost' => round($pool_cost, 2),
            'deck_cost' => round($deck_cost, 2),
            'subtotal' => round($subtotal, 2),
            'contingency_pct' => $cont_pct,
            'contingency' => round($contingency, 2),
            'grand_total' => round($grand_total, 2),
            'building_rate' => $building_rate,
            'location_factor' => $loc,
        )
    ));
}


// =================================================================
// MY ESTIMATES — list saved estimates for logged-in user
// =================================================================
function handle_my_estimates() {
    $uid = require_auth();
    $db = get_db();
    $stmt = $db->prepare("SELECT r.id, r.name, r.quality_level, r.num_storeys,
        r.total_floor_area_m2, r.grand_total_cost, r.created_at,
        p.name as preset_name
        FROM rab_calculator_runs r
        LEFT JOIN rab_calculator_presets p ON p.id = r.preset_id
        WHERE r.user_id = ? AND r.is_saved = 1
        ORDER BY r.created_at DESC");
    $stmt->execute(array($uid));
    $rows = $stmt->fetchAll();

    $ql_map = array('low' => 'Economy', 'mid' => 'Standard', 'high' => 'Premium');
    $results = array();
    foreach ($rows as $r) {
        $r['quality_label'] = isset($ql_map[$r['quality_level']]) ? $ql_map[$r['quality_level']] : $r['quality_level'];
        $results[] = $r;
    }

    json_out(array('data' => $results));
}


// =================================================================
// SAVE ESTIMATE — mark a run as saved (with optional name)
// =================================================================
function handle_save_estimate() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { json_error(405, 'POST required'); }
    $uid = require_auth();

    // Check feature access
    $access = check_feature_access('rab_calculator_save', $uid);
    if (!$access['allowed']) {
        json_error(403, 'Saving estimates requires a Basic or Premium subscription.');
    }

    $data = get_post_data();
    $run_id = (int)(isset($data['run_id']) ? $data['run_id'] : 0);
    $name = isset($data['name']) ? trim($data['name']) : '';

    if (!$run_id) { json_error(400, 'run_id required'); }

    $db = get_db();
    // Verify ownership or unclaimed
    $stmt = $db->prepare("SELECT id, user_id FROM rab_calculator_runs WHERE id = ?");
    $stmt->execute(array($run_id));
    $run = $stmt->fetch();
    if (!$run) { json_error(404, 'Estimate not found.'); }
    if ($run['user_id'] && (int)$run['user_id'] !== $uid) { json_error(403, 'Not your estimate.'); }

    $db->prepare("UPDATE rab_calculator_runs SET user_id = ?, name = ?, is_saved = 1 WHERE id = ?")
       ->execute(array($uid, $name ? $name : null, $run_id));

    json_out(array('success' => true, 'message' => 'Estimate saved.'));
}


// =================================================================
// DELETE ESTIMATE
// =================================================================
function handle_delete_estimate() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { json_error(405, 'POST required'); }
    $uid = require_auth();
    $data = get_post_data();
    $run_id = (int)(isset($data['run_id']) ? $data['run_id'] : 0);

    if (!$run_id) { json_error(400, 'run_id required'); }

    $db = get_db();
    $stmt = $db->prepare("SELECT id, user_id FROM rab_calculator_runs WHERE id = ?");
    $stmt->execute(array($run_id));
    $run = $stmt->fetch();
    if (!$run) { json_error(404, 'Estimate not found.'); }
    if ((int)$run['user_id'] !== $uid) { json_error(403, 'Not your estimate.'); }

    $db->prepare("UPDATE rab_calculator_runs SET is_saved = 0 WHERE id = ?")->execute(array($run_id));
    json_out(array('success' => true, 'message' => 'Estimate removed.'));
}


// =================================================================
// SINGLE ESTIMATE DETAIL
// =================================================================
function handle_estimate() {
    $id = (int)(isset($_GET['id']) ? $_GET['id'] : 0);
    if (!$id) { json_error(400, 'id required'); }

    $db = get_db();
    $stmt = $db->prepare("SELECT r.*, p.name as preset_name, p.description as preset_description
        FROM rab_calculator_runs r
        LEFT JOIN rab_calculator_presets p ON p.id = r.preset_id
        WHERE r.id = ?");
    $stmt->execute(array($id));
    $run = $stmt->fetch();
    if (!$run) { json_error(404, 'Estimate not found.'); }

    // If saved and belongs to a user, check ownership
    $uid = get_current_user_id();
    if ($run['is_saved'] && $run['user_id'] && $uid !== (int)$run['user_id']) {
        json_error(403, 'Access denied.');
    }

    $ql_map = array('low' => 'Economy', 'mid' => 'Standard', 'high' => 'Premium');
    $run['quality_label'] = isset($ql_map[$run['quality_level']]) ? $ql_map[$run['quality_level']] : $run['quality_level'];

    json_out(array('data' => $run));
}


// =================================================================
// CHECK FEATURE ACCESS
// =================================================================
function handle_check_feature() {
    $key = isset($_GET['key']) ? $_GET['key'] : '';
    if (!$key) { json_error(400, 'key required'); }

    $uid = get_current_user_id();
    $result = check_feature_access($key, $uid);
    $result['tier'] = get_user_tier($uid);
    $result['logged_in'] = $uid ? true : false;

    json_out($result);
}


// =================================================================
// FEATURE LIST — all features with tier access info
// =================================================================
function handle_feature_list() {
    $db = get_db();
    $features = $db->query("SELECT feature_key, feature_label, description,
        tier_free, tier_basic, tier_premium, require_login, sort_order
        FROM feature_access WHERE is_active = 1 ORDER BY sort_order ASC")->fetchAll();

    $uid = get_current_user_id();
    $tier = get_user_tier($uid);

    json_out(array(
        'data' => $features,
        'current_tier' => $tier,
        'logged_in' => $uid ? true : false
    ));
}
