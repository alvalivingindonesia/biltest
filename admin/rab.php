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
    $q      = trim($_GET['q'] ?? '');
    $f_cat  = trim($_GET['fc'] ?? '');

    $where = '1=1'; $params = [];
    if ($q) {
        $where .= ' AND m.name LIKE ?';
        $params[] = "%{$q}%";
    }
    if ($f_cat) {
        $where .= ' AND m.category = ?';
        $params[] = $f_cat;
    }

    $stmt = $db->prepare("SELECT m.*, u.code AS unit_code FROM rab_materials m LEFT JOIN rab_units u ON u.id=m.unit_id WHERE {$where} ORDER BY m.category, m.name LIMIT 500");
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    // Get distinct categories for filter
    $cats = $db->query("SELECT DISTINCT category FROM rab_materials WHERE category IS NOT NULL ORDER BY category")->fetchAll(PDO::FETCH_COLUMN);
    // Group types for reference
    $group_types_list = $db->query("SELECT DISTINCT group_type FROM rab_materials WHERE group_type IS NOT NULL AND group_type != '' ORDER BY group_type")->fetchAll(PDO::FETCH_COLUMN);
?>

<div class="page-header">
    <h1>Materials <span style="color:#64748b;font-size:1rem;font-weight:400">(<?= count($rows) ?>)</span></h1>
    <a href="?s=materials&a=edit" class="btn btn-p">+ Add Material</a>
</div>

<form class="search-bar" method="GET">
    <input type="hidden" name="s" value="materials">
    <input type="text" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Search by name…" style="max-width:300px">
    <select name="fc" style="min-width:160px">
        <option value="">All Categories</option>
        <?php foreach ($cats as $c): ?>
            <option value="<?= htmlspecialchars($c) ?>" <?= $f_cat === $c ? 'selected' : '' ?>><?= htmlspecialchars($c) ?></option>
        <?php endforeach; ?>
    </select>
    <button class="btn btn-o" type="submit">Filter</button>
    <?php if ($q || $f_cat): ?><a href="?s=materials" class="btn btn-o">Clear</a><?php endif; ?>
</form>

<div class="card" style="padding:0;overflow:hidden">
<table>
    <thead>
        <tr>
            <th>Name</th>
            <th>Unit</th>
            <th>Default Rate</th>
            <th>Category</th>
            <th>Tier</th>
            <th>Group</th>
            <th>Type</th>
            <th style="width:120px">Actions</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
    <tr>
        <td><?= htmlspecialchars($r['name']) ?></td>
        <td><code style="color:#94a3b8;font-size:12px"><?= htmlspecialchars($r['unit_code'] ?? '—') ?></code></td>
        <td class="idr"><?= fmt_idr($r['default_rate']) ?></td>
        <td><?= $r['category'] ? htmlspecialchars($r['category']) : '<span style="color:#475569">—</span>' ?></td>
        <td>
            <?php if (!empty($r['tier'])): ?>
                <?php
                $tier_colors = array('economy' => '#22c55e', 'standard' => '#3b82f6', 'premium' => '#a855f7');
                $tc = isset($tier_colors[$r['tier']]) ? $tier_colors[$r['tier']] : '#64748b';
                ?>
                <span class="badge" style="background:<?= $tc ?>20;color:<?= $tc ?>;border:1px solid <?= $tc ?>40"><?= ucfirst($r['tier']) ?></span>
            <?php else: ?>
                <span style="color:#475569">—</span>
            <?php endif; ?>
        </td>
        <td style="font-size:12px;color:#94a3b8"><?= !empty($r['group_type']) ? htmlspecialchars($r['group_type']) : '<span style="color:#475569">—</span>' ?></td>
        <td>
            <?php if ($r['is_composite']): ?>
                <span class="badge b-blue">Composite</span>
            <?php else: ?>
                <span class="badge" style="background:#1e293b;color:#64748b;border:1px solid rgba(255,255,255,.1)">Simple</span>
            <?php endif; ?>
        </td>
        <td>
            <div class="actions">
                <a href="?s=materials&a=edit&id=<?= $r['id'] ?>" class="btn btn-o btn-sm">Edit</a>
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
?>

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
