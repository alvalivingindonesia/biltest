<?php
/**
 * Build in Lombok — nightly Agent Reputation recompute (docs/adr/0008)
 *
 * Earned trust from listing volume + tenure (first seen) + current active
 * count. Deliberately independent of the manual is_verified / is_trusted
 * flags. Counts distinct listings EVER seen (expired/sold included — the
 * soft-delete rule preserves them) so reputation doesn't evaporate on expiry.
 *
 * Run via cPanel cron, nightly (after the Worker's run):
 *   /usr/local/bin/php /home/rovin629/public_html/api/cron_reputation.php
 * Web access needs ?token=<CRON_REPUTATION_TOKEN> from private config.
 */

require_once('/home/rovin629/config/biltest_config.php');

if (php_sapi_name() !== 'cli') {
    $token = isset($_GET['token']) ? $_GET['token'] : '';
    if (!defined('CRON_REPUTATION_TOKEN') || $token === '' || !hash_equals(CRON_REPUTATION_TOKEN, $token)) {
        http_response_code(403); echo "Forbidden\n"; exit;
    }
    header('Content-Type: text/plain; charset=utf-8');
}

// ── Tunable thresholds (tier boundaries) ──────────────────────────────
$TIER_ESTABLISHED = array('total' => 5,  'tenure_days' => 90);
$TIER_TOP         = array('total' => 20, 'tenure_days' => 365);

try {
    $db = new PDO('mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4', DB_USER, DB_PASS, array(
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ));
} catch (Exception $e) { echo "DB connection failed.\n"; exit; }

// Canonical, browsable agents only ('agent' kind, not merged, active).
$agents = $db->query(
    "SELECT id FROM agents
      WHERE agent_kind = 'agent' AND is_active = 1 AND merged_into_agent_id IS NULL"
)->fetchAll(PDO::FETCH_COLUMN);

$merged_stmt = $db->prepare("SELECT id FROM agents WHERE merged_into_agent_id = ?");
// Plain template (NOT prepared — it carries a %IDS% placeholder that is invalid
// SQL until expanded; preparing it would throw).
$agg_template =
    "SELECT COUNT(*) AS total,
            SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) AS active,
            MIN(COALESCE(first_seen_at, source_scraped_at, created_at)) AS first_seen
       FROM listings
      WHERE agent_id IN (%IDS%)";
$upd = $db->prepare(
    "UPDATE agents
        SET reputation_score = ?, reputation_tier = ?, listings_total = ?, listings_active = ?,
            first_seen_at = COALESCE(first_seen_at, ?), reputation_updated_at = NOW()
      WHERE id = ?"
);

$n = 0;
foreach ($agents as $aid) {
    $aid = (int)$aid;
    // canonical id-set = self + any rows merged into it
    $merged_stmt->execute(array($aid));
    $ids = array_map('intval', $merged_stmt->fetchAll(PDO::FETCH_COLUMN));
    $ids[] = $aid;
    $ph = implode(',', array_fill(0, count($ids), '?'));

    $sql = str_replace('%IDS%', $ph, $agg_template);
    $q = $db->prepare($sql);
    $q->execute($ids);
    $row = $q->fetch();

    $total  = (int)$row['total'];
    $active = (int)$row['active'];
    $first  = $row['first_seen'];
    $tenure_days = $first ? max(0, (int)floor((time() - strtotime($first)) / 86400)) : 0;

    // Score: volume + tenure, with active stock as a recency nudge.
    $score = $total * 10 + (int)floor($tenure_days / 30) * 5 + $active * 2;

    $tier = 'new';
    if ($total >= $TIER_TOP['total'] && $tenure_days >= $TIER_TOP['tenure_days']) {
        $tier = 'top';
    } elseif ($total >= $TIER_ESTABLISHED['total'] && $tenure_days >= $TIER_ESTABLISHED['tenure_days']) {
        $tier = 'established';
    }

    $upd->execute(array($score, $tier, $total, $active, $first, $aid));
    $n++;
}

echo "OK — reputation recomputed for {$n} agents.\n";
