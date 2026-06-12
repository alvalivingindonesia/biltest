<?php
/**
 * Build in Lombok — One-time listing corrector + review desk (docs/adr/0007)
 *
 * Re-reads EVERY listing's price by MAGNITUDE (land size + a plausible Lombok
 * per-m² band) instead of trusting the unreliable "Per Are" label, so numbers
 * that are already totals are kept (never multiplied into trillions). Land only
 * — built property (villa/house/apartment) is priced as a total incl. building,
 * so its land per-m² is never gated. Also re-resolves silent-'praya' areas and
 * mines feature tags (ocean_view, beachfront, …) from the descriptions.
 *
 * This is also a working desk: each flagged row has its SOURCE link (to verify
 * against the live portal) and inline controls to fix the area / price or
 * accept it — no need to hop to the main console.
 *
 *   • Dry-run by default; ?apply=1 writes the bulk corrections + tags.
 *   • Per-row Save / Accept buttons POST immediately (independent of apply).
 *
 * Place at: /admin/recanonicalize_listings.php  (not linked; direct URL only)
 */

session_start();
require_once('/home/rovin629/config/biltest_config.php');
require_once(__DIR__ . '/../api/listing_canonical.php');

// ─── auth ────────────────────────────────────────────────────────────
$auth_error = '';
if (isset($_POST['login'])) {
    if (($_POST['username'] ?? '') === ADMIN_USER && ($_POST['password'] ?? '') === ADMIN_PASS) {
        $_SESSION['admin_auth'] = true;
    } else { $auth_error = 'Invalid credentials.'; }
}
if (isset($_GET['logout'])) { session_destroy(); header('Location: recanonicalize_listings.php'); exit; }
if (empty($_SESSION['admin_auth'])) {
    echo '<!doctype html><meta charset="utf-8"><title>Login</title>';
    echo '<form method="post" style="font-family:sans-serif;max-width:320px;margin:80px auto">';
    echo '<h2>Re-canonicalise listings</h2>';
    if ($auth_error) echo '<p style="color:#c00">'.htmlspecialchars($auth_error).'</p>';
    echo '<p><input name="username" placeholder="username" style="width:100%;padding:8px"></p>';
    echo '<p><input name="password" type="password" placeholder="password" style="width:100%;padding:8px"></p>';
    echo '<p><button name="login" value="1" style="padding:8px 16px">Log in</button></p></form>';
    exit;
}

function get_db() {
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO('mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4', DB_USER, DB_PASS, array(
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ));
    }
    return $pdo;
}

$db = get_db();

// ─── bulk apply (checkbox selection) — POST, then redirect (PRG) ─────────
// Each table is one form; checked rows post ids[] plus their area[id]/price[id]
// (and loc[id] for area conflicts so we can learn the alias). Only checked rows
// are written.
if (($_POST['do'] ?? '') === 'bulk_apply') {
    $ids    = isset($_POST['ids'])   && is_array($_POST['ids'])   ? $_POST['ids']   : array();
    $areaM  = isset($_POST['area'])  && is_array($_POST['area'])  ? $_POST['area']  : array();
    $priceM = isset($_POST['price']) && is_array($_POST['price']) ? $_POST['price'] : array();
    $locM   = isset($_POST['loc'])   && is_array($_POST['loc'])   ? $_POST['loc']   : array();

    $upd_psqm  = $db->prepare(
        "UPDATE listings SET price_idr_per_sqm = CASE WHEN listing_type_key = 'land' AND land_size_sqm > 0 AND land_size_sqm < 50000000
                                                      THEN ROUND(price_idr / land_size_sqm) ELSE price_idr_per_sqm END WHERE id = ?");
    $resolve   = $db->prepare(
        "UPDATE listing_review_queue SET status = 'resolved', resolved_at = NOW()
          WHERE listing_id = ? AND status = 'open' AND kind IN ('price_uncertain','price_verify','area_flip','unmapped_area')");
    $alias_ins = $db->prepare("INSERT IGNORE INTO area_aliases (alias_text, area_key) VALUES (?, ?)");

    $n = 0;
    foreach ($ids as $rawid) {
        $lid = (int)$rawid; if ($lid <= 0) continue;
        $sets = array(); $vals = array();
        if (isset($priceM[$lid])) {
            $p = (int)preg_replace('/\D+/', '', (string)$priceM[$lid]);
            if ($p > 0) { $sets[] = 'price_idr = ?'; $vals[] = $p; }
        }
        $area = isset($areaM[$lid]) ? trim((string)$areaM[$lid]) : '';
        if ($area !== '') { $sets[] = 'area_key = ?'; $vals[] = $area; }
        $sets[] = 'price_review_flag = 0';
        $sets[] = 'updated_at = NOW()';
        $vals[] = $lid;
        $db->prepare("UPDATE listings SET " . implode(', ', $sets) . " WHERE id = ?")->execute($vals);
        $upd_psqm->execute(array($lid));
        // Teach the area alias from the conflict's location text so future
        // imports map automatically (same as the ingest console does).
        if ($area !== '' && !empty($locM[$lid])) {
            $norm = lc_normalize_area_text((string)$locM[$lid]);
            if ($norm !== '') $alias_ins->execute(array($norm, $area));
        }
        $resolve->execute(array($lid));
        $n++;
    }
    header('Location: recanonicalize_listings.php?done=' . $n);
    exit;
}

// ─── per-row actions (Save / Accept) — POST, then redirect (PRG) ─────────
if (($_POST['do'] ?? '') === 'row_action') {
    $lid = (int)($_POST['listing_id'] ?? 0);
    $act = $_POST['act'] ?? '';
    if ($lid > 0) {
        if ($act === 'accept') {
            // Numbers look right — just clear the review flag.
            $db->prepare("UPDATE listings SET price_review_flag = 0, updated_at = NOW() WHERE id = ?")->execute(array($lid));
        } elseif ($act === 'save') {
            $sets = array(); $vals = array();
            $praw = preg_replace('/\D+/', '', (string)($_POST['price_idr'] ?? ''));
            if ($praw !== '') { $sets[] = 'price_idr = ?'; $vals[] = (int)$praw; }
            $area = trim((string)($_POST['area_key'] ?? ''));
            if ($area !== '') { $sets[] = 'area_key = ?'; $vals[] = $area; }
            $sets[] = 'price_review_flag = 0';
            $sets[] = 'updated_at = NOW()';
            $vals[] = $lid;
            $db->prepare("UPDATE listings SET " . implode(', ', $sets) . " WHERE id = ?")->execute($vals);
            // Recompute per-m² for LAND only (built property's land per-m² is meaningless).
            if ($praw !== '') {
                $db->prepare(
                    "UPDATE listings
                        SET price_idr_per_sqm = CASE WHEN listing_type_key = 'land' AND land_size_sqm > 0 AND land_size_sqm < 50000000
                                                     THEN ROUND(price_idr / land_size_sqm) ELSE price_idr_per_sqm END
                      WHERE id = ?"
                )->execute(array($lid));
            }
        }
        // Resolve any open price/area review items for this listing.
        $db->prepare(
            "UPDATE listing_review_queue SET status = 'resolved', resolved_at = NOW()
              WHERE listing_id = ? AND status = 'open'
                AND kind IN ('price_uncertain','price_verify','area_flip','unmapped_area')"
        )->execute(array($lid));
    }
    header('Location: recanonicalize_listings.php#l' . $lid);
    exit;
}

$apply = isset($_GET['apply']) && $_GET['apply'] === '1';

// Lookups for the inline controls.
$areas = $db->query("SELECT `key`, label FROM areas ORDER BY label")->fetchAll();
$tag_labels = array();
try {
    foreach ($db->query("SELECT `key`, label FROM feature_tags")->fetchAll() as $t) $tag_labels[$t['key']] = $t['label'];
} catch (Exception $e) { /* feature_tags absent — fall back to raw keys */ }

$rows = $db->query(
    "SELECT id, title, description, short_description, location_detail, area_key,
            price_idr, price_label, price_idr_per_sqm, price_review_flag,
            land_size_sqm, listing_type_key, source_url
       FROM listings"
)->fetchAll();

$price_auto   = array();  // confident ('ok') — auto-applied on bulk Apply
$price_verify = array();  // soft ('verify') — auto-normalised + flagged, plus per-row controls
$price_review = array();  // implausible — price kept, flagged, per-row controls
$price_nosize = array();  // priced but no land size to sanity-check
$area_fixes   = array();
$area_flips   = array();
$tag_rows     = array();  // [id, title, tags[]]
$tag_added    = 0;

foreach ($rows as $r) {
    $id    = (int)$r['id'];
    $sqm   = (int)$r['land_size_sqm'];
    $sqm_ok = lc_trustworthy_size_sqm($sqm);
    $type  = (string)$r['listing_type_key'];
    $is_land = ($type === 'land' || $type === '');
    $label = (string)$r['price_label'];
    $cur_idr  = $r['price_idr'] === null ? null : (int)$r['price_idr'];
    $cur_psqm = $r['price_idr_per_sqm'] === null ? null : (int)$r['price_idr_per_sqm'];

    // ── TAGS — mine descriptions for feature tags ────────────────────
    $tags = lc_suggest_tags($r['title'], $r['description'], $r['short_description'], $type);
    if ($tags) {
        $tag_rows[] = array('id' => $id, 'title' => $r['title'], 'tags' => $tags);
        if ($apply) $tag_added += lc_save_tags($db, $id, $tags);
    }

    // ── PRICE — re-evaluate by magnitude, not by label ───────────────
    if ($cur_idr !== null && $cur_idr > 0) {
        $inf = lc_infer_price($cur_idr, $sqm, $label, $is_land);
        $dsug = $is_land ? lc_best_total_from_text($r['description'], $sqm) : null;
        $row = array(
            'id' => $id, 'title' => $r['title'], 'source_url' => $r['source_url'],
            'type' => $type, 'area_key' => $r['area_key'],
            'sqm' => $sqm_ok ? $sqm : null, 'are' => $sqm_ok ? round($sqm / 100, 2) : null,
            'stored' => $cur_idr, 'interp' => $inf['interp'], 'note' => $inf['note'],
            'total' => $inf['total'], 'per_sqm' => $inf['per_sqm'], 'per_are' => $inf['per_are'],
            'confidence' => $inf['confidence'], 'tags' => $tags,
            'desc_total' => $dsug ? $dsug['total'] : null, 'desc_raw' => $dsug ? $dsug['raw'] : '',
        );

        if (!$sqm_ok && $is_land) {
            if ($inf['confidence'] === 'review') {
                $price_nosize[] = $row;
                if ($apply) {
                    $db->prepare("UPDATE listings SET price_review_flag = 1, updated_at = NOW() WHERE id = ?")->execute(array($id));
                    lc_queue_review($db, $id, 'price_uncertain', array('stored_idr' => $cur_idr, 'reason' => $inf['note'], 'land_size_sqm' => $sqm));
                }
            }
        } elseif ($inf['confidence'] === 'review') {
            $price_review[] = $row;
            if ($apply) {
                $db->prepare("UPDATE listings SET price_review_flag = 1, updated_at = NOW() WHERE id = ?")->execute(array($id));
                lc_queue_review($db, $id, 'price_uncertain', array(
                    'stored_idr' => $cur_idr, 'land_size_sqm' => $sqm,
                    'implied_per_sqm' => $inf['per_sqm'], 'reason' => $inf['note']));
            }
        } else {
            // 'ok' or 'verify' — price_idr becomes the canonical TOTAL, per-m²
            // populated, label normalised. (For these, total usually == stored,
            // so the price itself is unchanged — only the label/flag move.)
            $new_total = (int)$inf['total'];
            $new_psqm  = $inf['per_sqm'] !== null ? (int)$inf['per_sqm'] : null;
            $flag = $inf['confidence'] === 'verify' ? 1 : 0;
            $changed = ($new_total !== $cur_idr) || ($new_psqm !== $cur_psqm) || ($label !== 'Total') || ((int)$r['price_review_flag'] !== $flag);
            $row['changed_total'] = ($new_total !== $cur_idr);
            if ($changed) {
                if ($inf['confidence'] === 'verify') $price_verify[] = $row; else $price_auto[] = $row;
                if ($apply) {
                    $db->prepare("UPDATE listings SET price_idr = ?, price_idr_per_sqm = ?, price_label = 'Total', price_review_flag = ?, updated_at = NOW() WHERE id = ?")
                       ->execute(array($new_total, $new_psqm, $flag, $id));
                    if ($flag) lc_queue_review($db, $id, 'price_verify', array(
                        'stored_idr' => $cur_idr, 'new_total' => $new_total,
                        'land_size_sqm' => $sqm, 'per_sqm' => $new_psqm, 'reason' => $inf['note']));
                }
            }
        }
    }

    // ── AREA ─────────────────────────────────────────────────────────
    $resolved = lc_resolve_area_key($db, array($r['location_detail'], $r['title'], $r['description']));
    if ($resolved && $resolved !== $r['area_key']) {
        $is_default = ($r['area_key'] === null || $r['area_key'] === '' || $r['area_key'] === 'praya');
        $arow = array('id' => $id, 'title' => $r['title'], 'old' => $r['area_key'], 'new' => $resolved,
                      'loc' => $r['location_detail'], 'source_url' => $r['source_url']);
        if ($is_default) {
            $area_fixes[] = $arow;
            if ($apply) $db->prepare("UPDATE listings SET area_key = ?, updated_at = NOW() WHERE id = ?")->execute(array($resolved, $id));
        } else {
            $area_flips[] = $arow;
            if ($apply) lc_queue_review($db, $id, 'area_flip', array('old_area_key' => $r['area_key'], 'new_area_key' => $resolved, 'location_detail' => $r['location_detail']));
        }
    }
}

// ─── helpers ──────────────────────────────────────────────────────────
header('Content-Type: text/html; charset=utf-8');
$esc = function($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };
$fmt = function($n) { return $n === null ? '—' : 'Rp ' . number_format((int)$n, 0, ',', '.'); };
$are_fmt = function($a){ return $a === null ? '—' : number_format($a, 2, ',', '.') . ' are'; };
$sqm_fmt = function($s){ return $s === null ? '—' : number_format((int)$s, 0, ',', '.') . ' m²'; };
$conf_badge = function($c){
    $map = array('ok'=>array('#093','OK'),'verify'=>array('#b8860b','VERIFY'),'review'=>array('#c00','REVIEW'));
    $m = isset($map[$c]) ? $map[$c] : array('#666', strtoupper($c));
    return '<span style="font-weight:700;color:'.$m[0].'">'.$m[1].'</span>';
};
$src_link = function($url) use ($esc) {
    if (!$url) return '<span style="color:#bbb">no link</span>';
    return '<a href="'.$esc($url).'" target="_blank" rel="noopener">↗ source</a>';
};
$tag_chips = function($tags) use ($esc, $tag_labels) {
    if (!$tags) return '';
    $out = '';
    foreach ($tags as $k) {
        $lbl = isset($tag_labels[$k]) ? $tag_labels[$k] : $k;
        $out .= '<span style="display:inline-block;background:#eef6f6;color:#0c7c84;border-radius:8px;padding:1px 7px;font-size:11px;margin:1px 2px">'.$esc($lbl).'</span>';
    }
    return $out;
};
// Area <select name="area[ID]"> — pre-selects the SUGGESTED area when given
// (so area-conflict rows just need a tick), else the current area.
$area_select = function($id, $current, $suggest = '') use ($areas, $esc) {
    $want = $suggest !== '' ? $suggest : $current;
    ob_start(); ?>
    <select name="area[<?= (int)$id ?>]" title="area">
      <option value="">— keep (<?= $esc($current ?: 'none') ?>) —</option>
      <?php foreach ($areas as $a): $sel = ($a['key'] === $want) ? ' selected' : ''; ?>
        <option value="<?= $esc($a['key']) ?>"<?= $sel ?>><?= $esc($a['label']) ?></option>
      <?php endforeach; ?>
    </select>
    <?php return ob_get_clean();
};
// Price <input name="price[ID]"> prefilled with the description total if found,
// else the inferred total — with a small "from description" hint above it.
$price_input = function($f) use ($fmt) {
    $has_desc = !empty($f['desc_total']);
    $prefill = $has_desc ? (int)$f['desc_total'] : ($f['total'] !== null ? (int)$f['total'] : (int)$f['stored']);
    ob_start(); ?>
    <?php if ($has_desc): ?><div style="font-size:11px;color:#0c7c84;margin-bottom:2px">📝 <?= $fmt($f['desc_total']) ?></div><?php endif; ?>
    <input name="price[<?= (int)$f['id'] ?>]" value="<?= $prefill ?>" size="13" style="font-variant-numeric:tabular-nums">
    <?php return ob_get_clean();
};
?>
<!doctype html><meta charset="utf-8"><title>Re-canonicalise listings</title>
<style>
 body{font-family:system-ui,sans-serif;margin:24px;color:#1a1a1a}
 h1{font-size:20px} h2{font-size:16px;margin-top:28px}
 table{border-collapse:collapse;width:100%;font-size:13px;margin-top:8px}
 th,td{border:1px solid #ddd;padding:6px 8px;text-align:left;vertical-align:top} th{background:#f5f5f5}
 .banner{padding:12px 16px;border-radius:8px;margin:12px 0}
 .dry{background:#fff7e6;border:1px solid #ffd591}
 .applied{background:#e6ffed;border:1px solid #95de64}
 .btn{display:inline-block;padding:10px 18px;background:#1677ff;color:#fff;border-radius:8px;text-decoration:none;font-weight:600}
 .old{color:#c00} .new{color:#093;font-weight:600}
 a{color:#1677ff} select,input,button{padding:5px;font:inherit;cursor:pointer}
 .type{font-size:11px;color:#666;text-transform:uppercase}
 tr:target{outline:3px solid #ffd591}
 .bulkbar{position:sticky;bottom:0;background:#fff;border-top:2px solid #1677ff;padding:10px 0;margin-top:6px}
 .bulkbtn{padding:9px 18px;background:#1677ff;color:#fff;border:0;border-radius:6px;font-weight:600;cursor:pointer}
 .chkcol{width:34px;text-align:center}
</style>
<script>
 function toggleAll(master, cls){ document.querySelectorAll('.'+cls).forEach(c => c.checked = master.checked); }
</script>
<h1>Re-canonicalise listings <a href="?logout=1" style="font-size:12px;float:right">log out</a></h1>

<?php if (isset($_GET['done'])): ?>
  <div class="banner applied"><strong><?= (int)$_GET['done'] ?> listing(s) updated.</strong> They've been removed from the lists below.</div>
<?php endif; ?>
<?php if ($apply): ?>
  <div class="banner applied"><strong>APPLIED.</strong> Bulk corrections + tags written.
  Tick the flagged rows below and use <em>Apply checked</em> to finish verifying.
  Remaining queue is in the <a href="ingest_console.php">ingest console</a>.</div>
<?php else: ?>
  <div class="banner dry"><strong>DRY RUN.</strong> The Confident/Area-corrected/Tags sections only write when you hit
  <em>Apply bulk changes</em>. The <strong>Verify</strong>, <strong>Review</strong> and <strong>Area conflict</strong>
  sections are interactive: tick rows and press <em>Apply checked</em> (those write immediately). &nbsp;
  <a class="btn" href="?apply=1" onclick="return confirm('Apply all bulk price/area/tag changes below?')">Apply bulk changes</a></div>
<?php endif; ?>

<p>
  Confident price fixes: <strong><?= count($price_auto) ?></strong> &nbsp;·&nbsp;
  Verify (high / ambiguous): <strong><?= count($price_verify) ?></strong> &nbsp;·&nbsp;
  Review (implausible): <strong><?= count($price_review) ?></strong> &nbsp;·&nbsp;
  No land size: <strong><?= count($price_nosize) ?></strong> &nbsp;·&nbsp;
  Area corrections: <strong><?= count($area_fixes) ?></strong> &nbsp;·&nbsp;
  Area conflicts: <strong><?= count($area_flips) ?></strong> &nbsp;·&nbsp;
  Listings with tags: <strong><?= count($tag_rows) ?></strong><?php if ($apply): ?> (<?= $tag_added ?> new tags written)<?php endif; ?>
</p>
<p style="font-size:12px;color:#666;margin:4px 0">
  Prices read against a plausible Lombok LAND band of Rp <?= number_format(LC_PERM2_MIN,0,',','.') ?>–<?= number_format(LC_PERM2_HARD_MAX,0,',','.') ?>/m²
  (soft ceiling Rp <?= number_format(LC_PERM2_SOFT_MAX,0,',','.') ?>/m²). A value that is already a sane total is kept (never multiplied).
  Built property (villa/house/apartment) is always a total — its land per-m² is not gated.
</p>

<h2>① Verify — tick the ones that look right, then Apply checked <span style="font-weight:400;font-size:12px;color:#666">(high per-m² or also-plausible-as-per-are; price kept as total)</span></h2>
<form method="post">
<input type="hidden" name="do" value="bulk_apply">
<table><tr>
  <th class="chkcol"><input type="checkbox" onclick="toggleAll(this,'cv')" title="select all / none"></th>
  <th>ID</th><th>Title / type</th><th>Source</th><th>Land</th><th>per m²</th><th>Note</th><th>Area</th><th>Total price (IDR)</th></tr>
<?php foreach ($price_verify as $f): ?>
 <tr id="l<?= $f['id'] ?>">
  <td class="chkcol"><input type="checkbox" class="cv" name="ids[]" value="<?= (int)$f['id'] ?>"></td>
  <td><?= $f['id'] ?></td>
  <td><?= $esc(mb_substr($f['title'],0,40)) ?><br><span class="type"><?= $esc($f['type']) ?></span><?= $tag_chips($f['tags']) ?></td>
  <td><?= $src_link($f['source_url']) ?></td>
  <td><?= $sqm_fmt($f['sqm']) ?><br><span style="color:#888"><?= $are_fmt($f['are']) ?></span></td>
  <td><?= $fmt($f['per_sqm']) ?><br><span style="color:#888;font-size:11px"><?= $fmt($f['per_are']) ?>/are</span></td>
  <td style="font-size:11px"><?= $esc($f['note']) ?></td>
  <td><?= $area_select($f['id'], $f['area_key']) ?></td>
  <td><?= $price_input($f) ?></td>
 </tr>
<?php endforeach; if (!$price_verify) echo '<tr><td colspan="9">None.</td></tr>'; ?>
</table>
<?php if ($price_verify): ?><div class="bulkbar"><button type="submit" class="bulkbtn">✓ Apply checked — set price + area, clear flag</button></div><?php endif; ?>
</form>

<h2>② Review — no plausible reading <span style="font-weight:400;font-size:12px;color:#666">(too expensive even as a total, or the land size looks wrong; price NOT changed — fix or confirm here)</span></h2>
<form method="post">
<input type="hidden" name="do" value="bulk_apply">
<table><tr>
  <th class="chkcol"><input type="checkbox" onclick="toggleAll(this,'cr')" title="select all / none"></th>
  <th>ID</th><th>Title / type</th><th>Source</th><th>Land</th><th>Stored price</th><th>Implied per m²</th><th>Note</th><th>Area</th><th>Total price (IDR)</th></tr>
<?php foreach (array_merge($price_review, $price_nosize) as $f): ?>
 <tr id="l<?= $f['id'] ?>">
  <td class="chkcol"><input type="checkbox" class="cr" name="ids[]" value="<?= (int)$f['id'] ?>"></td>
  <td><?= $f['id'] ?></td>
  <td><?= $esc(mb_substr($f['title'],0,40)) ?><br><span class="type"><?= $esc($f['type']) ?></span><?= $tag_chips($f['tags']) ?></td>
  <td><?= $src_link($f['source_url']) ?></td>
  <td><?= $sqm_fmt($f['sqm']) ?></td>
  <td class="old"><?= $fmt($f['stored']) ?></td>
  <td><?= $fmt($f['per_sqm']) ?></td>
  <td style="font-size:11px"><?= $esc($f['note']) ?></td>
  <td><?= $area_select($f['id'], $f['area_key']) ?></td>
  <td><?= $price_input($f) ?></td>
 </tr>
<?php endforeach; if (!$price_review && !$price_nosize) echo '<tr><td colspan="10">None.</td></tr>'; ?>
</table>
<?php if ($price_review || $price_nosize): ?><div class="bulkbar"><button type="submit" class="bulkbtn">✓ Apply checked — set price + area, clear flag</button></div><?php endif; ?>
</form>

<h2>③ Confident — bulk-applied on Apply <span style="font-weight:400;font-size:12px;color:#666">(already-sane totals; only label/per-m² normalised)</span></h2>
<table><tr>
  <th>ID</th><th>Title / type</th><th>Source</th><th>Land</th><th>Stored</th><th>Read as</th><th>New total</th><th>per m²</th><th>per are</th></tr>
<?php foreach ($price_auto as $f): ?>
 <tr id="l<?= $f['id'] ?>">
  <td><?= $f['id'] ?></td>
  <td><?= $esc(mb_substr($f['title'],0,40)) ?><br><span class="type"><?= $esc($f['type']) ?></span></td>
  <td><?= $src_link($f['source_url']) ?></td>
  <td><?= $sqm_fmt($f['sqm']) ?><br><span style="color:#888"><?= $are_fmt($f['are']) ?></span></td>
  <td class="<?= $f['changed_total'] ? 'old' : '' ?>"><?= $fmt($f['stored']) ?></td>
  <td><?= $esc($f['interp']) ?></td>
  <td class="new"><?= $fmt($f['total']) ?></td>
  <td><?= $fmt($f['per_sqm']) ?></td><td><?= $fmt($f['per_are']) ?></td>
 </tr>
<?php endforeach; if (!$price_auto) echo '<tr><td colspan="9">None.</td></tr>'; ?>
</table>

<h2>④ Area corrected (was default / blank) — bulk-applied</h2>
<table><tr><th>ID</th><th>Title</th><th>Source</th><th>Location text</th><th>Old</th><th>New</th></tr>
<?php foreach ($area_fixes as $f): ?>
 <tr><td><?= $f['id'] ?></td><td><?= $esc(mb_substr($f['title'],0,40)) ?></td><td><?= $src_link($f['source_url']) ?></td>
 <td><?= $esc($f['loc']) ?></td><td class="old"><?= $esc($f['old'] ?: 'NULL') ?></td><td class="new"><?= $esc($f['new']) ?></td></tr>
<?php endforeach; if (!$area_fixes) echo '<tr><td colspan="6">None.</td></tr>'; ?>
</table>

<h2>⑤ Area conflicts → tick to confirm the suggested area, then Apply checked <span style="font-weight:400;font-size:12px;color:#666">(dropdown is pre-set to the suggestion; change it if wrong)</span></h2>
<form method="post">
<input type="hidden" name="do" value="bulk_apply">
<table><tr>
  <th class="chkcol"><input type="checkbox" onclick="toggleAll(this,'ca')" title="select all / none"></th>
  <th>ID</th><th>Title</th><th>Source</th><th>Location text</th><th>Current</th><th>Suggested</th><th>Set area</th></tr>
<?php foreach ($area_flips as $f): ?>
 <tr id="l<?= $f['id'] ?>">
  <td class="chkcol"><input type="checkbox" class="ca" name="ids[]" value="<?= (int)$f['id'] ?>"></td>
  <td><?= $f['id'] ?></td><td><?= $esc(mb_substr($f['title'],0,40)) ?></td><td><?= $src_link($f['source_url']) ?></td>
  <td><?= $esc($f['loc']) ?></td><td><?= $esc($f['old']) ?></td><td class="new"><?= $esc($f['new']) ?></td>
  <td><?= $area_select($f['id'], $f['old'], $f['new']) ?><input type="hidden" name="loc[<?= (int)$f['id'] ?>]" value="<?= $esc($f['loc']) ?>"></td>
 </tr>
<?php endforeach; if (!$area_flips) echo '<tr><td colspan="8">None.</td></tr>'; ?>
</table>
<?php if ($area_flips): ?><div class="bulkbar"><button type="submit" class="bulkbtn">✓ Apply checked areas (and learn the alias)</button></div><?php endif; ?>
</form>

<h2>⑥ Feature tags mined from descriptions <span style="font-weight:400;font-size:12px;color:#666">(written on Apply; never clobbers manual tags)</span></h2>
<table><tr><th>ID</th><th>Title</th><th>Tags</th></tr>
<?php foreach (array_slice($tag_rows, 0, 400) as $f): ?>
 <tr><td><?= $f['id'] ?></td><td><?= $esc(mb_substr($f['title'],0,60)) ?></td><td><?= $tag_chips($f['tags']) ?></td></tr>
<?php endforeach; if (!$tag_rows) echo '<tr><td colspan="3">None.</td></tr>'; ?>
</table>
