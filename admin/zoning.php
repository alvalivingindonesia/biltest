<?php
/**
 * Build in Lombok — Zoning & Land Check admin (ADR 0013)
 * Concierge report queue + Land-Use Class coverage. Standalone admin tool
 * (mirrors admin/rab.php). Access: /admin/zoning.php
 */
require_once(__DIR__ . '/../api/_sec.php');
require_once('/home/rovin629/config/biltest_config.php');
sec_session_start('Strict');

// ─── AUTH ────────────────────────────────────────────────────────────
$auth_error = '';
if (isset($_POST['login'])) {
    $u = $_POST['username'] ?? '';
    $p = $_POST['password'] ?? '';
    if (!sec_rate_ok('admin_login', sec_client_ip(), 12, 900)) $auth_error = 'Too many attempts. Try again later.';
    elseif (sec_admin_user_ok($u) && sec_admin_password_ok($p)) { sec_session_regenerate(); $_SESSION['admin_auth'] = true; }
    else $auth_error = 'Invalid credentials.';
}
if (isset($_GET['logout'])) { sec_session_destroy(); header('Location: zoning.php'); exit; }

function get_db(): PDO {
    static $pdo = null;
    if ($pdo === null) $pdo = new PDO('mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4', DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES=>false]);
    return $pdo;
}
function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8'); }
function fmt_idr($v){ return 'Rp ' . number_format((float)$v, 0, ',', '.'); }

function show_login($error){
    ?><!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Zoning Admin — Login</title><style>
    body{font-family:system-ui,sans-serif;background:#141210;color:#d4d1ca;display:flex;min-height:100vh;align-items:center;justify-content:center;margin:0}
    form{background:#1f1c18;padding:32px;border:1px solid #34302a;border-radius:12px;width:300px}
    h1{font-size:1.2rem;margin:0 0 18px}input{width:100%;padding:10px;margin-bottom:10px;background:#141210;border:1px solid #34302a;color:#d4d1ca;border-radius:6px;box-sizing:border-box}
    button{width:100%;padding:10px;background:#6b8db0;border:none;border-radius:6px;color:#141210;font-weight:600;cursor:pointer}
    .err{color:#d4604a;font-size:.85rem;margin-bottom:10px}</style></head><body>
    <form method="POST"><h1>Zoning Admin</h1><?php if($error) echo '<p class="err">'.e($error).'</p>'; ?>
    <input name="username" placeholder="Username" autocomplete="username">
    <input name="password" type="password" placeholder="Password" autocomplete="current-password">
    <button name="login" value="1">Sign in</button></form></body></html><?php
}
if (empty($_SESSION['admin_auth'])) { show_login($auth_error); exit; }

$db = get_db();
$section = $_GET['s'] ?? 'reports';
$msg = isset($_GET['msg']) ? $_GET['msg'] : '';

// ─── POST actions (CSRF-protected) ───────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['login'])) {
    if (!sec_csrf_validate()) { http_response_code(403); exit('CSRF failed'); }
    $a = $_POST['a'] ?? '';
    if ($a === 'update_report') {
        $id = (int)($_POST['id'] ?? 0);
        $status = $_POST['status'] ?? 'requested';
        $allowed = ['requested','invoiced','paid','in_review','delivered','cancelled'];
        if (!in_array($status, $allowed, true)) $status = 'requested';
        $price = $_POST['price_idr'] !== '' ? (int)preg_replace('/\D/','',$_POST['price_idr']) : null;
        $invoice = mb_substr(trim($_POST['invoice_ref'] ?? ''), 0, 80);
        $notes = mb_substr(trim($_POST['admin_notes'] ?? ''), 0, 4000);
        $vnote = mb_substr(trim($_POST['verified_note'] ?? ''), 0, 4000);

        // Load current draft to build verified content if delivering.
        $st = $db->prepare("SELECT draft_json, verified_json FROM zoning_reports WHERE id=?");
        $st->execute([$id]); $cur = $st->fetch();
        $verified_json = $cur['verified_json'];
        $deliver_extra = '';
        if ($status === 'delivered') {
            $content = $cur['draft_json'] ? json_decode($cur['draft_json'], true) : array();
            if (!is_array($content)) $content = array();
            if ($vnote !== '') $content['verified_note'] = $vnote;
            $content['verified_at'] = date('c');
            $verified_json = json_encode($content, JSON_UNESCAPED_UNICODE);
            $deliver_extra = ', delivered_at = COALESCE(delivered_at, NOW()), verified_at = COALESCE(verified_at, NOW())';
        } elseif ($vnote !== '' && $cur['verified_json']) {
            $content = json_decode($cur['verified_json'], true); if(!is_array($content)) $content=array();
            $content['verified_note'] = $vnote; $verified_json = json_encode($content, JSON_UNESCAPED_UNICODE);
        }
        $sql = "UPDATE zoning_reports SET status=?, price_idr=?, invoice_ref=?, admin_notes=?, verified_json=?".$deliver_extra." WHERE id=?";
        $db->prepare($sql)->execute([$status, $price, $invoice, $notes, $verified_json, $id]);
        header('Location: zoning.php?s=reports&view='.$id.'&msg='.urlencode('Report #'.$id.' updated.')); exit;
    }
    header('Location: zoning.php'); exit;
}

$csrf = sec_csrf_token();

// ─── Layout header ───────────────────────────────────────────────────
?><!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Zoning &amp; Land Check — Admin</title><style>
body{font-family:system-ui,-apple-system,sans-serif;background:#f7f5f0;color:#1a1814;margin:0}
.top{background:#1f3a52;color:#fff;padding:14px 22px;display:flex;justify-content:space-between;align-items:center}
.top h1{font-size:1.05rem;margin:0;font-weight:600}.top a{color:#cdd9e6;text-decoration:none;margin-left:16px;font-size:.9rem}
.tabs{display:flex;gap:4px;padding:14px 22px 0;border-bottom:1px solid #d3cfc8;background:#faf8f4}
.tabs a{padding:9px 16px;text-decoration:none;color:#6b675e;border:1px solid transparent;border-bottom:none;border-radius:6px 6px 0 0;font-size:.9rem}
.tabs a.on{background:#fff;color:#1a1814;border-color:#d3cfc8;font-weight:600}
.wrap{max-width:1100px;margin:0 auto;padding:24px 22px 80px}
.msg{background:#d4dfcc;color:#2e5c10;padding:10px 14px;border-radius:6px;margin-bottom:16px;font-size:.9rem}
table{width:100%;border-collapse:collapse;background:#fff;border:1px solid #d3cfc8;border-radius:8px;overflow:hidden}
th,td{text-align:left;padding:10px 12px;border-bottom:1px solid #ece9e3;font-size:.88rem;vertical-align:top}
th{background:#f0ede6;text-transform:uppercase;letter-spacing:.06em;font-size:.7rem;color:#6b675e}
.badge{display:inline-block;padding:2px 9px;border-radius:99px;font-size:.72rem;font-weight:600}
.b-requested{background:#f0e5be;color:#b07a00}.b-invoiced{background:#dbe4ed;color:#1f3a52}.b-paid{background:#d0e8ea;color:#085560}
.b-in_review{background:#f3ebdb;color:#b8a079}.b-delivered{background:#d4dfcc;color:#2e5c10}.b-cancelled{background:#f5d5d2;color:#a52f22}
.pill{display:inline-block;padding:2px 8px;border-radius:99px;font-size:.7rem;font-weight:600}
.p-permitted{background:#d4dfcc;color:#2e5c10}.p-restricted{background:#f0e5be;color:#b07a00}.p-prohibited{background:#f5d5d2;color:#a52f22}.p-unknown{background:#e8e5de;color:#6b675e}
a.btn,button.btn{display:inline-block;padding:8px 14px;background:#1f3a52;color:#fff;border:none;border-radius:6px;text-decoration:none;font-size:.85rem;cursor:pointer}
a.lnk{color:#1f3a52}
.card{background:#fff;border:1px solid #d3cfc8;border-radius:8px;padding:20px;margin-bottom:16px}
.grid2{display:grid;grid-template-columns:1fr 1fr;gap:18px}
label{display:block;font-size:.78rem;text-transform:uppercase;letter-spacing:.05em;color:#6b675e;margin:10px 0 4px}
input,select,textarea{width:100%;padding:9px;border:1px solid #d3cfc8;border-radius:6px;box-sizing:border-box;font-size:.9rem;font-family:inherit}
.muted{color:#6b675e;font-size:.82rem}
</style></head><body>
<div class="top"><h1>Zoning &amp; Land Check — Admin</h1><div>
  <a href="zoning.php?s=reports">Reports</a><a href="zoning.php?s=coverage">Coverage</a>
  <a href="console.php">Main console</a><a href="zoning.php?logout=1">Logout</a></div></div>
<div class="tabs">
  <a href="zoning.php?s=reports" class="<?= $section==='reports'?'on':'' ?>">Report queue</a>
  <a href="zoning.php?s=coverage" class="<?= $section==='coverage'?'on':'' ?>">Coverage</a>
</div>
<div class="wrap">
<?php if ($msg) echo '<div class="msg">'.e($msg).'</div>'; ?>
<?php

function status_badge($s){ return '<span class="badge b-'.e($s).'">'.e(ucfirst(str_replace('_',' ',$s))).'</span>'; }
function build_pill($b){ return '<span class="pill p-'.e($b?:'unknown').'">'.e(ucfirst($b?:'unknown')).'</span>'; }

// ─── REPORT DETAIL ───────────────────────────────────────────────────
if ($section === 'reports' && isset($_GET['view'])) {
    $id = (int)$_GET['view'];
    $st = $db->prepare("SELECT r.*, p.lat, p.lng, p.label, p.nib, p.resolved_class_key, p.buildability FROM zoning_reports r JOIN zoning_plots p ON p.id=r.plot_id WHERE r.id=?");
    $st->execute([$id]); $r = $st->fetch();
    if (!$r) { echo '<p>Report not found. <a href="zoning.php?s=reports">Back</a></p></div></body></html>'; exit; }
    $draft = $r['draft_json'] ? json_decode($r['draft_json'], true) : array();
    $bld = isset($draft['buildability']) ? $draft['buildability'] : array();
    $ups = $db->prepare("SELECT * FROM zoning_cert_uploads WHERE report_id=? AND is_active=1 ORDER BY id");
    $ups->execute([$id]); $uploads = $ups->fetchAll();
    ?>
    <p><a class="lnk" href="zoning.php?s=reports">&larr; Back to queue</a></p>
    <h2>Report ZLC-<?= (int)$r['id'] ?> <?= status_badge($r['status']) ?></h2>
    <div class="grid2">
      <div class="card">
        <h3 style="margin-top:0">Plot</h3>
        <p class="muted">Coordinates</p><p><a class="lnk" target="_blank" href="https://www.google.com/maps?q=<?= e($r['lat']) ?>,<?= e($r['lng']) ?>"><?= e($r['lat']) ?>, <?= e($r['lng']) ?></a></p>
        <?php if($r['label']): ?><p class="muted">Label</p><p><?= e($r['label']) ?></p><?php endif; ?>
        <?php if($r['nib']): ?><p class="muted">NIB</p><p><?= e($r['nib']) ?></p><?php endif; ?>
        <p class="muted">Indicative zoning</p><p><?= build_pill($r['buildability']) ?> <?= e(isset($bld['name_en'])?$bld['name_en']:$r['resolved_class_key']) ?></p>
        <?php if(isset($draft['metrics_plain_en']) && $draft['metrics_plain_en']): ?><p class="muted">Limits</p><p><?= e($draft['metrics_plain_en']) ?></p><?php endif; ?>
      </div>
      <div class="card">
        <h3 style="margin-top:0">Requester</h3>
        <p class="muted">Name</p><p><?= e($r['contact_name'] ?: '—') ?></p>
        <p class="muted">Email</p><p><?= $r['contact_email'] ? '<a class="lnk" href="mailto:'.e($r['contact_email']).'">'.e($r['contact_email']).'</a>' : '—' ?></p>
        <p class="muted">WhatsApp</p><p><?= $r['contact_whatsapp'] ? '<a class="lnk" target="_blank" href="https://wa.me/'.e(preg_replace('/\D/','',$r['contact_whatsapp'])).'">'.e($r['contact_whatsapp']).'</a>' : '—' ?></p>
        <?php if($r['message']): ?><p class="muted">Message</p><p><?= e($r['message']) ?></p><?php endif; ?>
        <p class="muted">Owner link</p><p><a class="lnk" target="_blank" href="../index.html#zoning-report?id=<?= (int)$r['id'] ?>&token=<?= e($r['access_token']) ?>">Open report view</a></p>
      </div>
    </div>

    <?php if ($uploads): ?>
    <div class="card"><h3 style="margin-top:0">Certificate uploads (<?= count($uploads) ?>)</h3>
      <table><tr><th>File</th><th>Type</th><th>Size</th><th>Server path (cPanel File Manager)</th></tr>
      <?php foreach($uploads as $u): ?>
        <tr><td><?= e($u['original_name']) ?></td><td><?= e($u['mime']) ?></td><td><?= e(round($u['size_bytes']/1024)) ?> KB</td><td class="muted" style="word-break:break-all"><?= e($u['stored_path']) ?></td></tr>
      <?php endforeach; ?></table>
      <p class="muted">Files are stored outside the web root (personal data). Access via cPanel File Manager for the notary check; never linked publicly.</p>
    </div>
    <?php endif; ?>

    <div class="card">
      <h3 style="margin-top:0">Fulfil &amp; deliver</h3>
      <form method="POST" action="zoning.php">
        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
        <input type="hidden" name="a" value="update_report">
        <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
        <div class="grid2">
          <div><label>Status</label>
            <select name="status">
              <?php foreach(['requested','invoiced','paid','in_review','delivered','cancelled'] as $s): ?>
                <option value="<?= $s ?>" <?= $r['status']===$s?'selected':'' ?>><?= ucfirst(str_replace('_',' ',$s)) ?></option>
              <?php endforeach; ?>
            </select></div>
          <div><label>Price (IDR)</label><input name="price_idr" value="<?= e($r['price_idr']) ?>" placeholder="1500000"></div>
        </div>
        <label>Invoice reference</label><input name="invoice_ref" value="<?= e($r['invoice_ref']) ?>">
        <label>Verification note (shown on the delivered report)</label>
        <textarea name="verified_note" rows="3" placeholder="e.g. Confirmed against Perbup Lombok Tengah 105/2021 and a notary certificate check on 2026-..."><?= e(isset($draft['verified_note'])?$draft['verified_note']:'') ?></textarea>
        <label>Internal admin notes (private)</label>
        <textarea name="admin_notes" rows="3"><?= e($r['admin_notes']) ?></textarea>
        <p class="muted">Setting status to <b>Delivered</b> publishes the verified (Confirmed) report to the requester and stamps the delivery time.</p>
        <button class="btn" type="submit">Save</button>
      </form>
    </div>
    </div></body></html>
    <?php
    exit;
}

// ─── REPORT QUEUE ────────────────────────────────────────────────────
if ($section === 'reports') {
    $filter = $_GET['status'] ?? '';
    $where = ''; $params = [];
    if ($filter && in_array($filter, ['requested','invoiced','paid','in_review','delivered','cancelled'], true)) { $where = 'WHERE r.status=?'; $params[]=$filter; }
    $rows = $db->prepare("SELECT r.id, r.status, r.created_at, r.contact_name, r.contact_email, r.contact_whatsapp, r.price_idr, p.lat, p.lng, p.label, p.buildability FROM zoning_reports r JOIN zoning_plots p ON p.id=r.plot_id $where ORDER BY r.id DESC LIMIT 200");
    $rows->execute($params); $list = $rows->fetchAll();
    $counts = $db->query("SELECT status, COUNT(*) c FROM zoning_reports GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);
    ?>
    <p class="muted">Filter:
      <a class="lnk" href="zoning.php?s=reports">All</a> ·
      <?php foreach(['requested','invoiced','paid','in_review','delivered','cancelled'] as $s): ?>
        <a class="lnk" href="zoning.php?s=reports&status=<?= $s ?>"><?= ucfirst(str_replace('_',' ',$s)) ?> (<?= (int)($counts[$s]??0) ?>)</a><?= $s!=='cancelled'?' · ':'' ?>
      <?php endforeach; ?>
    </p>
    <?php if (!$list): ?><p class="muted">No report requests yet.</p><?php else: ?>
    <table>
      <tr><th>Ref</th><th>Status</th><th>Plot</th><th>Zoning</th><th>Requester</th><th>Price</th><th>Created</th><th></th></tr>
      <?php foreach($list as $r): ?>
      <tr>
        <td><b>ZLC-<?= (int)$r['id'] ?></b></td>
        <td><?= status_badge($r['status']) ?></td>
        <td class="muted"><?= e($r['label'] ?: ($r['lat'].', '.$r['lng'])) ?></td>
        <td><?= build_pill($r['buildability']) ?></td>
        <td class="muted"><?= e($r['contact_name'] ?: ($r['contact_email'] ?: $r['contact_whatsapp'] ?: '—')) ?></td>
        <td class="muted"><?= $r['price_idr']?e(fmt_idr($r['price_idr'])):'—' ?></td>
        <td class="muted"><?= e(substr($r['created_at'],0,16)) ?></td>
        <td><a class="btn" href="zoning.php?s=reports&view=<?= (int)$r['id'] ?>">Open</a></td>
      </tr>
      <?php endforeach; ?>
    </table>
    <?php endif;
}

// ─── COVERAGE ────────────────────────────────────────────────────────
if ($section === 'coverage') {
    $cov = $db->query("SELECT c.class_key, c.name_en, c.buildability, COUNT(p.id) polys, COALESCE(SUM(p.confidence='confirmed'),0) confirmed FROM zoning_landuse_classes c LEFT JOIN zoning_landuse_polys p ON p.class_key=c.class_key AND p.is_active=1 GROUP BY c.id ORDER BY c.sort_order")->fetchAll();
    $srcs = $db->query("SELECT source, COUNT(*) c, MAX(source_date) d FROM zoning_landuse_polys WHERE is_active=1 GROUP BY source ORDER BY c DESC")->fetchAll();
    ?>
    <div class="card"><h3 style="margin-top:0">Polygon coverage by Land-Use Class</h3>
    <table><tr><th>Class</th><th>Buildability</th><th>Polygons</th><th>Confirmed</th></tr>
    <?php foreach($cov as $c): ?>
      <tr><td><?= e($c['name_en']) ?> <span class="muted">(<?= e($c['class_key']) ?>)</span></td><td><?= build_pill($c['buildability']) ?></td><td><?= (int)$c['polys'] ?></td><td><?= (int)$c['confirmed'] ?></td></tr>
    <?php endforeach; ?></table></div>
    <div class="card"><h3 style="margin-top:0">By source</h3>
    <table><tr><th>Source</th><th>Polygons</th><th>Latest date</th></tr>
    <?php foreach($srcs as $s): ?><tr><td><?= e($s['source'] ?: '—') ?></td><td><?= (int)$s['c'] ?></td><td class="muted"><?= e($s['d']) ?></td></tr><?php endforeach; ?>
    <?php if(!$srcs): ?><tr><td colspan="3" class="muted">No polygons ingested yet.</td></tr><?php endif; ?></table>
    <p class="muted">Expand coverage with tools/zoning_ingest.mjs (see docs/zoning-ingest.md).</p></div>
    <?php
}
?>
</div></body></html>
