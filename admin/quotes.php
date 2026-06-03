<?php
/**
 * Build in Lombok — Quote Engine Admin
 * Reconciliation queue (Price Point -> catalog via aliases, ADR 0003) and the
 * human-intervention queue (ambiguous routing / disputes / parse failures).
 * Access: /admin/quotes.php  (shares console.php's admin session)
 */
session_start();
require_once('/home/rovin629/config/biltest_config.php');
if (empty($_SESSION['admin_auth'])) { header('Location: console.php'); exit; }

function db() {
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO('mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4', DB_USER, DB_PASS, array(
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ));
    }
    return $pdo;
}
function esc($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function fmt_idr($v) { return $v === null ? '—' : 'Rp ' . number_format((float)$v, 0, ',', '.'); }

// Same normalisation the worker uses for alias lookups (api/quote_worker.php).
function normalize_label($s) {
    $s = mb_strtolower(trim((string)$s), 'UTF-8');
    $s = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $s);
    $s = preg_replace('/\s+/u', ' ', $s);
    return trim($s);
}

$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $db = db();
    $act = $_POST['action'] ?? '';
    try {
        if ($act === 'map_alias') {
            $raw  = trim($_POST['alias_label'] ?? '');
            $rid  = (int)($_POST['rab_material_id'] ?? 0);
            $uid  = ($_POST['unit_id'] ?? '') !== '' ? (int)$_POST['unit_id'] : null;
            $norm = normalize_label($raw);
            if ($norm === '' || !$rid) {
                $msg = 'Label and catalog material are required.';
            } else {
                // Create/refresh the reusable alias (ADR 0003).
                $db->prepare("INSERT INTO material_aliases (alias_text, rab_material_id, unit_id) VALUES (?,?,?)
                              ON DUPLICATE KEY UPDATE rab_material_id=VALUES(rab_material_id), unit_id=VALUES(unit_id)")
                   ->execute(array($norm, $rid, $uid));
                // Back-fill every unlinked Price Point whose label normalises to this.
                $rows = $db->query("SELECT id, vendor_item_label FROM historical_material_prices WHERE rab_material_id IS NULL")->fetchAll();
                $ids = array();
                foreach ($rows as $r) { if (normalize_label($r['vendor_item_label']) === $norm) $ids[] = (int)$r['id']; }
                $n = 0;
                if ($ids) {
                    $ph = implode(',', array_fill(0, count($ids), '?'));
                    $st = $db->prepare("UPDATE historical_material_prices SET rab_material_id=? WHERE id IN ($ph)");
                    $st->execute(array_merge(array($rid), $ids));
                    $n = $st->rowCount();
                }
                $msg = "Alias saved. Linked $n price point(s) into the index.";
            }
        } elseif ($act === 'resolve_chat') {
            $cid = (int)($_POST['chat_id'] ?? 0);
            if ($cid) {
                $db->prepare("UPDATE quote_vendor_chats
                                 SET admin_intervention=0, admin_intervention_reason=NULL, state='info_received'
                               WHERE id=?")->execute(array($cid));
                $msg = 'Chat marked resolved.';
            }
        }
    } catch (Exception $e) { $msg = 'Error: ' . $e->getMessage(); }
}

$db = db();
$tab = $_GET['tab'] ?? 'reconcile';

// Data for reconciliation: distinct unlinked labels.
$unlinked = $db->query(
    "SELECT vendor_item_label,
            COUNT(*) AS cnt, MAX(unit) AS unit_sample, MAX(currency) AS cur,
            MAX(unit_price) AS price_sample, MAX(quoted_at) AS last_seen
       FROM historical_material_prices
      WHERE rab_material_id IS NULL
      GROUP BY vendor_item_label
      ORDER BY cnt DESC, last_seen DESC
      LIMIT 300"
)->fetchAll();

// Catalog + units for the mapping dropdowns.
$materials = $db->query("SELECT id, name FROM rab_materials ORDER BY name LIMIT 2000")->fetchAll();
$units     = $db->query("SELECT id, code FROM rab_units ORDER BY code")->fetchAll();

// Intervention queue.
$interventions = $db->query(
    "SELECT c.id, c.request_id, c.admin_intervention_reason, c.state, c.stock_status,
            c.last_inbound_at, p.name AS provider_name,
            (SELECT body_raw FROM quote_messages m WHERE m.chat_id=c.id AND m.direction='inbound'
              ORDER BY m.id DESC LIMIT 1) AS last_inbound
       FROM quote_vendor_chats c
       JOIN providers p ON p.id=c.provider_id
      WHERE c.admin_intervention=1 OR c.state='needs_admin'
      ORDER BY c.last_inbound_at DESC, c.id DESC
      LIMIT 200"
)->fetchAll();

$counts = array(
    'unlinked' => (int)$db->query("SELECT COUNT(*) FROM historical_material_prices WHERE rab_material_id IS NULL")->fetchColumn(),
    'linked'   => (int)$db->query("SELECT COUNT(*) FROM historical_material_prices WHERE rab_material_id IS NOT NULL")->fetchColumn(),
    'attn'     => count($interventions),
);
?><!DOCTYPE html>
<html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Quote Engine — Admin</title>
<style>
  body{font-family:system-ui,Arial,sans-serif;margin:0;background:#f4f5f7;color:#222}
  header{background:#0c7c84;color:#fff;padding:14px 22px;display:flex;align-items:center;gap:18px}
  header h1{font-size:17px;margin:0}
  header a{color:#cdeff1;text-decoration:none;font-size:13px}
  .wrap{max-width:1100px;margin:18px auto;padding:0 16px}
  .tabs{display:flex;gap:8px;margin-bottom:14px}
  .tabs a{padding:8px 14px;border-radius:6px;background:#fff;border:1px solid #dcdfe3;text-decoration:none;color:#333;font-size:13px}
  .tabs a.on{background:#0c7c84;color:#fff;border-color:#0c7c84}
  .badge{display:inline-block;background:#e9412a;color:#fff;border-radius:10px;padding:0 7px;font-size:11px;margin-left:5px}
  table{width:100%;border-collapse:collapse;background:#fff;border:1px solid #e2e5e9;border-radius:8px;overflow:hidden}
  th,td{padding:9px 11px;text-align:left;font-size:13px;border-bottom:1px solid #eef0f2;vertical-align:top}
  th{background:#fafbfc;font-size:11px;text-transform:uppercase;letter-spacing:.04em;color:#667}
  .msg{background:#e6f6ee;border:1px solid #b6e2c9;color:#1b6b42;padding:10px 14px;border-radius:6px;margin-bottom:14px;font-size:13px}
  select,input[type=text]{padding:5px 7px;border:1px solid #cfd4d9;border-radius:5px;font-size:12px}
  button{background:#0c7c84;color:#fff;border:0;border-radius:5px;padding:6px 11px;font-size:12px;cursor:pointer}
  button.ghost{background:#eef0f2;color:#333}
  .lbl{font-weight:600}
  .meta{color:#778;font-size:12px}
  .raw{white-space:pre-wrap;background:#fafbfc;border:1px solid #eef0f2;border-radius:5px;padding:7px;font-size:12px;max-width:420px}
</style></head><body>
<header>
  <h1>Quote Engine — Admin</h1>
  <a href="console.php">← Main console</a>
  <span style="margin-left:auto;font-size:12px;color:#cdeff1">
    Index: <?= $counts['linked'] ?> linked · <?= $counts['unlinked'] ?> unlinked
  </span>
</header>
<div class="wrap">
  <?php if ($msg): ?><div class="msg"><?= esc($msg) ?></div><?php endif; ?>
  <div class="tabs">
    <a href="?tab=reconcile" class="<?= $tab==='reconcile'?'on':'' ?>">Reconciliation <span class="badge"><?= $counts['unlinked'] ?></span></a>
    <a href="?tab=interventions" class="<?= $tab==='interventions'?'on':'' ?>">Needs attention <span class="badge"><?= $counts['attn'] ?></span></a>
  </div>

  <?php if ($tab === 'reconcile'): ?>
  <p class="meta">Map each vendor's wording to a catalog material once — it creates a reusable alias and
     pulls every matching price point (now and future) into the RAB index. Unmapped prices stay visible to the
     user but are excluded from the global index.</p>
  <table>
    <tr><th>Vendor label</th><th>Seen</th><th>Sample price</th><th>Last</th><th>Map to catalog material</th></tr>
    <?php if (!$unlinked): ?>
      <tr><td colspan="5" class="meta">Nothing to reconcile. 🎉</td></tr>
    <?php endif; ?>
    <?php foreach ($unlinked as $u): ?>
    <tr>
      <td><span class="lbl"><?= esc($u['vendor_item_label']) ?></span></td>
      <td><?= (int)$u['cnt'] ?>×</td>
      <td><?= $u['cur']==='IDR'||!$u['cur'] ? fmt_idr($u['price_sample']) : esc($u['cur']).' '.esc($u['price_sample']) ?>
          <?= $u['unit_sample'] ? '<span class="meta">/ '.esc($u['unit_sample']).'</span>' : '' ?></td>
      <td class="meta"><?= esc(substr((string)$u['last_seen'],0,10)) ?></td>
      <td>
        <form method="post" style="display:flex;gap:6px;flex-wrap:wrap;align-items:center">
          <input type="hidden" name="action" value="map_alias">
          <input type="hidden" name="alias_label" value="<?= esc($u['vendor_item_label']) ?>">
          <select name="rab_material_id" required>
            <option value="">— material —</option>
            <?php foreach ($materials as $m): ?>
              <option value="<?= (int)$m['id'] ?>"><?= esc($m['name']) ?></option>
            <?php endforeach; ?>
          </select>
          <select name="unit_id">
            <option value="">unit?</option>
            <?php foreach ($units as $un): ?>
              <option value="<?= (int)$un['id'] ?>"><?= esc($un['code']) ?></option>
            <?php endforeach; ?>
          </select>
          <button type="submit">Map &amp; link</button>
        </form>
      </td>
    </tr>
    <?php endforeach; ?>
  </table>

  <?php else: ?>
  <p class="meta">Chats the system would not guess at: ambiguous inbound routing, disputes, or parse failures (Q9/Q10/Q12).</p>
  <table>
    <tr><th>Vendor</th><th>Reason</th><th>Last vendor message</th><th>When</th><th></th></tr>
    <?php if (!$interventions): ?>
      <tr><td colspan="5" class="meta">Queue is clear. 🎉</td></tr>
    <?php endif; ?>
    <?php foreach ($interventions as $c): ?>
    <tr>
      <td><span class="lbl"><?= esc($c['provider_name']) ?></span><br>
          <span class="meta">req #<?= (int)$c['request_id'] ?> · <?= esc($c['state']) ?> · stock: <?= esc($c['stock_status']) ?></span></td>
      <td><?= esc($c['admin_intervention_reason'] ?: '—') ?></td>
      <td><?php if ($c['last_inbound']): ?><div class="raw"><?= esc($c['last_inbound']) ?></div><?php else: ?><span class="meta">—</span><?php endif; ?></td>
      <td class="meta"><?= esc(substr((string)$c['last_inbound_at'],0,16)) ?></td>
      <td>
        <form method="post" onsubmit="return confirm('Mark this chat resolved?')">
          <input type="hidden" name="action" value="resolve_chat">
          <input type="hidden" name="chat_id" value="<?= (int)$c['id'] ?>">
          <button type="submit" class="ghost">Resolve</button>
        </form>
      </td>
    </tr>
    <?php endforeach; ?>
  </table>
  <?php endif; ?>
</div>
</body></html>
