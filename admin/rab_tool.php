<?php
/**
 * Build in Lombok — RAB Tool (User-facing)
 * Rencana Anggaran Biaya: projects, RAB editor, calculator, summary, export.
 * Access: /admin/rab_tool.php
 */
session_start();
require_once('/home/rovin629/config/biltest_config.php');

// ─── AUTH ─────────────────────────────────────────────────────────────
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
if (isset($_GET['logout'])) { session_destroy(); header('Location: rab_tool.php'); exit; }
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

// ─── HELPERS ─────────────────────────────────────────────────────────
function fmt_idr($val): string {
    return 'Rp ' . number_format((float)$val, 0, ',', '.');
}

function he($s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

// ─── RECALCULATE RAB TOTALS ───────────────────────────────────────────
function recalculate_rab($db, $rab_id) {
    $rab_id = (int)$rab_id;

    // Sum per discipline
    $stmt = $db->prepare("
        SELECT d.code,
               COALESCE(SUM(i.quantity * i.rate), 0) AS disc_total
        FROM rab_sections s
        JOIN rab_disciplines d ON d.id = s.discipline_id
        LEFT JOIN rab_items i ON i.section_id = s.id
        WHERE s.rab_id = ?
        GROUP BY d.code
    ");
    $stmt->execute([$rab_id]);
    $rows = $stmt->fetchAll();

    $arch  = 0;
    $mep   = 0;
    $str   = 0;
    foreach ($rows as $r) {
        if ($r['code'] === 'ARCH') $arch = (float)$r['disc_total'];
        if ($r['code'] === 'MEP')  $mep  = (float)$r['disc_total'];
        if ($r['code'] === 'STR')  $str  = (float)$r['disc_total'];
    }
    $grand = $arch + $mep + $str;

    // Update item totals first
    $db->prepare("UPDATE rab_items i JOIN rab_sections s ON s.id = i.section_id SET i.total = i.quantity * i.rate WHERE s.rab_id = ?")->execute([$rab_id]);

    // Fetch house area
    $ta = $db->prepare("SELECT house_area_m2 FROM rab_totals WHERE rab_id = ?");
    $ta->execute([$rab_id]);
    $ta_row = $ta->fetch();
    $house_area = $ta_row ? (float)$ta_row['house_area_m2'] : 0;
    $cost_per_m2 = ($house_area > 0) ? round($grand / $house_area, 2) : null;

    // Upsert totals
    $exists = $db->prepare("SELECT id FROM rab_totals WHERE rab_id = ?");
    $exists->execute([$rab_id]);
    if ($exists->fetch()) {
        $db->prepare("UPDATE rab_totals SET architecture_total=?, mep_total=?, structure_total=?, grand_total=?, cost_per_m2=? WHERE rab_id=?")
           ->execute([$arch, $mep, $str, $grand, $cost_per_m2, $rab_id]);
    } else {
        $db->prepare("INSERT INTO rab_totals (rab_id, architecture_total, mep_total, structure_total, grand_total, cost_per_m2) VALUES (?,?,?,?,?,?)")
           ->execute([$rab_id, $arch, $mep, $str, $grand, $cost_per_m2]);
    }

    return ['arch' => $arch, 'mep' => $mep, 'str' => $str, 'grand' => $grand];
}

// ─── CREATE DEFAULT SECTIONS ──────────────────────────────────────────
function create_default_sections($db, $rab_id) {
    $disciplines = $db->query("SELECT id, code FROM rab_disciplines ORDER BY id")->fetchAll();
    $disc_map = [];
    foreach ($disciplines as $d) {
        $disc_map[$d['code']] = $d['id'];
    }

    $sections = [
        'ARCH' => ['Site Works','Walls','Floors','Ceilings','Doors & Windows','Roof','Finishes','Waterproofing','External Works'],
        'MEP'  => ['Electrical','Lighting','Plumbing & Sanitary','HVAC','Fire Fighting'],
        'STR'  => ['Excavation','Foundations','Columns & Beams','Slabs','Stairs','Retaining Walls','Roof Structure'],
    ];

    $stmt = $db->prepare("INSERT INTO rab_sections (rab_id, discipline_id, name, order_index) VALUES (?,?,?,?)");
    foreach ($sections as $code => $names) {
        if (!isset($disc_map[$code])) continue;
        $disc_id = $disc_map[$code];
        foreach ($names as $idx => $sname) {
            $stmt->execute([$rab_id, $disc_id, $sname, $idx]);
        }
    }
}

// ─── POPULATE FROM BUILD TEMPLATE ────────────────────────────────────
function populate_from_build_template($db, $rab_id, $build_template_id) {
    // First create default sections (as a baseline)
    create_default_sections($db, $rab_id);

    // Check if the build template has defined sections with items
    $bt_sections = $db->prepare("
        SELECT bts.*, d.code AS disc_code
        FROM rab_build_template_sections bts
        JOIN rab_disciplines d ON d.id = bts.discipline_id
        WHERE bts.build_template_id = ?
        ORDER BY d.id, bts.order_index
    ");
    $bt_sections->execute(array($build_template_id));
    $bt_sect_rows = $bt_sections->fetchAll();

    if (empty($bt_sect_rows)) {
        return; // No template sections defined yet, just use defaults
    }

    // For each template section, find matching section in the RAB and add items
    foreach ($bt_sect_rows as $bts) {
        // Find existing section matching discipline + name
        $sect_q = $db->prepare("
            SELECT s.id FROM rab_sections s
            WHERE s.rab_id = ? AND s.discipline_id = ? AND s.name = ?
            LIMIT 1
        ");
        $sect_q->execute(array($rab_id, $bts['discipline_id'], $bts['section_name']));
        $sect_row = $sect_q->fetch();
        $sect_id = $sect_row ? (int)$sect_row['id'] : 0;

        // If no matching section found, create one
        if (!$sect_id) {
            $max_oi = $db->prepare("SELECT COALESCE(MAX(order_index),0)+1 FROM rab_sections WHERE rab_id=? AND discipline_id=?");
            $max_oi->execute(array($rab_id, $bts['discipline_id']));
            $oidx = (int)$max_oi->fetchColumn();
            $db->prepare("INSERT INTO rab_sections (rab_id, discipline_id, name, order_index) VALUES (?,?,?,?)")
               ->execute(array($rab_id, $bts['discipline_id'], $bts['section_name'], $oidx));
            $sect_id = (int)$db->lastInsertId();
        }

        // Add items from template
        $items_q = $db->prepare("
            SELECT bti.* FROM rab_build_template_items bti
            WHERE bti.build_template_section_id = ?
            ORDER BY bti.order_index
        ");
        $items_q->execute(array($bts['id']));
        $items = $items_q->fetchAll();

        $ins_item = $db->prepare("INSERT INTO rab_items (section_id, item_template_id, name, unit_id, quantity, rate, total, order_index) VALUES (?,?,?,?,?,?,?,?)");
        foreach ($items as $it) {
            $total = (float)$it['default_quantity'] * (float)$it['default_rate'];
            $ins_item->execute(array(
                $sect_id, $it['item_template_id'], $it['name'], $it['unit_id'],
                $it['default_quantity'], $it['default_rate'], $total, $it['order_index']
            ));
        }
    }
}

// ─── CLONE RAB ───────────────────────────────────────────────────────
function clone_rab($db, $rab_id) {
    $src = $db->prepare("SELECT * FROM rab_rabs WHERE id=?");
    $src->execute([$rab_id]);
    $rab = $src->fetch();
    if (!$rab) return null;

    // Get next version number
    $vq = $db->prepare("SELECT COALESCE(MAX(version),0)+1 FROM rab_rabs WHERE project_id=?");
    $vq->execute([$rab['project_id']]);
    $next_v = (int)$vq->fetchColumn();

    $db->prepare("INSERT INTO rab_rabs (project_id, version, name, notes) VALUES (?,?,?,?)")
       ->execute([$rab['project_id'], $next_v, $rab['name'] . ' (Copy)', $rab['notes']]);
    $new_rab_id = (int)$db->lastInsertId();

    // Copy sections
    $sects = $db->prepare("SELECT * FROM rab_sections WHERE rab_id=? ORDER BY discipline_id, order_index");
    $sects->execute([$rab_id]);
    $sect_rows = $sects->fetchAll();

    $sect_map = [];
    $ins_sect = $db->prepare("INSERT INTO rab_sections (rab_id, discipline_id, name, order_index) VALUES (?,?,?,?)");
    foreach ($sect_rows as $s) {
        $ins_sect->execute([$new_rab_id, $s['discipline_id'], $s['name'], $s['order_index']]);
        $sect_map[$s['id']] = (int)$db->lastInsertId();
    }

    // Copy items
    $items = $db->prepare("SELECT * FROM rab_items WHERE section_id IN (SELECT id FROM rab_sections WHERE rab_id=?) ORDER BY order_index");
    $items->execute([$rab_id]);
    $item_rows = $items->fetchAll();

    $ins_item = $db->prepare("INSERT INTO rab_items (section_id, item_template_id, name, description, unit_id, quantity, rate, total, order_index) VALUES (?,?,?,?,?,?,?,?,?)");
    foreach ($item_rows as $it) {
        $new_sect = $sect_map[$it['section_id']] ?? null;
        if (!$new_sect) continue;
        $ins_item->execute([
            $new_sect, $it['item_template_id'], $it['name'], $it['description'],
            $it['unit_id'], $it['quantity'], $it['rate'], $it['total'], $it['order_index']
        ]);
    }

    // Clone totals (reset)
    $old_totals = $db->prepare("SELECT * FROM rab_totals WHERE rab_id=?");
    $old_totals->execute([$rab_id]);
    $ot = $old_totals->fetch();
    if ($ot) {
        $db->prepare("INSERT INTO rab_totals (rab_id, architecture_total, mep_total, structure_total, grand_total, house_area_m2, cost_per_m2) VALUES (?,?,?,?,?,?,?)")
           ->execute([$new_rab_id, $ot['architecture_total'], $ot['mep_total'], $ot['structure_total'], $ot['grand_total'], $ot['house_area_m2'], $ot['cost_per_m2']]);
    }

    recalculate_rab($db, $new_rab_id);
    return $new_rab_id;
}

// ─── ROUTING ─────────────────────────────────────────────────────────
$view   = $_GET['v']      ?? 'projects';
$id     = (int)($_GET['id'] ?? 0);
$action = $_POST['action'] ?? $_GET['action'] ?? '';
$msg    = '';

// ─── MATERIALS JSON (early exit for AJAX) ─────────────────────────────
if ($view === 'materials_json') {
    header('Content-Type: application/json; charset=utf-8');
    $db = get_db();
    $group_type = trim($_GET['group_type'] ?? '');
    $tier       = trim($_GET['tier'] ?? '');

    $where = '1=1';
    $params = [];
    if ($group_type !== '') {
        $where .= ' AND m.group_type = ?';
        $params[] = $group_type;
    }
    if ($tier !== '' && in_array($tier, ['economy', 'standard', 'premium'])) {
        $where .= ' AND m.tier = ?';
        $params[] = $tier;
    }

    $sql = "SELECT m.id, m.name, m.default_rate, m.category, m.tier, m.group_type, m.unit_id, u.code AS unit_code
            FROM rab_materials m LEFT JOIN rab_units u ON u.id = m.unit_id
            WHERE {$where} ORDER BY m.group_type, m.tier, m.name LIMIT 300";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $gt_rows = $db->query("SELECT DISTINCT group_type FROM rab_materials WHERE group_type IS NOT NULL AND group_type != '' ORDER BY group_type")->fetchAll(PDO::FETCH_COLUMN);

    echo json_encode(['ok' => true, 'materials' => $rows, 'group_types' => $gt_rows]);
    exit;
}

// ─── EXCEL EXPORT (early exit) ────────────────────────────────────────
if ($action === 'export_excel' && $id > 0) {
    $db = get_db();
    $rab = $db->prepare("SELECT r.*, p.name AS project_name, p.gross_floor_area_m2 FROM rab_rabs r JOIN rab_projects p ON p.id=r.project_id WHERE r.id=?");
    $rab->execute([$id]);
    $rab_row = $rab->fetch();
    if ($rab_row) {
        $disciplines_q = $db->query("SELECT id, code, name FROM rab_disciplines ORDER BY id");
        $disciplines = $disciplines_q->fetchAll();
        $totals_q = $db->prepare("SELECT * FROM rab_totals WHERE rab_id=?");
        $totals_q->execute([$id]);
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

        // Title block
        echo '<tr><td colspan="7" class="title" style="border:none;">RENCANA ANGGARAN BIAYA (RAB)</td></tr>';
        echo '<tr><td colspan="7" class="subtitle" style="border:none;">Proyek: ' . he($proj_name) . ' — v' . $rab_row['version'] . '</td></tr>';
        echo '<tr><td colspan="7" class="subtitle" style="border:none;">Tanggal: ' . date('d/m/Y') . '</td></tr>';
        if ($rab_row['gross_floor_area_m2']) {
            echo '<tr><td colspan="7" class="subtitle" style="border:none;">Luas Bangunan: ' . number_format((float)$rab_row['gross_floor_area_m2'], 2, ',', '.') . ' m²</td></tr>';
        }
        echo '<tr><td colspan="7" style="border:none;">&nbsp;</td></tr>';

        // Summary
        echo '<tr><td colspan="7" class="disc-header">RINGKASAN / SUMMARY</td></tr>';
        echo '<tr class="col-header"><th>Disiplin</th><th colspan="5">&nbsp;</th><th class="num">Total (IDR)</th></tr>';
        if ($totals) {
            echo '<tr><td>Architecture</td><td colspan="5"></td><td class="num">' . fmt_idr($totals['architecture_total']) . '</td></tr>';
            echo '<tr><td>MEP</td><td colspan="5"></td><td class="num">' . fmt_idr($totals['mep_total']) . '</td></tr>';
            echo '<tr><td>Structure</td><td colspan="5"></td><td class="num">' . fmt_idr($totals['structure_total']) . '</td></tr>';
            echo '<tr class="grand-total"><td colspan="6">GRAND TOTAL</td><td class="num">' . fmt_idr($totals['grand_total']) . '</td></tr>';
            if ($totals['house_area_m2'] && $totals['cost_per_m2']) {
                echo '<tr><td colspan="6">Cost per m² (luas ' . number_format((float)$totals['house_area_m2'], 2, ',', '.') . ' m²)</td><td class="num">' . fmt_idr($totals['cost_per_m2']) . '</td></tr>';
            }
        }
        echo '<tr><td colspan="7" style="border:none;">&nbsp;</td></tr>';

        // Detail by discipline
        echo '<tr><td colspan="7" class="disc-header">RINCIAN ANGGARAN BIAYA</td></tr>';
        echo '<tr class="col-header"><th>No.</th><th>Uraian Pekerjaan</th><th>Satuan</th><th class="num">Vol.</th><th class="num">Harga Satuan (IDR)</th><th class="num">Jumlah (IDR)</th><th>&nbsp;</th></tr>';

        $item_no = 0;
        foreach ($disciplines as $disc) {
            $sects_q = $db->prepare("SELECT * FROM rab_sections WHERE rab_id=? AND discipline_id=? ORDER BY order_index");
            $sects_q->execute([$id, $disc['id']]);
            $sects = $sects_q->fetchAll();
            if (!$sects) continue;

            echo '<tr><td colspan="7" class="disc-header">' . he($disc['name']) . '</td></tr>';
            $disc_total = 0;
            $sect_alpha = 'A';

            foreach ($sects as $sect) {
                $items_q = $db->prepare("SELECT i.*, u.code AS unit_code FROM rab_items i LEFT JOIN rab_units u ON u.id=i.unit_id WHERE i.section_id=? ORDER BY i.order_index");
                $items_q->execute([$sect['id']]);
                $sitems = $items_q->fetchAll();

                echo '<tr class="sect-header"><td>' . $sect_alpha . '</td><td colspan="5">' . he($sect['name']) . '</td><td></td></tr>';
                $sect_alpha++;

                $sect_total = 0;
                $row_num = 1;
                foreach ($sitems as $it) {
                    $item_no++;
                    $total = (float)$it['quantity'] * (float)$it['rate'];
                    $sect_total += $total;
                    echo '<tr>';
                    echo '<td>' . $item_no . '</td>';
                    echo '<td>' . he($it['name']) . '</td>';
                    echo '<td>' . he($it['unit_code']) . '</td>';
                    echo '<td class="num">' . number_format((float)$it['quantity'], 3, ',', '.') . '</td>';
                    echo '<td class="num">' . fmt_idr($it['rate']) . '</td>';
                    echo '<td class="num">' . fmt_idr($total) . '</td>';
                    echo '<td></td>';
                    echo '</tr>';
                    $row_num++;
                }
                $disc_total += $sect_total;
                echo '<tr class="subtotal"><td colspan="5" style="text-align:right;">Subtotal ' . he($sect['name']) . '</td><td class="num">' . fmt_idr($sect_total) . '</td><td></td></tr>';
            }
            echo '<tr class="subtotal" style="background:#0c2040;"><td colspan="5" style="text-align:right;">Total ' . he($disc['name']) . '</td><td class="num">' . fmt_idr($disc_total) . '</td><td></td></tr>';
            echo '<tr><td colspan="7" style="border:none;">&nbsp;</td></tr>';
        }

        if ($totals) {
            echo '<tr class="grand-total"><td colspan="5" style="text-align:right;">GRAND TOTAL</td><td class="num">' . fmt_idr($totals['grand_total']) . '</td><td></td></tr>';
        }

        echo '</table></body></html>';
        exit;
    }
}

// ─── AJAX / POST ACTIONS ─────────────────────────────────────────────
$ajaxActions = ['saveitem','deleteitem','savesection','deletesection','recalculate','getsections','updatearea'];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action && in_array($action, $ajaxActions)) {
    header('Content-Type: application/json; charset=utf-8');
    $db = get_db();
    $result = ['ok' => false, 'msg' => ''];

    try {
        // ── save_item ──
        if ($action === 'save_item') {
            $item_id    = (int)($_POST['item_id']   ?? 0);
            $section_id = (int)($_POST['section_id']  ?? 0);
            $name       = trim($_POST['name']         ?? '');
            $unit_id    = trim($_POST['unit_id']     ?? '');
            $quantity   = (float)($_POST['quantity']  ?? 0);
            $rate       = (float)($_POST['rate']      ?? 0);
            $tpl_id     = (int)($_POST['tpl_id']      ?? 0) ?: null;
            $total      = $quantity * $rate;

            if (!$name || $unit_id === '' || !$section_id) {
                $result['msg'] = 'Missing required fields.';
                echo json_encode($result); exit;
            }

            if ($item_id) {
                $db->prepare("UPDATE rab_items SET name=?, unit_id=?, quantity=?, rate=?, total=?, item_template_id=? WHERE id=?")
                   ->execute([$name, $unit_id, $quantity, $rate, $total, $tpl_id, $item_id]);
            } else {
                $max_idx = $db->prepare("SELECT COALESCE(MAX(order_index),0)+1 FROM rab_items WHERE section_id=?");
                $max_idx->execute([$section_id]);
                $oidx = (int)$max_idx->fetchColumn();
                $db->prepare("INSERT INTO rab_items (section_id, item_template_id, name, unit_id, quantity, rate, total, order_index) VALUES (?,?,?,?,?,?,?,?)")
                   ->execute([$section_id, $tpl_id, $name, $unit_id, $quantity, $rate, $total, $oidx]);
                $item_id = (int)$db->lastInsertId();
            }

            // Get rab_id for recalc
            $sq = $db->prepare("SELECT s.rab_id, d.code AS disc_code FROM rab_sections s JOIN rab_disciplines d ON d.id=s.discipline_id WHERE s.id=?");
            $sq->execute([$section_id]);
            $sq_row = $sq->fetch();
            $totals = recalculate_rab($db, $sq_row['rab_id']);

            $result = ['ok' => true, 'item_id' => $item_id, 'total' => $total, 'totals' => $totals, 'msg' => 'Saved.'];
        }

        // ── delete_item ──
        elseif ($action === 'delete_item') {
            $item_id = (int)($_POST['item_id'] ?? 0);
            $it = $db->prepare("SELECT s.rab_id FROM rab_items i JOIN rab_sections s ON s.id=i.section_id WHERE i.id=?");
            $it->execute([$item_id]);
            $rab_id_row = $it->fetch();
            $db->prepare("DELETE FROM rab_items WHERE id=?")->execute([$item_id]);
            $totals = recalculate_rab($db, $rab_id_row['rab_id']);
            $result = ['ok' => true, 'totals' => $totals, 'msg' => 'Deleted.'];
        }

        // ── save_section ──
        elseif ($action === 'save_section') {
            $section_id  = (int)($_POST['section_id'] ?? 0);
            $rab_id      = (int)($_POST['rab_id']     ?? 0);
            $disc_id     = (int)($_POST['disc_id']    ?? 0);
            $name        = trim($_POST['name']         ?? '');

            if (!$name) { $result['msg'] = 'Section name required.'; echo json_encode($result); exit; }

            if ($section_id) {
                $db->prepare("UPDATE rab_sections SET name=? WHERE id=?")->execute([$name, $section_id]);
                $result = ['ok' => true, 'section_id' => $section_id, 'msg' => 'Renamed.'];
            } else {
                if (!$rab_id || !$disc_id) { $result['msg'] = 'Missing rab_id or disc_id.'; echo json_encode($result); exit; }
                $max_oi = $db->prepare("SELECT COALESCE(MAX(order_index),0)+1 FROM rab_sections WHERE rab_id=? AND discipline_id=?");
                $max_oi->execute([$rab_id, $disc_id]);
                $oidx = (int)$max_oi->fetchColumn();
                $db->prepare("INSERT INTO rab_sections (rab_id, discipline_id, name, order_index) VALUES (?,?,?,?)")
                   ->execute([$rab_id, $disc_id, $name, $oidx]);
                $section_id = (int)$db->lastInsertId();
                $result = ['ok' => true, 'section_id' => $section_id, 'msg' => 'Section created.'];
            }
        }

        // ── delete_section ──
        elseif ($action === 'delete_section') {
            $section_id = (int)($_POST['section_id'] ?? 0);
            $cnt = $db->prepare("SELECT COUNT(*) FROM rab_items WHERE section_id=?");
            $cnt->execute([$section_id]);
            if ($cnt->fetchColumn() > 0) {
                $result['msg'] = 'Cannot delete section with items. Remove all items first.';
                echo json_encode($result); exit;
            }
            $sq = $db->prepare("SELECT rab_id FROM rab_sections WHERE id=?");
            $sq->execute([$section_id]);
            $sq_row = $sq->fetch();
            $db->prepare("DELETE FROM rab_sections WHERE id=?")->execute([$section_id]);
            recalculate_rab($db, $sq_row['rab_id']);
            $result = ['ok' => true, 'msg' => 'Deleted.'];
        }

        // ── recalculate ──
        elseif ($action === 'recalculate') {
            $rab_id = (int)($_POST['rab_id'] ?? 0);
            $totals = recalculate_rab($db, $rab_id);
            $result = ['ok' => true, 'totals' => $totals];
        }

        // ── get_sections ──
        elseif ($action === 'get_sections') {
            $rab_id  = (int)($_POST['rab_id']  ?? 0);
            $disc_id = (int)($_POST['disc_id'] ?? 0);
            $sects_q = $db->prepare("SELECT s.id, s.name, s.order_index FROM rab_sections s WHERE s.rab_id=? AND s.discipline_id=? ORDER BY s.order_index");
            $sects_q->execute([$rab_id, $disc_id]);
            $sects = $sects_q->fetchAll();

            $out = [];
            foreach ($sects as $s) {
                $items_q = $db->prepare("SELECT i.*, u.code AS unit_code FROM rab_items i LEFT JOIN rab_units u ON u.id=i.unit_id WHERE i.section_id=? ORDER BY i.order_index");
                $items_q->execute([$s['id']]);
                $s['items'] = $items_q->fetchAll();
                $out[] = $s;
            }
            echo json_encode(['ok' => true, 'sections' => $out]);
            exit;
        }

        // ── update_area ──
        elseif ($action === 'update_area') {
            $rab_id = (int)($_POST['rab_id'] ?? 0);
            $area   = (float)($_POST['area'] ?? 0);
            $exists = $db->prepare("SELECT id FROM rab_totals WHERE rab_id=?");
            $exists->execute([$rab_id]);
            if ($exists->fetch()) {
                $db->prepare("UPDATE rab_totals SET house_area_m2=? WHERE rab_id=?")->execute([$area, $rab_id]);
            } else {
                $db->prepare("INSERT INTO rab_totals (rab_id, house_area_m2) VALUES (?,?)")->execute([$rab_id, $area]);
            }
            $totals = recalculate_rab($db, $rab_id);
            $result = ['ok' => true, 'totals' => $totals];
        }

        else {
            $result['msg'] = 'Unknown action.';
        }

    } catch (Exception $e) {
        $result['msg'] = 'Error: ' . $e->getMessage();
    }

    echo json_encode($result);
    exit;
}

// ─── REGULAR POST ACTIONS (non-AJAX) ─────────────────────────────────
$db = get_db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // ── Create/Edit Project ──
        if ($action === 'save_project') {
            $proj_id  = (int)($_POST['proj_id']           ?? 0);
            $name     = trim($_POST['name']                ?? '');
            $location = trim($_POST['location']            ?? '');
            $desc     = trim($_POST['description']         ?? '');
            $area     = (float)($_POST['gross_floor_area_m2'] ?? 0) ?: null;
            $status   = $_POST['status']                   ?? 'draft';
            if (!in_array($status, ['draft','active','archived'])) $status = 'draft';

            if (!$name) throw new Exception('Project name is required.');

            if ($proj_id) {
                $db->prepare("UPDATE rab_projects SET name=?, location=?, description=?, gross_floor_area_m2=?, status=? WHERE id=?")
                   ->execute([$name, $location ?: null, $desc ?: null, $area, $status, $proj_id]);
                $_SESSION['flash'] = 'Project updated.';
                header('Location: rab_tool.php?v=project&id=' . $proj_id); exit;
            } else {
                $db->prepare("INSERT INTO rab_projects (name, location, description, gross_floor_area_m2, status) VALUES (?,?,?,?,?)")
                   ->execute([$name, $location ?: null, $desc ?: null, $area, $status]);
                $proj_id = (int)$db->lastInsertId();
                $_SESSION['flash'] = 'Project created.';
                header('Location: rab_tool.php?v=project&id=' . $proj_id); exit;
            }
        }

        // ── Delete Project ──
        if ($action === 'delete_project') {
            $proj_id = (int)($_POST['proj_id'] ?? 0);
            $db->prepare("DELETE FROM rab_projects WHERE id=?")->execute([$proj_id]);
            $_SESSION['flash'] = 'Project deleted.';
            header('Location: rab_tool.php?v=projects'); exit;
        }

        // ── Create RAB ──
        if ($action === 'create_rab') {
            $proj_id           = (int)($_POST['proj_id'] ?? 0);
            $rab_name          = trim($_POST['rab_name'] ?? '');
            $build_template_id = (int)($_POST['build_template_id'] ?? 0);
            if (!$proj_id) throw new Exception('No project specified.');
            $vq = $db->prepare("SELECT COALESCE(MAX(version),0)+1 FROM rab_rabs WHERE project_id=?");
            $vq->execute([$proj_id]);
            $version = (int)$vq->fetchColumn();
            if (!$rab_name) $rab_name = 'Version ' . $version;
            $db->prepare("INSERT INTO rab_rabs (project_id, version, name) VALUES (?,?,?)")
               ->execute([$proj_id, $version, $rab_name]);
            $new_rab_id = (int)$db->lastInsertId();

            if ($build_template_id) {
                // Populate from build template
                populate_from_build_template($db, $new_rab_id, $build_template_id);
            } else {
                create_default_sections($db, $new_rab_id);
            }

            // Initialise totals row
            $db->prepare("INSERT INTO rab_totals (rab_id, house_area_m2) VALUES (?, (SELECT gross_floor_area_m2 FROM rab_projects WHERE id=?))")
               ->execute([$new_rab_id, $proj_id]);
            recalculate_rab($db, $new_rab_id);
            header('Location: rab_tool.php?v=rab&id=' . $new_rab_id); exit;
        }

        // ── Clone RAB ──
        if ($action === 'clone_rab') {
            $rab_id = (int)($_POST['rab_id'] ?? 0);
            $rab_row = $db->prepare("SELECT project_id FROM rab_rabs WHERE id=?");
            $rab_row->execute([$rab_id]);
            $rab_data = $rab_row->fetch();
            $new_id = clone_rab($db, $rab_id);
            $_SESSION['flash'] = 'RAB cloned successfully.';
            header('Location: rab_tool.php?v=project&id=' . $rab_data['project_id']); exit;
        }

        // ── Delete RAB ──
        if ($action === 'delete_rab') {
            $rab_id  = (int)($_POST['rab_id']  ?? 0);
            $proj_id = (int)($_POST['proj_id'] ?? 0);
            $db->prepare("DELETE FROM rab_rabs WHERE id=?")->execute([$rab_id]);
            $_SESSION['flash'] = 'RAB deleted.';
            header('Location: rab_tool.php?v=project&id=' . $proj_id); exit;
        }

        // ── Run Calculator ──
        if ($action === 'run_calculator') {
            $preset_id    = (int)($_POST['preset_id']     ?? 0);
            $quality      = $_POST['quality']              ?? 'mid';
            if (!in_array($quality, ['low','mid','high'])) $quality = 'mid';
            $num_storeys  = (int)($_POST['num_storeys']   ?? 1);
            $fa1          = (float)($_POST['floor_area_1'] ?? 0);
            $fa2          = (float)($_POST['floor_area_2'] ?? 0);
            $fa3          = (float)($_POST['floor_area_3'] ?? 0);
            $fa4          = (float)($_POST['floor_area_4'] ?? 0);
            $walkable     = isset($_POST['walkable_rooftop']) ? 1 : 0;
            $rooftop_area = (float)($_POST['rooftop_area'] ?? 0);
            $has_pool     = isset($_POST['has_pool']) ? 1 : 0;
            $pool_inf     = ($_POST['pool_type'] ?? 'standard') === 'infinity' ? 1 : 0;
            $pool_area    = (float)($_POST['pool_area']   ?? 0);
            $deck_area    = (float)($_POST['deck_area']   ?? 0);

            $p_q = $db->prepare("SELECT * FROM rab_calculator_presets WHERE id=?");
            $p_q->execute([$preset_id]);
            $preset = $p_q->fetch();
            if (!$preset) throw new Exception('Preset not found.');

            $col = 'base_cost_per_m2_' . $quality;
            $building_rate  = (float)$preset[$col];
            $loc            = (float)$preset['location_factor'];
            $cont_pct       = (float)$preset['contingency_percent'];

            $total_floor    = $fa1 + $fa2 + $fa3 + $fa4;
            $building_cost  = $total_floor * $building_rate * $loc;
            $rooftop_cost   = $walkable ? ($rooftop_area * (float)$preset['rooftop_cost_per_m2'] * $loc) : 0;
            $pool_rate_col  = $pool_inf ? 'pool_cost_per_m2_infinity' : 'pool_cost_per_m2_standard';
            $pool_cost      = $has_pool ? ($pool_area * (float)$preset[$pool_rate_col] * $loc) : 0;
            $deck_cost      = $deck_area * (float)$preset['deck_cost_per_m2'] * $loc;
            $subtotal       = $building_cost + $rooftop_cost + $pool_cost + $deck_cost;
            $contingency    = $subtotal * ($cont_pct / 100);
            $grand_total    = $subtotal + $contingency;

            $ins = $db->prepare("INSERT INTO rab_calculator_runs
                (preset_id, quality_level, num_storeys,
                 floor_area_level1_m2, floor_area_level2_m2, floor_area_level3_m2, floor_area_other_m2,
                 rooftop_walkable, rooftop_area_m2,
                 pool_has_pool, pool_is_infinity, pool_area_m2,
                 deck_area_m2, total_floor_area_m2,
                 building_cost, rooftop_cost, pool_cost, deck_cost,
                 subtotal_cost, contingency_amount, grand_total_cost)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
            $ins->execute([
                $preset_id, $quality, $num_storeys,
                $fa1, $fa2, $fa3, $fa4,
                $walkable, $rooftop_area,
                $has_pool, $pool_inf, $pool_area,
                $deck_area, $total_floor,
                $building_cost, $rooftop_cost, $pool_cost, $deck_cost,
                $subtotal, $contingency, $grand_total
            ]);
            $run_id = (int)$db->lastInsertId();
            header('Location: rab_tool.php?v=result&id=' . $run_id); exit;
        }

        // ── Create Project from Calculator ──
        if ($action === 'create_project_from_calc') {
            $run_id   = (int)($_POST['run_id']      ?? 0);
            $proj_name = trim($_POST['proj_name']   ?? '');
            $location  = trim($_POST['location']    ?? '');

            $run_q = $db->prepare("SELECT * FROM rab_calculator_runs WHERE id=?");
            $run_q->execute([$run_id]);
            $run = $run_q->fetch();
            if (!$run) throw new Exception('Run not found.');
            if (!$proj_name) $proj_name = 'Project from Estimate #' . $run_id;

            // Create project
            $db->prepare("INSERT INTO rab_projects (name, location, gross_floor_area_m2, status) VALUES (?,?,?,?)")
               ->execute([$proj_name, $location ?: null, $run['total_floor_area_m2'] ?: null, 'draft']);
            $proj_id = (int)$db->lastInsertId();

            // Create RAB
            $db->prepare("INSERT INTO rab_rabs (project_id, version, name, notes) VALUES (?,?,?,?)")
               ->execute([$proj_id, 1, 'Version 1 (From Estimate)', 'Auto-created from cost calculator estimate #' . $run_id]);
            $rab_id = (int)$db->lastInsertId();

            // Disciplines
            $discs = $db->query("SELECT id, code FROM rab_disciplines ORDER BY id")->fetchAll();
            $disc_map = [];
            foreach ($discs as $d) { $disc_map[$d['code']] = $d['id']; }

            // Units — find lump_sum id
            $ls_q = $db->query("SELECT id FROM rab_units WHERE code='lump_sum' LIMIT 1");
            $ls_id = (int)$ls_q->fetchColumn();

            // Splits
            $grand = (float)$run['grand_total_cost'];
            $splits = ['STR' => 0.30, 'ARCH' => 0.50, 'MEP' => 0.20];
            $sect_ins = $db->prepare("INSERT INTO rab_sections (rab_id, discipline_id, name, order_index) VALUES (?,?,?,0)");
            $item_ins = $db->prepare("INSERT INTO rab_items (section_id, name, unit_id, quantity, rate, total) VALUES (?,?,?,1,?,?)");

            foreach ($splits as $code => $pct) {
                if (!isset($disc_map[$code])) continue;
                $disc_names = ['STR' => 'Structure', 'ARCH' => 'Architecture', 'MEP' => 'MEP'];
                $sect_ins->execute([$rab_id, $disc_map[$code], $disc_names[$code] . ' (Lump Sum)']);
                $sect_id = (int)$db->lastInsertId();
                $amt = round($grand * $pct, 2);
                $item_ins->execute([$sect_id, $disc_names[$code] . ' Works', $ls_id, $amt, $amt]);
            }

            // Totals
            $arch_amt = round($grand * 0.50, 2);
            $mep_amt  = round($grand * 0.20, 2);
            $str_amt  = round($grand * 0.30, 2);
            $db->prepare("INSERT INTO rab_totals (rab_id, architecture_total, mep_total, structure_total, grand_total, house_area_m2) VALUES (?,?,?,?,?,?)")
               ->execute([$rab_id, $arch_amt, $mep_amt, $str_amt, $grand, $run['total_floor_area_m2'] ?: null]);

            // Link run to project
            $db->prepare("UPDATE rab_calculator_runs SET rab_project_id=?, rab_rab_id=? WHERE id=?")
               ->execute([$proj_id, $rab_id, $run_id]);

            header('Location: rab_tool.php?v=rab&id=' . $rab_id); exit;
        }

    } catch (Exception $e) {
        $msg = 'Error: ' . $e->getMessage();
    }
}

// Flash
if (isset($_SESSION['flash'])) {
    $msg = $_SESSION['flash'];
    unset($_SESSION['flash']);
}

// ─── PRE-FETCH FOR VIEWS ──────────────────────────────────────────────
$disciplines = [];
try {
    $disciplines = $db->query("SELECT id, code, name FROM rab_disciplines ORDER BY id")->fetchAll();
} catch (Exception $e) {}

$units_list = [];
try {
    $units_list = $db->query("SELECT id, code, name FROM rab_units WHERE is_active=1 ORDER BY code")->fetchAll();
} catch (Exception $e) {}

?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title>RAB Tool — Build in Lombok</title>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',system-ui,sans-serif;background:#0f172a;color:#e2e8f0;line-height:1.5;font-size:14px}
a{color:#0c7c84;text-decoration:none}
a:hover{text-decoration:underline;color:#14b8a6}

/* Layout */
.shell{display:flex;flex-direction:column;min-height:100vh}

/* Header */
.header{background:#1e293b;border-bottom:1px solid rgba(255,255,255,.07);padding:0 24px;display:flex;align-items:stretch;gap:0;position:sticky;top:0;z-index:100}
.header-brand{display:flex;align-items:center;gap:10px;padding:14px 24px 14px 0;border-right:1px solid rgba(255,255,255,.07);margin-right:16px}
.header-brand svg{flex-shrink:0}
.header-brand span{font-weight:700;font-size:15px;color:#f1f5f9;white-space:nowrap}
.header-brand small{display:block;font-size:11px;color:#64748b;font-weight:400}
nav{display:flex;align-items:stretch;gap:0;flex:1}
nav a{display:flex;align-items:center;gap:6px;padding:0 16px;color:#94a3b8;font-size:13px;font-weight:500;border-bottom:3px solid transparent;white-space:nowrap;text-decoration:none;transition:color .15s,border-color .15s}
nav a:hover{color:#e2e8f0;text-decoration:none}
nav a.active{color:#0c7c84;border-bottom-color:#0c7c84}
.header-right{display:flex;align-items:center;margin-left:auto;gap:12px;padding-left:16px}
.logout-link{font-size:12px;color:#64748b}
.logout-link:hover{color:#e2e8f0;text-decoration:none}

/* Main */
.main{flex:1;padding:28px 32px;max-width:1280px;width:100%}

/* Flash */
.msg{padding:11px 16px;border-radius:7px;margin-bottom:18px;font-size:13px;font-weight:500}
.msg-ok{background:#052e16;color:#4ade80;border:1px solid #166534}
.msg-err{background:#2d0a0a;color:#f87171;border:1px solid #991b1b}

/* Headings */
h1{font-size:1.35rem;font-weight:700;color:#f1f5f9;margin-bottom:18px}
h2{font-size:1.1rem;font-weight:600;color:#f1f5f9;margin-bottom:14px}
h3{font-size:.95rem;font-weight:600;color:#cbd5e1;margin-bottom:10px}

/* Card */
.card{background:#1e293b;border-radius:10px;padding:22px;margin-bottom:18px;border:1px solid rgba(255,255,255,.05)}
.card-sm{background:#1e293b;border-radius:8px;padding:16px;margin-bottom:12px;border:1px solid rgba(255,255,255,.05)}

/* Buttons */
.btn{padding:7px 15px;border:none;border-radius:6px;font-size:13px;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:5px;text-decoration:none;line-height:1.4;transition:background .15s,opacity .15s}
.btn-p{background:#0c7c84;color:#fff}.btn-p:hover{background:#0e8f98;text-decoration:none}
.btn-r{background:#d4604a;color:#fff}.btn-r:hover{background:#c0533e;text-decoration:none}
.btn-o{background:transparent;border:1px solid rgba(255,255,255,.15);color:#cbd5e1}.btn-o:hover{background:rgba(255,255,255,.05);text-decoration:none}
.btn-g{background:#166534;color:#4ade80;border:1px solid #166534}.btn-g:hover{background:#1a7a3f;text-decoration:none}
.btn-sm{padding:4px 10px;font-size:12px}
.btn-xs{padding:3px 8px;font-size:11px}

/* Tables */
table{width:100%;border-collapse:collapse;font-size:13px}
th,td{padding:9px 12px;text-align:left;border-bottom:1px solid rgba(255,255,255,.05)}
th{font-size:11px;text-transform:uppercase;letter-spacing:.04em;color:#64748b;background:#162032;font-weight:600}
tbody tr:hover{background:rgba(255,255,255,.02)}
.actions{display:flex;gap:6px;flex-wrap:nowrap;align-items:center}
.num{text-align:right;font-variant-numeric:tabular-nums}

/* Forms */
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}
.form-grid.cols3{grid-template-columns:1fr 1fr 1fr}
.form-grid.full{grid-template-columns:1fr}
.fg{display:flex;flex-direction:column;gap:5px}
.fg.span2{grid-column:span 2}
.fg.span3{grid-column:span 3}
.fg label{font-size:11px;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:.04em}
input[type=text],input[type=number],input[type=email],select,textarea{
    padding:8px 11px;border:1px solid rgba(255,255,255,.1);border-radius:6px;
    font-size:13px;font-family:inherit;width:100%;
    background:#0f172a;color:#e2e8f0;transition:border-color .15s
}
input[type=text]:focus,input[type=number]:focus,input[type=email]:focus,select:focus,textarea:focus{outline:none;border-color:#0c7c84}
textarea{min-height:80px;resize:vertical}
select option{background:#1e293b}
.ck{display:flex;align-items:center;gap:8px;margin-top:4px;cursor:pointer}
.ck input[type=checkbox]{width:16px;height:16px;accent-color:#0c7c84;cursor:pointer}
.ck input[type=radio]{width:16px;height:16px;accent-color:#0c7c84;cursor:pointer}
.ck span{font-size:13px;color:#cbd5e1}
.form-note{font-size:11px;color:#64748b;margin-top:2px}

/* Badges */
.badge{display:inline-block;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:700}
.b-green{background:#052e16;color:#4ade80;border:1px solid #166534}
.b-red{background:#2d0a0a;color:#f87171;border:1px solid #991b1b}
.b-blue{background:#1e3a5f;color:#93c5fd;border:1px solid #1d4ed8}
.b-yellow{background:#3a2a00;color:#fbbf24;border:1px solid #92400e}
.b-teal{background:#042f2e;color:#5eead4;border:1px solid #0d9488}
.b-gray{background:#1e293b;color:#94a3b8;border:1px solid rgba(255,255,255,.1)}

/* Page header */
.page-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;flex-wrap:wrap;gap:10px}
.page-header h1{margin-bottom:0}

/* Back link */
.back-link{display:inline-flex;align-items:center;gap:5px;color:#64748b;font-size:13px;margin-bottom:14px;text-decoration:none}
.back-link:hover{color:#e2e8f0;text-decoration:none}

/* Tabs */
.tabs{display:flex;gap:4px;margin-bottom:20px;border-bottom:2px solid rgba(255,255,255,.07);padding-bottom:0}
.tab-btn{padding:9px 18px;border:none;background:transparent;color:#64748b;font-size:13px;font-weight:600;cursor:pointer;border-bottom:2px solid transparent;margin-bottom:-2px;border-radius:6px 6px 0 0;transition:color .15s,border-color .15s,background .15s}
.tab-btn:hover{color:#e2e8f0;background:rgba(255,255,255,.04)}
.tab-btn.active{color:#0c7c84;border-bottom-color:#0c7c84;background:rgba(12,124,132,.07)}
.tab-panel{display:none}
.tab-panel.active{display:block}

/* Section panels */
.section-panel{background:#162032;border:1px solid rgba(255,255,255,.06);border-radius:8px;margin-bottom:10px;overflow:hidden}
.section-header{display:flex;align-items:center;gap:10px;padding:10px 14px;cursor:pointer;user-select:none;transition:background .15s}
.section-header:hover{background:#1a2a42}
.section-title{font-weight:600;color:#e2e8f0;font-size:14px;flex:1}
.section-toggle{color:#64748b;font-size:12px;transition:transform .2s}
.section-toggle.open{transform:rotate(180deg)}
.section-body{padding:14px;border-top:1px solid rgba(255,255,255,.05);display:none}
.section-body.open{display:block}

/* Item table in editor */
.item-table{width:100%;border-collapse:collapse;font-size:13px;margin-bottom:10px}
.item-table th{font-size:11px;text-transform:uppercase;letter-spacing:.04em;color:#64748b;background:#0f172a;padding:7px 10px;font-weight:600;border-bottom:1px solid rgba(255,255,255,.07)}
.item-table td{padding:7px 10px;border-bottom:1px solid rgba(255,255,255,.04);vertical-align:middle}
.item-table tbody tr:hover{background:rgba(255,255,255,.02)}
.item-table .num{text-align:right}

/* Inline edit form */
.item-edit-form{background:#0a1628;border:1px solid rgba(12,124,132,.3);border-radius:6px;padding:12px;margin:4px 0 8px 0}
.edit-grid{display:grid;grid-template-columns:3fr 1fr 1fr 2fr;gap:8px;align-items:end}
.edit-grid .fg label{font-size:10px}
.edit-grid input,
.edit-grid select{padding:6px 8px;font-size:12px}

/* Total row */
.section-total-row td{font-weight:700;color:#5eead4;background:#0c1f34;font-size:13px}

/* Discipline totals bar */
.disc-totals{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:20px}
.disc-total-card{background:#1e293b;border-radius:8px;padding:14px 16px;border:1px solid rgba(255,255,255,.05);text-align:center}
.disc-total-card .label{font-size:11px;text-transform:uppercase;letter-spacing:.04em;color:#64748b;margin-bottom:4px}
.disc-total-card .value{font-size:1.1rem;font-weight:700;color:#5eead4;font-variant-numeric:tabular-nums}
.disc-total-card.grand .value{color:#0c7c84;font-size:1.3rem}

/* Calculator */
.calc-section{margin-bottom:22px}
.calc-section h3{font-size:13px;text-transform:uppercase;letter-spacing:.05em;color:#64748b;margin-bottom:10px;padding-bottom:6px;border-bottom:1px solid rgba(255,255,255,.07)}
.quality-radios{display:flex;gap:10px;flex-wrap:wrap}
.quality-opt{flex:1;min-width:100px}
.quality-opt input[type=radio]{display:none}
.quality-opt label{display:block;padding:12px;border:2px solid rgba(255,255,255,.1);border-radius:8px;cursor:pointer;text-align:center;transition:border-color .15s,background .15s}
.quality-opt input[type=radio]:checked + label{border-color:#0c7c84;background:rgba(12,124,132,.12)}
.quality-label{font-weight:700;font-size:13px;color:#e2e8f0;display:block}
.quality-sub{font-size:11px;color:#64748b;display:block;margin-top:2px}

/* Result page */
.result-grid{display:grid;grid-template-columns:1fr 1fr;gap:20px}
.result-card{background:#1e293b;border-radius:10px;padding:20px;border:1px solid rgba(255,255,255,.05)}
.breakdown-row{display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid rgba(255,255,255,.05);font-size:13px}
.breakdown-row.total{border-bottom:none;padding-top:12px;margin-top:4px;font-weight:700;font-size:1rem;color:#5eead4}
.breakdown-row.grand{font-size:1.15rem;color:#0c7c84;border-top:2px solid rgba(12,124,132,.3);padding-top:12px;margin-top:4px}

/* Summary */
.summary-table{width:100%;border-collapse:collapse}
.summary-table th,.summary-table td{padding:12px 16px;border-bottom:1px solid rgba(255,255,255,.07)}
.summary-table th{font-size:11px;text-transform:uppercase;letter-spacing:.04em;color:#64748b;background:#162032}
.summary-grand td{font-weight:700;font-size:1.05rem;color:#0c7c84;background:#0c2336;border-top:2px solid rgba(12,124,132,.3)}

/* Modal backdrop */
.modal-bg{display:none;position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:200;align-items:center;justify-content:center;padding:20px}
.modal-bg.open{display:flex}
.modal{background:#1e293b;border-radius:12px;padding:28px;width:100%;max-width:520px;border:1px solid rgba(255,255,255,.07);max-height:90vh;overflow-y:auto}
.modal h2{margin-bottom:18px}
.modal-close{float:right;background:none;border:none;color:#64748b;font-size:18px;cursor:pointer;margin-top:-4px}

/* Loading spinner */
.spinner{width:16px;height:16px;border:2px solid rgba(255,255,255,.2);border-top-color:#0c7c84;border-radius:50%;display:inline-block;animation:spin .6s linear infinite}
@keyframes spin{to{transform:rotate(360deg)}}

/* Inline alert */
.inline-alert{padding:8px 12px;border-radius:6px;font-size:12px;margin:6px 0}
.inline-alert.ok{background:#052e16;color:#4ade80;border:1px solid #166534}
.inline-alert.err{background:#2d0a0a;color:#f87171;border:1px solid #991b1b}

/* IDR */
.idr{font-variant-numeric:tabular-nums}

/* Add item area */
.add-item-area{margin-top:10px;padding-top:10px;border-top:1px dashed rgba(255,255,255,.08)}
</style>
</head>
<body>
<div class="shell">

<!-- HEADER -->
<div class="header">
    <div class="header-brand">
        <svg width="28" height="28" viewBox="0 0 28 28" fill="none" aria-label="RAB Tool">
            <rect width="28" height="28" rx="6" fill="#0c7c84"/>
            <text x="3" y="19" font-family="system-ui,sans-serif" font-weight="800" font-size="11" fill="#fff">RAB</text>
        </svg>
        <span>RAB Tool<small>Build in Lombok</small></span>
    </div>
    <nav>
        <a href="rab_tool.php?v=projects" class="<?= $view === 'projects' ? 'active' : '' ?>">&#127968; Projects</a>
        <a href="rab_tool.php?v=calculator" class="<?= $view === 'calculator' ? 'active' : '' ?>">&#128290; Calculator</a>
    </nav>
    <div class="header-right">
        <a href="rab.php" class="logout-link">Admin</a>
        <a href="console.php" class="logout-link">Console</a>
        <a href="?logout=1" class="logout-link">Logout</a>
    </div>
</div>

<!-- MAIN -->
<div class="main">

<?php if ($msg): ?>
<div class="msg <?= strpos($msg, 'Error') !== false || strpos($msg, 'error') !== false ? 'msg-err' : 'msg-ok' ?>"><?= he($msg) ?></div>
<?php endif; ?>

<?php

// ═══════════════════════════════════════════════════════════════════════════
// VIEW: PROJECTS LIST
// ═══════════════════════════════════════════════════════════════════════════
if ($view === 'projects'):
    $projects = [];
    try {
        $projects = $db->query("
            SELECT p.*,
                   COUNT(DISTINCT r.id) AS rab_count,
                   MAX(r.updated_at) AS last_updated,
                   (SELECT t.grand_total FROM rab_rabs r2 JOIN rab_totals t ON t.rab_id=r2.id WHERE r2.project_id=p.id ORDER BY t.grand_total DESC LIMIT 1) AS max_grand_total
            FROM rab_projects p
            LEFT JOIN rab_rabs r ON r.project_id = p.id
            GROUP BY p.id
            ORDER BY p.updated_at DESC
        ")->fetchAll();
    } catch (Exception $e) { $msg = 'DB error: ' . $e->getMessage(); }
?>

<div class="page-header">
    <h1>Projects</h1>
    <button class="btn btn-p" onclick="openModal('modal-new-project')">+ New Project</button>
</div>

<?php if ($projects): ?>
<div class="card" style="padding:0;overflow:hidden">
<table>
<thead>
<tr>
    <th>Project Name</th>
    <th>Location</th>
    <th class="num">Area (m²)</th>
    <th>Status</th>
    <th class="num">RABs</th>
    <th>Last Updated</th>
    <th>Actions</th>
</tr>
</thead>
<tbody>
<?php foreach ($projects as $proj): ?>
<tr>
    <td>
        <a href="rab_tool.php?v=project&id=<?= $proj['id'] ?>" style="font-weight:600;color:#e2e8f0"><?= he($proj['name']) ?></a>
        <?php if ($proj['max_grand_total']): ?>
        <div style="font-size:11px;color:#0c7c84;margin-top:2px"><?= fmt_idr($proj['max_grand_total']) ?></div>
        <?php endif; ?>
    </td>
    <td style="color:#94a3b8"><?= he($proj['location'] ?? '—') ?></td>
    <td class="num"><?= $proj['gross_floor_area_m2'] ? number_format((float)$proj['gross_floor_area_m2'], 1, '.', ',') : '—' ?></td>
    <td>
        <?php
        $sc = ['draft'=>'b-gray','active'=>'b-green','archived'=>'b-yellow'];
        $sl = $sc[$proj['status']] ?? 'b-gray';
        ?><span class="badge <?= $sl ?>"><?= ucfirst($proj['status']) ?></span>
    </td>
    <td class="num"><?= $proj['rab_count'] ?></td>
    <td style="color:#64748b;font-size:12px"><?= $proj['last_updated'] ? date('d M Y', strtotime($proj['last_updated'])) : '—' ?></td>
    <td>
        <div class="actions">
            <a href="rab_tool.php?v=project&id=<?= $proj['id'] ?>" class="btn btn-o btn-xs">View</a>
            <button class="btn btn-o btn-xs" onclick="openEditProject(<?= $proj['id'] ?>, '<?= addslashes(he($proj['name'])) ?>', '<?= addslashes(he($proj['location'] ?? '')) ?>', '<?= addslashes(he($proj['description'] ?? '')) ?>', '<?= $proj['gross_floor_area_m2'] ?? '' ?>', '<?= $proj['status'] ?>')">Edit</button>
            <form method="POST" style="display:inline" onsubmit="return confirm('Delete project and ALL its RABs?')">
                <input type="hidden" name="action" value="delete_project">
                <input type="hidden" name="proj_id" value="<?= $proj['id'] ?>">
                <button type="submit" class="btn btn-r btn-xs">Del</button>
            </form>
        </div>
    </td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<?php else: ?>
<div class="card" style="text-align:center;padding:40px;color:#64748b">
    <div style="font-size:2.5rem;margin-bottom:12px">&#127968;</div>
    <div style="font-weight:600;color:#e2e8f0;margin-bottom:6px">No projects yet</div>
    <div style="margin-bottom:16px">Create your first RAB project or run the cost calculator.</div>
    <div style="display:flex;gap:10px;justify-content:center">
        <button class="btn btn-p" onclick="openModal('modal-new-project')">+ New Project</button>
        <a href="rab_tool.php?v=calculator" class="btn btn-o">&#128290; Calculator</a>
    </div>
</div>
<?php endif; ?>

<!-- New/Edit Project Modal -->
<div class="modal-bg" id="modal-new-project">
<div class="modal">
    <button class="modal-close" onclick="closeModal('modal-new-project')">&times;</button>
    <h2 id="modal-project-title">New Project</h2>
    <form method="POST" id="project-form">
        <input type="hidden" name="action" value="save_project">
        <input type="hidden" name="proj_id" id="form-proj-id" value="0">
        <div class="form-grid" style="margin-bottom:14px">
            <div class="fg span2">
                <label>Project Name *</label>
                <input type="text" name="name" id="form-proj-name" required placeholder="e.g. Villa Selong 2-Storey">
            </div>
            <div class="fg">
                <label>Location</label>
                <input type="text" name="location" id="form-proj-loc" placeholder="e.g. Selong Belanak">
            </div>
            <div class="fg">
                <label>Gross Floor Area (m²)</label>
                <input type="number" name="gross_floor_area_m2" id="form-proj-area" min="0" step="0.5" placeholder="e.g. 250">
            </div>
            <div class="fg span2">
                <label>Description</label>
                <textarea name="description" id="form-proj-desc" rows="2" placeholder="Optional notes..."></textarea>
            </div>
            <div class="fg">
                <label>Status</label>
                <select name="status" id="form-proj-status">
                    <option value="draft">Draft</option>
                    <option value="active">Active</option>
                    <option value="archived">Archived</option>
                </select>
            </div>
        </div>
        <div style="display:flex;gap:8px">
            <button type="submit" class="btn btn-p" id="modal-save-btn">Create Project</button>
            <button type="button" class="btn btn-o" onclick="closeModal('modal-new-project')">Cancel</button>
        </div>
    </form>
</div>
</div>

<?php

// ═══════════════════════════════════════════════════════════════════════════
// VIEW: PROJECT DETAIL
// ═══════════════════════════════════════════════════════════════════════════
elseif ($view === 'project' && $id > 0):
    $proj = null;
    $rabs = [];
    try {
        $pq = $db->prepare("SELECT * FROM rab_projects WHERE id=?");
        $pq->execute([$id]);
        $proj = $pq->fetch();
        if ($proj) {
            $rq = $db->prepare("
                SELECT r.*, COALESCE(t.grand_total,0) AS grand_total, t.house_area_m2
                FROM rab_rabs r
                LEFT JOIN rab_totals t ON t.rab_id=r.id
                WHERE r.project_id=?
                ORDER BY r.version ASC
            ");
            $rq->execute([$id]);
            $rabs = $rq->fetchAll();
        }
    } catch (Exception $e) { $msg = 'Error: ' . $e->getMessage(); }

    if (!$proj): ?>
<div class="card"><p style="color:#f87171">Project not found.</p><a href="rab_tool.php?v=projects" class="back-link">← Projects</a></div>
<?php else: ?>

<a href="rab_tool.php?v=projects" class="back-link">&#8592; All Projects</a>

<div class="page-header">
    <div>
        <h1><?= he($proj['name']) ?></h1>
        <div style="color:#64748b;font-size:13px;margin-top:2px">
            <?= he($proj['location'] ?? '') ?>
            <?php if ($proj['gross_floor_area_m2']): ?>
            &nbsp;&bull;&nbsp; <?= number_format((float)$proj['gross_floor_area_m2'], 1, '.', ',') ?> m²
            <?php endif; ?>
            &nbsp;&bull;&nbsp; <span class="badge <?= ['draft'=>'b-gray','active'=>'b-green','archived'=>'b-yellow'][$proj['status']] ?? 'b-gray' ?>"><?= ucfirst($proj['status']) ?></span>
        </div>
    </div>
    <div style="display:flex;gap:8px">
        <button class="btn btn-o" onclick="openEditProject(<?= $proj['id'] ?>, '<?= addslashes(he($proj['name'])) ?>', '<?= addslashes(he($proj['location'] ?? '')) ?>', '<?= addslashes(he($proj['description'] ?? '')) ?>', '<?= $proj['gross_floor_area_m2'] ?? '' ?>', '<?= $proj['status'] ?>')">Edit Project</button>
        <button class="btn btn-p" onclick="openModal('modal-new-rab')">+ New RAB Version</button>
    </div>
</div>

<?php if ($proj['description']): ?>
<div class="card-sm" style="color:#94a3b8;font-size:13px;margin-bottom:20px"><?= he($proj['description']) ?></div>
<?php endif; ?>

<h2>RAB Versions</h2>

<?php if ($rabs): ?>
<div class="card" style="padding:0;overflow:hidden">
<table>
<thead>
<tr>
    <th>Version</th>
    <th>Name</th>
    <th>Created</th>
    <th class="num">Grand Total</th>
    <th class="num">House Area</th>
    <th>Actions</th>
</tr>
</thead>
<tbody>
<?php foreach ($rabs as $rab): ?>
<tr>
    <td><span class="badge b-teal">v<?= $rab['version'] ?></span></td>
    <td>
        <a href="rab_tool.php?v=rab&id=<?= $rab['id'] ?>" style="font-weight:600;color:#e2e8f0"><?= he($rab['name']) ?></a>
        <?php if ($rab['notes']): ?><div style="font-size:11px;color:#64748b;margin-top:2px"><?= he($rab['notes']) ?></div><?php endif; ?>
    </td>
    <td style="color:#64748b;font-size:12px"><?= date('d M Y', strtotime($rab['created_at'])) ?></td>
    <td class="num idr"><?= $rab['grand_total'] > 0 ? fmt_idr($rab['grand_total']) : '<span style="color:#64748b">—</span>' ?></td>
    <td class="num"><?= $rab['house_area_m2'] ? number_format((float)$rab['house_area_m2'], 1, '.', ',') . ' m²' : '—' ?></td>
    <td>
        <div class="actions">
            <a href="rab_tool.php?v=rab&id=<?= $rab['id'] ?>" class="btn btn-p btn-xs">Edit RAB</a>
            <a href="rab_tool.php?v=summary&id=<?= $rab['id'] ?>" class="btn btn-o btn-xs">Summary</a>
            <form method="POST" style="display:inline">
                <input type="hidden" name="action" value="clone_rab">
                <input type="hidden" name="rab_id" value="<?= $rab['id'] ?>">
                <button type="submit" class="btn btn-o btn-xs">Clone</button>
            </form>
            <form method="POST" style="display:inline" onsubmit="return confirm('Delete this RAB version?')">
                <input type="hidden" name="action" value="delete_rab">
                <input type="hidden" name="rab_id" value="<?= $rab['id'] ?>">
                <input type="hidden" name="proj_id" value="<?= $id ?>">
                <button type="submit" class="btn btn-r btn-xs">Del</button>
            </form>
        </div>
    </td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<?php else: ?>
<div class="card" style="text-align:center;padding:32px;color:#64748b">
    <div style="margin-bottom:12px">No RAB versions yet.</div>
    <button class="btn btn-p" onclick="openModal('modal-new-rab')">+ Create First RAB</button>
</div>
<?php endif; ?>

<!-- New RAB Modal -->
<?php
// Fetch build templates for the dropdown
$build_templates = [];
try {
    $build_templates = $db->query("SELECT id, name, code, description, default_tier FROM rab_build_templates WHERE is_active=1 ORDER BY sort_order, name")->fetchAll();
} catch (Exception $e) { /* table may not exist yet */ }
?>
<div class="modal-bg" id="modal-new-rab">
<div class="modal">
    <button class="modal-close" onclick="closeModal('modal-new-rab')">&times;</button>
    <h2>Create New RAB Version</h2>
    <form method="POST">
        <input type="hidden" name="action" value="create_rab">
        <input type="hidden" name="proj_id" value="<?= $id ?>">
        <div class="fg" style="margin-bottom:16px">
            <label>RAB Name</label>
            <input type="text" name="rab_name" placeholder="e.g. Version 1 — Schematic Design">
            <span class="form-note">Leave blank to auto-name (Version 1, Version 2, ...)</span>
        </div>
        <?php if (!empty($build_templates)): ?>
        <div class="fg" style="margin-bottom:16px">
            <label>Build Template <span style="font-weight:400;text-transform:none;letter-spacing:0;color:#64748b">(optional)</span></label>
            <select name="build_template_id" id="build-tpl-select" onchange="showBuildTplInfo(this)">
                <option value="">— Blank RAB (default sections only) —</option>
                <?php foreach ($build_templates as $bt): ?>
                <option value="<?= $bt['id'] ?>" data-desc="<?= he($bt['description'] ?? '') ?>" data-tier="<?= he($bt['default_tier']) ?>">
                    <?= he($bt['name']) ?>
                </option>
                <?php endforeach; ?>
            </select>
            <div id="build-tpl-info" style="display:none;margin-top:8px;padding:10px 12px;background:rgba(12,124,132,.08);border:1px solid rgba(12,124,132,.2);border-radius:6px;font-size:12px;color:#94a3b8">
                <span id="build-tpl-desc"></span>
                <div style="margin-top:6px"><span style="font-weight:600;color:#e2e8f0">Default Tier:</span> <span id="build-tpl-tier"></span></div>
            </div>
        </div>
        <?php endif; ?>
        <div style="display:flex;gap:8px">
            <button type="submit" class="btn btn-p">Create RAB</button>
            <button type="button" class="btn btn-o" onclick="closeModal('modal-new-rab')">Cancel</button>
        </div>
    </form>
</div>
</div>

<!-- Edit Project Modal (shared) -->
<div class="modal-bg" id="modal-edit-project">
<div class="modal">
    <button class="modal-close" onclick="closeModal('modal-edit-project')">&times;</button>
    <h2>Edit Project</h2>
    <form method="POST" id="edit-project-form">
        <input type="hidden" name="action" value="save_project">
        <input type="hidden" name="proj_id" id="edit-proj-id" value="<?= $id ?>">
        <div class="form-grid" style="margin-bottom:14px">
            <div class="fg span2">
                <label>Project Name *</label>
                <input type="text" name="name" id="edit-proj-name" required>
            </div>
            <div class="fg">
                <label>Location</label>
                <input type="text" name="location" id="edit-proj-loc">
            </div>
            <div class="fg">
                <label>Gross Floor Area (m²)</label>
                <input type="number" name="gross_floor_area_m2" id="edit-proj-area" min="0" step="0.5">
            </div>
            <div class="fg span2">
                <label>Description</label>
                <textarea name="description" id="edit-proj-desc" rows="2"></textarea>
            </div>
            <div class="fg">
                <label>Status</label>
                <select name="status" id="edit-proj-status">
                    <option value="draft">Draft</option>
                    <option value="active">Active</option>
                    <option value="archived">Archived</option>
                </select>
            </div>
        </div>
        <div style="display:flex;gap:8px">
            <button type="submit" class="btn btn-p">Save Changes</button>
            <button type="button" class="btn btn-o" onclick="closeModal('modal-edit-project')">Cancel</button>
        </div>
    </form>
</div>
</div>

<?php endif; // proj exists

// ═══════════════════════════════════════════════════════════════════════════
// VIEW: RAB EDITOR
// ═══════════════════════════════════════════════════════════════════════════
elseif ($view === 'rab' && $id > 0):
    $rab_row = null;
    $proj_row = null;
    $totals_row = null;
    $templates_by_disc = [];
    try {
        $rq = $db->prepare("SELECT r.*, p.name AS project_name, p.id AS project_id FROM rab_rabs r JOIN rab_projects p ON p.id=r.project_id WHERE r.id=?");
        $rq->execute([$id]);
        $rab_row = $rq->fetch();
        if ($rab_row) {
            $tq = $db->prepare("SELECT * FROM rab_totals WHERE rab_id=?");
            $tq->execute([$id]);
            $totals_row = $tq->fetch();

            // Templates for add-item dropdown
            $tmpl_q = $db->query("SELECT t.*, d.code AS disc_code, u.code AS unit_code FROM rab_item_templates t JOIN rab_disciplines d ON d.id=t.discipline_id JOIN rab_units u ON u.id=t.default_unit_id WHERE t.is_active=1 ORDER BY d.code, t.section_name, t.name");
            foreach ($tmpl_q->fetchAll() as $tmpl) {
                $templates_by_disc[$tmpl['disc_code']][] = $tmpl;
            }
        }
    } catch (Exception $e) { $msg = 'Error: ' . $e->getMessage(); }

    if (!$rab_row): ?>
<div class="card"><p style="color:#f87171">RAB not found.</p><a href="rab_tool.php?v=projects" class="back-link">← Projects</a></div>
<?php else:
    $house_area = $totals_row ? (float)$totals_row['house_area_m2'] : 0;
    $arch_total = $totals_row ? (float)$totals_row['architecture_total'] : 0;
    $mep_total  = $totals_row ? (float)$totals_row['mep_total'] : 0;
    $str_total  = $totals_row ? (float)$totals_row['structure_total'] : 0;
    $grand      = $totals_row ? (float)$totals_row['grand_total'] : 0;
?>

<a href="rab_tool.php?v=project&id=<?= $rab_row['project_id'] ?>" class="back-link">&#8592; <?= he($rab_row['project_name']) ?></a>

<div class="page-header">
    <div>
        <h1><?= he($rab_row['project_name']) ?> <span style="color:#0c7c84">v<?= $rab_row['version'] ?></span></h1>
        <div style="color:#64748b;font-size:13px;margin-top:2px"><?= he($rab_row['name']) ?></div>
    </div>
    <div style="display:flex;gap:8px;align-items:center">
        <div style="display:flex;align-items:center;gap:8px">
            <label style="font-size:12px;color:#94a3b8;font-weight:600">House Area:</label>
            <input type="number" id="house-area-input" value="<?= $house_area ?>" min="0" step="0.5" style="width:90px;padding:6px 8px;font-size:13px" placeholder="m²">
            <span style="font-size:12px;color:#64748b">m²</span>
            <button class="btn btn-o btn-sm" onclick="saveArea()">Save</button>
        </div>
        <a href="rab_tool.php?v=summary&id=<?= $id ?>" class="btn btn-o btn-sm">&#128196; Summary</a>
        <a href="rab_tool.php?action=export_excel&id=<?= $id ?>" class="btn btn-g btn-sm" id="export-link">&#128462; Export XLS</a>
    </div>
</div>

<!-- Discipline Totals Bar -->
<div class="disc-totals" id="disc-totals-bar">
    <div class="disc-total-card">
        <div class="label">Architecture</div>
        <div class="value idr" id="total-arch"><?= fmt_idr($arch_total) ?></div>
    </div>
    <div class="disc-total-card">
        <div class="label">MEP</div>
        <div class="value idr" id="total-mep"><?= fmt_idr($mep_total) ?></div>
    </div>
    <div class="disc-total-card">
        <div class="label">Structure</div>
        <div class="value idr" id="total-str"><?= fmt_idr($str_total) ?></div>
    </div>
    <div class="disc-total-card grand">
        <div class="label">Grand Total</div>
        <div class="value idr" id="total-grand"><?= fmt_idr($grand) ?></div>
    </div>
</div>

<!-- Discipline Tabs -->
<div class="tabs">
<?php foreach ($disciplines as $disc): ?>
    <button class="tab-btn<?= $disc === reset($disciplines) ? ' active' : '' ?>" onclick="switchTab('tab-<?= $disc['code'] ?>',this)"><?= he($disc['name']) ?></button>
<?php endforeach; ?>
</div>

<?php foreach ($disciplines as $disc):
    $first_disc = $disc === reset($disciplines);
    // Load sections for this discipline
    $sects_q = $db->prepare("SELECT s.* FROM rab_sections s WHERE s.rab_id=? AND s.discipline_id=? ORDER BY s.order_index");
    $sects_q->execute([$id, $disc['id']]);
    $sections = $sects_q->fetchAll();
    $disc_templates = $templates_by_disc[$disc['code']] ?? [];
?>
<div class="tab-panel<?= $first_disc ? ' active' : '' ?>" id="tab-<?= $disc['code'] ?>">

    <?php foreach ($sections as $sect):
        $items_q = $db->prepare("SELECT i.*, u.code AS unit_code FROM rab_items i LEFT JOIN rab_units u ON u.id=i.unit_id WHERE i.section_id=? ORDER BY i.order_index");
        $items_q->execute([$sect['id']]);
        $items = $items_q->fetchAll();
        $sect_total = 0;
        foreach ($items as $it) { $sect_total += (float)$it['quantity'] * (float)$it['rate']; }
    ?>
    <div class="section-panel" id="sect-<?= $sect['id'] ?>">
        <div class="section-header" onclick="toggleSection(<?= $sect['id'] ?>)">
            <span class="section-title"><?= he($sect['name']) ?></span>
            <span class="idr" style="font-size:13px;color:#5eead4;margin-right:10px" id="sect-total-<?= $sect['id'] ?>"><?= fmt_idr($sect_total) ?></span>
            <div class="actions" onclick="event.stopPropagation()">
                <button class="btn btn-o btn-xs" onclick="renameSection(<?= $sect['id'] ?>, '<?= addslashes(he($sect['name'])) ?>')">Rename</button>
                <button class="btn btn-r btn-xs" onclick="deleteSection(<?= $sect['id'] ?>, '<?= addslashes(he($sect['name'])) ?>')">Delete</button>
            </div>
            <span class="section-toggle" id="sect-toggle-<?= $sect['id'] ?>">&#9660;</span>
        </div>
        <div class="section-body" id="sect-body-<?= $sect['id'] ?>">
            <table class="item-table" id="items-table-<?= $sect['id'] ?>">
            <thead>
            <tr>
                <th style="width:35%">Description</th>
                <th>Unit</th>
                <th class="num">Quantity</th>
                <th class="num">Rate (IDR)</th>
                <th class="num">Total (IDR)</th>
                <th style="width:120px">Actions</th>
            </tr>
            </thead>
            <tbody id="items-body-<?= $sect['id'] ?>">
            <?php foreach ($items as $it): ?>
            <tr id="item-row-<?= $it['id'] ?>">
                <td><?= he($it['name']) ?></td>
                <td><?= he($it['unit_code']) ?></td>
                <td class="num"><?= number_format((float)$it['quantity'], 3, '.', ',') ?></td>
                <td class="num"><?= fmt_idr($it['rate']) ?></td>
                <td class="num" id="item-total-<?= $it['id'] ?>"><?= fmt_idr((float)$it['quantity'] * (float)$it['rate']) ?></td>
                <td>
                    <div class="actions">
                        <button class="btn btn-o btn-xs" onclick="editItem(<?= $it['id'] ?>, <?= $sect['id'] ?>, '<?= addslashes(he($it['name'])) ?>', <?= $it['unit_id'] ?>, <?= $it['quantity'] ?>, <?= $it['rate'] ?>)">Edit</button>
                        <button class="btn btn-r btn-xs" onclick="deleteItem(<?= $it['id'] ?>)">Del</button>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
            </table>

            <!-- Add Item Area -->
            <div class="add-item-area" id="add-area-<?= $sect['id'] ?>">
                <button class="btn btn-p btn-sm" onclick="showAddItem(<?= $sect['id'] ?>, <?= $disc['id'] ?>, '<?= addslashes(he($sect['name'])) ?>')">+ Add Item</button>
                <div id="add-form-<?= $sect['id'] ?>" style="display:none;margin-top:10px">
                    <div class="item-edit-form">
                        <div style="margin-bottom:8px">
                            <label style="font-size:11px;color:#94a3b8;font-weight:700;text-transform:uppercase;letter-spacing:.04em;display:block;margin-bottom:4px">From Template</label>
                            <select id="tpl-select-<?= $sect['id'] ?>" onchange="applyTemplate(<?= $sect['id'] ?>, this.value)" style="margin-bottom:8px">
                                <option value="">— Custom (no template) —</option>
                                <?php foreach ($disc_templates as $tmpl): ?>
                                <option value="<?= $tmpl['id'] ?>" data-name="<?= he($tmpl['name']) ?>" data-unit="<?= $tmpl['default_unit_id'] ?>"><?= he($tmpl['section_name']) ?> › <?= he($tmpl['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <!-- Material Picker (context-aware) -->
                        <div style="margin-bottom:8px">
                            <label style="font-size:11px;color:#94a3b8;font-weight:700;text-transform:uppercase;letter-spacing:.04em;display:block;margin-bottom:4px">Material / Item Reference</label>
                            <div style="display:flex;gap:6px;margin-bottom:6px;flex-wrap:wrap">
                                <select id="mat-tier-<?= $sect['id'] ?>" onchange="loadMaterials(<?= $sect['id'] ?>)" style="min-width:120px">
                                    <option value="">All Tiers</option>
                                    <option value="economy">Economy</option>
                                    <option value="standard">Standard</option>
                                    <option value="premium">Premium</option>
                                </select>
                                <select id="mat-group-<?= $sect['id'] ?>" onchange="loadMaterials(<?= $sect['id'] ?>)" style="min-width:140px">
                                    <option value="">All Groups</option>
                                </select>
                                <button class="btn btn-o btn-xs" onclick="loadMaterials(<?= $sect['id'] ?>)" title="Refresh">↻</button>
                            </div>
                            <select id="mat-select-<?= $sect['id'] ?>" onchange="applyMaterial(<?= $sect['id'] ?>)" style="width:100%">
                                <option value="">— Select material to auto-fill rate —</option>
                            </select>
                        </div>
                        <div class="edit-grid">
                            <div class="fg">
                                <label>Description *</label>
                                <input type="text" id="add-name-<?= $sect['id'] ?>" placeholder="Item description">
                            </div>
                            <div class="fg">
                                <label>Unit *</label>
                                <select id="add-unit-<?= $sect['id'] ?>">
                                    <?php foreach ($units_list as $u): ?>
                                    <option value="<?= $u['id'] ?>"><?= he($u['code']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="fg">
                                <label>Quantity</label>
                                <input type="number" id="add-qty-<?= $sect['id'] ?>" value="1" min="0" step="0.001">
                            </div>
                            <div class="fg">
                                <label>Rate (IDR)</label>
                                <input type="number" id="add-rate-<?= $sect['id'] ?>" value="0" min="0" step="1000">
                            </div>
                        </div>
                        <div style="display:flex;gap:8px;margin-top:10px">
                            <button class="btn btn-p btn-sm" onclick="saveNewItem(<?= $sect['id'] ?>)">Save Item</button>
                            <button class="btn btn-o btn-sm" onclick="hideAddItem(<?= $sect['id'] ?>)">Cancel</button>
                        </div>
                    </div>
                </div>
            </div>
        </div><!-- .section-body -->
    </div><!-- .section-panel -->
    <?php endforeach; // sections ?>

    <!-- Add Section -->
    <div style="margin-top:12px;display:flex;gap:8px;align-items:center">
        <input type="text" id="new-sect-name-<?= $disc['code'] ?>" placeholder="New section name..." style="max-width:280px">
        <button class="btn btn-o btn-sm" onclick="addSection('<?= $disc['code'] ?>', <?= $id ?>, <?= $disc['id'] ?>)">+ Add Section</button>
    </div>
</div><!-- .tab-panel -->
<?php endforeach; // disciplines ?>

<?php endif; // rab_row exists

// ═══════════════════════════════════════════════════════════════════════════
// VIEW: CALCULATOR
// ═══════════════════════════════════════════════════════════════════════════
elseif ($view === 'calculator'):
    $presets = [];
    try {
        $presets = $db->query("SELECT * FROM rab_calculator_presets ORDER BY is_default DESC, name ASC")->fetchAll();
    } catch (Exception $e) {}
?>

<div class="page-header">
    <h1>&#128290; Cost Calculator</h1>
</div>

<?php if (!$presets): ?>
<div class="card" style="color:#64748b">No calculator presets found. Please add presets in the <a href="rab.php?s=presets">RAB Admin</a>.</div>
<?php else: ?>

<form method="POST" id="calc-form">
<input type="hidden" name="action" value="run_calculator">
<div style="display:grid;grid-template-columns:1fr 380px;gap:20px;align-items:start">

<!-- Left column -->
<div>

<div class="card calc-section">
    <h3>Preset &amp; Quality Level</h3>
    <div class="fg" style="margin-bottom:16px">
        <label>Rate Preset</label>
        <select name="preset_id" id="preset-sel">
            <?php foreach ($presets as $p): ?>
            <option value="<?= $p['id'] ?>"<?= $p['is_default'] ? ' selected' : '' ?>><?= he($p['name']) ?><?= $p['description'] ? ' — ' . he(mb_substr($p['description'],0,60)) : '' ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="quality-radios">
        <div class="quality-opt">
            <input type="radio" name="quality" id="q-low" value="low">
            <label for="q-low"><span class="quality-label">Economy</span><span class="quality-sub">Budget finish</span></label>
        </div>
        <div class="quality-opt">
            <input type="radio" name="quality" id="q-mid" value="mid" checked>
            <label for="q-mid"><span class="quality-label">Standard</span><span class="quality-sub">Mid-range</span></label>
        </div>
        <div class="quality-opt">
            <input type="radio" name="quality" id="q-high" value="high">
            <label for="q-high"><span class="quality-label">Premium</span><span class="quality-sub">High-end finish</span></label>
        </div>
    </div>
</div>

<div class="card calc-section">
    <h3>Building Floors</h3>
    <div class="fg" style="margin-bottom:16px">
        <label>Number of Storeys</label>
        <select name="num_storeys" id="storeys-sel" onchange="updateFloorInputs()">
            <option value="1">1 Storey</option>
            <option value="2">2 Storeys</option>
            <option value="3">3 Storeys</option>
            <option value="4">4+ Storeys</option>
        </select>
    </div>
    <div class="form-grid">
        <div class="fg" id="fa-1">
            <label>Ground Floor Area (m²)</label>
            <input type="number" name="floor_area_1" value="" min="0" step="0.5" placeholder="e.g. 150">
        </div>
        <div class="fg" id="fa-2" style="display:none">
            <label>1st Floor Area (m²)</label>
            <input type="number" name="floor_area_2" value="0" min="0" step="0.5" placeholder="e.g. 120">
        </div>
        <div class="fg" id="fa-3" style="display:none">
            <label>2nd Floor Area (m²)</label>
            <input type="number" name="floor_area_3" value="0" min="0" step="0.5" placeholder="e.g. 100">
        </div>
        <div class="fg" id="fa-4" style="display:none">
            <label>Other Levels Area (m²)</label>
            <input type="number" name="floor_area_4" value="0" min="0" step="0.5" placeholder="e.g. 80">
        </div>
    </div>
</div>

<div class="card calc-section">
    <h3>Optional Extras</h3>

    <div style="margin-bottom:14px">
        <label class="ck" style="margin-bottom:8px">
            <input type="checkbox" name="walkable_rooftop" id="ck-rooftop" onchange="toggleField('rooftop-area-wrap', this.checked)">
            <span>Walkable Rooftop / Roof Deck</span>
        </label>
        <div id="rooftop-area-wrap" style="display:none;margin-left:24px">
            <div class="fg">
                <label>Rooftop Area (m²)</label>
                <input type="number" name="rooftop_area" value="" min="0" step="0.5" placeholder="e.g. 80">
            </div>
        </div>
    </div>

    <div style="margin-bottom:14px">
        <label class="ck" style="margin-bottom:8px">
            <input type="checkbox" name="has_pool" id="ck-pool" onchange="toggleField('pool-wrap', this.checked)">
            <span>Swimming Pool</span>
        </label>
        <div id="pool-wrap" style="display:none;margin-left:24px">
            <div class="form-grid" style="margin-bottom:8px">
                <div class="fg">
                    <label>Pool Area (m²)</label>
                    <input type="number" name="pool_area" value="" min="0" step="0.5" placeholder="e.g. 30">
                </div>
                <div class="fg">
                    <label>Pool Type</label>
                    <div style="display:flex;gap:16px;margin-top:6px">
                        <label class="ck"><input type="radio" name="pool_type" value="standard" checked><span>Standard</span></label>
                        <label class="ck"><input type="radio" name="pool_type" value="infinity"><span>Infinity</span></label>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div>
        <div class="fg">
            <label>Deck / Terrace Area (m²)</label>
            <input type="number" name="deck_area" value="" min="0" step="0.5" placeholder="e.g. 40">
        </div>
    </div>
</div>

<button type="submit" class="btn btn-p" style="font-size:15px;padding:11px 28px">Calculate Cost &#10140;</button>

</div><!-- left col -->

<!-- Right: preset info -->
<div>
<?php if ($presets): $dp = null; foreach ($presets as $p) { if ($p['is_default']) { $dp = $p; break; } } if (!$dp) $dp = $presets[0]; ?>
<div class="card" id="preset-info-card">
    <h3 style="margin-bottom:12px"><?= he($dp['name']) ?> — Rates</h3>
    <div id="preset-rates">
        <div class="breakdown-row"><span>Economy (m²)</span><span class="idr"><?= fmt_idr($dp['base_cost_per_m2_low']) ?></span></div>
        <div class="breakdown-row"><span>Standard (m²)</span><span class="idr"><?= fmt_idr($dp['base_cost_per_m2_mid']) ?></span></div>
        <div class="breakdown-row"><span>Premium (m²)</span><span class="idr"><?= fmt_idr($dp['base_cost_per_m2_high']) ?></span></div>
        <div class="breakdown-row"><span>Standard Pool (m²)</span><span class="idr"><?= fmt_idr($dp['pool_cost_per_m2_standard']) ?></span></div>
        <div class="breakdown-row"><span>Infinity Pool (m²)</span><span class="idr"><?= fmt_idr($dp['pool_cost_per_m2_infinity']) ?></span></div>
        <div class="breakdown-row"><span>Deck (m²)</span><span class="idr"><?= fmt_idr($dp['deck_cost_per_m2']) ?></span></div>
        <div class="breakdown-row"><span>Rooftop (m²)</span><span class="idr"><?= fmt_idr($dp['rooftop_cost_per_m2']) ?></span></div>
        <div class="breakdown-row"><span>Location Factor</span><span><?= number_format((float)$dp['location_factor'],3) ?>×</span></div>
        <div class="breakdown-row"><span>Contingency</span><span><?= number_format((float)$dp['contingency_percent'],1) ?>%</span></div>
    </div>
</div>

<!-- Preset data for JS -->
<script>
var PRESETS = <?php
$p_data = [];
foreach ($presets as $p) { $p_data[(int)$p['id']] = $p; }
echo json_encode($p_data);
?>;
</script>
<?php endif; ?>
</div><!-- right col -->

</div><!-- grid -->
</form>
<?php endif; /* close if (!$presets) else */ ?>

<?php

// ═══════════════════════════════════════════════════════════════════════════
// VIEW: CALCULATOR RESULT
// ═══════════════════════════════════════════════════════════════════════════
elseif ($view === 'result' && $id > 0):
    $run = null;
    $preset = null;
    try {
        $rq = $db->prepare("SELECT * FROM rab_calculator_runs WHERE id=?");
        $rq->execute([$id]);
        $run = $rq->fetch();
        if ($run) {
            $pq = $db->prepare("SELECT * FROM rab_calculator_presets WHERE id=?");
            $pq->execute([$run['preset_id']]);
            $preset = $pq->fetch();
        }
    } catch (Exception $e) {}

    if (!$run): ?>
<div class="card"><p style="color:#f87171">Result not found.</p><a href="rab_tool.php?v=calculator" class="back-link">← Calculator</a></div>
<?php else: ?>

<a href="rab_tool.php?v=calculator" class="back-link">&#8592; New Calculation</a>
<h1>Cost Estimate Result</h1>

<div class="result-grid">

<!-- Left: inputs -->
<div class="result-card">
    <h3 style="margin-bottom:14px">Your Inputs</h3>
    <?php
    $ql_map = ['low'=>'Economy','mid'=>'Standard','high'=>'Premium'];
    ?>
    <div class="breakdown-row"><span>Preset</span><span><?= he($preset ? $preset['name'] : '?') ?></span></div>
    <div class="breakdown-row"><span>Quality Level</span><span><?= he($ql_map[$run['quality_level']] ?? $run['quality_level']) ?></span></div>
    <div class="breakdown-row"><span>Storeys</span><span><?= $run['num_storeys'] ?></span></div>
    <div class="breakdown-row"><span>Ground Floor</span><span><?= number_format((float)$run['floor_area_level1_m2'],1,'.',',' ) ?> m²</span></div>
    <?php if ($run['floor_area_level2_m2'] > 0): ?>
    <div class="breakdown-row"><span>1st Floor</span><span><?= number_format((float)$run['floor_area_level2_m2'],1,'.',',' ) ?> m²</span></div>
    <?php endif; ?>
    <?php if ($run['floor_area_level3_m2'] > 0): ?>
    <div class="breakdown-row"><span>2nd Floor</span><span><?= number_format((float)$run['floor_area_level3_m2'],1,'.',',' ) ?> m²</span></div>
    <?php endif; ?>
    <?php if ($run['floor_area_other_m2'] > 0): ?>
    <div class="breakdown-row"><span>Other Levels</span><span><?= number_format((float)$run['floor_area_other_m2'],1,'.',',' ) ?> m²</span></div>
    <?php endif; ?>
    <div class="breakdown-row total"><span>Total Floor Area</span><span><?= number_format((float)$run['total_floor_area_m2'],1,'.',',' ) ?> m²</span></div>
    <?php if ($run['rooftop_walkable']): ?>
    <div class="breakdown-row"><span>Walkable Rooftop</span><span><?= number_format((float)$run['rooftop_area_m2'],1) ?> m²</span></div>
    <?php endif; ?>
    <?php if ($run['pool_has_pool']): ?>
    <div class="breakdown-row"><span>Pool</span><span><?= $run['pool_is_infinity'] ? 'Infinity' : 'Standard' ?> — <?= number_format((float)$run['pool_area_m2'],1) ?> m²</span></div>
    <?php endif; ?>
    <?php if ($run['deck_area_m2'] > 0): ?>
    <div class="breakdown-row"><span>Deck / Terrace</span><span><?= number_format((float)$run['deck_area_m2'],1) ?> m²</span></div>
    <?php endif; ?>
</div>

<!-- Right: cost breakdown -->
<div class="result-card">
    <h3 style="margin-bottom:14px">Cost Breakdown</h3>
    <div class="breakdown-row"><span>Building Cost</span><span class="idr"><?= fmt_idr($run['building_cost']) ?></span></div>
    <?php if ($run['rooftop_cost'] > 0): ?>
    <div class="breakdown-row"><span>Rooftop Cost</span><span class="idr"><?= fmt_idr($run['rooftop_cost']) ?></span></div>
    <?php endif; ?>
    <?php if ($run['pool_cost'] > 0): ?>
    <div class="breakdown-row"><span>Pool Cost</span><span class="idr"><?= fmt_idr($run['pool_cost']) ?></span></div>
    <?php endif; ?>
    <?php if ($run['deck_cost'] > 0): ?>
    <div class="breakdown-row"><span>Deck Cost</span><span class="idr"><?= fmt_idr($run['deck_cost']) ?></span></div>
    <?php endif; ?>
    <div class="breakdown-row total"><span>Subtotal</span><span class="idr"><?= fmt_idr($run['subtotal_cost']) ?></span></div>
    <div class="breakdown-row"><span>Contingency (<?= $preset ? number_format((float)$preset['contingency_percent'],1) . '%' : '' ?>)</span><span class="idr"><?= fmt_idr($run['contingency_amount']) ?></span></div>
    <div class="breakdown-row grand"><span>Grand Total</span><span class="idr"><?= fmt_idr($run['grand_total_cost']) ?></span></div>
    <?php if ($run['total_floor_area_m2'] > 0): ?>
    <div style="margin-top:14px;padding:10px;background:#0a1628;border-radius:6px;font-size:12px;color:#64748b;text-align:center">
        Cost per m²: <strong style="color:#5eead4"><?= fmt_idr($run['grand_total_cost'] / $run['total_floor_area_m2']) ?></strong>
    </div>
    <?php endif; ?>

    <div style="margin-top:14px;padding-top:14px;border-top:1px solid rgba(255,255,255,.07)">
        <div style="font-size:12px;color:#64748b;margin-bottom:6px">Indicative split:</div>
        <div class="breakdown-row"><span>Structure (30%)</span><span class="idr"><?= fmt_idr($run['grand_total_cost'] * 0.30) ?></span></div>
        <div class="breakdown-row"><span>Architecture (50%)</span><span class="idr"><?= fmt_idr($run['grand_total_cost'] * 0.50) ?></span></div>
        <div class="breakdown-row"><span>MEP (20%)</span><span class="idr"><?= fmt_idr($run['grand_total_cost'] * 0.20) ?></span></div>
    </div>
</div>

</div><!-- result-grid -->

<div style="margin-top:22px;display:flex;gap:10px;flex-wrap:wrap">
    <a href="rab_tool.php?v=calculator" class="btn btn-o">&#8592; New Calculation</a>
    <button class="btn btn-p" onclick="openModal('modal-create-project')">&#127968; Create RAB Project from Estimate</button>
</div>

<!-- Create Project from Estimate Modal -->
<div class="modal-bg" id="modal-create-project">
<div class="modal">
    <button class="modal-close" onclick="closeModal('modal-create-project')">&times;</button>
    <h2>Create RAB Project from Estimate</h2>
    <p style="color:#64748b;font-size:13px;margin-bottom:16px">
        This will create a new project with one RAB version containing three lump-sum items
        (Structure 30%, Architecture 50%, MEP 20%) based on the total of
        <strong style="color:#0c7c84"><?= fmt_idr($run['grand_total_cost']) ?></strong>.
    </p>
    <form method="POST">
        <input type="hidden" name="action" value="create_project_from_calc">
        <input type="hidden" name="run_id" value="<?= $run['id'] ?>">
        <div class="fg" style="margin-bottom:12px">
            <label>Project Name *</label>
            <input type="text" name="proj_name" required placeholder="e.g. Villa Lombok 3-bed">
        </div>
        <div class="fg" style="margin-bottom:16px">
            <label>Location</label>
            <input type="text" name="location" placeholder="e.g. Selong Belanak">
        </div>
        <div style="display:flex;gap:8px">
            <button type="submit" class="btn btn-p">Create Project &amp; RAB</button>
            <button type="button" class="btn btn-o" onclick="closeModal('modal-create-project')">Cancel</button>
        </div>
    </form>
</div>
</div>

<?php endif; // run exists

// ═══════════════════════════════════════════════════════════════════════════
// VIEW: RAB SUMMARY
// ═══════════════════════════════════════════════════════════════════════════
elseif ($view === 'summary' && $id > 0):
    $rab_row = null;
    $totals_row = null;
    $disc_sections = [];
    try {
        $rq = $db->prepare("SELECT r.*, p.name AS project_name, p.id AS project_id FROM rab_rabs r JOIN rab_projects p ON p.id=r.project_id WHERE r.id=?");
        $rq->execute([$id]);
        $rab_row = $rq->fetch();
        if ($rab_row) {
            $tq = $db->prepare("SELECT * FROM rab_totals WHERE rab_id=?");
            $tq->execute([$id]);
            $totals_row = $tq->fetch();

            foreach ($disciplines as $disc) {
                $sects_q = $db->prepare("SELECT s.* FROM rab_sections s WHERE s.rab_id=? AND s.discipline_id=? ORDER BY s.order_index");
                $sects_q->execute([$id, $disc['id']]);
                $sects = $sects_q->fetchAll();
                foreach ($sects as &$s) {
                    $iq = $db->prepare("SELECT i.*, u.code AS unit_code FROM rab_items i LEFT JOIN rab_units u ON u.id=i.unit_id WHERE i.section_id=? ORDER BY i.order_index");
                    $iq->execute([$s['id']]);
                    $s['items'] = $iq->fetchAll();
                }
                unset($s);
                $disc_sections[$disc['code']] = ['disc' => $disc, 'sections' => $sects];
            }
        }
    } catch (Exception $e) { $msg = 'Error: ' . $e->getMessage(); }

    if (!$rab_row): ?>
<div class="card"><p style="color:#f87171">RAB not found.</p><a href="rab_tool.php?v=projects" class="back-link">← Projects</a></div>
<?php else:
    $house_area = $totals_row ? (float)$totals_row['house_area_m2'] : 0;
    $arch_total = $totals_row ? (float)$totals_row['architecture_total'] : 0;
    $mep_total  = $totals_row ? (float)$totals_row['mep_total'] : 0;
    $str_total  = $totals_row ? (float)$totals_row['structure_total'] : 0;
    $grand      = $totals_row ? (float)$totals_row['grand_total'] : 0;
    $cost_pm2   = ($house_area > 0 && $grand > 0) ? $grand / $house_area : 0;
?>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;flex-wrap:wrap;gap:10px">
    <div>
        <a href="rab_tool.php?v=rab&id=<?= $id ?>" class="back-link" style="display:inline-flex;margin-bottom:6px">&#8592; Back to Editor</a>
        <h1><?= he($rab_row['project_name']) ?> — <span style="color:#0c7c84">v<?= $rab_row['version'] ?> Summary</span></h1>
        <div style="color:#64748b;font-size:13px"><?= he($rab_row['name']) ?><?= $house_area ? ' &nbsp;&bull;&nbsp; ' . number_format($house_area,1) . ' m²' : '' ?></div>
    </div>
    <div>
        <a href="rab_tool.php?v=summary&id=<?= $id ?>&action=export_excel" class="btn btn-g">&#128462; Export Excel</a>
    </div>
</div>

<!-- Summary Cards -->
<div class="disc-totals" style="margin-bottom:24px">
    <div class="disc-total-card">
        <div class="label">Architecture</div>
        <div class="value idr"><?= fmt_idr($arch_total) ?></div>
    </div>
    <div class="disc-total-card">
        <div class="label">MEP</div>
        <div class="value idr"><?= fmt_idr($mep_total) ?></div>
    </div>
    <div class="disc-total-card">
        <div class="label">Structure</div>
        <div class="value idr"><?= fmt_idr($str_total) ?></div>
    </div>
    <div class="disc-total-card grand">
        <div class="label">Grand Total</div>
        <div class="value idr"><?= fmt_idr($grand) ?></div>
    </div>
</div>

<?php if ($cost_pm2 > 0): ?>
<div class="card-sm" style="text-align:center;font-size:14px;color:#94a3b8;margin-bottom:24px">
    Cost per m²: <strong style="color:#5eead4;font-size:1.1rem"><?= fmt_idr($cost_pm2) ?></strong>
    <span style="color:#64748b;font-size:12px"> / m² (based on <?= number_format($house_area,1) ?> m²)</span>
</div>
<?php endif; ?>

<!-- Discipline breakdown -->
<?php foreach ($disc_sections as $code => $ds):
    $disc_total = 0;
    foreach ($ds['sections'] as $s) { foreach ($s['items'] as $it) { $disc_total += (float)$it['quantity'] * (float)$it['rate']; } }
?>
<div class="card" style="margin-bottom:20px">
<h2 style="margin-bottom:4px;color:#0c7c84"><?= he($ds['disc']['name']) ?></h2>
<div style="font-size:13px;color:#5eead4;font-weight:600;margin-bottom:14px"><?= fmt_idr($disc_total) ?></div>
<table class="summary-table">
<thead>
<tr>
    <th style="width:40px">No.</th>
    <th>Description</th>
    <th>Unit</th>
    <th class="num">Quantity</th>
    <th class="num">Rate</th>
    <th class="num">Amount</th>
</tr>
</thead>
<tbody>
<?php $item_no = 0; foreach ($ds['sections'] as $sect):
    $sect_total = 0;
    foreach ($sect['items'] as $it) { $sect_total += (float)$it['quantity'] * (float)$it['rate']; }
?>
<tr style="background:#0c2336">
    <td colspan="5" style="font-weight:700;color:#93c5fd;padding:7px 12px"><?= he($sect['name']) ?></td>
    <td class="num" style="font-weight:700;color:#93c5fd"><?= fmt_idr($sect_total) ?></td>
</tr>
<?php foreach ($sect['items'] as $it):
    $item_no++;
    $line_total = (float)$it['quantity'] * (float)$it['rate'];
?>
<tr>
    <td style="color:#64748b"><?= $item_no ?></td>
    <td><?= he($it['name']) ?></td>
    <td><?= he($it['unit_code'] ?? '') ?></td>
    <td class="num"><?= number_format((float)$it['quantity'],3,'.',',' ) ?></td>
    <td class="num"><?= fmt_idr($it['rate']) ?></td>
    <td class="num"><?= fmt_idr($line_total) ?></td>
</tr>
<?php endforeach; // items ?>
<?php endforeach; // sections ?>
</tbody>
</table>
</div>
<?php endforeach; // disc_sections ?>

<!-- Grand Total row -->
<table class="summary-table" style="margin-bottom:30px">
<tfoot>
<tr class="summary-grand">
    <td colspan="5" style="font-size:1.05rem;font-weight:700;color:#0c7c84">GRAND TOTAL</td>
    <td class="num" style="font-size:1.15rem;font-weight:700;color:#0c7c84"><?= fmt_idr($grand) ?></td>
</tr>
</tfoot>
</table>

<?php endif; // rab_row exists for summary

// ═══════════════════════════════════════════════════════════════════════════
// FALLBACK
// ═══════════════════════════════════════════════════════════════════════════
else: ?>
<div class="page-header"><h1>Projects</h1><button class="btn btn-p" onclick="openModal('modal-new-project')">+ New Project</button></div>
<div class="card"><a href="rab_tool.php?v=projects">Go to Projects</a> or <a href="rab_tool.php?v=calculator">Calculator</a></div>
<?php endif; ?>

</div><!-- .main -->
</div><!-- .shell -->

<!-- ══ JAVASCRIPT ══════════════════════════════════════════════════════════ -->
<script>
// ── Modal helpers ──────────────────────────────────────────────────────────
function openModal(id) {
    var el = document.getElementById(id);
    if (el) el.classList.add('open');
}
function closeModal(id) {
    var el = document.getElementById(id);
    if (el) el.classList.remove('open');
}
// Close modal on backdrop click
document.addEventListener('click', function(e) {
    if (e.target.classList.contains('modal-bg')) {
        e.target.classList.remove('open');
    }
});

// ── Edit project helper ────────────────────────────────────────────────────
function openEditProject(pid, name, loc, desc, area, status) {
    var modal = document.getElementById('modal-edit-project') || document.getElementById('modal-new-project');
    if (!modal) return;

    // Try edit modal first (project detail page), fall back to new project modal (projects list)
    var editModal = document.getElementById('modal-edit-project');
    var newModal  = document.getElementById('modal-new-project');

    if (editModal) {
        document.getElementById('edit-proj-id').value    = pid;
        document.getElementById('edit-proj-name').value  = name;
        document.getElementById('edit-proj-loc').value   = loc;
        document.getElementById('edit-proj-desc').value  = desc;
        document.getElementById('edit-proj-area').value  = area;
        var sel = document.getElementById('edit-proj-status');
        if (sel) sel.value = status;
        openModal('modal-edit-project');
    } else if (newModal) {
        document.getElementById('form-proj-id').value    = pid;
        document.getElementById('form-proj-name').value  = name;
        document.getElementById('form-proj-loc').value   = loc;
        document.getElementById('form-proj-desc').value  = desc;
        document.getElementById('form-proj-area').value  = area;
        var sel2 = document.getElementById('form-proj-status');
        if (sel2) sel2.value = status;
        document.getElementById('modal-project-title').textContent = 'Edit Project';
        document.getElementById('modal-save-btn').textContent = 'Save Changes';
        openModal('modal-new-project');
    }
}

// ── RAB Editor tabs ─────────────────────────────────────────────────────────
function switchTab(panelId, btn) {
    var panels = document.querySelectorAll('.tab-panel');
    var btns   = document.querySelectorAll('.tab-btn');
    for (var i = 0; i < panels.length; i++) {
        panels[i].classList.remove('active');
        btns[i].classList.remove('active');
    }
    var panel = document.getElementById(panelId);
    if (panel) panel.classList.add('active');
    if (btn) btn.classList.add('active');
}

// ── Section toggle ──────────────────────────────────────────────────────────
function toggleSection(sectId) {
    var body   = document.getElementById('sect-body-' + sectId);
    var toggle = document.getElementById('sect-toggle-' + sectId);
    if (!body) return;
    if (body.classList.contains('open')) {
        body.classList.remove('open');
        if (toggle) toggle.classList.remove('open');
    } else {
        body.classList.add('open');
        if (toggle) toggle.classList.add('open');
    }
}

// ── AJAX helper ────────────────────────────────────────────────────────────
function ajaxPost(data, callback) {
    var xhr = new XMLHttpRequest();
    xhr.open('POST', 'rab_tool.php', true);
    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
    xhr.onreadystatechange = function() {
        if (xhr.readyState === 4) {
            try {
                var res = JSON.parse(xhr.responseText);
                callback(res);
            } catch(e) {
                callback({ok: false, msg: 'Parse error: ' + xhr.responseText.substring(0, 100)});
            }
        }
    };
    var parts = [];
    for (var k in data) {
        parts.push(encodeURIComponent(k) + '=' + encodeURIComponent(data[k]));
    }
    xhr.send(parts.join('&'));
}

function encodeForm(obj) {
    var parts = [];
    for (var k in obj) {
        parts.push(encodeURIComponent(k) + '=' + encodeURIComponent(obj[k]));
    }
    return parts.join('&');
}

// Format IDR in JS
function fmtIdr(val) {
    val = parseFloat(val) || 0;
    return 'Rp ' + Math.round(val).toLocaleString('id-ID');
}

// ── Update totals display ──────────────────────────────────────────────────
function updateTotalsBar(totals) {
    if (!totals) return;
    var el;
    el = document.getElementById('total-arch');   if (el) el.textContent = fmtIdr(totals.arch);
    el = document.getElementById('total-mep');    if (el) el.textContent = fmtIdr(totals.mep);
    el = document.getElementById('total-str');    if (el) el.textContent = fmtIdr(totals.str);
    el = document.getElementById('total-grand');  if (el) el.textContent = fmtIdr(totals.grand);
}

// ── Add/Edit Items ─────────────────────────────────────────────────────────
var _editItemId   = 0;
var _editSectId   = 0;

// ── Section name map for context-aware material loading ──
var _sectionNames = {};

function showAddItem(sectId, discId, sectionName) {
    var form = document.getElementById('add-form-' + sectId);
    if (form) form.style.display = 'block';
    _sectionNames[sectId] = sectionName || '';
    // Auto-load materials filtered by the section name (group_type)
    loadMaterials(sectId);
}
function hideAddItem(sectId) {
    var form = document.getElementById('add-form-' + sectId);
    if (form) form.style.display = 'none';
}

// ── Build Template info display ──
function showBuildTplInfo(sel) {
    var info = document.getElementById('build-tpl-info');
    var desc = document.getElementById('build-tpl-desc');
    var tier = document.getElementById('build-tpl-tier');
    if (!info || !sel) return;
    var opt = sel.options[sel.selectedIndex];
    if (!opt || !sel.value) { info.style.display = 'none'; return; }
    var d = opt.getAttribute('data-desc') || '';
    var t = opt.getAttribute('data-tier') || 'standard';
    if (desc) desc.textContent = d;
    if (tier) {
        var colors = {economy: '#22c55e', standard: '#3b82f6', premium: '#a855f7'};
        tier.innerHTML = '<span style="color:' + (colors[t] || '#3b82f6') + ';font-weight:700">' + t.charAt(0).toUpperCase() + t.slice(1) + '</span>';
    }
    info.style.display = 'block';
}

// ── Context-aware material loading ──
var _materialsCache = {};

function loadMaterials(sectId) {
    var tierSel  = document.getElementById('mat-tier-' + sectId);
    var groupSel = document.getElementById('mat-group-' + sectId);
    var matSel   = document.getElementById('mat-select-' + sectId);
    if (!matSel) return;

    var tier  = tierSel ? tierSel.value : '';
    var group = groupSel ? groupSel.value : '';

    // If group is empty and section name maps to a known group, auto-select it
    var sn = _sectionNames[sectId] || '';
    // Only auto-filter if it's NOT a custom/user-defined section
    var isCustom = true;
    var knownSections = ['Site Works','Walls','Floors','Ceilings','Doors & Windows','Roof','Finishes','Waterproofing','External Works','Electrical','Lighting','Plumbing & Sanitary','HVAC','Fire Fighting','Excavation','Foundations','Columns & Beams','Slabs','Stairs','Retaining Walls','Roof Structure'];
    for (var i = 0; i < knownSections.length; i++) {
        if (knownSections[i] === sn) { isCustom = false; break; }
    }
    // For known sections and first load, set group_type = section name
    if (!isCustom && group === '' && !groupSel._loaded) {
        group = sn;
    }

    var url = 'rab_tool.php?v=materials_json&group_type=' + encodeURIComponent(group) + '&tier=' + encodeURIComponent(tier);

    var xhr = new XMLHttpRequest();
    xhr.open('GET', url, true);
    xhr.onreadystatechange = function() {
        if (xhr.readyState !== 4) return;
        if (xhr.status !== 200) return;
        try {
            var data = JSON.parse(xhr.responseText);
            // Update group dropdown
            if (groupSel && data.group_types && !groupSel._loaded) {
                var prev = group;
                groupSel.innerHTML = '<option value="">All Groups</option>';
                for (var g = 0; g < data.group_types.length; g++) {
                    var gt = data.group_types[g];
                    var selAttr = (gt === prev) ? ' selected' : '';
                    groupSel.innerHTML += '<option value="' + gt + '"' + selAttr + '>' + gt + '</option>';
                }
                groupSel._loaded = true;
            }
            // Update materials dropdown
            matSel.innerHTML = '<option value="">\u2014 Select material to auto-fill rate \u2014</option>';
            if (data.materials) {
                for (var m = 0; m < data.materials.length; m++) {
                    var mat = data.materials[m];
                    var tierLabel = mat.tier ? ' [' + mat.tier.charAt(0).toUpperCase() + mat.tier.slice(1) + ']' : '';
                    var priceLabel = mat.default_rate ? ' \u2014 ' + fmtIdr(mat.default_rate) + '/' + (mat.unit_code || '') : '';
                    var groupLabel = mat.group_type ? ' (' + mat.group_type + ')' : '';
                    matSel.innerHTML += '<option value="' + mat.id + '"' +
                        ' data-rate="' + mat.default_rate + '"' +
                        ' data-name="' + mat.name.replace(/"/g, '&quot;') + '"' +
                        ' data-unit="' + (mat.unit_id || '') + '"' +
                        '>' + mat.name + tierLabel + priceLabel + groupLabel + '</option>';
                }
            }
        } catch (e) { /* ignore parse errors */ }
    };
    xhr.send();
}

function applyMaterial(sectId) {
    var matSel = document.getElementById('mat-select-' + sectId);
    if (!matSel || !matSel.value) return;
    var opt = matSel.options[matSel.selectedIndex];
    if (!opt) return;

    var nameEl = document.getElementById('add-name-' + sectId);
    var rateEl = document.getElementById('add-rate-' + sectId);
    var unitEl = document.getElementById('add-unit-' + sectId);

    var matName = opt.getAttribute('data-name') || '';
    var matRate = opt.getAttribute('data-rate') || '0';
    var matUnit = opt.getAttribute('data-unit') || '';

    if (nameEl && !nameEl.value) nameEl.value = matName;
    if (rateEl) rateEl.value = matRate;
    if (unitEl && matUnit) unitEl.value = matUnit;
}

function applyTemplate(sectId, tplId) {
    if (!tplId) return;
    var sel  = document.getElementById('tpl-select-' + sectId);
    var opt  = sel ? sel.options[sel.selectedIndex] : null;
    if (!opt) return;
    var nameEl = document.getElementById('add-name-' + sectId);
    var unitEl = document.getElementById('add-unit-' + sectId);
    if (nameEl) nameEl.value = opt.getAttribute('data-name') || '';
    if (unitEl) unitEl.value = opt.getAttribute('data-unit') || '';
}

function saveNewItem(sectId) {
    var name   = document.getElementById('add-name-' + sectId).value.trim();
    var unitId = document.getElementById('add-unit-' + sectId).value;
    var qty    = document.getElementById('add-qty-' + sectId).value;
    var rate   = document.getElementById('add-rate-' + sectId).value;
    var tplSel = document.getElementById('tpl-select-' + sectId);
    var tplId  = tplSel ? tplSel.value : '';

    if (!name || !unitId) { alert('Name and unit are required.'); return; }

    ajaxPost({
        action: 'save_item',
        section_id: sectId,
        name: name,
        unit_id: unitId,
        quantity: qty,
        rate: rate,
        tpl_id: tplId
    }, function(res) {
        if (res.ok) {
            // Refresh the page to show the new item
            window.location.reload();
        } else {
            alert(res.msg || 'Error saving item.');
        }
    });
}

function editItem(itemId, sectId, name, unitId, qty, rate) {
    // Create an inline edit overlay in the row
    var row = document.getElementById('item-row-' + itemId);
    if (!row) return;

    // Build select options for units
    var unitOpts = '';
    var unitMap = <?php
        $unit_opts = [];
        foreach ($units_list as $u) { $unit_opts[] = ['id' => (int)$u['id'], 'code' => $u['code']]; }
        echo json_encode($unit_opts);
    ?>;
    for (var i = 0; i < unitMap.length; i++) {
        var sel = (unitMap[i].id == unitId) ? ' selected' : '';
        unitOpts += '<option value="' + unitMap[i].id + '"' + sel + '>' + unitMap[i].code + '</option>';
    }

    row.innerHTML = '<td colspan="6">' +
        '<div class="item-edit-form">' +
        '<div class="edit-grid">' +
        '<div class="fg"><label>Description</label><input type="text" id="ei-name" value="' + name.replace(/"/g,'&quot;') + '"></div>' +
        '<div class="fg"><label>Unit</label><select id="ei-unit">' + unitOpts + '</select></div>' +
        '<div class="fg"><label>Quantity</label><input type="number" id="ei-qty" value="' + qty + '" step="0.001"></div>' +
        '<div class="fg"><label>Rate (IDR)</label><input type="number" id="ei-rate" value="' + rate + '" step="1000"></div>' +
        '</div>' +
        '<div style="display:flex;gap:8px;margin-top:8px">' +
        '<button class="btn btn-p btn-sm" onclick="saveEditItem(' + itemId + ', ' + sectId + ')">Save</button>' +
        '<button class="btn btn-o btn-sm" onclick="cancelEditItem(' + itemId + ')">Cancel</button>' +
        '</div>' +
        '</div>' +
        '</td>';

    _editItemId = itemId;
    _editSectId = sectId;
}

function saveEditItem(itemId, sectId) {
    var name   = document.getElementById('ei-name').value.trim();
    var unitId = document.getElementById('ei-unit').value;
    var qty    = document.getElementById('ei-qty').value;
    var rate   = document.getElementById('ei-rate').value;
    if (!name || !unitId) { alert('Name and unit required.'); return; }

    ajaxPost({
        action: 'save_item',
        item_id: itemId,
        section_id: sectId,
        name: name,
        unit_id: unitId,
        quantity: qty,
        rate: rate
    }, function(res) {
        if (res.ok) {
            window.location.reload();
        } else {
            alert(res.msg || 'Error saving.');
        }
    });
}

function cancelEditItem(itemId) {
    window.location.reload();
}

function deleteItem(itemId) {
    if (!confirm('Delete this item?')) return;
    ajaxPost({action: 'delete_item', item_id: itemId}, function(res) {
        if (res.ok) {
            var row = document.getElementById('item-row-' + itemId);
            if (row) row.remove();
            updateTotalsBar(res.totals);
        } else {
            alert(res.msg || 'Error deleting item.');
        }
    });
}

// ── Sections ────────────────────────────────────────────────────────────────
function renameSection(sectId, currentName) {
    var newName = prompt('New section name:', currentName);
    if (!newName || !newName.trim()) return;
    ajaxPost({action: 'save_section', section_id: sectId, name: newName.trim()}, function(res) {
        if (res.ok) {
            var title = document.querySelector('#sect-' + sectId + ' .section-title');
            if (title) title.textContent = newName.trim();
        } else {
            alert(res.msg || 'Error renaming.');
        }
    });
}

function deleteSection(sectId, name) {
    if (!confirm('Delete section "' + name + '"? (Only empty sections can be deleted)')) return;
    ajaxPost({action: 'delete_section', section_id: sectId}, function(res) {
        if (res.ok) {
            var panel = document.getElementById('sect-' + sectId);
            if (panel) panel.remove();
            updateTotalsBar(res.totals);
        } else {
            alert(res.msg || res.msg);
        }
    });
}

function addSection(discCode, rabId, discId) {
    var inp = document.getElementById('new-sect-name-' + discCode);
    if (!inp) return;
    var name = inp.value.trim();
    if (!name) { inp.focus(); return; }
    ajaxPost({action: 'save_section', rab_id: rabId, disc_id: discId, name: name}, function(res) {
        if (res.ok) {
            inp.value = '';
            // Reload to show new section
            window.location.reload();
        } else {
            alert(res.msg || 'Error creating section.');
        }
    });
}

// ── House area ──────────────────────────────────────────────────────────────
function saveArea() {
    var inp = document.getElementById('house-area-input');
    if (!inp) return;
    var rabId = <?php echo ($view === 'rab') ? $id : 0; ?>;
    ajaxPost({action: 'update_area', rab_id: rabId, area: inp.value}, function(res) {
        if (res.ok) {
            updateTotalsBar(res.totals);
        } else {
            alert(res.msg || 'Error saving area.');
        }
    });
}

// ── Calculator ──────────────────────────────────────────────────────────────
function updateFloorInputs() {
    var sel = document.getElementById('storeys-sel');
    if (!sel) return;
    var n = parseInt(sel.value, 10);
    for (var i = 1; i <= 4; i++) {
        var wrap = document.getElementById('fa-' + i);
        if (!wrap) continue;
        wrap.style.display = (i <= n) ? '' : 'none';
        var inp = wrap.querySelector('input');
        if (inp && i > n) inp.value = '0';
    }
}

function toggleField(wrapperId, show) {
    var el = document.getElementById(wrapperId);
    if (el) el.style.display = show ? '' : 'none';
}

// Update preset info panel on preset change
function updatePresetInfo() {
    var sel = document.getElementById('preset-sel');
    if (!sel || typeof PRESETS === 'undefined') return;
    var pid = parseInt(sel.value, 10);
    var p = PRESETS[pid];
    if (!p) return;

    var el = document.getElementById('preset-rates');
    if (!el) return;

    function row(label, val) {
        return '<div class="breakdown-row"><span>' + label + '</span><span class="idr">' + fmtIdr(val) + '</span></div>';
    }
    el.innerHTML =
        row('Economy (m²)',      p.base_cost_per_m2_low) +
        row('Standard (m²)',     p.base_cost_per_m2_mid) +
        row('Premium (m²)',      p.base_cost_per_m2_high) +
        row('Standard Pool (m²)',p.pool_cost_per_m2_standard) +
        row('Infinity Pool (m²)',p.pool_cost_per_m2_infinity) +
        row('Deck (m²)',         p.deck_cost_per_m2) +
        row('Rooftop (m²)',      p.rooftop_cost_per_m2) +
        '<div class="breakdown-row"><span>Location Factor</span><span>' + parseFloat(p.location_factor).toFixed(3) + '×</span></div>' +
        '<div class="breakdown-row"><span>Contingency</span><span>' + parseFloat(p.contingency_percent).toFixed(1) + '%</span></div>';
}

// Wire preset select on calculator page
(function() {
    var sel = document.getElementById('preset-sel');
    if (sel) {
        sel.addEventListener('change', function() { updatePresetInfo(); });
    }
})();

// Export link — already set correctly in PHP

</script>
</body>
</html>

<?php
// ─── LOGIN FUNCTION ────────────────────────────────────────────────────────
function show_login(string $error = ''): void {
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title>RAB Tool Login — Build in Lombok</title>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',system-ui,sans-serif;background:#0f172a;display:flex;align-items:center;justify-content:center;min-height:100vh}
.lc{background:#1e293b;border-radius:12px;padding:40px;box-shadow:0 8px 32px rgba(0,0,0,.4);width:100%;max-width:360px;border:1px solid rgba(255,255,255,.07)}
.brand{display:flex;align-items:center;gap:10px;margin-bottom:24px}
.brand-icon{background:#0c7c84;border-radius:8px;width:40px;height:40px;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:12px;color:#fff;letter-spacing:.05em;flex-shrink:0}
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
        <div><h1>RAB Tool</h1><p>Build in Lombok</p></div>
    </div>
    <?php if ($error): ?><div class="err"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
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
