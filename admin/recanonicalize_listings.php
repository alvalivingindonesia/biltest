<?php
/**
 * Build in Lombok — One-time listing corrector (docs/adr/0007)
 *
 * Re-runs the corrected canonicalisation over EXISTING listings to fix the
 * per-are price bug and the wrong-location (silent 'praya') bug, without any
 * manual editing. Dry-run by default; pass ?apply=1 to write.
 *
 *   Per-are fix: a row with price_label='Per Are', a trustworthy land size and
 *   NO price_idr_per_sqm is an old buggy row whose price_idr is actually the
 *   per-are unit price -> total = per_are × are. With no size -> Price on
 *   Request + review flag (never a guessed total).
 *
 *   Area fix: rows silently defaulted to 'praya' (or NULL) are re-resolved
 *   against area_aliases from their location/title/description. A conflict with
 *   a non-default existing area is queued for review, not overwritten.
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
$apply = isset($_GET['apply']) && $_GET['apply'] === '1';

$rows = $db->query(
    "SELECT id, title, description, location_detail, area_key,
            price_idr, price_label, price_idr_per_sqm, price_review_flag, land_size_sqm
       FROM listings"
)->fetchAll();

$price_changes = array();  // total or per-m² changed / relabelled (auto-applied)
$price_reviews = array();   // can't trust — flagged, price left as-is
$price_nosize  = array();   // priced but no land size to sanity-check
$area_fixes  = array();
$area_flips  = array();

foreach ($rows as $r) {
    $id = (int)$r['id'];
    $sqm = (int)$r['land_size_sqm'];
    $sqm_ok = lc_trustworthy_size_sqm($sqm);
    $label = (string)$r['price_label'];
    $cur_idr = $r['price_idr'] === null ? null : (int)$r['price_idr'];
    $cur_psqm = $r['price_idr_per_sqm'] === null ? null : (int)$r['price_idr_per_sqm'];

    // ── PRICE ── re-evaluate EVERY priced row by magnitude, not by label ──
    if ($cur_idr !== null && $cur_idr > 0) {
        $inf = lc_infer_price($cur_idr, $sqm, $label);
        $row = array(
            'id' => $id, 'title' => $r['title'],
            'sqm' => $sqm_ok ? $sqm : null, 'are' => $sqm_ok ? round($sqm / 100, 2) : null,
            'stored' => $cur_idr, 'interp' => $inf['interp'], 'note' => $inf['note'],
            'total' => $inf['total'], 'per_sqm' => $inf['per_sqm'], 'per_are' => $inf['per_are'],
            'confidence' => $inf['confidence'],
        );

        if (!$sqm_ok) {
            // No size: keep whatever total we have, but can't verify it.
            if ($inf['confidence'] === 'review') {
                $price_nosize[] = $row;
                if ($apply) {
                    $db->prepare("UPDATE listings SET price_review_flag=1, updated_at=NOW() WHERE id=?")->execute(array($id));
                    lc_queue_review($db, $id, 'price_uncertain', array('stored_idr' => $cur_idr, 'reason' => $inf['note'], 'land_size_sqm' => $sqm));
                }
            }
        } elseif ($inf['confidence'] === 'review') {
            // Implausible at any reading — leave price, flag for a human.
            $price_reviews[] = $row;
            if ($apply) {
                $db->prepare("UPDATE listings SET price_review_flag=1, updated_at=NOW() WHERE id=?")->execute(array($id));
                lc_queue_review($db, $id, 'price_uncertain', array(
                    'stored_idr' => $cur_idr, 'land_size_sqm' => $sqm,
                    'implied_per_sqm' => $inf['per_sqm'], 'reason' => $inf['note']));
            }
        } else {
            // Confident ('ok') or soft ('verify'). price_idr becomes the canonical
            // TOTAL; per-m² populated; label normalised to Total.
            $new_total = (int)$inf['total'];
            $new_psqm  = $inf['per_sqm'] !== null ? (int)$inf['per_sqm'] : null;
            $flag = $inf['confidence'] === 'verify' ? 1 : 0;
            $total_changed = ($new_total !== $cur_idr);
            $psqm_changed  = ($new_psqm !== $cur_psqm);
            $label_changed = ($label !== 'Total');
            if ($total_changed || $psqm_changed || $label_changed || (int)$r['price_review_flag'] !== $flag) {
                $price_changes[] = $row + array('changed_total' => $total_changed);
                if ($apply) {
                    $db->prepare("UPDATE listings SET price_idr=?, price_idr_per_sqm=?, price_label='Total', price_review_flag=?, updated_at=NOW() WHERE id=?")
                       ->execute(array($new_total, $new_psqm, $flag, $id));
                    if ($flag) lc_queue_review($db, $id, 'price_verify', array(
                        'stored_idr' => $cur_idr, 'new_total' => $new_total,
                        'land_size_sqm' => $sqm, 'per_sqm' => $new_psqm, 'reason' => $inf['note']));
                }
            }
        }
    }

    // ── AREA ────────────────────────────────────────────────────────
    $resolved = lc_resolve_area_key($db, array($r['location_detail'], $r['title'], $r['description']));
    if ($resolved && $resolved !== $r['area_key']) {
        $is_default = ($r['area_key'] === null || $r['area_key'] === '' || $r['area_key'] === 'praya');
        if ($is_default) {
            $area_fixes[] = array('id' => $id, 'title' => $r['title'], 'old' => $r['area_key'], 'new' => $resolved, 'loc' => $r['location_detail']);
            if ($apply) {
                $db->prepare("UPDATE listings SET area_key=?, updated_at=NOW() WHERE id=?")->execute(array($resolved, $id));
            }
        } else {
            $area_flips[] = array('id' => $id, 'title' => $r['title'], 'old' => $r['area_key'], 'new' => $resolved, 'loc' => $r['location_detail']);
            if ($apply) {
                lc_queue_review($db, $id, 'area_flip', array('old_area_key' => $r['area_key'], 'new_area_key' => $resolved, 'location_detail' => $r['location_detail']));
            }
        }
    }
}

// ─── report ──────────────────────────────────────────────────────────
header('Content-Type: text/html; charset=utf-8');
$esc = function($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };
$fmt = function($n) { return $n === null ? '—' : 'Rp ' . number_format((int)$n, 0, ',', '.'); };
?>
<!doctype html><meta charset="utf-8"><title>Re-canonicalise listings</title>
<style>
 body{font-family:system-ui,sans-serif;margin:24px;color:#1a1a1a}
 h1{font-size:20px} h2{font-size:16px;margin-top:28px}
 table{border-collapse:collapse;width:100%;font-size:13px;margin-top:8px}
 th,td{border:1px solid #ddd;padding:6px 8px;text-align:left} th{background:#f5f5f5}
 .banner{padding:12px 16px;border-radius:8px;margin:12px 0}
 .dry{background:#fff7e6;border:1px solid #ffd591}
 .applied{background:#e6ffed;border:1px solid #95de64}
 .btn{display:inline-block;padding:10px 18px;background:#1677ff;color:#fff;border-radius:8px;text-decoration:none;font-weight:600}
 .old{color:#c00} .new{color:#093;font-weight:600}
 a{color:#1677ff}
</style>
<h1>Re-canonicalise listings <a href="?logout=1" style="font-size:12px;float:right">log out</a></h1>

<?php if ($apply): ?>
  <div class="banner applied"><strong>APPLIED.</strong> Changes written to the database.
  Review flags and conflicts are in the <a href="ingest_console.php">ingest console</a>.</div>
<?php else: ?>
  <div class="banner dry"><strong>DRY RUN.</strong> Nothing has been changed. Showing what
  <em>would</em> change. &nbsp; <a class="btn" href="?apply=1" onclick="return confirm('Apply all changes below?')">Apply changes</a></div>
<?php endif; ?>

<?php
 $are_fmt = function($a){ return $a === null ? '—' : number_format($a, 2, ',', '.') . ' are'; };
 $sqm_fmt = function($s){ return $s === null ? '—' : number_format((int)$s, 0, ',', '.') . ' m²'; };
 $conf_badge = function($c){
     $map = array('ok'=>array('#093','OK'),'verify'=>array('#b8860b','VERIFY'),'review'=>array('#c00','REVIEW'));
     $m = isset($map[$c]) ? $map[$c] : array('#666',strtoupper($c));
     return '<span style="font-weight:700;color:'.$m[0].'">'.$m[1].'</span>';
 };
?>
<p>
  Price changes (auto-applied): <strong><?= count($price_changes) ?></strong> &nbsp;·&nbsp;
  Price uncertain (→review, price kept): <strong><?= count($price_reviews) ?></strong> &nbsp;·&nbsp;
  Priced, no land size: <strong><?= count($price_nosize) ?></strong> &nbsp;·&nbsp;
  Area corrections: <strong><?= count($area_fixes) ?></strong> &nbsp;·&nbsp;
  Area conflicts (→review): <strong><?= count($area_flips) ?></strong>
</p>

<h2>Price re-evaluation (by land size, not by label)</h2>
<p style="font-size:12px;color:#666;margin:4px 0">
  Each number is read against a plausible Lombok band of
  Rp <?= number_format(LC_PERM2_MIN,0,',','.') ?>–<?= number_format(LC_PERM2_HARD_MAX,0,',','.') ?>/m²
  (soft ceiling Rp <?= number_format(LC_PERM2_SOFT_MAX,0,',','.') ?>/m²). A value that is already a
  sane total is kept as-is (no multiplication); only an implausibly cheap value is read as a unit price.
</p>
<table><tr>
  <th>ID</th><th>Title</th><th>Land</th><th>Stored price_idr</th><th>Read as</th>
  <th>New total</th><th>per m²</th><th>per are</th><th>Conf.</th><th>Note</th></tr>
<?php foreach ($price_changes as $f): ?>
 <tr>
  <td><?= $f['id'] ?></td><td><?= $esc(mb_substr($f['title'],0,42)) ?></td>
  <td><?= $sqm_fmt($f['sqm']) ?><br><span style="color:#888"><?= $are_fmt($f['are']) ?></span></td>
  <td class="<?= $f['changed_total'] ? 'old' : '' ?>"><?= $fmt($f['stored']) ?></td>
  <td><?= $esc($f['interp']) ?></td>
  <td class="new"><?= $fmt($f['total']) ?></td>
  <td><?= $fmt($f['per_sqm']) ?></td><td><?= $fmt($f['per_are']) ?></td>
  <td><?= $conf_badge($f['confidence']) ?></td><td style="font-size:11px"><?= $esc($f['note']) ?></td>
 </tr>
<?php endforeach; if (!$price_changes) echo '<tr><td colspan="10">None.</td></tr>'; ?>
</table>

<h2>Price uncertain → review queue (price NOT changed)</h2>
<p style="font-size:12px;color:#666;margin:4px 0">No reading is plausible (too expensive even as a total, or the land size looks wrong). Left untouched for a human.</p>
<table><tr><th>ID</th><th>Title</th><th>Land</th><th>Stored price_idr</th><th>Implied per m²</th><th>Note</th></tr>
<?php foreach (array_merge($price_reviews, $price_nosize) as $f): ?>
 <tr><td><?= $f['id'] ?></td><td><?= $esc(mb_substr($f['title'],0,42)) ?></td>
 <td><?= $sqm_fmt($f['sqm']) ?></td><td class="old"><?= $fmt($f['stored']) ?></td>
 <td><?= $fmt($f['per_sqm']) ?></td><td style="font-size:11px"><?= $esc($f['note']) ?></td></tr>
<?php endforeach; if (!$price_reviews && !$price_nosize) echo '<tr><td colspan="6">None.</td></tr>'; ?>
</table>

<h2>Area corrected (was default / blank)</h2>
<table><tr><th>ID</th><th>Title</th><th>Location text</th><th>Old</th><th>New</th></tr>
<?php foreach ($area_fixes as $f): ?>
 <tr><td><?= $f['id'] ?></td><td><?= $esc(mb_substr($f['title'],0,50)) ?></td><td><?= $esc($f['loc']) ?></td>
 <td class="old"><?= $esc($f['old'] ?: 'NULL') ?></td><td class="new"><?= $esc($f['new']) ?></td></tr>
<?php endforeach; if (!$area_fixes) echo '<tr><td colspan="5">None.</td></tr>'; ?>
</table>

<h2>Area conflicts → review queue (NOT auto-changed)</h2>
<table><tr><th>ID</th><th>Title</th><th>Location text</th><th>Current</th><th>Suggested</th></tr>
<?php foreach ($area_flips as $f): ?>
 <tr><td><?= $f['id'] ?></td><td><?= $esc(mb_substr($f['title'],0,50)) ?></td><td><?= $esc($f['loc']) ?></td>
 <td><?= $esc($f['old']) ?></td><td class="new"><?= $esc($f['new']) ?></td></tr>
<?php endforeach; if (!$area_flips) echo '<tr><td colspan="5">None.</td></tr>'; ?>
</table>
