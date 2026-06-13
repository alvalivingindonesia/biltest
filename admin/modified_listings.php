<?php
/**
 * Build in Lombok — Modified Listings review & revert panel
 *
 * Lists listings the automated pipeline (Worker re-check / recanonicaliser)
 * changed, shows each field's old→new diff, and lets an admin:
 *   • EDIT any field (price, area, description, land size, beds/baths, …)
 *   • REVERT an individual automated change back to its old value
 * Editing or reverting a field LOCKS it (locked_fields) so the Worker won't
 * overwrite it again. A search box lets you pull up any listing to edit too.
 *
 * Place at: /admin/modified_listings.php  (linked from the ingest console)
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
if (isset($_GET['logout'])) { session_destroy(); header('Location: modified_listings.php'); exit; }
if (empty($_SESSION['admin_auth'])) {
    echo '<!doctype html><meta charset="utf-8"><title>Login</title>';
    echo '<form method="post" style="font-family:sans-serif;max-width:320px;margin:80px auto">';
    echo '<h2>Modified listings</h2>';
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

// Editable fields and how to coerce them on save.
$EDITABLE = array(
    'title'             => 'text',
    'listing_type_key'  => 'select_type',
    'area_key'          => 'select_area',
    'status'            => 'select_status',
    'price_idr'         => 'int',
    'land_size_sqm'     => 'int',
    'building_size_sqm' => 'int',
    'bedrooms'          => 'int',
    'bathrooms'         => 'int',
    'location_detail'   => 'text',
    'short_description' => 'text',
    'description'       => 'textarea',
);
$STATUSES = array('active','expired','sold','under_offer','draft');

function coerce_val($type, $raw) {
    $raw = (string)$raw;
    if ($type === 'int') {
        $d = preg_replace('/[^\d]/', '', $raw);
        return $d === '' ? null : (int)$d;
    }
    $t = trim($raw);
    return $t === '' ? null : $t;
}
// Keep land_size_are and price_idr_per_sqm consistent after an edit/revert.
function rederive(PDO $db, $id) {
    $r = $db->prepare("SELECT listing_type_key, land_size_sqm, price_idr FROM listings WHERE id = ?");
    $r->execute(array($id)); $row = $r->fetch();
    if (!$row) return;
    $sqm = (int)$row['land_size_sqm'];
    $are = $sqm > 0 ? round($sqm / 100, 2) : null;
    $psqm = ($row['listing_type_key'] === 'land' && $sqm > 0 && $sqm < 50000000 && $row['price_idr'])
          ? (int)round($row['price_idr'] / $sqm) : null;
    $db->prepare("UPDATE listings SET land_size_are = ?, price_idr_per_sqm = ? WHERE id = ?")
       ->execute(array($are, $psqm, $id));
}

// ─── POST actions (edit / revert) — PRG ──────────────────────────────
if (($_POST['do'] ?? '') === 'edit') {
    $id = (int)($_POST['listing_id'] ?? 0);
    if ($id > 0) {
        $cur = $db->prepare("SELECT * FROM listings WHERE id = ?"); $cur->execute(array($id)); $row = $cur->fetch();
        if ($row) {
            foreach ($EDITABLE as $f => $type) {
                if (!array_key_exists($f, $_POST)) continue;
                $new = coerce_val($type === 'int' ? 'int' : 'text', $_POST[$f]);
                $old = $row[$f];
                if ((string)$old === (string)$new) continue;
                $db->prepare("UPDATE listings SET `$f` = ?, updated_at = NOW() WHERE id = ?")->execute(array($new, $id));
                lc_record_revision($db, $id, $f, $old, $new, 'admin');
                lc_lock_field($db, $id, $f); // admin's value wins over future Worker runs
            }
            rederive($db, $id);
        }
    }
    header('Location: modified_listings.php?ok=edited&id=' . $id . '#l' . $id);
    exit;
}

if (($_POST['do'] ?? '') === 'revert') {
    $rid = (int)($_POST['revision_id'] ?? 0);
    $allow = array_keys($EDITABLE);
    $allow[] = 'price_label'; $allow[] = 'certificate_type_key';
    $rev = $db->prepare("SELECT * FROM listing_revisions WHERE id = ?"); $rev->execute(array($rid)); $r = $rev->fetch();
    if ($r && !$r['reverted'] && in_array($r['field'], $allow, true)) {
        $lid = (int)$r['listing_id']; $f = $r['field'];
        $cur = $db->prepare("SELECT `$f` AS v FROM listings WHERE id = ?"); $cur->execute(array($lid)); $now = $cur->fetchColumn();
        $db->prepare("UPDATE listings SET `$f` = ?, updated_at = NOW() WHERE id = ?")->execute(array($r['old_value'], $lid));
        $db->prepare("UPDATE listing_revisions SET reverted = 1 WHERE id = ?")->execute(array($rid));
        lc_record_revision($db, $lid, $f, $now, $r['old_value'], 'revert');
        lc_lock_field($db, $lid, $f);
        rederive($db, $lid);
        header('Location: modified_listings.php?ok=reverted&id=' . $lid . '#l' . $lid);
        exit;
    }
    header('Location: modified_listings.php?ok=noop');
    exit;
}

// ─── load data ───────────────────────────────────────────────────────
$q = trim($_GET['q'] ?? '');
$areas = $db->query("SELECT `key`, label FROM areas ORDER BY label")->fetchAll();
$types = array();
try { $types = $db->query("SELECT `key`, label FROM listing_types ORDER BY label")->fetchAll(); } catch (Exception $e) {}
if (!$types) foreach (array('land','villa','house','apartment','commercial','warehouse','long_term_rental') as $k) $types[] = array('key'=>$k,'label'=>ucfirst($k));

$has_revisions = true;
try { $db->query("SELECT 1 FROM listing_revisions LIMIT 1"); } catch (Exception $e) { $has_revisions = false; }

// Listing ids to show: search result, else the most-recently-changed listings.
$ids = array();
if ($q !== '') {
    if (ctype_digit($q)) { $ids[] = (int)$q; }
    else {
        $s = $db->prepare("SELECT id FROM listings WHERE title LIKE ? ORDER BY updated_at DESC LIMIT 50");
        $s->execute(array('%' . $q . '%'));
        $ids = array_map('intval', $s->fetchAll(PDO::FETCH_COLUMN));
    }
} elseif ($has_revisions) {
    $s = $db->query("SELECT listing_id FROM listing_revisions GROUP BY listing_id ORDER BY MAX(changed_at) DESC LIMIT 100");
    $ids = array_map('intval', $s->fetchAll(PDO::FETCH_COLUMN));
}
// Fallback: if no revision history yet, show what the Worker recently touched.
$fallback = (!$has_revisions || (empty($ids) && $q === ''));
if ($fallback) {
    $s = $db->query("SELECT id FROM listings WHERE last_rechecked_at IS NOT NULL ORDER BY last_rechecked_at DESC LIMIT 100");
    $ids = array_map('intval', $s->fetchAll(PDO::FETCH_COLUMN));
}

$listings = array();
if ($ids) {
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $st = $db->prepare("SELECT * FROM listings WHERE id IN ($ph)");
    $st->execute($ids);
    foreach ($st->fetchAll() as $row) $listings[(int)$row['id']] = $row;
}
// Preserve order of $ids.
$ordered = array();
foreach ($ids as $i) if (isset($listings[$i])) $ordered[] = $listings[$i];

// Revisions per listing.
$revs = array();
if ($has_revisions && $ordered) {
    $ph = implode(',', array_fill(0, count($ordered), '?'));
    $rid = array_map(function($l){ return (int)$l['id']; }, $ordered);
    $rs = $db->prepare("SELECT * FROM listing_revisions WHERE listing_id IN ($ph) ORDER BY changed_at DESC, id DESC");
    $rs->execute($rid);
    foreach ($rs->fetchAll() as $rv) $revs[(int)$rv['listing_id']][] = $rv;
}

// ─── render ──────────────────────────────────────────────────────────
header('Content-Type: text/html; charset=utf-8');
$esc = function($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };
$fmt = function($n){ return $n === null || $n === '' ? '—' : 'Rp ' . number_format((int)$n, 0, ',', '.'); };
$short = function($s,$n=60){ $s=(string)$s; return mb_strlen($s)>$n ? mb_substr($s,0,$n).'…' : $s; };
?>
<!doctype html><meta charset="utf-8"><title>Modified listings</title>
<style>
 body{font-family:system-ui,sans-serif;margin:24px;color:#1a1a1a;max-width:1100px}
 h1{font-size:20px} a{color:#1677ff}
 .banner{padding:10px 14px;border-radius:8px;margin:10px 0;background:#e6ffed;border:1px solid #95de64}
 .warn{background:#fff7e6;border:1px solid #ffd591}
 .card{border:1px solid #e3e3e3;border-radius:10px;padding:14px 16px;margin:14px 0}
 .card h3{margin:0 0 4px;font-size:15px}
 .meta{font-size:12px;color:#666;margin-bottom:8px}
 table.diff{border-collapse:collapse;width:100%;font-size:13px;margin:6px 0}
 .diff th,.diff td{border:1px solid #eee;padding:4px 8px;text-align:left;vertical-align:top}
 .diff th{background:#fafafa}
 .old{color:#c00} .new{color:#093;font-weight:600}
 .reverted{opacity:.5;text-decoration:line-through}
 form.edit{display:grid;grid-template-columns:repeat(2,1fr);gap:8px 14px;margin-top:10px;background:#f8fafb;padding:12px;border-radius:8px}
 form.edit label{font-size:11px;color:#555;display:block;margin-bottom:2px}
 form.edit input,form.edit select,form.edit textarea{width:100%;padding:6px;font:inherit;box-sizing:border-box}
 form.edit .full{grid-column:1/3}
 .btn{padding:7px 14px;border:0;border-radius:6px;background:#1677ff;color:#fff;font-weight:600;cursor:pointer}
 .btn-rev{padding:3px 9px;border:1px solid #d0d0d0;border-radius:5px;background:#fff;cursor:pointer;font-size:12px}
 .pill{display:inline-block;background:#eef;border-radius:8px;padding:0 7px;font-size:11px;color:#446}
 .src{font-size:11px;color:#888}
 tr:target,.card:target{outline:3px solid #ffd591}
</style>
<h1>Modified listings <a href="ingest_console.php" style="font-size:12px">↩ ingest console</a>
  <a href="?logout=1" style="font-size:12px;float:right">log out</a></h1>

<?php if (isset($_GET['ok'])): $m=array('edited'=>'Saved.','reverted'=>'Reverted.','noop'=>'Nothing to do.'); ?>
  <div class="banner"><?= $esc($m[$_GET['ok']] ?? 'Done.') ?></div>
<?php endif; ?>
<?php if (!$has_revisions): ?>
  <div class="banner warn"><strong>Change history not active yet.</strong> Run
  <code>migrations/2026_06_13_listing_revisions.sql</code> to start recording old→new changes
  (needed for per-change <em>revert</em>). Until then this lists Worker-touched listings and you can still edit them.</div>
<?php elseif ($fallback): ?>
  <div class="banner warn">No tracked changes yet — showing the most recently Worker-checked listings. New automated
  changes from now on will appear here with revert buttons.</div>
<?php endif; ?>

<form method="get" style="margin:12px 0">
  <input name="q" value="<?= $esc($q) ?>" placeholder="search any listing by id or title…" style="padding:7px;width:320px">
  <button class="btn">Search</button>
  <?php if ($q !== ''): ?><a href="modified_listings.php" style="margin-left:8px">clear</a><?php endif; ?>
  <span style="float:right;color:#666;font-size:12px"><?= count($ordered) ?> listing(s)</span>
</form>

<?php if (!$ordered): ?>
  <p>No listings to show.</p>
<?php endif; ?>

<?php foreach ($ordered as $l): $id = (int)$l['id']; $lrevs = $revs[$id] ?? array(); ?>
 <div class="card" id="l<?= $id ?>">
   <h3>#<?= $id ?> · <?= $esc($l['title']) ?></h3>
   <div class="meta">
     <span class="pill"><?= $esc($l['listing_type_key']) ?></span>
     <span class="pill"><?= $esc($l['status']) ?></span>
     area: <strong><?= $esc($l['area_key'] ?: '—') ?></strong> ·
     <?= $fmt($l['price_idr']) ?> ·
     land <?= $l['land_size_sqm'] ? number_format((int)$l['land_size_sqm']).' m²' : '—' ?> ·
     updated <?= $esc($l['updated_at']) ?>
     <?php if (!empty($l['source_url'])): ?> · <a href="<?= $esc($l['source_url']) ?>" target="_blank" rel="noopener">↗ source</a><?php endif; ?>
     <?php if (!empty($l['locked_fields'])): ?> · 🔒 locked: <?= $esc($l['locked_fields']) ?><?php endif; ?>
   </div>

   <?php if ($lrevs): ?>
   <table class="diff"><tr><th>Field</th><th>Old</th><th>New</th><th>By</th><th>When</th><th></th></tr>
     <?php foreach ($lrevs as $rv): $isRev = (int)$rv['reverted'] === 1; ?>
       <tr class="<?= $isRev ? 'reverted' : '' ?>">
         <td><strong><?= $esc($rv['field']) ?></strong></td>
         <td class="old"><?= $esc($short($rv['old_value'])) ?></td>
         <td class="new"><?= $esc($short($rv['new_value'])) ?></td>
         <td class="src"><?= $esc($rv['source']) ?></td>
         <td class="src"><?= $esc($rv['changed_at']) ?></td>
         <td><?php if (!$isRev && $rv['source'] !== 'revert'): ?>
           <form method="post" style="margin:0" onsubmit="return confirm('Revert <?= $esc($rv['field']) ?> to the old value?')">
             <input type="hidden" name="do" value="revert"><input type="hidden" name="revision_id" value="<?= (int)$rv['id'] ?>">
             <button class="btn-rev" title="set back to the old value">↶ revert</button>
           </form>
         <?php endif; ?></td>
       </tr>
     <?php endforeach; ?>
   </table>
   <?php endif; ?>

   <form method="post" class="edit">
     <input type="hidden" name="do" value="edit"><input type="hidden" name="listing_id" value="<?= $id ?>">
     <div><label>Title</label><input name="title" value="<?= $esc($l['title']) ?>"></div>
     <div><label>Price IDR (total)</label><input name="price_idr" value="<?= $l['price_idr']!==null?(int)$l['price_idr']:'' ?>"></div>
     <div><label>Area</label>
       <select name="area_key">
         <option value="">—</option>
         <?php foreach ($areas as $a): ?><option value="<?= $esc($a['key']) ?>" <?= $a['key']===$l['area_key']?'selected':'' ?>><?= $esc($a['label']) ?></option><?php endforeach; ?>
       </select></div>
     <div><label>Type</label>
       <select name="listing_type_key">
         <?php foreach ($types as $t): ?><option value="<?= $esc($t['key']) ?>" <?= $t['key']===$l['listing_type_key']?'selected':'' ?>><?= $esc($t['label']) ?></option><?php endforeach; ?>
       </select></div>
     <div><label>Land size (m²)</label><input name="land_size_sqm" value="<?= $l['land_size_sqm']!==null?(int)$l['land_size_sqm']:'' ?>"></div>
     <div><label>Building size (m²)</label><input name="building_size_sqm" value="<?= $l['building_size_sqm']!==null?(int)$l['building_size_sqm']:'' ?>"></div>
     <div><label>Bedrooms</label><input name="bedrooms" value="<?= $l['bedrooms']!==null?(int)$l['bedrooms']:'' ?>"></div>
     <div><label>Bathrooms</label><input name="bathrooms" value="<?= $l['bathrooms']!==null?(int)$l['bathrooms']:'' ?>"></div>
     <div><label>Status</label>
       <select name="status">
         <?php foreach ($STATUSES as $s): ?><option value="<?= $esc($s) ?>" <?= $s===$l['status']?'selected':'' ?>><?= $esc($s) ?></option><?php endforeach; ?>
       </select></div>
     <div><label>Location detail</label><input name="location_detail" value="<?= $esc($l['location_detail']) ?>"></div>
     <div class="full"><label>Short description</label><input name="short_description" value="<?= $esc($l['short_description']) ?>"></div>
     <div class="full"><label>Description</label><textarea name="description" rows="4"><?= $esc($l['description']) ?></textarea></div>
     <div class="full"><button class="btn" type="submit">Save changes (locks edited fields)</button></div>
   </form>
 </div>
<?php endforeach; ?>
