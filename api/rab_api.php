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
 *   GET  ?action=build_templates       — list build templates (RCC, Steel, Wood, etc.)
 *   GET  ?action=build_template_detail&id=X — get sections + items for a build template
 *   GET  ?action=materials_filtered&group_type=X&tier=Y — filtered materials list
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
    // ── Detailed RAB endpoints ──
    case 'projects':        handle_projects(); break;
    case 'save_project':    handle_save_project(); break;
    case 'delete_project':  handle_delete_project(); break;
    case 'project_detail':  handle_project_detail(); break;
    case 'create_rab':      handle_create_rab(); break;
    case 'clone_rab':       handle_clone_rab(); break;
    case 'delete_rab':      handle_delete_rab(); break;
    case 'rab_detail':      handle_rab_detail(); break;
    case 'disciplines':     handle_disciplines(); break;
    case 'units':           handle_units(); break;
    case 'get_sections':    handle_get_sections(); break;
    case 'save_item':       handle_save_item(); break;
    case 'delete_item':     handle_delete_item(); break;
    case 'save_section':    handle_save_section(); break;
    case 'delete_section':  handle_delete_section(); break;
    case 'update_area':     handle_update_area(); break;
    case 'recalculate':     handle_recalculate(); break;
    case 'export_excel':    handle_export_excel(); break;
    // ── Build Templates & Filtered Materials ──
    case 'build_templates':       handle_build_templates(); break;
    case 'build_template_detail': handle_build_template_detail(); break;
    case 'materials_filtered':    handle_materials_filtered(); break;
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


// =================================================================
// DETAILED RAB HELPER: recalculate_rab
// =================================================================
function recalculate_rab($db, $rab_id) {
    $rab_id = (int)$rab_id;
    $stmt = $db->prepare("
        SELECT d.code,
               COALESCE(SUM(i.quantity * i.rate), 0) AS disc_total
        FROM rab_sections s
        JOIN rab_disciplines d ON d.id = s.discipline_id
        LEFT JOIN rab_items i ON i.section_id = s.id
        WHERE s.rab_id = ?
        GROUP BY d.code
    ");
    $stmt->execute(array($rab_id));
    $rows = $stmt->fetchAll();

    $arch = 0; $mep = 0; $str = 0;
    foreach ($rows as $r) {
        if ($r['code'] === 'ARCH') $arch = (float)$r['disc_total'];
        if ($r['code'] === 'MEP')  $mep  = (float)$r['disc_total'];
        if ($r['code'] === 'STR')  $str  = (float)$r['disc_total'];
    }
    $grand = $arch + $mep + $str;

    $db->prepare("UPDATE rab_items i JOIN rab_sections s ON s.id = i.section_id SET i.total = i.quantity * i.rate WHERE s.rab_id = ?")->execute(array($rab_id));

    $ta = $db->prepare("SELECT house_area_m2 FROM rab_totals WHERE rab_id = ?");
    $ta->execute(array($rab_id));
    $ta_row = $ta->fetch();
    $house_area = $ta_row ? (float)$ta_row['house_area_m2'] : 0;
    $cost_per_m2 = ($house_area > 0) ? round($grand / $house_area, 2) : null;

    $exists = $db->prepare("SELECT id FROM rab_totals WHERE rab_id = ?");
    $exists->execute(array($rab_id));
    if ($exists->fetch()) {
        $db->prepare("UPDATE rab_totals SET architecture_total=?, mep_total=?, structure_total=?, grand_total=?, cost_per_m2=? WHERE rab_id=?")
           ->execute(array($arch, $mep, $str, $grand, $cost_per_m2, $rab_id));
    } else {
        $db->prepare("INSERT INTO rab_totals (rab_id, architecture_total, mep_total, structure_total, grand_total, cost_per_m2) VALUES (?,?,?,?,?,?)")
           ->execute(array($rab_id, $arch, $mep, $str, $grand, $cost_per_m2));
    }

    return array('arch' => $arch, 'mep' => $mep, 'str' => $str, 'grand' => $grand, 'house_area_m2' => $house_area, 'cost_per_m2' => $cost_per_m2);
}

function create_default_sections($db, $rab_id) {
    $disciplines = $db->query("SELECT id, code FROM rab_disciplines ORDER BY id")->fetchAll();
    $disc_map = array();
    foreach ($disciplines as $d) {
        $disc_map[$d['code']] = $d['id'];
    }
    $sections = array(
        'ARCH' => array('Site Works','Walls','Floors','Ceilings','Doors & Windows','Roof','Finishes','Waterproofing','External Works'),
        'MEP'  => array('Electrical','Lighting','Plumbing & Sanitary','HVAC','Fire Fighting'),
        'STR'  => array('Excavation','Foundations','Columns & Beams','Slabs','Stairs','Retaining Walls','Roof Structure'),
    );
    $stmt = $db->prepare("INSERT INTO rab_sections (rab_id, discipline_id, name, order_index) VALUES (?,?,?,?)");
    foreach ($sections as $code => $names) {
        if (!isset($disc_map[$code])) continue;
        $disc_id = $disc_map[$code];
        foreach ($names as $idx => $sname) {
            $stmt->execute(array($rab_id, $disc_id, $sname, $idx));
        }
    }
}


// =================================================================
// PROJECTS LIST
// =================================================================
function handle_projects() {
    $uid = require_auth();
    $db = get_db();
    $rows = $db->query("SELECT p.*, (SELECT COUNT(*) FROM rab_rabs r WHERE r.project_id = p.id) AS rab_count FROM rab_projects p ORDER BY p.created_at DESC")->fetchAll();
    json_out(array('data' => $rows));
}


// =================================================================
// SAVE PROJECT (create or update)
// =================================================================
function handle_save_project() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_error(405, 'POST required');
    $uid = require_auth();
    $data = get_post_data();

    $proj_id  = (int)(isset($data['id']) ? $data['id'] : 0);
    $name     = trim(isset($data['name']) ? $data['name'] : '');
    $location = trim(isset($data['location']) ? $data['location'] : '');
    $desc     = trim(isset($data['description']) ? $data['description'] : '');
    $area     = isset($data['gross_floor_area_m2']) ? (float)$data['gross_floor_area_m2'] : null;
    $status   = isset($data['status']) ? $data['status'] : 'draft';
    if (!in_array($status, array('draft','active','archived'))) $status = 'draft';
    if (!$name) json_error(400, 'Project name is required.');

    $db = get_db();
    if ($proj_id) {
        $db->prepare("UPDATE rab_projects SET name=?, location=?, description=?, gross_floor_area_m2=?, status=? WHERE id=?")
           ->execute(array($name, $location ? $location : null, $desc ? $desc : null, $area, $status, $proj_id));
    } else {
        $db->prepare("INSERT INTO rab_projects (name, location, description, gross_floor_area_m2, status) VALUES (?,?,?,?,?)")
           ->execute(array($name, $location ? $location : null, $desc ? $desc : null, $area, $status));
        $proj_id = (int)$db->lastInsertId();
    }
    json_out(array('success' => true, 'project_id' => $proj_id));
}


// =================================================================
// DELETE PROJECT
// =================================================================
function handle_delete_project() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_error(405, 'POST required');
    $uid = require_auth();
    $data = get_post_data();
    $proj_id = (int)(isset($data['id']) ? $data['id'] : 0);
    if (!$proj_id) json_error(400, 'id required');
    $db = get_db();
    $db->prepare("DELETE FROM rab_projects WHERE id=?")->execute(array($proj_id));
    json_out(array('success' => true));
}


// =================================================================
// PROJECT DETAIL (with RAB versions)
// =================================================================
function handle_project_detail() {
    $uid = require_auth();
    $id = (int)(isset($_GET['id']) ? $_GET['id'] : 0);
    if (!$id) json_error(400, 'id required');
    $db = get_db();
    $proj = $db->prepare("SELECT * FROM rab_projects WHERE id=?");
    $proj->execute(array($id));
    $project = $proj->fetch();
    if (!$project) json_error(404, 'Project not found.');

    $rabs = $db->prepare("SELECT r.*, t.grand_total, t.cost_per_m2 FROM rab_rabs r LEFT JOIN rab_totals t ON t.rab_id = r.id WHERE r.project_id = ? ORDER BY r.version DESC");
    $rabs->execute(array($id));
    $project['rabs'] = $rabs->fetchAll();

    json_out(array('data' => $project));
}


// =================================================================
// CREATE RAB
// =================================================================
function handle_create_rab() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_error(405, 'POST required');
    $uid = require_auth();
    $data = get_post_data();
    $proj_id  = (int)(isset($data['project_id']) ? $data['project_id'] : 0);
    $rab_name = trim(isset($data['name']) ? $data['name'] : '');
    if (!$proj_id) json_error(400, 'project_id required');

    $db = get_db();
    $vq = $db->prepare("SELECT COALESCE(MAX(version),0)+1 FROM rab_rabs WHERE project_id=?");
    $vq->execute(array($proj_id));
    $version = (int)$vq->fetchColumn();
    if (!$rab_name) $rab_name = 'Version ' . $version;

    $db->prepare("INSERT INTO rab_rabs (project_id, version, name) VALUES (?,?,?)")
       ->execute(array($proj_id, $version, $rab_name));
    $new_rab_id = (int)$db->lastInsertId();
    create_default_sections($db, $new_rab_id);
    $db->prepare("INSERT INTO rab_totals (rab_id, house_area_m2) VALUES (?, (SELECT gross_floor_area_m2 FROM rab_projects WHERE id=?))")
       ->execute(array($new_rab_id, $proj_id));
    recalculate_rab($db, $new_rab_id);

    json_out(array('success' => true, 'rab_id' => $new_rab_id));
}


// =================================================================
// CLONE RAB
// =================================================================
function handle_clone_rab() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_error(405, 'POST required');
    $uid = require_auth();
    $data = get_post_data();
    $rab_id = (int)(isset($data['rab_id']) ? $data['rab_id'] : 0);
    if (!$rab_id) json_error(400, 'rab_id required');

    $db = get_db();
    $src = $db->prepare("SELECT * FROM rab_rabs WHERE id=?");
    $src->execute(array($rab_id));
    $rab = $src->fetch();
    if (!$rab) json_error(404, 'RAB not found.');

    $vq = $db->prepare("SELECT COALESCE(MAX(version),0)+1 FROM rab_rabs WHERE project_id=?");
    $vq->execute(array($rab['project_id']));
    $next_v = (int)$vq->fetchColumn();

    $db->prepare("INSERT INTO rab_rabs (project_id, version, name, notes) VALUES (?,?,?,?)")
       ->execute(array($rab['project_id'], $next_v, $rab['name'] . ' (Copy)', $rab['notes']));
    $new_rab_id = (int)$db->lastInsertId();

    $sects = $db->prepare("SELECT * FROM rab_sections WHERE rab_id=? ORDER BY discipline_id, order_index");
    $sects->execute(array($rab_id));
    $sect_rows = $sects->fetchAll();
    $sect_map = array();
    $ins_sect = $db->prepare("INSERT INTO rab_sections (rab_id, discipline_id, name, order_index) VALUES (?,?,?,?)");
    foreach ($sect_rows as $s) {
        $ins_sect->execute(array($new_rab_id, $s['discipline_id'], $s['name'], $s['order_index']));
        $sect_map[$s['id']] = (int)$db->lastInsertId();
    }

    $items = $db->prepare("SELECT * FROM rab_items WHERE section_id IN (SELECT id FROM rab_sections WHERE rab_id=?) ORDER BY order_index");
    $items->execute(array($rab_id));
    $item_rows = $items->fetchAll();
    $ins_item = $db->prepare("INSERT INTO rab_items (section_id, name, description, unit_id, quantity, rate, total, order_index) VALUES (?,?,?,?,?,?,?,?)");
    foreach ($item_rows as $it) {
        $new_sect = isset($sect_map[$it['section_id']]) ? $sect_map[$it['section_id']] : null;
        if (!$new_sect) continue;
        $ins_item->execute(array(
            $new_sect, $it['name'], $it['description'],
            $it['unit_id'], $it['quantity'], $it['rate'], $it['total'], $it['order_index']
        ));
    }

    $old_totals = $db->prepare("SELECT * FROM rab_totals WHERE rab_id=?");
    $old_totals->execute(array($rab_id));
    $ot = $old_totals->fetch();
    if ($ot) {
        $db->prepare("INSERT INTO rab_totals (rab_id, architecture_total, mep_total, structure_total, grand_total, house_area_m2, cost_per_m2) VALUES (?,?,?,?,?,?,?)")
           ->execute(array($new_rab_id, $ot['architecture_total'], $ot['mep_total'], $ot['structure_total'], $ot['grand_total'], $ot['house_area_m2'], $ot['cost_per_m2']));
    }
    recalculate_rab($db, $new_rab_id);

    json_out(array('success' => true, 'rab_id' => $new_rab_id));
}


// =================================================================
// DELETE RAB
// =================================================================
function handle_delete_rab() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_error(405, 'POST required');
    $uid = require_auth();
    $data = get_post_data();
    $rab_id = (int)(isset($data['rab_id']) ? $data['rab_id'] : 0);
    if (!$rab_id) json_error(400, 'rab_id required');
    $db = get_db();
    $db->prepare("DELETE FROM rab_items WHERE section_id IN (SELECT id FROM rab_sections WHERE rab_id=?)")->execute(array($rab_id));
    $db->prepare("DELETE FROM rab_sections WHERE rab_id=?")->execute(array($rab_id));
    $db->prepare("DELETE FROM rab_totals WHERE rab_id=?")->execute(array($rab_id));
    $db->prepare("DELETE FROM rab_rabs WHERE id=?")->execute(array($rab_id));
    json_out(array('success' => true));
}


// =================================================================
// RAB DETAIL (single rab with totals)
// =================================================================
function handle_rab_detail() {
    $uid = require_auth();
    $id = (int)(isset($_GET['id']) ? $_GET['id'] : 0);
    if (!$id) json_error(400, 'id required');
    $db = get_db();
    $stmt = $db->prepare("SELECT r.*, p.name AS project_name, p.gross_floor_area_m2, p.location FROM rab_rabs r JOIN rab_projects p ON p.id=r.project_id WHERE r.id=?");
    $stmt->execute(array($id));
    $rab = $stmt->fetch();
    if (!$rab) json_error(404, 'RAB not found.');

    $totals = $db->prepare("SELECT * FROM rab_totals WHERE rab_id=?");
    $totals->execute(array($id));
    $rab['totals'] = $totals->fetch();
    if (!$rab['totals']) $rab['totals'] = array('architecture_total' => 0, 'mep_total' => 0, 'structure_total' => 0, 'grand_total' => 0, 'house_area_m2' => 0, 'cost_per_m2' => 0);

    $disc = $db->query("SELECT id, code, name FROM rab_disciplines ORDER BY id");
    $rab['disciplines'] = $disc->fetchAll();

    json_out(array('data' => $rab));
}


// =================================================================
// DISCIPLINES LIST
// =================================================================
function handle_disciplines() {
    $db = get_db();
    json_out(array('data' => $db->query("SELECT id, code, name FROM rab_disciplines ORDER BY id")->fetchAll()));
}


// =================================================================
// UNITS LIST
// =================================================================
function handle_units() {
    $db = get_db();
    json_out(array('data' => $db->query("SELECT id, code, name FROM rab_units ORDER BY name")->fetchAll()));
}


// =================================================================
// GET SECTIONS (with items) for a RAB + discipline
// =================================================================
function handle_get_sections() {
    $uid = require_auth();
    $rab_id  = (int)(isset($_GET['rab_id']) ? $_GET['rab_id'] : 0);
    $disc_id = (int)(isset($_GET['disc_id']) ? $_GET['disc_id'] : 0);
    if (!$rab_id || !$disc_id) json_error(400, 'rab_id and disc_id required');

    $db = get_db();
    $sects_q = $db->prepare("SELECT s.id, s.name, s.order_index FROM rab_sections s WHERE s.rab_id=? AND s.discipline_id=? ORDER BY s.order_index");
    $sects_q->execute(array($rab_id, $disc_id));
    $sects = $sects_q->fetchAll();

    $out = array();
    foreach ($sects as $s) {
        $items_q = $db->prepare("SELECT i.*, u.code AS unit_code FROM rab_items i LEFT JOIN rab_units u ON u.id=i.unit_id WHERE i.section_id=? ORDER BY i.order_index");
        $items_q->execute(array($s['id']));
        $s['items'] = $items_q->fetchAll();
        $out[] = $s;
    }
    json_out(array('ok' => true, 'sections' => $out));
}


// =================================================================
// SAVE ITEM
// =================================================================
function handle_save_item() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_error(405, 'POST required');
    $uid = require_auth();
    $data = get_post_data();

    $item_id    = (int)(isset($data['item_id']) ? $data['item_id'] : 0);
    $section_id = (int)(isset($data['section_id']) ? $data['section_id'] : 0);
    $name       = trim(isset($data['name']) ? $data['name'] : '');
    $unit_id    = (int)(isset($data['unit_id']) ? $data['unit_id'] : 0);
    $quantity   = (float)(isset($data['quantity']) ? $data['quantity'] : 0);
    $rate       = (float)(isset($data['rate']) ? $data['rate'] : 0);
    $total      = $quantity * $rate;

    if (!$name || !$unit_id || !$section_id) json_error(400, 'Missing required fields.');

    $db = get_db();
    if ($item_id) {
        $db->prepare("UPDATE rab_items SET name=?, unit_id=?, quantity=?, rate=?, total=? WHERE id=?")
           ->execute(array($name, $unit_id, $quantity, $rate, $total, $item_id));
    } else {
        $max_idx = $db->prepare("SELECT COALESCE(MAX(order_index),0)+1 FROM rab_items WHERE section_id=?");
        $max_idx->execute(array($section_id));
        $oidx = (int)$max_idx->fetchColumn();
        $db->prepare("INSERT INTO rab_items (section_id, name, unit_id, quantity, rate, total, order_index) VALUES (?,?,?,?,?,?,?)")
           ->execute(array($section_id, $name, $unit_id, $quantity, $rate, $total, $oidx));
        $item_id = (int)$db->lastInsertId();
    }

    $sq = $db->prepare("SELECT s.rab_id FROM rab_sections s WHERE s.id=?");
    $sq->execute(array($section_id));
    $sq_row = $sq->fetch();
    $totals = recalculate_rab($db, $sq_row['rab_id']);

    json_out(array('ok' => true, 'item_id' => $item_id, 'total' => $total, 'totals' => $totals));
}


// =================================================================
// DELETE ITEM
// =================================================================
function handle_delete_item() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_error(405, 'POST required');
    $uid = require_auth();
    $data = get_post_data();
    $item_id = (int)(isset($data['item_id']) ? $data['item_id'] : 0);
    if (!$item_id) json_error(400, 'item_id required');

    $db = get_db();
    $it = $db->prepare("SELECT s.rab_id FROM rab_items i JOIN rab_sections s ON s.id=i.section_id WHERE i.id=?");
    $it->execute(array($item_id));
    $row = $it->fetch();
    if (!$row) json_error(404, 'Item not found.');
    $db->prepare("DELETE FROM rab_items WHERE id=?")->execute(array($item_id));
    $totals = recalculate_rab($db, $row['rab_id']);
    json_out(array('ok' => true, 'totals' => $totals));
}


// =================================================================
// SAVE SECTION
// =================================================================
function handle_save_section() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_error(405, 'POST required');
    $uid = require_auth();
    $data = get_post_data();

    $section_id = (int)(isset($data['section_id']) ? $data['section_id'] : 0);
    $rab_id     = (int)(isset($data['rab_id']) ? $data['rab_id'] : 0);
    $disc_id    = (int)(isset($data['disc_id']) ? $data['disc_id'] : 0);
    $name       = trim(isset($data['name']) ? $data['name'] : '');
    if (!$name) json_error(400, 'Section name required.');

    $db = get_db();
    if ($section_id) {
        $db->prepare("UPDATE rab_sections SET name=? WHERE id=?")->execute(array($name, $section_id));
    } else {
        if (!$rab_id || !$disc_id) json_error(400, 'rab_id and disc_id required.');
        $max_oi = $db->prepare("SELECT COALESCE(MAX(order_index),0)+1 FROM rab_sections WHERE rab_id=? AND discipline_id=?");
        $max_oi->execute(array($rab_id, $disc_id));
        $oidx = (int)$max_oi->fetchColumn();
        $db->prepare("INSERT INTO rab_sections (rab_id, discipline_id, name, order_index) VALUES (?,?,?,?)")
           ->execute(array($rab_id, $disc_id, $name, $oidx));
        $section_id = (int)$db->lastInsertId();
    }
    json_out(array('ok' => true, 'section_id' => $section_id));
}


// =================================================================
// DELETE SECTION
// =================================================================
function handle_delete_section() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_error(405, 'POST required');
    $uid = require_auth();
    $data = get_post_data();
    $section_id = (int)(isset($data['section_id']) ? $data['section_id'] : 0);
    if (!$section_id) json_error(400, 'section_id required');

    $db = get_db();
    $cnt = $db->prepare("SELECT COUNT(*) FROM rab_items WHERE section_id=?");
    $cnt->execute(array($section_id));
    if ($cnt->fetchColumn() > 0) json_error(400, 'Cannot delete section with items. Remove all items first.');

    $sq = $db->prepare("SELECT rab_id FROM rab_sections WHERE id=?");
    $sq->execute(array($section_id));
    $sq_row = $sq->fetch();
    $db->prepare("DELETE FROM rab_sections WHERE id=?")->execute(array($section_id));
    recalculate_rab($db, $sq_row['rab_id']);
    json_out(array('ok' => true));
}


// =================================================================
// UPDATE AREA (house_area_m2)
// =================================================================
function handle_update_area() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_error(405, 'POST required');
    $uid = require_auth();
    $data = get_post_data();
    $rab_id = (int)(isset($data['rab_id']) ? $data['rab_id'] : 0);
    $area   = (float)(isset($data['area']) ? $data['area'] : 0);
    if (!$rab_id) json_error(400, 'rab_id required');

    $db = get_db();
    $exists = $db->prepare("SELECT id FROM rab_totals WHERE rab_id=?");
    $exists->execute(array($rab_id));
    if ($exists->fetch()) {
        $db->prepare("UPDATE rab_totals SET house_area_m2=? WHERE rab_id=?")->execute(array($area, $rab_id));
    } else {
        $db->prepare("INSERT INTO rab_totals (rab_id, house_area_m2) VALUES (?,?)")->execute(array($rab_id, $area));
    }
    $totals = recalculate_rab($db, $rab_id);
    json_out(array('ok' => true, 'totals' => $totals));
}


// =================================================================
// RECALCULATE
// =================================================================
function handle_recalculate() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_error(405, 'POST required');
    $uid = require_auth();
    $data = get_post_data();
    $rab_id = (int)(isset($data['rab_id']) ? $data['rab_id'] : 0);
    if (!$rab_id) json_error(400, 'rab_id required');
    $db = get_db();
    $totals = recalculate_rab($db, $rab_id);
    json_out(array('ok' => true, 'totals' => $totals));
}


// =================================================================
// EXPORT EXCEL
// =================================================================
function handle_export_excel() {
    $uid = require_auth();
    $id = (int)(isset($_GET['id']) ? $_GET['id'] : 0);
    if (!$id) json_error(400, 'id required');

    $db = get_db();
    $rab = $db->prepare("SELECT r.*, p.name AS project_name, p.gross_floor_area_m2 FROM rab_rabs r JOIN rab_projects p ON p.id=r.project_id WHERE r.id=?");
    $rab->execute(array($id));
    $rab_row = $rab->fetch();
    if (!$rab_row) json_error(404, 'RAB not found.');

    $disciplines = $db->query("SELECT id, code, name FROM rab_disciplines ORDER BY id")->fetchAll();
    $totals_q = $db->prepare("SELECT * FROM rab_totals WHERE rab_id=?");
    $totals_q->execute(array($id));
    $totals = $totals_q->fetch();

    $proj_name = $rab_row['project_name'];
    $safe_name = preg_replace('/[^a-zA-Z0-9\-_ ]/', '', $proj_name);
    $safe_name = str_replace(' ', '_', trim($safe_name));

    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="RAB-' . $safe_name . '-v' . $rab_row['version'] . '.xls"');
    header('Cache-Control: max-age=0');

    echo '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel">';
    echo '<head><meta charset="utf-8"><style>';
    echo 'td,th{border:1px solid #ccc;padding:4px 8px;font-size:11pt;}';
    echo '.title{font-size:14pt;font-weight:bold;border:none;}';
    echo '.subtitle{font-size:10pt;color:#555;border:none;}';
    echo '.disc-header{background:#0c7c84;color:white;font-weight:bold;}';
    echo '.sect-header{background:#1e3a5f;color:#93c5fd;font-weight:bold;}';
    echo '.col-header{background:#162032;color:#94a3b8;font-weight:bold;}';
    echo '.subtotal{background:#1a2540;font-weight:bold;}';
    echo '.grand-total{background:#0c2336;color:#0c7c84;font-weight:bold;font-size:12pt;}';
    echo '.num{text-align:right;}';
    echo '</style></head><body>';
    echo '<table cellspacing="0" cellpadding="4">';

    echo '<tr><td colspan="7" class="title" style="border:none;">RENCANA ANGGARAN BIAYA (RAB)</td></tr>';
    echo '<tr><td colspan="7" class="subtitle" style="border:none;">Proyek: ' . htmlspecialchars($proj_name, ENT_QUOTES, 'UTF-8') . ' &mdash; v' . $rab_row['version'] . '</td></tr>';
    echo '<tr><td colspan="7" class="subtitle" style="border:none;">Tanggal: ' . date('d/m/Y') . '</td></tr>';
    if ($rab_row['gross_floor_area_m2']) {
        echo '<tr><td colspan="7" class="subtitle" style="border:none;">Luas Bangunan: ' . number_format((float)$rab_row['gross_floor_area_m2'], 2, ',', '.') . ' m&sup2;</td></tr>';
    }
    echo '<tr><td colspan="7" style="border:none;">&nbsp;</td></tr>';

    echo '<tr><td colspan="7" class="disc-header">RINGKASAN / SUMMARY</td></tr>';
    echo '<tr class="col-header"><th>Disiplin</th><th colspan="5">&nbsp;</th><th class="num">Total (IDR)</th></tr>';
    if ($totals) {
        echo '<tr><td>Architecture</td><td colspan="5"></td><td class="num">' . fmt_idr($totals['architecture_total']) . '</td></tr>';
        echo '<tr><td>MEP</td><td colspan="5"></td><td class="num">' . fmt_idr($totals['mep_total']) . '</td></tr>';
        echo '<tr><td>Structure</td><td colspan="5"></td><td class="num">' . fmt_idr($totals['structure_total']) . '</td></tr>';
        echo '<tr class="grand-total"><td colspan="6">GRAND TOTAL</td><td class="num">' . fmt_idr($totals['grand_total']) . '</td></tr>';
        if ($totals['house_area_m2'] && $totals['cost_per_m2']) {
            echo '<tr><td colspan="6">Cost per m&sup2; (luas ' . number_format((float)$totals['house_area_m2'], 2, ',', '.') . ' m&sup2;)</td><td class="num">' . fmt_idr($totals['cost_per_m2']) . '</td></tr>';
        }
    }
    echo '<tr><td colspan="7" style="border:none;">&nbsp;</td></tr>';

    echo '<tr><td colspan="7" class="disc-header">RINCIAN ANGGARAN BIAYA</td></tr>';
    echo '<tr class="col-header"><th>No.</th><th>Uraian Pekerjaan</th><th>Satuan</th><th class="num">Vol.</th><th class="num">Harga Satuan (IDR)</th><th class="num">Jumlah (IDR)</th><th>&nbsp;</th></tr>';

    $item_no = 0;
    foreach ($disciplines as $disc) {
        $sects_q = $db->prepare("SELECT * FROM rab_sections WHERE rab_id=? AND discipline_id=? ORDER BY order_index");
        $sects_q->execute(array($id, $disc['id']));
        $sects = $sects_q->fetchAll();
        if (!$sects) continue;

        echo '<tr><td colspan="7" class="disc-header">' . htmlspecialchars($disc['name'], ENT_QUOTES, 'UTF-8') . '</td></tr>';
        $disc_total = 0;
        $sect_alpha = 'A';

        foreach ($sects as $sect) {
            $items_q = $db->prepare("SELECT i.*, u.code AS unit_code FROM rab_items i LEFT JOIN rab_units u ON u.id=i.unit_id WHERE i.section_id=? ORDER BY i.order_index");
            $items_q->execute(array($sect['id']));
            $sitems = $items_q->fetchAll();

            echo '<tr class="sect-header"><td>' . $sect_alpha . '</td><td colspan="5">' . htmlspecialchars($sect['name'], ENT_QUOTES, 'UTF-8') . '</td><td></td></tr>';
            $sect_alpha++;

            $sect_total = 0;
            foreach ($sitems as $it) {
                $item_no++;
                $total = (float)$it['quantity'] * (float)$it['rate'];
                $sect_total += $total;
                echo '<tr>';
                echo '<td>' . $item_no . '</td>';
                echo '<td>' . htmlspecialchars($it['name'], ENT_QUOTES, 'UTF-8') . '</td>';
                echo '<td>' . htmlspecialchars($it['unit_code'], ENT_QUOTES, 'UTF-8') . '</td>';
                echo '<td class="num">' . number_format((float)$it['quantity'], 3, ',', '.') . '</td>';
                echo '<td class="num">' . fmt_idr($it['rate']) . '</td>';
                echo '<td class="num">' . fmt_idr($total) . '</td>';
                echo '<td></td>';
                echo '</tr>';
            }
            $disc_total += $sect_total;
            echo '<tr class="subtotal"><td colspan="5" style="text-align:right;">Subtotal ' . htmlspecialchars($sect['name'], ENT_QUOTES, 'UTF-8') . '</td><td class="num">' . fmt_idr($sect_total) . '</td><td></td></tr>';
        }
        echo '<tr class="subtotal" style="background:#0c2040;"><td colspan="5" style="text-align:right;">Total ' . htmlspecialchars($disc['name'], ENT_QUOTES, 'UTF-8') . '</td><td class="num">' . fmt_idr($disc_total) . '</td><td></td></tr>';
        echo '<tr><td colspan="7" style="border:none;">&nbsp;</td></tr>';
    }

    if ($totals) {
        echo '<tr class="grand-total"><td colspan="5" style="text-align:right;">GRAND TOTAL</td><td class="num">' . fmt_idr($totals['grand_total']) . '</td><td></td></tr>';
    }

    echo '</table></body></html>';
    exit;
}

// =================================================================
// BUILD TEMPLATES — list all active build templates
// GET ?action=build_templates
// =================================================================
function handle_build_templates() {
    $db = get_db();
    $rows = $db->query("SELECT id, name, code, description, default_tier, sort_order FROM rab_build_templates WHERE is_active=1 ORDER BY sort_order, name")->fetchAll();
    json_out(array('ok' => true, 'templates' => $rows));
}

// =================================================================
// BUILD TEMPLATE DETAIL — get sections + items for a build template
// GET ?action=build_template_detail&id=X
// =================================================================
function handle_build_template_detail() {
    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if (!$id) json_error(400, 'Missing template id');
    $db = get_db();

    $tpl = $db->prepare("SELECT * FROM rab_build_templates WHERE id=? AND is_active=1");
    $tpl->execute(array($id));
    $tpl_row = $tpl->fetch();
    if (!$tpl_row) json_error(404, 'Build template not found');

    $sects = $db->prepare("
        SELECT bts.id, bts.discipline_id, bts.section_name, bts.order_index,
               d.code AS disc_code, d.name AS disc_name
        FROM rab_build_template_sections bts
        JOIN rab_disciplines d ON d.id = bts.discipline_id
        WHERE bts.build_template_id = ?
        ORDER BY d.id, bts.order_index
    ");
    $sects->execute(array($id));
    $sect_rows = $sects->fetchAll();

    $sections = array();
    foreach ($sect_rows as $s) {
        $items_q = $db->prepare("
            SELECT bti.id, bti.name, bti.unit_id, bti.default_quantity, bti.default_rate, bti.order_index,
                   u.code AS unit_code, bti.item_template_id
            FROM rab_build_template_items bti
            JOIN rab_units u ON u.id = bti.unit_id
            WHERE bti.build_template_section_id = ?
            ORDER BY bti.order_index
        ");
        $items_q->execute(array($s['id']));
        $s['items'] = $items_q->fetchAll();
        $sections[] = $s;
    }

    json_out(array('ok' => true, 'template' => $tpl_row, 'sections' => $sections));
}

// =================================================================
// MATERIALS FILTERED — get materials with optional group_type/tier filter
// GET ?action=materials_filtered&group_type=Ceilings&tier=standard
// =================================================================
function handle_materials_filtered() {
    $db = get_db();
    $group_type = isset($_GET['group_type']) ? trim($_GET['group_type']) : '';
    $tier       = isset($_GET['tier']) ? trim($_GET['tier']) : '';
    $category   = isset($_GET['category']) ? trim($_GET['category']) : '';

    $where = '1=1';
    $params = array();

    if ($group_type !== '') {
        $where .= ' AND m.group_type = ?';
        $params[] = $group_type;
    }
    if ($tier !== '' && in_array($tier, array('economy', 'standard', 'premium'))) {
        $where .= ' AND m.tier = ?';
        $params[] = $tier;
    }
    if ($category !== '') {
        $where .= ' AND m.category = ?';
        $params[] = $category;
    }

    $sql = "SELECT m.id, m.name, m.default_rate, m.currency, m.category, m.tier, m.group_type,
                   u.code AS unit_code, m.unit_id
            FROM rab_materials m
            LEFT JOIN rab_units u ON u.id = m.unit_id
            WHERE {$where}
            ORDER BY m.group_type, m.tier, m.name
            LIMIT 500";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    // Also return distinct group_types for filter dropdown
    $gt_rows = $db->query("SELECT DISTINCT group_type FROM rab_materials WHERE group_type IS NOT NULL AND group_type != '' ORDER BY group_type")->fetchAll(PDO::FETCH_COLUMN);

    json_out(array('ok' => true, 'materials' => $rows, 'group_types' => $gt_rows));
}
