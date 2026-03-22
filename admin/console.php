<?php
/**
 * Build in Lombok — Admin Console
 * Lightweight CRUD for all site data.
 * Access: /admin/console.php (not linked from any menu)
 */
session_start();
require_once('/home/rovin629/config/biltest_config.php');

// ─── AUTH ────────────────────────────────────────────────────────────
$auth_error = '';
if (isset($_POST['login'])) {
    $u = $_POST['username'] ?? '';
    $p = $_POST['password'] ?? '';
    if ($u === ADMIN_USER && $p === ADMIN_PASS) {
        $_SESSION['admin_auth'] = true;
    } else {
        $auth_error = 'Invalid credentials.';
    }
}
if (isset($_GET['logout'])) { session_destroy(); header('Location: console.php'); exit; }
if (empty($_SESSION['admin_auth'])) { show_login($auth_error); exit; }

// ─── AJAX API ────────────────────────────────────────────────────────
// ─── HELPER: Server-side currency conversion for listings ──────────
function convert_listing_prices($db_conn, &$fields, &$vals) {
    $currencies = array('usd', 'idr', 'eur', 'aud');
    $prices = array();
    foreach ($currencies as $c) {
        $key = 'price' . $c;
        $prices[$c] = (isset($_POST[$key]) && trim($_POST[$key]) !== '') ? floatval($_POST[$key]) : null;
    }
    // Find the source currency (first non-empty)
    $source = null;
    foreach ($currencies as $c) {
        if ($prices[$c] !== null && $prices[$c] > 0) { $source = $c; break; }
    }
    if (!$source) return;
    // Load rates
    $rates = array();
    try {
        $rs = $db_conn->query("SELECT from_currency, to_currency, rate FROM currency_rates");
        foreach ($rs as $r) {
            $rates[$r['from_currency'] . '_' . $r['to_currency']] = (float)$r['rate'];
        }
    } catch (Exception $e) { return; }
    // Fill missing currencies
    foreach ($currencies as $target) {
        if ($target === $source) continue;
        if ($prices[$target] !== null && $prices[$target] > 0) continue;
        $rateKey = strtoupper($source) . '_' . strtoupper($target);
        if (!isset($rates[$rateKey])) continue;
        $converted = round($prices[$source] * $rates[$rateKey]);
        $col = 'price' . $target . '=?';
        $replaced = false;
        for ($i = 0; $i < count($fields); $i++) {
            if ($fields[$i] === $col) { $vals[$i] = $converted; $replaced = true; break; }
        }
        if (!$replaced) { $fields[] = $col; $vals[] = $converted; }
    }
}

// All quick actions (delete, toggle, status change, approve/reject, edit)
// return JSON when called with ?ajax=1
if (isset($_GET['ajax']) && $_GET['ajax'] === '1' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    $db_ajax = new PDO('mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4', DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $aj_action = $_POST['aj_action'] ?? '';
    $aj_id = (int)($_POST['aj_id'] ?? 0);
    $aj_result = array('ok' => false, 'msg' => 'Unknown action');
    try {
        switch ($aj_action) {
            // ─── ENTITY DELETE (providers, developers, projects, guides) ───
            case 'delete_entity':
                $table = $_POST['aj_table'] ?? '';
                $allowed = array('providers','developers','projects','guides');
                if (in_array($table, $allowed) && $aj_id) {
                    // Clean up junction tables for providers/developers
                    if ($table === 'providers') {
                        $db_ajax->prepare("DELETE FROM provider_categories WHERE provider_id=?")->execute(array($aj_id));
                        $db_ajax->prepare("DELETE FROM provider_tags WHERE provider_id=?")->execute(array($aj_id));
                    } elseif ($table === 'developers') {
                        $db_ajax->prepare("DELETE FROM developer_areas WHERE developer_id=?")->execute(array($aj_id));
                        $db_ajax->prepare("DELETE FROM developer_categories WHERE developer_id=?")->execute(array($aj_id));
                        $db_ajax->prepare("DELETE FROM developer_tags WHERE developer_id=?")->execute(array($aj_id));
                    }
                    $db_ajax->prepare("DELETE FROM `{$table}` WHERE id=?")->execute(array($aj_id));
                    $aj_result = array('ok' => true, 'msg' => ucfirst($table) . ' entry deleted.');
                }
                break;

            // ─── LISTING ACTIONS ───
            case 'listing_delete':
                if ($aj_id) {
                    $db_ajax->prepare("DELETE FROM listings WHERE id=?")->execute(array($aj_id));
                    $aj_result = array('ok' => true, 'msg' => 'Listing deleted.');
                }
                break;

            case 'listing_approve':
                if ($aj_id) {
                    $db_ajax->prepare("UPDATE listings SET is_approved=1, status='active' WHERE id=?")->execute(array($aj_id));
                    $aj_result = array('ok' => true, 'msg' => 'Listing approved.', 'new_val' => 1);
                }
                break;

            case 'listing_reject':
                if ($aj_id) {
                    $db_ajax->prepare("UPDATE listings SET is_approved=0, status='draft' WHERE id=?")->execute(array($aj_id));
                    $aj_result = array('ok' => true, 'msg' => 'Listing rejected.', 'new_val' => 0);
                }
                break;

            case 'listing_toggle_featured':
                if ($aj_id) {
                    $db_ajax->prepare("UPDATE listings SET is_featured = NOT is_featured WHERE id=?")->execute(array($aj_id));
                    $row = $db_ajax->prepare("SELECT is_featured FROM listings WHERE id=?");
                    $row->execute(array($aj_id));
                    $nv = $row->fetchColumn();
                    $aj_result = array('ok' => true, 'msg' => 'Featured status updated.', 'new_val' => (int)$nv);
                }
                break;

            case 'listing_change_status':
                if ($aj_id) {
                    $ns = $_POST['new_status'] ?? '';
                    $valid = array('draft','active','under_offer','sold','expired');
                    if (in_array($ns, $valid)) {
                        $db_ajax->prepare("UPDATE listings SET status=? WHERE id=?")->execute(array($ns, $aj_id));
                        $aj_result = array('ok' => true, 'msg' => 'Status updated to ' . $ns . '.', 'new_val' => $ns);
                    }
                }
                break;

            case 'listing_edit':
                if ($aj_id) {
                    $fields = array();
                    $vals = array();
                    $allowed_f = array('title','listing_type','area_key','price_usd','price_idr','price_eur','price_aud','land_size_sqm','land_size_are','building_size_sqm','bedrooms','bathrooms','short_description','source_url','contact_whatsapp','agent_id','admin_notes');
                    foreach ($allowed_f as $f) {
                        if (isset($_POST[$f])) {
                            $fields[] = "`" . $f . "`=?";
                            $v = trim($_POST[$f]);
                            $vals[] = ($v === '') ? null : $v;
                        }
                    }
                    // Server-side currency conversion
                    convert_listing_prices($db_ajax, $fields, $vals);
                    if (count($fields) > 0) {
                        $vals[] = $aj_id;
                        $db_ajax->prepare("UPDATE listings SET " . implode(',', $fields) . " WHERE id=?")->execute($vals);
                    }
                    // Return updated row data
                    $upd = $db_ajax->prepare("SELECT pl.*, a.display_name AS agent_name, ar.label AS area_label FROM listings pl LEFT JOIN agents a ON a.id=pl.agent_id LEFT JOIN areas ar ON ar.`key`=pl.area_key WHERE pl.id=?");
                    $upd->execute(array($aj_id));
                    $aj_result = array('ok' => true, 'msg' => 'Listing updated.', 'row' => $upd->fetch());
                }
                break;

            // ─── USER ACTIONS (list view) ───
            case 'user_toggle_active':
                if ($aj_id) {
                    $db_ajax->prepare("UPDATE users SET is_active = NOT is_active WHERE id=?")->execute(array($aj_id));
                    $row = $db_ajax->prepare("SELECT is_active FROM users WHERE id=?"); $row->execute(array($aj_id));
                    $aj_result = array('ok' => true, 'msg' => 'User status updated.', 'new_val' => (int)$row->fetchColumn());
                }
                break;

            // ─── AGENT ACTIONS ───
            case 'agent_toggle_verified':
                if ($aj_id) {
                    $db_ajax->prepare("UPDATE agents SET is_verified = NOT is_verified WHERE id=?")->execute(array($aj_id));
                    $row = $db_ajax->prepare("SELECT is_verified FROM agents WHERE id=?"); $row->execute(array($aj_id));
                    $aj_result = array('ok' => true, 'msg' => 'Verification updated.', 'new_val' => (int)$row->fetchColumn());
                }
                break;

            case 'agent_toggle_active':
                if ($aj_id) {
                    $db_ajax->prepare("UPDATE agents SET is_active = NOT is_active WHERE id=?")->execute(array($aj_id));
                    $row = $db_ajax->prepare("SELECT is_active FROM agents WHERE id=?"); $row->execute(array($aj_id));
                    $aj_result = array('ok' => true, 'msg' => 'Active status updated.', 'new_val' => (int)$row->fetchColumn());
                }
                break;

            // ─── CLAIM/SUBMISSION REVIEW ───
            case 'claim_review':
                if ($aj_id) {
                    $decision = $_POST['decision'] ?? '';
                    $notes = trim($_POST['admin_notes'] ?? '');
                    if (in_array($decision, array('approved','rejected'))) {
                        $db_ajax->prepare("UPDATE claim_requests SET status=?, admin_notes=?, reviewed_at=NOW() WHERE id=?")
                           ->execute(array($decision, $notes ?: null, $aj_id));
                        if ($decision === 'approved') {
                            $cl = $db_ajax->prepare("SELECT user_id, provider_id FROM claim_requests WHERE id=?");
                            $cl->execute(array($aj_id));
                            $cl = $cl->fetch();
                            if ($cl) {
                                $db_ajax->prepare("INSERT IGNORE INTO provider_owners (provider_id, user_id) VALUES (?,?)")->execute(array($cl['provider_id'], $cl['user_id']));
                                $db_ajax->prepare("UPDATE users SET role='provider_owner' WHERE id=? AND role='user'")->execute(array($cl['user_id']));
                            }
                        }
                        $aj_result = array('ok' => true, 'msg' => 'Claim ' . $decision . '.', 'new_val' => $decision);
                    }
                }
                break;

            case 'submission_review':
                if ($aj_id) {
                    $decision = $_POST['decision'] ?? '';
                    $notes = trim($_POST['admin_notes'] ?? '');
                    if ($decision === 'rejected') {
                        $db_ajax->prepare("UPDATE listing_submissions SET status='rejected', admin_notes=?, reviewed_at=NOW() WHERE id=?")
                           ->execute(array($notes ?: null, $aj_id));
                        $aj_result = array('ok' => true, 'msg' => 'Submission rejected.', 'new_val' => 'rejected');
                    } elseif ($decision === 'approved') {
                        // simplified — just mark approved (the full provider-creation stays in the form handler for complex cases)
                        $sub = $db_ajax->prepare("SELECT * FROM listing_submissions WHERE id=?"); $sub->execute(array($aj_id)); $sub = $sub->fetch();
                        if ($sub) {
                            $slug = strtolower(trim(preg_replace('/[\s-]+/', '-', preg_replace('/[^a-z0-9\s-]/', '', strtolower(trim($sub['business_name'])))), '-'));
                            $cat_keys = explode(',', $sub['category_keys']);
                            $first_cat = trim($cat_keys[0]);
                            $db_ajax->prepare("INSERT INTO providers (slug, name, group_key, category_key, area_key, short_description, description, address, phone, whatsapp_number, website_url, google_maps_url, languages, is_active) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,1)")->execute(array(
                                $slug, $sub['business_name'], $sub['group_key'], $first_cat, $sub['area_key'],
                                $sub['short_description'], $sub['short_description'],
                                $sub['address'], $sub['phone'], $sub['whatsapp_number'], $sub['website_url'],
                                $sub['google_maps_url'], $sub['languages']
                            ));
                            $new_prov = (int)$db_ajax->lastInsertId();
                            $cat_ins = $db_ajax->prepare("INSERT IGNORE INTO provider_categories (provider_id, category_key) VALUES (?,?)");
                            foreach ($cat_keys as $ck) { $ck = trim($ck); if ($ck) $cat_ins->execute(array($new_prov, $ck)); }
                            $db_ajax->prepare("INSERT IGNORE INTO provider_owners (provider_id, user_id) VALUES (?,?)")->execute(array($new_prov, $sub['user_id']));
                            $db_ajax->prepare("UPDATE users SET role='provider_owner' WHERE id=? AND role='user'")->execute(array($sub['user_id']));
                            $db_ajax->prepare("UPDATE listing_submissions SET status='approved', admin_notes=?, reviewed_at=NOW(), created_provider_id=? WHERE id=?")
                               ->execute(array($notes ?: null, $new_prov, $aj_id));
                            $aj_result = array('ok' => true, 'msg' => 'Submission approved — provider created.', 'new_val' => 'approved');
                        }
                    }
                }
                break;

            // ─── SUBSCRIPTION UPDATE ───
            case 'subscription_update':
                if ($aj_id) {
                    $tier = $_POST['subscription_tier'] ?? 'free';
                    $period = $_POST['subscription_period'] ?: null;
                    $expires = $_POST['subscription_expires_at'] ?: null;
                    $auto_renew = isset($_POST['subscription_auto_renew']) ? 1 : 0;
                    $valid_tiers = array('free','basic','premium');
                    if (!in_array($tier, $valid_tiers)) $tier = 'free';
                    if ($period && !in_array($period, array('monthly','annual','lifetime'))) $period = null;
                    $started = ($tier !== 'free') ? date('Y-m-d H:i:s') : null;
                    $db_ajax->prepare("UPDATE users SET subscription_tier=?, subscription_period=?, subscription_started_at=COALESCE(subscription_started_at, ?), subscription_expires_at=?, subscription_auto_renew=? WHERE id=?")
                       ->execute(array($tier, $period, $started, $expires, $auto_renew, $aj_id));
                    $aj_result = array('ok' => true, 'msg' => 'Subscription updated to ' . $tier . '.', 'new_val' => $tier);
                }
                break;

            // ─── FEATURE ACCESS ───
            case 'feature_update':
                if ($aj_id) {
                    $tf = isset($_POST['tier_free']) ? 1 : 0;
                    $tb = isset($_POST['tier_basic']) ? 1 : 0;
                    $tp = isset($_POST['tier_premium']) ? 1 : 0;
                    $rl = isset($_POST['require_login']) ? 1 : 0;
                    $ia = isset($_POST['is_active']) ? 1 : 0;
                    $db_ajax->prepare("UPDATE feature_access SET tier_free=?, tier_basic=?, tier_premium=?, require_login=?, is_active=? WHERE id=?")
                       ->execute(array($tf, $tb, $tp, $rl, $ia, $aj_id));
                    $aj_result = array('ok' => true, 'msg' => 'Feature access updated.');
                }
                break;

            case 'feature_delete':
                if ($aj_id) {
                    $db_ajax->prepare("DELETE FROM feature_access WHERE id=?")->execute(array($aj_id));
                    $aj_result = array('ok' => true, 'msg' => 'Feature removed.');
                }
                break;

            // ─── LOOKUP DELETE ───
            case 'lookup_delete':
                $ltable = $_POST['aj_table'] ?? '';
                $lkey = $_POST['aj_key'] ?? '';
                $ok_tables = array('groups','categories','areas','project_types','project_statuses');
                if (in_array($ltable, $ok_tables) && $lkey) {
                    $db_ajax->prepare("DELETE FROM `{$ltable}` WHERE `key`=?")->execute(array($lkey));
                    $aj_result = array('ok' => true, 'msg' => 'Entry deleted.');
                }
                break;
        }
    } catch (Exception $e) {
        $aj_result = array('ok' => false, 'msg' => 'Error: ' . $e->getMessage());
    }
    echo json_encode($aj_result);
    exit;
}

// ─── DB ──────────────────────────────────────────────────────────────
function get_db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO('mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4', DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }
    return $pdo;
}

function slugify(string $t): string {
    $t = strtolower(trim($t));
    $t = preg_replace('/[^a-z0-9\s-]/', '', $t);
    $t = preg_replace('/[\s-]+/', '-', $t);
    return trim($t, '-');
}

// ─── ROUTING ─────────────────────────────────────────────────────────
$section = $_GET['s'] ?? 'dashboard';
$action  = $_GET['a'] ?? 'list';
$id      = (int)($_GET['id'] ?? 0);
$msg     = '';

// ─── HANDLE POST ACTIONS ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Join language checkboxes into comma-separated string
    if (isset($_POST['languages']) && is_array($_POST['languages'])) {
        $_POST['languages'] = implode(', ', $_POST['languages']);
    } elseif (!isset($_POST['languages'])) {
        $_POST['languages'] = '';
    }
    $db = get_db();
    try {
        // --- PROVIDERS ---
        if ($section === 'providers' && $action === 'save') {
            $data = $_POST;
            $slug = slugify($data['name']);
            if ($id) {
                $db->prepare("UPDATE providers SET name=?, slug=?, group_key=?, area_key=?,
                    short_description=?, description=?, address=?, latitude=?, longitude=?,
                    google_maps_url=?, google_rating=?, google_review_count=?,
                    phone=?, whatsapp_number=?, website_url=?, languages=?,
                    profile_photo_url=?, logo_url=?, hero_image_url=?, image_url_2=?, image_url_3=?, image_url_4=?,
                    instagram_url=?, facebook_url=?, linkedin_url=?,
                    is_featured=?, is_trusted=?, badge=?, is_active=? WHERE id=?")->execute([
                    $data['name'], $slug, $data['group_key'], $data['area_key'],
                    $data['short_description'], $data['description'], $data['address'], $data['latitude'] ?: null, $data['longitude'] ?: null,
                    $data['google_maps_url'], $data['google_rating'] ?: null, $data['google_review_count'] ?: 0,
                    $data['phone'], $data['whatsapp_number'], $data['website_url'], $data['languages'],
                    $data['profile_photo_url'] ?: null, $data['logo_url'] ?: null, $data['hero_image_url'] ?: null, $data['image_url_2'] ?: null, $data['image_url_3'] ?: null, $data['image_url_4'] ?: null,
                    $data['instagram_url'] ?: null, $data['facebook_url'] ?: null, $data['linkedin_url'] ?: null,
                    isset($data['is_featured']) ? 1 : 0, isset($data['is_trusted']) ? 1 : 0, $data['badge'] ?: null, isset($data['is_active']) ? 1 : 0, $id
                ]);
                // Update categories (junction table)
                $db->prepare("DELETE FROM provider_categories WHERE provider_id=?")->execute([$id]);
                $cat_ins = $db->prepare("INSERT IGNORE INTO provider_categories (provider_id, category_key) VALUES (?, ?)");
                foreach (($data['categories'] ?? []) as $ck) { if ($ck) $cat_ins->execute([$id, $ck]); }
                // Update tags
                $db->prepare("DELETE FROM provider_tags WHERE provider_id=?")->execute([$id]);
                $tags = array_filter(array_map('trim', explode(',', $data['tags'] ?? '')));
                $ins = $db->prepare("INSERT IGNORE INTO provider_tags (provider_id, tag) VALUES (?, ?)");
                foreach ($tags as $t) { if ($t) $ins->execute([$id, $t]); }
                $msg = 'Provider updated.';
            } else {
                $db->prepare("INSERT INTO providers (slug, name, group_key, category_key, area_key,
                    short_description, description, address, latitude, longitude,
                    google_maps_url, google_rating, google_review_count,
                    phone, whatsapp_number, website_url, languages,
                    profile_photo_url, logo_url, hero_image_url, image_url_2, image_url_3, image_url_4,
                    instagram_url, facebook_url, linkedin_url,
                    is_featured, is_trusted, badge, is_active)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")->execute([
                    $slug, $data['name'], $data['group_key'], ($data['categories'] ?? [''])[0] ?? '', $data['area_key'],
                    $data['short_description'], $data['description'], $data['address'], $data['latitude'] ?: null, $data['longitude'] ?: null,
                    $data['google_maps_url'], $data['google_rating'] ?: null, $data['google_review_count'] ?: 0,
                    $data['phone'], $data['whatsapp_number'], $data['website_url'], $data['languages'],
                    $data['profile_photo_url'] ?: null, $data['logo_url'] ?: null, $data['hero_image_url'] ?: null, $data['image_url_2'] ?: null, $data['image_url_3'] ?: null, $data['image_url_4'] ?: null,
                    $data['instagram_url'] ?: null, $data['facebook_url'] ?: null, $data['linkedin_url'] ?: null,
                    isset($data['is_featured']) ? 1 : 0, isset($data['is_trusted']) ? 1 : 0, $data['badge'] ?: null, isset($data['is_active']) ? 1 : 0
                ]);
                $new_id = $db->lastInsertId();
                // Insert categories (junction table)
                $cat_ins = $db->prepare("INSERT IGNORE INTO provider_categories (provider_id, category_key) VALUES (?, ?)");
                foreach (($data['categories'] ?? []) as $ck) { if ($ck) $cat_ins->execute([$new_id, $ck]); }
                $tags = array_filter(array_map('trim', explode(',', $data['tags'] ?? '')));
                $ins = $db->prepare("INSERT IGNORE INTO provider_tags (provider_id, tag) VALUES (?, ?)");
                foreach ($tags as $t) { if ($t) $ins->execute([$new_id, $t]); }
                $msg = 'Provider created.';
                $id = $new_id;
            }
        }
        // --- DEVELOPERS ---
        elseif ($section === 'developers' && $action === 'save') {
            $data = $_POST;
            $slug = slugify($data['name']);
            if ($id) {
                $db->prepare("UPDATE developers SET name=?, slug=?, short_description=?, description=?,
                    min_ticket_usd=?, google_maps_url=?, google_rating=?, google_review_count=?,
                    phone=?, whatsapp_number=?, website_url=?, languages=?,
                    profile_photo_url=?, logo_url=?, hero_image_url=?, image_url_2=?, image_url_3=?, image_url_4=?,
                    instagram_url=?, facebook_url=?, linkedin_url=?,
                    is_featured=?, badge=?, is_active=? WHERE id=?")->execute([
                    $data['name'], $slug, $data['short_description'], $data['description'],
                    $data['min_ticket_usd'] ?: null, $data['google_maps_url'], $data['google_rating'] ?: null, $data['google_review_count'] ?: 0,
                    $data['phone'], $data['whatsapp_number'], $data['website_url'], $data['languages'],
                    $data['profile_photo_url'] ?: null, $data['logo_url'] ?: null, $data['hero_image_url'] ?: null, $data['image_url_2'] ?: null, $data['image_url_3'] ?: null, $data['image_url_4'] ?: null,
                    $data['instagram_url'] ?: null, $data['facebook_url'] ?: null, $data['linkedin_url'] ?: null,
                    isset($data['is_featured']) ? 1 : 0, $data['badge'] ?: null, isset($data['is_active']) ? 1 : 0, $id
                ]);
                // Update areas
                $db->prepare("DELETE FROM developer_areas WHERE developer_id=?")->execute([$id]);
                foreach (($data['areas'] ?? []) as $ak) {
                    $db->prepare("INSERT IGNORE INTO developer_areas (developer_id, area_key) VALUES (?,?)")->execute([$id, $ak]);
                }
                // Update categories
                $db->prepare("DELETE FROM developer_categories WHERE developer_id=?")->execute([$id]);
                $cat_ins = $db->prepare("INSERT IGNORE INTO developer_categories (developer_id, category_key) VALUES (?, ?)");
                foreach (($data['categories'] ?? []) as $ck) { if ($ck) $cat_ins->execute([$id, $ck]); }
                // Update tags
                $db->prepare("DELETE FROM developer_tags WHERE developer_id=?")->execute([$id]);
                $tags = array_filter(array_map('trim', explode(',', $data['tags'] ?? '')));
                $ins = $db->prepare("INSERT IGNORE INTO developer_tags (developer_id, tag) VALUES (?, ?)");
                foreach ($tags as $t) { if ($t) $ins->execute([$id, $t]); }
                $msg = 'Developer updated.';
            } else {
                $db->prepare("INSERT INTO developers (slug, name, short_description, description,
                    min_ticket_usd, google_maps_url, google_rating, google_review_count,
                    phone, whatsapp_number, website_url, languages,
                    profile_photo_url, logo_url, hero_image_url, image_url_2, image_url_3, image_url_4,
                    instagram_url, facebook_url, linkedin_url,
                    is_featured, badge, is_active)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")->execute([
                    $slug, $data['name'], $data['short_description'], $data['description'],
                    $data['min_ticket_usd'] ?: null, $data['google_maps_url'], $data['google_rating'] ?: null, $data['google_review_count'] ?: 0,
                    $data['phone'], $data['whatsapp_number'], $data['website_url'], $data['languages'],
                    $data['profile_photo_url'] ?: null, $data['logo_url'] ?: null, $data['hero_image_url'] ?: null, $data['image_url_2'] ?: null, $data['image_url_3'] ?: null, $data['image_url_4'] ?: null,
                    $data['instagram_url'] ?: null, $data['facebook_url'] ?: null, $data['linkedin_url'] ?: null,
                    isset($data['is_featured']) ? 1 : 0, $data['badge'] ?: null, isset($data['is_active']) ? 1 : 0
                ]);
                $new_id = $db->lastInsertId();
                foreach (($data['areas'] ?? []) as $ak) {
                    $db->prepare("INSERT IGNORE INTO developer_areas (developer_id, area_key) VALUES (?,?)")->execute([$new_id, $ak]);
                }
                // Insert categories
                $cat_ins = $db->prepare("INSERT IGNORE INTO developer_categories (developer_id, category_key) VALUES (?, ?)");
                foreach (($data['categories'] ?? []) as $ck) { if ($ck) $cat_ins->execute([$new_id, $ck]); }
                $tags = array_filter(array_map('trim', explode(',', $data['tags'] ?? '')));
                $ins = $db->prepare("INSERT IGNORE INTO developer_tags (developer_id, tag) VALUES (?, ?)");
                foreach ($tags as $t) { if ($t) $ins->execute([$new_id, $t]); }
                $msg = 'Developer created.';
                $id = $new_id;
            }
        }
        // --- PROJECTS ---
        elseif ($section === 'projects' && $action === 'save') {
            $data = $_POST;
            $slug = slugify($data['name']);
            if ($id) {
                $db->prepare("UPDATE projects SET name=?, slug=?, developer_id=?, area_key=?, project_type_key=?, status_key=?,
                    min_investment_usd=?, expected_yield_range=?, timeline_summary=?,
                    short_description=?, description=?, website_url=?, info_contact_whatsapp=?, logo_url=?,
                    is_featured=?, badge=?, is_active=? WHERE id=?")->execute([
                    $data['name'], $slug, $data['developer_id'] ?: null, $data['area_key'], $data['project_type_key'], $data['status_key'],
                    $data['min_investment_usd'] ?: null, $data['expected_yield_range'], $data['timeline_summary'],
                    $data['short_description'], $data['description'], $data['website_url'], $data['info_contact_whatsapp'], $data['logo_url'] ?: null,
                    isset($data['is_featured']) ? 1 : 0, $data['badge'] ?: null, isset($data['is_active']) ? 1 : 0, $id
                ]);
                $msg = 'Project updated.';
            } else {
                $db->prepare("INSERT INTO projects (slug, name, developer_id, area_key, project_type_key, status_key,
                    min_investment_usd, expected_yield_range, timeline_summary,
                    short_description, description, website_url, info_contact_whatsapp, logo_url,
                    is_featured, badge, is_active) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")->execute([
                    $slug, $data['name'], $data['developer_id'] ?: null, $data['area_key'], $data['project_type_key'], $data['status_key'],
                    $data['min_investment_usd'] ?: null, $data['expected_yield_range'], $data['timeline_summary'],
                    $data['short_description'], $data['description'], $data['website_url'], $data['info_contact_whatsapp'], $data['logo_url'] ?: null,
                    isset($data['is_featured']) ? 1 : 0, $data['badge'] ?: null, isset($data['is_active']) ? 1 : 0
                ]);
                $msg = 'Project created.';
                $id = $db->lastInsertId();
            }
        }
        // --- GUIDES ---
        elseif ($section === 'guides' && $action === 'save') {
            $data = $_POST;
            $slug = slugify($data['title']);
            if ($id) {
                $db->prepare("UPDATE guides SET title=?, slug=?, category=?, read_time=?, excerpt=?, content=?, is_published=? WHERE id=?")->execute([
                    $data['title'], $slug, $data['category'], $data['read_time'], $data['excerpt'], $data['content'],
                    isset($data['is_published']) ? 1 : 0, $id
                ]);
                $msg = 'Guide updated.';
            } else {
                $db->prepare("INSERT INTO guides (slug, title, category, read_time, excerpt, content, is_published) VALUES (?,?,?,?,?,?,?)")->execute([
                    $slug, $data['title'], $data['category'], $data['read_time'], $data['excerpt'], $data['content'],
                    isset($data['is_published']) ? 1 : 0
                ]);
                $msg = 'Guide created.';
                $id = $db->lastInsertId();
            }
        }
        // --- LOOKUP TABLES (groups, categories, areas, project_types, project_statuses) ---
        elseif ($section === 'lookups' && $action === 'save') {
            $table = $_POST['_table'];
            $allowed = ['groups','categories','areas','project_types','project_statuses'];
            if (!in_array($table, $allowed)) throw new Exception('Invalid table');
            $key = trim($_POST['key']);
            $label = trim($_POST['label']);
            $sort = (int)($_POST['sort_order'] ?? 0);
            $old_key = $_POST['_old_key'] ?? '';

            // --- Sort collision: bump consecutive duplicates ---
            // Exclude the row being edited (old_key) from collision check
            $exclude_clause = $old_key ? "AND `key` != ?" : "";
            $check_params = $old_key ? [$table, $sort, $old_key] : [$table, $sort];
            // Check if any other row has this sort_order
            $conflict = $db->prepare("SELECT COUNT(*) FROM `{$table}` WHERE `sort_order` = ?" . ($old_key ? " AND `key` != ?" : ""));
            $conflict->execute($old_key ? [$sort, $old_key] : [$sort]);
            if ($conflict->fetchColumn() > 0) {
                // Get all sort_orders >= $sort (excluding the row being edited), ordered ascending
                $bump_q = $db->prepare("SELECT `key`, `sort_order` FROM `{$table}` WHERE `sort_order` >= ?" . ($old_key ? " AND `key` != ?" : "") . " ORDER BY `sort_order` ASC");
                $bump_q->execute($old_key ? [$sort, $old_key] : [$sort]);
                $to_bump = $bump_q->fetchAll();
                // Walk through and only increment consecutive collisions
                $expected = $sort;
                $upd = $db->prepare("UPDATE `{$table}` SET `sort_order` = ? WHERE `key` = ?");
                foreach ($to_bump as $row) {
                    if ((int)$row['sort_order'] === $expected) {
                        $upd->execute([$expected + 1, $row['key']]);
                        $expected++;
                    } else {
                        break; // gap found, stop bumping
                    }
                }
            }

            if ($old_key) {
                // Update
                $sql = "UPDATE `{$table}` SET `key`=?, `label`=?, `sort_order`=?";
                $params = [$key, $label, $sort];
                if ($table === 'categories') {
                    $sql .= ", `group_key`=?";
                    $params[] = $_POST['group_key'];
                }
                if ($table === 'areas' && isset($_POST['region_key'])) {
                    $sql .= ", `region_key`=?";
                    $params[] = $_POST['region_key'] ?: null;
                }
                $sql .= " WHERE `key`=?";
                $params[] = $old_key;
                $db->prepare($sql)->execute($params);
                $msg = ucfirst($table).' entry updated.';
            } else {
                // Insert
                if ($table === 'categories') {
                    $db->prepare("INSERT INTO `{$table}` (`key`,`group_key`,`label`,`sort_order`) VALUES (?,?,?,?)")
                        ->execute([$key, $_POST['group_key'], $label, $sort]);
                } elseif ($table === 'areas' && isset($_POST['region_key'])) {
                    $db->prepare("INSERT INTO `{$table}` (`key`,`label`,`region_key`,`sort_order`) VALUES (?,?,?,?)")
                        ->execute([$key, $label, $_POST['region_key'] ?: null, $sort]);
                } else {
                    $db->prepare("INSERT INTO `{$table}` (`key`,`label`,`sort_order`) VALUES (?,?,?)")
                        ->execute([$key, $label, $sort]);
                }
                $msg = ucfirst($table).' entry created.';
            }
        }
        // --- DELETE ---
        elseif ($action === 'delete' && $id) {
            $tables = ['providers'=>'providers','developers'=>'developers','projects'=>'projects','guides'=>'guides'];
            if (isset($tables[$section])) {
                $db->prepare("DELETE FROM `{$tables[$section]}` WHERE id=?")->execute([$id]);
                $msg = ucfirst($section).' entry deleted.';
            }
        }
        elseif ($section === 'lookups' && $action === 'delete_lookup') {
            $table = $_POST['_table'];
            $key = $_POST['_key'];
            $allowed = ['groups','categories','areas','project_types','project_statuses'];
            if (in_array($table, $allowed)) {
                $db->prepare("DELETE FROM `{$table}` WHERE `key`=?")->execute([$key]);
                $msg = 'Entry deleted.';
            }
        }
        // --- CURRENCY RATES: save ---
        elseif ($section === 'lookups' && $action === 'save_rates') {
            $from_list = $_POST['from_currency'];
            $to_list = $_POST['to_currency'];
            $rate_list = $_POST['rate'];
            if (is_array($from_list)) {
                for ($ri = 0; $ri < count($from_list); $ri++) {
                    $f = strtoupper(trim($from_list[$ri]));
                    $t = strtoupper(trim($to_list[$ri]));
                    $rv = (float)$rate_list[$ri];
                    if ($f && $t && $rv > 0) {
                        $db->prepare("INSERT INTO currency_rates (from_currency, to_currency, rate) VALUES (?,?,?) ON DUPLICATE KEY UPDATE rate=?")
                           ->execute(array($f, $t, $rv, $rv));
                    }
                }
            }
            $msg = 'Currency rates updated.';
            // Reload rates
            try { $currency_rates = $db->query("SELECT * FROM currency_rates ORDER BY from_currency, to_currency")->fetchAll(); } catch (Exception $e) {}
        }

        // --- CLAIMS: approve/reject ---
        elseif ($section === 'claims' && $action === 'review_claim') {
            $claim_id = (int)$_POST['claim_id'];
            $decision = $_POST['decision']; // approved or rejected
            $admin_notes = trim($_POST['admin_notes'] ?? '');
            if (!in_array($decision, ['approved','rejected'])) throw new Exception('Invalid decision');
            $db->prepare("UPDATE claim_requests SET status=?, admin_notes=?, reviewed_at=NOW() WHERE id=?")
               ->execute([$decision, $admin_notes ?: null, $claim_id]);
            if ($decision === 'approved') {
                $claim = $db->prepare("SELECT user_id, provider_id FROM claim_requests WHERE id=?")->fetch();
                if (!$claim) { $claim = $db->prepare("SELECT user_id, provider_id FROM claim_requests WHERE id=?"); $claim->execute([$claim_id]); $claim = $claim->fetch(); }
                if ($claim) {
                    $db->prepare("INSERT IGNORE INTO provider_owners (provider_id, user_id) VALUES (?,?)")->execute([$claim['provider_id'], $claim['user_id']]);
                    $db->prepare("UPDATE users SET role='provider_owner' WHERE id=? AND role='user'")->execute([$claim['user_id']]);
                }
            }
            $msg = 'Claim ' . $decision . '.';
            header("Location: console.php?s=claims&msg=" . urlencode($msg)); exit;
        }
        // --- SUBMISSIONS: approve/reject ---
        elseif ($section === 'submissions' && $action === 'review_submission') {
            $sub_id = (int)$_POST['sub_id'];
            $decision = $_POST['decision'];
            $admin_notes = trim($_POST['admin_notes'] ?? '');
            if (!in_array($decision, ['approved','rejected'])) throw new Exception('Invalid decision');
            if ($decision === 'approved') {
                // Create provider from submission
                $sub = $db->prepare("SELECT * FROM listing_submissions WHERE id=?")->fetch();
                if (!$sub) { $sub_q = $db->prepare("SELECT * FROM listing_submissions WHERE id=?"); $sub_q->execute([$sub_id]); $sub = $sub_q->fetch(); }
                if ($sub) {
                    $slug = slugify($sub['business_name']);
                    $cat_keys = explode(',', $sub['category_keys']);
                    $first_cat = trim($cat_keys[0] ?? '');
                    $db->prepare("INSERT INTO providers (slug, name, group_key, category_key, area_key,
                        short_description, description, address, phone, whatsapp_number, website_url,
                        google_maps_url, languages, is_active)
                        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,1)")->execute([
                        $slug, $sub['business_name'], $sub['group_key'], $first_cat, $sub['area_key'],
                        $sub['short_description'], $sub['short_description'],
                        $sub['address'], $sub['phone'], $sub['whatsapp_number'], $sub['website_url'],
                        $sub['google_maps_url'], $sub['languages']
                    ]);
                    $new_prov = (int)$db->lastInsertId();
                    // Insert categories
                    $cat_ins = $db->prepare("INSERT IGNORE INTO provider_categories (provider_id, category_key) VALUES (?,?)");
                    foreach ($cat_keys as $ck) { $ck = trim($ck); if ($ck) $cat_ins->execute([$new_prov, $ck]); }
                    // Grant ownership
                    $db->prepare("INSERT IGNORE INTO provider_owners (provider_id, user_id) VALUES (?,?)")->execute([$new_prov, $sub['user_id']]);
                    $db->prepare("UPDATE users SET role='provider_owner' WHERE id=? AND role='user'")->execute([$sub['user_id']]);
                    $db->prepare("UPDATE listing_submissions SET status='approved', admin_notes=?, reviewed_at=NOW(), created_provider_id=? WHERE id=?")
                       ->execute([$admin_notes ?: null, $new_prov, $sub_id]);
                    $msg = 'Submission approved — provider created.';
                }
            } else {
                $db->prepare("UPDATE listing_submissions SET status='rejected', admin_notes=?, reviewed_at=NOW() WHERE id=?")
                   ->execute([$admin_notes ?: null, $sub_id]);
                $msg = 'Submission rejected.';
            }
            header("Location: console.php?s=submissions&msg=" . urlencode($msg)); exit;
        }
        // --- USERS: toggle active ---
        elseif ($section === 'users' && $action === 'toggle_user') {
            $uid = (int)$_POST['user_id'];
            $db->prepare("UPDATE users SET is_active = NOT is_active WHERE id=?")->execute([$uid]);
            $msg = 'User status updated.';
            $redir = isset($_POST['return_edit']) ? 'console.php?s=users&edit=' . $uid . '&msg=' : 'console.php?s=users&msg=';
            header('Location: ' . $redir . urlencode($msg)); exit;
        }
        // --- USERS: toggle verified ---
        elseif ($section === 'users' && $action === 'toggle_verified') {
            $uid = (int)$_POST['user_id'];
            $db->prepare("UPDATE users SET is_verified = NOT is_verified WHERE id=?")->execute([$uid]);
            $msg = 'User verification status updated.';
            header("Location: console.php?s=users&edit=" . $uid . '&msg=' . urlencode($msg)); exit;
        }
        // --- USERS: change role ---
        elseif ($section === 'users' && $action === 'change_role') {
            $uid = (int)$_POST['user_id'];
            $new_role = $_POST['role'] ?? 'user';
            if (!in_array($new_role, array('user', 'provider_owner', 'admin'))) $new_role = 'user';
            $db->prepare("UPDATE users SET role=? WHERE id=?")->execute(array($new_role, $uid));
            $msg = 'User role updated to ' . $new_role . '.';
            header("Location: console.php?s=users&edit=" . $uid . '&msg=' . urlencode($msg)); exit;
        }
        // --- USERS: change subscription tier ---
        elseif ($section === 'users' && $action === 'change_tier') {
            $uid = (int)$_POST['user_id'];
            $tier = $_POST['subscription_tier'] ?? 'free';
            $period = $_POST['subscription_period'] ?: null;
            $expires = $_POST['subscription_expires_at'] ?: null;
            if (!in_array($tier, array('free', 'basic', 'premium'))) $tier = 'free';
            if ($period && !in_array($period, array('monthly', 'annual', 'lifetime'))) $period = null;
            $started = ($tier !== 'free') ? date('Y-m-d H:i:s') : null;
            $db->prepare("UPDATE users SET subscription_tier=?, subscription_period=?, subscription_started_at=COALESCE(subscription_started_at, ?), subscription_expires_at=?, subscription_auto_renew=0 WHERE id=?")
               ->execute(array($tier, $period, $started, $expires, $uid));
            $msg = 'Subscription updated to ' . $tier . '.';
            header("Location: console.php?s=users&edit=" . $uid . '&msg=' . urlencode($msg)); exit;
        }
        // --- USERS: send password reset email ---
        elseif ($section === 'users' && $action === 'send_reset') {
            require_once($_SERVER['DOCUMENT_ROOT'] . '/api/smtp_mailer.php');
            $uid = (int)$_POST['user_id'];
            $user = $db->prepare("SELECT id, email, display_name FROM users WHERE id=?");
            $user->execute(array($uid));
            $user = $user->fetch();
            if ($user) {
                $token = bin2hex(random_bytes(32));
                $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));
                $db->prepare("UPDATE users SET reset_token=?, reset_expires=? WHERE id=?")->execute(array($token, $expires, $uid));
                $site_url = 'https://biltest.roving-i.com.au';
                $reset_url = $site_url . '/#reset-password?token=' . urlencode($token);
                $html = '<html><body style="font-family:Arial,sans-serif;background:#f7f5f0;margin:0;padding:0;">'
                      . '<table width="100%" cellpadding="0" cellspacing="0" style="background:#f7f5f0;padding:40px 0;">'
                      . '<tr><td align="center">'
                      . '<table width="600" cellpadding="0" cellspacing="0" style="background:#fff;border-radius:8px;overflow:hidden;">'
                      . '<tr><td style="background:#0c7c84;padding:30px 40px;text-align:center;"><h1 style="color:#fff;margin:0;font-size:24px;">Build in Lombok</h1></td></tr>'
                      . '<tr><td style="padding:40px;">'
                      . '<p style="color:#333;font-size:16px;">Hi ' . htmlspecialchars($user['display_name']) . ',</p>'
                      . '<p style="color:#333;font-size:16px;">An administrator has requested a password reset for your account. Click below to set a new password:</p>'
                      . '<p style="text-align:center;margin:30px 0;"><a href="' . $reset_url . '" style="background:#d4604a;color:#fff;text-decoration:none;padding:14px 32px;border-radius:6px;font-size:16px;font-weight:bold;display:inline-block;">Reset Password</a></p>'
                      . '<p style="color:#666;font-size:14px;">This link expires in 1 hour.</p>'
                      . '</td></tr></table></td></tr></table></body></html>';
                $text = 'Hi ' . $user['display_name'] . ", an admin has requested a password reset. Visit: " . $reset_url;
                $result = smtp_send_mail($user['email'], 'Reset your Build in Lombok password', $html, $text);
                $msg = ($result === true) ? 'Password reset email sent to ' . $user['email'] . '.' : 'Email failed: ' . $result;
            } else {
                $msg = 'User not found.';
            }
            header("Location: console.php?s=users&edit=" . $uid . '&msg=' . urlencode($msg)); exit;
        }
        // --- USERS: set password manually ---
        elseif ($section === 'users' && $action === 'set_password') {
            $uid = (int)$_POST['user_id'];
            $new_pass = $_POST['new_password'] ?? '';
            if (strlen($new_pass) < 8) {
                $msg = 'Password must be at least 8 characters.';
            } else {
                $hash = password_hash($new_pass, PASSWORD_DEFAULT);
                $db->prepare("UPDATE users SET password_hash=?, reset_token=NULL, reset_expires=NULL WHERE id=?")->execute(array($hash, $uid));
                $msg = 'Password updated manually.';
            }
            header("Location: console.php?s=users&edit=" . $uid . '&msg=' . urlencode($msg)); exit;
        }
        // --- USERS: update admin notes ---
        elseif ($section === 'users' && $action === 'update_notes') {
            $uid = (int)$_POST['user_id'];
            $notes = trim($_POST['admin_notes'] ?? '');
            $db->prepare("UPDATE users SET admin_notes=? WHERE id=?")->execute(array($notes ?: null, $uid));
            $msg = 'Admin notes saved.';
            header("Location: console.php?s=users&edit=" . $uid . '&msg=' . urlencode($msg)); exit;
        }
        // --- USERS: resend verification email ---
        elseif ($section === 'users' && $action === 'resend_verify') {
            require_once($_SERVER['DOCUMENT_ROOT'] . '/api/smtp_mailer.php');
            $uid = (int)$_POST['user_id'];
            $user = $db->prepare("SELECT id, email, display_name, is_verified FROM users WHERE id=?");
            $user->execute(array($uid));
            $user = $user->fetch();
            if ($user && !$user['is_verified']) {
                $token = bin2hex(random_bytes(32));
                $expires = date('Y-m-d H:i:s', strtotime('+24 hours'));
                $db->prepare("UPDATE users SET verify_token=?, verify_expires=? WHERE id=?")->execute(array($token, $expires, $uid));
                $site_url = 'https://biltest.roving-i.com.au';
                $verify_url = $site_url . '/api/user.php?action=verify&token=' . urlencode($token);
                $html = '<html><body style="font-family:Arial,sans-serif;background:#f7f5f0;margin:0;padding:0;">'
                      . '<table width="100%" cellpadding="0" cellspacing="0" style="background:#f7f5f0;padding:40px 0;">'
                      . '<tr><td align="center">'
                      . '<table width="600" cellpadding="0" cellspacing="0" style="background:#fff;border-radius:8px;overflow:hidden;">'
                      . '<tr><td style="background:#0c7c84;padding:30px 40px;text-align:center;"><h1 style="color:#fff;margin:0;font-size:24px;">Build in Lombok</h1></td></tr>'
                      . '<tr><td style="padding:40px;">'
                      . '<p style="color:#333;font-size:16px;">Hi ' . htmlspecialchars($user['display_name']) . ',</p>'
                      . '<p style="color:#333;font-size:16px;">Please verify your email by clicking below:</p>'
                      . '<p style="text-align:center;margin:30px 0;"><a href="' . $verify_url . '" style="background:#0c7c84;color:#fff;text-decoration:none;padding:14px 32px;border-radius:6px;font-size:16px;font-weight:bold;display:inline-block;">Verify Email</a></p>'
                      . '<p style="color:#666;font-size:14px;">This link expires in 24 hours.</p>'
                      . '</td></tr></table></td></tr></table></body></html>';
                $text = 'Hi ' . $user['display_name'] . ", verify your email: " . $verify_url;
                $result = smtp_send_mail($user['email'], 'Verify your Build in Lombok account', $html, $text);
                $msg = ($result === true) ? 'Verification email sent to ' . $user['email'] . '.' : 'Email failed: ' . $result;
            } else {
                $msg = $user ? 'User is already verified.' : 'User not found.';
            }
            header("Location: console.php?s=users&edit=" . $uid . '&msg=' . urlencode($msg)); exit;
        }
        // --- USERS: delete user ---
        elseif ($section === 'users' && $action === 'delete_user') {
            $uid = (int)$_POST['user_id'];
            $confirm = $_POST['confirm_delete'] ?? '';
            if ($confirm !== 'DELETE') {
                $msg = 'You must type DELETE to confirm.';
            } else {
                // Cascading FKs handle favorites, claims, submissions, provider_owners
                $db->prepare("DELETE FROM users WHERE id=?")->execute(array($uid));
                $msg = 'User deleted permanently.';
            }
            header("Location: console.php?s=users&msg=" . urlencode($msg)); exit;
        }
        // --- AGENTS: toggle verified ---
        elseif ($section === 'agents' && $action === 'toggle_verified') {
            $aid = (int)$_POST['agent_id'];
            $db->prepare("UPDATE agents SET is_verified = NOT is_verified WHERE id=?")->execute([$aid]);
            $msg = 'Agent verification status updated.';
            header("Location: console.php?s=agents&msg=" . urlencode($msg)); exit;
        }
        // --- AGENTS: toggle active ---
        elseif ($section === 'agents' && $action === 'toggle_active') {
            $aid = (int)$_POST['agent_id'];
            $db->prepare("UPDATE agents SET is_active = NOT is_active WHERE id=?")->execute([$aid]);
            $msg = 'Agent active status updated.';
            header("Location: console.php?s=agents&msg=" . urlencode($msg)); exit;
        }
        // --- LISTINGS: approve ---
        elseif ($section === 'listings' && $action === 'approve_listing') {
            $lid = (int)$_POST['listing_id'];
            $db->prepare("UPDATE listings SET is_approved=1, status='active' WHERE id=?")->execute([$lid]);
            $msg = 'Listing approved.';
            header("Location: console.php?s=listings&msg=" . urlencode($msg)); exit;
        }
        // --- LISTINGS: reject ---
        elseif ($section === 'listings' && $action === 'reject_listing') {
            $lid = (int)$_POST['listing_id'];
            $db->prepare("UPDATE listings SET is_approved=0, status='draft' WHERE id=?")->execute([$lid]);
            $msg = 'Listing rejected (set back to draft).';
            header("Location: console.php?s=listings&msg=" . urlencode($msg)); exit;
        }
        // --- LISTINGS: toggle featured ---
        elseif ($section === 'listings' && $action === 'toggle_featured') {
            $lid = (int)$_POST['listing_id'];
            $db->prepare("UPDATE listings SET is_featured = NOT is_featured WHERE id=?")->execute([$lid]);
            $msg = 'Listing featured status updated.';
            header("Location: console.php?s=listings&msg=" . urlencode($msg)); exit;
        }
        // --- LISTINGS: change status ---
        elseif ($section === 'listings' && $action === 'change_status') {
            $lid = (int)$_POST['listing_id'];
            $new_status = $_POST['new_status'];
            $allowed_statuses = ['draft','active','under_offer','sold','expired'];
            if (!in_array($new_status, $allowed_statuses)) throw new Exception('Invalid status');
            $db->prepare("UPDATE listings SET status=? WHERE id=?")->execute([$new_status, $lid]);
            $msg = 'Listing status updated to ' . $new_status . '.';
            header("Location: console.php?s=listings&msg=" . urlencode($msg)); exit;
        }
        // --- LISTINGS: delete ---
        elseif ($section === 'listings' && $action === 'delete_listing') {
            $lid = (int)$_POST['listing_id'];
            $db->prepare("DELETE FROM listings WHERE id=?")->execute([$lid]);
            $msg = 'Listing deleted.';
            header("Location: console.php?s=listings&msg=" . urlencode($msg)); exit;
        }
        // --- LISTINGS: edit ---
        elseif ($section === 'listings' && $action === 'edit_listing') {
            $lid = (int)$_POST['listing_id'];
            $fields = array();
            $vals = array();
            $allowed = array('title','listing_type','area_key','price_usd','price_idr','price_eur','price_aud','land_size_sqm','land_size_are','building_size_sqm','bedrooms','bathrooms','short_description','source_url','contact_whatsapp','agent_id','admin_notes');
            foreach ($allowed as $f) {
                if (isset($_POST[$f])) {
                    $fields[] = "`" . $f . "`=?";
                    $v = trim($_POST[$f]);
                    $vals[] = ($v === '') ? null : $v;
                }
            }
            // Server-side currency conversion
            convert_listing_prices($db, $fields, $vals);
            if (count($fields) > 0) {
                $vals[] = $lid;
                $db->prepare("UPDATE listings SET " . implode(',', $fields) . " WHERE id=?")->execute($vals);
            }
            $msg = 'Listing updated.';
            header("Location: console.php?s=listings&msg=" . urlencode($msg)); exit;
        }
        // --- SUBSCRIPTIONS: update user tier ---
        elseif ($section === 'subscriptions' && $action === 'update_tier') {
            $uid = (int)$_POST['user_id'];
            $tier = $_POST['subscription_tier'];
            $period = $_POST['subscription_period'] ?: null;
            $expires = $_POST['subscription_expires_at'] ?: null;
            $auto_renew = isset($_POST['subscription_auto_renew']) ? 1 : 0;
            if (!in_array($tier, ['free','basic','premium'])) $tier = 'free';
            if ($period && !in_array($period, ['monthly','annual','lifetime'])) $period = null;
            $started = ($tier !== 'free') ? date('Y-m-d H:i:s') : null;
            $db->prepare("UPDATE users SET subscription_tier=?, subscription_period=?, subscription_started_at=COALESCE(subscription_started_at, ?), subscription_expires_at=?, subscription_auto_renew=? WHERE id=?")
               ->execute([$tier, $period, $started, $expires, $auto_renew, $uid]);
            $msg = 'User subscription updated.';
            header("Location: console.php?s=subscriptions&msg=" . urlencode($msg)); exit;
        }
        // --- FEATURE ACCESS: update ---
        elseif ($section === 'feature_access' && $action === 'update_feature') {
            $fid = (int)$_POST['feature_id'];
            $tier_free = isset($_POST['tier_free']) ? 1 : 0;
            $tier_basic = isset($_POST['tier_basic']) ? 1 : 0;
            $tier_premium = isset($_POST['tier_premium']) ? 1 : 0;
            $require_login = isset($_POST['require_login']) ? 1 : 0;
            $is_active = isset($_POST['is_active']) ? 1 : 0;
            $db->prepare("UPDATE feature_access SET tier_free=?, tier_basic=?, tier_premium=?, require_login=?, is_active=? WHERE id=?")
               ->execute([$tier_free, $tier_basic, $tier_premium, $require_login, $is_active, $fid]);
            $msg = 'Feature access updated.';
            header("Location: console.php?s=feature_access&msg=" . urlencode($msg)); exit;
        }
        // --- FEATURE ACCESS: add new feature ---
        elseif ($section === 'feature_access' && $action === 'add_feature') {
            $key = trim($_POST['feature_key']);
            $label = trim($_POST['feature_label']);
            $desc = trim($_POST['description'] ?? '');
            $tier_free = isset($_POST['tier_free']) ? 1 : 0;
            $tier_basic = isset($_POST['tier_basic']) ? 1 : 0;
            $tier_premium = isset($_POST['tier_premium']) ? 1 : 0;
            $require_login = isset($_POST['require_login']) ? 1 : 0;
            if (!$key || !$label) { $msg = 'Error: Key and label required.'; }
            else {
                $db->prepare("INSERT INTO feature_access (feature_key, feature_label, description, tier_free, tier_basic, tier_premium, require_login, sort_order) VALUES (?,?,?,?,?,?,?, (SELECT COALESCE(MAX(s.sort_order),0)+1 FROM (SELECT sort_order FROM feature_access) s))")
                   ->execute([$key, $label, $desc, $tier_free, $tier_basic, $tier_premium, $require_login]);
                $msg = 'Feature added.';
                header("Location: console.php?s=feature_access&msg=" . urlencode($msg)); exit;
            }
        }
        // --- FEATURE ACCESS: delete ---
        elseif ($section === 'feature_access' && $action === 'delete_feature') {
            $fid = (int)$_POST['feature_id'];
            $db->prepare("DELETE FROM feature_access WHERE id=?")->execute([$fid]);
            $msg = 'Feature removed.';
            header("Location: console.php?s=feature_access&msg=" . urlencode($msg)); exit;
        }
    } catch (Exception $e) {
        $msg = 'Error: ' . $e->getMessage();
    }
    // PRG redirect after non-delete POST
    if ($action === 'save' && $msg && strpos($msg, 'Error') === false) {
        $redir = "console.php?s={$section}&a=list&msg=" . urlencode($msg);
        if ($section === 'lookups') {
            $anchor = isset($_POST['_table']) ? '#lookup-' . $_POST['_table'] : '';
            $redir = "console.php?s=lookups&msg=" . urlencode($msg) . $anchor;
        } elseif ($id) {
            $redir = "console.php?s={$section}&a=edit&id={$id}&msg=" . urlencode($msg);
        }
        header("Location: {$redir}");
        exit;
    }
    // PRG redirect after lookup delete
    if ($action === 'delete_lookup' && $msg && strpos($msg, 'Error') === false) {
        $anchor = isset($_POST['_table']) ? '#lookup-' . $_POST['_table'] : '';
        header("Location: console.php?s=lookups&msg=" . urlencode($msg) . $anchor);
        exit;
    }
}

if (!empty($_GET['msg'])) $msg = $_GET['msg'];

// ─── LOAD LOOKUPS ────────────────────────────────────────────────────
$db = get_db();
$groups_list = $db->query("SELECT * FROM `groups` ORDER BY sort_order")->fetchAll();
$cats_list = $db->query("SELECT * FROM categories ORDER BY group_key, sort_order")->fetchAll();
$areas_list = $db->query("SELECT * FROM areas ORDER BY sort_order")->fetchAll();
try { $regions_list = $db->query("SELECT * FROM area_regions ORDER BY sort_order")->fetchAll(); } catch (Exception $e) { $regions_list = []; }
try { $currency_rates = $db->query("SELECT * FROM currency_rates ORDER BY from_currency, to_currency")->fetchAll(); } catch (Exception $e) { $currency_rates = []; }
$ptypes_list = $db->query("SELECT * FROM project_types ORDER BY sort_order")->fetchAll();
$pstatus_list = $db->query("SELECT * FROM project_statuses ORDER BY sort_order")->fetchAll();
// Developer sidebar: only show categories that at least one developer uses
try {
    $dev_cats_sidebar = $db->query("SELECT DISTINCT c.`key`, c.`label` FROM developer_categories dc JOIN categories c ON c.`key`=dc.category_key ORDER BY c.sort_order")->fetchAll();
} catch (Exception $e) { $dev_cats_sidebar = []; }
// Agent categories for sidebar
try {
    $agent_cats_sidebar = $db->query("SELECT * FROM agent_categories ORDER BY sort_order")->fetchAll();
} catch (Exception $e) {
    $agent_cats_sidebar = [
        ['key' => 'property_sales', 'label' => 'Property Sales'],
        ['key' => 'property_management', 'label' => 'Property Management'],
        ['key' => 'buyers_agent', 'label' => "Buyer's Agent"],
    ];
}

// Count stats
$prov_count = $db->query("SELECT COUNT(*) FROM providers")->fetchColumn();
$dev_count = $db->query("SELECT COUNT(*) FROM developers")->fetchColumn();
$proj_count = $db->query("SELECT COUNT(*) FROM projects")->fetchColumn();
$guide_count = $db->query("SELECT COUNT(*) FROM guides")->fetchColumn();
$user_count = 0; $claim_count = 0; $sub_count = 0;
$agent_count = 0; $listing_count = 0; $listing_pending_count = 0;
try {
    $user_count = $db->query("SELECT COUNT(*) FROM users")->fetchColumn();
    $claim_count = $db->query("SELECT COUNT(*) FROM claim_requests WHERE status='pending'")->fetchColumn();
    $sub_count = $db->query("SELECT COUNT(*) FROM listing_submissions WHERE status='pending'")->fetchColumn();
} catch (Exception $e) { /* tables may not exist yet */ }
try {
    $agent_count = $db->query("SELECT COUNT(*) FROM agents")->fetchColumn();
    $listing_count = $db->query("SELECT COUNT(*) FROM listings")->fetchColumn();
    $listing_pending_count = $db->query("SELECT COUNT(*) FROM listings WHERE is_approved=0 AND status != 'draft'")->fetchColumn();
} catch (Exception $e) { /* tables may not exist yet */ }

?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title>Admin Console — Build in Lombok</title>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',system-ui,sans-serif;background:#f0f2f5;color:#1a1a1a;line-height:1.5;font-size:14px}
a{color:#0c7c84;text-decoration:none}a:hover{text-decoration:underline}

/* Layout */
.shell{display:flex;min-height:100vh}
.sidebar{width:220px;background:#1e293b;color:#94a3b8;flex-shrink:0;padding:16px 0;position:sticky;top:0;height:100vh;overflow-y:auto}
.sidebar h2{color:#fff;font-size:13px;padding:0 16px;margin:16px 0 6px;text-transform:uppercase;letter-spacing:.05em}
.sidebar a{display:block;padding:7px 16px;color:#94a3b8;font-size:13px;border-left:3px solid transparent}
.sidebar a:hover,.sidebar a.active{color:#fff;background:rgba(255,255,255,.06);text-decoration:none;border-left-color:#0c7c84}
.sidebar .brand{color:#fff;font-weight:700;font-size:15px;padding:0 16px 16px;border-bottom:1px solid rgba(255,255,255,.08)}
.main{flex:1;padding:24px;max-width:1100px}

/* Components */
h1{font-size:1.4rem;margin-bottom:16px}
.card{background:#fff;border-radius:8px;padding:20px;margin-bottom:16px;box-shadow:0 1px 3px rgba(0,0,0,.06)}
.lookup-section{scroll-margin-top:20px}
.msg{padding:10px 14px;border-radius:6px;margin-bottom:16px;font-size:13px}
.msg-ok{background:#dcfce7;color:#16a34a;border:1px solid #bbf7d0}
.msg-err{background:#fee2e2;color:#dc2626;border:1px solid #fecaca}

.btn{padding:6px 14px;border:none;border-radius:5px;font-size:13px;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:4px;text-decoration:none}
.btn-p{background:#0c7c84;color:#fff}.btn-p:hover{background:#0a6a70}
.btn-g{background:#16a34a;color:#fff}.btn-g:hover{background:#138a3e}
.btn-r{background:#dc2626;color:#fff;font-size:12px}.btn-r:hover{background:#b91c1c}
.btn-o{background:transparent;border:1px solid #d0d0d0;color:#333}.btn-o:hover{background:#f0f0f0}
.btn-sm{padding:4px 10px;font-size:12px}

table{width:100%;border-collapse:collapse;font-size:13px}
th,td{padding:7px 10px;text-align:left;border-bottom:1px solid #eee}
th{font-size:11px;text-transform:uppercase;letter-spacing:.03em;color:#666;background:#fafafa}
tr:hover{background:#f9fafb}

.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}
.form-grid.full{grid-template-columns:1fr}
.fg{display:flex;flex-direction:column;gap:3px}
.fg.span2{grid-column:span 2}
.fg label{font-size:12px;font-weight:600;color:#555;text-transform:uppercase;letter-spacing:.03em}
input[type=text],input[type=number],input[type=url],select,textarea{padding:7px 10px;border:1px solid #d0d0d0;border-radius:5px;font-size:13px;font-family:inherit;width:100%}
textarea{min-height:80px;resize:vertical}
.ck{display:flex;align-items:center;gap:6px;margin-top:4px}
.ck input{width:16px;height:16px}

.stats{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:20px}
.stat{background:#fff;border-radius:8px;padding:16px;text-align:center;box-shadow:0 1px 3px rgba(0,0,0,.06)}
.stat-n{font-size:1.8rem;font-weight:700;color:#0c7c84}
.stat-l{font-size:11px;text-transform:uppercase;color:#666}

.search-bar{margin-bottom:14px;display:flex;gap:8px;flex-wrap:wrap}
.search-bar input[type="text"]{flex:1;min-width:140px}
.search-bar select{min-width:130px;max-width:200px}
.badge{display:inline-block;padding:1px 7px;border-radius:10px;font-size:11px;font-weight:600}
.b-green{background:#dcfce7;color:#16a34a}.b-red{background:#fee2e2;color:#dc2626}.b-blue{background:#dbeafe;color:#1d4ed8}.b-yellow{background:#fef9c3;color:#a16207}
.actions{display:flex;gap:6px;flex-wrap:nowrap}

.lookup-section{margin-bottom:24px}
.lookup-section h3{font-size:14px;margin-bottom:8px;padding-bottom:4px;border-bottom:2px solid #0c7c84}
.sidebar-group{position:relative}
.sidebar-sub{display:none;background:rgba(0,0,0,.15)}
.sidebar-sub a{opacity:.8}
.sidebar-parent{display:flex!important;justify-content:space-between;align-items:center}
.sb-arrow{font-size:10px;transition:transform .2s}
.sidebar-group.open .sb-arrow{transform:rotate(90deg)}
th a:hover{text-decoration:underline!important}
</style>
<script>
function toggleSidebarSub(e,id){
    var sub=document.getElementById(id);
    var grp=sub.parentElement;
    if(sub.style.display==='block'){
        // Already open — navigate to the section
        return true;
    }
    e.preventDefault();
    sub.style.display='block';
    grp.classList.add('open');
}
</script>
</head>
<body>
<div class="shell">

<!-- SIDEBAR -->
<div class="sidebar">
    <div class="brand">Build in Lombok</div>
    <a href="?s=dashboard" class="<?= $section==='dashboard'?'active':'' ?>">Dashboard</a>
    <h2>Content</h2>
    <div class="sidebar-group">
        <a href="?s=providers" class="sidebar-parent <?= $section==='providers'?'active':'' ?>" onclick="toggleSidebarSub(event,'sb-providers')">Providers (<?= $prov_count ?>) <span class="sb-arrow">▸</span></a>
        <div class="sidebar-sub" id="sb-providers" <?= $section==='providers'?'style="display:block"':'' ?>>
            <?php foreach ($groups_list as $g): ?>
                <a href="?s=providers&fg=<?= $g['key'] ?>" style="padding-left:32px;font-size:12px;"><?= htmlspecialchars($g['label']) ?></a>
            <?php endforeach; ?>
        </div>
    </div>
    <div class="sidebar-group">
        <a href="?s=developers" class="sidebar-parent <?= $section==='developers'?'active':'' ?>" onclick="toggleSidebarSub(event,'sb-developers')">Developers (<?= $dev_count ?>) <span class="sb-arrow">▸</span></a>
        <div class="sidebar-sub" id="sb-developers" <?= $section==='developers'?'style="display:block"':'' ?>>
            <?php foreach ($dev_cats_sidebar as $dc): ?>
                <a href="?s=developers&fc=<?= $dc['key'] ?>" style="padding-left:32px;font-size:12px;"><?= htmlspecialchars($dc['label']) ?></a>
            <?php endforeach; ?>
        </div>
    </div>
    <div class="sidebar-group">
        <a href="?s=agents" class="sidebar-parent <?= $section==='agents'?'active':'' ?>" onclick="toggleSidebarSub(event,'sb-agents')">Agents (<?= $agent_count ?>) <span class="sb-arrow">▸</span></a>
        <div class="sidebar-sub" id="sb-agents" <?= $section==='agents'?'style="display:block"':'' ?>>
            <?php foreach ($agent_cats_sidebar as $ac): ?>
                <a href="?s=agents&fcat=<?= $ac['key'] ?>" style="padding-left:32px;font-size:12px;"><?= htmlspecialchars($ac['label']) ?></a>
            <?php endforeach; ?>
        </div>
    </div>
    <a href="?s=projects" class="<?= $section==='projects'?'active':'' ?>">Projects (<?= $proj_count ?>)</a>
    <a href="?s=guides" class="<?= $section==='guides'?'active':'' ?>">Guides (<?= $guide_count ?>)</a>
    <h2>Property Listings</h2>
    <a href="?s=listings" class="<?= $section==='listings'?'active':'' ?>">All Listings (<?= $listing_count ?>)<?php if($listing_pending_count):?> <span style="background:#f59e0b;color:#fff;border-radius:10px;padding:0 6px;font-size:11px;margin-left:4px"><?=$listing_pending_count?></span><?php endif;?></a>
    <h2>User Management</h2>
    <a href="?s=users" class="<?= $section==='users'?'active':'' ?>">Users (<?= $user_count ?>)</a>
    <a href="?s=claims" class="<?= $section==='claims'?'active':'' ?>">Claims<?php if($claim_count):?> <span style="background:#f59e0b;color:#fff;border-radius:10px;padding:0 6px;font-size:11px;margin-left:4px"><?=$claim_count?></span><?php endif;?></a>
    <a href="?s=submissions" class="<?= $section==='submissions'?'active':'' ?>">Submissions<?php if($sub_count):?> <span style="background:#f59e0b;color:#fff;border-radius:10px;padding:0 6px;font-size:11px;margin-left:4px"><?=$sub_count?></span><?php endif;?></a>
    <h2>Configuration</h2>
    <div class="sidebar-group">
        <a href="?s=lookups" class="sidebar-parent <?= $section==='lookups'?'active':'' ?>" onclick="toggleSidebarSub(event,'sb-lookups')">Categories & Lookups <span class="sb-arrow">▸</span></a>
        <div class="sidebar-sub" id="sb-lookups" <?= $section==='lookups'?'style="display:block"':'' ?>>
            <a href="?s=lookups#lookup-groups" style="padding-left:32px;font-size:12px;">Provider Groups</a>
            <a href="?s=lookups#lookup-categories" style="padding-left:32px;font-size:12px;">Provider Categories</a>
            <a href="?s=lookups#lookup-areas" style="padding-left:32px;font-size:12px;">Areas / Locations</a>
            <a href="?s=lookups#lookup-project_types" style="padding-left:32px;font-size:12px;">Project Types</a>
            <a href="?s=lookups#lookup-project_statuses" style="padding-left:32px;font-size:12px;">Project Statuses</a>
            <a href="?s=lookups#lookup-currency_rates" style="padding-left:32px;font-size:12px;">Currency Rates</a>
        </div>
    </div>
    <h2>RAB Module</h2>
    <a href="rab_tool.php">RAB Projects & Calculator</a>
    <a href="rab.php">RAB Admin (Materials etc.)</a>
    <h2>Subscriptions</h2>
    <a href="?s=subscriptions" class="<?= $section==='subscriptions'?'active':'' ?>">User Subscriptions</a>
    <a href="?s=feature_access" class="<?= $section==='feature_access'?'active':'' ?>">Feature Access</a>
    <h2>Tools</h2>
    <a href="import.php">Google Maps Importer</a>
    <a href="scrape_listings.php">Property Listing Scraper</a>
    <a href="?s=batch_enrich" class="<?= $section==='batch_enrich'?'active':'' ?>">Batch Enrich</a>
    <a href="?s=review_updates" class="<?= $section==='review_updates'?'active':'' ?>">Review Update Log</a>
    <a href="https://biltest.roving-i.com.au" target="_blank" rel="noopener" style="opacity:.7;">🌐 View Live Site</a>
    <a href="?logout=1" style="margin-top:auto;opacity:.6">Logout</a>
</div>

<!-- MAIN -->
<div class="main">

<?php if ($msg): ?>
    <div class="msg <?= strpos($msg,'Error')!==false?'msg-err':'msg-ok' ?>"><?= htmlspecialchars($msg) ?></div>
<?php endif; ?>

<?php
// ═══════════════════════════════════════════════════════════════
// DASHBOARD
// ═══════════════════════════════════════════════════════════════
if ($section === 'dashboard'):
?>
<h1>Dashboard</h1>
<div class="stats">
    <div class="stat"><div class="stat-n"><?= $prov_count ?></div><div class="stat-l">Providers</div></div>
    <div class="stat"><div class="stat-n"><?= $dev_count ?></div><div class="stat-l">Developers</div></div>
    <div class="stat"><div class="stat-n"><?= $proj_count ?></div><div class="stat-l">Projects</div></div>
    <div class="stat"><div class="stat-n"><?= $guide_count ?></div><div class="stat-l">Guides</div></div>
</div>
<div class="card">
    <h3 style="margin-bottom:10px;">Recent Providers</h3>
    <table>
        <tr><th>Name</th><th>Categories</th><th>Rating</th><th>Updated</th></tr>
        <?php foreach ($db->query("SELECT p.* FROM providers p ORDER BY p.updated_at DESC LIMIT 10")->fetchAll() as $r):
            $p_cats = $db->prepare("SELECT c.label FROM provider_categories pc JOIN categories c ON c.`key`=pc.category_key WHERE pc.provider_id=? ORDER BY c.sort_order");
            $p_cats->execute([$r['id']]);
            $cat_labels = $p_cats->fetchAll(PDO::FETCH_COLUMN);
        ?>
        <tr>
            <td><a href="?s=providers&a=edit&id=<?= $r['id'] ?>"><?= htmlspecialchars($r['name']) ?></a></td>
            <td><?= htmlspecialchars(implode(', ', $cat_labels) ?: '-') ?></td>
            <td>★<?= $r['google_rating'] ?> (<?= $r['google_review_count'] ?>)</td>
            <td style="color:#888;font-size:12px"><?= $r['updated_at'] ?></td>
        </tr>
        <?php endforeach; ?>
    </table>
</div>

<?php
// ═══════════════════════════════════════════════════════════════
// PROVIDERS LIST
// ═══════════════════════════════════════════════════════════════
elseif ($section === 'providers' && $action === 'list'):
    $q = $_GET['q'] ?? '';
    $f_group = $_GET['fg'] ?? '';
    $f_cat = $_GET['fc'] ?? '';
    $f_area = $_GET['fa'] ?? '';
    $f_active = $_GET['fv'] ?? '';
    $f_min_reviews = $_GET['fr'] ?? '';
    $sort = $_GET['sort'] ?? 'reviews';
    $where = '1=1'; $params = [];
    if ($q) { $where .= " AND (p.name LIKE ? OR p.short_description LIKE ?)"; $params[] = "%{$q}%"; $params[] = "%{$q}%"; }
    if ($f_group) { $where .= " AND p.group_key=?"; $params[] = $f_group; }
    if ($f_cat) { $where .= " AND EXISTS(SELECT 1 FROM provider_categories pc2 WHERE pc2.provider_id=p.id AND pc2.category_key=?)"; $params[] = $f_cat; }
    if ($f_area) { $where .= " AND p.area_key=?"; $params[] = $f_area; }
    if ($f_active === '1') { $where .= " AND p.is_active=1"; }
    elseif ($f_active === '0') { $where .= " AND p.is_active=0"; }
    if ($f_min_reviews !== '') { $where .= " AND p.google_review_count >= ?"; $params[] = (int)$f_min_reviews; }
    if ($sort === 'name') { $order = 'p.name ASC'; }
    elseif ($sort === 'rating') { $order = 'p.google_rating DESC, p.google_review_count DESC'; }
    else { $order = 'p.google_review_count DESC, p.google_rating DESC'; }
    $stmt = $db->prepare("SELECT p.*, g.label AS grp_label, a.label AS area_label
        FROM providers p LEFT JOIN `groups` g ON g.`key`=p.group_key LEFT JOIN areas a ON a.`key`=p.area_key
        WHERE {$where} ORDER BY {$order} LIMIT 200");
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    // Fetch categories for all listed providers
    $prov_ids = array_column($rows, 'id');
    $prov_cat_map = [];
    if ($prov_ids) {
        $ph = implode(',', array_fill(0, count($prov_ids), '?'));
        $pc_stmt = $db->prepare("SELECT pc.provider_id, c.label FROM provider_categories pc JOIN categories c ON c.`key`=pc.category_key WHERE pc.provider_id IN ({$ph}) ORDER BY c.sort_order");
        $pc_stmt->execute($prov_ids);
        foreach ($pc_stmt->fetchAll() as $pcr) $prov_cat_map[$pcr['provider_id']][] = $pcr['label'];
    }
?>
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
    <h1 style="margin:0">Providers (<?= count($rows) ?>)</h1>
    <a href="?s=providers&a=edit" class="btn btn-p">+ Add Provider</a>
</div>
<form class="search-bar" method="GET">
    <input type="hidden" name="s" value="providers">
    <input type="text" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Search by name..." style="min-width:160px;">
    <select name="fg" id="pf-group" onchange="filterCatDropdown()">
        <option value="">All Groups</option>
        <?php foreach ($groups_list as $g): ?><option value="<?= $g['key'] ?>" <?= $f_group===$g['key']?'selected':'' ?>><?= htmlspecialchars($g['label']) ?></option><?php endforeach; ?>
    </select>
    <select name="fc" id="pf-cat">
        <option value="">All Categories</option>
        <?php foreach ($cats_list as $c): ?><option value="<?= $c['key'] ?>" data-group="<?= $c['group_key'] ?>" <?= $f_cat===$c['key']?'selected':'' ?>><?= htmlspecialchars($c['label']) ?></option><?php endforeach; ?>
    </select>
    <select name="fa">
        <option value="">All Areas</option>
        <?php foreach ($areas_list as $a): ?><option value="<?= $a['key'] ?>" <?= $f_area===$a['key']?'selected':'' ?>><?= htmlspecialchars($a['label']) ?></option><?php endforeach; ?>
    </select>
    <select name="fv">
        <option value="">Any Status</option>
        <option value="1" <?= $f_active==='1'?'selected':'' ?>>Active</option>
        <option value="0" <?= $f_active==='0'?'selected':'' ?>>Inactive</option>
    </select>
    <input type="number" name="fr" value="<?= htmlspecialchars($f_min_reviews) ?>" placeholder="Min reviews" style="width:100px;" min="0">
    <input type="hidden" name="sort" value="<?= htmlspecialchars($sort) ?>">
    <button class="btn btn-o">Filter</button>
    <?php if ($q || $f_group || $f_cat || $f_area || $f_active !== '' || $f_min_reviews !== ''): ?><a href="?s=providers" class="btn btn-o">Clear</a><?php endif; ?>
</form>
<script>
function filterCatDropdown() {
    var g = document.getElementById('pf-group').value;
    var sel = document.getElementById('pf-cat');
    for (var i = 0; i < sel.options.length; i++) {
        var opt = sel.options[i];
        if (!opt.value) continue;
        opt.style.display = (!g || opt.getAttribute('data-group') === g) ? '' : 'none';
        if (opt.selected && opt.style.display === 'none') opt.selected = false;
    }
}
filterCatDropdown();
</script>
<?php
    // Build sort URL helper — preserves current filters
    $p_sort_params = http_build_query(array_filter(['s'=>'providers','q'=>$q,'fg'=>$f_group,'fc'=>$f_cat,'fa'=>$f_area,'fv'=>$f_active,'fr'=>$f_min_reviews], function($v){ return $v!==''; }));
    $p_sort_name_url = $p_sort_params . '&sort=name';
    $p_sort_reviews_url = $p_sort_params . '&sort=reviews';
    $p_sort_rating_url = $p_sort_params . '&sort=rating';
?>
<div class="card" style="padding:0;overflow-x:auto">
<table>
    <tr>
        <th style="width:40px"></th>
        <th><a href="?<?= $p_sort_name_url ?>" style="color:inherit;text-decoration:none">Name <?= $sort==='name'?'&#9650;':'' ?></a></th>
        <th>Group</th><th>Categories</th><th>Area</th>
        <th><a href="?<?= $p_sort_reviews_url ?>" style="color:inherit;text-decoration:none">Reviews <?= $sort==='reviews'?'&#9660;':'' ?></a> / <a href="?<?= $p_sort_rating_url ?>" style="color:inherit;text-decoration:none">Rating <?= $sort==='rating'?'&#9660;':'' ?></a></th>
        <th>Website</th>
        <th>Active</th><th>Actions</th>
    </tr>
    <?php foreach ($rows as $r): ?>
    <tr id="entity-row-providers-<?= $r['id'] ?>">
        <td>
            <?php if (!empty($r['profile_photo_url'])): ?>
            <img src="<?= htmlspecialchars($r['profile_photo_url']) ?>" alt="" style="width:36px;height:36px;border-radius:4px;object-fit:cover;display:block">
            <?php else: ?>
            <div style="width:36px;height:36px;background:#e5e7eb;border-radius:4px;display:flex;align-items:center;justify-content:center;font-size:9px;color:#9ca3af">No img</div>
            <?php endif; ?>
        </td>
        <td><a href="?s=providers&a=edit&id=<?= $r['id'] ?>"><?= htmlspecialchars($r['name']) ?></a></td>
        <td><span class="badge b-blue"><?= htmlspecialchars($r['grp_label'] ?? '-') ?></span></td>
        <td><?= htmlspecialchars(implode(', ', $prov_cat_map[$r['id']] ?? []) ?: '-') ?></td>
        <td><?= htmlspecialchars($r['area_label'] ?? '-') ?></td>
        <td>&#9733;<?= $r['google_rating'] ?: '-' ?> (<?= $r['google_review_count'] ?>)</td>
        <td style="max-width:120px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?php if (!empty($r['website_url'])): ?><a href="<?= htmlspecialchars($r['website_url']) ?>" target="_blank" rel="noopener" style="font-size:12px"><?= htmlspecialchars(parse_url($r['website_url'], PHP_URL_HOST) ?: $r['website_url']) ?></a><?php else: ?><span style="color:#ccc">-</span><?php endif; ?></td>
        <td><?= $r['is_active'] ? '<span class="badge b-green">Yes</span>' : '<span class="badge b-red">No</span>' ?></td>
        <td class="actions">
            <a href="?s=providers&a=edit&id=<?= $r['id'] ?>" class="btn btn-o btn-sm">Edit</a>
            <button type="button" class="btn btn-r btn-sm" onclick="ajaxEntityDelete('providers',<?= $r['id'] ?>,'provider')">Del</button>
        </td>
    </tr>
    <?php endforeach; ?>
</table>
</div>

<?php
// ═══════════════════════════════════════════════════════════════
// PROVIDER EDIT/CREATE
// ═══════════════════════════════════════════════════════════════
elseif ($section === 'providers' && ($action === 'edit' || $action === 'save')):
    $item = $id ? $db->query("SELECT * FROM providers WHERE id={$id}")->fetch() : [];
    $tags = $id ? $db->query("SELECT tag FROM provider_tags WHERE provider_id={$id}")->fetchAll(PDO::FETCH_COLUMN) : [];
    $prov_cats = $id ? $db->query("SELECT category_key FROM provider_categories WHERE provider_id={$id}")->fetchAll(PDO::FETCH_COLUMN) : [];
    $v = function($k, $d='') use ($item) { return htmlspecialchars($item[$k] ?? $d); };
?>
<h1><a href="?s=providers">&larr; Providers</a> / <?= $id ? 'Edit: '.htmlspecialchars($item['name']) : 'New Provider' ?></h1>
<form method="POST" action="?s=providers&a=save<?= $id?"&id={$id}":'' ?>" class="card">
    <div class="form-grid">
        <div class="fg span2"><label>Name</label><input type="text" name="name" value="<?= $v('name') ?>" required></div>
        <div class="fg"><label>Group</label>
            <select name="group_key" required>
                <?php foreach ($groups_list as $g): ?><option value="<?= $g['key'] ?>" <?= ($item['group_key']??'')===$g['key']?'selected':'' ?>><?= htmlspecialchars($g['label']) ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="fg"><label>Categories (select multiple)</label>
            <select name="categories[]" multiple style="height:120px">
                <?php foreach ($cats_list as $c): ?><option value="<?= $c['key'] ?>" <?= in_array($c['key'], $prov_cats)?'selected':'' ?>><?= htmlspecialchars($c['label']) ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="fg"><label>Area</label>
            <select name="area_key" required>
                <?php foreach ($areas_list as $a): ?><option value="<?= $a['key'] ?>" <?= ($item['area_key']??'')===$a['key']?'selected':'' ?>><?= htmlspecialchars($a['label']) ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="fg"><label>Languages</label>
            <div style="display:flex;gap:12px;align-items:center;padding:6px 0">
                <?php $langs = array_map('trim', explode(',', $v('languages',''))); ?>
                <label style="font-weight:400;display:flex;align-items:center;gap:4px;cursor:pointer"><input type="checkbox" name="languages[]" value="Bahasa" <?= in_array('Bahasa', $langs) || in_array('Bahasa only', $langs) ? 'checked' : '' ?>> Bahasa</label>
                <label style="font-weight:400;display:flex;align-items:center;gap:4px;cursor:pointer"><input type="checkbox" name="languages[]" value="English" <?= in_array('English', $langs) || strpos($v('languages',''), 'English') !== false ? 'checked' : '' ?>> English</label>
            </div>
        </div>
        <div class="fg span2"><label>Short Description</label><input type="text" name="short_description" value="<?= $v('short_description') ?>"></div>
        <div class="fg span2"><label>Full Description</label><textarea name="description" rows="4"><?= $v('description') ?></textarea></div>
        <div class="fg"><label>Address</label><input type="text" name="address" value="<?= $v('address') ?>"></div>
        <div class="fg"><label>Google Maps URL</label><input type="url" name="google_maps_url" value="<?= $v('google_maps_url') ?>"></div>
        <div class="fg"><label>Latitude</label><input type="text" name="latitude" value="<?= $v('latitude') ?>"></div>
        <div class="fg"><label>Longitude</label><input type="text" name="longitude" value="<?= $v('longitude') ?>"></div>
        <div class="fg"><label>Google Rating</label><input type="number" name="google_rating" value="<?= $v('google_rating') ?>" step="0.1" min="1" max="5"></div>
        <div class="fg"><label>Review Count</label><input type="number" name="google_review_count" value="<?= $v('google_review_count','0') ?>"></div>
        <div class="fg"><label>Phone</label><input type="text" name="phone" value="<?= $v('phone') ?>"></div>
        <div class="fg"><label>WhatsApp</label><input type="text" name="whatsapp_number" value="<?= $v('whatsapp_number') ?>"></div>
        <div class="fg"><label>Website</label>
            <div style="display:flex;gap:8px;align-items:center">
                <input type="url" name="website_url" id="prov_website_url" value="<?= $v('website_url') ?>" style="flex:1">
                <?php if ($id): ?>
                <?php if (!empty($item['website_url'])): ?>
                <button type="button" class="btn btn-o btn-sm" onclick="scanWebsite('providers')" id="scan-btn" title="Scan website for missing info">&#x1F50D; Scan</button>
                <label style="font-size:11px;display:flex;align-items:center;gap:3px;white-space:nowrap;cursor:pointer" title="When checked, re-scan and overwrite existing field values">
                    <input type="checkbox" id="scan-update-prov" style="margin:0"> Update existing
                </label>
                <?php else: ?>
                <button type="button" class="btn btn-o btn-sm" onclick="window.open('https://www.google.com/search?q='+encodeURIComponent(document.querySelector('[name=name]').value+' Lombok'),'_blank')" title="Search Google for this entity">&#x1F50D; Search</button>
                <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
        <div class="fg"><label>Badge</label><input type="text" name="badge" value="<?= $v('badge') ?>" placeholder="e.g. Verified, Top Rated"></div>
        <div class="fg span2"><label>Profile Photo URL</label>
            <div style="display:flex;gap:8px;align-items:center">
                <input type="url" name="profile_photo_url" value="<?= $v('profile_photo_url') ?>" placeholder="https://..." style="flex:1">
                <?php if (!empty($item['profile_photo_url'])): ?><img src="<?= $v('profile_photo_url') ?>" style="width:36px;height:36px;border-radius:4px;object-fit:cover"><?php endif; ?>
            </div>
        </div>
        <div class="fg span2"><label>Logo URL</label>
            <div style="display:flex;gap:8px;align-items:center">
                <input type="url" name="logo_url" value="<?= $v('logo_url') ?>" placeholder="https://... (company logo)" style="flex:1">
                <?php if (!empty($item['logo_url'])): ?><img src="<?= $v('logo_url') ?>" style="height:36px;width:auto;border-radius:4px;object-fit:contain;background:#f5f5f5;padding:2px"><?php endif; ?>
            </div>
        </div>
        <div class="fg span2"><label>Hero Image URL</label>
            <div style="display:flex;gap:8px;align-items:center">
                <input type="url" name="hero_image_url" value="<?= $v('hero_image_url') ?>" placeholder="https://... (banner / hero background)" style="flex:1">
                <?php if (!empty($item['hero_image_url'])): ?><img src="<?= $v('hero_image_url') ?>" style="height:36px;width:auto;border-radius:4px;object-fit:cover"><?php endif; ?>
            </div>
        </div>
        <div class="fg span2"><label>Image 2</label><input type="url" name="image_url_2" value="<?= $v('image_url_2') ?>" placeholder="https://... (additional image)"></div>
        <div class="fg"><label>Image 3</label><input type="url" name="image_url_3" value="<?= $v('image_url_3') ?>" placeholder="https://..."></div>
        <div class="fg"><label>Image 4</label><input type="url" name="image_url_4" value="<?= $v('image_url_4') ?>" placeholder="https://..."></div>
        <div class="fg"><label>Instagram URL</label><input type="url" name="instagram_url" value="<?= $v('instagram_url') ?>" placeholder="https://instagram.com/..."></div>
        <div class="fg"><label>Facebook URL</label><input type="url" name="facebook_url" value="<?= $v('facebook_url') ?>" placeholder="https://facebook.com/..."></div>
        <div class="fg"><label>LinkedIn URL</label><input type="url" name="linkedin_url" value="<?= $v('linkedin_url') ?>" placeholder="https://linkedin.com/..."></div>
        <div class="fg"><label>Tags (comma-separated)</label><input type="text" name="tags" value="<?= htmlspecialchars(implode(', ', $tags)) ?>"></div>
        <div class="fg">
            <label>&nbsp;</label>
            <div class="ck"><input type="checkbox" name="is_featured" <?= !empty($item['is_featured'])?'checked':'' ?>> Featured</div>
            <div class="ck"><input type="checkbox" name="is_trusted" <?= !empty($item['is_trusted'])?'checked':'' ?>> Trusted</div>
            <div class="ck"><input type="checkbox" name="is_active" <?= ($id ? !empty($item['is_active']) : true)?'checked':'' ?>> Active</div>
        </div>
    </div>
    <div id="scan-results" style="display:none;margin-top:12px;padding:12px;background:#f0fdf4;border:1px solid #86efac;border-radius:6px"></div>
    <div style="margin-top:16px;display:flex;gap:8px">
        <button class="btn btn-g">Save</button>
        <a href="?s=providers" class="btn btn-o">Cancel</a>
    </div>
</form>

<?php
// ═══════════════════════════════════════════════════════════════
// DEVELOPERS LIST
// ═══════════════════════════════════════════════════════════════
elseif ($section === 'developers' && $action === 'list'):
    $q = $_GET['q'] ?? '';
    $f_area = $_GET['fa'] ?? '';
    $f_cat = $_GET['fc'] ?? '';
    $f_active = $_GET['fv'] ?? '';
    $f_min_reviews = $_GET['fr'] ?? '';
    $sort = $_GET['sort'] ?? 'reviews';
    $where = '1=1'; $params = [];
    if ($q) { $where .= " AND (d.name LIKE ?)"; $params[] = "%{$q}%"; }
    if ($f_area) { $where .= " AND EXISTS(SELECT 1 FROM developer_areas da2 WHERE da2.developer_id=d.id AND da2.area_key=?)"; $params[] = $f_area; }
    if ($f_cat) { $where .= " AND EXISTS(SELECT 1 FROM developer_categories dc2 WHERE dc2.developer_id=d.id AND dc2.category_key=?)"; $params[] = $f_cat; }
    if ($f_active === '1') { $where .= " AND d.is_active=1"; }
    elseif ($f_active === '0') { $where .= " AND d.is_active=0"; }
    if ($f_min_reviews !== '') { $where .= " AND d.google_review_count >= ?"; $params[] = (int)$f_min_reviews; }
    if ($sort === 'name') { $order = 'd.name ASC'; }
    elseif ($sort === 'rating') { $order = 'd.google_rating DESC, d.google_review_count DESC'; }
    else { $order = 'd.google_review_count DESC, d.google_rating DESC'; }
    $stmt = $db->prepare("SELECT d.* FROM developers d WHERE {$where} ORDER BY {$order} LIMIT 200");
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    // Fetch categories + areas for listed devs
    $dev_ids = array_column($rows, 'id');
    $dev_cat_map = []; $dev_area_map = [];
    if ($dev_ids) {
        $ph = implode(',', array_fill(0, count($dev_ids), '?'));
        $dc_s = $db->prepare("SELECT dc.developer_id, c.label FROM developer_categories dc JOIN categories c ON c.`key`=dc.category_key WHERE dc.developer_id IN ({$ph}) ORDER BY c.sort_order");
        $dc_s->execute($dev_ids);
        foreach ($dc_s->fetchAll() as $r2) $dev_cat_map[$r2['developer_id']][] = $r2['label'];
        $da_s = $db->prepare("SELECT da.developer_id, a.label FROM developer_areas da JOIN areas a ON a.`key`=da.area_key WHERE da.developer_id IN ({$ph}) ORDER BY a.sort_order");
        $da_s->execute($dev_ids);
        foreach ($da_s->fetchAll() as $r2) $dev_area_map[$r2['developer_id']][] = $r2['label'];
    }
?>
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
    <h1 style="margin:0">Developers (<?= count($rows) ?>)</h1>
    <a href="?s=developers&a=edit" class="btn btn-p">+ Add Developer</a>
</div>
<form class="search-bar" method="GET">
    <input type="hidden" name="s" value="developers">
    <input type="text" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Search by name...">
    <select name="fc">
        <option value="">All Categories</option>
        <?php foreach ($cats_list as $c): ?><option value="<?= $c['key'] ?>" <?= $f_cat===$c['key']?'selected':'' ?>><?= htmlspecialchars($c['label']) ?></option><?php endforeach; ?>
    </select>
    <select name="fa">
        <option value="">All Areas</option>
        <?php foreach ($areas_list as $a): ?><option value="<?= $a['key'] ?>" <?= $f_area===$a['key']?'selected':'' ?>><?= htmlspecialchars($a['label']) ?></option><?php endforeach; ?>
    </select>
    <select name="fv">
        <option value="">Any Status</option>
        <option value="1" <?= $f_active==='1'?'selected':'' ?>>Active</option>
        <option value="0" <?= $f_active==='0'?'selected':'' ?>>Inactive</option>
    </select>
    <input type="number" name="fr" value="<?= htmlspecialchars($f_min_reviews) ?>" placeholder="Min reviews" style="width:100px;" min="0">
    <input type="hidden" name="sort" value="<?= htmlspecialchars($sort) ?>">
    <button class="btn btn-o">Filter</button>
    <?php if ($q || $f_area || $f_cat || $f_active !== '' || $f_min_reviews !== ''): ?><a href="?s=developers" class="btn btn-o">Clear</a><?php endif; ?>
</form>
<?php
    $d_sort_params = http_build_query(array_filter(['s'=>'developers','q'=>$q,'fc'=>$f_cat,'fa'=>$f_area,'fv'=>$f_active,'fr'=>$f_min_reviews], function($v){ return $v!==''; }));
    $d_sort_name_url = $d_sort_params . '&sort=name';
    $d_sort_reviews_url = $d_sort_params . '&sort=reviews';
    $d_sort_rating_url = $d_sort_params . '&sort=rating';
?>
<div class="card" style="padding:0;overflow-x:auto">
<table>
    <tr>
        <th style="width:40px"></th>
        <th><a href="?<?= $d_sort_name_url ?>" style="color:inherit;text-decoration:none">Name <?= $sort==='name'?'&#9650;':'' ?></a></th>
        <th>Categories</th><th>Areas</th>
        <th><a href="?<?= $d_sort_reviews_url ?>" style="color:inherit;text-decoration:none">Reviews <?= $sort==='reviews'?'&#9660;':'' ?></a> / <a href="?<?= $d_sort_rating_url ?>" style="color:inherit;text-decoration:none">Rating <?= $sort==='rating'?'&#9660;':'' ?></a></th>
        <th>Website</th>
        <th>Active</th><th>Actions</th>
    </tr>
    <?php foreach ($rows as $r): ?>
    <tr id="entity-row-developers-<?= $r['id'] ?>">
        <td>
            <?php if (!empty($r['profile_photo_url'])): ?>
            <img src="<?= htmlspecialchars($r['profile_photo_url']) ?>" alt="" style="width:36px;height:36px;border-radius:4px;object-fit:cover;display:block">
            <?php else: ?>
            <div style="width:36px;height:36px;background:#e5e7eb;border-radius:4px;display:flex;align-items:center;justify-content:center;font-size:9px;color:#9ca3af">No img</div>
            <?php endif; ?>
        </td>
        <td><a href="?s=developers&a=edit&id=<?= $r['id'] ?>"><?= htmlspecialchars($r['name']) ?></a></td>
        <td><?= htmlspecialchars(implode(', ', $dev_cat_map[$r['id']] ?? []) ?: '-') ?></td>
        <td><?= htmlspecialchars(implode(', ', $dev_area_map[$r['id']] ?? []) ?: '-') ?></td>
        <td>&#9733;<?= $r['google_rating'] ?: '-' ?> (<?= $r['google_review_count'] ?>)</td>
        <td style="max-width:120px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?php if (!empty($r['website_url'])): ?><a href="<?= htmlspecialchars($r['website_url']) ?>" target="_blank" rel="noopener" style="font-size:12px"><?= htmlspecialchars(parse_url($r['website_url'], PHP_URL_HOST) ?: $r['website_url']) ?></a><?php else: ?><span style="color:#ccc">-</span><?php endif; ?></td>
        <td><?= $r['is_active'] ? '<span class="badge b-green">Yes</span>' : '<span class="badge b-red">No</span>' ?></td>
        <td class="actions">
            <a href="?s=developers&a=edit&id=<?= $r['id'] ?>" class="btn btn-o btn-sm">Edit</a>
            <button type="button" class="btn btn-r btn-sm" onclick="ajaxEntityDelete('developers',<?= $r['id'] ?>,'developer')">Del</button>
        </td>
    </tr>
    <?php endforeach; ?>
</table>
</div>

<?php
// ═══════════════════════════════════════════════════════════════
// DEVELOPER EDIT/CREATE
// ═══════════════════════════════════════════════════════════════
elseif ($section === 'developers' && ($action === 'edit' || $action === 'save')):
    $item = $id ? $db->query("SELECT * FROM developers WHERE id={$id}")->fetch() : [];
    $tags = $id ? $db->query("SELECT tag FROM developer_tags WHERE developer_id={$id}")->fetchAll(PDO::FETCH_COLUMN) : [];
    $dev_areas = $id ? $db->query("SELECT area_key FROM developer_areas WHERE developer_id={$id}")->fetchAll(PDO::FETCH_COLUMN) : [];
    $dev_cats = $id ? $db->query("SELECT category_key FROM developer_categories WHERE developer_id={$id}")->fetchAll(PDO::FETCH_COLUMN) : [];
    $v = function($k, $d='') use ($item) { return htmlspecialchars($item[$k] ?? $d); };
?>
<h1><a href="?s=developers">&larr; Developers</a> / <?= $id ? 'Edit: '.htmlspecialchars($item['name']) : 'New Developer' ?></h1>
<form method="POST" action="?s=developers&a=save<?= $id?"&id={$id}":'' ?>" class="card">
    <div class="form-grid">
        <div class="fg span2"><label>Name</label><input type="text" name="name" value="<?= $v('name') ?>" required></div>
        <div class="fg span2"><label>Short Description</label><input type="text" name="short_description" value="<?= $v('short_description') ?>"></div>
        <div class="fg span2"><label>Full Description</label><textarea name="description" rows="4"><?= $v('description') ?></textarea></div>
        <div class="fg"><label>Min Ticket (USD)</label><input type="number" name="min_ticket_usd" value="<?= $v('min_ticket_usd') ?>"></div>
        <div class="fg"><label>Languages</label>
            <div style="display:flex;gap:12px;align-items:center;padding:6px 0">
                <?php $langs_d = array_map('trim', explode(',', $v('languages',''))); ?>
                <label style="font-weight:400;display:flex;align-items:center;gap:4px;cursor:pointer"><input type="checkbox" name="languages[]" value="Bahasa" <?= in_array('Bahasa', $langs_d) || in_array('Bahasa only', $langs_d) ? 'checked' : '' ?>> Bahasa</label>
                <label style="font-weight:400;display:flex;align-items:center;gap:4px;cursor:pointer"><input type="checkbox" name="languages[]" value="English" <?= in_array('English', $langs_d) || strpos($v('languages',''), 'English') !== false ? 'checked' : '' ?>> English</label>
            </div>
        </div>
        <div class="fg"><label>Google Maps URL</label><input type="url" name="google_maps_url" value="<?= $v('google_maps_url') ?>"></div>
        <div class="fg"><label>Google Rating</label><input type="number" name="google_rating" value="<?= $v('google_rating') ?>" step="0.1" min="1" max="5"></div>
        <div class="fg"><label>Review Count</label><input type="number" name="google_review_count" value="<?= $v('google_review_count','0') ?>"></div>
        <div class="fg"><label>Phone</label><input type="text" name="phone" value="<?= $v('phone') ?>"></div>
        <div class="fg"><label>WhatsApp</label><input type="text" name="whatsapp_number" value="<?= $v('whatsapp_number') ?>"></div>
        <div class="fg"><label>Website</label>
            <div style="display:flex;gap:8px;align-items:center">
                <input type="url" name="website_url" id="dev_website_url" value="<?= $v('website_url') ?>" style="flex:1">
                <?php if ($id): ?>
                <?php if (!empty($item['website_url'])): ?>
                <button type="button" class="btn btn-o btn-sm" onclick="scanWebsite('developers')" id="scan-btn-dev" title="Scan website for missing info">&#x1F50D; Scan</button>
                <label style="font-size:11px;display:flex;align-items:center;gap:3px;white-space:nowrap;cursor:pointer" title="When checked, re-scan and overwrite existing field values">
                    <input type="checkbox" id="scan-update-dev" style="margin:0"> Update existing
                </label>
                <?php else: ?>
                <button type="button" class="btn btn-o btn-sm" onclick="window.open('https://www.google.com/search?q='+encodeURIComponent(document.querySelector('[name=name]').value+' Lombok'),'_blank')" title="Search Google for this entity">&#x1F50D; Search</button>
                <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
        <div class="fg"><label>Badge</label><input type="text" name="badge" value="<?= $v('badge') ?>"></div>
        <div class="fg span2"><label>Profile Photo URL</label>
            <div style="display:flex;gap:8px;align-items:center">
                <input type="url" name="profile_photo_url" value="<?= $v('profile_photo_url') ?>" placeholder="https://..." style="flex:1">
                <?php if (!empty($item['profile_photo_url'])): ?><img src="<?= $v('profile_photo_url') ?>" style="width:36px;height:36px;border-radius:4px;object-fit:cover"><?php endif; ?>
            </div>
        </div>
        <div class="fg span2"><label>Logo URL</label>
            <div style="display:flex;gap:8px;align-items:center">
                <input type="url" name="logo_url" value="<?= $v('logo_url') ?>" placeholder="https://... (company logo)" style="flex:1">
                <?php if (!empty($item['logo_url'])): ?><img src="<?= $v('logo_url') ?>" style="height:36px;width:auto;border-radius:4px;object-fit:contain;background:#f5f5f5;padding:2px"><?php endif; ?>
            </div>
        </div>
        <div class="fg span2"><label>Hero Image URL</label>
            <div style="display:flex;gap:8px;align-items:center">
                <input type="url" name="hero_image_url" value="<?= $v('hero_image_url') ?>" placeholder="https://... (banner / hero background)" style="flex:1">
                <?php if (!empty($item['hero_image_url'])): ?><img src="<?= $v('hero_image_url') ?>" style="height:36px;width:auto;border-radius:4px;object-fit:cover"><?php endif; ?>
            </div>
        </div>
        <div class="fg span2"><label>Image 2</label><input type="url" name="image_url_2" value="<?= $v('image_url_2') ?>" placeholder="https://... (additional image)"></div>
        <div class="fg"><label>Image 3</label><input type="url" name="image_url_3" value="<?= $v('image_url_3') ?>" placeholder="https://..."></div>
        <div class="fg"><label>Image 4</label><input type="url" name="image_url_4" value="<?= $v('image_url_4') ?>" placeholder="https://..."></div>
        <div class="fg"><label>Instagram URL</label><input type="url" name="instagram_url" value="<?= $v('instagram_url') ?>" placeholder="https://instagram.com/..."></div>
        <div class="fg"><label>Facebook URL</label><input type="url" name="facebook_url" value="<?= $v('facebook_url') ?>" placeholder="https://facebook.com/..."></div>
        <div class="fg"><label>LinkedIn URL</label><input type="url" name="linkedin_url" value="<?= $v('linkedin_url') ?>" placeholder="https://linkedin.com/..."></div>
        <div class="fg span2"><label>Areas (select multiple)</label>
            <select name="areas[]" multiple style="height:100px">
                <?php foreach ($areas_list as $a): ?><option value="<?= $a['key'] ?>" <?= in_array($a['key'], $dev_areas)?'selected':'' ?>><?= htmlspecialchars($a['label']) ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="fg span2"><label>Categories / Also operates as (select multiple)</label>
            <select name="categories[]" multiple style="height:120px">
                <?php foreach ($cats_list as $c): ?><option value="<?= $c['key'] ?>" <?= in_array($c['key'], $dev_cats)?'selected':'' ?>><?= htmlspecialchars($c['label']) ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="fg"><label>Tags (comma-separated)</label><input type="text" name="tags" value="<?= htmlspecialchars(implode(', ', $tags)) ?>"></div>
        <div class="fg">
            <label>&nbsp;</label>
            <div class="ck"><input type="checkbox" name="is_featured" <?= !empty($item['is_featured'])?'checked':'' ?>> Featured</div>
            <div class="ck"><input type="checkbox" name="is_active" <?= ($id ? !empty($item['is_active']) : true)?'checked':'' ?>> Active</div>
        </div>
    </div>
    <div id="scan-results-dev" style="display:none;margin-top:12px;padding:12px;background:#f0fdf4;border:1px solid #86efac;border-radius:6px"></div>
    <div style="margin-top:16px;display:flex;gap:8px">
        <button class="btn btn-g">Save</button>
        <a href="?s=developers" class="btn btn-o">Cancel</a>
    </div>
</form>

<?php
// ═══════════════════════════════════════════════════════════════
// PROJECTS LIST
// ═══════════════════════════════════════════════════════════════
elseif ($section === 'projects' && $action === 'list'):
    $q = $_GET['q'] ?? '';
    $f_area = $_GET['fa'] ?? '';
    $f_type = $_GET['ft'] ?? '';
    $f_status = $_GET['fs'] ?? '';
    $f_dev = $_GET['fd'] ?? '';
    $f_active = $_GET['fv'] ?? '';
    $where = '1=1'; $params = [];
    if ($q) { $where .= " AND (p.name LIKE ?)"; $params[] = "%{$q}%"; }
    if ($f_area) { $where .= " AND p.area_key=?"; $params[] = $f_area; }
    if ($f_type) { $where .= " AND p.project_type_key=?"; $params[] = $f_type; }
    if ($f_status) { $where .= " AND p.status_key=?"; $params[] = $f_status; }
    if ($f_dev) { $where .= " AND p.developer_id=?"; $params[] = (int)$f_dev; }
    if ($f_active === '1') { $where .= " AND p.is_active=1"; }
    elseif ($f_active === '0') { $where .= " AND p.is_active=0"; }
    $all_devs = $db->query("SELECT id, name FROM developers ORDER BY name")->fetchAll();
    $stmt = $db->prepare("SELECT p.*, d.name AS dev_name, a.label AS area_label, pt.label AS type_label, ps.label AS status_label
        FROM projects p LEFT JOIN developers d ON d.id=p.developer_id LEFT JOIN areas a ON a.`key`=p.area_key
        LEFT JOIN project_types pt ON pt.`key`=p.project_type_key LEFT JOIN project_statuses ps ON ps.`key`=p.status_key
        WHERE {$where} ORDER BY p.name LIMIT 200");
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
?>
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
    <h1 style="margin:0">Projects (<?= count($rows) ?>)</h1>
    <a href="?s=projects&a=edit" class="btn btn-p">+ Add Project</a>
</div>
<form class="search-bar" method="GET">
    <input type="hidden" name="s" value="projects">
    <input type="text" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Search by name...">
    <select name="fd">
        <option value="">All Developers</option>
        <?php foreach ($all_devs as $d): ?><option value="<?= $d['id'] ?>" <?= $f_dev==(string)$d['id']?'selected':'' ?>><?= htmlspecialchars($d['name']) ?></option><?php endforeach; ?>
    </select>
    <select name="ft">
        <option value="">All Types</option>
        <?php foreach ($ptypes_list as $pt): ?><option value="<?= $pt['key'] ?>" <?= $f_type===$pt['key']?'selected':'' ?>><?= htmlspecialchars($pt['label']) ?></option><?php endforeach; ?>
    </select>
    <select name="fs">
        <option value="">All Statuses</option>
        <?php foreach ($pstatus_list as $ps): ?><option value="<?= $ps['key'] ?>" <?= $f_status===$ps['key']?'selected':'' ?>><?= htmlspecialchars($ps['label']) ?></option><?php endforeach; ?>
    </select>
    <select name="fa">
        <option value="">All Areas</option>
        <?php foreach ($areas_list as $a): ?><option value="<?= $a['key'] ?>" <?= $f_area===$a['key']?'selected':'' ?>><?= htmlspecialchars($a['label']) ?></option><?php endforeach; ?>
    </select>
    <select name="fv">
        <option value="">Any Status</option>
        <option value="1" <?= $f_active==='1'?'selected':'' ?>>Active</option>
        <option value="0" <?= $f_active==='0'?'selected':'' ?>>Inactive</option>
    </select>
    <button class="btn btn-o">Filter</button>
    <?php if ($q || $f_area || $f_type || $f_status || $f_dev || $f_active !== ''): ?><a href="?s=projects" class="btn btn-o">Clear</a><?php endif; ?>
</form>
<div class="card" style="padding:0;overflow-x:auto">
<table>
    <tr><th>Name</th><th>Developer</th><th>Type</th><th>Status</th><th>Area</th><th>Active</th><th>Actions</th></tr>
    <?php foreach ($rows as $r): ?>
    <tr id="entity-row-projects-<?= $r['id'] ?>">
        <td><a href="?s=projects&a=edit&id=<?= $r['id'] ?>"><?= htmlspecialchars($r['name']) ?></a></td>
        <td><?= htmlspecialchars($r['dev_name'] ?? '-') ?></td>
        <td><span class="badge b-blue"><?= htmlspecialchars($r['type_label'] ?? '-') ?></span></td>
        <td><span class="badge b-yellow"><?= htmlspecialchars($r['status_label'] ?? '-') ?></span></td>
        <td><?= htmlspecialchars($r['area_label'] ?? '-') ?></td>
        <td><?= $r['is_active'] ? '<span class="badge b-green">Yes</span>' : '<span class="badge b-red">No</span>' ?></td>
        <td class="actions">
            <a href="?s=projects&a=edit&id=<?= $r['id'] ?>" class="btn btn-o btn-sm">Edit</a>
            <button type="button" class="btn btn-r btn-sm" onclick="ajaxEntityDelete('projects',<?= $r['id'] ?>,'project')">Del</button>
        </td>
    </tr>
    <?php endforeach; ?>
</table>
</div>

<?php
// ═══════════════════════════════════════════════════════════════
// PROJECT EDIT/CREATE
// ═══════════════════════════════════════════════════════════════
elseif ($section === 'projects' && ($action === 'edit' || $action === 'save')):
    $item = $id ? $db->query("SELECT * FROM projects WHERE id={$id}")->fetch() : [];
    $devs = $db->query("SELECT id, name FROM developers WHERE is_active=1 ORDER BY name")->fetchAll();
    $v = function($k, $d='') use ($item) { return htmlspecialchars($item[$k] ?? $d); };
?>
<h1><a href="?s=projects">&larr; Projects</a> / <?= $id ? 'Edit: '.htmlspecialchars($item['name']) : 'New Project' ?></h1>
<form method="POST" action="?s=projects&a=save<?= $id?"&id={$id}":'' ?>" class="card">
    <div class="form-grid">
        <div class="fg span2"><label>Name</label><input type="text" name="name" value="<?= $v('name') ?>" required></div>
        <div class="fg"><label>Developer</label>
            <select name="developer_id"><option value="">— None —</option>
                <?php foreach ($devs as $d): ?><option value="<?= $d['id'] ?>" <?= ($item['developer_id']??'')==$d['id']?'selected':'' ?>><?= htmlspecialchars($d['name']) ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="fg"><label>Area</label>
            <select name="area_key" required>
                <?php foreach ($areas_list as $a): ?><option value="<?= $a['key'] ?>" <?= ($item['area_key']??'')===$a['key']?'selected':'' ?>><?= htmlspecialchars($a['label']) ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="fg"><label>Project Type</label>
            <select name="project_type_key" required>
                <?php foreach ($ptypes_list as $pt): ?><option value="<?= $pt['key'] ?>" <?= ($item['project_type_key']??'')===$pt['key']?'selected':'' ?>><?= htmlspecialchars($pt['label']) ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="fg"><label>Status</label>
            <select name="status_key" required>
                <?php foreach ($pstatus_list as $ps): ?><option value="<?= $ps['key'] ?>" <?= ($item['status_key']??'planning')===$ps['key']?'selected':'' ?>><?= htmlspecialchars($ps['label']) ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="fg"><label>Min Investment (USD)</label><input type="number" name="min_investment_usd" value="<?= $v('min_investment_usd') ?>"></div>
        <div class="fg"><label>Expected Yield Range</label><input type="text" name="expected_yield_range" value="<?= $v('expected_yield_range') ?>" placeholder="e.g. 8-12%"></div>
        <div class="fg span2"><label>Timeline Summary</label><input type="text" name="timeline_summary" value="<?= $v('timeline_summary') ?>"></div>
        <div class="fg span2"><label>Short Description</label><input type="text" name="short_description" value="<?= $v('short_description') ?>"></div>
        <div class="fg span2"><label>Full Description</label><textarea name="description" rows="5"><?= $v('description') ?></textarea></div>
        <div class="fg"><label>Website</label><input type="url" name="website_url" value="<?= $v('website_url') ?>"></div>
        <div class="fg"><label>Contact WhatsApp</label><input type="text" name="info_contact_whatsapp" value="<?= $v('info_contact_whatsapp') ?>"></div>
        <div class="fg"><label>Badge</label><input type="text" name="badge" value="<?= $v('badge') ?>"></div>
        <div class="fg span2"><label>Logo URL</label>
            <div style="display:flex;gap:8px;align-items:center">
                <input type="url" name="logo_url" value="<?= $v('logo_url') ?>" placeholder="https://... (project logo)" style="flex:1">
                <?php if (!empty($item['logo_url'])): ?><img src="<?= $v('logo_url') ?>" style="height:36px;width:auto;border-radius:4px;object-fit:contain;background:#f5f5f5;padding:2px"><?php endif; ?>
            </div>
        </div>
        <div class="fg">
            <label>&nbsp;</label>
            <div class="ck"><input type="checkbox" name="is_featured" <?= !empty($item['is_featured'])?'checked':'' ?>> Featured</div>
            <div class="ck"><input type="checkbox" name="is_active" <?= ($id ? !empty($item['is_active']) : true)?'checked':'' ?>> Active</div>
        </div>
    </div>
    <div style="margin-top:16px;display:flex;gap:8px">
        <button class="btn btn-g">Save</button>
        <a href="?s=projects" class="btn btn-o">Cancel</a>
    </div>
</form>

<?php
// ═══════════════════════════════════════════════════════════════
// GUIDES LIST
// ═══════════════════════════════════════════════════════════════
elseif ($section === 'guides' && $action === 'list'):
    $q = $_GET['q'] ?? '';
    $f_gcat = $_GET['fc'] ?? '';
    $f_pub = $_GET['fp'] ?? '';
    $where = '1=1'; $params = [];
    if ($q) { $where .= " AND (title LIKE ? OR excerpt LIKE ?)"; $params[] = "%{$q}%"; $params[] = "%{$q}%"; }
    if ($f_gcat) { $where .= " AND category=?"; $params[] = $f_gcat; }
    if ($f_pub === '1') { $where .= " AND is_published=1"; }
    elseif ($f_pub === '0') { $where .= " AND is_published=0"; }
    $stmt = $db->prepare("SELECT * FROM guides WHERE {$where} ORDER BY created_at DESC LIMIT 200");
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    // Get distinct guide categories for filter
    $guide_cats = $db->query("SELECT DISTINCT category FROM guides WHERE category != '' ORDER BY category")->fetchAll(PDO::FETCH_COLUMN);
?>
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
    <h1 style="margin:0">Guides (<?= count($rows) ?>)</h1>
    <a href="?s=guides&a=edit" class="btn btn-p">+ Add Guide</a>
</div>
<form class="search-bar" method="GET">
    <input type="hidden" name="s" value="guides">
    <input type="text" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Search by title...">
    <select name="fc">
        <option value="">All Categories</option>
        <?php foreach ($guide_cats as $gc): ?><option value="<?= htmlspecialchars($gc) ?>" <?= $f_gcat===$gc?'selected':'' ?>><?= htmlspecialchars($gc) ?></option><?php endforeach; ?>
    </select>
    <select name="fp">
        <option value="">Any Status</option>
        <option value="1" <?= $f_pub==='1'?'selected':'' ?>>Published</option>
        <option value="0" <?= $f_pub==='0'?'selected':'' ?>>Draft</option>
    </select>
    <button class="btn btn-o">Filter</button>
    <?php if ($q || $f_gcat || $f_pub !== ''): ?><a href="?s=guides" class="btn btn-o">Clear</a><?php endif; ?>
</form>
<div class="card" style="padding:0;overflow-x:auto">
<table>
    <tr><th>Title</th><th>Category</th><th>Read Time</th><th>Published</th><th>Updated</th><th>Actions</th></tr>
    <?php foreach ($rows as $r): ?>
    <tr id="entity-row-guides-<?= $r['id'] ?>">
        <td><a href="?s=guides&a=edit&id=<?= $r['id'] ?>"><?= htmlspecialchars($r['title']) ?></a></td>
        <td><span class="badge b-blue"><?= htmlspecialchars($r['category']) ?></span></td>
        <td><?= htmlspecialchars($r['read_time'] ?? '-') ?></td>
        <td><?= $r['is_published'] ? '<span class="badge b-green">Yes</span>' : '<span class="badge b-red">Draft</span>' ?></td>
        <td style="color:#888;font-size:12px"><?= $r['updated_at'] ?></td>
        <td class="actions">
            <a href="?s=guides&a=edit&id=<?= $r['id'] ?>" class="btn btn-o btn-sm">Edit</a>
            <button type="button" class="btn btn-r btn-sm" onclick="ajaxEntityDelete('guides',<?= $r['id'] ?>,'guide')">Del</button>
        </td>
    </tr>
    <?php endforeach; ?>
</table>
</div>

<?php
// ═══════════════════════════════════════════════════════════════
// GUIDE EDIT/CREATE
// ═══════════════════════════════════════════════════════════════
elseif ($section === 'guides' && ($action === 'edit' || $action === 'save')):
    $item = $id ? $db->query("SELECT * FROM guides WHERE id={$id}")->fetch() : [];
    $v = function($k, $d='') use ($item) { return htmlspecialchars($item[$k] ?? $d); };
?>
<h1><a href="?s=guides">&larr; Guides</a> / <?= $id ? 'Edit: '.htmlspecialchars($item['title']) : 'New Guide' ?></h1>
<form method="POST" action="?s=guides&a=save<?= $id?"&id={$id}":'' ?>" class="card">
    <div class="form-grid">
        <div class="fg span2"><label>Title</label><input type="text" name="title" value="<?= $v('title') ?>" required></div>
        <div class="fg"><label>Category</label><input type="text" name="category" value="<?= $v('category') ?>" placeholder="e.g. Building, Legal, Finance"></div>
        <div class="fg"><label>Read Time</label><input type="text" name="read_time" value="<?= $v('read_time') ?>" placeholder="e.g. 8 min read"></div>
        <div class="fg span2"><label>Excerpt</label><textarea name="excerpt" rows="2"><?= $v('excerpt') ?></textarea></div>
        <div class="fg span2"><label>Content (HTML)</label><textarea name="content" rows="16" style="font-family:monospace;font-size:12px"><?= $v('content') ?></textarea></div>
        <div class="fg">
            <div class="ck"><input type="checkbox" name="is_published" <?= ($id ? !empty($item['is_published']) : true)?'checked':'' ?>> Published</div>
        </div>
    </div>
    <div style="margin-top:16px;display:flex;gap:8px">
        <button class="btn btn-g">Save</button>
        <a href="?s=guides" class="btn btn-o">Cancel</a>
    </div>
</form>

<?php
// ═══════════════════════════════════════════════════════════════
// CATEGORIES & LOOKUPS
// ═══════════════════════════════════════════════════════════════
elseif ($section === 'lookups'):

    // Handle inline edit
    $edit_table = $_GET['et'] ?? '';
    $edit_key = $_GET['ek'] ?? '';

    $lookup_tables = [
        'groups' => ['title' => 'Provider Groups', 'has_group' => false, 'items' => $groups_list],
        'categories' => ['title' => 'Provider Categories', 'has_group' => true, 'items' => $cats_list],
        'areas' => ['title' => 'Areas / Locations', 'has_group' => false, 'has_region' => true, 'items' => $areas_list],
        'project_types' => ['title' => 'Project Types', 'has_group' => false, 'items' => $ptypes_list],
        'project_statuses' => ['title' => 'Project Statuses', 'has_group' => false, 'items' => $pstatus_list],
    ];
?>
<h1>Categories & Lookups</h1>
<p style="color:#666;margin-bottom:20px">Manage groups, categories, areas, and other lookup data used across the site.</p>

<?php foreach ($lookup_tables as $tbl => $cfg): ?>
<div class="card lookup-section" id="lookup-<?= $tbl ?>">
    <div style="display:flex;justify-content:space-between;align-items:center">
        <h3 style="margin:0;border:none"><?= $cfg['title'] ?> (<?= count($cfg['items']) ?>)</h3>
        <a href="?s=lookups&et=<?= $tbl ?>&ek=_new#lookup-<?= $tbl ?>" class="btn btn-p btn-sm">+ Add</a>
    </div>
    <table style="margin-top:8px">
        <tr><th>Key</th><th>Label</th><?php if ($cfg['has_group']): ?><th>Group</th><?php endif; ?><?php if (!empty($cfg['has_region'])): ?><th>Region</th><?php endif; ?><th>Sort</th><th style="width:120px">Actions</th></tr>
        <?php foreach ($cfg['items'] as $it): ?>
        <?php if ($edit_table === $tbl && $edit_key === $it['key']): ?>
        <tr style="background:#fffff0">
            <form method="POST" action="?s=lookups&a=save">
                <input type="hidden" name="_table" value="<?= $tbl ?>">
                <input type="hidden" name="_old_key" value="<?= htmlspecialchars($it['key']) ?>">
                <td><input type="text" name="key" value="<?= htmlspecialchars($it['key']) ?>" style="width:140px" required></td>
                <td><input type="text" name="label" value="<?= htmlspecialchars($it['label']) ?>" style="width:200px" required></td>
                <?php if ($cfg['has_group']): ?>
                <td><select name="group_key" style="width:140px">
                    <?php foreach ($groups_list as $g): ?><option value="<?= $g['key'] ?>" <?= ($it['group_key']??'')===$g['key']?'selected':'' ?>><?= htmlspecialchars($g['label']) ?></option><?php endforeach; ?>
                </select></td>
                <?php endif; ?>
                <?php if (!empty($cfg['has_region'])): ?>
                <td><select name="region_key" style="width:140px">
                    <option value="">(none)</option>
                    <?php foreach ($regions_list as $rg): ?><option value="<?= $rg['region_key'] ?>" <?= ($it['region_key']??'')===$rg['region_key']?'selected':'' ?>><?= htmlspecialchars($rg['label']) ?></option><?php endforeach; ?>
                </select></td>
                <?php endif; ?>
                <td><input type="number" name="sort_order" value="<?= $it['sort_order'] ?>" style="width:60px"></td>
                <td class="actions"><button class="btn btn-g btn-sm">Save</button> <a href="?s=lookups#lookup-<?= $tbl ?>" class="btn btn-o btn-sm">Cancel</a></td>
            </form>
        </tr>
        <?php else: ?>
        <tr id="lookup-row-<?= $tbl ?>-<?= htmlspecialchars($it['key']) ?>">
            <td><code style="font-size:12px;color:#666"><?= htmlspecialchars($it['key']) ?></code></td>
            <td><?= htmlspecialchars($it['label']) ?></td>
            <?php if ($cfg['has_group']): ?>
            <td><span class="badge b-blue"><?= htmlspecialchars($it['group_key'] ?? '') ?></span></td>
            <?php endif; ?>
            <?php if (!empty($cfg['has_region'])): ?>
            <td><span class="badge b-blue"><?= htmlspecialchars($it['region_key'] ?? '-') ?></span></td>
            <?php endif; ?>
            <td><?= $it['sort_order'] ?></td>
            <td class="actions">
                <a href="?s=lookups&et=<?= $tbl ?>&ek=<?= urlencode($it['key']) ?>#lookup-<?= $tbl ?>" class="btn btn-o btn-sm">Edit</a>
                <button type="button" class="btn btn-r btn-sm" onclick="ajaxLookupDelete('<?= $tbl ?>','<?= htmlspecialchars($it['key'], ENT_QUOTES) ?>')">Del</button>
            </td>
        </tr>
        <?php endif; ?>
        <?php endforeach; ?>
        <?php if ($edit_table === $tbl && $edit_key === '_new'): ?>
        <tr style="background:#f0fff0">
            <form method="POST" action="?s=lookups&a=save">
                <input type="hidden" name="_table" value="<?= $tbl ?>">
                <input type="hidden" name="_old_key" value="">
                <td><input type="text" name="key" placeholder="snake_case_key" style="width:140px" required></td>
                <td><input type="text" name="label" placeholder="Display Label" style="width:200px" required></td>
                <?php if ($cfg['has_group']): ?>
                <td><select name="group_key" style="width:140px">
                    <?php foreach ($groups_list as $g): ?><option value="<?= $g['key'] ?>"><?= htmlspecialchars($g['label']) ?></option><?php endforeach; ?>
                </select></td>
                <?php endif; ?>
                <?php if (!empty($cfg['has_region'])): ?>
                <td><select name="region_key" style="width:140px">
                    <option value="">(none)</option>
                    <?php foreach ($regions_list as $rg): ?><option value="<?= $rg['region_key'] ?>"><?= htmlspecialchars($rg['label']) ?></option><?php endforeach; ?>
                </select></td>
                <?php endif; ?>
                <td><input type="number" name="sort_order" value="99" style="width:60px"></td>
                <td class="actions"><button class="btn btn-g btn-sm">Add</button> <a href="?s=lookups#lookup-<?= $tbl ?>" class="btn btn-o btn-sm">Cancel</a></td>
            </form>
        </tr>
        <?php endif; ?>
    </table>
</div>
<?php endforeach; ?>

<!-- Currency Rates -->
<div class="card lookup-section" id="lookup-currency_rates">
    <div style="display:flex;justify-content:space-between;align-items:center">
        <h3 style="margin:0;border:none">Currency Exchange Rates (<?= count($currency_rates) ?>)</h3>
    </div>
    <form method="POST" action="?s=lookups&a=save_rates">
    <table style="margin-top:8px">
        <tr><th>From</th><th>To</th><th>Rate</th></tr>
        <?php foreach ($currency_rates as $cr): ?>
        <tr>
            <td>
                <input type="hidden" name="from_currency[]" value="<?= htmlspecialchars($cr['from_currency']) ?>">
                <code style="font-size:12px"><?= htmlspecialchars($cr['from_currency']) ?></code>
            </td>
            <td>
                <input type="hidden" name="to_currency[]" value="<?= htmlspecialchars($cr['to_currency']) ?>">
                <code style="font-size:12px"><?= htmlspecialchars($cr['to_currency']) ?></code>
            </td>
            <td><input type="text" name="rate[]" value="<?= $cr['rate'] ?>" style="width:140px;padding:4px 8px;border:1px solid #d0d0d0;border-radius:4px;font-size:12px"></td>
        </tr>
        <?php endforeach; ?>
    </table>
    <div style="margin-top:8px"><button class="btn btn-p btn-sm">Save Rates</button> <span style="font-size:11px;color:#94a3b8;margin-left:8px">Last updated: <?= !empty($currency_rates) ? $currency_rates[0]['updated_at'] : 'N/A' ?></span></div>
    </form>
</div>

<?php
// ═══════════════════════════════════════════════════════════════
// USERS LIST + DETAIL EDIT
// ═══════════════════════════════════════════════════════════════
elseif ($section === 'users'):
    $edit_uid = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;

    // ─── SINGLE USER EDIT VIEW ───
    if ($edit_uid > 0):
        $eu = $db->prepare("SELECT * FROM users WHERE id=?");
        $eu->execute(array($edit_uid));
        $eu = $eu->fetch();
        if (!$eu) { echo '<p>User not found.</p>'; return; }
        // Count related data
        $fav_count = $db->prepare("SELECT COUNT(*) FROM user_favorites WHERE user_id=?"); $fav_count->execute(array($edit_uid)); $fav_count = $fav_count->fetchColumn();
        $claim_count_u = $db->prepare("SELECT COUNT(*) FROM claim_requests WHERE user_id=?"); $claim_count_u->execute(array($edit_uid)); $claim_count_u = $claim_count_u->fetchColumn();
        $owned_count = $db->prepare("SELECT COUNT(*) FROM provider_owners WHERE user_id=?"); $owned_count->execute(array($edit_uid)); $owned_count = $owned_count->fetchColumn();
?>
<p><a href="?s=users" style="color:#0c7c84">&larr; Back to Users</a></p>
<h1>Manage User: <?= htmlspecialchars($eu['display_name']) ?></h1>

<!-- User Info Card -->
<div class="card" style="margin-bottom:20px">
  <table>
    <tr><td style="width:160px;font-weight:600;color:#666">ID</td><td><?= $eu['id'] ?></td></tr>
    <tr><td style="font-weight:600;color:#666">Email</td><td><?= htmlspecialchars($eu['email']) ?></td></tr>
    <tr><td style="font-weight:600;color:#666">Display Name</td><td><?= htmlspecialchars($eu['display_name']) ?></td></tr>
    <tr><td style="font-weight:600;color:#666">Phone</td><td><?= htmlspecialchars($eu['phone'] ?: '—') ?></td></tr>
    <tr><td style="font-weight:600;color:#666">WhatsApp</td><td><?= htmlspecialchars($eu['whatsapp_number'] ?: '—') ?></td></tr>
    <tr><td style="font-weight:600;color:#666">Verified</td><td><?= $eu['is_verified'] ? '<span class="badge b-green">Yes</span>' : '<span class="badge b-yellow">No</span>' ?></td></tr>
    <tr><td style="font-weight:600;color:#666">Status</td><td><?= $eu['is_active'] ? '<span class="badge b-green">Active</span>' : '<span class="badge b-red">Inactive</span>' ?></td></tr>
    <tr><td style="font-weight:600;color:#666">Role</td><td><span class="badge <?= $eu['role']==='admin'?'b-red':($eu['role']==='provider_owner'?'b-blue':'b-green') ?>"><?= $eu['role'] ?></span></td></tr>
    <tr><td style="font-weight:600;color:#666">Subscription</td><td><span class="badge <?= $eu['subscription_tier']==='premium'?'b-blue':($eu['subscription_tier']==='basic'?'b-green':'') ?>"><?= $eu['subscription_tier'] ?: 'free' ?></span>
      <?php if (isset($eu['subscription_expires_at']) && $eu['subscription_expires_at']): ?>
        <span style="color:#888;font-size:12px"> expires <?= $eu['subscription_expires_at'] ?></span>
      <?php endif; ?>
    </td></tr>
    <tr><td style="font-weight:600;color:#666">Joined</td><td><?= $eu['created_at'] ?></td></tr>
    <tr><td style="font-weight:600;color:#666">Last Login</td><td><?= $eu['last_login_at'] ?: 'Never' ?></td></tr>
    <tr><td style="font-weight:600;color:#666">Favorites</td><td><?= $fav_count ?></td></tr>
    <tr><td style="font-weight:600;color:#666">Claim Requests</td><td><?= $claim_count_u ?></td></tr>
    <tr><td style="font-weight:600;color:#666">Owned Providers</td><td><?= $owned_count ?></td></tr>
  </table>
</div>

<!-- Actions Grid -->
<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:16px;margin-bottom:20px">

  <!-- Toggle Verified -->
  <div class="card" style="padding:16px">
    <h3 style="margin:0 0 10px;font-size:14px;color:#666">Email Verification</h3>
    <div style="display:flex;gap:8px;align-items:center">
      <form method="POST" action="?s=users&a=toggle_verified">
        <input type="hidden" name="user_id" value="<?= $eu['id'] ?>">
        <button class="btn btn-sm <?= $eu['is_verified'] ? 'btn-o' : 'btn-p' ?>"><?= $eu['is_verified'] ? 'Mark Unverified' : 'Mark Verified' ?></button>
      </form>
      <?php if (!$eu['is_verified']): ?>
      <form method="POST" action="?s=users&a=resend_verify">
        <input type="hidden" name="user_id" value="<?= $eu['id'] ?>">
        <button class="btn btn-sm btn-o" title="Sends verification email">Resend Verification Email</button>
      </form>
      <?php endif; ?>
    </div>
  </div>

  <!-- Toggle Active -->
  <div class="card" style="padding:16px">
    <h3 style="margin:0 0 10px;font-size:14px;color:#666">Account Status</h3>
    <form method="POST" action="?s=users&a=toggle_user">
      <input type="hidden" name="user_id" value="<?= $eu['id'] ?>">
      <input type="hidden" name="return_edit" value="1">
      <button class="btn btn-sm <?= $eu['is_active'] ? 'btn-o' : 'btn-p' ?>"><?= $eu['is_active'] ? 'Deactivate Account' : 'Activate Account' ?></button>
    </form>
    <p style="margin:8px 0 0;font-size:12px;color:#999"><?= $eu['is_active'] ? 'User can log in and use the site.' : 'User is blocked from logging in.' ?></p>
  </div>

  <!-- Change Role -->
  <div class="card" style="padding:16px">
    <h3 style="margin:0 0 10px;font-size:14px;color:#666">User Role</h3>
    <form method="POST" action="?s=users&a=change_role" style="display:flex;gap:8px;align-items:center">
      <input type="hidden" name="user_id" value="<?= $eu['id'] ?>">
      <select name="role" style="flex:1">
        <option value="user" <?= $eu['role']==='user'?'selected':'' ?>>user</option>
        <option value="provider_owner" <?= $eu['role']==='provider_owner'?'selected':'' ?>>provider_owner</option>
        <option value="admin" <?= $eu['role']==='admin'?'selected':'' ?>>admin</option>
      </select>
      <button class="btn btn-sm btn-p">Update Role</button>
    </form>
  </div>

  <!-- Change Subscription Tier -->
  <div class="card" style="padding:16px">
    <h3 style="margin:0 0 10px;font-size:14px;color:#666">Subscription Tier</h3>
    <form method="POST" action="?s=users&a=change_tier">
      <input type="hidden" name="user_id" value="<?= $eu['id'] ?>">
      <div style="display:flex;gap:8px;margin-bottom:8px">
        <select name="subscription_tier" style="flex:1">
          <option value="free" <?= ($eu['subscription_tier'] ?: 'free')==='free'?'selected':'' ?>>Free</option>
          <option value="basic" <?= $eu['subscription_tier']==='basic'?'selected':'' ?>>Basic</option>
          <option value="premium" <?= $eu['subscription_tier']==='premium'?'selected':'' ?>>Premium</option>
        </select>
        <select name="subscription_period" style="flex:1">
          <option value="">No period</option>
          <option value="monthly" <?= ($eu['subscription_period'] ?? '')==='monthly'?'selected':'' ?>>Monthly</option>
          <option value="annual" <?= ($eu['subscription_period'] ?? '')==='annual'?'selected':'' ?>>Annual</option>
          <option value="lifetime" <?= ($eu['subscription_period'] ?? '')==='lifetime'?'selected':'' ?>>Lifetime</option>
        </select>
      </div>
      <div style="display:flex;gap:8px;align-items:center">
        <label style="font-size:12px;color:#666;white-space:nowrap">Expires</label>
        <input type="datetime-local" name="subscription_expires_at" value="<?= $eu['subscription_expires_at'] ? date('Y-m-d\TH:i', strtotime($eu['subscription_expires_at'])) : '' ?>" style="flex:1">
        <button class="btn btn-sm btn-p">Update Tier</button>
      </div>
    </form>
  </div>

  <!-- Send Password Reset Email -->
  <div class="card" style="padding:16px">
    <h3 style="margin:0 0 10px;font-size:14px;color:#666">Password Reset (Send Email)</h3>
    <form method="POST" action="?s=users&a=send_reset" onsubmit="return confirm('Send a password reset email to <?= htmlspecialchars($eu['email']) ?>?')">
      <input type="hidden" name="user_id" value="<?= $eu['id'] ?>">
      <button class="btn btn-sm btn-o">Send Reset Email</button>
    </form>
    <p style="margin:8px 0 0;font-size:12px;color:#999">Sends a link to their email. They click it and set a new password. Link expires in 1 hour.</p>
  </div>

  <!-- Set Password Manually -->
  <div class="card" style="padding:16px">
    <h3 style="margin:0 0 10px;font-size:14px;color:#666">Set Password Manually</h3>
    <form method="POST" action="?s=users&a=set_password" onsubmit="return confirm('Overwrite this user\'s password?')" style="display:flex;gap:8px;align-items:center">
      <input type="hidden" name="user_id" value="<?= $eu['id'] ?>">
      <input type="text" name="new_password" placeholder="New password (min 8 chars)" required minlength="8" style="flex:1">
      <button class="btn btn-sm btn-p">Set Password</button>
    </form>
    <p style="margin:8px 0 0;font-size:12px;color:#999">Directly sets the password. No email is sent.</p>
  </div>

</div>

<!-- Admin Notes -->
<div class="card" style="margin-bottom:20px;padding:16px">
  <h3 style="margin:0 0 10px;font-size:14px;color:#666">Admin Notes</h3>
  <form method="POST" action="?s=users&a=update_notes">
    <input type="hidden" name="user_id" value="<?= $eu['id'] ?>">
    <textarea name="admin_notes" rows="3" style="width:100%;margin-bottom:8px" placeholder="Internal notes about this user (not visible to them)..."><?= htmlspecialchars($eu['admin_notes'] ?? '') ?></textarea>
    <button class="btn btn-sm btn-p">Save Notes</button>
  </form>
</div>

<!-- Danger Zone -->
<div class="card" style="border:2px solid #e74c3c;padding:16px">
  <h3 style="margin:0 0 10px;font-size:14px;color:#e74c3c">Danger Zone</h3>
  <form method="POST" action="?s=users&a=delete_user" onsubmit="return confirm('PERMANENTLY delete this user and all their data? This cannot be undone.')" style="display:flex;gap:8px;align-items:center">
    <input type="hidden" name="user_id" value="<?= $eu['id'] ?>">
    <input type="text" name="confirm_delete" placeholder="Type DELETE to confirm" style="flex:1;max-width:200px" required>
    <button class="btn btn-sm" style="background:#e74c3c;color:#fff;border:none">Delete User Permanently</button>
  </form>
  <p style="margin:8px 0 0;font-size:12px;color:#e74c3c">Removes user and all their favorites, claims, submissions, and provider ownership records.</p>
</div>

<?php
    // ─── USERS LIST VIEW ───
    else:
    $f_status = $_GET['fs'] ?? '';
    $f_tier = $_GET['ft'] ?? '';
    $q = $_GET['q'] ?? '';
    $where = '1=1'; $params = array();
    if ($q) { $where .= " AND (email LIKE ? OR display_name LIKE ?)"; $params[] = "%{$q}%"; $params[] = "%{$q}%"; }
    if ($f_status === 'active') { $where .= " AND is_active=1"; }
    elseif ($f_status === 'inactive') { $where .= " AND is_active=0"; }
    elseif ($f_status === 'unverified') { $where .= " AND is_verified=0"; }
    if ($f_tier && in_array($f_tier, array('free','basic','premium'))) { $where .= " AND subscription_tier=?"; $params[] = $f_tier; }
    try {
        $stmt = $db->prepare("SELECT * FROM users WHERE {$where} ORDER BY created_at DESC LIMIT 200");
        $stmt->execute($params);
        $users = $stmt->fetchAll();
    } catch (Exception $e) { $users = array(); }
?>
<h1>Users (<?= count($users) ?>)</h1>
<div class="search-bar">
    <form method="GET" style="display:flex;gap:8px;flex-wrap:wrap;width:100%">
        <input type="hidden" name="s" value="users">
        <input type="text" name="q" placeholder="Search email or name..." value="<?= htmlspecialchars($q) ?>" style="flex:1;min-width:140px">
        <select name="fs">
            <option value="">All statuses</option>
            <option value="active" <?= $f_status==='active'?'selected':'' ?>>Active</option>
            <option value="inactive" <?= $f_status==='inactive'?'selected':'' ?>>Inactive</option>
            <option value="unverified" <?= $f_status==='unverified'?'selected':'' ?>>Unverified</option>
        </select>
        <select name="ft">
            <option value="">All tiers</option>
            <option value="free" <?= $f_tier==='free'?'selected':'' ?>>Free</option>
            <option value="basic" <?= $f_tier==='basic'?'selected':'' ?>>Basic</option>
            <option value="premium" <?= $f_tier==='premium'?'selected':'' ?>>Premium</option>
        </select>
        <button class="btn btn-p">Filter</button>
    </form>
</div>
<div class="card">
<table>
    <tr><th>Email</th><th>Name</th><th>Role</th><th>Tier</th><th>Verified</th><th>Status</th><th>Last Login</th><th>Joined</th><th>Actions</th></tr>
    <?php foreach ($users as $u): ?>
    <tr id="user-row-<?= $u['id'] ?>">
        <td><?= htmlspecialchars($u['email']) ?></td>
        <td><?= htmlspecialchars($u['display_name']) ?></td>
        <td><span class="badge <?= $u['role']==='admin'?'b-red':($u['role']==='provider_owner'?'b-blue':'b-green') ?>"><?= $u['role'] ?></span></td>
        <td><span class="badge <?= ($u['subscription_tier'] ?? 'free')==='premium'?'b-blue':(($u['subscription_tier'] ?? 'free')==='basic'?'b-green':'') ?>"><?= $u['subscription_tier'] ?: 'free' ?></span></td>
        <td><?= $u['is_verified'] ? '<span class="badge b-green">Yes</span>' : '<span class="badge b-yellow">No</span>' ?></td>
        <td class="aj-user-status"><?= $u['is_active'] ? '<span class="badge b-green">Active</span>' : '<span class="badge b-red">Inactive</span>' ?></td>
        <td style="color:#888;font-size:12px"><?= $u['last_login_at'] ?: 'Never' ?></td>
        <td style="color:#888;font-size:12px"><?= $u['created_at'] ?></td>
        <td style="white-space:nowrap">
            <a href="?s=users&edit=<?= $u['id'] ?>" class="btn btn-p btn-sm">Manage</a>
            <button type="button" class="btn btn-o btn-sm aj-user-toggle" onclick="ajaxUserToggle(<?= $u['id'] ?>)"><?= $u['is_active'] ? 'Deactivate' : 'Activate' ?></button>
        </td>
    </tr>
    <?php endforeach; ?>
</table>
</div>
<?php endif; ?>

<?php
// ═══════════════════════════════════════════════════════════════
// CLAIMS
// ═══════════════════════════════════════════════════════════════
elseif ($section === 'claims'):
    $f_status = $_GET['fs'] ?? 'pending';
    $where = '1=1'; $params = [];
    if ($f_status) { $where .= " AND cr.status=?"; $params[] = $f_status; }
    try {
        $stmt = $db->prepare("SELECT cr.*, u.email, u.display_name AS user_name, p.name AS provider_name
            FROM claim_requests cr
            JOIN users u ON u.id = cr.user_id
            JOIN providers p ON p.id = cr.provider_id
            WHERE {$where}
            ORDER BY cr.created_at DESC LIMIT 100");
        $stmt->execute($params);
        $claims = $stmt->fetchAll();
    } catch (Exception $e) { $claims = []; }
?>
<h1>Listing Claims (<?= count($claims) ?>)</h1>
<div class="search-bar">
    <a href="?s=claims&fs=pending" class="btn <?= $f_status==='pending'?'btn-p':'btn-o' ?> btn-sm">Pending</a>
    <a href="?s=claims&fs=approved" class="btn <?= $f_status==='approved'?'btn-p':'btn-o' ?> btn-sm">Approved</a>
    <a href="?s=claims&fs=rejected" class="btn <?= $f_status==='rejected'?'btn-p':'btn-o' ?> btn-sm">Rejected</a>
    <a href="?s=claims&fs=" class="btn <?= $f_status===''?'btn-p':'btn-o' ?> btn-sm">All</a>
</div>
<?php foreach ($claims as $cl): ?>
<div class="card" id="claim-card-<?= $cl['id'] ?>">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:10px">
        <div>
            <strong><?= htmlspecialchars($cl['provider_name']) ?></strong>
            <span class="badge aj-claim-badge <?= $cl['status']==='pending'?'b-yellow':($cl['status']==='approved'?'b-green':'b-red') ?>" style="margin-left:8px"><?= $cl['status'] ?></span>
        </div>
        <span style="color:#888;font-size:12px"><?= $cl['created_at'] ?></span>
    </div>
    <table style="margin-bottom:10px">
        <tr><td style="width:120px;font-weight:600">Claimant</td><td><?= htmlspecialchars($cl['user_name']) ?> (<?= htmlspecialchars($cl['email']) ?>)</td></tr>
        <tr><td style="font-weight:600">Role</td><td><?= htmlspecialchars($cl['business_role']) ?></td></tr>
        <tr><td style="font-weight:600">Proof</td><td><?= htmlspecialchars($cl['proof_description']) ?></td></tr>
        <?php if ($cl['contact_phone']): ?><tr><td style="font-weight:600">Phone</td><td><?= htmlspecialchars($cl['contact_phone']) ?></td></tr><?php endif; ?>
        <?php if ($cl['admin_notes']): ?><tr><td style="font-weight:600">Admin notes</td><td><?= htmlspecialchars($cl['admin_notes']) ?></td></tr><?php endif; ?>
    </table>
    <?php if ($cl['status'] === 'pending'): ?>
    <div class="aj-claim-form" style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap">
        <div class="fg" style="flex:1;min-width:200px">
            <label>Admin Notes</label>
            <input type="text" id="claim-notes-<?= $cl['id'] ?>" placeholder="Optional notes...">
        </div>
        <button type="button" onclick="ajaxClaimReview(<?= $cl['id'] ?>,'approved')" class="btn btn-g btn-sm">Approve</button>
        <button type="button" onclick="ajaxClaimReview(<?= $cl['id'] ?>,'rejected')" class="btn btn-r btn-sm">Reject</button>
    </div>
    <?php endif; ?>
</div>
<?php endforeach; ?>
<?php if (empty($claims)): ?>
<div class="card" style="text-align:center;color:#888">No claims found.</div>
<?php endif; ?>

<?php
// ═══════════════════════════════════════════════════════════════
// SUBMISSIONS
// ═══════════════════════════════════════════════════════════════
elseif ($section === 'submissions'):
    $f_status = $_GET['fs'] ?? 'pending';
    $where = '1=1'; $params = [];
    if ($f_status) { $where .= " AND ls.status=?"; $params[] = $f_status; }
    try {
        $stmt = $db->prepare("SELECT ls.*, u.email, u.display_name AS user_name
            FROM listing_submissions ls
            JOIN users u ON u.id = ls.user_id
            WHERE {$where}
            ORDER BY ls.created_at DESC LIMIT 100");
        $stmt->execute($params);
        $subs = $stmt->fetchAll();
    } catch (Exception $e) { $subs = []; }
?>
<h1>New Listing Submissions (<?= count($subs) ?>)</h1>
<div class="search-bar">
    <a href="?s=submissions&fs=pending" class="btn <?= $f_status==='pending'?'btn-p':'btn-o' ?> btn-sm">Pending</a>
    <a href="?s=submissions&fs=approved" class="btn <?= $f_status==='approved'?'btn-p':'btn-o' ?> btn-sm">Approved</a>
    <a href="?s=submissions&fs=rejected" class="btn <?= $f_status==='rejected'?'btn-p':'btn-o' ?> btn-sm">Rejected</a>
    <a href="?s=submissions&fs=" class="btn <?= $f_status===''?'btn-p':'btn-o' ?> btn-sm">All</a>
</div>
<?php foreach ($subs as $sub): ?>
<div class="card" id="sub-card-<?= $sub['id'] ?>">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:10px">
        <div>
            <strong><?= htmlspecialchars($sub['business_name']) ?></strong>
            <span class="badge aj-sub-badge <?= $sub['status']==='pending'?'b-yellow':($sub['status']==='approved'?'b-green':'b-red') ?>" style="margin-left:8px"><?= $sub['status'] ?></span>
        </div>
        <span style="color:#888;font-size:12px"><?= $sub['created_at'] ?></span>
    </div>
    <table style="margin-bottom:10px">
        <tr><td style="width:120px;font-weight:600">Submitted by</td><td><?= htmlspecialchars($sub['user_name']) ?> (<?= htmlspecialchars($sub['email']) ?>)</td></tr>
        <tr><td style="font-weight:600">Group</td><td><?= htmlspecialchars($sub['group_key']) ?></td></tr>
        <tr><td style="font-weight:600">Categories</td><td><?= htmlspecialchars($sub['category_keys']) ?></td></tr>
        <tr><td style="font-weight:600">Area</td><td><?= htmlspecialchars($sub['area_key']) ?></td></tr>
        <tr><td style="font-weight:600">Description</td><td><?= htmlspecialchars($sub['short_description']) ?></td></tr>
        <?php if ($sub['phone']): ?><tr><td style="font-weight:600">Phone</td><td><?= htmlspecialchars($sub['phone']) ?></td></tr><?php endif; ?>
        <?php if ($sub['whatsapp_number']): ?><tr><td style="font-weight:600">WhatsApp</td><td><?= htmlspecialchars($sub['whatsapp_number']) ?></td></tr><?php endif; ?>
        <?php if ($sub['website_url']): ?><tr><td style="font-weight:600">Website</td><td><a href="<?= htmlspecialchars($sub['website_url']) ?>" target="_blank"><?= htmlspecialchars($sub['website_url']) ?></a></td></tr><?php endif; ?>
        <?php if ($sub['google_maps_url']): ?><tr><td style="font-weight:600">Google Maps</td><td><a href="<?= htmlspecialchars($sub['google_maps_url']) ?>" target="_blank">View</a></td></tr><?php endif; ?>
        <?php if ($sub['admin_notes']): ?><tr><td style="font-weight:600">Admin notes</td><td><?= htmlspecialchars($sub['admin_notes']) ?></td></tr><?php endif; ?>
        <?php if ($sub['created_provider_id']): ?><tr><td style="font-weight:600">Provider</td><td><a href="?s=providers&a=edit&id=<?= $sub['created_provider_id'] ?>">View created provider #<?= $sub['created_provider_id'] ?></a></td></tr><?php endif; ?>
    </table>
    <?php if ($sub['status'] === 'pending'): ?>
    <div class="aj-sub-form" style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap">
        <div class="fg" style="flex:1;min-width:200px">
            <label>Admin Notes</label>
            <input type="text" id="sub-notes-<?= $sub['id'] ?>" placeholder="Optional notes...">
        </div>
        <button type="button" onclick="ajaxSubmissionReview(<?= $sub['id'] ?>,'approved')" class="btn btn-g btn-sm">Approve & Create</button>
        <button type="button" onclick="ajaxSubmissionReview(<?= $sub['id'] ?>,'rejected')" class="btn btn-r btn-sm">Reject</button>
    </div>
    <?php endif; ?>
</div>
<?php endforeach; ?>
<?php if (empty($subs)): ?>
<div class="card" style="text-align:center;color:#888">No submissions found.</div>
<?php endif; ?>

<?php
// ═══════════════════════════════════════════════════════════════
// AGENTS
// ═══════════════════════════════════════════════════════════════
elseif ($section === 'agents'):
    $q = $_GET['q'] ?? '';
    $f_verified = $_GET['fv'] ?? '';
    $f_active = $_GET['fa'] ?? '';
    $f_cat = $_GET['fcat'] ?? '';
    $where = '1=1'; $params = [];
    if ($q) { $where .= " AND (u.email LIKE ? OR a.display_name LIKE ? OR a.agency_name LIKE ?)"; $params[] = "%{$q}%"; $params[] = "%{$q}%"; $params[] = "%{$q}%"; }
    if ($f_verified === '1') { $where .= " AND a.is_verified=1"; }
    elseif ($f_verified === '0') { $where .= " AND a.is_verified=0"; }
    if ($f_active === '1') { $where .= " AND a.is_active=1"; }
    elseif ($f_active === '0') { $where .= " AND a.is_active=0"; }
    if ($f_cat) { $where .= " AND EXISTS(SELECT 1 FROM agent_category_map acm WHERE acm.agent_id=a.id AND acm.category_key=?)"; $params[] = $f_cat; }
    $agents = [];
    try {
        $stmt = $db->prepare("
            SELECT a.*, u.email,
                (SELECT COUNT(*) FROM listings pl WHERE pl.agent_id=a.id) AS listings_count
            FROM agents a
            LEFT JOIN users u ON u.id = a.user_id
            WHERE {$where}
            ORDER BY a.created_at DESC LIMIT 200");
        $stmt->execute($params);
        $agents = $stmt->fetchAll();
    } catch (Exception $e) { $agents = []; }
?>
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
    <h1 style="margin:0">Agents (<?= count($agents) ?>)</h1>
</div>
<form class="search-bar" method="GET">
    <input type="hidden" name="s" value="agents">
    <input type="text" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Search email, name, agency..." style="flex:1;min-width:160px">
    <select name="fv">
        <option value="">Any Verification</option>
        <option value="1" <?= $f_verified==='1'?'selected':'' ?>>Verified</option>
        <option value="0" <?= $f_verified==='0'?'selected':'' ?>>Not Verified</option>
    </select>
    <select name="fa">
        <option value="">Any Status</option>
        <option value="1" <?= $f_active==='1'?'selected':'' ?>>Active</option>
        <option value="0" <?= $f_active==='0'?'selected':'' ?>>Inactive</option>
    </select>
    <select name="fcat">
        <option value="">All Categories</option>
        <?php foreach ($agent_cats_sidebar as $ac): ?>
        <option value="<?= $ac['key'] ?>" <?= $f_cat===$ac['key']?'selected':'' ?>><?= htmlspecialchars($ac['label']) ?></option>
        <?php endforeach; ?>
    </select>
    <button class="btn btn-o">Filter</button>
    <?php if ($q || $f_verified !== '' || $f_active !== '' || $f_cat): ?><a href="?s=agents" class="btn btn-o">Clear</a><?php endif; ?>
</form>
<div class="card" style="padding:0;overflow-x:auto">
<table>
    <tr><th style="width:40px">Photo</th><th>ID</th><th>User Email</th><th>Display Name</th><th>Agency Name</th><th>Website</th><th>Phone</th><th>Verified</th><th>Active</th><th>Listings</th><th>Created</th><th>Actions</th></tr>
    <?php foreach ($agents as $ag): ?>
    <tr id="agent-row-<?= $ag['id'] ?>">
        <td>
            <?php if (!empty($ag['profile_photo_url'])): ?>
            <img src="<?= htmlspecialchars($ag['profile_photo_url']) ?>" alt="" style="width:40px;height:40px;border-radius:50%;object-fit:cover;display:block">
            <?php else: ?>
            <div style="width:40px;height:40px;background:#e5e7eb;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:10px;color:#9ca3af">—</div>
            <?php endif; ?>
        </td>
        <td style="color:#888;font-size:12px"><?= $ag['id'] ?></td>
        <td><?= htmlspecialchars($ag['email'] ?? '-') ?></td>
        <td><?= htmlspecialchars($ag['display_name'] ?? '-') ?></td>
        <td><?= htmlspecialchars($ag['agency_name'] ?? '-') ?></td>
        <td style="max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?php if (!empty($ag['website_url'])): ?><a href="<?= htmlspecialchars($ag['website_url']) ?>" target="_blank" title="<?= htmlspecialchars($ag['website_url']) ?>" style="color:#0c7c84"><?= htmlspecialchars(preg_replace('#^https?://(www\.)?#','', $ag['website_url'])) ?></a><?php else: ?>-<?php endif; ?></td>
        <td><?= htmlspecialchars($ag['phone'] ?? '-') ?></td>
        <td class="aj-agent-verified"><?= $ag['is_verified'] ? '<span class="badge b-green">Y</span>' : '<span class="badge b-red">N</span>' ?></td>
        <td class="aj-agent-active"><?= $ag['is_active'] ? '<span class="badge b-green">Y</span>' : '<span class="badge b-red">N</span>' ?></td>
        <td style="text-align:center"><?= (int)$ag['listings_count'] ?></td>
        <td style="color:#888;font-size:12px"><?= $ag['created_at'] ?? '-' ?></td>
        <td class="actions" style="white-space:nowrap">
            <button type="button" class="btn btn-o btn-sm aj-agent-verify-btn" onclick="ajaxAgentToggle('agent_toggle_verified',<?= $ag['id'] ?>)" title="Toggle verified"><?= $ag['is_verified'] ? 'Unverify' : 'Verify' ?></button>
            <button type="button" class="btn btn-o btn-sm aj-agent-active-btn" onclick="ajaxAgentToggle('agent_toggle_active',<?= $ag['id'] ?>)"><?= $ag['is_active'] ? 'Deactivate' : 'Activate' ?></button>
            <?php if (!empty($ag['user_id'])): ?>
            <a href="?s=users&q=<?= urlencode($ag['email'] ?? '') ?>" class="btn btn-o btn-sm" title="View user account">User</a>
            <?php endif; ?>
        </td>
    </tr>
    <?php endforeach; ?>
</table>
</div>
<?php if (empty($agents)): ?>
<div class="card" style="text-align:center;color:#888">No agents found.</div>
<?php endif; ?>

<?php
// ═══════════════════════════════════════════════════════════════
// LISTINGS
// ═══════════════════════════════════════════════════════════════
elseif ($section === 'listings'):
    $q = $_GET['q'] ?? '';
    $f_status = $_GET['fs'] ?? '';
    $f_approved = $_GET['fap'] ?? '';
    $f_region = $_GET['frg'] ?? '';
    $f_area = $_GET['fa'] ?? '';
    $where = '1=1'; $params = [];
    if ($q) { $where .= " AND (pl.title LIKE ? OR pl.listing_type LIKE ?)"; $params[] = "%{$q}%"; $params[] = "%{$q}%"; }
    if ($f_status) { $where .= " AND pl.status=?"; $params[] = $f_status; }
    if ($f_approved === '1') { $where .= " AND pl.is_approved=1"; }
    elseif ($f_approved === '0') { $where .= " AND pl.is_approved=0"; }
    if ($f_region) { $where .= " AND ar.region_key=?"; $params[] = $f_region; }
    if ($f_area) { $where .= " AND pl.area_key=?"; $params[] = $f_area; }
    $listings = [];
    try {
        $stmt = $db->prepare("
            SELECT pl.*,
                a.display_name AS agent_name,
                ar.label AS area_label,
                (SELECT url FROM listing_images li WHERE li.listing_id=pl.id AND li.is_primary=1 LIMIT 1) AS primary_image
            FROM listings pl
            LEFT JOIN agents a ON a.id = pl.agent_id
            LEFT JOIN areas ar ON ar.`key` = pl.area_key
            WHERE {$where}
            ORDER BY pl.created_at DESC LIMIT 200");
        $stmt->execute($params);
        $listings = $stmt->fetchAll();
    } catch (Exception $e) { $listings = []; }
    // Agents list for edit dropdown
    $all_agents = array();
    try { $all_agents = $db->query("SELECT id, display_name FROM agents ORDER BY display_name ASC")->fetchAll(); } catch (Exception $e) {}
    // Status badge colour helper
    $status_badge = array(
        'draft'      => 'b-yellow',
        'active'     => 'b-green',
        'under_offer'=> 'b-blue',
        'sold'       => 'b-red',
        'expired'    => 'b-red',
    );
?>
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
    <h1 style="margin:0">Listings (<?= count($listings) ?>)</h1>
</div>
<form class="search-bar" method="GET">
    <input type="hidden" name="s" value="listings">
    <input type="text" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Search title or type..." style="flex:1;min-width:160px">
    <select name="fs">
        <option value="">All Statuses</option>
        <option value="draft" <?= $f_status==='draft'?'selected':'' ?>>Draft</option>
        <option value="active" <?= $f_status==='active'?'selected':'' ?>>Active</option>
        <option value="under_offer" <?= $f_status==='under_offer'?'selected':'' ?>>Under Offer</option>
        <option value="sold" <?= $f_status==='sold'?'selected':'' ?>>Sold</option>
        <option value="expired" <?= $f_status==='expired'?'selected':'' ?>>Expired</option>
    </select>
    <select name="fap">
        <option value="">Approved: All</option>
        <option value="1" <?= $f_approved==='1'?'selected':'' ?>>Approved: Yes</option>
        <option value="0" <?= $f_approved==='0'?'selected':'' ?>>Approved: No</option>
    </select>
    <select name="frg" id="lst-frg" onchange="lstRegionChanged()">
        <option value="">All Regions</option>
        <?php foreach ($regions_list as $rg): ?><option value="<?= htmlspecialchars($rg['region_key']) ?>" <?= $f_region===$rg['region_key']?'selected':'' ?>><?= htmlspecialchars($rg['label']) ?></option><?php endforeach; ?>
    </select>
    <select name="fa" id="lst-fa">
        <option value="">All Areas</option>
        <?php foreach ($areas_list as $a): ?><option value="<?= $a['key'] ?>" data-region="<?= htmlspecialchars($a['region_key'] ?? '') ?>" <?= $f_area===$a['key']?'selected':'' ?>><?= htmlspecialchars($a['label']) ?></option><?php endforeach; ?>
    </select>
    <button class="btn btn-o">Filter</button>
    <?php if ($q || $f_status || $f_approved !== '' || $f_region || $f_area): ?><a href="?s=listings" class="btn btn-o">Clear</a><?php endif; ?>
</form>
<div class="card" style="padding:0;overflow-x:auto">
<table class="tbl" style="font-size:13px">
<thead>
    <tr><th style="width:36px"></th><th>Title / Source</th><th>Area</th><th>Agent</th><th>Land</th><th>Price</th><th>Status</th><th style="width:60px;text-align:center">Appr</th><th style="width:50px;text-align:center">Feat</th><th style="text-align:right">Actions</th></tr>
</thead>
<tbody>
    <?php foreach ($listings as $lst): ?>
    <tr id="lst-row-<?= $lst['id'] ?>">
        <td>
            <?php if (!empty($lst['primary_image'])): ?>
            <img src="<?= htmlspecialchars($lst['primary_image']) ?>" alt="" style="width:36px;height:28px;object-fit:cover;border-radius:3px;display:block">
            <?php else: ?>
            <div style="width:36px;height:28px;background:#e5e7eb;border-radius:3px;display:flex;align-items:center;justify-content:center;font-size:9px;color:#9ca3af">—</div>
            <?php endif; ?>
        </td>
        <td>
            <?php if (!empty($lst['source_url'])): ?>
            <a href="<?= htmlspecialchars($lst['source_url']) ?>" target="_blank" rel="noopener" title="Open on <?= htmlspecialchars($lst['source_site'] ?? 'source') ?>" style="color:#0c7c84;text-decoration:none;font-weight:500"><?= htmlspecialchars(mb_strimwidth($lst['title'] ?? '-', 0, 50, '...')) ?></a>
            <?php else: ?>
            <span style="font-weight:500"><?= htmlspecialchars(mb_strimwidth($lst['title'] ?? '-', 0, 50, '...')) ?></span>
            <?php endif; ?>
            <div style="font-size:11px;color:#94a3b8;margin-top:1px">#<?= $lst['id'] ?> · <?= htmlspecialchars($lst['listing_type'] ?? $lst['listing_type_key'] ?? '-') ?> · <?= isset($lst['created_at']) ? substr($lst['created_at'],0,10) : '' ?></div>
        </td>
        <td style="font-size:12px"><?= htmlspecialchars($lst['area_label'] ?? '-') ?></td>
        <td style="font-size:12px"><?= htmlspecialchars($lst['agent_name'] ?? 'Private Seller') ?></td>
        <td style="font-size:12px;white-space:nowrap"><?= $lst['land_size_are'] ? number_format((float)$lst['land_size_are'],0) . ' are' : ($lst['land_size_sqm'] ? number_format((float)$lst['land_size_sqm']) . ' m²' : '-') ?></td>
        <td style="font-size:12px;white-space:nowrap"><?= $lst['price_usd'] ? '$' . number_format((float)$lst['price_usd']) : ($lst['price_idr'] ? 'Rp ' . number_format((float)$lst['price_idr'],0,',','.') : '-') ?></td>
        <td>
            <select onchange="ajaxListingStatus(this,<?= $lst['id'] ?>)" style="padding:2px 4px;font-size:11px;border:1px solid #d0d0d0;border-radius:4px;cursor:pointer;background:<?= $lst['status']==='active'?'#dcfce7':($lst['status']==='sold'||$lst['status']==='expired'?'#fee2e2':'#fef9c3') ?>">
                    <option value="draft" <?= $lst['status']==='draft'?'selected':'' ?>>draft</option>
                    <option value="active" <?= $lst['status']==='active'?'selected':'' ?>>active</option>
                    <option value="under_offer" <?= $lst['status']==='under_offer'?'selected':'' ?>>under_offer</option>
                    <option value="sold" <?= $lst['status']==='sold'?'selected':'' ?>>sold</option>
                    <option value="expired" <?= $lst['status']==='expired'?'selected':'' ?>>expired</option>
                </select>
        </td>
        <td style="text-align:center">
            <button type="button" class="aj-appr-btn" onclick="ajaxListingApprove(<?= $lst['id'] ?>,<?= $lst['is_approved'] ? 'true' : 'false' ?>)" style="background:none;border:none;cursor:pointer;font-size:16px" title="<?= $lst['is_approved'] ? 'Click to reject' : 'Click to approve' ?>"><?= $lst['is_approved'] ? '✅' : '❌' ?></button>
        </td>
        <td style="text-align:center">
            <button type="button" class="aj-feat-btn" onclick="ajaxListingFeatured(<?= $lst['id'] ?>)" style="background:none;border:none;cursor:pointer;font-size:16px" title="<?= !empty($lst['is_featured']) ? 'Unfeature' : 'Feature' ?>"><?= !empty($lst['is_featured']) ? '⭐' : '☆' ?></button>
        </td>
        <td style="text-align:right;white-space:nowrap">
            <button type="button" class="btn btn-o btn-sm" onclick="toggleListingEdit(<?= $lst['id'] ?>)" style="font-size:11px;padding:3px 10px">Edit</button>
            <button type="button" class="btn btn-r btn-sm" onclick="ajaxListingDelete(<?= $lst['id'] ?>)" style="font-size:11px;padding:3px 8px">Del</button>
        </td>
    </tr>
    <!-- Edit row -->
    <tr id="lst-edit-<?= $lst['id'] ?>" style="display:none;background:rgba(12,124,132,.04)">
        <td colspan="10">
            <div style="padding:12px 4px">
                <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px 16px;margin-bottom:10px">
                    <div><label style="font-size:11px;display:block;margin-bottom:2px;color:#64748b">Title</label><input type="text" name="title" value="<?= htmlspecialchars($lst['title'] ?? '') ?>" style="width:100%;padding:5px 8px;border:1px solid #d0d0d0;border-radius:4px;font-size:12px"></div>
                    <div><label style="font-size:11px;display:block;margin-bottom:2px;color:#64748b">Type</label><input type="text" name="listing_type" value="<?= htmlspecialchars($lst['listing_type'] ?? $lst['listing_type_key'] ?? '') ?>" style="width:100%;padding:5px 8px;border:1px solid #d0d0d0;border-radius:4px;font-size:12px"></div>
                    <div><label style="font-size:11px;display:block;margin-bottom:2px;color:#64748b">Area</label>
                        <select name="area_key" style="width:100%;padding:5px 8px;border:1px solid #d0d0d0;border-radius:4px;font-size:12px">
                            <option value="">—</option>
                            <?php foreach ($areas_list as $a): ?><option value="<?= $a['key'] ?>" <?= ($lst['area_key'] ?? '')===$a['key']?'selected':'' ?>><?= htmlspecialchars($a['label']) ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div><label style="font-size:11px;display:block;margin-bottom:2px;color:#64748b">Agent</label>
                        <select name="agent_id" style="width:100%;padding:5px 8px;border:1px solid #d0d0d0;border-radius:4px;font-size:12px">
                            <option value="">— No Agent —</option>
                            <?php foreach ($all_agents as $ag): ?><option value="<?= $ag['id'] ?>" <?= ((int)($lst['agent_id'] ?? 0))===(int)$ag['id']?'selected':'' ?>><?= htmlspecialchars($ag['display_name']) ?> (#<?= $ag['id'] ?>)</option><?php endforeach; ?>
                        </select>
                    </div>
                    <div><label style="font-size:11px;display:block;margin-bottom:2px;color:#64748b">Price USD</label><input type="number" name="price_usd" value="<?= htmlspecialchars($lst['price_usd'] ?? '') ?>" data-lst-id="<?= $lst['id'] ?>" data-currency="usd" onchange="lstPriceConvert(this)" style="width:100%;padding:5px 8px;border:1px solid #d0d0d0;border-radius:4px;font-size:12px"></div>
                    <div><label style="font-size:11px;display:block;margin-bottom:2px;color:#64748b">Price IDR</label><input type="number" name="price_idr" value="<?= htmlspecialchars($lst['price_idr'] ?? '') ?>" data-lst-id="<?= $lst['id'] ?>" data-currency="idr" onchange="lstPriceConvert(this)" style="width:100%;padding:5px 8px;border:1px solid #d0d0d0;border-radius:4px;font-size:12px"></div>
                    <div><label style="font-size:11px;display:block;margin-bottom:2px;color:#64748b">Price EUR</label><input type="number" name="price_eur" value="<?= htmlspecialchars($lst['price_eur'] ?? '') ?>" data-lst-id="<?= $lst['id'] ?>" data-currency="eur" onchange="lstPriceConvert(this)" style="width:100%;padding:5px 8px;border:1px solid #d0d0d0;border-radius:4px;font-size:12px"></div>
                    <div><label style="font-size:11px;display:block;margin-bottom:2px;color:#64748b">Price AUD</label><input type="number" name="price_aud" value="<?= htmlspecialchars($lst['price_aud'] ?? '') ?>" data-lst-id="<?= $lst['id'] ?>" data-currency="aud" onchange="lstPriceConvert(this)" style="width:100%;padding:5px 8px;border:1px solid #d0d0d0;border-radius:4px;font-size:12px"></div>
                    <div><label style="font-size:11px;display:block;margin-bottom:2px;color:#64748b">Land (m²)</label><input type="number" name="land_size_sqm" value="<?= htmlspecialchars($lst['land_size_sqm'] ?? '') ?>" style="width:100%;padding:5px 8px;border:1px solid #d0d0d0;border-radius:4px;font-size:12px"></div>
                    <div><label style="font-size:11px;display:block;margin-bottom:2px;color:#64748b">Land (are)</label><input type="number" name="land_size_are" value="<?= htmlspecialchars($lst['land_size_are'] ?? '') ?>" step="0.01" style="width:100%;padding:5px 8px;border:1px solid #d0d0d0;border-radius:4px;font-size:12px"></div>
                    <div><label style="font-size:11px;display:block;margin-bottom:2px;color:#64748b">Building (m²)</label><input type="number" name="building_size_sqm" value="<?= htmlspecialchars($lst['building_size_sqm'] ?? '') ?>" style="width:100%;padding:5px 8px;border:1px solid #d0d0d0;border-radius:4px;font-size:12px"></div>
                    <div><label style="font-size:11px;display:block;margin-bottom:2px;color:#64748b">Bedrooms</label><input type="number" name="bedrooms" value="<?= htmlspecialchars($lst['bedrooms'] ?? '') ?>" style="width:100%;padding:5px 8px;border:1px solid #d0d0d0;border-radius:4px;font-size:12px"></div>
                    <div><label style="font-size:11px;display:block;margin-bottom:2px;color:#64748b">Bathrooms</label><input type="number" name="bathrooms" value="<?= htmlspecialchars($lst['bathrooms'] ?? '') ?>" style="width:100%;padding:5px 8px;border:1px solid #d0d0d0;border-radius:4px;font-size:12px"></div>
                    <div><label style="font-size:11px;display:block;margin-bottom:2px;color:#64748b">WhatsApp</label><input type="text" name="contact_whatsapp" value="<?= htmlspecialchars($lst['contact_whatsapp'] ?? '') ?>" style="width:100%;padding:5px 8px;border:1px solid #d0d0d0;border-radius:4px;font-size:12px"></div>
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px 16px;margin-bottom:10px">
                    <div><label style="font-size:11px;display:block;margin-bottom:2px;color:#64748b">Source URL</label><input type="url" name="source_url" value="<?= htmlspecialchars($lst['source_url'] ?? '') ?>" style="width:100%;padding:5px 8px;border:1px solid #d0d0d0;border-radius:4px;font-size:12px"></div>
                    <div><label style="font-size:11px;display:block;margin-bottom:2px;color:#64748b">Short Description</label><input type="text" name="short_description" value="<?= htmlspecialchars($lst['short_description'] ?? '') ?>" style="width:100%;padding:5px 8px;border:1px solid #d0d0d0;border-radius:4px;font-size:12px"></div>
                </div>
                <div style="margin-bottom:10px"><label style="font-size:11px;display:block;margin-bottom:2px;color:#64748b">Admin Notes</label><textarea name="admin_notes" rows="2" style="width:100%;padding:5px 8px;border:1px solid #d0d0d0;border-radius:4px;font-size:12px;resize:vertical"><?= htmlspecialchars($lst['admin_notes'] ?? '') ?></textarea></div>
                <div style="display:flex;gap:8px">
                    <button type="button" class="btn btn-p" onclick="ajaxListingEdit(<?= $lst['id'] ?>)" style="font-size:12px;padding:5px 16px">Save Changes</button>
                    <button type="button" class="btn btn-o" onclick="toggleListingEdit(<?= $lst['id'] ?>)" style="font-size:12px;padding:5px 12px">Cancel</button>
                </div>
            </div>
        </td>
    </tr>
    <?php endforeach; ?>
</tbody>
</table>
</div>
<script>
var toggleListingEdit = function(id) {
    var row = document.getElementById('lst-edit-' + id);
    if (row) row.style.display = row.style.display === 'none' ? '' : 'none';
};
var lstRegionChanged = function() {
    var rgSel = document.getElementById('lst-frg');
    var areaSel = document.getElementById('lst-fa');
    var rg = rgSel.value;
    var opts = areaSel.options;
    areaSel.value = '';
    for (var i = 1; i < opts.length; i++) {
        if (!rg || opts[i].getAttribute('data-region') === rg) {
            opts[i].style.display = '';
        } else {
            opts[i].style.display = 'none';
        }
    }
};
lstRegionChanged();
</script>
<?php if (empty($listings)): ?>
<div class="card" style="text-align:center;color:#888">No listings found.</div>
<?php endif; ?>

<?php
// ═══════════════════════════════════════════════════════════════
// BATCH ENRICH — Client-side workflow: admin searches manually, pastes data
// ═══════════════════════════════════════════════════════════════
elseif ($section === 'batch_enrich'):
?>
<style>
.be-card{background:#fff;border:1px solid #e0e0e0;border-radius:8px;margin-bottom:10px;overflow:hidden}
.be-header{display:flex;align-items:center;justify-content:space-between;padding:10px 14px;cursor:pointer;user-select:none;gap:8px}
.be-header:hover{background:#fafaf8}
.be-name{font-weight:600;font-size:14px;flex:1}
.be-meta{font-size:12px;color:#888;display:flex;gap:10px;align-items:center}
.be-detail{display:none;padding:0 14px 14px;border-top:1px solid #f0f0f0}
.be-detail.open{display:block}
.be-existing{font-size:12px;color:#888;margin:8px 0 12px;line-height:1.6}
.be-existing b{color:#444}
.be-links{display:flex;flex-wrap:wrap;gap:6px;margin-bottom:14px}
.be-links a{display:inline-block;padding:5px 10px;font-size:12px;border-radius:5px;text-decoration:none;color:#fff;font-weight:500}
.be-links a.l-gs{background:#4285f4}
.be-links a.l-gi{background:#ea4335}
.be-links a.l-gm{background:#34a853}
.be-links a.l-ig{background:linear-gradient(45deg,#f09433,#e6683c,#dc2743,#cc2366,#bc1888)}
.be-links a.l-fb{background:#1877f2}
.be-fields{display:grid;grid-template-columns:120px 1fr;gap:6px 10px;align-items:center;font-size:13px}
.be-fields label{font-weight:500;color:#555;text-align:right}
.be-fields input{padding:5px 8px;border:1px solid #d0d0d0;border-radius:5px;font-size:13px}
.be-actions{display:flex;gap:8px;margin-top:12px;align-items:center}
.be-actions .be-msg{font-size:12px;margin-left:8px}
</style>

<h1>Batch Enrich Tool</h1>
<p style="color:#666;margin-bottom:16px;font-size:13px">
    Finds entities with Google reviews but no profile image. Click search links to find info in your browser, then paste URLs back and save.
</p>
<div style="display:flex;gap:8px;align-items:center;margin-bottom:16px">
    <label style="font-size:13px;font-weight:600">Min reviews:</label>
    <input type="number" id="be-min-reviews" value="10" min="1" style="width:80px;padding:5px 8px;font-size:13px;border:1px solid #d0d0d0;border-radius:5px">
    <button class="btn btn-p" id="be-find-btn" onclick="beFindMissing()">Find Entities</button>
    <span id="be-status" style="font-size:13px;color:#666"></span>
</div>
<div id="be-cards"></div>
<div id="be-empty" class="card" style="display:none;text-align:center;color:#888">No entities found matching criteria.</div>

<script>
var beEntities = [];

function beFindMissing() {
    var minReviews = parseInt(document.getElementById('be-min-reviews').value) || 10;
    var btn = document.getElementById('be-find-btn');
    var status = document.getElementById('be-status');
    btn.disabled = true;
    btn.textContent = 'Searching\u2026';
    status.textContent = '';
    document.getElementById('be-cards').innerHTML = '';
    document.getElementById('be-empty').style.display = 'none';

    fetch('google_enrich.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({action: 'find_missing', min_reviews: minReviews})
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        btn.disabled = false;
        btn.textContent = 'Find Entities';
        if (data.error) { status.textContent = 'Error: ' + data.error; return; }
        beEntities = data.entities || [];
        if (beEntities.length === 0) {
            document.getElementById('be-empty').style.display = 'block';
            status.textContent = 'No matching entities.';
            return;
        }
        status.textContent = 'Found ' + beEntities.length + ' entit' + (beEntities.length === 1 ? 'y' : 'ies') + '.';
        beRenderCards();
    })
    .catch(function(err) {
        btn.disabled = false;
        btn.textContent = 'Find Entities';
        status.textContent = 'Network error: ' + err.message;
    });
}

function beRenderCards() {
    var container = document.getElementById('be-cards');
    var html = '';
    for (var i = 0; i < beEntities.length; i++) {
        var e = beEntities[i];
        var q = encodeURIComponent(e.name + ' Lombok');
        var existing = '';

        if (e.google_maps_url) existing += '<b>Maps:</b> <a href="' + e.google_maps_url + '" target="_blank">' + e.google_maps_url.substring(0, 60) + '</a><br>';
        if (e.instagram_url) existing += '<b>Instagram:</b> ' + e.instagram_url + '<br>';
        if (e.facebook_url) existing += '<b>Facebook:</b> ' + e.facebook_url + '<br>';
        if (e.phone) existing += '<b>Phone:</b> ' + e.phone + '<br>';
        if (e.logo_url) existing += '<b>Logo:</b> ' + e.logo_url + '<br>';

        html += '<div class="be-card" id="be-card-' + i + '">';
        html += '<div class="be-header" onclick="beToggle(' + i + ')">';
        html += '<span class="badge b-blue" style="margin-right:6px">' + e.entity_type + '</span>';
        var editSection = e.entity_type === 'provider' ? 'providers' : (e.entity_type === 'developer' ? 'developers' : 'agents');
        html += '<a class="be-name" href="console.php?s=' + editSection + '&a=edit&id=' + e.id + '" target="_blank" onclick="event.stopPropagation()" style="color:#0c7c84;text-decoration:underline">' + (e.name || 'ID ' + e.id) + '</a>';
        html += '<span class="be-meta"><span>\u2605' + (e.google_rating || '-') + '</span><span>' + (e.google_review_count || 0) + ' reviews</span></span>';
        html += '<span style="font-size:18px;color:#aaa" id="be-arrow-' + i + '">&#9654;</span>';
        html += '</div>';
        html += '<div class="be-detail" id="be-detail-' + i + '">';
        if (existing) html += '<div class="be-existing">' + existing + '</div>';
        html += '<div style="font-size:12px;font-weight:600;color:#555;margin-bottom:6px">Search (opens in new tab):</div>';
        html += '<div class="be-links">';
        html += '<a class="l-gs" href="https://www.google.com/search?q=' + q + '" target="_blank">Google Search</a>';
        html += '<a class="l-gi" href="https://www.google.com/search?tbm=isch&q=' + q + '" target="_blank">Google Images</a>';
        html += '<a class="l-gm" href="https://www.google.com/maps/search/' + q + '" target="_blank">Google Maps</a>';
        html += '<a class="l-ig" href="https://www.google.com/search?q=site:instagram.com+' + q + '" target="_blank">Instagram</a>';
        html += '<a class="l-fb" href="https://www.google.com/search?q=site:facebook.com+' + q + '" target="_blank">Facebook</a>';
        html += '</div>';
        html += '<div style="font-size:12px;font-weight:600;color:#555;margin-bottom:6px">Paste found info:</div>';
        html += '<div class="be-fields">';
        html += '<label>Profile Image</label><input type="text" id="be-f-' + i + '-profile_photo_url" placeholder="Image URL">';
        html += '<label>Instagram</label><input type="text" id="be-f-' + i + '-instagram_url" placeholder="https://instagram.com/..." value="' + (e.instagram_url || '') + '">';
        html += '<label>Facebook</label><input type="text" id="be-f-' + i + '-facebook_url" placeholder="https://facebook.com/..." value="' + (e.facebook_url || '') + '">';
        html += '<label>Logo</label><input type="text" id="be-f-' + i + '-logo_url" placeholder="Logo image URL" value="' + (e.logo_url || '') + '">';
        html += '<label>Hero Image</label><input type="text" id="be-f-' + i + '-hero_image_url" placeholder="Banner / hero image URL" value="' + (e.hero_image_url || '') + '">';
        html += '<label>Image 2</label><input type="text" id="be-f-' + i + '-image_url_2" placeholder="Additional image" value="' + (e.image_url_2 || '') + '">';
        html += '<label>Image 3</label><input type="text" id="be-f-' + i + '-image_url_3" placeholder="Additional image" value="' + (e.image_url_3 || '') + '">';
        html += '<label>Image 4</label><input type="text" id="be-f-' + i + '-image_url_4" placeholder="Additional image" value="' + (e.image_url_4 || '') + '">';
        html += '<label>Phone</label><input type="text" id="be-f-' + i + '-phone" placeholder="+62..." value="' + (e.phone || '') + '">';
        html += '<label>Website</label><input type="text" id="be-f-' + i + '-website_url" placeholder="https://..." value="' + (e.website_url || '') + '">';
        html += '</div>';
        html += '<div class="be-actions">';
        html += '<button class="btn btn-p btn-sm" onclick="beSave(' + i + ')">Save</button>';
        html += '<button class="btn btn-sm" style="background:#eee;color:#555" onclick="beSkip(' + i + ')">Skip</button>';
        html += '<span class="be-msg" id="be-msg-' + i + '"></span>';
        html += '</div>';
        html += '</div></div>';
    }
    container.innerHTML = html;
    /* Auto-open first card */
    if (beEntities.length > 0) beToggle(0);
}

function beToggle(idx) {
    var detail = document.getElementById('be-detail-' + idx);
    var arrow = document.getElementById('be-arrow-' + idx);
    if (detail.classList.contains('open')) {
        detail.classList.remove('open');
        arrow.innerHTML = '&#9654;';
    } else {
        detail.classList.add('open');
        arrow.innerHTML = '&#9660;';
    }
}

function beSave(idx) {
    var e = beEntities[idx];
    var msg = document.getElementById('be-msg-' + idx);
    var fieldNames = ['profile_photo_url','instagram_url','facebook_url','logo_url','hero_image_url','image_url_2','image_url_3','image_url_4','phone','website_url'];
    var fields = {};
    var count = 0;
    for (var f = 0; f < fieldNames.length; f++) {
        var val = document.getElementById('be-f-' + idx + '-' + fieldNames[f]).value.trim();
        if (val) { fields[fieldNames[f]] = val; count++; }
    }
    if (count === 0) { msg.innerHTML = '<span style="color:#d4604a">No fields filled in</span>'; return; }
    msg.innerHTML = '<span style="color:#888">Saving\u2026</span>';

    fetch('google_enrich.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({action: 'quick_save', entity_type: e.entity_type, entity_id: e.id, fields: fields})
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.error) { msg.innerHTML = '<span style="color:#d4604a">' + data.error + '</span>'; return; }
        msg.innerHTML = '<span style="color:#16a34a">\u2713 Saved ' + data.saved + ' field(s)</span>';
        document.getElementById('be-card-' + idx).style.borderColor = '#16a34a';
        /* Auto-open next card */
        if (idx + 1 < beEntities.length) {
            var nextDetail = document.getElementById('be-detail-' + (idx + 1));
            if (!nextDetail.classList.contains('open')) beToggle(idx + 1);
        }
    })
    .catch(function(err) {
        msg.innerHTML = '<span style="color:#d4604a">Network error</span>';
    });
}

function beSkip(idx) {
    document.getElementById('be-card-' + idx).style.opacity = '0.4';
    document.getElementById('be-detail-' + idx).classList.remove('open');
    document.getElementById('be-arrow-' + idx).innerHTML = '&#9654;';
    if (idx + 1 < beEntities.length) {
        var nextDetail = document.getElementById('be-detail-' + (idx + 1));
        if (!nextDetail.classList.contains('open')) beToggle(idx + 1);
    }
}
</script>

<?php
// ═══════════════════════════════════════════════════════════════
// REVIEW UPDATE LOG
// ═══════════════════════════════════════════════════════════════
elseif ($section === 'review_updates'):
    $review_log = [];
    try {
        $review_log = $db->query("
            SELECT * FROM review_update_log
            ORDER BY checked_at DESC
            LIMIT 200
        ")->fetchAll();
    } catch (Exception $e) { $review_log = []; }
?>
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
    <h1 style="margin:0">Review Update Log (<?= count($review_log) ?>)</h1>
    <button class="btn btn-p" id="trigger-review-check" onclick="triggerReviewCheck(this)">Run Review Check (All)</button>
</div>
<p style="color:#666;margin-bottom:16px;font-size:13px">Log of rating/review count changes detected by the automated review checker. Use the button above to trigger a fresh check.</p>
<div class="card" style="padding:0;overflow-x:auto">
<table>
    <tr><th>ID</th><th>Entity Type</th><th>Entity ID</th><th>Old Rating</th><th>New Rating</th><th>Old Count</th><th>New Count</th><th>Source</th><th>Checked At</th></tr>
    <?php foreach ($review_log as $rl): ?>
    <tr>
        <td style="color:#888;font-size:12px"><?= $rl['id'] ?></td>
        <td><span class="badge b-blue"><?= htmlspecialchars($rl['entity_type'] ?? '-') ?></span></td>
        <td><?= htmlspecialchars($rl['entity_id'] ?? '-') ?></td>
        <td><?= $rl['old_rating'] !== null ? htmlspecialchars($rl['old_rating']) : '<span style="color:#aaa">—</span>' ?></td>
        <td><?php
            $old = $rl['old_rating'];
            $new = $rl['new_rating'];
            $changed = ($old !== null && $new !== null && (float)$old !== (float)$new);
            echo $new !== null ? '<span'.($changed?' style="font-weight:600;color:#16a34a"':'').'>'.htmlspecialchars($new).'</span>' : '<span style="color:#aaa">—</span>';
        ?></td>
        <td><?= $rl['old_count'] !== null ? htmlspecialchars($rl['old_count']) : '<span style="color:#aaa">—</span>' ?></td>
        <td><?php
            $oc = $rl['old_count'];
            $nc = $rl['new_count'];
            $cnt_changed = ($oc !== null && $nc !== null && (int)$oc !== (int)$nc);
            echo $nc !== null ? '<span'.($cnt_changed?' style="font-weight:600;color:#16a34a"':'').'>'.htmlspecialchars($nc).'</span>' : '<span style="color:#aaa">—</span>';
        ?></td>
        <td><?= htmlspecialchars($rl['source'] ?? '-') ?></td>
        <td style="color:#888;font-size:12px;white-space:nowrap"><?= htmlspecialchars($rl['checked_at'] ?? '-') ?></td>
    </tr>
    <?php endforeach; ?>
</table>
</div>
<?php if (empty($review_log)): ?>
<div class="card" style="text-align:center;color:#888">No review update log entries found.</div>
<?php endif; ?>
<script>
function triggerReviewCheck(btn) {
    btn.disabled = true;
    btn.textContent = 'Running…';
    fetch('../api/reviews.php', {method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({action:'check_all'})})
        .then(function(r) { return r.json(); })
        .then(function(data) {
            btn.textContent = 'Done — reload to see results';
            btn.disabled = false;
        })
        .catch(function(err) {
            btn.textContent = 'Error — check console';
            btn.disabled = false;
            console.error(err);
        });
}
</script>

<?php
// ═══════════════════════════════════════════════════════════════
// SUBSCRIPTIONS MANAGEMENT (INLINE)
// ═══════════════════════════════════════════════════════════════
elseif ($section === 'subscriptions'):
    $sub_users_inline = [];
    try {
        $sub_users_inline = $db->query("SELECT id, email, display_name, role, subscription_tier, subscription_period, subscription_started_at, subscription_expires_at, subscription_auto_renew, is_active, created_at FROM users ORDER BY subscription_tier DESC, display_name ASC")->fetchAll();
    } catch (Exception $e) { $sub_users_inline = []; }
?>
<h1>User Subscriptions</h1>
<p style="color:#64748b;margin-bottom:16px">Manage user subscription tiers, periods, and expiration dates.</p>
<table class="tbl">
<thead><tr><th>ID</th><th>User</th><th>Email</th><th>Role</th><th>Tier</th><th>Period</th><th>Expires</th><th>Auto-Renew</th><th>Actions</th></tr></thead>
<tbody>
<?php foreach ($sub_users_inline as $su): ?>
<tr>
    <td><?= $su['id'] ?></td>
    <td><?= htmlspecialchars($su['display_name']) ?></td>
    <td style="font-size:12px"><?= htmlspecialchars($su['email']) ?></td>
    <td><span style="background:<?= $su['role']==='admin'?'#dc2626':($su['role']==='provider_owner'?'#2563eb':'#64748b') ?>;color:#fff;padding:2px 8px;border-radius:4px;font-size:11px"><?= $su['role'] ?></span></td>
    <td class="aj-sub-tier"><span style="background:<?= $su['subscription_tier']==='premium'?'#0c7c84':($su['subscription_tier']==='basic'?'#2563eb':'#94a3b8') ?>;color:#fff;padding:2px 8px;border-radius:4px;font-size:11px"><?= strtoupper($su['subscription_tier'] ?: 'free') ?></span></td>
    <td><?= $su['subscription_period'] ?: '—' ?></td>
    <td style="font-size:12px"><?= $su['subscription_expires_at'] ? date('d M Y', strtotime($su['subscription_expires_at'])) : '—' ?></td>
    <td><?= $su['subscription_auto_renew'] ? '✓' : '—' ?></td>
    <td><button class="btn btn-sm btn-o" onclick="var r=document.getElementById('erow-<?= $su['id'] ?>');r.style.display=r.style.display==='none'?'':'none'" type="button" style="font-size:11px;padding:3px 10px">Edit</button></td>
</tr>
<tr id="erow-<?= $su['id'] ?>" style="display:none;background:rgba(12,124,132,.04)">
    <td colspan="9">
        <div style="display:flex;flex-wrap:wrap;gap:12px;align-items:end;padding:8px 0">
            <div><label style="font-size:11px;display:block;margin-bottom:2px">Tier</label>
                <select name="subscription_tier" style="padding:6px 10px;border-radius:4px;border:1px solid #d0d0d0;font-size:13px">
                    <option value="free" <?= $su['subscription_tier']==='free'?'selected':'' ?>>Free</option>
                    <option value="basic" <?= $su['subscription_tier']==='basic'?'selected':'' ?>>Basic</option>
                    <option value="premium" <?= $su['subscription_tier']==='premium'?'selected':'' ?>>Premium</option>
                </select>
            </div>
            <div><label style="font-size:11px;display:block;margin-bottom:2px">Period</label>
                <select name="subscription_period" style="padding:6px 10px;border-radius:4px;border:1px solid #d0d0d0;font-size:13px">
                    <option value="">None</option>
                    <option value="monthly" <?= $su['subscription_period']==='monthly'?'selected':'' ?>>Monthly</option>
                    <option value="annual" <?= $su['subscription_period']==='annual'?'selected':'' ?>>Annual</option>
                    <option value="lifetime" <?= $su['subscription_period']==='lifetime'?'selected':'' ?>>Lifetime</option>
                </select>
            </div>
            <div><label style="font-size:11px;display:block;margin-bottom:2px">Expires At</label>
                <input type="datetime-local" name="subscription_expires_at" value="<?= $su['subscription_expires_at'] ? date('Y-m-d\TH:i', strtotime($su['subscription_expires_at'])) : '' ?>" style="padding:6px 10px;border-radius:4px;border:1px solid #d0d0d0;font-size:13px">
            </div>
            <div><label style="font-size:11px;display:block;margin-bottom:2px">&nbsp;</label>
                <label style="font-size:12px;cursor:pointer"><input type="checkbox" name="subscription_auto_renew" <?= $su['subscription_auto_renew']?'checked':'' ?>> Auto-Renew</label>
            </div>
            <button type="button" class="btn btn-p" onclick="ajaxSubscriptionUpdate(<?= $su['id'] ?>)" style="font-size:12px;padding:6px 16px">Save</button>
            <button type="button" class="btn btn-o" onclick="document.getElementById('erow-<?= $su['id'] ?>').style.display='none'" style="font-size:12px;padding:6px 12px">Cancel</button>
        </div>
    </td>
</tr>
<?php endforeach; ?>
</tbody>
</table>

<?php
// ═══════════════════════════════════════════════════════════════
// FEATURE ACCESS MANAGEMENT (INLINE)
// ═══════════════════════════════════════════════════════════════
elseif ($section === 'feature_access'):
    $fa_features = [];
    try {
        $fa_features = $db->query("SELECT * FROM feature_access ORDER BY sort_order ASC")->fetchAll();
    } catch (Exception $e) { $fa_features = []; }
?>
<h1>Feature Access Controls</h1>
<p style="color:#64748b;margin-bottom:16px">Control which subscription tiers can access each feature. Click the icons to toggle access for each tier.</p>
<table class="tbl" style="font-size:13px">
<thead><tr><th>Key</th><th>Label</th><th style="text-align:center">Free</th><th style="text-align:center">Basic</th><th style="text-align:center">Premium</th><th style="text-align:center">Login</th><th style="text-align:center">Active</th><th>Delete</th></tr></thead>
<tbody>
<?php foreach ($fa_features as $f): ?>
<tr id="feat-row-<?= $f['id'] ?>">
    <td style="font-family:monospace;font-size:12px"><?= htmlspecialchars($f['feature_key']) ?></td>
    <td><?= htmlspecialchars($f['feature_label']) ?></td>
    <td style="text-align:center"><button type="button" data-field="tier_free" data-val="<?= $f['tier_free'] ?>" onclick="ajaxFeatureToggle(<?= $f['id'] ?>,'tier_free')" style="background:none;border:none;cursor:pointer;font-size:16px"><?= $f['tier_free'] ? '✅' : '❌' ?></button></td>
    <td style="text-align:center"><button type="button" data-field="tier_basic" data-val="<?= $f['tier_basic'] ?>" onclick="ajaxFeatureToggle(<?= $f['id'] ?>,'tier_basic')" style="background:none;border:none;cursor:pointer;font-size:16px"><?= $f['tier_basic'] ? '✅' : '❌' ?></button></td>
    <td style="text-align:center"><button type="button" data-field="tier_premium" data-val="<?= $f['tier_premium'] ?>" onclick="ajaxFeatureToggle(<?= $f['id'] ?>,'tier_premium')" style="background:none;border:none;cursor:pointer;font-size:16px"><?= $f['tier_premium'] ? '✅' : '❌' ?></button></td>
    <td style="text-align:center"><button type="button" data-field="require_login" data-val="<?= $f['require_login'] ?>" onclick="ajaxFeatureToggle(<?= $f['id'] ?>,'require_login')" style="background:none;border:none;cursor:pointer;font-size:16px"><?= $f['require_login'] ? '🔒' : '🔓' ?></button></td>
    <td style="text-align:center"><button type="button" data-field="is_active" data-val="<?= $f['is_active'] ?>" onclick="ajaxFeatureToggle(<?= $f['id'] ?>,'is_active')" style="background:none;border:none;cursor:pointer;font-size:16px"><?= $f['is_active'] ? '🟢' : '🔴' ?></button></td>
    <td><button type="button" onclick="ajaxFeatureDelete(<?= $f['id'] ?>)" style="background:none;border:none;cursor:pointer;color:#dc2626;font-size:12px">Del</button></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
<div class="card" style="margin-top:20px;padding:16px">
<h3 style="margin-bottom:10px;font-size:14px">Add New Feature</h3>
<form method="POST" style="display:flex;flex-wrap:wrap;gap:10px;align-items:end">
<input type="hidden" name="action" value="add_feature">
<div><label style="font-size:11px;display:block;margin-bottom:2px">Key</label><input type="text" name="feature_key" required placeholder="e.g. rab_advanced" style="padding:5px 8px;border-radius:4px;border:1px solid #d0d0d0;font-size:12px;width:160px"></div>
<div><label style="font-size:11px;display:block;margin-bottom:2px">Label</label><input type="text" name="feature_label" required placeholder="Label" style="padding:5px 8px;border-radius:4px;border:1px solid #d0d0d0;font-size:12px;width:180px"></div>
<div><label style="font-size:11px;display:block;margin-bottom:2px">Description</label><input type="text" name="description" placeholder="Optional" style="padding:5px 8px;border-radius:4px;border:1px solid #d0d0d0;font-size:12px;width:160px"></div>
<div style="display:flex;gap:8px;align-items:center">
    <label style="font-size:11px"><input type="checkbox" name="tier_free"> Free</label>
    <label style="font-size:11px"><input type="checkbox" name="tier_basic"> Basic</label>
    <label style="font-size:11px"><input type="checkbox" name="tier_premium" checked> Prem</label>
    <label style="font-size:11px"><input type="checkbox" name="require_login" checked> Login</label>
</div>
<button type="submit" class="btn btn-p" style="font-size:12px;padding:5px 14px">Add</button>
</form>
</div>

<?php endif; ?>

<script>
/* ── Website Enrichment Scanner ────────────────────────────── */
function scanWebsite(entityType) {
    var urlInput, resultsDiv, btn;
    if (entityType === 'providers') {
        urlInput = document.getElementById('prov_website_url');
        resultsDiv = document.getElementById('scan-results');
        btn = document.getElementById('scan-btn');
    } else if (entityType === 'developers') {
        urlInput = document.getElementById('dev_website_url');
        resultsDiv = document.getElementById('scan-results-dev');
        btn = document.getElementById('scan-btn-dev');
    } else {
        return;
    }
    var url = urlInput ? urlInput.value.trim() : '';
    var form = btn.closest('form');
    var nameInput = form.querySelector('[name="name"]');
    var entityName = nameInput ? nameInput.value.trim() : '';

    /* If no URL, fall back to Google search enrichment */
    if (!url) {
        if (!entityName) { alert('Enter a website URL or ensure the Name field is filled.'); return; }
        scanGoogle(entityType, entityName, form, resultsDiv, btn);
        return;
    }

    btn.disabled = true;
    btn.innerHTML = '&#x23F3; Scanning…';
    resultsDiv.style.display = 'block';
    resultsDiv.innerHTML = '<span style="color:#666">Fetching website data…</span>';

    /* Check if "Update existing" is ticked */
    var updateChk = (entityType === 'providers') ? document.getElementById('scan-update-prov') : document.getElementById('scan-update-dev');
    var forceUpdate = updateChk && updateChk.checked;

    /* Collect current form values so scraper knows what is already filled */
    var existing = forceUpdate ? {} : collectExisting(form);

    fetch('scrape_enrich.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({url: url, existing: existing})
    })
    .then(function(r) { return r.json(); })
    .then(function(data) { handleScanResult(data, entityType, form, resultsDiv, btn, forceUpdate); })
    .catch(function(err) {
        btn.disabled = false;
        btn.innerHTML = '&#x1F50D; Scan';
        resultsDiv.innerHTML = '<strong style="color:#dc2626">Network error:</strong> ' + err.message;
    });
}

/* Google Search enrichment — used when no website URL */
function scanGoogle(entityType, name, form, resultsDiv, btn) {
    btn.disabled = true;
    btn.innerHTML = '&#x23F3; Searching…';
    resultsDiv.style.display = 'block';
    resultsDiv.innerHTML = '<span style="color:#666">Searching Google for &ldquo;' + name + '&rdquo;…</span>';

    var existing = collectExisting(form);

    fetch('google_enrich.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({name: name, existing: existing})
    })
    .then(function(r) { return r.json(); })
    .then(function(data) { handleScanResult(data, entityType, form, resultsDiv, btn); })
    .catch(function(err) {
        btn.disabled = false;
        btn.innerHTML = '&#x1F50D; Scan';
        resultsDiv.innerHTML = '<strong style="color:#dc2626">Network error:</strong> ' + err.message;
    });
}

/* Collect existing form field values */
function collectExisting(form) {
    var existing = {};
    var fieldNames = ['description','short_description','profile_photo_url','logo_url',
        'hero_image_url','image_url_2','image_url_3','image_url_4',
        'instagram_url','facebook_url','linkedin_url','youtube_url','tiktok_url',
        'whatsapp_number','phone','email','website_url'];
    for (var i = 0; i < fieldNames.length; i++) {
        var inp = form.querySelector('[name="' + fieldNames[i] + '"]');
        if (inp && inp.value.trim()) existing[fieldNames[i]] = inp.value.trim();
    }
    return existing;
}

/* Handle scan/search results — shared by website scan and Google search */
function handleScanResult(data, entityType, form, resultsDiv, btn, forceUpdate) {
    btn.disabled = false;
    btn.innerHTML = '&#x1F50D; Scan';
    if (data.error) {
        resultsDiv.innerHTML = '<strong style="color:#dc2626">Error:</strong> ' + data.error;
        return;
    }
    var found = data.found || {};
    var log = data.log || [];
    var keys = Object.keys(found);
    if (keys.length === 0) {
        resultsDiv.innerHTML = '<strong>No new data found.</strong> All fields already populated or no additional info found online.';
        return;
    }
    /* Build summary and populate empty form fields */
    var html = '<strong style="color:#16a34a">Found ' + keys.length + ' field(s):</strong><ul style="margin:8px 0 0;padding-left:20px;font-size:13px">';
    for (var j = 0; j < keys.length; j++) {
        var k = keys[j];
        var val = found[k];
        html += '<li><strong>' + k.replace(/_/g, ' ') + ':</strong> ';
        if (val && (val.indexOf('http') === 0 || val.indexOf('//') === 0)) {
            html += '<a href="' + val + '" target="_blank" style="word-break:break-all">' + val.substring(0, 80) + '</a>';
        } else {
            html += (val && val.length > 120 ? val.substring(0, 120) + '…' : val);
        }
        html += ' <button type="button" onclick="applyField(this,\'' + entityType + '\',\'' + k + '\')" class="btn btn-g btn-sm" style="margin-left:6px;padding:2px 8px;font-size:11px">Apply</button></li>';
        /* Auto-apply to empty fields (or all fields if forceUpdate) */
        var inp = form.querySelector('[name="' + k + '"]');
        if (inp && (forceUpdate || !inp.value.trim())) {
            inp.value = val;
        }
    }
    html += '</ul>';
    if (log.length) {
        html += '<details style="margin-top:8px;font-size:12px;color:#666"><summary>Scan log</summary><ul style="padding-left:16px;margin-top:4px">';
        for (var l = 0; l < log.length; l++) { html += '<li>' + log[l] + '</li>'; }
        html += '</ul></details>';
    }
    html += '<p style="margin-top:10px;font-size:12px;color:#666">Fields auto-applied to empty inputs. Click <strong>Save</strong> to persist.</p>';
    if (data.saved) {
        html += '<p style="font-size:12px;color:#16a34a;font-weight:600">' + data.saved + ' field(s) saved to database.</p>';
    }
    resultsDiv.innerHTML = html;
}
function applyField(applyBtn, entityType, fieldName) {
    /* Manually apply a found value to its form field */
    var resultsDiv = (entityType === 'providers') ? document.getElementById('scan-results') : document.getElementById('scan-results-dev');
    var form = resultsDiv.closest('form');
    if (!form) return;
    var inp = form.querySelector('[name="' + fieldName + '"]');
    if (!inp) { alert('Field "' + fieldName + '" not found in form.'); return; }
    /* Get value from the list item text */
    var li = applyBtn.parentNode;
    var strong = li.querySelector('strong');
    var aTag = li.querySelector('a');
    var val = '';
    if (aTag) { val = aTag.getAttribute('href'); }
    else {
        var text = li.textContent || '';
        text = text.replace(strong.textContent, '').replace('Apply', '').trim();
        if (text.charAt(text.length - 1) === '…') text = text.substring(0, text.length - 1);
        val = text;
    }
    inp.value = val;
    applyBtn.textContent = 'Applied';
    applyBtn.disabled = true;
    applyBtn.className = 'btn btn-o btn-sm';
}
</script>
<script>
// Auto-scroll to hash anchor (for sidebar lookup sub-links)
if (window.location.hash) {
    var tgt = document.getElementById(window.location.hash.substring(1));
    if (tgt) { setTimeout(function(){ tgt.scrollIntoView({behavior:'smooth',block:'start'}); }, 100); }
}
</script>

</div><!-- main -->
</div><!-- shell -->
<div id="ajax-flash" style="display:none;position:fixed;top:16px;right:16px;padding:12px 20px;border-radius:8px;font-size:13px;font-weight:600;z-index:9999;box-shadow:0 4px 12px rgba(0,0,0,.15);transition:opacity .3s"></div>
<script>
/* ── AJAX Helpers (PHP 7.x compatible: var + function(){}) ────────── */
function ajaxFlash(msg, isError) {
    var el = document.getElementById('ajax-flash');
    el.textContent = msg;
    el.style.background = isError ? '#fee2e2' : '#dcfce7';
    el.style.color = isError ? '#dc2626' : '#16a34a';
    el.style.border = '1px solid ' + (isError ? '#fecaca' : '#bbf7d0');
    el.style.display = 'block';
    el.style.opacity = '1';
    clearTimeout(window._flashTimer);
    window._flashTimer = setTimeout(function() {
        el.style.opacity = '0';
        setTimeout(function() { el.style.display = 'none'; }, 300);
    }, 3000);
}

function ajaxAction(params, onSuccess) {
    var fd = new FormData();
    var keys = Object.keys(params);
    for (var i = 0; i < keys.length; i++) {
        fd.append(keys[i], params[keys[i]]);
    }
    var xhr = new XMLHttpRequest();
    xhr.open('POST', 'console.php?ajax=1', true);
    xhr.onload = function() {
        if (xhr.status === 200) {
            try {
                var data = JSON.parse(xhr.responseText);
                if (data.ok) {
                    ajaxFlash(data.msg, false);
                    if (typeof onSuccess === 'function') onSuccess(data);
                } else {
                    ajaxFlash(data.msg || 'Action failed.', true);
                }
            } catch(e) {
                ajaxFlash('Invalid server response.', true);
            }
        } else {
            ajaxFlash('Server error (' + xhr.status + ').', true);
        }
    };
    xhr.onerror = function() { ajaxFlash('Network error.', true); };
    xhr.send(fd);
}

function fadeOutRow(el) {
    el.style.transition = 'opacity 0.4s, max-height 0.4s';
    el.style.opacity = '0';
    el.style.maxHeight = el.offsetHeight + 'px';
    el.style.overflow = 'hidden';
    setTimeout(function() {
        el.style.maxHeight = '0';
        el.style.padding = '0';
        setTimeout(function() { el.remove(); }, 400);
    }, 300);
}

/* ── Listings AJAX ───────────────────────────────────────────────── */
function ajaxListingDelete(id) {
    if (!confirm('Delete listing #' + id + ' permanently?')) return;
    ajaxAction({aj_action: 'listing_delete', aj_id: id}, function() {
        var row = document.getElementById('lst-row-' + id);
        var editRow = document.getElementById('lst-edit-' + id);
        if (row) fadeOutRow(row);
        if (editRow) editRow.remove();
    });
}

function ajaxListingStatus(sel, id) {
    ajaxAction({aj_action: 'listing_change_status', aj_id: id, new_status: sel.value}, function(data) {
        /* Update select background colour */
        var v = data.new_val || sel.value;
        sel.style.background = (v === 'active') ? '#dcfce7' : ((v === 'sold' || v === 'expired') ? '#fee2e2' : '#fef9c3');
    });
}

function ajaxListingApprove(id, currentlyApproved) {
    var action = currentlyApproved ? 'listing_reject' : 'listing_approve';
    if (currentlyApproved && !confirm('Reject this listing?')) return;
    ajaxAction({aj_action: action, aj_id: id}, function(data) {
        var cell = document.querySelector('#lst-row-' + id + ' .aj-appr-btn');
        if (cell) {
            var isNowApproved = (data.new_val === 1 || data.new_val === '1');
            cell.innerHTML = isNowApproved ? '\u2705' : '\u274C';
            cell.setAttribute('onclick', 'ajaxListingApprove(' + id + ',' + (isNowApproved ? 'true' : 'false') + ')');
            cell.title = isNowApproved ? 'Click to reject' : 'Click to approve';
        }
    });
}

function ajaxListingFeatured(id) {
    ajaxAction({aj_action: 'listing_toggle_featured', aj_id: id}, function(data) {
        var cell = document.querySelector('#lst-row-' + id + ' .aj-feat-btn');
        if (cell) {
            var isF = (data.new_val === 1 || data.new_val === '1');
            cell.innerHTML = isF ? '\u2B50' : '\u2606';
            cell.title = isF ? 'Unfeature' : 'Feature';
        }
    });
}

function ajaxListingEdit(id) {
    var editRow = document.getElementById('lst-edit-' + id);
    if (!editRow) return;
    var inputs = editRow.querySelectorAll('input, select, textarea');
    var params = {aj_action: 'listing_edit', aj_id: id};
    for (var i = 0; i < inputs.length; i++) {
        var inp = inputs[i];
        if (inp.name && inp.name !== 'listing_id') {
            params[inp.name] = inp.value;
        }
    }
    ajaxAction(params, function(data) {
        /* Close edit row */
        editRow.style.display = 'none';
        /* Update every visible cell in the display row */
        if (data.row) {
            var r = data.row;
            var row = document.getElementById('lst-row-' + id);
            if (!row) return;
            var cells = row.querySelectorAll('td');
            /* cell 0 = image (skip), cell 1 = title/source, cell 2 = area, cell 3 = agent,
               cell 4 = land, cell 5 = price, cell 6 = status, cell 7 = appr, cell 8 = feat, cell 9 = actions */

            /* ── Title / Source (cell 1) ── */
            var titleText = r.title || '-';
            var shortTitle = titleText.length > 50 ? titleText.substring(0, 47) + '...' : titleText;
            var typeText = r.listing_type || r.listing_type_key || '-';
            var dateText = r.created_at ? r.created_at.substring(0, 10) : '';
            if (r.source_url) {
                cells[1].innerHTML = '<a href="' + escHtml(r.source_url) + '" target="_blank" rel="noopener" style="color:#0c7c84;text-decoration:none;font-weight:500">' + escHtml(shortTitle) + '</a>'
                    + '<div style="font-size:11px;color:#94a3b8;margin-top:1px">#' + id + ' \u00b7 ' + escHtml(typeText) + ' \u00b7 ' + dateText + '</div>';
            } else {
                cells[1].innerHTML = '<span style="font-weight:500">' + escHtml(shortTitle) + '</span>'
                    + '<div style="font-size:11px;color:#94a3b8;margin-top:1px">#' + id + ' \u00b7 ' + escHtml(typeText) + ' \u00b7 ' + dateText + '</div>';
            }

            /* ── Area (cell 2) ── */
            cells[2].textContent = r.area_label || '-';

            /* ── Agent (cell 3) ── */
            cells[3].textContent = r.agent_name || 'Private Seller';

            /* ── Land size (cell 4) ── */
            if (r.land_size_are && parseFloat(r.land_size_are) > 0) {
                cells[4].textContent = formatNum(r.land_size_are, 0) + ' are';
            } else if (r.land_size_sqm && parseFloat(r.land_size_sqm) > 0) {
                cells[4].textContent = formatNum(r.land_size_sqm, 0) + ' m\u00B2';
            } else {
                cells[4].textContent = '-';
            }

            /* ── Price (cell 5) ── */
            if (r.price_usd && parseFloat(r.price_usd) > 0) {
                cells[5].textContent = '$' + formatNum(r.price_usd, 0);
            } else if (r.price_idr && parseFloat(r.price_idr) > 0) {
                cells[5].textContent = 'Rp ' + formatNum(r.price_idr, 0);
            } else {
                cells[5].textContent = '-';
            }

            /* Brief green flash on the row to confirm */
            row.style.transition = 'background 0.3s';
            row.style.background = '#f0fdf4';
            setTimeout(function() { row.style.background = ''; }, 1200);
        }
    });
}

/* ── Currency rates for admin auto-convert ── */
var CURRENCY_RATES = <?php
    $cr_map = array();
    foreach ($currency_rates as $cr) {
        $cr_map[$cr['from_currency'] . '_' . $cr['to_currency']] = (float)$cr['rate'];
    }
    echo json_encode($cr_map);
?>;

function lstPriceConvert(el) {
    var src = (el.getAttribute('data-currency') || '').toUpperCase();
    var val = parseFloat(el.value);
    if (!val || val <= 0) return;
    var editRow = el.closest('tr');
    if (!editRow) return;
    var currencies = ['USD','IDR','EUR','AUD'];
    for (var ci = 0; ci < currencies.length; ci++) {
        var tgt = currencies[ci];
        if (tgt === src) continue;
        var rateKey = src + '_' + tgt;
        var rate = CURRENCY_RATES[rateKey];
        if (!rate) continue;
        var converted = Math.round(val * rate);
        var inp = editRow.parentNode.querySelector('input[data-currency="' + tgt.toLowerCase() + '"]');
        if (!inp) {
            /* search in sibling rows for same listing */
            var lstId = el.getAttribute('data-lst-id');
            if (lstId) {
                var editTr = document.getElementById('lst-edit-' + lstId);
                if (editTr) inp = editTr.querySelector('input[data-currency="' + tgt.toLowerCase() + '"]');
            }
        }
        if (inp) inp.value = converted;
    }
}

/* Helper: escape HTML entities */
function escHtml(s) {
    if (!s) return '';
    var d = document.createElement('div');
    d.appendChild(document.createTextNode(s));
    return d.innerHTML;
}

/* Helper: format number with thousands separator */
function formatNum(n, decimals) {
    var num = parseFloat(n);
    if (isNaN(num)) return '0';
    return num.toFixed(decimals || 0).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
}

/* ── Entity Delete (providers, developers, projects, guides) ─────── */
function ajaxEntityDelete(table, id, label) {
    if (!confirm('Delete this ' + label + '?')) return;
    ajaxAction({aj_action: 'delete_entity', aj_table: table, aj_id: id}, function() {
        var row = document.getElementById('entity-row-' + table + '-' + id);
        if (row) fadeOutRow(row);
    });
}

/* ── Users: toggle active ────────────────────────────────────────── */
function ajaxUserToggle(id) {
    ajaxAction({aj_action: 'user_toggle_active', aj_id: id}, function(data) {
        var row = document.getElementById('user-row-' + id);
        if (!row) return;
        var statusCell = row.querySelector('.aj-user-status');
        var btn = row.querySelector('.aj-user-toggle');
        if (statusCell) {
            var isActive = (data.new_val === 1 || data.new_val === '1');
            statusCell.innerHTML = isActive ? '<span class="badge b-green">Active</span>' : '<span class="badge b-red">Inactive</span>';
            if (btn) btn.textContent = isActive ? 'Deactivate' : 'Activate';
        }
    });
}

/* ── Agents: toggle verified / active ────────────────────────────── */
function ajaxAgentToggle(action, id) {
    ajaxAction({aj_action: action, aj_id: id}, function(data) {
        var row = document.getElementById('agent-row-' + id);
        if (!row) return;
        var isOn = (data.new_val === 1 || data.new_val === '1');
        if (action === 'agent_toggle_verified') {
            var cell = row.querySelector('.aj-agent-verified');
            if (cell) cell.innerHTML = isOn ? '<span class="badge b-green">Y</span>' : '<span class="badge b-red">N</span>';
            var btn = row.querySelector('.aj-agent-verify-btn');
            if (btn) btn.textContent = isOn ? 'Unverify' : 'Verify';
        } else {
            var cell2 = row.querySelector('.aj-agent-active');
            if (cell2) cell2.innerHTML = isOn ? '<span class="badge b-green">Y</span>' : '<span class="badge b-red">N</span>';
            var btn2 = row.querySelector('.aj-agent-active-btn');
            if (btn2) btn2.textContent = isOn ? 'Deactivate' : 'Activate';
        }
    });
}

/* ── Claims: review ──────────────────────────────────────────────── */
function ajaxClaimReview(id, decision) {
    var notesInput = document.getElementById('claim-notes-' + id);
    var notes = notesInput ? notesInput.value : '';
    ajaxAction({aj_action: 'claim_review', aj_id: id, decision: decision, admin_notes: notes}, function(data) {
        var card = document.getElementById('claim-card-' + id);
        if (card) {
            /* Update badge */
            var badge = card.querySelector('.aj-claim-badge');
            if (badge) {
                badge.textContent = decision;
                badge.className = 'badge ' + (decision === 'approved' ? 'b-green' : 'b-red');
            }
            /* Remove the form */
            var form = card.querySelector('.aj-claim-form');
            if (form) form.remove();
        }
    });
}

/* ── Submissions: review ─────────────────────────────────────────── */
function ajaxSubmissionReview(id, decision) {
    var notesInput = document.getElementById('sub-notes-' + id);
    var notes = notesInput ? notesInput.value : '';
    ajaxAction({aj_action: 'submission_review', aj_id: id, decision: decision, admin_notes: notes}, function(data) {
        var card = document.getElementById('sub-card-' + id);
        if (card) {
            var badge = card.querySelector('.aj-sub-badge');
            if (badge) {
                badge.textContent = decision;
                badge.className = 'badge ' + (decision === 'approved' ? 'b-green' : 'b-red');
            }
            var form = card.querySelector('.aj-sub-form');
            if (form) form.remove();
        }
    });
}

/* ── Subscriptions: update ───────────────────────────────────────── */
function ajaxSubscriptionUpdate(userId) {
    var editRow = document.getElementById('erow-' + userId);
    if (!editRow) return;
    var params = {aj_action: 'subscription_update', aj_id: userId};
    var tier = editRow.querySelector('[name=subscription_tier]');
    var period = editRow.querySelector('[name=subscription_period]');
    var expires = editRow.querySelector('[name=subscription_expires_at]');
    var autoRenew = editRow.querySelector('[name=subscription_auto_renew]');
    if (tier) params.subscription_tier = tier.value;
    if (period) params.subscription_period = period.value;
    if (expires) params.subscription_expires_at = expires.value;
    if (autoRenew && autoRenew.checked) params.subscription_auto_renew = '1';
    ajaxAction(params, function(data) {
        editRow.style.display = 'none';
        /* Update tier badge in display row */
        var displayRow = editRow.previousElementSibling;
        if (displayRow) {
            var tierCell = displayRow.querySelector('.aj-sub-tier');
            if (tierCell && data.new_val) {
                var bg = data.new_val === 'premium' ? '#0c7c84' : (data.new_val === 'basic' ? '#2563eb' : '#94a3b8');
                tierCell.innerHTML = '<span style="background:' + bg + ';color:#fff;padding:2px 8px;border-radius:4px;font-size:11px">' + data.new_val.toUpperCase() + '</span>';
            }
        }
    });
}

/* ── Feature Access: toggle & delete ─────────────────────────────── */
function ajaxFeatureToggle(featureId, toggleField) {
    var row = document.getElementById('feat-row-' + featureId);
    if (!row) return;
    /* Build current state */
    var params = {aj_action: 'feature_update', aj_id: featureId};
    var fields = ['tier_free','tier_basic','tier_premium','require_login','is_active'];
    for (var i = 0; i < fields.length; i++) {
        var btn = row.querySelector('[data-field="' + fields[i] + '"]');
        if (btn) {
            var currentVal = parseInt(btn.getAttribute('data-val'));
            if (fields[i] === toggleField) {
                params[fields[i]] = currentVal ? '0' : '1';
            } else {
                params[fields[i]] = String(currentVal);
            }
        }
    }
    ajaxAction(params, function() {
        /* Toggle button display */
        var togBtn = row.querySelector('[data-field="' + toggleField + '"]');
        if (togBtn) {
            var curVal = parseInt(togBtn.getAttribute('data-val'));
            var newVal = curVal ? 0 : 1;
            togBtn.setAttribute('data-val', newVal);
            var icons = {tier_free: ['\u274C','\u2705'], tier_basic: ['\u274C','\u2705'], tier_premium: ['\u274C','\u2705'], require_login: ['\uD83D\uDD13','\uD83D\uDD12'], is_active: ['\uD83D\uDD34','\uD83D\uDFE2']};
            var pair = icons[toggleField];
            if (pair) togBtn.textContent = newVal ? pair[1] : pair[0];
        }
    });
}

function ajaxFeatureDelete(featureId) {
    if (!confirm('Delete this feature?')) return;
    ajaxAction({aj_action: 'feature_delete', aj_id: featureId}, function() {
        var row = document.getElementById('feat-row-' + featureId);
        if (row) fadeOutRow(row);
    });
}

/* ── Lookup Delete ───────────────────────────────────────────────── */
function ajaxLookupDelete(table, key) {
    if (!confirm('Delete this entry? This may break references.')) return;
    ajaxAction({aj_action: 'lookup_delete', aj_table: table, aj_key: key}, function() {
        var row = document.getElementById('lookup-row-' + table + '-' + key);
        if (row) fadeOutRow(row);
    });
}
</script>
</body>
</html>
<?php
// ─── LOGIN PAGE ──────────────────────────────────────────────────────
function show_login(string $error = ''): void {
?><!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<meta name="robots" content="noindex,nofollow"><title>Admin Console Login</title>
<style>
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',system-ui,sans-serif;background:#f0f2f5;display:flex;align-items:center;justify-content:center;min-height:100vh}
.lc{background:#fff;border-radius:10px;padding:40px;box-shadow:0 2px 12px rgba(0,0,0,.1);width:100%;max-width:360px}
h1{font-size:1.3rem;margin-bottom:6px}p{color:#666;font-size:.85rem;margin-bottom:20px}
label{display:block;font-size:.8rem;font-weight:600;text-transform:uppercase;letter-spacing:.03em;color:#555;margin-bottom:4px}
input{width:100%;padding:10px 12px;border:1px solid #d0d0d0;border-radius:6px;font-size:.95rem;margin-bottom:14px;box-sizing:border-box}
button{width:100%;padding:11px;background:#0c7c84;color:#fff;border:none;border-radius:6px;font-size:.95rem;font-weight:600;cursor:pointer}
button:hover{background:#0a6a70}.err{background:#fee2e2;color:#dc2626;padding:10px;border-radius:6px;margin-bottom:14px;font-size:.85rem}
</style></head><body><div class="lc"><h1>Admin Console</h1><p>Build in Lombok</p>
<?php if ($error): ?><div class="err"><?= htmlspecialchars($error) ?></div><?php endif; ?>
<form method="POST"><label>Username</label><input type="text" name="username" required autofocus>
<label>Password</label><input type="password" name="password" required>
<button type="submit" name="login">Log In</button></form></div></body></html>
<?php } ?>
