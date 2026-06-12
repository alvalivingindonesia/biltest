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

$price_fixes = array();   // [id, old, new, note]
$price_flags = array();
$area_fixes  = array();
$area_flips  = array();

foreach ($rows as $r) {
    $id = (int)$r['id'];
    $sqm = (int)$r['land_size_sqm'];
    $sqm_ok = lc_trustworthy_size_sqm($sqm);
    $label = (string)$r['price_label'];

    // ── PRICE ───────────────────────────────────────────────────────
    if (in_array($label, array('Per Are', 'Per m²'), true) && $r['price_idr'] !== null && $r['price_idr_per_sqm'] === null) {
        // Old buggy row: price_idr holds the per-are/per-m² unit price.
        if ($sqm_ok) {
            $pc = lc_canonical_price((int)$r['price_idr'], $label, $sqm);
            if ($pc['price_idr'] !== null && (int)$pc['price_idr'] !== (int)$r['price_idr']) {
                $price_fixes[] = array('id' => $id, 'title' => $r['title'],
                    'old' => (int)$r['price_idr'], 'new' => (int)$pc['price_idr'],
                    'per_sqm' => $pc['price_idr_per_sqm'], 'label' => $label);
                if ($apply) {
                    $db->prepare("UPDATE listings SET price_idr=?, price_idr_per_sqm=?, price_review_flag=0, updated_at=NOW() WHERE id=?")
                       ->execute(array($pc['price_idr'], $pc['price_idr_per_sqm'], $id));
                }
            }
        } else {
            // Per-are with no size -> can't total. Price on Request + review.
            $price_flags[] = array('id' => $id, 'title' => $r['title'], 'old' => (int)$r['price_idr'], 'label' => $label);
            if ($apply) {
                $db->prepare("UPDATE listings SET price_idr=NULL, price_review_flag=1, updated_at=NOW() WHERE id=?")->execute(array($id));
                lc_queue_review($db, $id, 'per_are_no_size', array('was_price_idr' => (int)$r['price_idr'], 'label' => $label));
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

<p>
  Per-are price fixes: <strong><?= count($price_fixes) ?></strong> &nbsp;·&nbsp;
  Priced→Price-on-Request (no size): <strong><?= count($price_flags) ?></strong> &nbsp;·&nbsp;
  Area corrections (from default): <strong><?= count($area_fixes) ?></strong> &nbsp;·&nbsp;
  Area conflicts (→review): <strong><?= count($area_flips) ?></strong>
</p>

<h2>Per-are price corrections (× land size)</h2>
<table><tr><th>ID</th><th>Title</th><th>Label</th><th>Old (per-are)</th><th>New (total)</th><th>per m²</th></tr>
<?php foreach ($price_fixes as $f): ?>
 <tr><td><?= $f['id'] ?></td><td><?= $esc(mb_substr($f['title'],0,60)) ?></td><td><?= $esc($f['label']) ?></td>
 <td class="old"><?= $fmt($f['old']) ?></td><td class="new"><?= $fmt($f['new']) ?></td><td><?= $fmt($f['per_sqm']) ?></td></tr>
<?php endforeach; if (!$price_fixes) echo '<tr><td colspan="6">None.</td></tr>'; ?>
</table>

<h2>Per-are, no land size → Price on Request + flag</h2>
<table><tr><th>ID</th><th>Title</th><th>Was</th><th>Label</th></tr>
<?php foreach ($price_flags as $f): ?>
 <tr><td><?= $f['id'] ?></td><td><?= $esc(mb_substr($f['title'],0,60)) ?></td><td class="old"><?= $fmt($f['old']) ?></td><td><?= $esc($f['label']) ?></td></tr>
<?php endforeach; if (!$price_flags) echo '<tr><td colspan="4">None.</td></tr>'; ?>
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
