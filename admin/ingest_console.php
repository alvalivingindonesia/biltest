<?php
/**
 * Build in Lombok — Ingestion Console (docs/adr/0007, 0008)
 *
 * Operates the automated listing pipeline:
 *   • Review queue   — surprises, unmapped areas, ambiguous agents, per-are flags
 *   • Area aliases   — map kecamatan/desa text -> area_key (one-time, then auto)
 *   • Discovery      — the search URLs the Worker scans for new listings
 *   • Agents         — merge cross-portal duplicates, reclassify, reputation
 *   • Field locks    — protect a listing's hand-edited fields from the Worker
 *
 * Place at: /admin/ingest_console.php  (not linked; direct URL only)
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
if (isset($_GET['logout'])) { session_destroy(); header('Location: ingest_console.php'); exit; }
if (empty($_SESSION['admin_auth'])) {
    echo '<!doctype html><meta charset="utf-8"><form method="post" style="font-family:sans-serif;max-width:320px;margin:80px auto">';
    echo '<h2>Ingest console</h2>';
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
$flash = '';

// ─── POST actions ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['do'])) {
    $do = $_POST['do'];
    $ajax = !empty($_POST['ajax']);
    $ic_json = function($a) { header('Content-Type: application/json; charset=utf-8'); echo json_encode($a, JSON_UNESCAPED_UNICODE); exit; };
    try {
        if ($do === 'review_resolve') {
            $rid = (int)$_POST['review_id'];
            $row = $db->prepare("SELECT * FROM listing_review_queue WHERE id=?"); $row->execute(array($rid));
            $item = $row->fetch();
            if ($item) {
                $action = $_POST['resolution'] ?? 'dismiss';
                if ($action === 'apply') {
                    $detail = json_decode($item['detail'], true) ?: array();
                    if ($item['kind'] === 'unmapped_area' || $item['kind'] === 'area_flip') {
                        $area = trim($_POST['area_key'] ?? '');
                        if ($area !== '') {
                            // learn the alias from the first candidate, then set the listing
                            $cand = '';
                            if (!empty($detail['candidates'][0])) $cand = $detail['candidates'][0];
                            $norm = lc_normalize_area_text($cand);
                            if ($norm !== '') {
                                $db->prepare("INSERT IGNORE INTO area_aliases (alias_text, area_key) VALUES (?, ?)")->execute(array($norm, $area));
                            }
                            if ($item['listing_id']) {
                                $db->prepare("UPDATE listings SET area_key=?, updated_at=NOW() WHERE id=?")->execute(array($area, $item['listing_id']));
                            }
                        }
                    } elseif ($item['kind'] === 'price_surprise' && $item['listing_id']) {
                        if (isset($detail['new_price_idr'])) {
                            $db->prepare("UPDATE listings SET price_idr=?, price_review_flag=0, updated_at=NOW() WHERE id=?")
                               ->execute(array((int)$detail['new_price_idr'], $item['listing_id']));
                        }
                    }
                }
                $db->prepare("UPDATE listing_review_queue SET status=?, resolved_at=NOW() WHERE id=?")
                   ->execute(array($action === 'apply' ? 'resolved' : 'dismissed', $rid));
                $flash = "Review #$rid {$action}d.";
                if ($ajax) $ic_json(array('ok' => true, 'ids' => array($rid), 'action' => $action));
            }
            if ($ajax) $ic_json(array('ok' => false, 'msg' => 'not found'));
        }
        elseif ($do === 'bulk_review') {
            $ids = isset($_POST['ids']) && is_array($_POST['ids']) ? array_map('intval', $_POST['ids']) : array();
            $res = ($_POST['resolution'] ?? 'dismiss') === 'apply' ? 'apply' : 'dismiss';
            $n = 0;
            foreach ($ids as $rid) {
                if ($rid <= 0) continue;
                if ($res === 'apply') {
                    $row = $db->prepare("SELECT * FROM listing_review_queue WHERE id=?"); $row->execute(array($rid)); $item = $row->fetch();
                    if ($item && $item['listing_id']) {
                        $detail = json_decode($item['detail'], true) ?: array();
                        if (in_array($item['kind'], array('area_flip','unmapped_area')) && !empty($detail['new_area_key'])) {
                            if (!empty($detail['new_place_key'])) {
                                $db->prepare("UPDATE listings SET area_key=?, place_key=?, updated_at=NOW() WHERE id=?")->execute(array($detail['new_area_key'], $detail['new_place_key'], $item['listing_id']));
                            } else {
                                $db->prepare("UPDATE listings SET area_key=?, updated_at=NOW() WHERE id=?")->execute(array($detail['new_area_key'], $item['listing_id']));
                            }
                        } elseif ($item['kind'] === 'price_surprise' && isset($detail['new_price_idr'])) {
                            $db->prepare("UPDATE listings SET price_idr=?, price_review_flag=0, updated_at=NOW() WHERE id=?")->execute(array((int)$detail['new_price_idr'], $item['listing_id']));
                        }
                    }
                }
                $db->prepare("UPDATE listing_review_queue SET status=?, resolved_at=NOW() WHERE id=?")->execute(array($res === 'apply' ? 'resolved' : 'dismissed', $rid));
                $n++;
            }
            $flash = "$n review(s) " . ($res === 'apply' ? 'applied' : 'dismissed') . ".";
            if ($ajax) $ic_json(array('ok' => true, 'ids' => $ids, 'action' => $res, 'n' => $n));
        }
        elseif ($do === 'alias_add') {
            $norm = lc_normalize_area_text($_POST['alias_text'] ?? '');
            $ak = trim($_POST['area_key'] ?? '');
            if ($norm !== '' && $ak !== '') {
                $db->prepare("INSERT INTO area_aliases (alias_text, area_key) VALUES (?, ?) ON DUPLICATE KEY UPDATE area_key=VALUES(area_key)")->execute(array($norm, $ak));
                $flash = "Alias '$norm' → $ak saved.";
            }
        }
        elseif ($do === 'alias_delete') {
            $db->prepare("DELETE FROM area_aliases WHERE id=?")->execute(array((int)$_POST['alias_id']));
            $flash = "Alias deleted.";
        }
        elseif ($do === 'discovery_add') {
            $db->prepare("INSERT INTO discovery_sources (source_site, label, search_url, max_pages, is_active) VALUES (?,?,?,?,1) ON DUPLICATE KEY UPDATE label=VALUES(label), source_site=VALUES(source_site), max_pages=VALUES(max_pages)")
               ->execute(array(trim($_POST['source_site']), trim($_POST['label']), trim($_POST['search_url']), max(1,(int)$_POST['max_pages'])));
            $flash = "Discovery source saved.";
        }
        elseif ($do === 'discovery_toggle') {
            $db->prepare("UPDATE discovery_sources SET is_active = 1 - is_active WHERE id=?")->execute(array((int)$_POST['ds_id']));
        }
        elseif ($do === 'discovery_delete') {
            $db->prepare("DELETE FROM discovery_sources WHERE id=?")->execute(array((int)$_POST['ds_id']));
            $flash = "Discovery source deleted.";
        }
        elseif ($do === 'agent_merge') {
            $from = (int)$_POST['from_id']; $into = (int)$_POST['into_id'];
            if ($from && $into && $from !== $into) {
                $db->prepare("UPDATE agents SET merged_into_agent_id=?, is_active=0 WHERE id=?")->execute(array($into, $from));
                $db->prepare("UPDATE agent_sources SET agent_id=? WHERE agent_id=?")->execute(array($into, $from));
                $db->prepare("UPDATE listings SET agent_id=? WHERE agent_id=?")->execute(array($into, $from));
                $flash = "Agent #$from merged into #$into.";
            }
        }
        elseif ($do === 'agent_reclassify') {
            $kind = in_array($_POST['kind'] ?? '', array('agent','private_seller'), true) ? $_POST['kind'] : 'agent';
            $db->prepare("UPDATE agents SET agent_kind=? WHERE id=?")->execute(array($kind, (int)$_POST['agent_id']));
            $flash = "Agent reclassified as $kind.";
        }
        elseif ($do === 'listing_lock') {
            $lid = (int)$_POST['listing_id'];
            $fields = isset($_POST['lock']) && is_array($_POST['lock']) ? implode(',', array_map('trim', $_POST['lock'])) : '';
            $db->prepare("UPDATE listings SET locked_fields=? WHERE id=?")->execute(array($fields ?: null, $lid));
            $flash = "Locked fields for listing #$lid: " . ($fields ?: 'none');
        }
    } catch (Exception $e) { $flash = 'Error: ' . $e->getMessage(); }
}

$tab = $_GET['tab'] ?? 'review';
$areas = $db->query("SELECT `key`, label FROM areas ORDER BY label")->fetchAll();
function area_options($areas, $sel='') {
    $o = '<option value="">— area —</option>';
    foreach ($areas as $a) {
        $s = $a['key'] === $sel ? ' selected' : '';
        $o .= '<option value="'.esc($a['key']).'"'.$s.'>'.esc($a['label']).' ('.esc($a['key']).')</option>';
    }
    return $o;
}
$counts = array(
  'review' => (int)$db->query("SELECT COUNT(*) FROM listing_review_queue WHERE status='open'")->fetchColumn(),
);
?>
<!doctype html><meta charset="utf-8"><title>Ingest console</title>
<style>
 body{font-family:system-ui,sans-serif;margin:0;color:#1a1a1a;background:#fafafa}
 header{background:#001529;color:#fff;padding:14px 24px;display:flex;align-items:center;gap:20px}
 header a{color:#cbd5e1;text-decoration:none} header a.on{color:#fff;font-weight:700}
 main{padding:24px;max-width:1100px;margin:0 auto}
 h2{font-size:17px} table{border-collapse:collapse;width:100%;font-size:13px;background:#fff;margin:10px 0}
 th,td{border:1px solid #e5e5e5;padding:7px 9px;text-align:left;vertical-align:top} th{background:#f5f5f5}
 input,select{padding:6px;font:inherit} button{padding:6px 12px;font:inherit;cursor:pointer}
 .flash{background:#e6ffed;border:1px solid #95de64;padding:10px 14px;border-radius:6px;margin-bottom:14px}
 .pill{background:#1677ff;color:#fff;border-radius:10px;padding:1px 8px;font-size:11px}
 code{background:#f0f0f0;padding:1px 4px;border-radius:3px;font-size:12px}
 .muted{color:#888}
</style>
<header>
 <strong>Ingest console</strong>
 <a href="?tab=review" class="<?= $tab==='review'?'on':'' ?>">Review <span class="pill"><?= $counts['review'] ?></span></a>
 <a href="?tab=aliases" class="<?= $tab==='aliases'?'on':'' ?>">Area aliases</a>
 <a href="?tab=discovery" class="<?= $tab==='discovery'?'on':'' ?>">Discovery</a>
 <a href="?tab=agents" class="<?= $tab==='agents'?'on':'' ?>">Agents</a>
 <a href="?tab=locks" class="<?= $tab==='locks'?'on':'' ?>">Field locks</a>
 <a href="modified_listings.php">Modified listings ↗</a>
 <a href="recanonicalize_listings.php">Re-canonicalise ↗</a>
 <a href="?logout=1" style="margin-left:auto">log out</a>
</header>
<main>
<?php if ($flash): ?><div class="flash"><?= esc($flash) ?></div><?php endif; ?>

<?php if ($tab === 'review'): ?>
 <h2>Open review items</h2>
 <?php $items = $db->query(
     "SELECT q.*, l.source_url, l.title AS listing_title, l.area_key AS cur_area
        FROM listing_review_queue q LEFT JOIN listings l ON l.id = q.listing_id
       WHERE q.status='open' ORDER BY q.id DESC LIMIT 500")->fetchAll(); ?>
 <script>function selAll(m){document.querySelectorAll('.rchk').forEach(c=>c.checked=m.checked);}</script>
 <!-- bulk form (buttons here; checkboxes below reference it via form=) -->
 <form method="post" id="bulkform"><input type="hidden" name="do" value="bulk_review">
   <div style="position:sticky;top:0;background:#fff;padding:8px 0;border-bottom:2px solid #1677ff;z-index:5;display:flex;gap:8px;align-items:center">
     <label><input type="checkbox" onclick="selAll(this)"> select all</label>
     <button name="resolution" value="apply" style="background:#1677ff;color:#fff">✓ Apply selected</button>
     <button name="resolution" value="dismiss">✕ Dismiss selected</button>
     <span class="muted" id="openCount"><?= count($items) ?> open</span>
   </div>
 </form>
 <table><tr><th></th><th>#</th><th>Kind</th><th>Listing</th><th>Source</th><th>Detail</th><th>Resolve one</th></tr>
 <?php foreach ($items as $it): $d = json_decode($it['detail'], true) ?: array();
   $place = $d['new_place_key'] ?? ''; ?>
  <tr<?= $place ? ' style="background:#f3fbf4"' : '' ?>>
   <td><input type="checkbox" class="rchk" form="bulkform" name="ids[]" value="<?= $it['id'] ?>"></td>
   <td><?= $it['id'] ?></td>
   <td><code><?= esc($it['kind']) ?></code><?php if ($place): ?><br><span style="font-size:11px;color:#0c7c84">place ✓</span><?php endif; ?></td>
   <td><?= $it['listing_id'] ? '#'.$it['listing_id'] : '<span class="muted">new</span>' ?>
       <?php if (!empty($it['listing_title'])): ?><br><span style="font-size:11px;color:#666"><?= esc(mb_strimwidth($it['listing_title'],0,40,'…')) ?></span><?php endif; ?></td>
   <td><?php if (!empty($it['source_url'])): ?><a href="<?= esc($it['source_url']) ?>" target="_blank" rel="noopener">↗ check</a><?php else: ?><span class="muted">—</span><?php endif; ?></td>
   <td style="max-width:340px;font-size:11px"><?= esc(json_encode($d, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)) ?></td>
   <td><form method="post" style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;margin:0">
     <input type="hidden" name="do" value="review_resolve"><input type="hidden" name="review_id" value="<?= $it['id'] ?>">
     <?php if (in_array($it['kind'], array('unmapped_area','area_flip'))): ?>
       <select name="area_key"><?= area_options($areas, $d['new_area_key'] ?? '') ?></select>
     <?php endif; ?>
     <button name="resolution" value="apply">apply</button>
     <button name="resolution" value="dismiss">dismiss</button>
   </form></td>
  </tr>
 <?php endforeach; if (!$items) echo '<tr><td colspan="7" class="muted">Queue is empty. 🎉</td></tr>'; ?>
 </table>
 <p class="muted" style="margin-top:8px">Rows tinted green resolved to a specific <strong>Place</strong> (high confidence). Bare area guesses (no place) on thin descriptions are the unreliable ones — spot-check via ↗, or dismiss and let the corrected re-extract redo them.</p>
 <script>
 (function(){
   document.addEventListener('submit', async function(e){
     var f = e.target, d = f.querySelector('input[name="do"]');
     if (!d || (d.value !== 'review_resolve' && d.value !== 'bulk_review')) return;
     e.preventDefault();
     var fd = new FormData(f); fd.append('ajax','1');
     if (e.submitter && e.submitter.name) fd.append(e.submitter.name, e.submitter.value);
     var btn = e.submitter; if (btn) btn.disabled = true;
     try {
       var res = await fetch('ingest_console.php?tab=review', { method:'POST', body: fd });
       var j = await res.json();
       if (j.ok) {
         (j.ids||[]).forEach(function(id){
           var cb = document.querySelector('.rchk[value="'+id+'"]');
           if (cb && cb.closest('tr')) cb.closest('tr').remove();
         });
         toast((j.action==='apply'?'Applied ':'Dismissed ') + ((j.ids||[]).length) + ' ✓');
         var n = document.querySelectorAll('.rchk').length, el = document.getElementById('openCount');
         if (el) el.textContent = n + ' open';
       } else { toast('Nothing to do'); }
     } catch(err){ toast('Error'); }
     if (btn) btn.disabled = false;
   });
   function toast(m){ var n=document.createElement('div'); n.textContent=m;
     n.style.cssText='position:fixed;right:18px;bottom:18px;background:#093;color:#fff;padding:8px 14px;border-radius:8px;font:13px system-ui;z-index:9';
     document.body.appendChild(n); setTimeout(function(){n.remove();},1700); }
 })();
 </script>

<?php elseif ($tab === 'aliases'): ?>
 <h2>Add area alias</h2>
 <form method="post" style="display:flex;gap:8px;align-items:center;margin-bottom:14px">
  <input type="hidden" name="do" value="alias_add">
  <input name="alias_text" placeholder="kecamatan / desa text" size="28" required>
  <span>→</span><select name="area_key" required><?= area_options($areas) ?></select>
  <button>Add alias</button>
 </form>
 <?php $al = $db->query("SELECT * FROM area_aliases ORDER BY area_key, alias_text")->fetchAll(); ?>
 <table><tr><th>alias_text (normalised)</th><th>area_key</th><th></th></tr>
 <?php foreach ($al as $a): ?>
  <tr><td><?= esc($a['alias_text']) ?></td><td><code><?= esc($a['area_key']) ?></code></td>
   <td><form method="post" onsubmit="return confirm('Delete?')"><input type="hidden" name="do" value="alias_delete"><input type="hidden" name="alias_id" value="<?= $a['id'] ?>"><button>✕</button></form></td></tr>
 <?php endforeach; ?>
 </table>

<?php elseif ($tab === 'discovery'): ?>
 <h2>Add / update discovery source</h2>
 <form method="post" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:14px">
  <input type="hidden" name="do" value="discovery_add">
  <select name="source_site"><option>lamudi</option><option>rumah123</option><option>dotproperty</option></select>
  <input name="label" placeholder="label" size="22" required>
  <input name="search_url" placeholder="https://… search URL" size="44" required>
  <input name="max_pages" type="number" value="3" min="1" max="20" style="width:64px" title="max pages">
  <button>Save</button>
 </form>
 <p class="muted">OLX is intentionally excluded — it owns Lamudi, so scanning it would re-discover Lamudi stock as duplicates.</p>
 <?php $ds = $db->query("SELECT * FROM discovery_sources ORDER BY source_site, id")->fetchAll(); ?>
 <table><tr><th>Site</th><th>Label</th><th>URL</th><th>Pages</th><th>Active</th><th>Last run</th><th></th></tr>
 <?php foreach ($ds as $s): ?>
  <tr><td><?= esc($s['source_site']) ?></td><td><?= esc($s['label']) ?></td>
   <td style="max-width:340px;word-break:break-all"><a href="<?= esc($s['search_url']) ?>" target="_blank"><?= esc($s['search_url']) ?></a></td>
   <td><?= (int)$s['max_pages'] ?></td>
   <td><form method="post"><input type="hidden" name="do" value="discovery_toggle"><input type="hidden" name="ds_id" value="<?= $s['id'] ?>"><button><?= $s['is_active'] ? '✓ on' : '✗ off' ?></button></form></td>
   <td class="muted"><?= esc($s['last_run_at'] ?: '—') ?></td>
   <td><form method="post" onsubmit="return confirm('Delete?')"><input type="hidden" name="do" value="discovery_delete"><input type="hidden" name="ds_id" value="<?= $s['id'] ?>"><button>✕</button></form></td></tr>
 <?php endforeach; ?>
 </table>

<?php elseif ($tab === 'agents'): ?>
 <h2>Agents</h2>
 <?php $q = trim($_GET['q'] ?? ''); ?>
 <form method="get" style="margin-bottom:12px"><input type="hidden" name="tab" value="agents">
  <input name="q" value="<?= esc($q) ?>" placeholder="search name / phone" size="28"><button>Search</button></form>
 <?php
   if ($q !== '') {
     $like = '%'.$q.'%'; $pd = lc_normalize_phone($q);
     $st = $db->prepare("SELECT a.*, (SELECT COUNT(*) FROM listings l WHERE l.agent_id=a.id) AS lc
                         FROM agents a
                         LEFT JOIN agent_sources s ON s.agent_id=a.id
                         WHERE a.display_name LIKE ? OR a.phone LIKE ? OR s.phone_digits = ?
                         GROUP BY a.id ORDER BY a.reputation_score DESC LIMIT 50");
     $st->execute(array($like, $like, $pd));
   } else {
     $st = $db->query("SELECT a.*, (SELECT COUNT(*) FROM listings l WHERE l.agent_id=a.id) AS lc
                       FROM agents a WHERE a.merged_into_agent_id IS NULL AND a.is_active=1
                       ORDER BY a.reputation_score DESC LIMIT 50");
   }
   $ags = $st->fetchAll();
 ?>
 <table><tr><th>#</th><th>Name</th><th>Kind</th><th>Tier</th><th>Score</th><th>Listings</th><th>Phone</th><th>Reclassify</th></tr>
 <?php foreach ($ags as $a): ?>
  <tr<?= $a['merged_into_agent_id'] ? ' class="muted"' : '' ?>>
   <td><?= $a['id'] ?></td>
   <td><?= esc($a['display_name']) ?><?= $a['merged_into_agent_id'] ? ' → #'.$a['merged_into_agent_id'] : '' ?></td>
   <td><code><?= esc($a['agent_kind']) ?></code></td>
   <td><?= esc($a['reputation_tier']) ?></td><td><?= (int)$a['reputation_score'] ?></td>
   <td><?= (int)$a['lc'] ?></td><td><?= esc($a['phone']) ?></td>
   <td><form method="post" style="display:flex;gap:4px"><input type="hidden" name="do" value="agent_reclassify"><input type="hidden" name="agent_id" value="<?= $a['id'] ?>">
    <select name="kind"><option value="agent"<?= $a['agent_kind']==='agent'?' selected':'' ?>>agent</option><option value="private_seller"<?= $a['agent_kind']==='private_seller'?' selected':'' ?>>private_seller</option></select>
    <button>Set</button></form></td>
  </tr>
 <?php endforeach; ?>
 </table>
 <h2>Merge duplicate agents</h2>
 <form method="post" style="display:flex;gap:8px;align-items:center">
  <input type="hidden" name="do" value="agent_merge">
  Merge agent #<input name="from_id" type="number" style="width:90px" required> into #<input name="into_id" type="number" style="width:90px" required>
  <button onclick="return confirm('Merge — moves all sources + listings. Continue?')">Merge</button>
 </form>
 <p class="muted">The "from" agent is hidden and points to the "into" agent; their sources, listings and reputation roll up.</p>

<?php elseif ($tab === 'locks'): ?>
 <h2>Field locks for a listing</h2>
 <?php
   $lid = (int)($_GET['lid'] ?? 0);
   $listing = null;
   if ($lid) { $s=$db->prepare("SELECT id,title,locked_fields FROM listings WHERE id=?"); $s->execute(array($lid)); $listing=$s->fetch(); }
   $lockable = array('price_idr','area_key','land_size_sqm','land_size_are','title','short_description','description','building_size_sqm','bedrooms','bathrooms','certificate_type_key','listing_type_key','location_detail','photo_urls','agent_id');
 ?>
 <form method="get" style="margin-bottom:12px"><input type="hidden" name="tab" value="locks">
  Listing ID <input name="lid" type="number" value="<?= $lid ?: '' ?>" style="width:100px"><button>Load</button></form>
 <?php if ($listing): $locked = lc_locked_set($listing['locked_fields']); ?>
  <p><strong>#<?= $listing['id'] ?></strong> — <?= esc($listing['title']) ?></p>
  <form method="post">
   <input type="hidden" name="do" value="listing_lock"><input type="hidden" name="listing_id" value="<?= $listing['id'] ?>">
   <div style="columns:3;max-width:600px">
   <?php foreach ($lockable as $f): ?>
    <label style="display:block"><input type="checkbox" name="lock[]" value="<?= esc($f) ?>"<?= in_array($f,$locked,true)?' checked':'' ?>> <?= esc($f) ?></label>
   <?php endforeach; ?>
   </div>
   <p><button>Save locks</button> <span class="muted">Checked fields are never overwritten by the Worker.</span></p>
  </form>
 <?php elseif ($lid): ?><p class="muted">No listing #<?= $lid ?>.</p><?php endif; ?>
<?php endif; ?>
</main>
