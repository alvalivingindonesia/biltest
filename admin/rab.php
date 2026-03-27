<?php
/**
 * Build in Lombok — RAB Admin
 * CRUD for RAB (Rencana Anggaran Biaya) module data:
 * materials, units, item templates, calculator presets.
 * Access: /admin/rab.php (not linked from any public menu)
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
if (isset($_GET['logout'])) { session_destroy(); header('Location: rab.php'); exit; }
if (empty($_SESSION['admin_auth'])) { show_login($auth_error); exit; }

// ─── DB ──────────────────────────────────────────────────────────────
function get_db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
            DB_USER,
            DB_PASS,
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]
        );
    }
    return $pdo;
}

function fmt_idr($val): string {
    return 'Rp ' . number_format((float)$val, 0, ',', '.');
}

// ─── ROUTING ─────────────────────────────────────────────────────────
$section = $_GET['s'] ?? 'dashboard';
$action  = $_GET['a'] ?? 'list';
$id      = (int)($_GET['id'] ?? 0);
$msg     = '';

// ─── MATERIALS AJAX (GET, early exit) ────────────────────────────────
if ($section === 'materials' && $action === 'mat_ajax') {
    header('Content-Type: application/json; charset=utf-8');
    $db = get_db();

    $q      = trim($_GET['q']    ?? '');
    $f_cat  = trim($_GET['fc']   ?? '');
    $f_tier = trim($_GET['ft']   ?? '');
    $f_grp  = trim($_GET['fg']   ?? '');
    $sort   = $_GET['sort']      ?? 'name';
    $dir    = strtoupper($_GET['dir'] ?? 'ASC') === 'DESC' ? 'DESC' : 'ASC';

    $sort_map = [
        'name'     => 'm.name',
        'rate'     => 'm.default_rate',
        'category' => 'm.category',
        'tier'     => 'm.tier',
        'group'    => 'm.group_type',
        'unit'     => 'u.code',
    ];
    $sort_col = $sort_map[$sort] ?? 'm.name';

    $where = '1=1'; $params = [];
    if ($q)      { $where .= ' AND m.name LIKE ?';      $params[] = "%{$q}%"; }
    if ($f_cat)  { $where .= ' AND m.category = ?';     $params[] = $f_cat; }
    if ($f_tier) { $where .= ' AND m.tier = ?';         $params[] = $f_tier; }
    if ($f_grp)  { $where .= ' AND m.group_type = ?';   $params[] = $f_grp; }

    $stmt = $db->prepare("SELECT m.*, u.code AS unit_code FROM rab_materials m LEFT JOIN rab_units u ON u.id=m.unit_id WHERE {$where} ORDER BY {$sort_col} {$dir}, m.name ASC LIMIT 500");
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    echo json_encode(['ok' => true, 'count' => count($rows), 'rows' => $rows]);
    exit;
}

// ─── HANDLE POST ACTIONS ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $db = get_db();
    try {

        // ══════════════════════════════════════════════════════════════
        // MATERIALS
        // ══════════════════════════════════════════════════════════════
        if ($section === 'materials' && $action === 'save') {
            $name         = trim($_POST['name'] ?? '');
            $unit_id      = (int)($_POST['unit_id'] ?? 0);
            $default_rate = (float)($_POST['default_rate'] ?? 0);
            $currency     = trim($_POST['currency'] ?? 'IDR');
            $category     = trim($_POST['category'] ?? '');
            $is_composite = isset($_POST['is_composite']) ? 1 : 0;
            $tier         = trim($_POST['tier'] ?? '');
            $group_type   = trim($_POST['group_type'] ?? '');

            if ($id) {
                $db->prepare("UPDATE rab_materials SET name=?, unit_id=?, default_rate=?, currency=?, category=?, is_composite=?, tier=?, group_type=? WHERE id=?")
                   ->execute([$name, $unit_id, $default_rate, $currency, $category ?: null, $is_composite, $tier ?: null, $group_type ?: null, $id]);
                $_SESSION['flash'] = 'Material updated successfully.';
            } else {
                $db->prepare("INSERT INTO rab_materials (name, unit_id, default_rate, currency, category, is_composite, tier, group_type) VALUES (?,?,?,?,?,?,?,?)")
                   ->execute([$name, $unit_id, $default_rate, $currency, $category ?: null, $is_composite, $tier ?: null, $group_type ?: null]);
                $_SESSION['flash'] = 'Material created successfully.';
            }
            header('Location: rab.php?s=materials');
            exit;
        }

        if ($section === 'materials' && $action === 'copy') {
            $src_id = (int)($_POST['src_id'] ?? 0);
            $src = $db->prepare("SELECT * FROM rab_materials WHERE id=?");
            $src->execute([$src_id]);
            $orig = $src->fetch();
            if ($orig) {
                $db->prepare("INSERT INTO rab_materials (name, unit_id, default_rate, currency, category, is_composite, tier, group_type) VALUES (?,?,?,?,?,?,?,?)")
                   ->execute(['Copy of ' . $orig['name'], $orig['unit_id'], $orig['default_rate'], $orig['currency'], $orig['category'], $orig['is_composite'], $orig['tier'], $orig['group_type']]);
                $new_id = $db->lastInsertId();
                header('Location: rab.php?s=materials&a=edit&id=' . $new_id);
                exit;
            }
            header('Location: rab.php?s=materials');
            exit;
        }

        if ($section === 'materials' && $action === 'delete') {
            $del_id = (int)($_POST['del_id'] ?? 0);
            // Safety check: not used in rab_item_materials or rab_item_template_materials
            $used_items = $db->prepare("SELECT COUNT(*) FROM rab_item_materials WHERE material_id=?");
            $used_items->execute([$del_id]);
            $used_tpl = $db->prepare("SELECT COUNT(*) FROM rab_item_template_materials WHERE material_id=?");
            $used_tpl->execute([$del_id]);
            if ($used_items->fetchColumn() > 0 || $used_tpl->fetchColumn() > 0) {
                $_SESSION['flash'] = 'Error: Cannot delete — material is in use by items or templates.';
            } else {
                $db->prepare("DELETE FROM rab_materials WHERE id=?")->execute([$del_id]);
                $_SESSION['flash'] = 'Material deleted.';
            }
            header('Location: rab.php?s=materials');
            exit;
        }

        // ══════════════════════════════════════════════════════════════
        // UNITS
        // ══════════════════════════════════════════════════════════════
        if ($section === 'units' && $action === 'save') {
            $code      = trim($_POST['code'] ?? '');
            $name      = trim($_POST['name'] ?? '');
            $is_active = isset($_POST['is_active']) ? 1 : 0;

            if ($id) {
                $db->prepare("UPDATE rab_units SET code=?, name=?, is_active=? WHERE id=?")
                   ->execute([$code, $name, $is_active, $id]);
                $_SESSION['flash'] = 'Unit updated successfully.';
            } else {
                $db->prepare("INSERT INTO rab_units (code, name, is_active) VALUES (?,?,?)")
                   ->execute([$code, $name, $is_active]);
                $_SESSION['flash'] = 'Unit created successfully.';
            }
            header('Location: rab.php?s=units');
            exit;
        }

        if ($section === 'units' && $action === 'toggle') {
            $tog_id = (int)($_POST['tog_id'] ?? 0);
            $db->prepare("UPDATE rab_units SET is_active = 1 - is_active WHERE id=?")->execute([$tog_id]);
            header('Location: rab.php?s=units');
            exit;
        }

        if ($section === 'units' && $action === 'delete') {
            $del_id = (int)($_POST['del_id'] ?? 0);
            $used_mat = $db->prepare("SELECT COUNT(*) FROM rab_materials WHERE unit_id=?");
            $used_mat->execute([$del_id]);
            $used_item = $db->prepare("SELECT COUNT(*) FROM rab_items WHERE unit_id=?");
            $used_item->execute([$del_id]);
            $used_tpl = $db->prepare("SELECT COUNT(*) FROM rab_item_templates WHERE default_unit_id=?");
            $used_tpl->execute([$del_id]);
            if ($used_mat->fetchColumn() > 0 || $used_item->fetchColumn() > 0 || $used_tpl->fetchColumn() > 0) {
                $_SESSION['flash'] = 'Error: Cannot delete — unit is referenced by materials, items or templates.';
            } else {
                $db->prepare("DELETE FROM rab_units WHERE id=?")->execute([$del_id]);
                $_SESSION['flash'] = 'Unit deleted.';
            }
            header('Location: rab.php?s=units');
            exit;
        }

        // ══════════════════════════════════════════════════════════════
        // TEMPLATES
        // ══════════════════════════════════════════════════════════════
        if ($section === 'templates' && $action === 'save') {
            $discipline_id   = (int)($_POST['discipline_id'] ?? 0);
            $section_name    = trim($_POST['section_name'] ?? '');
            $name            = trim($_POST['name'] ?? '');
            $description     = trim($_POST['description'] ?? '');
            $default_unit_id = (int)($_POST['default_unit_id'] ?? 0);
            $is_active       = isset($_POST['is_active']) ? 1 : 0;
            $tier            = trim($_POST['tier'] ?? '');
            $group_type      = trim($_POST['group_type'] ?? '');

            if ($id) {
                $db->prepare("UPDATE rab_item_templates SET discipline_id=?, section_name=?, name=?, description=?, default_unit_id=?, is_active=?, tier=?, group_type=? WHERE id=?")
                   ->execute([$discipline_id, $section_name, $name, $description ?: null, $default_unit_id, $is_active, $tier ?: null, $group_type ?: null, $id]);
                $_SESSION['flash'] = 'Template updated successfully.';
            } else {
                $db->prepare("INSERT INTO rab_item_templates (discipline_id, section_name, name, description, default_unit_id, is_active, tier, group_type) VALUES (?,?,?,?,?,?,?,?)")
                   ->execute([$discipline_id, $section_name, $name, $description ?: null, $default_unit_id, $is_active, $tier ?: null, $group_type ?: null]);
                $_SESSION['flash'] = 'Template created successfully.';
            }
            header('Location: rab.php?s=templates');
            exit;
        }

        if ($section === 'templates' && $action === 'delete') {
            $del_id = (int)($_POST['del_id'] ?? 0);
            $used = $db->prepare("SELECT COUNT(*) FROM rab_items WHERE item_template_id=?");
            $used->execute([$del_id]);
            if ($used->fetchColumn() > 0) {
                $_SESSION['flash'] = 'Error: Cannot delete — template is in use by RAB items.';
            } else {
                $db->prepare("DELETE FROM rab_item_templates WHERE id=?")->execute([$del_id]);
                $_SESSION['flash'] = 'Template deleted.';
            }
            header('Location: rab.php?s=templates');
            exit;
        }

        // ══════════════════════════════════════════════════════════════
        // PRESETS
        // ══════════════════════════════════════════════════════════════
        if ($section === 'presets' && $action === 'save') {
            $name                     = trim($_POST['name'] ?? '');
            $description              = trim($_POST['description'] ?? '');
            $base_low                 = (float)($_POST['base_cost_per_m2_low'] ?? 0);
            $base_mid                 = (float)($_POST['base_cost_per_m2_mid'] ?? 0);
            $base_high                = (float)($_POST['base_cost_per_m2_high'] ?? 0);
            $pool_standard            = (float)($_POST['pool_cost_per_m2_standard'] ?? 0);
            $pool_infinity            = (float)($_POST['pool_cost_per_m2_infinity'] ?? 0);
            $deck_rate                = (float)($_POST['deck_cost_per_m2'] ?? 0);
            $rooftop_rate             = (float)($_POST['rooftop_cost_per_m2'] ?? 0);
            $location_factor          = (float)($_POST['location_factor'] ?? 1.0);
            $contingency_percent      = (float)($_POST['contingency_percent'] ?? 0);
            $is_default               = isset($_POST['is_default']) ? 1 : 0;

            // If setting as default, unset all others first
            if ($is_default) {
                $db->prepare("UPDATE rab_calculator_presets SET is_default=0")->execute();
            }

            if ($id) {
                $db->prepare("UPDATE rab_calculator_presets SET name=?, description=?,
                    base_cost_per_m2_low=?, base_cost_per_m2_mid=?, base_cost_per_m2_high=?,
                    pool_cost_per_m2_standard=?, pool_cost_per_m2_infinity=?,
                    deck_cost_per_m2=?, rooftop_cost_per_m2=?,
                    location_factor=?, contingency_percent=?, is_default=? WHERE id=?")
                   ->execute([
                       $name, $description ?: null,
                       $base_low, $base_mid, $base_high,
                       $pool_standard, $pool_infinity,
                       $deck_rate, $rooftop_rate,
                       $location_factor, $contingency_percent, $is_default, $id
                   ]);
                $_SESSION['flash'] = 'Preset updated successfully.';
            } else {
                $db->prepare("INSERT INTO rab_calculator_presets
                    (name, description, base_cost_per_m2_low, base_cost_per_m2_mid, base_cost_per_m2_high,
                     pool_cost_per_m2_standard, pool_cost_per_m2_infinity, deck_cost_per_m2, rooftop_cost_per_m2,
                     location_factor, contingency_percent, is_default)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?)")
                   ->execute([
                       $name, $description ?: null,
                       $base_low, $base_mid, $base_high,
                       $pool_standard, $pool_infinity,
                       $deck_rate, $rooftop_rate,
                       $location_factor, $contingency_percent, $is_default
                   ]);
                $_SESSION['flash'] = 'Preset created successfully.';
            }
            header('Location: rab.php?s=presets');
            exit;
        }

        if ($section === 'presets' && $action === 'delete') {
            $del_id = (int)($_POST['del_id'] ?? 0);
            $used = $db->prepare("SELECT COUNT(*) FROM rab_calculator_runs WHERE preset_id=?");
            $used->execute([$del_id]);
            if ($used->fetchColumn() > 0) {
                $_SESSION['flash'] = 'Error: Cannot delete — preset has associated calculator runs.';
            } else {
                $db->prepare("DELETE FROM rab_calculator_presets WHERE id=?")->execute([$del_id]);
                $_SESSION['flash'] = 'Preset deleted.';
            }
            header('Location: rab.php?s=presets');
            exit;
        }

        // ══════════════════════════════════════════════════════════════
        // BUILD TEMPLATES
        // ══════════════════════════════════════════════════════════════
        if ($section === 'build_templates' && $action === 'save') {
            $name         = trim($_POST['name'] ?? '');
            $code         = trim($_POST['code'] ?? '');
            $description  = trim($_POST['description'] ?? '');
            $default_tier = trim($_POST['default_tier'] ?? 'standard');
            $is_active    = isset($_POST['is_active']) ? 1 : 0;
            $sort_order   = (int)($_POST['sort_order'] ?? 0);

            if ($id) {
                $db->prepare("UPDATE rab_build_templates SET name=?, code=?, description=?, default_tier=?, is_active=?, sort_order=? WHERE id=?")
                   ->execute([$name, $code, $description ?: null, $default_tier, $is_active, $sort_order, $id]);
                $_SESSION['flash'] = 'Build template updated successfully.';
            } else {
                $db->prepare("INSERT INTO rab_build_templates (name, code, description, default_tier, is_active, sort_order) VALUES (?,?,?,?,?,?)")
                   ->execute([$name, $code, $description ?: null, $default_tier, $is_active, $sort_order]);
                $_SESSION['flash'] = 'Build template created successfully.';
            }
            header('Location: rab.php?s=build_templates');
            exit;
        }

        if ($section === 'build_templates' && $action === 'delete') {
            $del_id = (int)($_POST['del_id'] ?? 0);
            $db->prepare("DELETE FROM rab_build_templates WHERE id=?")->execute([$del_id]);
            $_SESSION['flash'] = 'Build template deleted.';
            header('Location: rab.php?s=build_templates');
            exit;
        }

        // ── BUILD TEMPLATE CONTENT — AJAX ──
        if ($section === 'build_templates' && $action === 'bt_ajax') {
            header('Content-Type: application/json; charset=utf-8');
            $bt_action = $_POST['bt_action'] ?? '';
            $result = ['ok' => false, 'msg' => ''];

            if ($bt_action === 'save_section') {
                $bt_id   = (int)($_POST['bt_id']   ?? 0);
                $sect_id = (int)($_POST['sect_id'] ?? 0);
                $disc_id = (int)($_POST['disc_id'] ?? 0);
                $name    = trim($_POST['name']      ?? '');
                if (!$name) { echo json_encode(['ok'=>false,'msg'=>'Section name required.']); exit; }
                if ($sect_id) {
                    $db->prepare("UPDATE rab_build_template_sections SET section_name=? WHERE id=?")->execute([$name, $sect_id]);
                    $result = ['ok' => true, 'sect_id' => $sect_id, 'msg' => 'Renamed.'];
                } else {
                    if (!$bt_id || !$disc_id) { echo json_encode(['ok'=>false,'msg'=>'Missing bt_id or disc_id.']); exit; }
                    $max_oi = $db->prepare("SELECT COALESCE(MAX(order_index),0)+1 FROM rab_build_template_sections WHERE build_template_id=? AND discipline_id=?");
                    $max_oi->execute([$bt_id, $disc_id]);
                    $oidx = (int)$max_oi->fetchColumn();
                    $db->prepare("INSERT INTO rab_build_template_sections (build_template_id, discipline_id, section_name, order_index) VALUES (?,?,?,?)")
                       ->execute([$bt_id, $disc_id, $name, $oidx]);
                    $sect_id = (int)$db->lastInsertId();
                    $result = ['ok' => true, 'sect_id' => $sect_id, 'name' => $name, 'msg' => 'Section created.'];
                }
            }

            elseif ($bt_action === 'delete_section') {
                $sect_id = (int)($_POST['sect_id'] ?? 0);
                $db->prepare("DELETE FROM rab_build_template_items WHERE build_template_section_id=?")->execute([$sect_id]);
                $db->prepare("DELETE FROM rab_build_template_sections WHERE id=?")->execute([$sect_id]);
                $result = ['ok' => true, 'msg' => 'Deleted.'];
            }

            elseif ($bt_action === 'save_item') {
                $sect_id = (int)($_POST['sect_id']  ?? 0);
                $item_id = (int)($_POST['item_id']  ?? 0);
                $name    = trim($_POST['name']       ?? '');
                $unit_id = (int)($_POST['unit_id']  ?? 0);
                $qty     = (float)($_POST['quantity'] ?? 1);
                $rate    = (float)($_POST['rate']    ?? 0);
                $tpl_id  = (int)($_POST['tpl_id']   ?? 0) ?: null;
                if (!$name || !$sect_id) { echo json_encode(['ok'=>false,'msg'=>'Name and section required.']); exit; }
                if ($item_id) {
                    $db->prepare("UPDATE rab_build_template_items SET name=?, unit_id=?, default_quantity=?, default_rate=?, item_template_id=? WHERE id=?")
                       ->execute([$name, $unit_id, $qty, $rate, $tpl_id, $item_id]);
                } else {
                    $max_oi = $db->prepare("SELECT COALESCE(MAX(order_index),0)+1 FROM rab_build_template_items WHERE build_template_section_id=?");
                    $max_oi->execute([$sect_id]);
                    $oidx = (int)$max_oi->fetchColumn();
                    $db->prepare("INSERT INTO rab_build_template_items (build_template_section_id, item_template_id, name, unit_id, default_quantity, default_rate, order_index) VALUES (?,?,?,?,?,?,?)")
                       ->execute([$sect_id, $tpl_id, $name, $unit_id, $qty, $rate, $oidx]);
                    $item_id = (int)$db->lastInsertId();
                }
                $uc_q = $db->prepare("SELECT code FROM rab_units WHERE id=?");
                $uc_q->execute([$unit_id]);
                $unit_code = (string)($uc_q->fetchColumn() ?: '');
                $result = ['ok' => true, 'item_id' => $item_id, 'unit_code' => $unit_code, 'unit_id' => $unit_id, 'name' => $name, 'quantity' => $qty, 'rate' => $rate, 'msg' => 'Saved.'];
            }

            elseif ($bt_action === 'delete_item') {
                $item_id = (int)($_POST['item_id'] ?? 0);
                $db->prepare("DELETE FROM rab_build_template_items WHERE id=?")->execute([$item_id]);
                $result = ['ok' => true, 'msg' => 'Deleted.'];
            }

            else { $result['msg'] = 'Unknown action.'; }

            echo json_encode($result);
            exit;
        }

    } catch (Exception $e) {
        $msg = 'Error: ' . $e->getMessage();
    }
}

// Flash message
if (isset($_SESSION['flash'])) {
    $msg = $_SESSION['flash'];
    unset($_SESSION['flash']);
}

// ─── PRE-FETCH COMMON DATA ────────────────────────────────────────────
$db = get_db();

// Counts for nav badges
$mat_count = 0; $tpl_count = 0; $preset_count = 0; $unit_count = 0; $btpl_count = 0;
try {
    $mat_count    = $db->query("SELECT COUNT(*) FROM rab_materials")->fetchColumn();
    $tpl_count    = $db->query("SELECT COUNT(*) FROM rab_item_templates")->fetchColumn();
    $preset_count = $db->query("SELECT COUNT(*) FROM rab_calculator_presets")->fetchColumn();
    $unit_count   = $db->query("SELECT COUNT(*) FROM rab_units")->fetchColumn();
    $btpl_count   = $db->query("SELECT COUNT(*) FROM rab_build_templates")->fetchColumn();
} catch (Exception $e) { /* tables may not exist yet */ }

// Units list (used in dropdowns across sections)
$units_list = [];
try {
    $units_list = $db->query("SELECT id, code, name FROM rab_units ORDER BY code")->fetchAll();
} catch (Exception $e) {}

// Disciplines list (used in templates)
$disciplines_list = [];
try {
    $disciplines_list = $db->query("SELECT id, code, name FROM rab_disciplines ORDER BY name")->fetchAll();
} catch (Exception $e) {}

?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title>RAB Admin — Build in Lombok</title>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',system-ui,sans-serif;background:#0f172a;color:#e2e8f0;line-height:1.5;font-size:14px}
a{color:#0c7c84;text-decoration:none}
a:hover{text-decoration:underline;color:#14b8a6}

/* ── Layout ── */
.shell{display:flex;flex-direction:column;min-height:100vh}

/* ── Header / Nav ── */
.header{background:#1e293b;border-bottom:1px solid rgba(255,255,255,.07);padding:0 24px;display:flex;align-items:stretch;gap:0}
.header-brand{display:flex;align-items:center;gap:10px;padding:14px 24px 14px 0;border-right:1px solid rgba(255,255,255,.07);margin-right:16px}
.header-brand svg{flex-shrink:0}
.header-brand span{font-weight:700;font-size:15px;color:#f1f5f9;white-space:nowrap}
.header-brand small{display:block;font-size:11px;color:#64748b;font-weight:400}
nav{display:flex;align-items:stretch;gap:0;flex:1}
nav a{display:flex;align-items:center;gap:6px;padding:0 16px;color:#94a3b8;font-size:13px;font-weight:500;border-bottom:3px solid transparent;white-space:nowrap;text-decoration:none;transition:color .15s,border-color .15s}
nav a:hover{color:#e2e8f0;text-decoration:none}
nav a.active{color:#0c7c84;border-bottom-color:#0c7c84}
.nav-badge{background:#0c7c84;color:#fff;font-size:10px;font-weight:700;padding:1px 6px;border-radius:9px}
.header-right{display:flex;align-items:center;margin-left:auto;gap:12px;padding-left:16px}
.logout-link{font-size:12px;color:#64748b}
.logout-link:hover{color:#e2e8f0;text-decoration:none}

/* ── Main content ── */
.main{flex:1;padding:28px 32px;max-width:1200px;width:100%}

/* ── Flash messages ── */
.msg{padding:11px 16px;border-radius:7px;margin-bottom:18px;font-size:13px;font-weight:500}
.msg-ok{background:#052e16;color:#4ade80;border:1px solid #166534}
.msg-err{background:#2d0a0a;color:#f87171;border:1px solid #991b1b}

/* ── Page headings ── */
h1{font-size:1.35rem;font-weight:700;color:#f1f5f9;margin-bottom:18px}
h2{font-size:1.1rem;font-weight:600;color:#f1f5f9;margin-bottom:14px}
h3{font-size:.95rem;font-weight:600;color:#cbd5e1;margin-bottom:10px}

/* ── Cards ── */
.card{background:#1e293b;border-radius:10px;padding:22px;margin-bottom:18px;border:1px solid rgba(255,255,255,.05)}

/* ── Stat boxes (dashboard) ── */
.stats{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:24px}
.stat{background:#1e293b;border-radius:10px;padding:18px 20px;text-align:center;border:1px solid rgba(255,255,255,.05);cursor:default}
.stat-n{font-size:2rem;font-weight:700;color:#0c7c84}
.stat-l{font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:#64748b;margin-top:4px}
.stat a{text-decoration:none}
.stat a:hover .stat-n{color:#14b8a6}

/* ── Buttons ── */
.btn{padding:7px 15px;border:none;border-radius:6px;font-size:13px;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:5px;text-decoration:none;line-height:1.4;transition:background .15s,opacity .15s}
.btn-p{background:#0c7c84;color:#fff}.btn-p:hover{background:#0e8f98;text-decoration:none}
.btn-r{background:#d4604a;color:#fff;font-size:12px}.btn-r:hover{background:#c0533e;text-decoration:none}
.btn-o{background:transparent;border:1px solid rgba(255,255,255,.15);color:#cbd5e1}.btn-o:hover{background:rgba(255,255,255,.05);text-decoration:none}
.btn-sm{padding:4px 10px;font-size:12px}
.btn-xs{padding:3px 8px;font-size:11px}

/* ── Tables ── */
table{width:100%;border-collapse:collapse;font-size:13px}
th,td{padding:9px 12px;text-align:left;border-bottom:1px solid rgba(255,255,255,.05)}
th{font-size:11px;text-transform:uppercase;letter-spacing:.04em;color:#64748b;background:#162032;font-weight:600}
tbody tr:hover{background:rgba(255,255,255,.03)}
.actions{display:flex;gap:6px;flex-wrap:nowrap;align-items:center}

/* ── Search / filter bar ── */
.search-bar{margin-bottom:16px;display:flex;gap:8px;flex-wrap:wrap;align-items:center}
.search-bar input[type="text"]{flex:1;min-width:160px}

/* ── Forms ── */
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}
.form-grid.cols3{grid-template-columns:1fr 1fr 1fr}
.form-grid.full{grid-template-columns:1fr}
.fg{display:flex;flex-direction:column;gap:5px}
.fg.span2{grid-column:span 2}
.fg.span3{grid-column:span 3}
.fg label{font-size:11px;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:.04em}
input[type=text],input[type=number],input[type=url],select,textarea{
    padding:8px 11px;border:1px solid rgba(255,255,255,.1);border-radius:6px;
    font-size:13px;font-family:inherit;width:100%;
    background:#0f172a;color:#e2e8f0;
    transition:border-color .15s
}
input[type=text]:focus,input[type=number]:focus,input[type=url]:focus,select:focus,textarea:focus{
    outline:none;border-color:#0c7c84
}
textarea{min-height:90px;resize:vertical}
select option{background:#1e293b}
.ck{display:flex;align-items:center;gap:8px;margin-top:6px;cursor:pointer}
.ck input[type=checkbox]{width:16px;height:16px;accent-color:#0c7c84;cursor:pointer}
.ck span{font-size:13px;color:#cbd5e1}

/* ── Badges ── */
.badge{display:inline-block;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:700}
.b-green{background:#052e16;color:#4ade80;border:1px solid #166534}
.b-red{background:#2d0a0a;color:#f87171;border:1px solid #991b1b}
.b-blue{background:#1e3a5f;color:#93c5fd;border:1px solid #1d4ed8}
.b-yellow{background:#3a2a00;color:#fbbf24;border:1px solid #92400e}
.b-teal{background:#042f2e;color:#5eead4;border:1px solid #0d9488}

/* ── Page header row ── */
.page-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:18px}
.page-header h1{margin-bottom:0}

/* ── Back link ── */
.back-link{display:inline-flex;align-items:center;gap:5px;color:#64748b;font-size:13px;margin-bottom:16px}
.back-link:hover{color:#e2e8f0;text-decoration:none}

/* ── IDR formatting ── */
.idr{font-variant-numeric:tabular-nums}

/* ── Discipline group header in templates ── */
.disc-header{background:#0c2336;padding:8px 12px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#0c7c84;border-bottom:1px solid rgba(12,124,132,.2)}

/* ── Quick link grid ── */
.quick-links{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-top:16px}
.ql{background:#0f172a;border:1px solid rgba(255,255,255,.07);border-radius:8px;padding:16px;text-align:center;text-decoration:none;transition:border-color .15s,background .15s}
.ql:hover{border-color:#0c7c84;background:#142535;text-decoration:none}
.ql-icon{font-size:1.6rem;margin-bottom:6px}
.ql-label{font-size:12px;font-weight:600;color:#cbd5e1}
.ql-sub{font-size:11px;color:#64748b;margin-top:2px}
</style>
</head>
<body>
<div class="shell">

<!-- HEADER -->
<div class="header">
    <div class="header-brand">
        <svg width="28" height="28" viewBox="0 0 28 28" fill="none" aria-label="RAB">
            <rect width="28" height="28" rx="6" fill="#0c7c84"/>
            <text x="4" y="20" font-family="system-ui,sans-serif" font-weight="800" font-size="13" fill="#fff">RAB</text>
        </svg>
        <span>RAB Admin<small>Build in Lombok</small></span>
    </div>
    <nav>
        <a href="?s=dashboard" class="<?= $section === 'dashboard' ? 'active' : '' ?>">Dashboard</a>
        <a href="?s=materials" class="<?= $section === 'materials' ? 'active' : '' ?>">
            Materials <span class="nav-badge"><?= $mat_count ?></span>
        </a>
        <a href="?s=units" class="<?= $section === 'units' ? 'active' : '' ?>">
            Units <span class="nav-badge"><?= $unit_count ?></span>
        </a>
        <a href="?s=templates" class="<?= $section === 'templates' ? 'active' : '' ?>">
            Templates <span class="nav-badge"><?= $tpl_count ?></span>
        </a>
        <a href="?s=presets" class="<?= $section === 'presets' ? 'active' : '' ?>">
            Presets <span class="nav-badge"><?= $preset_count ?></span>
        </a>
        <a href="?s=build_templates" class="<?= $section === 'build_templates' ? 'active' : '' ?>">
            Build Tpl <span class="nav-badge"><?= $btpl_count ?></span>
        </a>
    </nav>
    <div class="header-right">
        <a href="console.php" class="logout-link">← Console</a>
        <a href="?logout=1" class="logout-link">Logout</a>
    </div>
</div>

<!-- MAIN -->
<div class="main">

<?php if ($msg): ?>
    <div class="msg <?= strpos($msg, 'Error') !== false ? 'msg-err' : 'msg-ok' ?>"><?= htmlspecialchars($msg) ?></div>
<?php endif; ?>

<?php
// ═══════════════════════════════════════════════════════════════════════
// DASHBOARD
// ═══════════════════════════════════════════════════════════════════════
if ($section === 'dashboard'):
?>

<h1>RAB Module — Dashboard</h1>
<div class="stats">
    <div class="stat">
        <a href="?s=materials">
            <div class="stat-n"><?= $mat_count ?></div>
            <div class="stat-l">Materials</div>
        </a>
    </div>
    <div class="stat">
        <a href="?s=units">
            <div class="stat-n"><?= $unit_count ?></div>
            <div class="stat-l">Units</div>
        </a>
    </div>
    <div class="stat">
        <a href="?s=templates">
            <div class="stat-n"><?= $tpl_count ?></div>
            <div class="stat-l">Item Templates</div>
        </a>
    </div>
    <div class="stat">
        <a href="?s=presets">
            <div class="stat-n"><?= $preset_count ?></div>
            <div class="stat-l">Calculator Presets</div>
        </a>
    </div>
</div>

<?php
// Recent materials
$recent_mats = [];
try {
    $recent_mats = $db->query("SELECT m.*, u.code AS unit_code FROM rab_materials m LEFT JOIN rab_units u ON u.id=m.unit_id ORDER BY m.updated_at DESC LIMIT 8")->fetchAll();
} catch (Exception $e) {}

// Recent presets
$recent_presets = [];
try {
    $recent_presets = $db->query("SELECT * FROM rab_calculator_presets ORDER BY updated_at DESC LIMIT 5")->fetchAll();
} catch (Exception $e) {}
?>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:18px;">
    <div class="card">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px">
            <h3 style="margin-bottom:0">Recent Materials</h3>
            <a href="?s=materials&a=edit" class="btn btn-p btn-sm">+ Add</a>
        </div>
        <table>
            <thead><tr><th>Name</th><th>Unit</th><th>Rate</th></tr></thead>
            <tbody>
            <?php foreach ($recent_mats as $rm): ?>
            <tr>
                <td><a href="?s=materials&a=edit&id=<?= $rm['id'] ?>"><?= htmlspecialchars($rm['name']) ?></a></td>
                <td><code style="color:#94a3b8;font-size:11px"><?= htmlspecialchars($rm['unit_code'] ?? '—') ?></code></td>
                <td class="idr" style="color:#94a3b8;font-size:12px"><?= fmt_idr($rm['default_rate']) ?></td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($recent_mats)): ?>
            <tr><td colspan="3" style="color:#475569;font-size:12px;text-align:center;padding:16px">No materials yet.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
        <div style="margin-top:10px"><a href="?s=materials" style="font-size:12px;color:#64748b">View all →</a></div>
    </div>

    <div class="card">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px">
            <h3 style="margin-bottom:0">Calculator Presets</h3>
            <a href="?s=presets&a=edit" class="btn btn-p btn-sm">+ Add</a>
        </div>
        <table>
            <thead><tr><th>Name</th><th>Low</th><th>Mid</th><th>High</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($recent_presets as $rp): ?>
            <tr>
                <td>
                    <a href="?s=presets&a=edit&id=<?= $rp['id'] ?>"><?= htmlspecialchars($rp['name']) ?></a>
                    <?php if ($rp['is_default']): ?><span class="badge b-teal" style="margin-left:5px">default</span><?php endif; ?>
                </td>
                <td class="idr" style="font-size:11px;color:#94a3b8"><?= fmt_idr($rp['base_cost_per_m2_low']) ?></td>
                <td class="idr" style="font-size:11px;color:#94a3b8"><?= fmt_idr($rp['base_cost_per_m2_mid']) ?></td>
                <td class="idr" style="font-size:11px;color:#94a3b8"><?= fmt_idr($rp['base_cost_per_m2_high']) ?></td>
                <td><a href="?s=presets&a=edit&id=<?= $rp['id'] ?>" class="btn btn-o btn-xs">Edit</a></td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($recent_presets)): ?>
            <tr><td colspan="5" style="color:#475569;font-size:12px;text-align:center;padding:16px">No presets yet.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
        <div style="margin-top:10px"><a href="?s=presets" style="font-size:12px;color:#64748b">View all →</a></div>
    </div>
</div>

<div class="card" style="margin-top:0">
    <h3>Quick Actions</h3>
    <div class="quick-links">
        <a href="?s=materials&a=edit" class="ql">
            <div class="ql-icon">🧱</div>
            <div class="ql-label">Add Material</div>
            <div class="ql-sub">Library item</div>
        </a>
        <a href="?s=units&a=edit" class="ql">
            <div class="ql-icon">📐</div>
            <div class="ql-label">Add Unit</div>
            <div class="ql-sub">m², kg, pcs…</div>
        </a>
        <a href="?s=templates&a=edit" class="ql">
            <div class="ql-icon">📋</div>
            <div class="ql-label">Add Template</div>
            <div class="ql-sub">Line item template</div>
        </a>
        <a href="?s=presets&a=edit" class="ql">
            <div class="ql-icon">⚙️</div>
            <div class="ql-label">Add Preset</div>
            <div class="ql-sub">Calculator rates</div>
        </a>
    </div>
</div>

<?php
// ═══════════════════════════════════════════════════════════════════════
// MATERIALS — LIST
// ═══════════════════════════════════════════════════════════════════════
elseif ($section === 'materials' && $action === 'list'):
    // Initial full load
    $stmt = $db->prepare("SELECT m.*, u.code AS unit_code FROM rab_materials m LEFT JOIN rab_units u ON u.id=m.unit_id ORDER BY m.category, m.name LIMIT 500");
    $stmt->execute();
    $rows = $stmt->fetchAll();

    // Filter dropdown options
    $cats   = $db->query("SELECT DISTINCT category   FROM rab_materials WHERE category   IS NOT NULL AND category   != '' ORDER BY category")->fetchAll(PDO::FETCH_COLUMN);
    $groups = $db->query("SELECT DISTINCT group_type FROM rab_materials WHERE group_type IS NOT NULL AND group_type != '' ORDER BY group_type")->fetchAll(PDO::FETCH_COLUMN);
?>

<style>
.mat-sort-th{cursor:pointer;user-select:none;white-space:nowrap}
.mat-sort-th:hover{color:#e2e8f0}
.mat-sort-ind{font-size:10px;color:#0c7c84;margin-left:3px}
.mat-toolbar{display:flex;align-items:center;gap:8px;margin-bottom:16px;flex-wrap:nowrap}
.mat-toolbar h1{margin:0;flex-shrink:0}
.mat-toolbar input[type=text]{width:180px;flex-shrink:0}
.mat-toolbar select{width:130px;flex-shrink:0}
</style>

<div class="mat-toolbar">
    <h1>Materials <span id="mat-count" style="color:#64748b;font-size:1rem;font-weight:400">(<?= count($rows) ?>)</span></h1>
    <div style="flex:1"></div>
    <input type="text" id="mat-q" placeholder="Search name…" oninput="matDebounceFetch()">
    <select id="mat-fc" onchange="matFetch()">
        <option value="">All Categories</option>
        <?php foreach ($cats as $c): ?><option value="<?= htmlspecialchars($c) ?>"><?= htmlspecialchars($c) ?></option><?php endforeach; ?>
    </select>
    <select id="mat-ft" onchange="matFetch()">
        <option value="">All Tiers</option>
        <option value="economy">Economy</option>
        <option value="standard">Standard</option>
        <option value="premium">Premium</option>
    </select>
    <select id="mat-fg" onchange="matFetch()">
        <option value="">All Groups</option>
        <?php foreach ($groups as $g): ?><option value="<?= htmlspecialchars($g) ?>"><?= htmlspecialchars($g) ?></option><?php endforeach; ?>
    </select>
    <button class="btn btn-o btn-sm" onclick="matClear()">Clear</button>
    <span id="mat-loading" style="display:none;color:#64748b;font-size:12px">Loading…</span>
    <a href="?s=materials&a=edit" class="btn btn-p btn-sm" style="flex-shrink:0">+ Add Material</a>
</div>

<div class="card" style="padding:0;overflow:hidden">
<table>
    <thead>
        <tr>
            <th class="mat-sort-th" onclick="matSortBy('name')">Name <span class="mat-sort-ind" id="mat-si-name"></span></th>
            <th class="mat-sort-th" onclick="matSortBy('unit')">Unit <span class="mat-sort-ind" id="mat-si-unit"></span></th>
            <th class="mat-sort-th" onclick="matSortBy('rate')" style="text-align:right">Rate <span class="mat-sort-ind" id="mat-si-rate"></span></th>
            <th class="mat-sort-th" onclick="matSortBy('category')">Category <span class="mat-sort-ind" id="mat-si-category"></span></th>
            <th class="mat-sort-th" onclick="matSortBy('tier')">Tier <span class="mat-sort-ind" id="mat-si-tier"></span></th>
            <th class="mat-sort-th" onclick="matSortBy('group')">Group <span class="mat-sort-ind" id="mat-si-group"></span></th>
            <th>Type</th>
            <th style="width:160px">Actions</th>
        </tr>
    </thead>
    <tbody id="mat-tbody">
    <?php foreach ($rows as $r):
        $tc = ['economy'=>'#22c55e','standard'=>'#3b82f6','premium'=>'#a855f7'][$r['tier'] ?? ''] ?? '#64748b';
    ?>
    <tr>
        <td><?= htmlspecialchars($r['name']) ?></td>
        <td><code style="color:#94a3b8;font-size:12px"><?= htmlspecialchars($r['unit_code'] ?? '—') ?></code></td>
        <td class="idr" style="text-align:right"><?= fmt_idr($r['default_rate']) ?></td>
        <td><?= !empty($r['category']) ? htmlspecialchars($r['category']) : '<span style="color:#475569">—</span>' ?></td>
        <td><?= !empty($r['tier']) ? '<span class="badge" style="background:'.$tc.'20;color:'.$tc.';border:1px solid '.$tc.'40">'.ucfirst($r['tier']).'</span>' : '<span style="color:#475569">—</span>' ?></td>
        <td style="font-size:12px;color:#94a3b8"><?= !empty($r['group_type']) ? htmlspecialchars($r['group_type']) : '<span style="color:#475569">—</span>' ?></td>
        <td><?= $r['is_composite'] ? '<span class="badge b-blue">Composite</span>' : '<span class="badge" style="background:#1e293b;color:#64748b;border:1px solid rgba(255,255,255,.1)">Simple</span>' ?></td>
        <td>
            <div class="actions">
                <a href="?s=materials&a=edit&id=<?= $r['id'] ?>" class="btn btn-o btn-sm">Edit</a>
                <form method="POST" action="?s=materials&a=copy" style="display:inline">
                    <input type="hidden" name="src_id" value="<?= $r['id'] ?>">
                    <button type="submit" class="btn btn-o btn-sm">Copy</button>
                </form>
                <form method="POST" action="?s=materials&a=delete" style="display:inline" onsubmit="return confirm('Delete material: <?= htmlspecialchars(addslashes($r['name'])) ?>?')">
                    <input type="hidden" name="del_id" value="<?= $r['id'] ?>">
                    <button type="submit" class="btn btn-r btn-sm">Del</button>
                </form>
            </div>
        </td>
    </tr>
    <?php endforeach; ?>
    <?php if (empty($rows)): ?>
    <tr><td colspan="8" style="text-align:center;color:#475569;padding:24px">No materials found.</td></tr>
    <?php endif; ?>
    </tbody>
</table>
</div>

<script>
var matSortCol = '';
var matSortDir = 'ASC';
var matDebounceTimer = null;
var matTierColors = {economy:'#22c55e', standard:'#3b82f6', premium:'#a855f7'};

function matDebounceFetch() {
    clearTimeout(matDebounceTimer);
    matDebounceTimer = setTimeout(matFetch, 280);
}

function matFetch() {
    var q  = document.getElementById('mat-q').value;
    var fc = document.getElementById('mat-fc').value;
    var ft = document.getElementById('mat-ft').value;
    var fg = document.getElementById('mat-fg').value;
    var url = 'rab.php?s=materials&a=mat_ajax'
        + '&q='    + encodeURIComponent(q)
        + '&fc='   + encodeURIComponent(fc)
        + '&ft='   + encodeURIComponent(ft)
        + '&fg='   + encodeURIComponent(fg)
        + '&sort=' + encodeURIComponent(matSortCol)
        + '&dir='  + matSortDir;

    document.getElementById('mat-loading').style.display = '';
    var xhr = new XMLHttpRequest();
    xhr.open('GET', url, true);
    xhr.onload = function() {
        document.getElementById('mat-loading').style.display = 'none';
        try {
            var res = JSON.parse(xhr.responseText);
            if (res.ok) {
                matRenderRows(res.rows);
                document.getElementById('mat-count').textContent = '(' + res.count + ')';
            }
        } catch(e) {}
    };
    xhr.onerror = function() { document.getElementById('mat-loading').style.display = 'none'; };
    xhr.send();
}

function matSortBy(col) {
    if (matSortCol === col) {
        matSortDir = matSortDir === 'ASC' ? 'DESC' : 'ASC';
    } else {
        matSortCol = col;
        matSortDir = 'ASC';
    }
    matUpdateSortIndicators();
    matFetch();
}

function matClear() {
    document.getElementById('mat-q').value  = '';
    document.getElementById('mat-fc').value = '';
    document.getElementById('mat-ft').value = '';
    document.getElementById('mat-fg').value = '';
    matSortCol = '';
    matSortDir = 'ASC';
    matUpdateSortIndicators();
    matFetch();
}

function matUpdateSortIndicators() {
    ['name','unit','rate','category','tier','group'].forEach(function(c) {
        var el = document.getElementById('mat-si-' + c);
        if (!el) return;
        el.textContent = (c === matSortCol) ? (matSortDir === 'ASC' ? ' ▲' : ' ▼') : '';
    });
}

function matEsc(s) {
    return String(s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
function matEscJs(s) {
    return String(s || '').replace(/\\/g,'\\\\').replace(/'/g,"\\'");
}
function matFmtIdr(v) {
    return 'Rp\u00a0' + Math.round(parseFloat(v) || 0).toLocaleString('id-ID');
}

function matRenderRows(rows) {
    var tbody = document.getElementById('mat-tbody');
    if (!rows.length) {
        tbody.innerHTML = '<tr><td colspan="8" style="text-align:center;color:#475569;padding:24px">No materials found.</td></tr>';
        return;
    }
    var html = '';
    for (var i = 0; i < rows.length; i++) {
        var r = rows[i];
        var tier = r.tier || '';
        var tc = matTierColors[tier] || '#64748b';
        var tierHtml = tier
            ? '<span class="badge" style="background:' + tc + '20;color:' + tc + ';border:1px solid ' + tc + '40">' + tier.charAt(0).toUpperCase() + tier.slice(1) + '</span>'
            : '<span style="color:#475569">\u2014</span>';
        var typeHtml = (r.is_composite == 1)
            ? '<span class="badge b-blue">Composite</span>'
            : '<span class="badge" style="background:#1e293b;color:#64748b;border:1px solid rgba(255,255,255,.1)">Simple</span>';
        var catHtml  = r.category   ? matEsc(r.category)   : '<span style="color:#475569">\u2014</span>';
        var grpHtml  = r.group_type ? matEsc(r.group_type) : '<span style="color:#475569">\u2014</span>';
        html += '<tr>';
        html += '<td>' + matEsc(r.name) + '</td>';
        html += '<td><code style="color:#94a3b8;font-size:12px">' + matEsc(r.unit_code || '\u2014') + '</code></td>';
        html += '<td class="idr" style="text-align:right">' + matFmtIdr(r.default_rate) + '</td>';
        html += '<td>' + catHtml + '</td>';
        html += '<td>' + tierHtml + '</td>';
        html += '<td style="font-size:12px;color:#94a3b8">' + grpHtml + '</td>';
        html += '<td>' + typeHtml + '</td>';
        html += '<td><div class="actions">' +
            '<a href="?s=materials&a=edit&id=' + r.id + '" class="btn btn-o btn-sm">Edit</a>' +
            '<form method="POST" action="?s=materials&a=copy" style="display:inline">' +
            '<input type="hidden" name="src_id" value="' + r.id + '">' +
            '<button type="submit" class="btn btn-o btn-sm">Copy</button></form>' +
            '<form method="POST" action="?s=materials&a=delete" style="display:inline" onsubmit="return confirm(\'Delete material: ' + matEscJs(r.name) + '?\')">' +
            '<input type="hidden" name="del_id" value="' + r.id + '">' +
            '<button type="submit" class="btn btn-r btn-sm">Del</button></form>' +
            '</div></td>';
        html += '</tr>';
    }
    tbody.innerHTML = html;
}
</script>

<?php
// ═══════════════════════════════════════════════════════════════════════
// MATERIALS — CREATE / EDIT FORM
// ═══════════════════════════════════════════════════════════════════════
elseif ($section === 'materials' && $action === 'edit'):
    $row = ['id' => 0, 'name' => '', 'unit_id' => '', 'default_rate' => '', 'currency' => 'IDR', 'category' => '', 'is_composite' => 0, 'tier' => '', 'group_type' => ''];
    $group_types_list = $db->query("SELECT DISTINCT group_type FROM rab_materials WHERE group_type IS NOT NULL AND group_type != '' ORDER BY group_type")->fetchAll(PDO::FETCH_COLUMN);
    if ($id) {
        $stmt = $db->prepare("SELECT * FROM rab_materials WHERE id=?");
        $stmt->execute([$id]);
        $found = $stmt->fetch();
        if ($found) $row = $found;
    }
    $is_edit = (bool)$row['id'];
?>

<a href="?s=materials" class="back-link">← Back to Materials</a>
<div class="page-header">
    <h1><?= $is_edit ? 'Edit Material' : 'New Material' ?></h1>
</div>

<div class="card" style="max-width:740px">
    <form method="POST" action="?s=materials&a=save<?= $is_edit ? '&id=' . $id : '' ?>">
        <div class="form-grid">
            <div class="fg span2">
                <label>Material Name *</label>
                <input type="text" name="name" value="<?= htmlspecialchars($row['name']) ?>" required placeholder="e.g. K300 Ready-mix Concrete">
            </div>

            <div class="fg">
                <label>Unit *</label>
                <select name="unit_id" required>
                    <option value="">— Select unit —</option>
                    <?php foreach ($units_list as $u): ?>
                        <option value="<?= $u['id'] ?>" <?= (string)$row['unit_id'] === (string)$u['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($u['code']) ?> — <?= htmlspecialchars($u['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="fg">
                <label>Category</label>
                <input type="text" name="category" value="<?= htmlspecialchars($row['category'] ?? '') ?>" placeholder="e.g. Concrete, Steel, Labour…">
            </div>

            <div class="fg">
                <label>Default Rate (IDR)</label>
                <input type="number" name="default_rate" value="<?= htmlspecialchars($row['default_rate']) ?>" min="0" step="500" placeholder="0">
            </div>

            <div class="fg">
                <label>Currency</label>
                <select name="currency">
                    <option value="IDR" <?= $row['currency'] === 'IDR' ? 'selected' : '' ?>>IDR — Indonesian Rupiah</option>
                    <option value="USD" <?= $row['currency'] === 'USD' ? 'selected' : '' ?>>USD — US Dollar</option>
                    <option value="AUD" <?= $row['currency'] === 'AUD' ? 'selected' : '' ?>>AUD — Australian Dollar</option>
                </select>
            </div>

            <div class="fg">
                <label>Tier</label>
                <select name="tier">
                    <option value="" <?= empty($row['tier']) ? 'selected' : '' ?>>— No tier —</option>
                    <option value="economy" <?= ($row['tier'] ?? '') === 'economy' ? 'selected' : '' ?>>Economy</option>
                    <option value="standard" <?= ($row['tier'] ?? '') === 'standard' ? 'selected' : '' ?>>Standard</option>
                    <option value="premium" <?= ($row['tier'] ?? '') === 'premium' ? 'selected' : '' ?>>Premium</option>
                </select>
            </div>

            <div class="fg">
                <label>Group Type</label>
                <input type="text" name="group_type" value="<?= htmlspecialchars($row['group_type'] ?? '') ?>" placeholder="e.g. Ceilings, Floors, Walls, Roof…" list="group-types-dl">
                <datalist id="group-types-dl">
                    <?php foreach ($group_types_list as $gt): ?>
                    <option value="<?= htmlspecialchars($gt) ?>">
                    <?php endforeach; ?>
                </datalist>
                <small style="color:#64748b;font-size:11px;margin-top:3px">Determines which section this material appears in when adding items</small>
            </div>

            <div class="fg span2">
                <label>Type</label>
                <label class="ck">
                    <input type="checkbox" name="is_composite" value="1" <?= $row['is_composite'] ? 'checked' : '' ?>>
                    <span>Composite material (made up of component materials)</span>
                </label>
            </div>
        </div>

        <div style="margin-top:20px;display:flex;gap:10px;align-items:center">
            <button type="submit" class="btn btn-p"><?= $is_edit ? 'Save Changes' : 'Create Material' ?></button>
            <a href="?s=materials" class="btn btn-o">Cancel</a>
        </div>
    </form>
</div>

<?php
// ═══════════════════════════════════════════════════════════════════════
// UNITS — LIST
// ═══════════════════════════════════════════════════════════════════════
elseif ($section === 'units' && $action === 'list'):
    $rows = $db->query("SELECT * FROM rab_units ORDER BY code")->fetchAll();
?>

<div class="page-header">
    <h1>Units of Measurement <span style="color:#64748b;font-size:1rem;font-weight:400">(<?= count($rows) ?>)</span></h1>
    <a href="?s=units&a=edit" class="btn btn-p">+ Add Unit</a>
</div>

<div class="card" style="padding:0;overflow:hidden">
<table>
    <thead>
        <tr>
            <th>Code</th>
            <th>Name</th>
            <th>Status</th>
            <th style="width:160px">Actions</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
    <tr>
        <td><code style="color:#14b8a6;font-size:13px;font-weight:600"><?= htmlspecialchars($r['code']) ?></code></td>
        <td><?= htmlspecialchars($r['name']) ?></td>
        <td>
            <?php if ($r['is_active']): ?>
                <span class="badge b-green">Active</span>
            <?php else: ?>
                <span class="badge b-red">Inactive</span>
            <?php endif; ?>
        </td>
        <td>
            <div class="actions">
                <a href="?s=units&a=edit&id=<?= $r['id'] ?>" class="btn btn-o btn-sm">Edit</a>
                <form method="POST" action="?s=units&a=toggle" style="display:inline">
                    <input type="hidden" name="tog_id" value="<?= $r['id'] ?>">
                    <button type="submit" class="btn btn-o btn-sm"><?= $r['is_active'] ? 'Disable' : 'Enable' ?></button>
                </form>
                <form method="POST" action="?s=units&a=delete" style="display:inline" onsubmit="return confirm('Delete unit: <?= htmlspecialchars(addslashes($r['code'])) ?>?')">
                    <input type="hidden" name="del_id" value="<?= $r['id'] ?>">
                    <button type="submit" class="btn btn-r btn-sm">Del</button>
                </form>
            </div>
        </td>
    </tr>
    <?php endforeach; ?>
    <?php if (empty($rows)): ?>
    <tr><td colspan="4" style="text-align:center;color:#475569;padding:24px">No units found.</td></tr>
    <?php endif; ?>
    </tbody>
</table>
</div>

<?php
// ═══════════════════════════════════════════════════════════════════════
// UNITS — CREATE / EDIT FORM
// ═══════════════════════════════════════════════════════════════════════
elseif ($section === 'units' && $action === 'edit'):
    $row = ['id' => 0, 'code' => '', 'name' => '', 'is_active' => 1];
    if ($id) {
        $stmt = $db->prepare("SELECT * FROM rab_units WHERE id=?");
        $stmt->execute([$id]);
        $found = $stmt->fetch();
        if ($found) $row = $found;
    }
    $is_edit = (bool)$row['id'];
?>

<a href="?s=units" class="back-link">← Back to Units</a>
<div class="page-header">
    <h1><?= $is_edit ? 'Edit Unit' : 'New Unit' ?></h1>
</div>

<div class="card" style="max-width:480px">
    <form method="POST" action="?s=units&a=save<?= $is_edit ? '&id=' . $id : '' ?>">
        <div class="form-grid">
            <div class="fg">
                <label>Code *</label>
                <input type="text" name="code" value="<?= htmlspecialchars($row['code']) ?>" required placeholder="e.g. m2, kg, pcs">
            </div>
            <div class="fg">
                <label>Name *</label>
                <input type="text" name="name" value="<?= htmlspecialchars($row['name']) ?>" required placeholder="e.g. Square meter">
            </div>
            <div class="fg span2">
                <label>Status</label>
                <label class="ck">
                    <input type="checkbox" name="is_active" value="1" <?= $row['is_active'] ? 'checked' : '' ?>>
                    <span>Active (available for use in materials and items)</span>
                </label>
            </div>
        </div>
        <div style="margin-top:20px;display:flex;gap:10px">
            <button type="submit" class="btn btn-p"><?= $is_edit ? 'Save Changes' : 'Create Unit' ?></button>
            <a href="?s=units" class="btn btn-o">Cancel</a>
        </div>
    </form>
</div>

<?php
// ═══════════════════════════════════════════════════════════════════════
// TEMPLATES — LIST
// ═══════════════════════════════════════════════════════════════════════
elseif ($section === 'templates' && $action === 'list'):
    $f_disc = trim($_GET['fd'] ?? '');
    $q      = trim($_GET['q'] ?? '');

    $where = '1=1'; $params = [];
    if ($f_disc) {
        $where .= ' AND t.discipline_id = ?';
        $params[] = (int)$f_disc;
    }
    if ($q) {
        $where .= ' AND (t.name LIKE ? OR t.section_name LIKE ?)';
        $params[] = "%{$q}%";
        $params[] = "%{$q}%";
    }

    $stmt = $db->prepare("
        SELECT t.*, d.name AS discipline_name, d.code AS disc_code, u.code AS unit_code
        FROM rab_item_templates t
        LEFT JOIN rab_disciplines d ON d.id = t.discipline_id
        LEFT JOIN rab_units u ON u.id = t.default_unit_id
        WHERE {$where}
        ORDER BY d.name, t.section_name, t.name
    ");
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    // Group by discipline + section
    $grouped = [];
    foreach ($rows as $r) {
        $disc_key = $r['disc_code'] . '—' . $r['discipline_name'];
        $sec_key  = $r['section_name'];
        $grouped[$disc_key][$sec_key][] = $r;
    }
?>

<div class="page-header">
    <h1>Item Templates <span style="color:#64748b;font-size:1rem;font-weight:400">(<?= count($rows) ?>)</span></h1>
    <a href="?s=templates&a=edit" class="btn btn-p">+ Add Template</a>
</div>

<form class="search-bar" method="GET">
    <input type="hidden" name="s" value="templates">
    <input type="text" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Search name / section…" style="max-width:260px">
    <select name="fd" style="min-width:160px">
        <option value="">All Disciplines</option>
        <?php foreach ($disciplines_list as $d): ?>
            <option value="<?= $d['id'] ?>" <?= (string)$f_disc === (string)$d['id'] ? 'selected' : '' ?>><?= htmlspecialchars($d['name']) ?></option>
        <?php endforeach; ?>
    </select>
    <button class="btn btn-o" type="submit">Filter</button>
    <?php if ($q || $f_disc): ?><a href="?s=templates" class="btn btn-o">Clear</a><?php endif; ?>
</form>

<div class="card" style="padding:0;overflow:hidden">
<table>
    <thead>
        <tr>
            <th>Discipline / Section</th>
            <th>Name</th>
            <th>Default Unit</th>
            <th>Status</th>
            <th style="width:120px">Actions</th>
        </tr>
    </thead>
    <tbody>
    <?php if (empty($rows)): ?>
        <tr><td colspan="5" style="text-align:center;color:#475569;padding:24px">No templates found.</td></tr>
    <?php else: ?>
    <?php foreach ($grouped as $disc_key => $sections): ?>
        <?php
        $disc_parts = explode('—', $disc_key, 2);
        $disc_code  = $disc_parts[0];
        $disc_name  = $disc_parts[1] ?? $disc_key;
        ?>
        <tr>
            <td colspan="5" class="disc-header"><?= htmlspecialchars($disc_code) ?> — <?= htmlspecialchars($disc_name) ?></td>
        </tr>
        <?php foreach ($sections as $sec_name => $items): ?>
        <?php $first_in_sec = true; ?>
        <?php foreach ($items as $r): ?>
        <tr>
            <td style="color:#64748b;font-size:12px;padding-left:20px">
                <?php if ($first_in_sec): ?>
                    <?= htmlspecialchars($sec_name) ?>
                <?php else: ?>
                    &nbsp;
                <?php endif; ?>
                <?php $first_in_sec = false; ?>
            </td>
            <td><?= htmlspecialchars($r['name']) ?></td>
            <td><code style="color:#94a3b8;font-size:12px"><?= htmlspecialchars($r['unit_code'] ?? '—') ?></code></td>
            <td>
                <?php if ($r['is_active']): ?>
                    <span class="badge b-green">Active</span>
                <?php else: ?>
                    <span class="badge b-red">Inactive</span>
                <?php endif; ?>
            </td>
            <td>
                <div class="actions">
                    <a href="?s=templates&a=edit&id=<?= $r['id'] ?>" class="btn btn-o btn-sm">Edit</a>
                    <form method="POST" action="?s=templates&a=delete" style="display:inline" onsubmit="return confirm('Delete template: <?= htmlspecialchars(addslashes($r['name'])) ?>?')">
                        <input type="hidden" name="del_id" value="<?= $r['id'] ?>">
                        <button type="submit" class="btn btn-r btn-sm">Del</button>
                    </form>
                </div>
            </td>
        </tr>
        <?php endforeach; ?>
        <?php endforeach; ?>
    <?php endforeach; ?>
    <?php endif; ?>
    </tbody>
</table>
</div>

<?php
// ═══════════════════════════════════════════════════════════════════════
// TEMPLATES — CREATE / EDIT FORM
// ═══════════════════════════════════════════════════════════════════════
elseif ($section === 'templates' && $action === 'edit'):
    $row = ['id' => 0, 'discipline_id' => '', 'section_name' => '', 'name' => '', 'description' => '', 'default_unit_id' => '', 'is_active' => 1, 'tier' => '', 'group_type' => ''];
    $group_types_list = $db->query("SELECT DISTINCT group_type FROM rab_item_templates WHERE group_type IS NOT NULL AND group_type != '' ORDER BY group_type")->fetchAll(PDO::FETCH_COLUMN);
    if ($id) {
        $stmt = $db->prepare("SELECT * FROM rab_item_templates WHERE id=?");
        $stmt->execute([$id]);
        $found = $stmt->fetch();
        if ($found) $row = $found;
    }
    $is_edit = (bool)$row['id'];
?>

<a href="?s=templates" class="back-link">← Back to Templates</a>
<div class="page-header">
    <h1><?= $is_edit ? 'Edit Template' : 'New Item Template' ?></h1>
</div>

<div class="card" style="max-width:740px">
    <form method="POST" action="?s=templates&a=save<?= $is_edit ? '&id=' . $id : '' ?>">
        <div class="form-grid">
            <div class="fg">
                <label>Discipline *</label>
                <select name="discipline_id" required>
                    <option value="">— Select discipline —</option>
                    <?php foreach ($disciplines_list as $d): ?>
                        <option value="<?= $d['id'] ?>" <?= (string)$row['discipline_id'] === (string)$d['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($d['code']) ?> — <?= htmlspecialchars($d['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="fg">
                <label>Section Name *</label>
                <input type="text" name="section_name" value="<?= htmlspecialchars($row['section_name']) ?>" required placeholder="e.g. Foundations, Walls, Floors…">
            </div>

            <div class="fg span2">
                <label>Template Name *</label>
                <input type="text" name="name" value="<?= htmlspecialchars($row['name']) ?>" required placeholder="e.g. Reinforced Concrete Columns">
            </div>

            <div class="fg span2">
                <label>Description</label>
                <textarea name="description" placeholder="Optional notes or specification details…"><?= htmlspecialchars($row['description'] ?? '') ?></textarea>
            </div>

            <div class="fg">
                <label>Default Unit *</label>
                <select name="default_unit_id" required>
                    <option value="">— Select unit —</option>
                    <?php foreach ($units_list as $u): ?>
                        <option value="<?= $u['id'] ?>" <?= (string)$row['default_unit_id'] === (string)$u['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($u['code']) ?> — <?= htmlspecialchars($u['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="fg">
                <label>Tier</label>
                <select name="tier">
                    <option value="" <?= empty($row['tier']) ? 'selected' : '' ?>>— No tier —</option>
                    <option value="economy" <?= ($row['tier'] ?? '') === 'economy' ? 'selected' : '' ?>>Economy</option>
                    <option value="standard" <?= ($row['tier'] ?? '') === 'standard' ? 'selected' : '' ?>>Standard</option>
                    <option value="premium" <?= ($row['tier'] ?? '') === 'premium' ? 'selected' : '' ?>>Premium</option>
                </select>
            </div>

            <div class="fg">
                <label>Group Type</label>
                <input type="text" name="group_type" value="<?= htmlspecialchars($row['group_type'] ?? '') ?>" placeholder="e.g. Ceilings, Floors, Walls…" list="tpl-group-types-dl">
                <datalist id="tpl-group-types-dl">
                    <?php foreach ($group_types_list as $gt): ?>
                    <option value="<?= htmlspecialchars($gt) ?>">
                    <?php endforeach; ?>
                </datalist>
                <small style="color:#64748b;font-size:11px;margin-top:3px">Used for context-aware material filtering in RAB Tool</small>
            </div>

            <div class="fg">
                <label>Status</label>
                <label class="ck" style="margin-top:10px">
                    <input type="checkbox" name="is_active" value="1" <?= $row['is_active'] ? 'checked' : '' ?>>
                    <span>Active (visible when building RABs)</span>
                </label>
            </div>
        </div>

        <div style="margin-top:20px;display:flex;gap:10px">
            <button type="submit" class="btn btn-p"><?= $is_edit ? 'Save Changes' : 'Create Template' ?></button>
            <a href="?s=templates" class="btn btn-o">Cancel</a>
        </div>
    </form>
</div>

<?php
// ═══════════════════════════════════════════════════════════════════════
// PRESETS — LIST
// ═══════════════════════════════════════════════════════════════════════
elseif ($section === 'presets' && $action === 'list'):
    $rows = [];
    try {
        $rows = $db->query("SELECT * FROM rab_calculator_presets ORDER BY is_default DESC, name")->fetchAll();
    } catch (Exception $e) {}
?>

<div class="page-header">
    <h1>Calculator Presets <span style="color:#64748b;font-size:1rem;font-weight:400">(<?= count($rows) ?>)</span></h1>
    <a href="?s=presets&a=edit" class="btn btn-p">+ Add Preset</a>
</div>

<div class="card" style="padding:0;overflow:hidden">
<table>
    <thead>
        <tr>
            <th>Name</th>
            <th>Description</th>
            <th>Low (Rp/m²)</th>
            <th>Mid (Rp/m²)</th>
            <th>High (Rp/m²)</th>
            <th>Contingency</th>
            <th>Location ×</th>
            <th style="width:120px">Actions</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
    <tr>
        <td>
            <?= htmlspecialchars($r['name']) ?>
            <?php if ($r['is_default']): ?><span class="badge b-teal" style="margin-left:6px">Default</span><?php endif; ?>
        </td>
        <td style="color:#64748b;font-size:12px;max-width:180px"><?= $r['description'] ? htmlspecialchars(mb_substr($r['description'], 0, 80)) : '—' ?></td>
        <td class="idr" style="font-size:12px"><?= fmt_idr($r['base_cost_per_m2_low']) ?></td>
        <td class="idr" style="font-size:12px"><?= fmt_idr($r['base_cost_per_m2_mid']) ?></td>
        <td class="idr" style="font-size:12px"><?= fmt_idr($r['base_cost_per_m2_high']) ?></td>
        <td><?= number_format((float)$r['contingency_percent'], 2) ?>%</td>
        <td><?= number_format((float)$r['location_factor'], 3) ?>×</td>
        <td>
            <div class="actions">
                <a href="?s=presets&a=edit&id=<?= $r['id'] ?>" class="btn btn-o btn-sm">Edit</a>
                <form method="POST" action="?s=presets&a=delete" style="display:inline" onsubmit="return confirm('Delete preset: <?= htmlspecialchars(addslashes($r['name'])) ?>?')">
                    <input type="hidden" name="del_id" value="<?= $r['id'] ?>">
                    <button type="submit" class="btn btn-r btn-sm">Del</button>
                </form>
            </div>
        </td>
    </tr>
    <?php endforeach; ?>
    <?php if (empty($rows)): ?>
    <tr><td colspan="8" style="text-align:center;color:#475569;padding:24px">No presets yet. Add your first preset to enable the cost calculator.</td></tr>
    <?php endif; ?>
    </tbody>
</table>
</div>

<?php if (!empty($rows)): ?>
<div class="card" style="margin-top:0">
    <h3 style="margin-bottom:12px">Full Rate Details</h3>
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(360px,1fr));gap:14px">
    <?php foreach ($rows as $r): ?>
    <div style="background:#0f172a;border-radius:8px;padding:16px;border:1px solid rgba(255,255,255,.07)">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:12px">
            <strong style="color:#f1f5f9"><?= htmlspecialchars($r['name']) ?></strong>
            <?php if ($r['is_default']): ?><span class="badge b-teal">Default</span><?php endif; ?>
        </div>
        <table style="font-size:12px;width:100%">
            <tr><td style="color:#64748b;padding:2px 0">Base Low</td><td class="idr" style="text-align:right"><?= fmt_idr($r['base_cost_per_m2_low']) ?>/m²</td></tr>
            <tr><td style="color:#64748b;padding:2px 0">Base Mid</td><td class="idr" style="text-align:right"><?= fmt_idr($r['base_cost_per_m2_mid']) ?>/m²</td></tr>
            <tr><td style="color:#64748b;padding:2px 0">Base High</td><td class="idr" style="text-align:right"><?= fmt_idr($r['base_cost_per_m2_high']) ?>/m²</td></tr>
            <tr><td style="color:#64748b;padding:2px 0">Pool Standard</td><td class="idr" style="text-align:right"><?= fmt_idr($r['pool_cost_per_m2_standard']) ?>/m²</td></tr>
            <tr><td style="color:#64748b;padding:2px 0">Pool Infinity</td><td class="idr" style="text-align:right"><?= fmt_idr($r['pool_cost_per_m2_infinity']) ?>/m²</td></tr>
            <tr><td style="color:#64748b;padding:2px 0">Deck</td><td class="idr" style="text-align:right"><?= fmt_idr($r['deck_cost_per_m2']) ?>/m²</td></tr>
            <tr><td style="color:#64748b;padding:2px 0">Rooftop Walkable</td><td class="idr" style="text-align:right"><?= fmt_idr($r['rooftop_cost_per_m2']) ?>/m²</td></tr>
            <tr><td style="color:#64748b;padding:2px 0">Location Factor</td><td style="text-align:right"><?= number_format((float)$r['location_factor'], 3) ?>×</td></tr>
            <tr><td style="color:#64748b;padding:2px 0">Contingency</td><td style="text-align:right"><?= number_format((float)$r['contingency_percent'], 2) ?>%</td></tr>
        </table>
        <div style="margin-top:10px;text-align:right">
            <a href="?s=presets&a=edit&id=<?= $r['id'] ?>" class="btn btn-o btn-sm">Edit</a>
        </div>
    </div>
    <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<?php
// ═══════════════════════════════════════════════════════════════════════
// PRESETS — CREATE / EDIT FORM
// ═══════════════════════════════════════════════════════════════════════
elseif ($section === 'presets' && $action === 'edit'):
    $row = [
        'id' => 0, 'name' => '', 'description' => '',
        'base_cost_per_m2_low' => '', 'base_cost_per_m2_mid' => '', 'base_cost_per_m2_high' => '',
        'pool_cost_per_m2_standard' => '', 'pool_cost_per_m2_infinity' => '',
        'deck_cost_per_m2' => '', 'rooftop_cost_per_m2' => '',
        'location_factor' => '1.000', 'contingency_percent' => '10.00',
        'is_default' => 0,
    ];
    if ($id) {
        $stmt = $db->prepare("SELECT * FROM rab_calculator_presets WHERE id=?");
        $stmt->execute([$id]);
        $found = $stmt->fetch();
        if ($found) $row = $found;
    }
    $is_edit = (bool)$row['id'];
?>

<a href="?s=presets" class="back-link">← Back to Presets</a>
<div class="page-header">
    <h1><?= $is_edit ? 'Edit Preset' : 'New Calculator Preset' ?></h1>
</div>

<div class="card" style="max-width:860px">
    <form method="POST" action="?s=presets&a=save<?= $is_edit ? '&id=' . $id : '' ?>">

        <div class="form-grid" style="margin-bottom:20px">
            <div class="fg">
                <label>Preset Name *</label>
                <input type="text" name="name" value="<?= htmlspecialchars($row['name']) ?>" required placeholder="e.g. Lombok Standard 2026">
            </div>
            <div class="fg">
                <label>Status</label>
                <label class="ck" style="margin-top:10px">
                    <input type="checkbox" name="is_default" value="1" <?= $row['is_default'] ? 'checked' : '' ?>>
                    <span>Default preset (used by calculator by default)</span>
                </label>
            </div>
            <div class="fg span2">
                <label>Description</label>
                <textarea name="description" placeholder="Notes on this preset — e.g. applicable region, year, quality assumptions…"><?= htmlspecialchars($row['description'] ?? '') ?></textarea>
            </div>
        </div>

        <h3 style="margin-bottom:14px;padding-bottom:8px;border-bottom:1px solid rgba(255,255,255,.07)">Base Build Cost (per m²)</h3>
        <div class="form-grid cols3" style="margin-bottom:20px">
            <div class="fg">
                <label>Low Quality (Rp/m²)</label>
                <input type="number" name="base_cost_per_m2_low" value="<?= htmlspecialchars($row['base_cost_per_m2_low']) ?>" min="0" step="100000" placeholder="5500000">
            </div>
            <div class="fg">
                <label>Mid Quality (Rp/m²)</label>
                <input type="number" name="base_cost_per_m2_mid" value="<?= htmlspecialchars($row['base_cost_per_m2_mid']) ?>" min="0" step="100000" placeholder="7500000">
            </div>
            <div class="fg">
                <label>High Quality (Rp/m²)</label>
                <input type="number" name="base_cost_per_m2_high" value="<?= htmlspecialchars($row['base_cost_per_m2_high']) ?>" min="0" step="100000" placeholder="10000000">
            </div>
        </div>

        <h3 style="margin-bottom:14px;padding-bottom:8px;border-bottom:1px solid rgba(255,255,255,.07)">Pool Rates (per m²)</h3>
        <div class="form-grid" style="margin-bottom:20px">
            <div class="fg">
                <label>Standard Pool (Rp/m²)</label>
                <input type="number" name="pool_cost_per_m2_standard" value="<?= htmlspecialchars($row['pool_cost_per_m2_standard']) ?>" min="0" step="100000" placeholder="3500000">
            </div>
            <div class="fg">
                <label>Infinity Pool (Rp/m²)</label>
                <input type="number" name="pool_cost_per_m2_infinity" value="<?= htmlspecialchars($row['pool_cost_per_m2_infinity']) ?>" min="0" step="100000" placeholder="5500000">
            </div>
        </div>

        <h3 style="margin-bottom:14px;padding-bottom:8px;border-bottom:1px solid rgba(255,255,255,.07)">Additional Area Rates (per m²)</h3>
        <div class="form-grid" style="margin-bottom:20px">
            <div class="fg">
                <label>Deck / Terrace (Rp/m²)</label>
                <input type="number" name="deck_cost_per_m2" value="<?= htmlspecialchars($row['deck_cost_per_m2']) ?>" min="0" step="50000" placeholder="1200000">
            </div>
            <div class="fg">
                <label>Walkable Rooftop (Rp/m²)</label>
                <input type="number" name="rooftop_cost_per_m2" value="<?= htmlspecialchars($row['rooftop_cost_per_m2']) ?>" min="0" step="50000" placeholder="2500000">
            </div>
        </div>

        <h3 style="margin-bottom:14px;padding-bottom:8px;border-bottom:1px solid rgba(255,255,255,.07)">Adjustment Factors</h3>
        <div class="form-grid" style="margin-bottom:24px">
            <div class="fg">
                <label>Location Factor</label>
                <input type="number" name="location_factor" value="<?= htmlspecialchars($row['location_factor']) ?>" min="0.1" max="5" step="0.001" placeholder="1.000">
                <small style="color:#64748b;font-size:11px;margin-top:3px">Multiplier applied to all costs (1.000 = no adjustment)</small>
            </div>
            <div class="fg">
                <label>Contingency %</label>
                <input type="number" name="contingency_percent" value="<?= htmlspecialchars($row['contingency_percent']) ?>" min="0" max="100" step="0.5" placeholder="10.00">
                <small style="color:#64748b;font-size:11px;margin-top:3px">Added on top of subtotal (e.g. 10.00 = 10%)</small>
            </div>
        </div>

        <div style="display:flex;gap:10px">
            <button type="submit" class="btn btn-p"><?= $is_edit ? 'Save Changes' : 'Create Preset' ?></button>
            <a href="?s=presets" class="btn btn-o">Cancel</a>
        </div>
    </form>
</div>

<?php
// ═══════════════════════════════════════════════════════════════════════
// BUILD TEMPLATES — LIST
// ═══════════════════════════════════════════════════════════════════════
elseif ($section === 'build_templates' && $action === 'list'):
    $rows = $db->query("SELECT * FROM rab_build_templates ORDER BY sort_order, name")->fetchAll();
?>

<div class="page-header">
    <h1>Build Templates <span style="color:#64748b;font-size:1rem;font-weight:400">(<?= count($rows) ?>)</span></h1>
    <a href="?s=build_templates&a=edit" class="btn btn-p">+ Add Template</a>
</div>

<p style="color:#94a3b8;font-size:13px;margin-bottom:16px">Build templates pre-populate a new RAB with sections and items for specific construction methods (RCC, Steel, Timber, etc.).</p>

<div class="card" style="padding:0;overflow:hidden">
<table>
    <thead>
        <tr>
            <th>Name</th>
            <th>Code</th>
            <th>Default Tier</th>
            <th>Order</th>
            <th>Status</th>
            <th style="width:120px">Actions</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
    <tr>
        <td>
            <strong><?= htmlspecialchars($r['name']) ?></strong>
            <?php if ($r['description']): ?>
            <br><small style="color:#64748b;font-size:11px"><?= htmlspecialchars(substr($r['description'], 0, 120)) ?><?= strlen($r['description']) > 120 ? '…' : '' ?></small>
            <?php endif; ?>
        </td>
        <td><code style="color:#94a3b8;font-size:12px"><?= htmlspecialchars($r['code']) ?></code></td>
        <td>
            <?php
            $tier_colors = array('economy' => '#22c55e', 'standard' => '#3b82f6', 'premium' => '#a855f7');
            $tc = isset($tier_colors[$r['default_tier']]) ? $tier_colors[$r['default_tier']] : '#64748b';
            ?>
            <span class="badge" style="background:<?= $tc ?>20;color:<?= $tc ?>;border:1px solid <?= $tc ?>40"><?= ucfirst($r['default_tier']) ?></span>
        </td>
        <td style="color:#64748b"><?= $r['sort_order'] ?></td>
        <td>
            <?php if ($r['is_active']): ?>
                <span class="badge" style="background:#22c55e20;color:#22c55e;border:1px solid #22c55e40">Active</span>
            <?php else: ?>
                <span class="badge" style="background:#1e293b;color:#64748b;border:1px solid rgba(255,255,255,.1)">Inactive</span>
            <?php endif; ?>
        </td>
        <td>
            <div class="actions">
                <a href="?s=build_templates&a=edit&id=<?= $r['id'] ?>" class="btn btn-o btn-sm">Edit</a>
                <form method="POST" action="?s=build_templates&a=delete" style="display:inline" onsubmit="return confirm('Delete template: <?= htmlspecialchars(addslashes($r['name'])) ?>?')">
                    <input type="hidden" name="del_id" value="<?= $r['id'] ?>">
                    <button type="submit" class="btn btn-r btn-sm">Del</button>
                </form>
            </div>
        </td>
    </tr>
    <?php endforeach; ?>
    <?php if (empty($rows)): ?>
    <tr><td colspan="6" style="text-align:center;color:#475569;padding:24px">No build templates yet. Run the migration SQL to seed default templates.</td></tr>
    <?php endif; ?>
    </tbody>
</table>
</div>

<?php
// ═══════════════════════════════════════════════════════════════════════
// BUILD TEMPLATES — CREATE / EDIT
// ═══════════════════════════════════════════════════════════════════════
elseif ($section === 'build_templates' && $action === 'edit'):
    $row = ['id' => 0, 'name' => '', 'code' => '', 'description' => '', 'default_tier' => 'standard', 'is_active' => 1, 'sort_order' => 0];
    if ($id) {
        $stmt = $db->prepare("SELECT * FROM rab_build_templates WHERE id=?");
        $stmt->execute([$id]);
        $found = $stmt->fetch();
        if ($found) $row = $found;
    }
    $is_edit = (bool)$row['id'];

    // Load sections + items grouped by discipline (only if editing existing template)
    $bt_sections_by_disc = [];
    if ($is_edit) {
        foreach ($disciplines_list as $d) {
            $sq = $db->prepare("SELECT * FROM rab_build_template_sections WHERE build_template_id=? AND discipline_id=? ORDER BY order_index");
            $sq->execute([$id, $d['id']]);
            $sects = $sq->fetchAll();
            foreach ($sects as &$s) {
                $iq = $db->prepare("SELECT bti.*, u.code AS unit_code FROM rab_build_template_items bti LEFT JOIN rab_units u ON u.id=bti.unit_id WHERE bti.build_template_section_id=? ORDER BY bti.order_index");
                $iq->execute([$s['id']]);
                $s['items'] = $iq->fetchAll();
            }
            unset($s);
            $bt_sections_by_disc[$d['id']] = $sects;
        }
    }
?>

<style>
.bt-tabs{display:flex;gap:0;border-bottom:2px solid rgba(255,255,255,.08);margin-bottom:20px}
.bt-tab{padding:9px 20px;font-size:13px;font-weight:600;color:#64748b;cursor:pointer;border-bottom:2px solid transparent;margin-bottom:-2px;transition:color .15s,border-color .15s;user-select:none}
.bt-tab:hover{color:#e2e8f0}
.bt-tab.active{color:#0c7c84;border-bottom-color:#0c7c84}
.bt-panel{display:none}.bt-panel.active{display:block}
.bt-sect{background:#0f172a;border:1px solid rgba(255,255,255,.07);border-radius:8px;margin-bottom:12px;overflow:hidden}
.bt-sect-hdr{display:flex;align-items:center;gap:10px;padding:10px 14px;background:#162032;cursor:pointer}
.bt-sect-title{font-weight:600;font-size:13px;color:#e2e8f0;flex:1}
.bt-sect-body{padding:14px}
.bt-item-table{width:100%;border-collapse:collapse;font-size:13px;margin-bottom:10px}
.bt-item-table th{font-size:11px;text-transform:uppercase;letter-spacing:.04em;color:#64748b;background:#0a1628;padding:7px 10px;text-align:left}
.bt-item-table td{padding:7px 10px;border-bottom:1px solid rgba(255,255,255,.04)}
.bt-item-table tbody tr:hover{background:rgba(255,255,255,.02)}
.bt-add-area{margin-top:8px;padding-top:8px;border-top:1px dashed rgba(255,255,255,.08)}
.bt-add-form{background:#0a1628;border:1px solid rgba(255,255,255,.08);border-radius:7px;padding:14px;margin-top:8px}
.bt-edit-grid{display:grid;grid-template-columns:2fr 1fr 1fr 1fr;gap:10px;margin-bottom:10px}
.num-right{text-align:right}
</style>

<a href="?s=build_templates" class="back-link">← Back to Build Templates</a>
<div class="page-header">
    <h1><?= $is_edit ? 'Edit Build Template' : 'New Build Template' ?></h1>
</div>

<div class="card" style="max-width:740px">
    <form method="POST" action="?s=build_templates&a=save<?= $is_edit ? '&id=' . $id : '' ?>">
        <div class="form-grid">
            <div class="fg span2">
                <label>Template Name *</label>
                <input type="text" name="name" value="<?= htmlspecialchars($row['name']) ?>" required placeholder="e.g. Re-enforced Concrete (RCC) — Standard">
            </div>
            <div class="fg">
                <label>Code *</label>
                <input type="text" name="code" value="<?= htmlspecialchars($row['code']) ?>" required placeholder="e.g. rcc_standard">
                <small style="color:#64748b;font-size:11px;margin-top:3px">Unique identifier (lowercase, underscores)</small>
            </div>
            <div class="fg">
                <label>Default Tier</label>
                <select name="default_tier">
                    <option value="economy" <?= $row['default_tier'] === 'economy' ? 'selected' : '' ?>>Economy</option>
                    <option value="standard" <?= $row['default_tier'] === 'standard' ? 'selected' : '' ?>>Standard</option>
                    <option value="premium" <?= $row['default_tier'] === 'premium' ? 'selected' : '' ?>>Premium</option>
                </select>
            </div>
            <div class="fg span2">
                <label>Description</label>
                <textarea name="description" rows="3" placeholder="Brief description of this construction method…"><?= htmlspecialchars($row['description'] ?? '') ?></textarea>
            </div>
            <div class="fg">
                <label>Sort Order</label>
                <input type="number" name="sort_order" value="<?= (int)$row['sort_order'] ?>" min="0" step="1">
            </div>
            <div class="fg">
                <label>Status</label>
                <label class="ck">
                    <input type="checkbox" name="is_active" value="1" <?= $row['is_active'] ? 'checked' : '' ?>>
                    <span>Active (available for selection in RAB Tool)</span>
                </label>
            </div>
        </div>
        <div style="margin-top:20px;display:flex;gap:10px">
            <button type="submit" class="btn btn-p"><?= $is_edit ? 'Save Changes' : 'Create Template' ?></button>
            <a href="?s=build_templates" class="btn btn-o">Cancel</a>
        </div>
    </form>
</div>

<?php if ($is_edit): ?>

<div style="margin-top:28px">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px">
        <h2 style="margin-bottom:0">Template Content</h2>
        <span style="font-size:12px;color:#64748b">Sections and items that will be pre-populated when a user creates a RAB using this template.</span>
    </div>

    <!-- Discipline tabs -->
    <div class="bt-tabs">
        <?php foreach ($disciplines_list as $i => $d): ?>
        <div class="bt-tab<?= $i === 0 ? ' active' : '' ?>" onclick="btSwitchTab('<?= htmlspecialchars($d['code']) ?>')" id="bt-tabnav-<?= htmlspecialchars($d['code']) ?>">
            <?= htmlspecialchars($d['name']) ?>
            <span style="color:#475569;font-weight:400;font-size:11px;margin-left:4px">(<?= count($bt_sections_by_disc[$d['id']] ?? []) ?>)</span>
        </div>
        <?php endforeach; ?>
    </div>

    <?php foreach ($disciplines_list as $i => $d):
        $disc_sects = $bt_sections_by_disc[$d['id']] ?? [];
    ?>
    <div class="bt-panel<?= $i === 0 ? ' active' : '' ?>" id="bt-panel-<?= htmlspecialchars($d['code']) ?>">

        <?php foreach ($disc_sects as $sect): ?>
        <div class="bt-sect" id="bt-sect-<?= $sect['id'] ?>">
            <div class="bt-sect-hdr" onclick="btToggleSect(<?= $sect['id'] ?>)">
                <span class="bt-sect-title" id="bt-sect-title-<?= $sect['id'] ?>"><?= htmlspecialchars($sect['section_name']) ?></span>
                <div class="actions" onclick="event.stopPropagation()">
                    <button class="btn btn-o btn-xs" onclick="btRenameSection(<?= $sect['id'] ?>, '<?= htmlspecialchars(addslashes($sect['section_name'])) ?>')">Rename</button>
                    <button class="btn btn-r btn-xs" onclick="btDeleteSection(<?= $sect['id'] ?>, '<?= htmlspecialchars(addslashes($sect['section_name'])) ?>')">Delete</button>
                </div>
                <span id="bt-sect-toggle-<?= $sect['id'] ?>" style="color:#475569;font-size:11px;margin-left:6px">▼</span>
            </div>
            <div class="bt-sect-body" id="bt-sect-body-<?= $sect['id'] ?>">
                <table class="bt-item-table">
                    <thead>
                        <tr>
                            <th style="width:40%">Description</th>
                            <th>Unit</th>
                            <th class="num-right">Qty</th>
                            <th class="num-right">Rate (IDR)</th>
                            <th style="width:110px">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="bt-items-body-<?= $sect['id'] ?>">
                    <?php foreach ($sect['items'] as $it): ?>
                    <tr id="bt-item-row-<?= $it['id'] ?>">
                        <td><?= htmlspecialchars($it['name']) ?></td>
                        <td><?= htmlspecialchars($it['unit_code'] ?? '') ?></td>
                        <td class="num-right"><?= number_format((float)$it['default_quantity'], 3, '.', ',') ?></td>
                        <td class="num-right" style="font-variant-numeric:tabular-nums"><?= fmt_idr($it['default_rate']) ?></td>
                        <td>
                            <div class="actions">
                                <button class="btn btn-o btn-xs" onclick="btEditItem(<?= $it['id'] ?>, <?= $sect['id'] ?>, '<?= htmlspecialchars(addslashes($it['name'])) ?>', <?= (int)$it['unit_id'] ?>, <?= (float)$it['default_quantity'] ?>, <?= (float)$it['default_rate'] ?>)">Edit</button>
                                <button class="btn btn-r btn-xs" onclick="btDeleteItem(<?= $it['id'] ?>)">Del</button>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($sect['items'])): ?>
                    <tr id="bt-empty-<?= $sect['id'] ?>"><td colspan="5" style="color:#475569;font-size:12px;text-align:center;padding:12px">No items yet — add one below.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>

                <!-- Add item area -->
                <div class="bt-add-area" id="bt-add-area-<?= $sect['id'] ?>">
                    <button class="btn btn-p btn-sm" onclick="btShowAddItem(<?= $sect['id'] ?>)">+ Add Item</button>
                    <div id="bt-add-form-<?= $sect['id'] ?>" style="display:none">
                        <div class="bt-add-form">
                            <div class="bt-edit-grid">
                                <div class="fg">
                                    <label>Description *</label>
                                    <input type="text" id="bt-add-name-<?= $sect['id'] ?>" placeholder="Item description">
                                </div>
                                <div class="fg">
                                    <label>Unit *</label>
                                    <select id="bt-add-unit-<?= $sect['id'] ?>">
                                        <?php foreach ($units_list as $u): ?>
                                        <option value="<?= $u['id'] ?>"><?= htmlspecialchars($u['code']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="fg">
                                    <label>Qty</label>
                                    <input type="number" id="bt-add-qty-<?= $sect['id'] ?>" value="1" min="0" step="0.001">
                                </div>
                                <div class="fg">
                                    <label>Rate (IDR)</label>
                                    <input type="number" id="bt-add-rate-<?= $sect['id'] ?>" value="0" min="0" step="1000">
                                </div>
                            </div>
                            <div style="display:flex;gap:8px">
                                <button class="btn btn-p btn-sm" onclick="btSaveNewItem(<?= $sect['id'] ?>, <?= $id ?>)">Save Item</button>
                                <button class="btn btn-o btn-sm" onclick="btHideAddItem(<?= $sect['id'] ?>)">Cancel</button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>

        <!-- Add section -->
        <div style="margin-top:12px;display:flex;gap:8px;align-items:center">
            <input type="text" id="bt-new-sect-<?= htmlspecialchars($d['code']) ?>" placeholder="New section name…" style="max-width:280px">
            <button class="btn btn-o btn-sm" onclick="btAddSection(<?= $id ?>, <?= $d['id'] ?>, '<?= htmlspecialchars($d['code']) ?>')">+ Add Section</button>
        </div>

    </div><!-- .bt-panel -->
    <?php endforeach; ?>
</div>

<script>
// ── Tab switching ──
function btSwitchTab(code) {
    document.querySelectorAll('.bt-tab').forEach(function(t) { t.classList.remove('active'); });
    document.querySelectorAll('.bt-panel').forEach(function(p) { p.classList.remove('active'); });
    var tab = document.getElementById('bt-tabnav-' + code);
    var panel = document.getElementById('bt-panel-' + code);
    if (tab) tab.classList.add('active');
    if (panel) panel.classList.add('active');
}

// ── Section collapse ──
function btToggleSect(sectId) {
    var body = document.getElementById('bt-sect-body-' + sectId);
    var tog  = document.getElementById('bt-sect-toggle-' + sectId);
    if (!body) return;
    var hidden = body.style.display === 'none';
    body.style.display = hidden ? '' : 'none';
    if (tog) tog.textContent = hidden ? '▼' : '▶';
}

// ── AJAX helper ──
function btAjax(data, callback) {
    var xhr = new XMLHttpRequest();
    xhr.open('POST', 'rab.php?s=build_templates&a=bt_ajax', true);
    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
    var params = [];
    for (var k in data) { if (Object.prototype.hasOwnProperty.call(data, k)) params.push(encodeURIComponent(k) + '=' + encodeURIComponent(data[k])); }
    xhr.onload = function() {
        try { callback(JSON.parse(xhr.responseText)); } catch(e) { callback({ok:false,msg:'Invalid server response.'}); }
    };
    xhr.onerror = function() { callback({ok:false,msg:'Network error.'}); };
    xhr.send(params.join('&'));
}

// ── Escape helpers ──
function btEscHtml(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
function btEscHtmlJs(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/\\/g,'\\\\').replace(/'/g,"\\'");
}
function btFmtIdr(val) {
    val = parseFloat(val) || 0;
    return 'Rp ' + Math.round(val).toLocaleString('id-ID');
}

// ── Add item ──
function btShowAddItem(sectId) {
    var f = document.getElementById('bt-add-form-' + sectId);
    if (f) f.style.display = 'block';
}
function btHideAddItem(sectId) {
    var f = document.getElementById('bt-add-form-' + sectId);
    if (f) f.style.display = 'none';
}
function btSaveNewItem(sectId, btId) {
    var name   = document.getElementById('bt-add-name-' + sectId).value.trim();
    var unitId = document.getElementById('bt-add-unit-' + sectId).value;
    var qty    = document.getElementById('bt-add-qty-' + sectId).value;
    var rate   = document.getElementById('bt-add-rate-' + sectId).value;
    if (!name || !unitId) { alert('Description and unit are required.'); return; }
    btAjax({bt_action:'save_item', sect_id:sectId, name:name, unit_id:unitId, quantity:qty, rate:rate}, function(res) {
        if (res.ok) {
            // Remove empty-row placeholder if present
            var emptyRow = document.getElementById('bt-empty-' + sectId);
            if (emptyRow) emptyRow.remove();
            var tbody = document.getElementById('bt-items-body-' + sectId);
            if (tbody) {
                var tr = document.createElement('tr');
                tr.id = 'bt-item-row-' + res.item_id;
                var qtyFmt = (parseFloat(qty)||0).toFixed(3).replace(/\B(?=(\d{3})+(?!\d))/g,',');
                tr.innerHTML =
                    '<td>' + btEscHtml(name) + '</td>' +
                    '<td>' + btEscHtml(res.unit_code || '') + '</td>' +
                    '<td class="num-right">' + qtyFmt + '</td>' +
                    '<td class="num-right" style="font-variant-numeric:tabular-nums">' + btFmtIdr(rate) + '</td>' +
                    '<td><div class="actions">' +
                    '<button class="btn btn-o btn-xs" onclick="btEditItem(' + res.item_id + ',' + sectId + ',\'' + btEscHtmlJs(name) + '\',' + res.unit_id + ',' + qty + ',' + rate + ')">Edit</button> ' +
                    '<button class="btn btn-r btn-xs" onclick="btDeleteItem(' + res.item_id + ')">Del</button>' +
                    '</div></td>';
                tbody.appendChild(tr);
            }
            // Reset form
            document.getElementById('bt-add-name-' + sectId).value = '';
            document.getElementById('bt-add-qty-'  + sectId).value = '1';
            document.getElementById('bt-add-rate-' + sectId).value = '0';
            btHideAddItem(sectId);
        } else { alert(res.msg || 'Error saving item.'); }
    });
}

// ── Edit item (inline) ──
var _btEditOrig = {};
function btEditItem(itemId, sectId, name, unitId, qty, rate) {
    var row = document.getElementById('bt-item-row-' + itemId);
    if (!row) return;
    _btEditOrig[itemId] = row.innerHTML;
    var unitOpts = <?php
        $uo = [];
        foreach ($units_list as $u) { $uo[] = ['id' => (int)$u['id'], 'code' => $u['code']]; }
        echo json_encode($uo);
    ?>;
    var optHtml = '';
    for (var i = 0; i < unitOpts.length; i++) {
        var sel = (unitOpts[i].id == unitId) ? ' selected' : '';
        optHtml += '<option value="' + unitOpts[i].id + '"' + sel + '>' + btEscHtml(unitOpts[i].code) + '</option>';
    }
    row.innerHTML =
        '<td colspan="5"><div class="bt-add-form" style="margin:0">' +
        '<div class="bt-edit-grid">' +
        '<div class="fg"><label>Description</label><input type="text" id="bt-ei-name" value="' + name.replace(/"/g,'&quot;') + '"></div>' +
        '<div class="fg"><label>Unit</label><select id="bt-ei-unit">' + optHtml + '</select></div>' +
        '<div class="fg"><label>Qty</label><input type="number" id="bt-ei-qty" value="' + qty + '" step="0.001"></div>' +
        '<div class="fg"><label>Rate (IDR)</label><input type="number" id="bt-ei-rate" value="' + rate + '" step="1000"></div>' +
        '</div>' +
        '<div style="display:flex;gap:8px;margin-top:8px">' +
        '<button class="btn btn-p btn-sm" onclick="btSaveEditItem(' + itemId + ',' + sectId + ')">Save</button>' +
        '<button class="btn btn-o btn-sm" onclick="btCancelEditItem(' + itemId + ')">Cancel</button>' +
        '</div></div></td>';
}
function btSaveEditItem(itemId, sectId) {
    var name   = document.getElementById('bt-ei-name').value.trim();
    var unitId = document.getElementById('bt-ei-unit').value;
    var qty    = document.getElementById('bt-ei-qty').value;
    var rate   = document.getElementById('bt-ei-rate').value;
    if (!name || !unitId) { alert('Description and unit are required.'); return; }
    btAjax({bt_action:'save_item', item_id:itemId, sect_id:sectId, name:name, unit_id:unitId, quantity:qty, rate:rate}, function(res) {
        if (res.ok) {
            var row = document.getElementById('bt-item-row-' + itemId);
            if (row) {
                var qtyFmt = (parseFloat(qty)||0).toFixed(3).replace(/\B(?=(\d{3})+(?!\d))/g,',');
                row.innerHTML =
                    '<td>' + btEscHtml(name) + '</td>' +
                    '<td>' + btEscHtml(res.unit_code || '') + '</td>' +
                    '<td class="num-right">' + qtyFmt + '</td>' +
                    '<td class="num-right" style="font-variant-numeric:tabular-nums">' + btFmtIdr(rate) + '</td>' +
                    '<td><div class="actions">' +
                    '<button class="btn btn-o btn-xs" onclick="btEditItem(' + itemId + ',' + sectId + ',\'' + btEscHtmlJs(name) + '\',' + res.unit_id + ',' + qty + ',' + rate + ')">Edit</button> ' +
                    '<button class="btn btn-r btn-xs" onclick="btDeleteItem(' + itemId + ')">Del</button>' +
                    '</div></td>';
            }
            delete _btEditOrig[itemId];
        } else { alert(res.msg || 'Error saving.'); }
    });
}
function btCancelEditItem(itemId) {
    var row = document.getElementById('bt-item-row-' + itemId);
    if (row && _btEditOrig[itemId]) { row.innerHTML = _btEditOrig[itemId]; delete _btEditOrig[itemId]; }
}

// ── Delete item ──
function btDeleteItem(itemId) {
    if (!confirm('Delete this item?')) return;
    btAjax({bt_action:'delete_item', item_id:itemId}, function(res) {
        if (res.ok) {
            var row = document.getElementById('bt-item-row-' + itemId);
            if (row) row.remove();
        } else { alert(res.msg || 'Error deleting.'); }
    });
}

// ── Sections ──
function btAddSection(btId, discId, discCode) {
    var inp = document.getElementById('bt-new-sect-' + discCode);
    if (!inp) return;
    var name = inp.value.trim();
    if (!name) { inp.focus(); return; }
    btAjax({bt_action:'save_section', bt_id:btId, disc_id:discId, name:name}, function(res) {
        if (res.ok) {
            inp.value = '';
            var panel = document.getElementById('bt-panel-' + discCode);
            var addRow = inp.parentElement;
            if (panel && addRow) {
                var sectHtml = document.createElement('div');
                sectHtml.id  = 'bt-sect-' + res.sect_id;
                sectHtml.className = 'bt-sect';
                sectHtml.innerHTML =
                    '<div class="bt-sect-hdr" onclick="btToggleSect(' + res.sect_id + ')">' +
                    '<span class="bt-sect-title" id="bt-sect-title-' + res.sect_id + '">' + btEscHtml(res.name) + '</span>' +
                    '<div class="actions" onclick="event.stopPropagation()">' +
                    '<button class="btn btn-o btn-xs" onclick="btRenameSection(' + res.sect_id + ',\'' + btEscHtmlJs(res.name) + '\')">Rename</button> ' +
                    '<button class="btn btn-r btn-xs" onclick="btDeleteSection(' + res.sect_id + ',\'' + btEscHtmlJs(res.name) + '\')">Delete</button>' +
                    '</div>' +
                    '<span id="bt-sect-toggle-' + res.sect_id + '" style="color:#475569;font-size:11px;margin-left:6px">▼</span>' +
                    '</div>' +
                    '<div class="bt-sect-body" id="bt-sect-body-' + res.sect_id + '">' +
                    '<table class="bt-item-table"><thead><tr><th style="width:40%">Description</th><th>Unit</th><th class="num-right">Qty</th><th class="num-right">Rate (IDR)</th><th style="width:110px">Actions</th></tr></thead>' +
                    '<tbody id="bt-items-body-' + res.sect_id + '"><tr id="bt-empty-' + res.sect_id + '"><td colspan="5" style="color:#475569;font-size:12px;text-align:center;padding:12px">No items yet — add one below.</td></tr></tbody></table>' +
                    '<div class="bt-add-area" id="bt-add-area-' + res.sect_id + '">' +
                    '<button class="btn btn-p btn-sm" onclick="btShowAddItem(' + res.sect_id + ')">+ Add Item</button>' +
                    '<div id="bt-add-form-' + res.sect_id + '" style="display:none"><div class="bt-add-form">' +
                    '<div class="bt-edit-grid">' +
                    '<div class="fg"><label>Description *</label><input type="text" id="bt-add-name-' + res.sect_id + '" placeholder="Item description"></div>' +
                    '<div class="fg"><label>Unit *</label><select id="bt-add-unit-' + res.sect_id + '">' + _btUnitOptions + '</select></div>' +
                    '<div class="fg"><label>Qty</label><input type="number" id="bt-add-qty-' + res.sect_id + '" value="1" min="0" step="0.001"></div>' +
                    '<div class="fg"><label>Rate (IDR)</label><input type="number" id="bt-add-rate-' + res.sect_id + '" value="0" min="0" step="1000"></div>' +
                    '</div>' +
                    '<div style="display:flex;gap:8px"><button class="btn btn-p btn-sm" onclick="btSaveNewItem(' + res.sect_id + ',' + btId + ')">Save Item</button> <button class="btn btn-o btn-sm" onclick="btHideAddItem(' + res.sect_id + ')">Cancel</button></div>' +
                    '</div></div></div>' +
                    '</div>';
                panel.insertBefore(sectHtml, addRow);
            }
        } else { alert(res.msg || 'Error creating section.'); }
    });
}
function btRenameSection(sectId, currentName) {
    var newName = prompt('New section name:', currentName);
    if (!newName || !newName.trim()) return;
    btAjax({bt_action:'save_section', sect_id:sectId, name:newName.trim()}, function(res) {
        if (res.ok) {
            var t = document.getElementById('bt-sect-title-' + sectId);
            if (t) t.textContent = newName.trim();
        } else { alert(res.msg || 'Error renaming.'); }
    });
}
function btDeleteSection(sectId, name) {
    if (!confirm('Delete section "' + name + '" and all its items?')) return;
    btAjax({bt_action:'delete_section', sect_id:sectId}, function(res) {
        if (res.ok) {
            var el = document.getElementById('bt-sect-' + sectId);
            if (el) el.remove();
        } else { alert(res.msg || 'Error deleting.'); }
    });
}

// Pre-built unit options HTML for dynamically created sections
var _btUnitOptions = (function() {
    var units = <?php
        $uo2 = [];
        foreach ($units_list as $u) { $uo2[] = ['id' => (int)$u['id'], 'code' => htmlspecialchars($u['code'], ENT_QUOTES)]; }
        echo json_encode($uo2);
    ?>;
    var html = '';
    for (var i = 0; i < units.length; i++) { html += '<option value="' + units[i].id + '">' + units[i].code + '</option>'; }
    return html;
})();
</script>

<?php endif; ?>

<?php
// ═══════════════════════════════════════════════════════════════════════
// FALLBACK — unknown section
// ═══════════════════════════════════════════════════════════════════════
else:
?>
<h1>Not Found</h1>
<p style="color:#64748b">Section <code><?= htmlspecialchars($section) ?></code> does not exist.</p>
<a href="?s=dashboard" class="btn btn-o" style="margin-top:12px">← Dashboard</a>
<?php endif; ?>

</div><!-- .main -->
</div><!-- .shell -->

<?php
// ─── LOGIN FUNCTION ───────────────────────────────────────────────────
function show_login(string $error = ''): void {
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title>RAB Admin Login — Build in Lombok</title>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',system-ui,sans-serif;background:#0f172a;display:flex;align-items:center;justify-content:center;min-height:100vh}
.lc{background:#1e293b;border-radius:12px;padding:40px;box-shadow:0 8px 32px rgba(0,0,0,.4);width:100%;max-width:360px;border:1px solid rgba(255,255,255,.07)}
.brand{display:flex;align-items:center;gap:10px;margin-bottom:24px}
.brand-icon{background:#0c7c84;border-radius:8px;width:36px;height:36px;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:12px;color:#fff;letter-spacing:.05em}
.brand h1{font-size:1.1rem;font-weight:700;color:#f1f5f9;margin:0}
.brand p{font-size:11px;color:#64748b;margin:0}
label{display:block;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:#94a3b8;margin-bottom:5px;margin-top:14px}
input[type=text],input[type=password]{width:100%;padding:10px 12px;border:1px solid rgba(255,255,255,.1);border-radius:7px;font-size:.95rem;background:#0f172a;color:#e2e8f0;transition:border-color .15s}
input[type=text]:focus,input[type=password]:focus{outline:none;border-color:#0c7c84}
button{width:100%;padding:11px;background:#0c7c84;color:#fff;border:none;border-radius:7px;font-size:.95rem;font-weight:700;cursor:pointer;margin-top:20px;transition:background .15s}
button:hover{background:#0e8f98}
.err{background:#2d0a0a;color:#f87171;padding:11px 14px;border-radius:7px;margin-bottom:6px;font-size:.85rem;border:1px solid #991b1b}
</style>
</head>
<body>
<div class="lc">
    <div class="brand">
        <div class="brand-icon">RAB</div>
        <div><h1>RAB Admin</h1><p>Build in Lombok</p></div>
    </div>
    <?php if ($error): ?><div class="err"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <form method="POST">
        <label>Username</label>
        <input type="text" name="username" required autofocus autocomplete="username">
        <label>Password</label>
        <input type="password" name="password" required autocomplete="current-password">
        <button type="submit" name="login">Log In</button>
    </form>
</div>
</body>
</html>
<?php
}
?>
