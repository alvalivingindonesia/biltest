<?php
/**
 * Build in Lombok — Zoning & Land Check API (ADR 0013)
 * Namespace: zoning_*   (isolated subsystem; its own Leaflet map, separate from
 * the listings SVG region map of ADR 0005)
 * PHP 7.4 compatible — NO match(), NO fn(), NO named args, NO enums.
 *
 * Data is served from OUR DB (ingest-once): a point-in-polygon against
 * zoning_landuse_polys yields a Land-Use Class -> Buildability Status. Parcel
 * facts are read on demand from BHUMI's WMS (when configured). Owner identity is
 * never obtained — the only owner-grade answer is the notary-brokered Verified
 * Certificate Check. Free = Indicative; paid (concierge) = human-verified Confirmed.
 *
 * Public endpoints (see router):
 *   GET  ?action=meta                       — config, classes legend, csrf, auth
 *   GET  ?action=check&lat=..&lng=..        — triage: PIP -> class -> verdict
 *   GET  ?action=geocode&q=..               — landmark search (Photon proxy)
 *   GET  ?action=plot_profile&lat=..&lng=.. — BHUMI WMS parcel facts (if configured)
 *   POST ?action=save_plot                  — save a checked plot (auth)
 *   POST ?action=request_report             — request the Site Suitability Report
 *   POST ?action=upload_cert                — attach a certificate scan (hardened)
 *   GET  ?action=report&id=..&token=..      — view a report (owner/token/admin)
 *   GET  ?action=my_reports                 — current user's report requests (auth)
 */

require_once(__DIR__ . '/_sec.php');
require_once('/home/rovin629/config/biltest_config.php');
sec_session_start();
sec_install_json_exception_handler();

header('Content-Type: application/json; charset=utf-8');
sec_api_headers(true);
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
sec_require_same_origin();

// ─── DB + helpers (mirror drab_api.php conventions) ─────────────────────────
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
function is_admin_uid($uid) {
    if (!$uid) return false;
    $db = get_db();
    $stmt = $db->prepare("SELECT role FROM users WHERE id = ? AND is_active = 1");
    $stmt->execute(array($uid));
    $r = $stmt->fetchColumn();
    return ($r === 'admin' || $r === 'superadmin');
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
function zcfg($key, $default = '') {
    static $cache = null;
    if ($cache === null) {
        $cache = array();
        foreach (get_db()->query("SELECT cfg_key, cfg_value FROM zoning_config")->fetchAll() as $r) {
            $cache[$r['cfg_key']] = $r['cfg_value'];
        }
    }
    return isset($cache[$key]) ? $cache[$key] : $default;
}
function z_token() { return bin2hex(random_bytes(20)); }
function valid_latlng($lat, $lng) {
    if (!is_numeric($lat) || !is_numeric($lng)) return false;
    $lat = (float)$lat; $lng = (float)$lng;
    // Loosely constrain to the Lombok / NTB window so the tool is not abused as a
    // generic geocoder, and to keep results meaningful.
    return ($lat >= -9.3 && $lat <= -8.0 && $lng >= 115.5 && $lng <= 117.2);
}

// ─── Core: point-in-polygon lookup ──────────────────────────────────────────
/** Resolve a coordinate to its Land-Use Class row (RDTR preferred over RTRW). */
function zoning_lookup($lat, $lng) {
    $db = get_db();
    // SRID 0 geometry, X=lng Y=lat. RDTR (detailed) wins over RTRW where both cover.
    $sql = "SELECT p.id AS poly_id, p.plan_level, p.kdb, p.klb, p.kkb, p.max_floors,
                   p.confidence, p.source, p.source_date, p.raw_zona, p.kabupaten,
                   c.class_key, c.name_en, c.name_id, c.buildability, c.villa_allowed,
                   c.summary_en, c.summary_id, c.color
            FROM zoning_landuse_polys p
            JOIN zoning_landuse_classes c ON c.class_key = p.class_key
            WHERE p.is_active = 1
              AND MBRContains(p.geom, ST_GeomFromText(?, 0))
              AND ST_Contains(p.geom, ST_GeomFromText(?, 0))
            ORDER BY (p.plan_level = 'rdtr') DESC, p.id DESC
            LIMIT 1";
    $pt = 'POINT(' . (float)$lng . ' ' . (float)$lat . ')';
    $stmt = $db->prepare($sql);
    $stmt->execute(array($pt, $pt));
    return $stmt->fetch();
}

/** The honest 'unknown' class when no polygon covers the point. */
function zoning_unknown_class() {
    $db = get_db();
    $stmt = $db->prepare("SELECT class_key, name_en, name_id, buildability, villa_allowed, summary_en, summary_id, color FROM zoning_landuse_classes WHERE class_key='unknown' LIMIT 1");
    $stmt->execute();
    $c = $stmt->fetch();
    if (!$c) $c = array('class_key'=>'unknown','name_en'=>'Not Yet Mapped','name_id'=>'Belum Terpetakan','buildability'=>'unknown','villa_allowed'=>0,'summary_en'=>'','summary_id'=>'','color'=>'grey');
    return $c;
}

/** Build the triage result object for a coordinate. */
function build_triage($lat, $lng) {
    $row = zoning_lookup($lat, $lng);
    $covered = (bool)$row;
    if (!$covered) {
        $c = zoning_unknown_class();
        $row = array_merge($c, array('plan_level'=>null,'kdb'=>null,'klb'=>null,'kkb'=>null,'max_floors'=>null,'confidence'=>null,'source'=>null,'source_date'=>null,'raw_zona'=>null,'kabupaten'=>null,'poly_id'=>null));
    }
    $metrics = null;
    if ($row['kdb'] !== null || $row['klb'] !== null || $row['kkb'] !== null) {
        $metrics = array(
            'kdb' => $row['kdb'] !== null ? (float)$row['kdb'] : null,
            'klb' => $row['klb'] !== null ? (float)$row['klb'] : null,
            'kkb' => $row['kkb'] !== null ? (float)$row['kkb'] : null,
            'max_floors' => $row['max_floors'] !== null ? (int)$row['max_floors'] : null,
        );
    }
    return array(
        'covered' => $covered,
        'lat' => (float)$lat,
        'lng' => (float)$lng,
        'class_key' => $row['class_key'],
        'name_en' => $row['name_en'],
        'name_id' => $row['name_id'],
        'buildability' => $row['buildability'],
        'villa_allowed' => (int)$row['villa_allowed'],
        'summary_en' => $row['summary_en'],
        'summary_id' => $row['summary_id'],
        'color' => $row['color'],
        'raw_zona' => $row['raw_zona'],
        'plan_level' => $row['plan_level'],
        'metrics' => $metrics,
        'provenance' => array(
            'confidence' => $covered ? ($row['confidence'] ? $row['confidence'] : 'indicative') : null,
            'source' => $row['source'],
            'date' => $row['source_date'],
        ),
    );
}

/** Plain-English development-metrics line. */
function metrics_plain($m, $lang) {
    if (!$m) return '';
    $parts = array();
    if (!empty($m['kdb'])) {
        $parts[] = ($lang === 'id')
            ? ('Tapak bangunan maksimum sekitar ' . rtrim(rtrim(number_format($m['kdb'],2,'.',''),'0'),'.') . '% dari luas lahan')
            : ('Building footprint up to about ' . rtrim(rtrim(number_format($m['kdb'],2,'.',''),'0'),'.') . '% of the plot');
    }
    if (!empty($m['max_floors'])) {
        $parts[] = ($lang === 'id') ? ('maksimum sekitar ' . (int)$m['max_floors'] . ' lantai') : ('up to about ' . (int)$m['max_floors'] . ' floors');
    } elseif (!empty($m['klb'])) {
        $parts[] = ($lang === 'id') ? ('rasio luas lantai (KLB) ' . rtrim(rtrim(number_format($m['klb'],2,'.',''),'0'),'.')) : ('floor-area ratio (KLB) ' . rtrim(rtrim(number_format($m['klb'],2,'.',''),'0'),'.'));
    }
    if (!empty($m['kkb'])) {
        $parts[] = ($lang === 'id') ? ('tinggi maksimum sekitar ' . rtrim(rtrim(number_format($m['kkb'],2,'.',''),'0'),'.') . ' m') : ('height up to about ' . rtrim(rtrim(number_format($m['kkb'],2,'.',''),'0'),'.') . ' m');
    }
    return implode(($lang === 'id') ? ', ' : ', ', $parts);
}

/** Build the regulatory checklist + warnings, tailored to the Buildability Status. */
function build_checklist($buildability) {
    $steps = array();
    $steps[] = array('en' => 'Confirm the foreign-ownership structure (PT PMA company title, or Hak Pakai for a residence).',
                     'id' => 'Pastikan struktur kepemilikan asing (Hak Guna Bangunan via PT PMA, atau Hak Pakai untuk hunian).');
    $steps[] = array('en' => 'Engage a licensed notary (PPAT) for a certificate check (Pengecekan Sertipikat) and due diligence.',
                     'id' => 'Tunjuk notaris (PPAT) berlisensi untuk pengecekan sertipikat dan uji tuntas.');
    if ($buildability === 'permitted') {
        $steps[] = array('en' => 'Apply for KKPR / PKKPR (spatial-use suitability) confirmation via the OSS system.',
                         'id' => 'Ajukan konfirmasi KKPR / PKKPR (kesesuaian pemanfaatan ruang) melalui sistem OSS.');
        $steps[] = array('en' => 'Obtain a PBG building permit (and SLF on completion) before construction.',
                         'id' => 'Peroleh Persetujuan Bangunan Gedung (PBG) dan SLF saat selesai sebelum membangun.');
    } elseif ($buildability === 'restricted') {
        $steps[] = array('en' => 'Verify whether land-use conversion (alih fungsi) is possible — protected farmland (LP2B) cannot be converted.',
                         'id' => 'Verifikasi apakah alih fungsi lahan dimungkinkan — lahan pertanian dilindungi (LP2B) tidak dapat dialihfungsikan.');
        $steps[] = array('en' => 'Apply for KKPR / PKKPR; expect additional conditions or refusal for non-conforming use.',
                         'id' => 'Ajukan KKPR / PKKPR; siapkan syarat tambahan atau kemungkinan penolakan untuk penggunaan tidak sesuai.');
    } else { // prohibited / unknown
        $steps[] = array('en' => 'Do not transact on a build assumption — building is not permitted in this class. Seek written confirmation before any commitment.',
                         'id' => 'Jangan bertransaksi dengan asumsi dapat membangun — pembangunan tidak diizinkan pada kelas ini. Minta konfirmasi tertulis sebelum komitmen.');
    }
    return $steps;
}

function build_warnings($triage) {
    $w = array();
    if (in_array($triage['class_key'], array('pariwisata','permukiman','perdagangan_jasa'), true)) {
        $w[] = array('en' => 'If the plot is near the coast, a coastal setback (sempadan pantai, commonly ~100 m from the high-tide line) may forbid building on part of it.',
                     'id' => 'Bila lahan dekat pantai, sempadan pantai (umumnya ~100 m dari pasang tertinggi) dapat melarang pembangunan pada sebagiannya.');
    }
    $w[] = array('en' => 'Zoning boundaries are approximate at the pixel level — a plot can straddle two classes. The verified report checks the exact parcel.',
                 'id' => 'Batas zonasi bersifat perkiraan pada tingkat piksel — satu lahan bisa melintasi dua kelas. Laporan terverifikasi memeriksa bidang yang tepat.');
    return $w;
}

/** Assemble the full (Indicative) report content for a plot. */
function generate_report_draft($plot, $triage) {
    return array(
        'generated_at' => date('c'),
        'plot' => array(
            'lat' => (float)$plot['lat'], 'lng' => (float)$plot['lng'],
            'label' => isset($plot['label']) ? $plot['label'] : null,
            'nib' => isset($plot['nib']) ? $plot['nib'] : null,
        ),
        'buildability' => array(
            'status' => $triage['buildability'],
            'class_key' => $triage['class_key'],
            'name_en' => $triage['name_en'], 'name_id' => $triage['name_id'],
            'summary_en' => $triage['summary_en'], 'summary_id' => $triage['summary_id'],
            'color' => $triage['color'],
        ),
        'metrics' => $triage['metrics'],
        'metrics_plain_en' => metrics_plain($triage['metrics'], 'en'),
        'metrics_plain_id' => metrics_plain($triage['metrics'], 'id'),
        'checklist' => build_checklist($triage['buildability']),
        'warnings' => build_warnings($triage),
        'provenance' => $triage['provenance'],
        'disclaimer_en' => zcfg('disclaimer_en', ''),
        'disclaimer_id' => zcfg('disclaimer_id', ''),
    );
}

// ─── Hardened certificate upload storage ────────────────────────────────────
function zoning_upload_dir() {
    if (defined('ZONING_UPLOAD_DIR') && ZONING_UPLOAD_DIR) $base = ZONING_UPLOAD_DIR;
    else $base = '/home/rovin629/zoning_uploads';
    if (!is_dir($base)) { @mkdir($base, 0700, true); }
    if (!is_dir($base) || !is_writable($base)) {
        $base = sys_get_temp_dir() . '/biltest_zoning_uploads';
        if (!is_dir($base)) { @mkdir($base, 0700, true); }
    }
    return $base;
}

// ─── ROUTER ─────────────────────────────────────────────────────────────────
$action = isset($_GET['action']) ? $_GET['action'] : '';
$uid = get_current_user_id();
$ip  = sec_client_ip();

// CSRF for any state-changing action.
$write_actions = array('save_plot', 'request_report', 'upload_cert');
if (in_array($action, $write_actions, true)) {
    sec_require_csrf();
}

try {
switch ($action) {

case 'meta': {
    $classes = array();
    foreach (get_db()->query("SELECT class_key, name_en, name_id, buildability, villa_allowed, color, sort_order FROM zoning_landuse_classes WHERE is_active=1 ORDER BY sort_order")->fetchAll() as $c) {
        $classes[] = $c;
    }
    json_out(array(
        'csrf' => sec_csrf_token(),
        'authed' => (bool)$uid,
        'tier' => get_user_tier($uid),
        'is_admin' => is_admin_uid($uid),
        'map' => array(
            'center' => array((float)zcfg('map_center_lat','-8.78'), (float)zcfg('map_center_lng','116.28')),
            'zoom' => (int)zcfg('map_default_zoom','11'),
            'min_zoom' => (int)zcfg('map_min_zoom','9'),
            'max_zoom' => (int)zcfg('map_max_zoom','19'),
            'satellite_url' => zcfg('satellite_tiles_url',''),
            'satellite_attr' => zcfg('satellite_attribution',''),
            'labels_url' => zcfg('labels_tiles_url',''),
            'bhumi_wms_url' => zcfg('bhumi_wms_url',''),
            'bhumi_wms_layers' => zcfg('bhumi_wms_layers',''),
        ),
        'geocoder_enabled' => (zcfg('geocoder_url','') !== ''),
        'parcel_overlay' => (zcfg('parcel_overlay','0') === '1'),
        'report_price_idr' => (int)zcfg('report_price_idr','0'),
        'report_price_label' => fmt_idr((int)zcfg('report_price_idr','0')),
        'contact_whatsapp' => zcfg('contact_whatsapp',''),
        'disclaimer_en' => zcfg('disclaimer_en',''),
        'disclaimer_id' => zcfg('disclaimer_id',''),
        'coverage_note_en' => zcfg('coverage_note_en',''),
        'coverage_note_id' => zcfg('coverage_note_id',''),
        'classes' => $classes,
    ));
    break;
}

case 'check': {
    // Free triage — enforced server-side even though it is a free feature.
    $access = check_feature_access('zoning_check', $uid);
    if (empty($access['allowed'])) json_out(array('error' => 'access_denied', 'detail' => $access), 403);
    if (!sec_rate_ok('zoning_check', $ip, 60, 60)) json_error(429, 'rate_limited');
    $lat = isset($_GET['lat']) ? $_GET['lat'] : null;
    $lng = isset($_GET['lng']) ? $_GET['lng'] : null;
    if (!valid_latlng($lat, $lng)) json_error(400, 'invalid_coordinates');
    json_out(array('ok' => true, 'triage' => build_triage($lat, $lng)));
    break;
}

case 'geocode': {
    if (!sec_rate_ok('zoning_geocode', $ip, 30, 60)) json_error(429, 'rate_limited');
    $q = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
    if (strlen($q) < 2) json_out(array('ok' => true, 'results' => array()));
    $base = zcfg('geocoder_url', '');
    if ($base === '') json_out(array('ok' => true, 'results' => array()));
    // Bias to the Lombok / NTB window.
    $url = $base . (strpos($base, '?') === false ? '?' : '&')
         . 'q=' . rawurlencode($q)
         . '&limit=6&lang=en&lat=-8.65&lon=116.30&bbox=' . rawurlencode('115.7,-9.1,116.9,-8.1');
    $res = safe_fetch($url, array('timeout' => 8, 'max_bytes' => 512 * 1024));
    if (empty($res['ok'])) json_out(array('ok' => true, 'results' => array()));
    $j = json_decode($res['body'], true);
    $out = array();
    if (isset($j['features']) && is_array($j['features'])) {
        foreach ($j['features'] as $f) {
            if (empty($f['geometry']['coordinates'])) continue;
            $coords = $f['geometry']['coordinates']; // [lon, lat]
            $lng = (float)$coords[0]; $lat = (float)$coords[1];
            if (!valid_latlng($lat, $lng)) continue; // keep results on-island
            $p = isset($f['properties']) ? $f['properties'] : array();
            $bits = array();
            foreach (array('name','street','district','city','county','state') as $k) {
                if (!empty($p[$k]) && !in_array($p[$k], $bits, true)) $bits[] = $p[$k];
            }
            $label = $bits ? implode(', ', array_slice($bits, 0, 3)) : ($q);
            $out[] = array('label' => $label, 'lat' => $lat, 'lng' => $lng);
        }
    }
    json_out(array('ok' => true, 'results' => $out));
    break;
}

case 'plot_profile': {
    if (!sec_rate_ok('zoning_profile', $ip, 40, 60)) json_error(429, 'rate_limited');
    $lat = isset($_GET['lat']) ? $_GET['lat'] : null;
    $lng = isset($_GET['lng']) ? $_GET['lng'] : null;
    if (!valid_latlng($lat, $lng)) json_error(400, 'invalid_coordinates');
    $wms = zcfg('bhumi_wms_url', '');
    if ($wms === '') {
        // Parcel layer not connected yet — honest 'unavailable' (the Verified
        // Certificate Check is the legal route to parcel/owner facts anyway).
        json_out(array('ok' => true, 'available' => false, 'reason' => 'parcel_source_not_configured'));
    }
    // Cache by rounded point.
    $ck = 'pt:' . round((float)$lat, 5) . ',' . round((float)$lng, 5);
    $db = get_db();
    $st = $db->prepare("SELECT * FROM zoning_parcel_cache WHERE cache_key=? AND fetched_at > (NOW() - INTERVAL 30 DAY) LIMIT 1");
    $st->execute(array($ck));
    $hit = $st->fetch();
    if ($hit) {
        json_out(array('ok' => true, 'available' => true, 'cached' => true, 'profile' => array(
            'nib' => $hit['nib'], 'area_m2' => $hit['area_m2'], 'right_type' => $hit['right_type'],
            'registered_status' => $hit['registered_status'], 'znt_idr' => $hit['znt_idr'],
            'geojson' => $hit['geojson'] ? json_decode($hit['geojson'], true) : null,
            'source' => $hit['source'], 'fetched_at' => $hit['fetched_at'],
        )));
    }
    // Build a small GetFeatureInfo request around the point.
    $d = 0.0006; // ~60m box
    $minx = (float)$lng - $d; $miny = (float)$lat - $d; $maxx = (float)$lng + $d; $maxy = (float)$lat + $d;
    $layers = zcfg('bhumi_wms_layers', '');
    $u = $wms . (strpos($wms, '?') === false ? '?' : '&')
       . 'SERVICE=WMS&VERSION=1.1.1&REQUEST=GetFeatureInfo&INFO_FORMAT=application/json'
       . '&SRS=EPSG:4326&WIDTH=101&HEIGHT=101&X=50&Y=50'
       . '&BBOX=' . rawurlencode($minx . ',' . $miny . ',' . $maxx . ',' . $maxy)
       . '&LAYERS=' . rawurlencode($layers) . '&QUERY_LAYERS=' . rawurlencode($layers);
    $res = safe_fetch($u, array('timeout' => 10, 'max_bytes' => 512 * 1024));
    if (empty($res['ok'])) json_out(array('ok' => true, 'available' => false, 'reason' => 'fetch_failed'));
    $j = json_decode($res['body'], true);
    $prof = array('nib'=>null,'area_m2'=>null,'right_type'=>null,'registered_status'=>null,'znt_idr'=>null,'geojson'=>null,'source'=>'bhumi_wms');
    if (isset($j['features'][0])) {
        $f = $j['features'][0];
        $pr = isset($f['properties']) ? $f['properties'] : array();
        // Best-effort field mapping (BHUMI attribute names vary by layer).
        foreach ($pr as $k => $v) {
            $lk = strtolower($k);
            if (strpos($lk, 'nib') !== false && $prof['nib'] === null) $prof['nib'] = $v;
            elseif (strpos($lk, 'luas') !== false && $prof['area_m2'] === null) $prof['area_m2'] = $v;
            elseif ((strpos($lk, 'hak') !== false || strpos($lk, 'tipe') !== false) && $prof['right_type'] === null) $prof['right_type'] = $v;
        }
        if (isset($f['geometry'])) $prof['geojson'] = $f['geometry'];
    }
    // Cache.
    $ins = $db->prepare("INSERT INTO zoning_parcel_cache (cache_key, lat, lng, nib, area_m2, right_type, registered_status, geojson, raw, source) VALUES (?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE nib=VALUES(nib), area_m2=VALUES(area_m2), right_type=VALUES(right_type), geojson=VALUES(geojson), raw=VALUES(raw), fetched_at=NOW()");
    $ins->execute(array($ck, (float)$lat, (float)$lng, $prof['nib'],
        is_numeric($prof['area_m2']) ? $prof['area_m2'] : null, $prof['right_type'], $prof['registered_status'],
        $prof['geojson'] ? json_encode($prof['geojson']) : null, substr(json_encode($j), 0, 60000), 'bhumi_wms'));
    json_out(array('ok' => true, 'available' => true, 'cached' => false, 'profile' => $prof));
    break;
}

case 'overlay': {
    // Colour-overlay layer: zoning polygons (as GeoJSON) intersecting the map view.
    if (!sec_rate_ok('zoning_overlay', $ip, 120, 60)) json_error(429, 'rate_limited');
    $w = isset($_GET['w']) ? (float)$_GET['w'] : 115.7;
    $s = isset($_GET['s']) ? (float)$_GET['s'] : -9.3;
    $e = isset($_GET['e']) ? (float)$_GET['e'] : 117.2;
    $n = isset($_GET['n']) ? (float)$_GET['n'] : -8.0;
    if ($w > $e) { $t = $w; $w = $e; $e = $t; }
    if ($s > $n) { $t = $s; $s = $n; $n = $t; }
    // Simplify geometry to the viewport scale and cap coordinates to ~1 m precision.
    // Without this, a wide (desktop) view returned ~12 MB of full-resolution polygons:
    // the heavy query starved the shared DB (so the concurrent `check` hung — details
    // never loaded) and the giant payload froze the browser main thread (so parcels
    // and the panel never rendered). Mobile's smaller bbox dodged it. Tolerance scales
    // with the view span (Douglas–Peucker); ST_Simplify can null out tiny polys so we
    // COALESCE back to the original geometry for those.
    // Floor (~16 m) keeps zoomed-in views bounded too: without it a deep zoom applies
    // ~no simplification and returns the whole over-detailed polygon (was ~1 MB). For an
    // indicative colour overlay 16 m is plenty; the precise layer is "Land certificates".
    $span = max($e - $w, $n - $s);
    $tol  = $span / 700;
    if ($tol < 0.00015) $tol = 0.00015;
    $db = get_db();
    $poly = sprintf('POLYGON((%F %F,%F %F,%F %F,%F %F,%F %F))', $w, $s, $e, $s, $e, $n, $w, $n, $w, $s);
    $sql = "SELECT p.class_key, c.name_en, c.name_id, c.buildability,
                   ST_AsGeoJSON(COALESCE(ST_Simplify(p.geom, ?), p.geom), 5) gj
            FROM zoning_landuse_polys p JOIN zoning_landuse_classes c ON c.class_key = p.class_key
            WHERE p.is_active = 1 AND MBRIntersects(p.geom, ST_GeomFromText(?, 0)) LIMIT 4000";
    $st = $db->prepare($sql);
    $st->execute(array($tol, $poly));
    // Stream the FeatureCollection, embedding the SQL-built GeoJSON geometry verbatim.
    // Decoding then re-encoding it in PHP re-emitted every coordinate at full float
    // precision (serialize_precision), ~4x-ing the payload — so we concatenate instead.
    $parts = array();
    foreach ($st->fetchAll() as $r) {
        if ($r['gj'] === null || $r['gj'] === '') continue;
        $props = json_encode(array(
            'class_key'    => $r['class_key'],
            'name_en'      => $r['name_en'],
            'name_id'      => $r['name_id'],
            'buildability' => $r['buildability'],
        ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $parts[] = '{"type":"Feature","properties":' . $props . ',"geometry":' . $r['gj'] . '}';
    }
    echo '{"type":"FeatureCollection","features":[' . implode(',', $parts) . ']}';
    exit;
}

case 'save_plot': {
    $uid = require_auth();
    $in = get_post_data();
    $lat = isset($in['lat']) ? $in['lat'] : null;
    $lng = isset($in['lng']) ? $in['lng'] : null;
    if (!valid_latlng($lat, $lng)) json_error(400, 'invalid_coordinates');
    $triage = build_triage($lat, $lng);
    $label = isset($in['label']) ? mb_substr(trim((string)$in['label']), 0, 240) : null;
    $nib   = isset($in['nib']) ? mb_substr(trim((string)$in['nib']), 0, 60) : null;
    $db = get_db();
    $st = $db->prepare("INSERT INTO zoning_plots (user_id, lat, lng, label, nib, resolved_class_key, buildability, snapshot, is_saved) VALUES (?,?,?,?,?,?,?,?,1)");
    $st->execute(array($uid, (float)$lat, (float)$lng, $label, $nib, $triage['class_key'], $triage['buildability'], json_encode($triage)));
    json_out(array('ok' => true, 'plot_id' => (int)$db->lastInsertId()));
    break;
}

case 'request_report': {
    // Guests allowed (lead capture, GROW-FIRST) — strongly rate-limited.
    if (!sec_rate_ok('zoning_report_req', $ip, 8, 3600)) json_error(429, 'rate_limited');
    $in = get_post_data();
    $lat = isset($in['lat']) ? $in['lat'] : null;
    $lng = isset($in['lng']) ? $in['lng'] : null;
    if (!valid_latlng($lat, $lng)) json_error(400, 'invalid_coordinates');
    $name  = isset($in['contact_name']) ? mb_substr(trim((string)$in['contact_name']), 0, 140) : '';
    $email = isset($in['contact_email']) ? mb_substr(trim((string)$in['contact_email']), 0, 180) : '';
    $wa    = isset($in['contact_whatsapp']) ? preg_replace('/[^0-9+]/', '', (string)$in['contact_whatsapp']) : '';
    $msg   = isset($in['message']) ? mb_substr(trim((string)$in['message']), 0, 2000) : '';
    $label = isset($in['label']) ? mb_substr(trim((string)$in['label']), 0, 240) : null;
    $nib   = isset($in['nib']) ? mb_substr(trim((string)$in['nib']), 0, 60) : null;
    if ($email === '' && $wa === '') json_error(400, 'contact_required');
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) json_error(400, 'invalid_email');

    $db = get_db();
    $triage = build_triage($lat, $lng);
    $plot = array('lat'=>$lat,'lng'=>$lng,'label'=>$label,'nib'=>$nib);
    $stp = $db->prepare("INSERT INTO zoning_plots (user_id, lat, lng, label, nib, resolved_class_key, buildability, snapshot) VALUES (?,?,?,?,?,?,?,?)");
    $stp->execute(array($uid, (float)$lat, (float)$lng, $label, $nib, $triage['class_key'], $triage['buildability'], json_encode($triage)));
    $plot_id = (int)$db->lastInsertId();

    $draft = generate_report_draft($plot, $triage);
    $token = z_token();
    $price = (int)zcfg('report_price_idr', '0');
    $str = $db->prepare("INSERT INTO zoning_reports (plot_id, user_id, status, contact_name, contact_email, contact_whatsapp, message, price_idr, draft_json, access_token) VALUES (?,?,'requested',?,?,?,?,?,?,?)");
    $str->execute(array($plot_id, $uid, $name, $email, $wa, $msg, $price ? $price : null, json_encode($draft), $token));
    $report_id = (int)$db->lastInsertId();

    // Optional prefilled WhatsApp deep link to the business.
    $biz_wa = preg_replace('/[^0-9]/', '', zcfg('contact_whatsapp', ''));
    $wa_link = '';
    if ($biz_wa !== '') {
        $txt = "Hi, I would like the verified Site Suitability Report for a plot in Lombok.\n"
             . "Ref: ZLC-" . $report_id . "\n"
             . "Location: " . round((float)$lat,6) . ", " . round((float)$lng,6)
             . ($label ? ("\nLabel: " . $label) : '')
             . "\nIndicative zoning: " . $triage['name_en'] . " (" . $triage['buildability'] . ")";
        $wa_link = 'https://wa.me/' . $biz_wa . '?text=' . rawurlencode($txt);
    }
    json_out(array('ok' => true, 'report_id' => $report_id, 'token' => $token, 'ref' => 'ZLC-' . $report_id, 'wa_link' => $wa_link, 'price_idr' => $price, 'price_label' => $price ? fmt_idr($price) : null));
    break;
}

case 'upload_cert': {
    if (!sec_rate_ok('zoning_upload', $ip, 20, 3600)) json_error(429, 'rate_limited');
    $report_id = isset($_POST['report_id']) ? (int)$_POST['report_id'] : 0;
    $token = isset($_POST['token']) ? (string)$_POST['token'] : '';
    if (!$report_id || empty($_FILES['file'])) json_error(400, 'missing_file');
    $db = get_db();
    $st = $db->prepare("SELECT id, user_id, plot_id, access_token FROM zoning_reports WHERE id=? LIMIT 1");
    $st->execute(array($report_id));
    $rep = $st->fetch();
    if (!$rep) json_error(404, 'report_not_found');
    // Ownership: matching auth user OR a valid access token.
    $owner_ok = ($uid && (int)$rep['user_id'] === (int)$uid) || ($token !== '' && hash_equals((string)$rep['access_token'], $token));
    if (!$owner_ok && !is_admin_uid($uid)) json_error(403, 'forbidden');

    $f = $_FILES['file'];
    if ($f['error'] !== UPLOAD_ERR_OK) json_error(400, 'upload_error');
    if ($f['size'] > 8 * 1024 * 1024) json_error(400, 'file_too_large');
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($f['tmp_name']);
    $allowed = array('application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png');
    if (!isset($allowed[$mime])) json_error(400, 'unsupported_type');
    $ext = $allowed[$mime];
    $dir = zoning_upload_dir();
    $name = 'cert_' . $report_id . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
    $dest = rtrim($dir, '/').'/'.$name;
    if (!move_uploaded_file($f['tmp_name'], $dest)) json_error(500, 'store_failed');
    @chmod($dest, 0600);
    $sha = hash_file('sha256', $dest);
    $ins = $db->prepare("INSERT INTO zoning_cert_uploads (report_id, plot_id, user_id, original_name, stored_path, mime, size_bytes, sha256) VALUES (?,?,?,?,?,?,?,?)");
    $ins->execute(array($report_id, (int)$rep['plot_id'], $uid, mb_substr((string)$f['name'],0,240), $dest, $mime, (int)$f['size'], $sha));
    json_out(array('ok' => true, 'upload_id' => (int)$db->lastInsertId()));
    break;
}

case 'report': {
    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    $token = isset($_GET['token']) ? (string)$_GET['token'] : '';
    if (!$id) json_error(400, 'missing_id');
    $db = get_db();
    $st = $db->prepare("SELECT * FROM zoning_reports WHERE id=? LIMIT 1");
    $st->execute(array($id));
    $rep = $st->fetch();
    if (!$rep) json_error(404, 'not_found');
    $owner_ok = ($uid && (int)$rep['user_id'] === (int)$uid) || ($token !== '' && hash_equals((string)$rep['access_token'], $token));
    $admin = is_admin_uid($uid);
    if (!$owner_ok && !$admin) json_error(403, 'forbidden');
    $delivered = ($rep['status'] === 'delivered');
    $content = ($delivered && $rep['verified_json']) ? json_decode($rep['verified_json'], true) : json_decode($rep['draft_json'], true);
    json_out(array('ok' => true, 'report' => array(
        'id' => (int)$rep['id'], 'ref' => 'ZLC-' . (int)$rep['id'], 'status' => $rep['status'],
        'confidence' => $delivered ? 'confirmed' : 'indicative',
        'is_preview' => !$delivered,
        'price_idr' => $rep['price_idr'] !== null ? (int)$rep['price_idr'] : null,
        'price_label' => $rep['price_idr'] !== null ? fmt_idr($rep['price_idr']) : null,
        'created_at' => $rep['created_at'], 'delivered_at' => $rep['delivered_at'],
        'content' => $content,
    )));
    break;
}

case 'my_reports': {
    $uid = require_auth();
    $db = get_db();
    $st = $db->prepare("SELECT r.id, r.status, r.created_at, r.access_token, p.lat, p.lng, p.label, p.resolved_class_key, p.buildability FROM zoning_reports r JOIN zoning_plots p ON p.id=r.plot_id WHERE r.user_id=? ORDER BY r.id DESC LIMIT 50");
    $st->execute(array($uid));
    json_out(array('ok' => true, 'reports' => $st->fetchAll()));
    break;
}

default:
    json_error(404, 'unknown_action');
}
} catch (Exception $e) {
    error_log('[zoning_api] ' . $e->getMessage());
    json_error(500, 'server_error');
}
