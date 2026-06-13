<?php
/**
 * Build in Lombok — Listing Ingestion Worker API (machine-to-machine)
 *
 * The single authenticated door for the home Listing Worker (docs/adr/0007).
 * Everything is home-originated: the Worker POSTs here over HTTPS to pull work
 * and post results. HostPapa never reaches into the home box.
 *
 * Place at: /api/listing_ingest.php
 * Requires: PHP 7.4, the 2026_06_12_listing_ingestion.sql migration.
 *
 * Auth: every request carries the shared secret in a custom header
 *   X-Worker-Key: <secret>            (WORKER_API_KEY in private config)
 *
 * Endpoints (all POST, JSON body, ?action=):
 *   ping           -> auth/header check, no side effects
 *   pull_work      -> discovery search URLs + a batch of listings due re-check
 *   post_listing   -> authoritative ingest of one detail page (new or re-check):
 *                     canonicalise price/area/agent, dedupe, trust model, upsert
 *   post_liveness  -> report gone / failed / present for a listing
 */

require_once('/home/rovin629/config/biltest_config.php');
require_once(__DIR__ . '/listing_canonical.php');

header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

define('RECHECK_BATCH_DEFAULT', 80);   // nightly rolling-window size (ADR 0007)
define('PRICE_SURPRISE_RATIO', 5.0);

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
function read_worker_key() {
    // 1) custom header (normal case)
    if (function_exists('getallheaders')) {
        foreach (getallheaders() as $k => $v) if (strcasecmp($k, 'X-Worker-Key') === 0) return trim($v);
    }
    if (isset($_SERVER['HTTP_X_WORKER_KEY']) && $_SERVER['HTTP_X_WORKER_KEY'] !== '') return trim($_SERVER['HTTP_X_WORKER_KEY']);
    // 2) body fallback — some shared hosts strip custom request headers.
    $ct = isset($_SERVER['CONTENT_TYPE']) ? $_SERVER['CONTENT_TYPE'] : '';
    if (stripos($ct, 'application/json') !== false) {
        $d = json_decode(raw_post_body(), true);
        if (is_array($d) && !empty($d['worker_key'])) return trim((string)$d['worker_key']);
    }
    if (!empty($_POST['worker_key'])) return trim((string)$_POST['worker_key']);
    return '';
}
function require_worker_auth() {
    if (!defined('WORKER_API_KEY') || WORKER_API_KEY === '') json_error(500, 'Worker API key not configured.');
    $p = read_worker_key();
    if ($p === '' || !hash_equals(WORKER_API_KEY, $p)) json_error(401, 'Unauthorized.');
}

set_exception_handler(function ($e) {
    if (!headers_sent()) http_response_code(500);
    echo json_encode(array('error' => 'server_error', 'detail' => $e->getMessage()), JSON_UNESCAPED_UNICODE);
});

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_error(405, 'POST required.');
require_worker_auth();

$action = isset($_GET['action']) ? $_GET['action'] : '';
switch ($action) {
    case 'ping':          json_out(array('ok' => true, 'pong' => true)); break;
    case 'pull_work':     handle_pull_work(); break;
    case 'post_listing':  handle_post_listing(); break;
    case 'post_liveness': handle_post_liveness(); break;
    case 'recompute_reputation': handle_recompute_reputation(); break;
    case 'geography':     handle_geography(); break;       // areas + places + aliases for the Extractor prompt
    case 'serve_text':    handle_serve_text(); break;       // Mode A source: stored {id,title,description}
    case 'post_location': handle_post_location(); break;    // Mode A sink: location/place/tags only
    default:              json_error(400, 'Unknown action.');
}

// =====================================================================
// RECOMPUTE REPUTATION — worker-triggered nightly (ADR 0008)
// Lets the home Worker drive the reputation recompute with its own key, so no
// separate cPanel cron / CRON_REPUTATION_TOKEN is required.
// =====================================================================
function handle_recompute_reputation() {
    require_once(__DIR__ . '/reputation.php');
    $db = get_db();
    $n = bil_recompute_reputation($db);
    json_out(array('ok' => true, 'agents' => $n));
}

// =====================================================================
// PULL WORK — discovery URLs + the nightly re-check batch (ADR 0007)
// =====================================================================
function handle_pull_work() {
    $db = get_db();
    $data = get_post_data();
    $limit = isset($data['limit']) ? max(1, min(500, (int)$data['limit'])) : RECHECK_BATCH_DEFAULT;

    $disc = $db->query("SELECT id, source_site, label, search_url, max_pages FROM discovery_sources WHERE is_active = 1 ORDER BY id")->fetchAll();

    // Oldest-checked active listings first (NULL last_rechecked_at = never checked = first).
    // source_hash exists only post-migration — tolerate the older DB.
    $hash_col = lc_col_exists($db, 'listings', 'source_hash') ? 'source_hash' : "NULL AS source_hash";
    $sel = $db->prepare(
        "SELECT id, source_site, source_listing_id, source_url, price_idr, area_key, land_size_sqm, locked_fields, $hash_col
           FROM listings
          WHERE status = 'active' AND source_url IS NOT NULL AND source_url <> ''
          ORDER BY last_rechecked_at IS NULL DESC, last_rechecked_at ASC, id ASC
          LIMIT $limit"
    );
    $sel->execute();
    $rechecks = $sel->fetchAll();

    json_out(array('ok' => true, 'discovery_sources' => $disc, 'rechecks' => $rechecks, 'batch' => count($rechecks)));
}

// =====================================================================
// GEOGRAPHY — areas + places + aliases, injected into the Extractor prompt
// so the LLM knows the taxonomy AND every alias an admin has added.
// =====================================================================
function handle_geography() {
    $db = get_db();
    $areas = $db->query("SELECT `key`, label, region_key FROM areas ORDER BY region_key, sort_order, label")->fetchAll();
    $places = array();
    try { $places = $db->query("SELECT place_key, label, area_key FROM places WHERE is_active = 1 ORDER BY area_key, sort_order, label")->fetchAll(); } catch (Exception $e) {}
    // A compact alias hint list (text → key) so the model learns local synonyms.
    $aliases = array();
    try { $aliases = $db->query("SELECT alias_text, area_key, place_key FROM area_aliases ORDER BY alias_text LIMIT 1000")->fetchAll(); } catch (Exception $e) {}
    json_out(array('ok' => true, 'areas' => $areas, 'places' => $places, 'aliases' => $aliases));
}

// =====================================================================
// SERVE TEXT — Mode A source: stored title+description for re-extraction
// (location/tags only, no re-crawl). Cursor by id.
// =====================================================================
function handle_serve_text() {
    $db = get_db();
    $d = get_post_data();
    $after = isset($d['after_id']) ? (int)$d['after_id'] : 0;
    $limit = isset($d['limit']) ? max(1, min(500, (int)$d['limit'])) : 100;
    $pcol = lc_col_exists($db, 'listings', 'place_key') ? 'place_key' : "NULL AS place_key";
    $st = $db->prepare(
        "SELECT id, title, description, area_key, $pcol, locked_fields
           FROM listings
          WHERE status = 'active' AND id > ? AND description IS NOT NULL AND description <> ''
          ORDER BY id ASC LIMIT $limit"
    );
    $st->execute(array($after));
    $rows = $st->fetchAll();
    $next = $rows ? (int)$rows[count($rows) - 1]['id'] : 0;
    json_out(array('ok' => true, 'rows' => $rows, 'next_after_id' => $next, 'count' => count($rows)));
}

// =====================================================================
// POST LOCATION — Mode A sink: update only place/area (+ tags) for one
// listing from a stored-text re-extraction. Never touches price/size/title.
// =====================================================================
function handle_post_location() {
    $db = get_db();
    $d = get_post_data();
    $id = isset($d['listing_id']) ? (int)$d['listing_id'] : 0;
    if (!$id) json_error(400, 'listing_id required.');
    $st = $db->prepare("SELECT * FROM listings WHERE id = ? LIMIT 1");
    $st->execute(array($id));
    $existing = $st->fetch();
    if (!$existing) json_error(404, 'Listing not found.');

    $locked   = $existing['locked_fields'];
    $llm_area = isset($d['llm_area_key']) ? trim((string)$d['llm_area_key']) : '';
    $llm_place= isset($d['llm_place']) ? trim((string)$d['llm_place']) : '';
    $conf     = isset($d['extraction_confidence']) && $d['extraction_confidence'] !== '' ? (float)$d['extraction_confidence'] : null;
    $tags     = isset($d['tags']) && is_array($d['tags']) ? array_values(array_filter($d['tags'])) : array();

    // Resolve place_key + area_key (same priority as the full path).
    $area = null; $place = null;
    if ($llm_place !== '') {
        $r = lc_resolve_location($db, array($llm_place));
        if ($r['place_key']) { $place = $r['place_key']; $area = $r['area_key'] ?: lc_place_area($db, $place); }
        elseif ($r['area_key']) { $area = $r['area_key']; }
    }
    if (!$area && $llm_area !== '' && $llm_area !== 'unknown' && lc_area_exists($db, $llm_area)) $area = $llm_area;
    if ($place && !$area) $area = lc_place_area($db, $place);

    // The Worker only posts CORROBORATED locations (the place/area actually
    // appears in the listing text — ADR 0009), so we auto-apply them, even over
    // an existing keyword-derived area. Admin-locked fields are never touched,
    // and uncorroborated guesses never reach here (the Worker skips them), so
    // the review queue is no longer flooded with routine area changes.
    $set = array(); $par = array(); $changed = array();
    if ($area && !lc_is_locked($locked, 'area_key')) {
        if ((string)$existing['area_key'] !== (string)$area) { lc_record_revision($db, $id, 'area_key', $existing['area_key'], $area, 'extractor'); $changed[] = 'area_key'; }
        $set[] = "area_key = ?"; $par[] = $area;
        if ($place && !lc_is_locked($locked, 'place_key')) {
            if ((string)$existing['place_key'] !== (string)$place) { lc_record_revision($db, $id, 'place_key', $existing['place_key'], $place, 'extractor'); $changed[] = 'place_key'; }
            $set[] = "place_key = ?"; $par[] = $place;
        }
        if ($llm_place !== '' && !lc_is_locked($locked, 'location_detail')) { $set[] = "location_detail = ?"; $par[] = $llm_place; }
    }
    // Certificate (e.g. the LLM found "SHM" in the text) — mapped + lock-guarded.
    if (!empty($d['certificate_text']) && !lc_is_locked($locked, 'certificate_type_key')) {
        $cert = ingest_detect_certificate((string)$d['certificate_text']);
        if ($cert && (string)$existing['certificate_type_key'] !== (string)$cert) {
            lc_record_revision($db, $id, 'certificate_type_key', $existing['certificate_type_key'], $cert, 'extractor');
            $set[] = "certificate_type_key = ?"; $par[] = $cert;
        }
    }
    if ($conf !== null) { $set[] = "extraction_confidence = ?"; $par[] = $conf; }
    $set[] = "extraction_method = 'llm-location'";
    if ($set) {
        $set[] = "updated_at = NOW()";
        $par[] = $id;
        $db->prepare("UPDATE listings SET " . implode(', ', $set) . " WHERE id = ?")->execute($par);
    }
    if (!empty($tags)) lc_save_tags($db, $id, $tags);

    json_out(array('ok' => true, 'listing_id' => $id, 'area_key' => $area, 'place_key' => $place, 'changed' => $changed));
}

// =====================================================================
// POST LISTING — authoritative ingest of one detail page
// =====================================================================
function handle_post_listing() {
    $db = get_db();
    $d = get_post_data();

    $site   = isset($d['source_site']) ? trim((string)$d['source_site']) : '';
    $src_id = isset($d['source_listing_id']) ? trim((string)$d['source_listing_id']) : '';
    if ($site === '' || $src_id === '') json_error(400, 'source_site and source_listing_id required.');

    $title = isset($d['title']) ? trim((string)$d['title']) : '';
    if ($title === '') json_error(400, 'title required.');

    // ── Raw facts from the Worker ───────────────────────────────────
    $raw_amount = isset($d['price_amount']) ? $d['price_amount'] : null;
    $currency   = isset($d['price_currency']) ? $d['price_currency'] : 'IDR';
    $unit_label = isset($d['price_unit_label']) ? $d['price_unit_label'] : '';
    $land_sqm   = isset($d['land_size_sqm']) && $d['land_size_sqm'] !== '' ? (int)$d['land_size_sqm'] : null;
    $build_sqm  = isset($d['building_size_sqm']) && $d['building_size_sqm'] !== '' ? (int)$d['building_size_sqm'] : null;
    $beds       = isset($d['bedrooms']) && $d['bedrooms'] !== '' ? (int)$d['bedrooms'] : null;
    $baths      = isset($d['bathrooms']) && $d['bathrooms'] !== '' ? (int)$d['bathrooms'] : null;
    $desc       = isset($d['description']) ? trim((string)$d['description']) : '';
    $short      = $desc !== '' ? mb_substr($desc, 0, 200) : $title;
    $cert       = ingest_detect_certificate(isset($d['certificate_text']) ? $d['certificate_text'] : ($desc . ' ' . $title));
    $ltype      = ingest_listing_type(isset($d['listing_type']) ? $d['listing_type'] : '', $title);
    $photos     = isset($d['photos']) && is_array($d['photos']) ? array_values(array_filter($d['photos'])) : array();
    $source_url = isset($d['source_url']) ? trim((string)$d['source_url']) : '';

    // Place tier + extraction columns exist only after the migration; tolerate
    // the pre-migration DB so the nightly Worker never breaks before Jon runs it.
    $ext_cols = lc_col_exists($db, 'listings', 'place_key');

    // ── LLM Extractor fields (docs/adr/0009) ────────────────────────
    $llm_area   = isset($d['llm_area_key']) ? trim((string)$d['llm_area_key']) : '';
    $llm_place  = isset($d['llm_place']) ? trim((string)$d['llm_place']) : '';
    $source_hash = isset($d['source_hash']) ? trim((string)$d['source_hash']) : null;
    $extraction_method = isset($d['extraction_method']) ? trim((string)$d['extraction_method']) : null;
    $extraction_conf = isset($d['extraction_confidence']) && $d['extraction_confidence'] !== '' ? (float)$d['extraction_confidence'] : null;
    $llm_tags   = isset($d['tags']) && is_array($d['tags']) ? array_values(array_filter($d['tags'])) : array();

    // ── Canonicalise price (per-are fix; land-only per-m² gate) ─────
    $is_land = ($ltype === 'land');
    $idr_amount = lc_to_idr($db, $raw_amount, $currency);
    $price = lc_canonical_price($idr_amount, $unit_label, $land_sqm, $is_land);

    // Fallback: when the card price is missing or untrustworthy, recover the
    // real total from the description ("Hanya 1,9 M", "200 juta/are" × size).
    if (($price['price_idr'] === null || $price['flagged']) && $desc !== '') {
        $alt = lc_best_total_from_text($desc, $land_sqm);
        if ($alt) {
            $price['price_idr'] = $alt['total'];
            $price['price_idr_per_sqm'] = ($is_land && lc_trustworthy_size_sqm($land_sqm)) ? (int)round($alt['total'] / (int)$land_sqm) : null;
            $price['price_label'] = 'Total';
            $price['flagged'] = 0;
        }
    }

    // ── Resolve location → place_key + area_key (Place tier; no default) ──
    // Priority: the LLM's clean place name (it understood context, ignored
    // "30 min to Kuta") → its validated area_key → alias-resolve structured
    // fields + title. The raw description is used ONLY through the LLM, never
    // keyword-scanned here (that is what mis-tagged Awang as Kuta).
    $resolved_area = null; $resolved_place = null;
    if ($llm_place !== '') {
        $r = lc_resolve_location($db, array($llm_place));
        if ($r['place_key']) { $resolved_place = $r['place_key']; $resolved_area = $r['area_key'] ?: lc_place_area($db, $r['place_key']); }
        elseif ($r['area_key']) { $resolved_area = $r['area_key']; }
    }
    if (!$resolved_area && $llm_area !== '' && $llm_area !== 'unknown' && lc_area_exists($db, $llm_area)) {
        $resolved_area = $llm_area;
    }
    if (!$resolved_area) {
        $cands = array();
        if ($llm_place !== '') $cands[] = $llm_place;
        foreach (array('kecamatan','desa','district','sub_district','address','location_detail') as $f) {
            if (!empty($d[$f])) $cands[] = (string)$d[$f];
        }
        $cands[] = $title;
        $r = lc_resolve_location($db, $cands);
        $resolved_area = $r['area_key']; $resolved_place = $r['place_key'];
    }
    // A resolved Place always implies its Area.
    if ($resolved_place && !$resolved_area) $resolved_area = lc_place_area($db, $resolved_place);
    // location_detail = the specific place as named (LLM place preferred).
    $location_detail = $llm_place !== '' ? $llm_place
                     : (isset($d['kecamatan']) && $d['kecamatan'] !== '' ? trim((string)$d['kecamatan'])
                     : (isset($d['district']) ? trim((string)$d['district']) : ''));

    // ── Resolve agent (cross-portal identity) ───────────────────────
    $agent_in = isset($d['agent']) && is_array($d['agent']) ? $d['agent'] : array();
    $agent_in['source_site'] = $site;
    $agent_id = lc_resolve_agent($db, $agent_in);

    $land_are = $land_sqm ? round($land_sqm / 100, 2) : null;

    // ── Existing listing? ───────────────────────────────────────────
    // Match priority: explicit listing_id (re-check is authoritative about WHICH
    // row it is) → source tuple → source_url. This prevents the worker from
    // inserting a DUPLICATE when its derived source_listing_id differs from the
    // value the original import stored.
    $existing = false;
    $listing_id_in = isset($d['listing_id']) ? (int)$d['listing_id'] : 0;
    if ($listing_id_in > 0) {
        $st = $db->prepare("SELECT * FROM listings WHERE id = ? LIMIT 1");
        $st->execute(array($listing_id_in));
        $existing = $st->fetch();
    }
    if (!$existing) {
        $st = $db->prepare("SELECT * FROM listings WHERE source_site = ? AND source_listing_id = ? LIMIT 1");
        $st->execute(array($site, $src_id));
        $existing = $st->fetch();
    }
    if (!$existing && $source_url !== '') {
        $st = $db->prepare("SELECT * FROM listings WHERE source_url = ? ORDER BY id ASC LIMIT 1");
        $st->execute(array($source_url));
        $existing = $st->fetch();
    }

    if ($existing) {
        $id = (int)$existing['id'];
        $locked = $existing['locked_fields'];
        $set = array(); $par = array();

        // Fields whose changes are NOT worth recording in the history panel.
        $no_history = array('photo_urls', 'short_description', 'price_idr_per_sqm', 'price_label', 'land_size_are');
        $apply = function($col, $val) use (&$set, &$par, $locked, $db, $id, $existing, $no_history) {
            if (lc_is_locked($locked, $col)) return;
            // Never blank out an existing value with a null the scraper simply
            // failed to extract (e.g. OLX has no structured land size).
            $cur = isset($existing[$col]) ? $existing[$col] : null;
            if (($val === null || $val === '') && $cur !== null && $cur !== '') return;
            if (!in_array($col, $no_history, true)) {
                lc_record_revision($db, $id, $col, $cur, $val, 'worker');
            }
            $set[] = "$col = ?"; $par[] = $val;
        };

        // Price: guard surprises, never overwrite a locked price.
        if (!lc_is_locked($locked, 'price_idr')) {
            $new_idr = $price['price_idr'];
            if ($new_idr !== null && $existing['price_idr'] !== null && lc_is_price_surprise($existing['price_idr'], $new_idr)) {
                lc_queue_review($db, $id, 'price_surprise', array(
                    'old_price_idr' => (int)$existing['price_idr'], 'new_price_idr' => (int)$new_idr,
                    'unit_label' => $unit_label, 'land_size_sqm' => $land_sqm, 'source_url' => $source_url,
                ));
                // keep old price; still refresh per-sqm/label provenance
            } elseif ($new_idr === null && $existing['price_idr'] !== null) {
                // Extraction failed this cycle — keep the existing price, don't blank it.
            } else {
                lc_record_revision($db, $id, 'price_idr', $existing['price_idr'], $new_idr, 'worker');
                $set[] = "price_idr = ?";          $par[] = $new_idr;
                $set[] = "price_review_flag = ?";  $par[] = $price['flagged'];
                if ($price['flagged']) {
                    lc_queue_review($db, $id, 'per_are_no_size', array(
                        'unit_label' => $unit_label, 'raw_amount' => $idr_amount, 'land_size_sqm' => $land_sqm, 'source_url' => $source_url,
                    ));
                }
            }
            $apply('price_idr_per_sqm', $price['price_idr_per_sqm']);
            $apply('price_label', $price['price_label']);
        }

        // Area/Place: auto-apply only if previously empty or unchanged; a flip
        // goes to review. A resolved Place is applied with its Area.
        if (!lc_is_locked($locked, 'area_key') && $resolved_area) {
            // Auto-apply when empty/unchanged OR when the LLM corroborated a place
            // ($llm_place set) — only a keyword-only disagreement goes to review.
            if (empty($existing['area_key']) || $existing['area_key'] === $resolved_area || $llm_place !== '') {
                $apply('area_key', $resolved_area);
                if ($resolved_place && $ext_cols) $apply('place_key', $resolved_place);
            } else {
                lc_queue_review($db, $id, 'area_flip', array(
                    'old_area_key' => $existing['area_key'], 'new_area_key' => $resolved_area,
                    'new_place_key' => $resolved_place, 'evidence' => 'keyword',
                    'place' => $llm_place, 'source_url' => $source_url,
                ));
            }
        } elseif ($resolved_place && $ext_cols && !lc_is_locked($locked, 'place_key') && $existing['area_key'] === $resolved_area) {
            $apply('place_key', $resolved_place); // same Area, just refine the Place
        } elseif (!$resolved_area && empty($existing['area_key'])) {
            lc_queue_review($db, $id, 'unmapped_area', array('place' => $llm_place, 'candidates' => $loc_candidates, 'source_url' => $source_url));
        }

        // Title: take the scraped one, but never replace a real title with a
        // generic breadcrumb ("Tanah Dijual di Lombok Tengah").
        if ($title !== '' && !(lc_is_generic_title($title) && !empty($existing['title']) && !lc_is_generic_title($existing['title']))) {
            $apply('title', $title);
        }
        $apply('short_description', $short);
        $apply('description', $desc !== '' ? $desc : $existing['description']);
        $apply('land_size_sqm', $land_sqm);
        $apply('land_size_are', $land_are);
        $apply('building_size_sqm', $build_sqm);
        $apply('bedrooms', $beds);
        $apply('bathrooms', $baths);
        $apply('certificate_type_key', $cert);
        $apply('listing_type_key', $ltype);
        if ($location_detail !== '') $apply('location_detail', $location_detail);
        if (!empty($photos)) $apply('photo_urls', json_encode($photos, JSON_UNESCAPED_SLASHES));
        // Only adopt an agent when none is set (don't reassign admin/known agents).
        if (empty($existing['agent_id']) && $agent_id) $apply('agent_id', $agent_id);

        // Extraction provenance + content-hash (for gating) — post-migration only.
        if ($ext_cols) {
            if ($source_hash !== null)       { $set[] = "source_hash = ?";            $par[] = $source_hash; }
            if ($extraction_method !== null) { $set[] = "extraction_method = ?";      $par[] = $extraction_method; }
            if ($extraction_conf !== null)   { $set[] = "extraction_confidence = ?";  $par[] = $extraction_conf; }
        }

        // Liveness: confirmed present this cycle; revive if it had expired.
        $set[] = "last_seen_at = NOW()";
        $set[] = "last_rechecked_at = NOW()";
        $set[] = "recheck_status = 'present'";
        $set[] = "recheck_fail_count = 0";
        $set[] = "updated_at = NOW()";
        if ($existing['status'] === 'expired') { $set[] = "status = 'active'"; }

        $par[] = $id;
        $db->prepare("UPDATE listings SET " . implode(', ', $set) . " WHERE id = ?")->execute($par);
        lc_save_tags($db, $id, lc_suggest_tags($title, $desc, $short, $ltype));
        if (!empty($llm_tags)) lc_save_tags($db, $id, $llm_tags);

        // De-dupe by source_url: keep the lowest-id active row, retire the rest.
        // Self-heals duplicates a buggy earlier run may have inserted — whichever
        // copy is re-checked, only the original (min id) stays active.
        $deduped = 0;
        if ($source_url !== '') {
            $minq = $db->prepare("SELECT MIN(id) FROM listings WHERE source_url = ? AND status = 'active'");
            $minq->execute(array($source_url));
            $keep_id = (int)$minq->fetchColumn();
            if ($keep_id > 0) {
                $ddl = $db->prepare("UPDATE listings SET status = 'expired', recheck_status = 'duplicate', updated_at = NOW() WHERE source_url = ? AND status = 'active' AND id <> ?");
                $ddl->execute(array($source_url, $keep_id));
                $deduped = $ddl->rowCount();
            }
        }
        json_out(array('ok' => true, 'listing_id' => $id, 'mode' => 'updated', 'price_flagged' => (int)$price['flagged'], 'deduped' => $deduped));
    }

    // ── New listing ─────────────────────────────────────────────────
    if (!$resolved_area) {
        // ingest with NULL area but flag it so it never silently becomes praya
        lc_queue_review($db, null, 'unmapped_area', array('candidates' => $loc_candidates, 'source_url' => $source_url, 'title' => $title));
    }
    if ($price['flagged']) {
        lc_queue_review($db, null, 'per_are_no_size', array('unit_label' => $unit_label, 'raw_amount' => $idr_amount, 'title' => $title, 'source_url' => $source_url));
    }

    $slug = lc_make_slug($title);
    $chk = $db->prepare("SELECT COUNT(*) FROM listings WHERE slug = ?");
    $chk->execute(array($slug));
    if ($chk->fetchColumn() > 0) $slug .= '-' . substr(md5($site . $src_id), 0, 6);

    // Post-migration columns (place_key + extraction provenance) added only when present.
    $extra_cols = $ext_cols ? 'place_key, source_hash, extraction_method, extraction_confidence,' : '';
    $extra_ph   = $ext_cols ? '?, ?, ?, ?,' : '';
    $extra_vals = $ext_cols ? array($resolved_place, $source_hash, $extraction_method, $extraction_conf) : array();
    $ins = $db->prepare(
        "INSERT INTO listings
            (slug, agent_id, listing_type_key, status, title, short_description, description,
             area_key, location_detail, price_idr, price_label, price_idr_per_sqm, price_review_flag,
             land_size_sqm, land_size_are, certificate_type_key, building_size_sqm, bedrooms, bathrooms,
             is_featured, is_approved, source_site, source_url, source_listing_id,
             {$extra_cols}
             source_scraped_at, first_seen_at, last_seen_at, last_rechecked_at, recheck_status, photo_urls)
         VALUES (?, ?, ?, 'active', ?, ?, ?,
             ?, ?, ?, ?, ?, ?,
             ?, ?, ?, ?, ?, ?,
             0, 1, ?, ?, ?,
             {$extra_ph}
             NOW(), NOW(), NOW(), NOW(), 'present', ?)"
    );
    $ins->execute(array_merge(array(
        $slug, $agent_id, $ltype, $title, $short, $desc,
        $resolved_area, $location_detail !== '' ? $location_detail : null,
        $price['price_idr'], $price['price_label'], $price['price_idr_per_sqm'], $price['flagged'],
        $land_sqm, $land_are, $cert, $build_sqm, $beds, $baths,
        $site, $source_url !== '' ? $source_url : null, $src_id),
        $extra_vals,
        array(!empty($photos) ? json_encode($photos, JSON_UNESCAPED_SLASHES) : null)
    ));
    $id = (int)$db->lastInsertId();
    lc_save_tags($db, $id, lc_suggest_tags($title, $desc, $short, $ltype));
    if (!empty($llm_tags)) lc_save_tags($db, $id, $llm_tags);
    json_out(array('ok' => true, 'listing_id' => $id, 'mode' => 'inserted', 'price_flagged' => (int)$price['flagged'], 'area_resolved' => $resolved_area ? 1 : 0));
}

// =====================================================================
// POST LIVENESS — gone (expire) / failed (skip) / present (touch)
// =====================================================================
function handle_post_liveness() {
    $db = get_db();
    $d = get_post_data();
    $state = isset($d['state']) ? (string)$d['state'] : '';

    // Locate the listing by id or source tuple.
    $id = isset($d['listing_id']) ? (int)$d['listing_id'] : 0;
    if (!$id && !empty($d['source_site']) && !empty($d['source_listing_id'])) {
        $st = $db->prepare("SELECT id FROM listings WHERE source_site = ? AND source_listing_id = ? LIMIT 1");
        $st->execute(array($d['source_site'], $d['source_listing_id']));
        $id = (int)$st->fetchColumn();
    }
    if (!$id) json_error(404, 'Listing not found.');

    if ($state === 'gone') {
        // Genuine removal -> expire immediately, but only from 'active'
        // (never touch sold/under_offer/draft); never hard-delete.
        $db->prepare(
            "UPDATE listings
                SET status = CASE WHEN status = 'active' THEN 'expired' ELSE status END,
                    recheck_status = 'gone', last_rechecked_at = NOW(), updated_at = NOW()
              WHERE id = ?"
        )->execute(array($id));
        json_out(array('ok' => true, 'listing_id' => $id, 'action' => 'expired'));
    }

    if ($state === 'failed') {
        // Infra failure (timeout/block) — NOT a removal. Touch last_rechecked
        // so it rotates to the back of the queue and is retried next cycle.
        $db->prepare("UPDATE listings SET recheck_status = 'failed', recheck_fail_count = recheck_fail_count + 1, last_rechecked_at = NOW() WHERE id = ?")
           ->execute(array($id));
        json_out(array('ok' => true, 'listing_id' => $id, 'action' => 'skipped'));
    }

    // present (alive, no detail change)
    $db->prepare("UPDATE listings SET recheck_status = 'present', recheck_fail_count = 0, last_seen_at = NOW(), last_rechecked_at = NOW() WHERE id = ?")
       ->execute(array($id));
    json_out(array('ok' => true, 'listing_id' => $id, 'action' => 'present'));
}

// ─── small detectors (mirror the paste importer) ─────────────────────
function ingest_detect_certificate($text) {
    $t = mb_strtolower((string)$text, 'UTF-8');
    if (strpos($t, 'shm') !== false || strpos($t, 'hak milik') !== false) return 'shm';
    if (strpos($t, 'hgb') !== false || strpos($t, 'hak guna') !== false) return 'hgb';
    if (strpos($t, 'hak pakai') !== false) return 'hak_pakai';
    if (strpos($t, 'girik') !== false || strpos($t, 'letter c') !== false) return 'girik';
    if (strpos($t, 'adat') !== false) return 'adat';
    return null;
}
function ingest_listing_type($hint, $title) {
    $t = mb_strtolower($hint . ' ' . $title, 'UTF-8');
    if (strpos($t, 'villa') !== false) return 'villa';
    if (strpos($t, 'rumah') !== false || strpos($t, 'house') !== false) return 'house';
    if (strpos($t, 'apart') !== false) return 'apartment';
    if (strpos($t, 'ruko') !== false || strpos($t, 'komersial') !== false || strpos($t, 'commercial') !== false) return 'commercial';
    if (strpos($t, 'tanah') !== false || strpos($t, 'land') !== false) return 'land';
    return 'land';
}
