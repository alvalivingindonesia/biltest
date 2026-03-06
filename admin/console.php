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
                    profile_photo_url=?, logo_url=?, instagram_url=?, facebook_url=?, linkedin_url=?,
                    is_featured=?, is_trusted=?, badge=?, is_active=? WHERE id=?")->execute([
                    $data['name'], $slug, $data['group_key'], $data['area_key'],
                    $data['short_description'], $data['description'], $data['address'], $data['latitude'] ?: null, $data['longitude'] ?: null,
                    $data['google_maps_url'], $data['google_rating'] ?: null, $data['google_review_count'] ?: 0,
                    $data['phone'], $data['whatsapp_number'], $data['website_url'], $data['languages'],
                    $data['profile_photo_url'] ?: null, $data['logo_url'] ?: null, $data['instagram_url'] ?: null, $data['facebook_url'] ?: null, $data['linkedin_url'] ?: null,
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
                    profile_photo_url, logo_url, instagram_url, facebook_url, linkedin_url,
                    is_featured, is_trusted, badge, is_active)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")->execute([
                    $slug, $data['name'], $data['group_key'], ($data['categories'] ?? [''])[0] ?? '', $data['area_key'],
                    $data['short_description'], $data['description'], $data['address'], $data['latitude'] ?: null, $data['longitude'] ?: null,
                    $data['google_maps_url'], $data['google_rating'] ?: null, $data['google_review_count'] ?: 0,
                    $data['phone'], $data['whatsapp_number'], $data['website_url'], $data['languages'],
                    $data['profile_photo_url'] ?: null, $data['logo_url'] ?: null, $data['instagram_url'] ?: null, $data['facebook_url'] ?: null, $data['linkedin_url'] ?: null,
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
                    profile_photo_url=?, logo_url=?, instagram_url=?, facebook_url=?, linkedin_url=?,
                    is_featured=?, badge=?, is_active=? WHERE id=?")->execute([
                    $data['name'], $slug, $data['short_description'], $data['description'],
                    $data['min_ticket_usd'] ?: null, $data['google_maps_url'], $data['google_rating'] ?: null, $data['google_review_count'] ?: 0,
                    $data['phone'], $data['whatsapp_number'], $data['website_url'], $data['languages'],
                    $data['profile_photo_url'] ?: null, $data['logo_url'] ?: null, $data['instagram_url'] ?: null, $data['facebook_url'] ?: null, $data['linkedin_url'] ?: null,
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
                    profile_photo_url, logo_url, instagram_url, facebook_url, linkedin_url,
                    is_featured, badge, is_active)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")->execute([
                    $slug, $data['name'], $data['short_description'], $data['description'],
                    $data['min_ticket_usd'] ?: null, $data['google_maps_url'], $data['google_rating'] ?: null, $data['google_review_count'] ?: 0,
                    $data['phone'], $data['whatsapp_number'], $data['website_url'], $data['languages'],
                    $data['profile_photo_url'] ?: null, $data['logo_url'] ?: null, $data['instagram_url'] ?: null, $data['facebook_url'] ?: null, $data['linkedin_url'] ?: null,
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
    <a href="?s=lookups" class="<?= $section==='lookups'?'active':'' ?>">Categories & Lookups</a>
    <h2>Tools</h2>
    <a href="import.php">Google Maps Importer</a>
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
    <tr>
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
            <form method="POST" action="?s=providers&a=delete&id=<?= $r['id'] ?>" style="display:inline" onsubmit="return confirm('Delete this provider?')">
                <button class="btn btn-r btn-sm">Del</button>
            </form>
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
        <div class="fg"><label>Languages</label><input type="text" name="languages" value="<?= $v('languages','Bahasa only') ?>"></div>
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
                <?php if ($id): ?><button type="button" class="btn btn-o btn-sm" onclick="scanWebsite('providers')" id="scan-btn" title="Scan website for missing info">&#x1F50D; Scan</button><?php endif; ?>
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
    <tr>
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
            <form method="POST" action="?s=developers&a=delete&id=<?= $r['id'] ?>" style="display:inline" onsubmit="return confirm('Delete?')">
                <button class="btn btn-r btn-sm">Del</button>
            </form>
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
        <div class="fg"><label>Languages</label><input type="text" name="languages" value="<?= $v('languages','Bahasa only') ?>"></div>
        <div class="fg"><label>Google Maps URL</label><input type="url" name="google_maps_url" value="<?= $v('google_maps_url') ?>"></div>
        <div class="fg"><label>Google Rating</label><input type="number" name="google_rating" value="<?= $v('google_rating') ?>" step="0.1" min="1" max="5"></div>
        <div class="fg"><label>Review Count</label><input type="number" name="google_review_count" value="<?= $v('google_review_count','0') ?>"></div>
        <div class="fg"><label>Phone</label><input type="text" name="phone" value="<?= $v('phone') ?>"></div>
        <div class="fg"><label>WhatsApp</label><input type="text" name="whatsapp_number" value="<?= $v('whatsapp_number') ?>"></div>
        <div class="fg"><label>Website</label>
            <div style="display:flex;gap:8px;align-items:center">
                <input type="url" name="website_url" id="dev_website_url" value="<?= $v('website_url') ?>" style="flex:1">
                <?php if ($id): ?><button type="button" class="btn btn-o btn-sm" onclick="scanWebsite('developers')" id="scan-btn-dev" title="Scan website for missing info">&#x1F50D; Scan</button><?php endif; ?>
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
    <tr>
        <td><a href="?s=projects&a=edit&id=<?= $r['id'] ?>"><?= htmlspecialchars($r['name']) ?></a></td>
        <td><?= htmlspecialchars($r['dev_name'] ?? '-') ?></td>
        <td><span class="badge b-blue"><?= htmlspecialchars($r['type_label'] ?? '-') ?></span></td>
        <td><span class="badge b-yellow"><?= htmlspecialchars($r['status_label'] ?? '-') ?></span></td>
        <td><?= htmlspecialchars($r['area_label'] ?? '-') ?></td>
        <td><?= $r['is_active'] ? '<span class="badge b-green">Yes</span>' : '<span class="badge b-red">No</span>' ?></td>
        <td class="actions">
            <a href="?s=projects&a=edit&id=<?= $r['id'] ?>" class="btn btn-o btn-sm">Edit</a>
            <form method="POST" action="?s=projects&a=delete&id=<?= $r['id'] ?>" style="display:inline" onsubmit="return confirm('Delete?')">
                <button class="btn btn-r btn-sm">Del</button>
            </form>
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
    <tr>
        <td><a href="?s=guides&a=edit&id=<?= $r['id'] ?>"><?= htmlspecialchars($r['title']) ?></a></td>
        <td><span class="badge b-blue"><?= htmlspecialchars($r['category']) ?></span></td>
        <td><?= htmlspecialchars($r['read_time'] ?? '-') ?></td>
        <td><?= $r['is_published'] ? '<span class="badge b-green">Yes</span>' : '<span class="badge b-red">Draft</span>' ?></td>
        <td style="color:#888;font-size:12px"><?= $r['updated_at'] ?></td>
        <td class="actions">
            <a href="?s=guides&a=edit&id=<?= $r['id'] ?>" class="btn btn-o btn-sm">Edit</a>
            <form method="POST" action="?s=guides&a=delete&id=<?= $r['id'] ?>" style="display:inline" onsubmit="return confirm('Delete this guide?')">
                <button class="btn btn-r btn-sm">Del</button>
            </form>
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
        <tr>
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
                <form method="POST" action="?s=lookups&a=delete_lookup" style="display:inline" onsubmit="return confirm('Delete this entry? This may break references.')">
                    <input type="hidden" name="_table" value="<?= $tbl ?>">
                    <input type="hidden" name="_key" value="<?= htmlspecialchars($it['key']) ?>">
                    <button class="btn btn-r btn-sm">Del</button>
                </form>
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

<?php
// ═══════════════════════════════════════════════════════════════
// USERS LIST
// ═══════════════════════════════════════════════════════════════
elseif ($section === 'users'):
    $f_status = $_GET['fs'] ?? '';
    $q = $_GET['q'] ?? '';
    $where = '1=1'; $params = [];
    if ($q) { $where .= " AND (email LIKE ? OR display_name LIKE ?)"; $params[] = "%{$q}%"; $params[] = "%{$q}%"; }
    if ($f_status === 'active') { $where .= " AND is_active=1"; }
    elseif ($f_status === 'inactive') { $where .= " AND is_active=0"; }
    elseif ($f_status === 'unverified') { $where .= " AND is_verified=0"; }
    try {
        $stmt = $db->prepare("SELECT * FROM users WHERE {$where} ORDER BY created_at DESC LIMIT 200");
        $stmt->execute($params);
        $users = $stmt->fetchAll();
    } catch (Exception $e) { $users = []; }
?>
<h1>Users (<?= count($users) ?>)</h1>
<div class="search-bar">
    <form method="GET" style="display:flex;gap:8px;flex-wrap:wrap;width:100%">
        <input type="hidden" name="s" value="users">
        <input type="text" name="q" placeholder="Search email or name..." value="<?= htmlspecialchars($q) ?>" style="flex:1;min-width:140px">
        <select name="fs">
            <option value="">All users</option>
            <option value="active" <?= $f_status==='active'?'selected':'' ?>>Active</option>
            <option value="inactive" <?= $f_status==='inactive'?'selected':'' ?>>Inactive</option>
            <option value="unverified" <?= $f_status==='unverified'?'selected':'' ?>>Unverified</option>
        </select>
        <button class="btn btn-p">Filter</button>
    </form>
</div>
<div class="card">
<table>
    <tr><th>Email</th><th>Name</th><th>Role</th><th>Verified</th><th>Status</th><th>Joined</th><th>Actions</th></tr>
    <?php foreach ($users as $u): ?>
    <tr>
        <td><?= htmlspecialchars($u['email']) ?></td>
        <td><?= htmlspecialchars($u['display_name']) ?></td>
        <td><span class="badge <?= $u['role']==='admin'?'b-red':($u['role']==='provider_owner'?'b-blue':'b-green') ?>"><?= $u['role'] ?></span></td>
        <td><?= $u['is_verified'] ? '<span class="badge b-green">Yes</span>' : '<span class="badge b-yellow">No</span>' ?></td>
        <td><?= $u['is_active'] ? '<span class="badge b-green">Active</span>' : '<span class="badge b-red">Inactive</span>' ?></td>
        <td style="color:#888;font-size:12px"><?= $u['created_at'] ?></td>
        <td>
            <form method="POST" action="?s=users&a=toggle_user" style="display:inline">
                <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                <button class="btn btn-o btn-sm"><?= $u['is_active'] ? 'Deactivate' : 'Activate' ?></button>
            </form>
        </td>
    </tr>
    <?php endforeach; ?>
</table>
</div>

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
<div class="card">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:10px">
        <div>
            <strong><?= htmlspecialchars($cl['provider_name']) ?></strong>
            <span class="badge <?= $cl['status']==='pending'?'b-yellow':($cl['status']==='approved'?'b-green':'b-red') ?>" style="margin-left:8px"><?= $cl['status'] ?></span>
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
    <form method="POST" action="?s=claims&a=review_claim" style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap">
        <input type="hidden" name="claim_id" value="<?= $cl['id'] ?>">
        <div class="fg" style="flex:1;min-width:200px">
            <label>Admin Notes</label>
            <input type="text" name="admin_notes" placeholder="Optional notes...">
        </div>
        <button name="decision" value="approved" class="btn btn-g btn-sm">Approve</button>
        <button name="decision" value="rejected" class="btn btn-r btn-sm">Reject</button>
    </form>
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
<div class="card">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:10px">
        <div>
            <strong><?= htmlspecialchars($sub['business_name']) ?></strong>
            <span class="badge <?= $sub['status']==='pending'?'b-yellow':($sub['status']==='approved'?'b-green':'b-red') ?>" style="margin-left:8px"><?= $sub['status'] ?></span>
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
    <form method="POST" action="?s=submissions&a=review_submission" style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap">
        <input type="hidden" name="sub_id" value="<?= $sub['id'] ?>">
        <div class="fg" style="flex:1;min-width:200px">
            <label>Admin Notes</label>
            <input type="text" name="admin_notes" placeholder="Optional notes...">
        </div>
        <button name="decision" value="approved" class="btn btn-g btn-sm">Approve & Create</button>
        <button name="decision" value="rejected" class="btn btn-r btn-sm">Reject</button>
    </form>
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
    <tr>
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
        <td><?= $ag['is_verified'] ? '<span class="badge b-green">Y</span>' : '<span class="badge b-red">N</span>' ?></td>
        <td><?= $ag['is_active'] ? '<span class="badge b-green">Y</span>' : '<span class="badge b-red">N</span>' ?></td>
        <td style="text-align:center"><?= (int)$ag['listings_count'] ?></td>
        <td style="color:#888;font-size:12px"><?= $ag['created_at'] ?? '-' ?></td>
        <td class="actions" style="white-space:nowrap">
            <form method="POST" action="?s=agents&a=toggle_verified" style="display:inline">
                <input type="hidden" name="agent_id" value="<?= $ag['id'] ?>">
                <button class="btn btn-o btn-sm" title="Toggle verified"><?= $ag['is_verified'] ? 'Unverify' : 'Verify' ?></button>
            </form>
            <form method="POST" action="?s=agents&a=toggle_active" style="display:inline">
                <input type="hidden" name="agent_id" value="<?= $ag['id'] ?>">
                <button class="btn btn-o btn-sm"><?= $ag['is_active'] ? 'Deactivate' : 'Activate' ?></button>
            </form>
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
    $f_area = $_GET['fa'] ?? '';
    $where = '1=1'; $params = [];
    if ($q) { $where .= " AND (pl.title LIKE ? OR pl.listing_type LIKE ?)"; $params[] = "%{$q}%"; $params[] = "%{$q}%"; }
    if ($f_status) { $where .= " AND pl.status=?"; $params[] = $f_status; }
    if ($f_approved === '1') { $where .= " AND pl.is_approved=1"; }
    elseif ($f_approved === '0') { $where .= " AND pl.is_approved=0"; }
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
    // Status badge colour helper
    $status_badge = [
        'draft'      => 'b-yellow',
        'active'     => 'b-green',
        'under_offer'=> 'b-blue',
        'sold'       => 'b-red',
        'expired'    => 'b-red',
    ];
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
    <select name="fa">
        <option value="">All Areas</option>
        <?php foreach ($areas_list as $a): ?><option value="<?= $a['key'] ?>" <?= $f_area===$a['key']?'selected':'' ?>><?= htmlspecialchars($a['label']) ?></option><?php endforeach; ?>
    </select>
    <button class="btn btn-o">Filter</button>
    <?php if ($q || $f_status || $f_approved !== '' || $f_area): ?><a href="?s=listings" class="btn btn-o">Clear</a><?php endif; ?>
</form>
<div class="card" style="padding:0;overflow-x:auto">
<table>
    <tr><th style="width:40px"></th><th>ID</th><th>Title</th><th>Type</th><th>Area</th><th>Agent</th><th>Price (USD)</th><th>Status</th><th>Approved</th><th>Featured</th><th>Created</th><th>Actions</th></tr>
    <?php foreach ($listings as $lst): ?>
    <tr>
        <td>
            <?php if (!empty($lst['primary_image'])): ?>
            <img src="<?= htmlspecialchars($lst['primary_image']) ?>" alt="" style="width:40px;height:32px;object-fit:cover;border-radius:3px;display:block">
            <?php else: ?>
            <div style="width:40px;height:32px;background:#e5e7eb;border-radius:3px;display:flex;align-items:center;justify-content:center;font-size:10px;color:#9ca3af">—</div>
            <?php endif; ?>
        </td>
        <td style="color:#888;font-size:12px"><?= $lst['id'] ?></td>
        <td style="max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="<?= htmlspecialchars($lst['title'] ?? '') ?>"><?= htmlspecialchars($lst['title'] ?? '-') ?></td>
        <td><span class="badge b-blue"><?= htmlspecialchars($lst['listing_type'] ?? '-') ?></span></td>
        <td><?= htmlspecialchars($lst['area_label'] ?? '-') ?></td>
        <td><?= htmlspecialchars($lst['agent_name'] ?? '-') ?></td>
        <td><?= $lst['price_usd'] ? '$'.number_format((float)$lst['price_usd']) : '-' ?></td>
        <td><span class="badge <?= $status_badge[$lst['status']] ?? 'b-yellow' ?>"><?= htmlspecialchars($lst['status'] ?? '-') ?></span></td>
        <td><?= $lst['is_approved'] ? '<span class="badge b-green">Y</span>' : '<span class="badge b-red">N</span>' ?></td>
        <td><?= !empty($lst['is_featured']) ? '<span class="badge b-blue">Y</span>' : '<span class="badge b-yellow">N</span>' ?></td>
        <td style="color:#888;font-size:12px;white-space:nowrap"><?= isset($lst['created_at']) ? substr($lst['created_at'],0,10) : '-' ?></td>
        <td class="actions" style="white-space:nowrap">
            <?php if (!$lst['is_approved'] && $lst['status'] !== 'draft'): ?>
            <form method="POST" action="?s=listings&a=approve_listing" style="display:inline">
                <input type="hidden" name="listing_id" value="<?= $lst['id'] ?>">
                <button class="btn btn-g btn-sm">Approve</button>
            </form>
            <?php elseif ($lst['is_approved']): ?>
            <form method="POST" action="?s=listings&a=reject_listing" style="display:inline" onsubmit="return confirm('Reject this listing?')">
                <input type="hidden" name="listing_id" value="<?= $lst['id'] ?>">
                <button class="btn btn-o btn-sm">Reject</button>
            </form>
            <?php endif; ?>
            <form method="POST" action="?s=listings&a=toggle_featured" style="display:inline">
                <input type="hidden" name="listing_id" value="<?= $lst['id'] ?>">
                <button class="btn btn-o btn-sm"><?= !empty($lst['is_featured']) ? 'Unfeature' : 'Feature' ?></button>
            </form>
            <form method="POST" action="?s=listings&a=change_status" style="display:inline">
                <input type="hidden" name="listing_id" value="<?= $lst['id'] ?>">
                <select name="new_status" onchange="this.form.submit()" style="padding:3px 6px;font-size:12px;border:1px solid #d0d0d0;border-radius:4px;cursor:pointer">
                    <option value="">— set status —</option>
                    <option value="draft">draft</option>
                    <option value="active">active</option>
                    <option value="under_offer">under_offer</option>
                    <option value="sold">sold</option>
                    <option value="expired">expired</option>
                </select>
            </form>
            <form method="POST" action="?s=listings&a=delete_listing" style="display:inline" onsubmit="return confirm('Delete listing permanently?')">
                <input type="hidden" name="listing_id" value="<?= $lst['id'] ?>">
                <button class="btn btn-r btn-sm">Del</button>
            </form>
        </td>
    </tr>
    <?php endforeach; ?>
</table>
</div>
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
    var fieldNames = ['profile_photo_url','instagram_url','facebook_url','logo_url','phone','website_url'];
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
        .then(r => r.json())
        .then(data => {
            btn.textContent = 'Done — reload to see results';
            btn.disabled = false;
        })
        .catch(err => {
            btn.textContent = 'Error — check console';
            btn.disabled = false;
            console.error(err);
        });
}
</script>

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

    /* Collect current form values so scraper knows what is already filled */
    var existing = collectExisting(form);

    fetch('scrape_enrich.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({url: url, existing: existing})
    })
    .then(function(r) { return r.json(); })
    .then(function(data) { handleScanResult(data, entityType, form, resultsDiv, btn); })
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
        'instagram_url','facebook_url','linkedin_url','youtube_url','tiktok_url',
        'whatsapp_number','phone','email','website_url'];
    for (var i = 0; i < fieldNames.length; i++) {
        var inp = form.querySelector('[name="' + fieldNames[i] + '"]');
        if (inp && inp.value.trim()) existing[fieldNames[i]] = inp.value.trim();
    }
    return existing;
}

/* Handle scan/search results — shared by website scan and Google search */
function handleScanResult(data, entityType, form, resultsDiv, btn) {
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
        /* Auto-apply to empty fields */
        var inp = form.querySelector('[name="' + k + '"]');
        if (inp && !inp.value.trim()) {
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

</div><!-- main -->
</div><!-- shell -->
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
