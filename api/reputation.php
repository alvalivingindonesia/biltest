<?php
/**
 * Build in Lombok — shared Agent Reputation recompute (docs/adr/0008)
 *
 * Earned trust from listing volume + tenure (first seen) + current active
 * count. Independent of the manual is_verified / is_trusted flags. Counts
 * distinct listings EVER seen so reputation doesn't evaporate on expiry.
 *
 * Caller passes an open PDO. Used by:
 *   • api/cron_reputation.php          (cPanel cron / manual web)
 *   • api/listing_ingest.php           (worker-authed action, nightly)
 */

function bil_recompute_reputation(
    $db,
    $tier_established = array('total' => 5,  'tenure_days' => 90),
    $tier_top        = array('total' => 20, 'tenure_days' => 365)
) {
    // Canonical, browsable agents only ('agent' kind, not merged, active).
    $agents = $db->query(
        "SELECT id FROM agents
          WHERE agent_kind = 'agent' AND is_active = 1 AND merged_into_agent_id IS NULL"
    )->fetchAll(PDO::FETCH_COLUMN);

    $merged_stmt = $db->prepare("SELECT id FROM agents WHERE merged_into_agent_id = ?");
    // Plain template (NOT prepared — it carries a %IDS% placeholder that is
    // invalid SQL until expanded; preparing it would throw).
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

        $q = $db->prepare(str_replace('%IDS%', $ph, $agg_template));
        $q->execute($ids);
        $row = $q->fetch();

        $total  = (int)$row['total'];
        $active = (int)$row['active'];
        $first  = $row['first_seen'];
        $tenure_days = $first ? max(0, (int)floor((time() - strtotime($first)) / 86400)) : 0;

        // Score: volume + tenure, with active stock as a recency nudge.
        $score = $total * 10 + (int)floor($tenure_days / 30) * 5 + $active * 2;

        $tier = 'new';
        if ($total >= $tier_top['total'] && $tenure_days >= $tier_top['tenure_days']) {
            $tier = 'top';
        } elseif ($total >= $tier_established['total'] && $tenure_days >= $tier_established['tenure_days']) {
            $tier = 'established';
        }

        $upd->execute(array($score, $tier, $total, $active, $first, $aid));
        $n++;
    }
    return $n;
}
