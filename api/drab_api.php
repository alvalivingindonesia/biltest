<?php
/**
 * Build in Lombok — Detailed RAB Generator API (ADR 0012)
 * Namespace: drab_*   (isolated from the old rab_* tool, which stays as a backup)
 * PHP 7.4 compatible — NO match(), NO fn(), NO named args, NO enums.
 *
 * The wizard collects drivers + style/structure/roof/finish/site, then `generate`
 * builds a full RAB (Development -> Building -> RAB -> Sections -> Items) from the
 * parametric overlay templates. Confirmed pricing + clean export are premium-gated;
 * free users are priced from Indicative numbers only (never see Confirmed rates).
 *
 * Key endpoints (see router):
 *   GET  ?action=meta                 — styles/structures/roofs/tiers/zones/units/slots
 *   POST ?action=generate             — create + populate a RAB from wizard inputs
 *   GET  ?action=developments         — list current user's developments
 *   GET  ?action=development&id=X      — development + buildings + roll-up
 *   POST ?action=save_development      — update development settings
 *   POST ?action=delete_development
 *   GET  ?action=rab&id=X             — full RAB payload for the editor
 *   POST ?action=regenerate&building_id=X — new version, re-run the engine
 *   POST ?action=save_item / add_item / delete_item
 *   POST ?action=save_section / add_section / delete_section
 *   POST ?action=save_takeoff         — replace takeoff rows, qty = sum
 *   GET  ?action=slot_alternatives&slot=X
 *   POST ?action=swap_slot            — swap an item to another slot option
 *   POST ?action=set_markups / set_display / save_rab_meta
 *   GET  ?action=ahsp&work_item_id=X  — coefficient build-up (transparency)
 *   GET  ?action=catalog&q=...        — catalog browser (premium)
 *   GET  ?action=export&rab_id=X&format=xlsx|pdf|csv
 *   POST ?action=save_template / GET ?action=templates / POST ?action=load_template
 *   GET  ?action=check_feature&key=X
 */

require_once(__DIR__ . '/_sec.php');                         // SEC-008/011/021-024/055/056
require_once('/home/rovin629/config/biltest_config.php');
sec_session_start();
sec_install_json_exception_handler();

if (isset($_GET['action']) && $_GET['action'] === 'export') {
    // export sets its own headers
} else {
    header('Content-Type: application/json; charset=utf-8');
    sec_api_headers(true);
}
// Same-origin SPA: no wildcard CORS + credentials combo (SEC-037).
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
sec_require_same_origin();   // reject cross-site state-changing requests (SEC-008)

// ─── DB + helpers (mirrors rab_api.php conventions) ──────────────────────────
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
function get_current_user_id() { return isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null; }
function require_auth() {
    $uid = get_current_user_id();
    if (!$uid) { json_error(401, 'login_required'); }
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
    $tier = $user['subscription_tier'] ? $user['subscription_tier'] : 'free';
    if ($tier !== 'free' && $user['subscription_expires_at']) {
        if (strtotime($user['subscription_expires_at']) < time()) return 'free';
    }
    return $tier;
}
function check_feature_access($feature_key, $uid = null) {
    $db = get_db();
    $stmt = $db->prepare("SELECT * FROM feature_access WHERE feature_key = ? AND is_active = 1");
    $stmt->execute(array($feature_key));
    $feature = $stmt->fetch();
    if (!$feature) return array('allowed' => false, 'reason' => 'feature_not_found');
    if ($feature['require_login'] && !$uid) return array('allowed' => false, 'reason' => 'login_required');
    $tier = get_user_tier($uid);
    $allowed = false;
    if ($tier === 'guest') $allowed = !$feature['require_login'] && $feature['tier_free'];
    elseif ($tier === 'free') $allowed = (bool)$feature['tier_free'];
    elseif ($tier === 'basic') $allowed = (bool)$feature['tier_basic'];
    elseif ($tier === 'premium') $allowed = (bool)$feature['tier_premium'];
    if (!$allowed) return array('allowed' => false, 'reason' => 'tier_insufficient', 'required_tier' => 'premium');
    return array('allowed' => true);
}
function user_can($key, $uid) { $a = check_feature_access($key, $uid); return !empty($a['allowed']); }
function fmt_idr($val) { return 'Rp ' . number_format((float)$val, 0, ',', '.'); }
function cfg($key, $default = 0) {
    static $cache = null;
    if ($cache === null) {
        $cache = array();
        foreach (get_db()->query("SELECT cfg_key, cfg_value FROM drab_config")->fetchAll() as $r) {
            $cache[$r['cfg_key']] = $r['cfg_value'];
        }
    }
    return isset($cache[$key]) ? $cache[$key] : $default;
}
function new_line_id() {
    // stable per-line id that survives version clones (for the future Variations portal)
    $b = random_bytes(8);
    return 'L' . bin2hex($b);
}
// Schema-tolerance helpers: the UX-pass migration (floors, floor_code, per_level)
// may not have run yet on a given environment. These let the API degrade to the
// pre-migration behaviour instead of fatally erroring on a missing column/table.
function drab_table_exists($db, $table) {
    static $cache = array();
    if (array_key_exists($table, $cache)) return $cache[$table];
    try {
        $st = $db->prepare("SELECT COUNT(*) c FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?");
        $st->execute(array($table));
        $r = $st->fetch();
        $cache[$table] = $r ? ((int)$r['c'] > 0) : false;
    } catch (Exception $e) { $cache[$table] = false; }
    return $cache[$table];
}
function drab_has_column($db, $table, $col) {
    static $cache = array();
    $k = $table . '.' . $col;
    if (array_key_exists($k, $cache)) return $cache[$k];
    try {
        $st = $db->prepare("SELECT COUNT(*) c FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=?");
        $st->execute(array($table, $col));
        $r = $st->fetch();
        $cache[$k] = $r ? ((int)$r['c'] > 0) : false;
    } catch (Exception $e) { $cache[$k] = false; }
    return $cache[$k];
}
// Resolve a chosen floor type (drab_floors.code) to its floor_finish work item id.
function drab_floor_work_item_id($db, $floor_code) {
    if (!$floor_code || !drab_table_exists($db, 'drab_floors')) return 0;
    $st = $db->prepare("SELECT wi.id FROM drab_floors f JOIN drab_work_items wi ON wi.code=f.work_item_code
        WHERE f.code=? AND f.is_active=1 AND wi.is_active=1 LIMIT 1");
    $st->execute(array($floor_code));
    $r = $st->fetch();
    return $r ? (int)$r['id'] : 0;
}
// Bilingual section name for a per-storey superstructure block (matches the
// wizard's own floor labels: area_l1 = Ground floor, area_l2 = 1st floor, …).
function drab_storey_section_name($i, $multi) {
    if (!$multi) return array('SUPERSTRUCTURE (FRAME & SLABS)', 'STRUKTUR ATAS (RANGKA & PELAT)');
    $en = array(1 => 'GROUND FLOOR STRUCTURE', 2 => '1ST FLOOR STRUCTURE', 3 => '2ND FLOOR STRUCTURE');
    $idn = array(1 => 'STRUKTUR LANTAI DASAR', 2 => 'STRUKTUR LANTAI 1', 3 => 'STRUKTUR LANTAI 2');
    if (isset($en[$i])) return array($en[$i], $idn[$i]);
    return array('UPPER FLOORS STRUCTURE', 'STRUKTUR LANTAI ATAS');
}

// ─── ROUTER ──────────────────────────────────────────────────────────────────
$action = isset($_GET['action']) ? $_GET['action'] : '';
try {
    switch ($action) {
        case 'meta':                handle_meta(); break;
        case 'generate':            handle_generate(); break;
        case 'developments':        handle_developments(); break;
        case 'development':         handle_development(); break;
        case 'save_development':    handle_save_development(); break;
        case 'delete_development':  handle_delete_development(); break;
        case 'delete_building':     handle_delete_building(); break;
        case 'rab':                 handle_rab(); break;
        case 'regenerate':          handle_regenerate(); break;
        case 'save_item':           handle_save_item(); break;
        case 'add_item':            handle_add_item(); break;
        case 'delete_item':         handle_delete_item(); break;
        case 'save_section':        handle_save_section(); break;
        case 'add_section':         handle_add_section(); break;
        case 'delete_section':      handle_delete_section(); break;
        case 'save_takeoff':        handle_save_takeoff(); break;
        case 'slot_alternatives':   handle_slot_alternatives(); break;
        case 'swap_slot':           handle_swap_slot(); break;
        case 'set_markups':         handle_set_markups(); break;
        case 'set_display':         handle_set_display(); break;
        case 'save_rab_meta':       handle_save_rab_meta(); break;
        case 'ahsp':                handle_ahsp(); break;
        case 'catalog':             handle_catalog(); break;
        case 'export':              handle_export(); break;
        case 'save_template':       handle_save_template(); break;
        case 'templates':           handle_templates(); break;
        case 'load_template':       handle_load_template(); break;
        case 'check_feature':       handle_check_feature(); break;
        default:                    json_error(400, 'Unknown action');
    }
} catch (Exception $e) {
    error_log('[biltest] drab_api: ' . $e->getMessage());
    json_error(500, 'server_error');   // no schema/message leak (SEC-023)
}

// ============================================================================
// META — everything the wizard needs to render
// ============================================================================
function handle_meta() {
    $db = get_db();
    $out = array(
        'styles'      => $db->query("SELECT code,name_en,name_id,description_en,description_id,wall_factor,status,default_structure,default_roof FROM drab_styles WHERE is_active=1 ORDER BY sort_order")->fetchAll(),
        'structures'  => $db->query("SELECT code,name_en,name_id,description_en,description_id FROM drab_structures WHERE is_active=1 ORDER BY sort_order")->fetchAll(),
        'roofs'       => $db->query("SELECT code,name_en,name_id,description_en,description_id FROM drab_roofs WHERE is_active=1 ORDER BY sort_order")->fetchAll(),
        'tiers'       => $db->query("SELECT code,name_en,name_id,description_en,description_id FROM drab_finish_tiers ORDER BY sort_order")->fetchAll(),
        'zones'       => $db->query("SELECT code,name_en,name_id,base_zone,distance_band,access_level FROM drab_zone_presets WHERE is_active=1 ORDER BY sort_order")->fetchAll(),
        'units'       => $db->query("SELECT id,code,name_en,name_id FROM drab_units ORDER BY sort_order")->fetchAll(),
        'spec_slots'  => $db->query("SELECT code,name_en,name_id FROM drab_spec_slots ORDER BY sort_order")->fetchAll(),
    );
    // Floor types are an optional wizard axis added by the UX-pass migration; if it
    // hasn't run yet just return an empty list and the wizard hides the Floor cards.
    $out['floors'] = drab_table_exists($db, 'drab_floors')
        ? $db->query("SELECT code,name_en,name_id,description_en,description_id,status FROM drab_floors WHERE is_active=1 ORDER BY sort_order")->fetchAll()
        : array();
    json_out(array('ok' => true, 'meta' => $out));
}

// ============================================================================
// GENERATION ENGINE
// ============================================================================
function drab_resolve_zone($db, $input) {
    // Returns array(base_zone, distance_band, access_level, zone_preset)
    $preset = isset($input['zone_preset']) ? trim($input['zone_preset']) : '';
    if ($preset !== '') {
        $st = $db->prepare("SELECT * FROM drab_zone_presets WHERE code=? AND is_active=1");
        $st->execute(array($preset));
        $z = $st->fetch();
        if ($z) return array($z['base_zone'], $z['distance_band'], $z['access_level'], $preset);
    }
    $bz = (isset($input['base_zone']) && $input['base_zone'] === 'mataram') ? 'mataram' : 'south';
    $dist = isset($input['distance_band']) ? $input['distance_band'] : 'near';
    $acc = isset($input['access_level']) ? $input['access_level'] : 'easy';
    return array($bz, $dist, $acc, $preset);
}
function drab_site_factor($db, $dist, $acc) {
    $st = $db->prepare("SELECT material_pct, labour_pct FROM drab_site_factors WHERE distance_band=? AND access_level=?");
    $st->execute(array($dist, $acc));
    $f = $st->fetch();
    if (!$f) return array(0.0, 0.0);
    return array((float)$f['material_pct'], (float)$f['labour_pct']);
}
function drab_resolve_price($db, $work_item_id, $base_zone, $allow_confirmed) {
    // Returns array(material, labour, confidence, basis, source)
    $st = $db->prepare("SELECT zone,material_rate,labour_rate,confidence,basis,source FROM drab_work_item_prices WHERE work_item_id=?");
    $st->execute(array($work_item_id));
    $rows = array();
    foreach ($st->fetchAll() as $r) $rows[$r['zone']] = $r;
    if (!$rows) return array(0, 0, 'indicative', 'estimate', null);
    if ($allow_confirmed) {
        $r = isset($rows[$base_zone]) ? $rows[$base_zone] : (isset($rows['mataram']) ? $rows['mataram'] : reset($rows));
        return array((int)$r['material_rate'], (int)$r['labour_rate'], $r['confidence'], $r['basis'], $r['source']);
    }
    // Free path: never reveal a confirmed rate. Prefer the Mataram indicative row; never fall
    // back to a confirmed row (a future south-only catalog item must not leak its confirmed rate).
    $r = null;
    if (isset($rows['mataram']) && $rows['mataram']['confidence'] !== 'confirmed') {
        $r = $rows['mataram'];
    } else {
        foreach ($rows as $cand) { if ($cand['confidence'] !== 'confirmed') { $r = $cand; break; } }
    }
    if (!$r) return array(0, 0, 'indicative', 'estimate', null); // no indicative source — emit zero, never leak
    $mat = (int)$r['material_rate']; $lab = (int)$r['labour_rate'];
    if ($base_zone === 'south') {
        $mat = (int)round($mat * (1 + (float)cfg('south_default_premium_material', 0.12)));
        $lab = (int)round($lab * (1 + (float)cfg('south_default_premium_labour', 0.05)));
    }
    return array($mat, $lab, 'indicative', ($r['basis'] === 'real_boq' ? 'derived' : $r['basis']), $r['source']);
}
function drab_driver_value($driver, $b, $wall_factor) {
    $floor_area = (float)$b['area_l1'] + (float)$b['area_l2'] + (float)$b['area_l3'] + (float)$b['area_other'];
    if ($floor_area <= 0) $floor_area = (float)$b['area_l1'];
    $footprint = (float)$b['footprint_m2'] > 0 ? (float)$b['footprint_m2'] : (float)$b['area_l1'];
    if ($footprint <= 0) $footprint = $floor_area;
    switch ($driver) {
        case 'floor_area':        return $floor_area;
        case 'footprint':         return $footprint;
        case 'ground_floor_area': return (float)$b['area_l1'] > 0 ? (float)$b['area_l1'] : $floor_area;
        case 'wall_area':         return $floor_area * (float)$wall_factor;
        case 'roof_area':         return $footprint;
        case 'bedroom':           return (float)$b['bedrooms'];
        case 'bathroom':          return (float)$b['bathrooms'];
        case 'floors':            return (float)$b['floors'];
        case 'pool_area':         return (float)$b['pool_area'];
        case 'deck_area':         return (float)$b['deck_area'];
        case 'rooftop_area':      return (float)$b['rooftop_area'];
        case 'pergola_area':      return (float)$b['pergola_area'];
        case 'carport_area':      return (float)$b['carport_area'];
        case 'boundary_len':      return (float)$b['boundary_len'];
        case 'fixed':             return 1.0;
    }
    return 0.0;
}
function drab_applies($flag, $b) {
    if (!$flag) return true;
    $neg = false;
    if (substr($flag, 0, 1) === '!') { $neg = true; $flag = substr($flag, 1); }
    $res = true;
    switch ($flag) {
        case 'has_pool':     $res = (int)$b['has_pool'] === 1 && (float)$b['pool_area'] > 0; break;
        case 'has_rooftop':  $res = (int)$b['has_rooftop'] === 1 && (float)$b['rooftop_area'] > 0; break;
        case 'has_deck':     $res = (int)$b['has_deck'] === 1 && (float)$b['deck_area'] > 0; break;
        case 'has_pergola':  $res = (int)$b['has_pergola'] === 1 && (float)$b['pergola_area'] > 0; break;
        case 'has_carport':  $res = (int)$b['has_carport'] === 1 && (float)$b['carport_area'] > 0; break;
        case 'has_boundary': $res = (float)$b['boundary_len'] > 0; break;
        default:             $res = true;
    }
    return $neg ? !$res : $res;
}

/**
 * Core engine: clears the rab's sections/items and repopulates from the overlay
 * templates for the given building + development settings.
 */
function drab_generate_rab($db, $rab_id, $b, $dev, $allow_confirmed) {
    // wipe existing content
    $secIds = $db->prepare("SELECT id FROM drab_sections WHERE rab_id=?");
    $secIds->execute(array($rab_id));
    foreach ($secIds->fetchAll() as $s) {
        $itemIds = $db->prepare("SELECT id FROM drab_items WHERE section_id=?");
        $itemIds->execute(array($s['id']));
        foreach ($itemIds->fetchAll() as $it) {
            $db->prepare("DELETE FROM drab_item_takeoffs WHERE item_id=?")->execute(array($it['id']));
        }
        $db->prepare("DELETE FROM drab_items WHERE section_id=?")->execute(array($s['id']));
    }
    $db->prepare("DELETE FROM drab_sections WHERE rab_id=?")->execute(array($rab_id));

    // style wall factor
    $st = $db->prepare("SELECT wall_factor FROM drab_styles WHERE code=?");
    $st->execute(array($b['style_code']));
    $sr = $st->fetch();
    $wall_factor = $sr ? (float)$sr['wall_factor'] : 2.2;

    $tier = $b['finish_tier'];
    $base_zone = $dev['base_zone'];
    $sf = drab_site_factor($db, $dev['distance_band'], $dev['access_level']);
    $mat_pct = $sf[0]; $lab_pct = $sf[1];

    // optional explicit floor choice — overrides the finish-tier default for the floor slot
    $floor_wi = (!empty($b['floor_code'])) ? drab_floor_work_item_id($db, $b['floor_code']) : 0;

    // effective per-storey areas — mirror the wizard so a blank upper level is
    // assumed equal to the one below rather than silently dropping a storey.
    $floors_n = (int)$b['floors']; if ($floors_n < 1) $floors_n = 1;
    $a1 = (float)$b['area_l1']; $a2 = (float)$b['area_l2']; $a3 = (float)$b['area_l3']; $ao = (float)$b['area_other'];
    if ($floors_n >= 2 && $a2 <= 0) $a2 = $a1;
    if ($floors_n >= 3 && $a3 <= 0) $a3 = ($a2 > 0 ? $a2 : $a1);
    if ($floors_n < 2) $a2 = 0;
    if ($floors_n < 3) $a3 = 0;
    if ($floors_n < 4) $ao = 0;
    $levels = array();
    if ($a1 > 0) $levels[] = array('i' => 1, 'area' => $a1);
    if ($a2 > 0) $levels[] = array('i' => 2, 'area' => $a2);
    if ($a3 > 0) $levels[] = array('i' => 3, 'area' => $a3);
    if ($ao > 0) $levels[] = array('i' => 4, 'area' => $ao);
    if (!$levels) $levels[] = array('i' => 1, 'area' => ($a1 > 0 ? $a1 : 1.0));
    $level_sum = 0.0; foreach ($levels as $lv) $level_sum += $lv['area'];
    $multi_level = count($levels) > 1;

    // which scopes apply
    $scopes = array(array('shared',''),
                    array('structure', $b['structure_code']),
                    array('roof', $b['roof_code']),
                    array('style', $b['style_code']));
    foreach (array('pool','rooftop','deck','pergola','carport','boundary') as $ex) $scopes[] = array('extra', $ex);

    // collect lines
    $lines = array();
    foreach ($scopes as $sc) {
        $q = $db->prepare("SELECT * FROM drab_template_lines WHERE scope=? AND scope_code=? AND is_active=1 ORDER BY sort_order");
        $q->execute(array($sc[0], $sc[1]));
        foreach ($q->fetchAll() as $ln) $lines[] = $ln;
    }

    // discipline order + letters
    $disc_order = array('PREP' => 0, 'STR' => 1, 'ARCH' => 2, 'MEP' => 3);
    $disc_letter = array('PREP' => 'P', 'STR' => 'A', 'ARCH' => 'B', 'MEP' => 'C');

    // group into sections preserving first-seen order. $sectionAdd appends an item,
    // creating the section on first use (key = discipline|group).
    $sections = array(); $secOrder = array();
    $sectionAdd = function($key, $disc, $name_en, $name_id, $item) use (&$sections, &$secOrder) {
        if (!isset($sections[$key])) {
            $sections[$key] = array('discipline' => $disc, 'name_en' => $name_en, 'name_id' => $name_id, 'items' => array());
            $secOrder[] = $key;
        }
        $sections[$key]['items'][] = $item;
    };

    foreach ($lines as $ln) {
        if (!drab_applies($ln['applies_when'], $b)) continue;
        // resolve work item
        $wi_id = $ln['work_item_id'];
        if (!$wi_id && $ln['slot_code']) {
            // an explicit floor choice wins for the floor_finish slot
            if ($ln['slot_code'] === 'floor_finish' && $floor_wi) {
                $wi_id = $floor_wi;
            } else {
                $so = $db->prepare("SELECT work_item_id FROM drab_slot_options WHERE slot_code=? AND tier_code=? AND is_default=1 LIMIT 1");
                $so->execute(array($ln['slot_code'], $tier));
                $srow = $so->fetch();
                if (!$srow) {
                    $so2 = $db->prepare("SELECT work_item_id FROM drab_slot_options WHERE slot_code=? LIMIT 1");
                    $so2->execute(array($ln['slot_code']));
                    $srow = $so2->fetch();
                }
                if (!$srow) continue;
                $wi_id = $srow['work_item_id'];
            }
        }
        if (!$wi_id) continue;
        $wq = $db->prepare("SELECT wi.*, u.code AS unit_code FROM drab_work_items wi JOIN drab_units u ON u.id=wi.unit_id WHERE wi.id=?");
        $wq->execute(array($wi_id));
        $wi = $wq->fetch();
        if (!$wi) continue;

        $dv = drab_driver_value($ln['driver'], $b, $wall_factor);
        $qty = round($dv * (float)$ln['coefficient'], 2);
        if ($qty <= 0) continue;

        $pr = drab_resolve_price($db, $wi_id, $base_zone, $allow_confirmed);
        $mat = (int)round($pr[0] * (1 + $mat_pct));
        $lab = (int)round($pr[1] * (1 + $lab_pct));
        $disc = $wi['discipline'];
        $baseItem = array(
            'work_item_id' => $wi_id,
            'slot_code' => $wi['spec_slot'],
            'name_en' => $wi['name_en'] . ($wi['spec_en'] ? ' — ' . $wi['spec_en'] : ''),
            'name_id' => $wi['name_id'] . ($wi['spec_id'] ? ' — ' . $wi['spec_id'] : ''),
            'unit_id' => $wi['unit_id'],
            'quantity' => $qty,
            'material_rate' => $mat,
            'labour_rate' => $lab,
            'is_pc_sum' => (int)$wi['is_pc_sum'],
            'confidence' => $pr[2],
            'source' => $pr[4],
        );

        // Per-storey superstructure: apportion the SAME quantity across storeys by
        // floor-area share, so the Structure page reads Ground/1st/2nd-floor blocks
        // while the grand total is identical to the un-split build.
        if (!empty($ln['per_level']) && $disc === 'STR' && $multi_level && $level_sum > 0) {
            // Apportion the line quantity across storeys by floor-area share; the
            // largest storey absorbs the rounding remainder so the parts always sum
            // back to exactly $qty (no stray +/-0.01, no negative quantity).
            $bigIdx = 0; $bigArea = -1.0;
            foreach ($levels as $idx => $lv) { if ($lv['area'] > $bigArea) { $bigArea = $lv['area']; $bigIdx = $idx; } }
            $placed = 0.0; $parts = array();
            foreach ($levels as $idx => $lv) {
                if ($idx === $bigIdx) continue;
                $q_i = round($qty * ($lv['area'] / $level_sum), 2);
                $parts[$idx] = $q_i; $placed += $q_i;
            }
            $parts[$bigIdx] = round($qty - $placed, 2);
            foreach ($levels as $idx => $lv) {
                $q_i = isset($parts[$idx]) ? $parts[$idx] : 0;
                if ($q_i <= 0) continue;
                $nm = drab_storey_section_name($lv['i'], true);
                $item = $baseItem; $item['quantity'] = $q_i;
                $sectionAdd('STR|__LVL' . $lv['i'], 'STR', $nm[0], $nm[1], $item);
            }
        } elseif (!empty($ln['per_level']) && $disc === 'STR') {
            $nm = drab_storey_section_name(1, false); // single-storey: one clear block
            $sectionAdd('STR|__LVL1', 'STR', $nm[0], $nm[1], $baseItem);
        } else {
            $sectionAdd($disc . '|' . $ln['section_group'], $disc, $ln['section_name_en'], $ln['section_name_id'], $baseItem);
        }
    }

    // sort sections by discipline order, keeping first-seen order within a discipline.
    // PHP 7.4's usort is NOT stable and there can be >16 sections (Substructure +
    // per-storey blocks + ARCH/MEP), so tie-break on the original index explicitly.
    $secIndex = array();
    foreach ($secOrder as $ix => $k) $secIndex[$k] = $ix;
    usort($secOrder, function($a, $b2) use ($sections, $disc_order, $secIndex) {
        $da = $disc_order[$sections[$a]['discipline']];
        $dbb = $disc_order[$sections[$b2]['discipline']];
        if ($da !== $dbb) return $da - $dbb;
        return $secIndex[$a] - $secIndex[$b2];
    });

    // assign codes + insert
    $discSecCount = array();
    foreach ($secOrder as $key) {
        $sec = $sections[$key];
        $disc = $sec['discipline'];
        $letter = $disc_letter[$disc];
        if (!isset($discSecCount[$disc])) $discSecCount[$disc] = 0;
        $discSecCount[$disc]++;
        $secCode = $letter . '.' . $discSecCount[$disc];
        $ins = $db->prepare("INSERT INTO drab_sections (rab_id,discipline,code,name_en,name_id,sort_order) VALUES (?,?,?,?,?,?)");
        $ins->execute(array($rab_id, $disc, $secCode, $sec['name_en'], $sec['name_id'], $disc_order[$disc] * 1000 + $discSecCount[$disc]));
        $section_id = (int)$db->lastInsertId();
        $itemN = 0;
        foreach ($sec['items'] as $it) {
            $itemN++;
            $ref = $secCode . '.' . $itemN;
            $iins = $db->prepare("INSERT INTO drab_items
                (section_id,ref_code,line_id,work_item_id,slot_code,name_en,name_id,unit_id,quantity,material_rate,labour_rate,is_pc_sum,has_takeoff,confidence,source,sort_order)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,0,?,?,?)");
            $iins->execute(array($section_id, $ref, new_line_id(), $it['work_item_id'], $it['slot_code'],
                $it['name_en'], $it['name_id'], $it['unit_id'], $it['quantity'], $it['material_rate'], $it['labour_rate'],
                $it['is_pc_sum'], $it['confidence'], $it['source'], $itemN));
        }
    }
}

function handle_generate() {
    $uid = require_auth();
    $acc = check_feature_access('drab_generate', $uid);
    if (empty($acc['allowed'])) json_out(array('error' => 'upgrade_required', 'feature' => 'drab_generate', 'detail' => $acc), 403);
    $allow_confirmed = user_can('drab_confirmed_pricing', $uid);
    $in = get_post_data();
    $db = get_db();

    // enforce 1-development limit for non-premium
    if (!user_can('drab_save_multi', $uid)) {
        $cnt = $db->prepare("SELECT COUNT(*) c FROM drab_developments WHERE user_id=? AND is_active=1");
        $cnt->execute(array($uid));
        $existing = (int)$cnt->fetch()['c'];
        $devId = isset($in['development_id']) ? (int)$in['development_id'] : 0;
        if ($existing >= 1 && !$devId) {
            json_out(array('error' => 'upgrade_required', 'feature' => 'drab_save_multi',
                'detail' => array('reason' => 'free_limit', 'message' => 'Free plan allows one saved project. Upgrade to save more.')), 403);
        }
    }

    $zone = drab_resolve_zone($db, $in);
    $base_zone = $zone[0]; $dist = $zone[1]; $accLvl = $zone[2]; $preset = $zone[3];

    // development: reuse or create
    $devId = isset($in['development_id']) ? (int)$in['development_id'] : 0;
    if ($devId) {
        $d = $db->prepare("SELECT * FROM drab_developments WHERE id=? AND user_id=? AND is_active=1");
        $d->execute(array($devId, $uid));
        $dev = $d->fetch();
        if (!$dev) json_error(404, 'development_not_found');
    } else {
        $name = isset($in['development_name']) ? trim($in['development_name']) : (isset($in['name']) ? trim($in['name']) : 'My project');
        if ($name === '') $name = 'My project';
        $loc = isset($in['location_text']) ? trim($in['location_text']) : null;
        $lang = isset($in['lang']) && in_array($in['lang'], array('en','id','both')) ? $in['lang'] : 'en';
        $ins = $db->prepare("INSERT INTO drab_developments (user_id,name,location_text,base_zone,distance_band,access_level,zone_preset,lang) VALUES (?,?,?,?,?,?,?,?)");
        $ins->execute(array($uid, $name, $loc, $base_zone, $dist, $accLvl, $preset !== '' ? $preset : null, $lang));
        $devId = (int)$db->lastInsertId();
        $d = $db->prepare("SELECT * FROM drab_developments WHERE id=?");
        $d->execute(array($devId));
        $dev = $d->fetch();
    }

    // building
    $bn = isset($in['building_name']) ? trim($in['building_name']) : 'Building 1';
    if ($bn === '') $bn = 'Building 1';
    $fields = drab_building_fields($in);
    $cols = array('development_id','name'); $vals = array($devId, $bn); $ph = array('?','?');
    foreach ($fields as $k => $v) { $cols[] = $k; $vals[] = $v; $ph[] = '?'; }
    $sql = "INSERT INTO drab_buildings (" . implode(',', $cols) . ") VALUES (" . implode(',', $ph) . ")";
    $db->prepare($sql)->execute($vals);
    $buildingId = (int)$db->lastInsertId();

    // rab v1 — markups OFF by default (a fresh RAB = pure direct cost like the real Villa BOQs),
    // but seed the standard Indonesian percentages so toggling them on yields a real number.
    $db->prepare("INSERT INTO drab_rabs (building_id,version,status,overhead_pct,contingency_pct,ppn_pct) VALUES (?,1,'draft',?,?,?)")
       ->execute(array($buildingId, (float)cfg('default_overhead_pct',10), (float)cfg('default_contingency_pct',5), (float)cfg('default_ppn_pct',11)));
    $rabId = (int)$db->lastInsertId();
    $db->prepare("UPDATE drab_buildings SET current_rab_id=? WHERE id=?")->execute(array($rabId, $buildingId));

    $b = drab_get_building($db, $buildingId);
    drab_generate_rab($db, $rabId, $b, $dev, $allow_confirmed);

    json_out(array('ok' => true, 'development_id' => $devId, 'building_id' => $buildingId, 'rab_id' => $rabId));
}

function drab_building_fields($in) {
    $f = array();
    $f['style_code']     = isset($in['style_code']) ? $in['style_code'] : 'trop_med';
    $f['structure_code'] = isset($in['structure_code']) ? $in['structure_code'] : 'rcc_full';
    $f['roof_code']      = isset($in['roof_code']) ? $in['roof_code'] : 'rcc_flat';
    $f['finish_tier']    = isset($in['finish_tier']) ? $in['finish_tier'] : 'standard';
    $f['floors']         = isset($in['floors']) ? max(1, (int)$in['floors']) : 1;
    $f['area_l1']        = isset($in['area_l1']) ? (float)$in['area_l1'] : 0;
    $f['area_l2']        = isset($in['area_l2']) ? (float)$in['area_l2'] : 0;
    $f['area_l3']        = isset($in['area_l3']) ? (float)$in['area_l3'] : 0;
    $f['area_other']     = isset($in['area_other']) ? (float)$in['area_other'] : 0;
    $f['footprint_m2']   = isset($in['footprint_m2']) ? (float)$in['footprint_m2'] : 0;
    $f['bedrooms']       = isset($in['bedrooms']) ? (int)$in['bedrooms'] : 0;
    $f['bathrooms']      = isset($in['bathrooms']) ? (int)$in['bathrooms'] : 0;
    $f['has_pool']       = !empty($in['has_pool']) ? 1 : 0;
    $f['pool_area']      = isset($in['pool_area']) ? (float)$in['pool_area'] : 0;
    $f['has_rooftop']    = !empty($in['has_rooftop']) ? 1 : 0;
    $f['rooftop_area']   = isset($in['rooftop_area']) ? (float)$in['rooftop_area'] : 0;
    $f['has_deck']       = !empty($in['has_deck']) ? 1 : 0;
    $f['deck_area']      = isset($in['deck_area']) ? (float)$in['deck_area'] : 0;
    $f['has_pergola']    = !empty($in['has_pergola']) ? 1 : 0;
    $f['pergola_area']   = isset($in['pergola_area']) ? (float)$in['pergola_area'] : 0;
    $f['has_carport']    = !empty($in['has_carport']) ? 1 : 0;
    $f['carport_area']   = isset($in['carport_area']) ? (float)$in['carport_area'] : 0;
    $f['boundary_len']   = isset($in['boundary_len']) ? (float)$in['boundary_len'] : 0;
    // Optional floor type (only once the UX-pass migration has added the column).
    if (drab_has_column(get_db(), 'drab_buildings', 'floor_code')) {
        $fc = isset($in['floor_code']) ? trim($in['floor_code']) : '';
        $f['floor_code'] = ($fc !== '') ? $fc : null;
    }
    return $f;
}
function drab_get_building($db, $id) {
    $st = $db->prepare("SELECT * FROM drab_buildings WHERE id=? AND is_active=1");
    $st->execute(array($id));
    return $st->fetch();
}

// ============================================================================
// OWNERSHIP guards
// ============================================================================
function drab_owns_rab($db, $rab_id, $uid) {
    $st = $db->prepare("SELECT r.id FROM drab_rabs r JOIN drab_buildings b ON b.id=r.building_id JOIN drab_developments d ON d.id=b.development_id WHERE r.id=? AND d.user_id=? AND d.is_active=1");
    $st->execute(array($rab_id, $uid));
    return (bool)$st->fetch();
}
function drab_rab_id_for_section($db, $section_id) {
    $st = $db->prepare("SELECT rab_id FROM drab_sections WHERE id=?");
    $st->execute(array($section_id));
    $r = $st->fetch();
    return $r ? (int)$r['rab_id'] : 0;
}
function drab_rab_id_for_item($db, $item_id) {
    $st = $db->prepare("SELECT s.rab_id FROM drab_items i JOIN drab_sections s ON s.id=i.section_id WHERE i.id=?");
    $st->execute(array($item_id));
    $r = $st->fetch();
    return $r ? (int)$r['rab_id'] : 0;
}

// ============================================================================
// TOTALS
// ============================================================================
function drab_compute_totals($db, $rab_id) {
    $st = $db->prepare("SELECT s.discipline, i.quantity, i.material_rate, i.labour_rate
        FROM drab_items i JOIN drab_sections s ON s.id=i.section_id WHERE s.rab_id=?");
    $st->execute(array($rab_id));
    $disc = array('PREP'=>array('m'=>0,'l'=>0),'STR'=>array('m'=>0,'l'=>0),'ARCH'=>array('m'=>0,'l'=>0),'MEP'=>array('m'=>0,'l'=>0));
    foreach ($st->fetchAll() as $r) {
        $q = $r['quantity'] === null ? 0 : (float)$r['quantity'];
        $disc[$r['discipline']]['m'] += $q * (float)$r['material_rate'];
        $disc[$r['discipline']]['l'] += $q * (float)$r['labour_rate'];
    }
    $sub_m = 0; $sub_l = 0;
    foreach ($disc as $d) { $sub_m += $d['m']; $sub_l += $d['l']; }
    $direct = $sub_m + $sub_l;

    $rb = $db->prepare("SELECT markups_on,overhead_pct,contingency_pct,ppn_pct FROM drab_rabs WHERE id=?");
    $rb->execute(array($rab_id));
    $rm = $rb->fetch();
    $overhead = 0; $contingency = 0; $ppn = 0;
    $grand = $direct;
    if ($rm && (int)$rm['markups_on'] === 1) {
        $overhead = $direct * ((float)$rm['overhead_pct'] / 100.0);
        $contingency = $direct * ((float)$rm['contingency_pct'] / 100.0);
        $taxable = $direct + $overhead + $contingency;
        $ppn = $taxable * ((float)$rm['ppn_pct'] / 100.0);
        $grand = $taxable + $ppn;
    }
    return array(
        'disciplines' => $disc,
        'material' => $sub_m, 'labour' => $sub_l, 'direct' => $direct,
        'overhead' => $overhead, 'contingency' => $contingency, 'ppn' => $ppn, 'grand' => $grand,
        'markups_on' => $rm ? (int)$rm['markups_on'] : 0,
        'overhead_pct' => $rm ? (float)$rm['overhead_pct'] : 0,
        'contingency_pct' => $rm ? (float)$rm['contingency_pct'] : 0,
        'ppn_pct' => $rm ? (float)$rm['ppn_pct'] : 0,
    );
}

// ============================================================================
// READ: full RAB payload for the editor
// ============================================================================
function handle_rab() {
    $uid = require_auth();
    $rab_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if (!drab_owns_rab(get_db(), $rab_id, $uid)) json_error(403, 'forbidden');
    json_out(array('ok' => true, 'rab' => drab_rab_payload(get_db(), $rab_id, $uid)));
}
function drab_rab_payload($db, $rab_id, $uid) {
    $allow_confirmed = user_can('drab_confirmed_pricing', $uid);
    $allow_split = user_can('drab_split_view', $uid);
    $allow_export = user_can('drab_export_clean', $uid);

    // Which work items actually have an AHSP build-up — so the editor only shows
    // the "how?" link where there is a real coefficient breakdown to reveal.
    $buildupIds = array();
    foreach ($db->query("SELECT DISTINCT work_item_id FROM drab_ahsp_components")->fetchAll() as $rb0) {
        $buildupIds[(int)$rb0['work_item_id']] = true;
    }

    $r = $db->prepare("SELECT * FROM drab_rabs WHERE id=?");
    $r->execute(array($rab_id));
    $rab = $r->fetch();
    $b = drab_get_building($db, $rab['building_id']);
    $d = $db->prepare("SELECT * FROM drab_developments WHERE id=?");
    $d->execute(array($b['development_id']));
    $dev = $d->fetch();

    // sections + items + takeoffs
    $secs = $db->prepare("SELECT * FROM drab_sections WHERE rab_id=? ORDER BY sort_order, id");
    $secs->execute(array($rab_id));
    $sections = array();
    foreach ($secs->fetchAll() as $sec) {
        $iq = $db->prepare("SELECT i.*, u.code AS unit_code, u.name_en AS unit_en, u.name_id AS unit_id_lbl FROM drab_items i JOIN drab_units u ON u.id=i.unit_id WHERE i.section_id=? ORDER BY sort_order, id");
        $iq->execute(array($sec['id']));
        $items = array();
        $sec_m = 0; $sec_l = 0;
        foreach ($iq->fetchAll() as $it) {
            $tq = $db->prepare("SELECT id,label,quantity,sort_order FROM drab_item_takeoffs WHERE item_id=? ORDER BY sort_order, id");
            $tq->execute(array($it['id']));
            $takeoffs = $tq->fetchAll();
            $qty = $it['quantity'] === null ? 0 : (float)$it['quantity'];
            $lm = $qty * (float)$it['material_rate'];
            $ll = $qty * (float)$it['labour_rate'];
            $sec_m += $lm; $sec_l += $ll; // subtotals always reflect real cost (aggregate, not a per-line confirmed rate)
            $confirmed_real = ($it['confidence'] === 'confirmed');
            $mask_confirmed = ($confirmed_real && !$allow_confirmed);
            // Server-side gating: free users never receive confirmed rates; non-split tiers never
            // receive the material/labour breakdown. The frontend cannot be the paywall.
            $mat_out = (int)$it['material_rate'];
            $lab_out = (int)$it['labour_rate'];
            $rate_out = $mat_out + $lab_out;
            $lm_out = $lm; $ll_out = $ll; $lt_out = $lm + $ll;
            if ($mask_confirmed) {
                $mat_out = null; $lab_out = null; $rate_out = null; $lm_out = null; $ll_out = null; $lt_out = null;
            } elseif (!$allow_split) {
                $mat_out = null; $lab_out = null; $lm_out = null; $ll_out = null; // keep combined rate + line total
            }
            $items[] = array(
                'id' => (int)$it['id'], 'ref_code' => $it['ref_code'], 'line_id' => $it['line_id'],
                'work_item_id' => $it['work_item_id'] ? (int)$it['work_item_id'] : null,
                'slot_code' => $it['slot_code'],
                'name_en' => $it['name_en'], 'name_id' => $it['name_id'],
                'unit_id' => (int)$it['unit_id'], 'unit_code' => $it['unit_code'],
                'quantity' => $it['quantity'] === null ? null : (float)$it['quantity'],
                'material_rate' => $mat_out,
                'labour_rate' => $lab_out,
                'rate' => $rate_out,
                'line_material' => $lm_out, 'line_labour' => $ll_out, 'line_total' => $lt_out,
                'is_pc_sum' => (int)$it['is_pc_sum'], 'has_takeoff' => (int)$it['has_takeoff'],
                'has_buildup' => ($it['work_item_id'] && isset($buildupIds[(int)$it['work_item_id']])) ? 1 : 0,
                'confidence' => $it['confidence'],
                'confirmed_locked' => $mask_confirmed ? 1 : 0,
                'split_locked' => (!$mask_confirmed && !$allow_split) ? 1 : 0,
                'remark' => $it['remark'],
                'takeoffs' => $takeoffs,
            );
        }
        $sections[] = array(
            'id' => (int)$sec['id'], 'discipline' => $sec['discipline'], 'code' => $sec['code'],
            'name_en' => $sec['name_en'], 'name_id' => $sec['name_id'],
            'material' => $sec_m, 'labour' => $sec_l, 'total' => $sec_m + $sec_l,
            'items' => $items,
        );
    }
    $totals = drab_compute_totals($db, $rab_id);
    return array(
        'id' => (int)$rab_id, 'version' => (int)$rab['version'], 'status' => $rab['status'],
        'name' => $rab['name'], 'notes' => $rab['notes'],
        'building' => $b, 'development' => $dev,
        'sections' => $sections, 'totals' => $totals,
        'caps' => array('confirmed' => $allow_confirmed ? 1 : 0, 'split' => $allow_split ? 1 : 0, 'export' => $allow_export ? 1 : 0),
        'area_schedule' => array(
            'indoor' => (float)$b['area_l1'] + (float)$b['area_l2'] + (float)$b['area_l3'],
            'rooftop' => (float)$b['rooftop_area'],
            'outdoor' => (float)$b['deck_area'] + (float)$b['carport_area'],
            'pool' => (float)$b['pool_area'],
        ),
    );
}

// ============================================================================
// DEVELOPMENTS
// ============================================================================
function handle_developments() {
    $uid = require_auth();
    $db = get_db();
    $st = $db->prepare("SELECT id,name,location_text,base_zone,created_at,updated_at FROM drab_developments WHERE user_id=? AND is_active=1 ORDER BY updated_at DESC");
    $st->execute(array($uid));
    $devs = $st->fetchAll();
    foreach ($devs as &$d) {
        $bc = $db->prepare("SELECT COUNT(*) c FROM drab_buildings WHERE development_id=? AND is_active=1");
        $bc->execute(array($d['id']));
        $d['building_count'] = (int)$bc->fetch()['c'];
    }
    json_out(array('ok' => true, 'developments' => $devs, 'can_save_multi' => user_can('drab_save_multi', $uid) ? 1 : 0));
}
function handle_development() {
    $uid = require_auth();
    $db = get_db();
    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    $st = $db->prepare("SELECT * FROM drab_developments WHERE id=? AND user_id=? AND is_active=1");
    $st->execute(array($id, $uid));
    $dev = $st->fetch();
    if (!$dev) json_error(404, 'not_found');
    $bq = $db->prepare("SELECT * FROM drab_buildings WHERE development_id=? AND is_active=1 ORDER BY sort_order, id");
    $bq->execute(array($id));
    $buildings = $bq->fetchAll();
    $roll = 0;
    foreach ($buildings as &$b) {
        if ($b['current_rab_id']) {
            $t = drab_compute_totals($db, (int)$b['current_rab_id']);
            $b['grand'] = $t['grand']; $roll += $t['grand'];
        } else { $b['grand'] = 0; }
    }
    json_out(array('ok' => true, 'development' => $dev, 'buildings' => $buildings, 'rollup' => $roll));
}
function handle_save_development() {
    $uid = require_auth();
    $in = get_post_data();
    $db = get_db();
    $id = (int)$in['id'];
    if (!$id) json_error(400, 'missing_id');
    $own = $db->prepare("SELECT id FROM drab_developments WHERE id=? AND user_id=?");
    $own->execute(array($id, $uid));
    if (!$own->fetch()) json_error(403, 'forbidden');
    $fields = array('name','location_text','base_zone','distance_band','access_level','zone_preset','display_combined','lang','markups_on','overhead_pct','contingency_pct','ppn_pct');
    $enums = array('base_zone'=>array('mataram','south'), 'lang'=>array('en','id','both'),
                   'distance_band'=>array('near','mid','far'), 'access_level'=>array('easy','moderate','steep','boat'));
    $sets = array(); $vals = array();
    foreach ($fields as $f) {
        if (!array_key_exists($f, $in)) continue;
        if (isset($enums[$f]) && !in_array($in[$f], $enums[$f], true)) continue; // ignore out-of-range enum values
        if (in_array($f, array('display_combined','markups_on'), true)) { $sets[] = "$f=?"; $vals[] = !empty($in[$f]) ? 1 : 0; continue; }
        $sets[] = "$f=?"; $vals[] = $in[$f];
    }
    if (!$sets) json_out(array('ok'=>true));
    $vals[] = $id;
    $db->prepare("UPDATE drab_developments SET " . implode(',', $sets) . " WHERE id=?")->execute($vals);
    json_out(array('ok' => true));
}
function handle_delete_development() {
    $uid = require_auth();
    $in = get_post_data();
    $db = get_db();
    $id = (int)$in['id'];
    $db->prepare("UPDATE drab_developments SET is_active=0 WHERE id=? AND user_id=?")->execute(array($id, $uid));
    json_out(array('ok' => true));
}
function handle_delete_building() {
    $uid = require_auth();
    $in = get_post_data();
    $db = get_db();
    $id = (int)$in['id'];
    $own = $db->prepare("SELECT b.id FROM drab_buildings b JOIN drab_developments d ON d.id=b.development_id WHERE b.id=? AND d.user_id=?");
    $own->execute(array($id, $uid));
    if (!$own->fetch()) json_error(403, 'forbidden');
    $db->prepare("UPDATE drab_buildings SET is_active=0 WHERE id=?")->execute(array($id));
    json_out(array('ok' => true));
}

// ============================================================================
// REGENERATE — new version, re-run the engine from the building's current inputs
// ============================================================================
function handle_regenerate() {
    $uid = require_auth();
    $in = get_post_data();
    $db = get_db();
    $building_id = (int)$in['building_id'];
    $own = $db->prepare("SELECT b.* FROM drab_buildings b JOIN drab_developments d ON d.id=b.development_id WHERE b.id=? AND d.user_id=? AND b.is_active=1");
    $own->execute(array($building_id, $uid));
    $b = $own->fetch();
    if (!$b) json_error(403, 'forbidden');
    // apply only the inputs the client actually sent — a bare regenerate (just a
    // building_id) must NOT reset the stored style/size/areas to defaults.
    $fields = drab_building_fields($in);
    $sets = array(); $vals = array();
    foreach ($fields as $k => $v) {
        if (!array_key_exists($k, $in)) continue;
        $sets[] = "$k=?"; $vals[] = $v;
    }
    if ($sets) {
        $vals[] = $building_id;
        $db->prepare("UPDATE drab_buildings SET " . implode(',', $sets) . " WHERE id=?")->execute($vals);
    }
    $b = drab_get_building($db, $building_id);

    $d = $db->prepare("SELECT * FROM drab_developments WHERE id=?");
    $d->execute(array($b['development_id']));
    $dev = $d->fetch();

    // new version
    $vq = $db->prepare("SELECT MAX(version) m FROM drab_rabs WHERE building_id=?");
    $vq->execute(array($building_id));
    $nextV = (int)$vq->fetch()['m'] + 1;
    $db->prepare("INSERT INTO drab_rabs (building_id,version,status,overhead_pct,contingency_pct,ppn_pct) VALUES (?,?,'draft',?,?,?)")
       ->execute(array($building_id, $nextV, (float)cfg('default_overhead_pct',10), (float)cfg('default_contingency_pct',5), (float)cfg('default_ppn_pct',11)));
    $rabId = (int)$db->lastInsertId();
    $db->prepare("UPDATE drab_buildings SET current_rab_id=? WHERE id=?")->execute(array($rabId, $building_id));

    drab_generate_rab($db, $rabId, $b, $dev, user_can('drab_confirmed_pricing', $uid));
    json_out(array('ok' => true, 'rab_id' => $rabId));
}

// ============================================================================
// ITEM / SECTION / TAKEOFF CRUD
// ============================================================================
function handle_save_item() {
    $uid = require_auth();
    $in = get_post_data();
    $db = get_db();
    $item_id = (int)$in['item_id'];
    $rab_id = drab_rab_id_for_item($db, $item_id);
    if (!$rab_id || !drab_owns_rab($db, $rab_id, $uid)) json_error(403, 'forbidden');
    $map = array('name_en','name_id','unit_id','quantity','material_rate','labour_rate','is_pc_sum','remark');
    $sets = array(); $vals = array();
    foreach ($map as $f) {
        if (array_key_exists($f, $in)) {
            $sets[] = "$f=?";
            if ($f === 'quantity') $vals[] = ($in[$f] === '' || $in[$f] === null) ? null : (float)$in[$f];
            elseif (in_array($f, array('unit_id','material_rate','labour_rate','is_pc_sum'))) $vals[] = (int)$in[$f];
            else $vals[] = $in[$f];
        }
    }
    // editing a rate downgrades any confirmed lock implications client-side; keep confidence as-is.
    if ($sets) { $vals[] = $item_id; $db->prepare("UPDATE drab_items SET " . implode(',', $sets) . " WHERE id=?")->execute($vals); }
    json_out(array('ok' => true, 'totals' => drab_compute_totals($db, $rab_id)));
}
function handle_add_item() {
    $uid = require_auth();
    $in = get_post_data();
    $db = get_db();
    $section_id = (int)$in['section_id'];
    $rab_id = drab_rab_id_for_section($db, $section_id);
    if (!$rab_id || !drab_owns_rab($db, $rab_id, $uid)) json_error(403, 'forbidden');
    // ref code: section code + next index
    $sc = $db->prepare("SELECT code FROM drab_sections WHERE id=?");
    $sc->execute(array($section_id));
    $code = $sc->fetch()['code'];
    $cnt = $db->prepare("SELECT COUNT(*) c FROM drab_items WHERE section_id=?");
    $cnt->execute(array($section_id));
    $n = (int)$cnt->fetch()['c'] + 1;
    $unit_id = isset($in['unit_id']) ? (int)$in['unit_id'] : 1;
    $ins = $db->prepare("INSERT INTO drab_items (section_id,ref_code,line_id,name_en,name_id,unit_id,quantity,material_rate,labour_rate,confidence,sort_order)
        VALUES (?,?,?,?,?,?,?,?,?, 'indicative', ?)");
    $ins->execute(array($section_id, $code . '.' . $n, new_line_id(),
        isset($in['name_en']) ? $in['name_en'] : 'New item',
        isset($in['name_id']) ? $in['name_id'] : (isset($in['name_en']) ? $in['name_en'] : 'Item baru'),
        $unit_id,
        isset($in['quantity']) ? (float)$in['quantity'] : 0,
        isset($in['material_rate']) ? (int)$in['material_rate'] : 0,
        isset($in['labour_rate']) ? (int)$in['labour_rate'] : 0,
        $n));
    json_out(array('ok' => true, 'item_id' => (int)$db->lastInsertId(), 'totals' => drab_compute_totals($db, $rab_id)));
}
function handle_delete_item() {
    $uid = require_auth();
    $in = get_post_data();
    $db = get_db();
    $item_id = (int)$in['item_id'];
    $rab_id = drab_rab_id_for_item($db, $item_id);
    if (!$rab_id || !drab_owns_rab($db, $rab_id, $uid)) json_error(403, 'forbidden');
    $db->prepare("DELETE FROM drab_item_takeoffs WHERE item_id=?")->execute(array($item_id));
    $db->prepare("DELETE FROM drab_items WHERE id=?")->execute(array($item_id));
    json_out(array('ok' => true, 'totals' => drab_compute_totals($db, $rab_id)));
}
function handle_save_section() {
    $uid = require_auth();
    $in = get_post_data();
    $db = get_db();
    $section_id = (int)$in['section_id'];
    $rab_id = drab_rab_id_for_section($db, $section_id);
    if (!$rab_id || !drab_owns_rab($db, $rab_id, $uid)) json_error(403, 'forbidden');
    $sets = array(); $vals = array();
    foreach (array('name_en','name_id') as $f) if (array_key_exists($f,$in)) { $sets[]="$f=?"; $vals[]=$in[$f]; }
    if ($sets) { $vals[] = $section_id; $db->prepare("UPDATE drab_sections SET ".implode(',',$sets)." WHERE id=?")->execute($vals); }
    json_out(array('ok' => true));
}
function handle_add_section() {
    $uid = require_auth();
    $in = get_post_data();
    $db = get_db();
    $rab_id = (int)$in['rab_id'];
    if (!drab_owns_rab($db, $rab_id, $uid)) json_error(403, 'forbidden');
    $disc = isset($in['discipline']) ? $in['discipline'] : 'ARCH';
    $letter = array('PREP'=>'P','STR'=>'A','ARCH'=>'B','MEP'=>'C');
    $cnt = $db->prepare("SELECT COUNT(*) c FROM drab_sections WHERE rab_id=? AND discipline=?");
    $cnt->execute(array($rab_id, $disc));
    $n = (int)$cnt->fetch()['c'] + 1;
    $code = $letter[$disc] . '.' . $n;
    $ins = $db->prepare("INSERT INTO drab_sections (rab_id,discipline,code,name_en,name_id,sort_order) VALUES (?,?,?,?,?,?)");
    $ins->execute(array($rab_id, $disc, $code, isset($in['name_en'])?$in['name_en']:'NEW SECTION', isset($in['name_id'])?$in['name_id']:'BAGIAN BARU', 9000+$n));
    json_out(array('ok' => true, 'section_id' => (int)$db->lastInsertId(), 'code' => $code));
}
function handle_delete_section() {
    $uid = require_auth();
    $in = get_post_data();
    $db = get_db();
    $section_id = (int)$in['section_id'];
    $rab_id = drab_rab_id_for_section($db, $section_id);
    if (!$rab_id || !drab_owns_rab($db, $rab_id, $uid)) json_error(403, 'forbidden');
    $items = $db->prepare("SELECT id FROM drab_items WHERE section_id=?");
    $items->execute(array($section_id));
    foreach ($items->fetchAll() as $it) $db->prepare("DELETE FROM drab_item_takeoffs WHERE item_id=?")->execute(array($it['id']));
    $db->prepare("DELETE FROM drab_items WHERE section_id=?")->execute(array($section_id));
    $db->prepare("DELETE FROM drab_sections WHERE id=?")->execute(array($section_id));
    json_out(array('ok' => true, 'totals' => drab_compute_totals($db, $rab_id)));
}
function handle_save_takeoff() {
    $uid = require_auth();
    $in = get_post_data();
    $db = get_db();
    $item_id = (int)$in['item_id'];
    $rab_id = drab_rab_id_for_item($db, $item_id);
    if (!$rab_id || !drab_owns_rab($db, $rab_id, $uid)) json_error(403, 'forbidden');
    $rows = isset($in['rows']) && is_array($in['rows']) ? $in['rows'] : array();
    $db->prepare("DELETE FROM drab_item_takeoffs WHERE item_id=?")->execute(array($item_id));
    $sum = 0; $i = 0;
    foreach ($rows as $r) {
        $i++;
        $label = isset($r['label']) ? $r['label'] : '';
        $qty = isset($r['quantity']) ? (float)$r['quantity'] : 0;
        $sum += $qty;
        $db->prepare("INSERT INTO drab_item_takeoffs (item_id,label,quantity,sort_order) VALUES (?,?,?,?)")
           ->execute(array($item_id, $label, $qty, $i));
    }
    $has = $i > 0 ? 1 : 0;
    if ($has) $db->prepare("UPDATE drab_items SET quantity=?, has_takeoff=1 WHERE id=?")->execute(array(round($sum,4), $item_id));
    else $db->prepare("UPDATE drab_items SET has_takeoff=0 WHERE id=?")->execute(array($item_id));
    json_out(array('ok' => true, 'quantity' => round($sum,4), 'totals' => drab_compute_totals($db, $rab_id)));
}

// ============================================================================
// SLOT alternatives + swap
// ============================================================================
function handle_slot_alternatives() {
    $uid = require_auth();
    $db = get_db();
    $slot = isset($_GET['slot']) ? $_GET['slot'] : '';
    $allow_confirmed = user_can('drab_confirmed_pricing', $uid);
    // any work item carrying this spec_slot, plus slot_option items
    $q = $db->prepare("SELECT DISTINCT wi.id, wi.code, wi.name_en, wi.name_id, wi.spec_en, wi.spec_id, u.code AS unit_code
        FROM drab_work_items wi JOIN drab_units u ON u.id=wi.unit_id
        WHERE (wi.spec_slot=? OR wi.id IN (SELECT work_item_id FROM drab_slot_options WHERE slot_code=?)) AND wi.is_active=1
        ORDER BY wi.name_en");
    $q->execute(array($slot, $slot));
    $out = array();
    foreach ($q->fetchAll() as $wi) {
        $pr = drab_resolve_price($db, $wi['id'], 'south', $allow_confirmed);
        $out[] = array('work_item_id'=>(int)$wi['id'],'name_en'=>$wi['name_en'],'name_id'=>$wi['name_id'],
            'unit_code'=>$wi['unit_code'],'rate'=>$pr[0]+$pr[1],'confidence'=>$pr[2]);
    }
    json_out(array('ok' => true, 'options' => $out));
}
function handle_swap_slot() {
    $uid = require_auth();
    $in = get_post_data();
    $db = get_db();
    $item_id = (int)$in['item_id'];
    $new_wi = (int)$in['work_item_id'];
    $rab_id = drab_rab_id_for_item($db, $item_id);
    if (!$rab_id || !drab_owns_rab($db, $rab_id, $uid)) json_error(403, 'forbidden');
    // development zone for repricing
    $zq = $db->prepare("SELECT d.base_zone,d.distance_band,d.access_level FROM drab_developments d
        JOIN drab_buildings b ON b.development_id=d.id JOIN drab_rabs r ON r.building_id=b.id WHERE r.id=?");
    $zq->execute(array($rab_id));
    $z = $zq->fetch();
    $wq = $db->prepare("SELECT wi.*, u.code AS unit_code FROM drab_work_items wi JOIN drab_units u ON u.id=wi.unit_id WHERE wi.id=?");
    $wq->execute(array($new_wi));
    $wi = $wq->fetch();
    if (!$wi) json_error(404, 'work_item_not_found');
    $sf = drab_site_factor($db, $z['distance_band'], $z['access_level']);
    $pr = drab_resolve_price($db, $new_wi, $z['base_zone'], user_can('drab_confirmed_pricing', $uid));
    $mat = (int)round($pr[0] * (1 + $sf[0]));
    $lab = (int)round($pr[1] * (1 + $sf[1]));
    $db->prepare("UPDATE drab_items SET work_item_id=?, slot_code=?, name_en=?, name_id=?, unit_id=?, material_rate=?, labour_rate=?, confidence=?, is_pc_sum=? WHERE id=?")
       ->execute(array($new_wi, $wi['spec_slot'],
           $wi['name_en'] . ($wi['spec_en'] ? ' — ' . $wi['spec_en'] : ''),
           $wi['name_id'] . ($wi['spec_id'] ? ' — ' . $wi['spec_id'] : ''),
           $wi['unit_id'], $mat, $lab, $pr[2], (int)$wi['is_pc_sum'], $item_id));
    json_out(array('ok' => true, 'totals' => drab_compute_totals($db, $rab_id)));
}

// ============================================================================
// Markups / display / meta
// ============================================================================
function handle_set_markups() {
    $uid = require_auth();
    $in = get_post_data();
    $db = get_db();
    $rab_id = (int)$in['rab_id'];
    if (!drab_owns_rab($db, $rab_id, $uid)) json_error(403, 'forbidden');
    $on = !empty($in['markups_on']) ? 1 : 0;
    // Clamp 0–100 server-side — the client max attribute is not authoritative
    // (a direct API call or paste could otherwise persist absurd/negative rates).
    $oh = max(0.0, min(100.0, isset($in['overhead_pct']) ? (float)$in['overhead_pct'] : 0));
    $co = max(0.0, min(100.0, isset($in['contingency_pct']) ? (float)$in['contingency_pct'] : 0));
    $pp = max(0.0, min(100.0, isset($in['ppn_pct']) ? (float)$in['ppn_pct'] : 0));
    $db->prepare("UPDATE drab_rabs SET markups_on=?, overhead_pct=?, contingency_pct=?, ppn_pct=? WHERE id=?")
       ->execute(array($on, $oh, $co, $pp, $rab_id));
    json_out(array('ok' => true, 'totals' => drab_compute_totals($db, $rab_id)));
}
function handle_set_display() {
    $uid = require_auth();
    $in = get_post_data();
    $db = get_db();
    $dev_id = (int)$in['development_id'];
    $own = $db->prepare("SELECT id FROM drab_developments WHERE id=? AND user_id=?");
    $own->execute(array($dev_id, $uid));
    if (!$own->fetch()) json_error(403, 'forbidden');
    $sets = array(); $vals = array();
    foreach (array('display_combined','lang') as $f) if (array_key_exists($f,$in)) { $sets[]="$f=?"; $vals[]=$in[$f]; }
    if ($sets) { $vals[]=$dev_id; $db->prepare("UPDATE drab_developments SET ".implode(',',$sets)." WHERE id=?")->execute($vals); }
    json_out(array('ok'=>true));
}
function handle_save_rab_meta() {
    $uid = require_auth();
    $in = get_post_data();
    $db = get_db();
    $rab_id = (int)$in['rab_id'];
    if (!drab_owns_rab($db, $rab_id, $uid)) json_error(403, 'forbidden');
    $sets = array(); $vals = array();
    foreach (array('name','notes','status') as $f) if (array_key_exists($f,$in)) {
        if ($f === 'status' && !in_array($in[$f], array('draft','issued_baseline'))) continue;
        $sets[]="$f=?"; $vals[]=$in[$f];
    }
    if ($sets) { $vals[]=$rab_id; $db->prepare("UPDATE drab_rabs SET ".implode(',',$sets)." WHERE id=?")->execute($vals); }
    json_out(array('ok'=>true));
}

// ============================================================================
// AHSP transparency
// ============================================================================
function handle_ahsp() {
    $uid = require_auth();
    // SEC-014: the coefficient build-up exposes base prices + the derived rate
    // (the premium Confirmed price book). Gate it like handle_catalog().
    if (!user_can('drab_confirmed_pricing', $uid)) {
        json_out(array('error' => 'upgrade_required', 'feature' => 'drab_confirmed_pricing'), 403);
    }
    $db = get_db();
    $wi = (int)$_GET['work_item_id'];
    $zone = isset($_GET['zone']) && $_GET['zone'] === 'south' ? 'south' : 'mataram';
    $q = $db->prepare("SELECT c.comp_type, c.base_code, c.coefficient, bp.name_en, bp.name_id, bp.price, u.code AS unit_code
        FROM drab_ahsp_components c
        LEFT JOIN drab_base_prices bp ON bp.code=c.base_code AND bp.zone=?
        LEFT JOIN drab_units u ON u.id=bp.unit_id
        WHERE c.work_item_id=? ORDER BY c.comp_type, c.id");
    $q->execute(array($zone, $wi));
    $typeMap = array('bahan'=>'material','upah'=>'labour','alat'=>'equipment');
    $rows = array(); $total = 0;
    foreach ($q->fetchAll() as $r) {
        $cost = (float)$r['coefficient'] * (float)$r['price'];
        $total += $cost;
        $t = isset($typeMap[$r['comp_type']]) ? $typeMap[$r['comp_type']] : $r['comp_type'];
        $rows[] = array('type'=>$t,'name_en'=>$r['name_en'],'name_id'=>$r['name_id'],
            'unit'=>$r['unit_code'],'coefficient'=>(float)$r['coefficient'],'price'=>(int)$r['price'],'cost'=>$cost);
    }
    json_out(array('ok'=>true,'components'=>$rows,'derived_rate'=>$total,'has_buildup'=>count($rows)>0));
}

// ============================================================================
// CATALOG browser (premium)
// ============================================================================
function handle_catalog() {
    $uid = require_auth();
    if (!user_can('drab_catalog_browse', $uid)) json_out(array('error'=>'upgrade_required','feature'=>'drab_catalog_browse'), 403);
    $db = get_db();
    $q = isset($_GET['q']) ? trim($_GET['q']) : '';
    $disc = isset($_GET['discipline']) ? $_GET['discipline'] : '';
    $sql = "SELECT wi.id,wi.code,wi.discipline,wi.section_group,wi.name_en,wi.name_id,u.code AS unit_code FROM drab_work_items wi JOIN drab_units u ON u.id=wi.unit_id WHERE wi.is_active=1";
    $args = array();
    if ($q !== '') { $sql .= " AND (wi.name_en LIKE ? OR wi.name_id LIKE ? OR wi.code LIKE ?)"; $args[] = "%$q%"; $args[] = "%$q%"; $args[] = "%$q%"; }
    if ($disc !== '') { $sql .= " AND wi.discipline=?"; $args[] = $disc; }
    $sql .= " ORDER BY wi.discipline, wi.code LIMIT 200";
    $st = $db->prepare($sql); $st->execute($args);
    $out = array();
    foreach ($st->fetchAll() as $wi) {
        $pr = drab_resolve_price($db, $wi['id'], 'south', true);
        $out[] = array('work_item_id'=>(int)$wi['id'],'code'=>$wi['code'],'discipline'=>$wi['discipline'],
            'name_en'=>$wi['name_en'],'name_id'=>$wi['name_id'],'unit_code'=>$wi['unit_code'],
            'material'=>$pr[0],'labour'=>$pr[1],'rate'=>$pr[0]+$pr[1],'confidence'=>$pr[2]);
    }
    json_out(array('ok'=>true,'items'=>$out));
}

// ============================================================================
// USER TEMPLATES (premium)
// ============================================================================
function handle_save_template() {
    $uid = require_auth();
    if (!user_can('drab_templates', $uid)) json_out(array('error'=>'upgrade_required','feature'=>'drab_templates'), 403);
    $in = get_post_data();
    $db = get_db();
    $name = isset($in['name']) ? trim($in['name']) : 'Template';
    $payload = json_encode(isset($in['payload']) ? $in['payload'] : array());
    $db->prepare("INSERT INTO drab_user_templates (user_id,name,payload) VALUES (?,?,?)")->execute(array($uid, $name, $payload));
    json_out(array('ok'=>true,'id'=>(int)$db->lastInsertId()));
}
function handle_templates() {
    $uid = require_auth();
    $db = get_db();
    $st = $db->prepare("SELECT id,name,created_at FROM drab_user_templates WHERE user_id=? ORDER BY created_at DESC");
    $st->execute(array($uid));
    json_out(array('ok'=>true,'templates'=>$st->fetchAll(),'can_use'=>user_can('drab_templates',$uid)?1:0));
}
function handle_load_template() {
    $uid = require_auth();
    if (!user_can('drab_templates', $uid)) json_out(array('error'=>'upgrade_required','feature'=>'drab_templates'), 403);
    $in = get_post_data();
    $db = get_db();
    $st = $db->prepare("SELECT payload FROM drab_user_templates WHERE id=? AND user_id=?");
    $st->execute(array((int)$in['id'], $uid));
    $r = $st->fetch();
    if (!$r) json_error(404,'not_found');
    json_out(array('ok'=>true,'payload'=>json_decode($r['payload'], true)));
}

function handle_check_feature() {
    $uid = get_current_user_id();
    $key = isset($_GET['key']) ? $_GET['key'] : '';
    json_out(array('ok'=>true,'access'=>check_feature_access($key, $uid)));
}

// ============================================================================
// EXPORT — xlsx (PhpSpreadsheet-style via DrabXlsx) | csv fallback | pdf(html)
// ============================================================================
function handle_export() {
    $uid = require_auth();
    $rab_id = isset($_GET['rab_id']) ? (int)$_GET['rab_id'] : 0;
    if (!drab_owns_rab(get_db(), $rab_id, $uid)) { header('Content-Type: text/plain'); http_response_code(403); echo 'forbidden'; exit; }
    $format = isset($_GET['format']) ? $_GET['format'] : 'xlsx';
    $clean = user_can('drab_export_clean', $uid);
    $rab = drab_rab_payload(get_db(), $rab_id, $uid);
    $lang = isset($_GET['lang']) ? $_GET['lang'] : $rab['development']['lang'];
    $data = drab_build_export_model($rab, $lang, $clean);
    $fname = 'RAB-' . preg_replace('/[^A-Za-z0-9_-]/','_', $rab['building']['name']) . '-v' . $rab['version'];

    if ($format === 'csv') { drab_export_csv($data, $fname); }
    elseif ($format === 'pdf') { drab_export_pdf_html($data, $fname, $clean); }
    else { // xlsx
        $lib = __DIR__ . '/lib/xlsx_writer.php';
        if (file_exists($lib)) require_once $lib;
        if (class_exists('DrabXlsx')) {
            $w = new DrabXlsx();
            foreach ($data['sheets'] as $sh) $w->addSheet($sh['title'], $sh['rows'], isset($sh['opts'])?$sh['opts']:array());
            $w->stream($fname . '.xlsx');
            exit;
        }
        // fallback: html-table .xls (still opens in Excel)
        drab_export_xls_html($data, $fname);
    }
}

function drab_pick($row, $lang, $key) {
    if ($lang === 'id') return $row[$key.'_id'];
    if ($lang === 'both') return $row[$key.'_en'] . ' / ' . $row[$key.'_id'];
    return $row[$key.'_en'];
}
function drab_build_export_model($rab, $lang, $clean) {
    $combined = (int)$rab['development']['display_combined'] === 1;
    $b = $rab['building']; $dev = $rab['development']; $t = $rab['totals'];
    $title = ($lang==='id'?'RAB':'BILL OF QUANTITIES') . ' — ' . $b['name'];
    $watermark = $clean ? '' : ' (PREVIEW — upgrade for clean export)';

    // ---- Final Summary sheet ----
    $sum = array();
    $sum[] = array($dev['name']);
    $sum[] = array($b['name'] . ($dev['location_text']? ' — '.$dev['location_text'] : '') . $watermark);
    $sum[] = array('');
    $sum[] = array('BIDDER NAME : PT / CV ___________');
    $sum[] = array('DATE OF TENDER : ___________');
    $sum[] = array('');
    if ($combined) $sum[] = array('No.','Description','','','Total Amount (Rp)');
    else $sum[] = array('No.','Description','Material (Rp)','Labour (Rp)','Total Amount (Rp)');
    $discNames = array('PREP'=>array('en'=>'PRELIMINARIES','id'=>'PEKERJAAN PERSIAPAN'),
        'STR'=>array('en'=>'STRUCTURE','id'=>'STRUKTUR'),'ARCH'=>array('en'=>'ARCHITECTURE','id'=>'ARSITEKTUR'),
        'MEP'=>array('en'=>'MEP','id'=>'MEP'));
    $i=0;
    foreach (array('PREP','STR','ARCH','MEP') as $dc) {
        $i++;
        $dn = $lang==='id'?$discNames[$dc]['id']:$discNames[$dc]['en'];
        $m=$t['disciplines'][$dc]['m']; $l=$t['disciplines'][$dc]['l'];
        if ($combined) $sum[] = array($i, $dn, '', '', $m+$l);
        else $sum[] = array($i, $dn, $m, $l, $m+$l);
    }
    $sum[] = array('');
    $sum[] = array('', 'DIRECT CONSTRUCTION COST', '', '', $t['direct']);
    if ($t['markups_on']) {
        $sum[] = array('', 'Overhead & Profit ('.$t['overhead_pct'].'%)', '', '', $t['overhead']);
        $sum[] = array('', 'Contingency ('.$t['contingency_pct'].'%)', '', '', $t['contingency']);
        $sum[] = array('', 'PPN ('.$t['ppn_pct'].'%)', '', '', $t['ppn']);
    }
    $sum[] = array('', 'GRAND TOTAL', '', '', $t['grand']);
    $sum[] = array('');
    $sum[] = array('AREA SCHEDULE');
    $sum[] = array('Indoor (m²)', $rab['area_schedule']['indoor']);
    $sum[] = array('Accessible rooftop (m²)', $rab['area_schedule']['rooftop']);
    $sum[] = array('Outdoor (m²)', $rab['area_schedule']['outdoor']);
    $sum[] = array('Pool (m²)', $rab['area_schedule']['pool']);

    $sheets = array(array('title'=>'Final Summary','rows'=>$sum,'opts'=>array('summary'=>true)));

    // ---- discipline sheets ----
    $sheetMap = array('STR'=>'Structure','ARCH'=>'Architecture','MEP'=>'MEP','PREP'=>'Preliminaries');
    foreach (array('PREP','STR','ARCH','MEP') as $dc) {
        $rows = array();
        if ($combined) $rows[] = array('Ref','Description','Qty','Unit','Unit Price (Rp)','Amount (Rp)','Remark');
        else $rows[] = array('Ref','Description','Qty','Unit','Material (Rp)','Labour (Rp)','Amount (Rp)','Remark');
        $any = false;
        foreach ($rab['sections'] as $sec) {
            if ($sec['discipline'] !== $dc) continue;
            $any = true;
            $secName = $lang==='id'?$sec['name_id']:($lang==='both'?$sec['name_en'].' / '.$sec['name_id']:$sec['name_en']);
            $rows[] = array($sec['code'], $secName);
            foreach ($sec['items'] as $it) {
                $nm = $lang==='id'?$it['name_id']:($lang==='both'?$it['name_en'].' / '.$it['name_id']:$it['name_en']);
                $rate = $it['rate'];
                $remark = $it['is_pc_sum']?'PC Sum':'';
                if (!$clean && $it['confidence']==='confirmed') $remark = trim($remark.' [confirmed]');
                if ($combined) $rows[] = array($it['ref_code'], $nm, $it['quantity'], $it['unit_code'], $rate, $it['line_total'], $remark);
                else $rows[] = array($it['ref_code'], $nm, $it['quantity'], $it['unit_code'], $it['material_rate'], $it['labour_rate'], $it['line_total'], $remark);
                foreach ($it['takeoffs'] as $tk) {
                    if ($combined) $rows[] = array('', '   '.$tk['label'], $tk['quantity']);
                    else $rows[] = array('', '   '.$tk['label'], $tk['quantity']);
                }
            }
            $m=$sec['material']; $l=$sec['labour'];
            if ($combined) $rows[] = array('', 'Sub Total', '', '', '', $m+$l);
            else $rows[] = array('', 'Sub Total', '', '', $m, $l, $m+$l);
        }
        if ($any) $sheets[] = array('title'=>$sheetMap[$dc], 'rows'=>$rows);
    }
    return array('title'=>$title, 'sheets'=>$sheets);
}
// SEC-044: neutralise spreadsheet formula triggers in any user-controlled cell.
function drab_csv_cell($v) {
    $s = (string)$v;
    if ($s !== '' && preg_match('/^[=+\-@\t\r]/', $s)) $s = "'" . $s;
    return $s;
}
function drab_export_csv($data, $fname) {
    $fname = preg_replace('/[^A-Za-z0-9_.-]+/', '_', (string)$fname);   // no header injection
    if ($fname === '') $fname = 'rab';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="'.$fname.'.csv"');
    $out = fopen('php://output', 'w');
    foreach ($data['sheets'] as $sh) {
        fputcsv($out, array(drab_csv_cell('=== '.$sh['title'].' ===')));
        foreach ($sh['rows'] as $r) fputcsv($out, array_map('drab_csv_cell', $r));
        fputcsv($out, array(''));
    }
    fclose($out); exit;
}
function drab_export_xls_html($data, $fname) {
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="'.$fname.'.xls"');
    echo "\xEF\xBB\xBF";
    echo '<html><head><meta charset="utf-8"></head><body>';
    foreach ($data['sheets'] as $sh) {
        echo '<h3>'.htmlspecialchars($sh['title']).'</h3><table border="1" cellspacing="0" cellpadding="3">';
        foreach ($sh['rows'] as $r) {
            echo '<tr>';
            foreach ($r as $c) echo '<td>'.htmlspecialchars((string)$c).'</td>';
            echo '</tr>';
        }
        echo '</table><br>';
    }
    echo '</body></html>'; exit;
}
function drab_export_pdf_html($data, $fname, $clean) {
    // Lightweight printable HTML (browser "Save as PDF"); avoids a PDF dependency in v1.
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html><head><meta charset="utf-8"><title>'.htmlspecialchars($data['title']).'</title>';
    echo '<style>body{font-family:Arial,sans-serif;font-size:11px;color:#1a1a1a}h2{color:#1F4E5F}'.
         'table{border-collapse:collapse;width:100%;margin-bottom:18px}td,th{border:1px solid #ccc;padding:4px 6px;text-align:left}'.
         '.wm{position:fixed;top:40%;left:10%;font-size:80px;color:rgba(0,0,0,.06);transform:rotate(-25deg)}'.
         '@media print{.noprint{display:none}}</style></head><body>';
    if (!$clean) echo '<div class="wm">PREVIEW</div>';
    echo '<p class="noprint">Use your browser\'s Print → Save as PDF.</p>';
    foreach ($data['sheets'] as $sh) {
        echo '<h2>'.htmlspecialchars($sh['title']).'</h2><table>';
        foreach ($sh['rows'] as $r) {
            echo '<tr>';
            foreach ($r as $c) echo '<td>'.htmlspecialchars((string)$c).'</td>';
            echo '</tr>';
        }
        echo '</table>';
    }
    echo '</body></html>'; exit;
}
