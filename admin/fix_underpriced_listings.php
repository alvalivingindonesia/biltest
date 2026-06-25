<?php
/**
 * Build in Lombok — One-time corrector: listing TYPE + sub-$10k PRICE
 *
 * Fixes two recurring data problems on existing rows, with a dry-run preview and
 * explicit apply (nothing is written until you click). Both reuse the same shared
 * rules the live ingester now uses, so this desk and the pipeline agree.
 *
 *   1. TYPE — listings tagged villa/house with NO building size and NO bedrooms
 *      are vacant land mislabelled from the word "villa" in the text. They become
 *      'land' (lc_listing_type: explicit category + building evidence, not prose).
 *
 *   2. PRICE — a real property total is never below ~USD $10,000. A sub-$10k total
 *      is almost certainly a per-are price (or, under $100, per-m²) stored as the
 *      total; rescale it by the land size (lc_enforce_min_price_floor). When a row
 *      becomes 'land' here, its price is re-evaluated as land.
 *
 * Locked fields are never touched. Every change is written to listing_revisions
 * (source = 'admin_fix') so it shows in Modified Listings and can be reverted.
 *
 * Place at: /admin/fix_underpriced_listings.php  (not linked; direct URL only)
 */

require_once(__DIR__ . '/../api/_sec.php');
require_once('/home/rovin629/config/biltest_config.php');
require_once(__DIR__ . '/../api/listing_canonical.php');
sec_session_start('Strict');

// ─── auth ────────────────────────────────────────────────────────────
$auth_error = '';
if (isset($_POST['login'])) {
    if (!sec_rate_ok('admin_login', sec_client_ip(), 12, 900)) {
        $auth_error = 'Too many attempts. Please wait a few minutes and try again.';
    } elseif (sec_admin_user_ok($_POST['username'] ?? '') && sec_admin_password_ok($_POST['password'] ?? '')) {
        sec_session_regenerate();
        $_SESSION['admin_auth'] = true;
    } else { $auth_error = 'Invalid credentials.'; }
}
if (isset($_GET['logout'])) { sec_session_destroy(); header('Location: fix_underpriced_listings.php'); exit; }
if (empty($_SESSION['admin_auth'])) {
    echo '<!doctype html><meta charset="utf-8"><title>Login</title>';
    echo '<form method="post" style="font-family:sans-serif;max-width:320px;margin:80px auto">';
    echo '<h2>Fix listing type / price</h2>';
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
function esc($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function rp($n){ return $n === null ? '—' : 'Rp ' . number_format((int)$n); }

/**
 * Compute the proposed type/price change for one listing row, applying the same
 * rules as the live ingester and honouring locked fields. Returns null when
 * nothing changes, else a struct describing the diff.
 */
function propose_fix($db, $row) {
    $locked   = lc_locked_set($row['locked_fields'] ?? '');
    $curType  = (string)$row['listing_type_key'];
    $newType  = lc_listing_type($curType, $row['title'], $row['building_size_sqm'], $row['bedrooms']);
    $typeLocked = in_array('listing_type_key', $locked, true);
    $typeChange = (!$typeLocked && $newType !== $curType) ? $newType : null;

    // Price is evaluated against the type we'd END UP with (villa→land re-gates).
    $effectiveType = $typeChange !== null ? $typeChange : $curType;
    $is_land  = ($effectiveType === 'land');
    $priceLocked = in_array('price_idr', $locked, true);
    $priceFix = null;
    if (!$priceLocked && $row['price_idr'] !== null && (int)$row['price_idr'] > 0) {
        $priceFix = lc_enforce_min_price_floor($db, $row['price_idr'], $row['land_size_sqm'], $is_land);
    }

    if ($typeChange === null && $priceFix === null) return null;
    return array(
        'id'        => (int)$row['id'],
        'title'     => $row['title'],
        'source_url'=> $row['source_url'],
        'land_sqm'  => $row['land_size_sqm'],
        'cur_type'  => $curType,
        'new_type'  => $typeChange,           // null = unchanged
        'cur_price' => $row['price_idr'] === null ? null : (int)$row['price_idr'],
        'price_fix' => $priceFix,             // null = unchanged; else floor struct
    );
}

/** Write one approved fix. Returns a short human summary of what changed. */
function apply_fix($db, $row) {
    $p = propose_fix($db, $row);
    if (!$p) return '';
    $changes = array();
    if ($p['new_type'] !== null) {
        lc_record_revision($db, $p['id'], 'listing_type_key', $p['cur_type'], $p['new_type'], 'admin_fix');
        $db->prepare("UPDATE listings SET listing_type_key = ?, updated_at = NOW() WHERE id = ?")
           ->execute(array($p['new_type'], $p['id']));
        $changes[] = "type {$p['cur_type']}→{$p['new_type']}";
    }
    if ($p['price_fix'] !== null) {
        $f = $p['price_fix'];
        lc_record_revision($db, $p['id'], 'price_idr', $p['cur_price'], $f['price_idr'], 'admin_fix');
        $db->prepare("UPDATE listings SET price_idr = ?, price_idr_per_sqm = ?, price_review_flag = ?, updated_at = NOW() WHERE id = ?")
           ->execute(array($f['price_idr'], $f['price_idr_per_sqm'], (int)$f['flagged'], $p['id']));
        $changes[] = $f['price_idr'] === null ? 'price→Price-on-Request' : ('price→' . rp($f['price_idr']) . ' (' . $f['basis'] . ')');
    }
    return implode(', ', $changes);
}

// Candidate rows: anything that could change either fix. Built-type rows (for the
// type fix) plus any priced active row (for the floor). Cap generous for one-off.
$SQL = "SELECT id, title, source_url, listing_type_key, price_idr, price_idr_per_sqm,
               land_size_sqm, building_size_sqm, bedrooms, locked_fields
          FROM listings
         WHERE status = 'active'
           AND (listing_type_key IN ('villa','house','apartment','commercial')
                OR price_idr IS NOT NULL)
         ORDER BY id ASC
         LIMIT 5000";

$flash = '';

// ─── apply (POST) ───────────────────────────────────────────────────
if (($_POST['do'] ?? '') === 'apply') {
    if (!sec_request_origin_ok()) { http_response_code(403); echo 'CSRF check failed.'; exit; }
    $all = !empty($_POST['apply_all']);
    $ids = isset($_POST['ids']) && is_array($_POST['ids']) ? array_map('intval', $_POST['ids']) : array();
    $idset = array_fill_keys($ids, true);
    $n = 0;
    $rows = $db->query($SQL)->fetchAll();
    foreach ($rows as $row) {
        if (!$all && empty($idset[(int)$row['id']])) continue;
        $summary = apply_fix($db, $row);
        if ($summary !== '') $n++;
    }
    $flash = "Applied $n fix(es).";
}

// ─── undo: restore listing types this tool changed (admin_fix) ───────
if (($_POST['do'] ?? '') === 'undo_type') {
    if (!sec_request_origin_ok()) { http_response_code(403); echo 'CSRF check failed.'; exit; }
    $revs = $db->query(
        "SELECT id, listing_id, old_value, new_value FROM listing_revisions
          WHERE field = 'listing_type_key' AND source = 'admin_fix'
          ORDER BY id DESC")->fetchAll();
    $seen = array(); $n = 0;
    $cur = $db->prepare("SELECT listing_type_key FROM listings WHERE id = ?");
    foreach ($revs as $rv) {
        $lid = (int)$rv['listing_id'];
        if (isset($seen[$lid])) continue;           // most-recent admin_fix per listing only
        $seen[$lid] = true;
        if ($rv['old_value'] === null || $rv['old_value'] === $rv['new_value']) continue;
        $cur->execute(array($lid));
        $cv = $cur->fetchColumn();
        if ($cv !== $rv['new_value']) continue;     // value changed since — don't clobber a later edit
        $db->prepare("UPDATE listings SET listing_type_key = ?, updated_at = NOW() WHERE id = ?")
           ->execute(array($rv['old_value'], $lid));
        lc_record_revision($db, $lid, 'listing_type_key', $rv['new_value'], $rv['old_value'], 'admin_undo');
        $n++;
    }
    $flash = "Reverted $n listing type(s) to their pre-fix value.";
}

// ─── preview ────────────────────────────────────────────────────────
$rows = $db->query($SQL)->fetchAll();
$cands = array();
$nType = 0; $nPrice = 0;
foreach ($rows as $row) {
    $p = propose_fix($db, $row);
    if (!$p) continue;
    $cands[] = $p;
    if ($p['new_type'] !== null) $nType++;
    if ($p['price_fix'] !== null) $nPrice++;
}
?>
<!doctype html><meta charset="utf-8"><title>Fix type / price</title>
<style>
 body{font-family:system-ui,sans-serif;margin:0;color:#1a1a1a;background:#fafafa}
 header{background:#001529;color:#fff;padding:14px 24px;display:flex;align-items:center;gap:20px}
 header a{color:#cbd5e1;text-decoration:none}
 main{padding:24px;max-width:1180px;margin:0 auto}
 h2{font-size:18px} table{border-collapse:collapse;width:100%;font-size:13px;background:#fff;margin:10px 0}
 th,td{border:1px solid #e5e5e5;padding:7px 9px;text-align:left;vertical-align:top} th{background:#f5f5f5}
 button{padding:7px 14px;font:inherit;cursor:pointer}
 .flash{background:#e6ffed;border:1px solid #95de64;padding:10px 14px;border-radius:6px;margin-bottom:14px}
 .muted{color:#888} code{background:#f0f0f0;padding:1px 4px;border-radius:3px;font-size:12px}
 .was{color:#c0392b} .now{color:#178a3a;font-weight:700}
 .pill{background:#1677ff;color:#fff;border-radius:10px;padding:1px 8px;font-size:11px}
 .bar{position:sticky;top:0;background:#fff;border-bottom:1px solid #e5e5e5;padding:10px 0;display:flex;gap:10px;align-items:center}
</style>
<header>
 <strong>Fix listing type &amp; sub-$10k price</strong>
 <a href="ingest_console.php">Ingest console ↗</a>
 <a href="modified_listings.php">Modified listings ↗</a>
 <a href="?logout=1" style="margin-left:auto">log out</a>
</header>
<main>
<?php if ($flash): ?><div class="flash"><?= esc($flash) ?></div><?php endif; ?>

<?php
  $undoable = 0;
  try { $undoable = (int)$db->query("SELECT COUNT(DISTINCT listing_id) FROM listing_revisions WHERE field='listing_type_key' AND source='admin_fix'")->fetchColumn(); } catch (Exception $e) {}
?>
<?php if ($undoable > 0): ?>
 <div style="background:#fff7e6;border:1px solid #ffd591;padding:10px 14px;border-radius:6px;margin-bottom:14px">
  This tool previously changed the <strong>type</strong> of <strong><?= $undoable ?></strong> listing(s).
  <form method="post" style="display:inline;margin-left:8px">
   <input type="hidden" name="do" value="undo_type">
   <button onclick="return confirm('Revert all <?= $undoable ?> type changes this tool made back to their original value?')">↶ Undo all type changes</button>
  </form>
 </div>
<?php endif; ?>

<p class="muted">Dry-run preview. <strong><?= count($cands) ?></strong> listing(s) would change —
 <span class="pill"><?= $nType ?></span> type, <span class="pill"><?= $nPrice ?></span> price.
 Nothing is written until you apply. Locked fields are skipped; every change is logged to Modified Listings (revertible).</p>

<?php if (!$cands): ?>
 <p>Nothing to fix. 🎉</p>
<?php else: ?>
<form method="post">
 <input type="hidden" name="do" value="apply">
 <div class="bar">
   <label><input type="checkbox" id="selall" onclick="document.querySelectorAll('.ck').forEach(c=>c.checked=this.checked)"> select all</label>
   <button type="submit">Apply selected</button>
   <button type="submit" name="apply_all" value="1" onclick="return confirm('Apply ALL <?= count($cands) ?> proposed fixes?')">Apply ALL</button>
 </div>
 <table>
  <tr><th></th><th>#</th><th>title</th><th>land</th><th>type</th><th>price</th><th>basis / note</th><th>verify</th></tr>
  <?php foreach ($cands as $c): $f = $c['price_fix']; ?>
   <tr>
    <td><input type="checkbox" class="ck" name="ids[]" value="<?= $c['id'] ?>"></td>
    <td><?= $c['id'] ?></td>
    <td><?= esc(mb_substr($c['title'] ?? '', 0, 56)) ?></td>
    <td><?= $c['land_sqm'] !== null ? number_format((int)$c['land_sqm']).' m²' : '<span class="muted">—</span>' ?></td>
    <td><?php if ($c['new_type'] !== null): ?><span class="was"><?= esc($c['cur_type']) ?></span> → <span class="now"><?= esc($c['new_type']) ?></span><?php else: ?><span class="muted"><?= esc($c['cur_type']) ?></span><?php endif; ?></td>
    <td><?php if ($f !== null): ?><span class="was"><?= rp($c['cur_price']) ?></span><br>→ <span class="now"><?= rp($f['price_idr']) ?></span><?php else: ?><span class="muted"><?= rp($c['cur_price']) ?></span><?php endif; ?></td>
    <td class="muted"><?= $f !== null ? esc($f['basis'].' — '.$f['note']) : '' ?></td>
    <td><?php if (!empty($c['source_url'])): ?><a href="<?= esc($c['source_url']) ?>" target="_blank" rel="noopener">↗</a><?php else: ?>—<?php endif; ?></td>
   </tr>
  <?php endforeach; ?>
 </table>
 <div class="bar">
   <button type="submit">Apply selected</button>
   <button type="submit" name="apply_all" value="1" onclick="return confirm('Apply ALL <?= count($cands) ?> proposed fixes?')">Apply ALL</button>
 </div>
</form>
<?php endif; ?>
</main>
