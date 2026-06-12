<?php
/**
 * Build in Lombok — Shared listing canonicalisation (docs/adr/0006, 0007, 0008)
 *
 * The single place the business rules live: per-are/per-m² -> total price,
 * location string -> area_key (no silent default), any currency -> canonical
 * price_idr, phone normalisation, cross-portal agent resolution, and the
 * review-queue helpers. Every ingest path (worker API, paste importer, admin
 * save) MUST route price + area + agent through here, or the per-are / wrong-
 * location / USD-only bugs come back.
 *
 * Pure-ish: callers pass an open PDO ($db). No direct config/require here.
 * PHP 7.4 compatible (no enums, named args, fibers).
 */

if (!defined('LC_PLATFORM_NAMES')) {
    // Portal names that masquerade as a "seller" — never a real Agent.
    define('LC_PLATFORM_NAMES', 'lamudi|rumah123|rumah 123|dotproperty|dot property|olx|olx indonesia');
}

// ─────────────────────────────────────────────────────────────────────
// TEXT / PHONE NORMALISATION
// ─────────────────────────────────────────────────────────────────────

function lc_make_slug($text) {
    $text = mb_strtolower(trim((string)$text), 'UTF-8');
    $text = preg_replace('/[^a-z0-9\s-]/', '', $text);
    $text = preg_replace('/[\s-]+/', '-', $text);
    $text = trim($text, '-');
    return $text === '' ? 'item' : substr($text, 0, 150);
}

function lc_normalize_area_text($s) {
    $s = mb_strtolower(trim((string)$s), 'UTF-8');
    // strip common admin prefixes so "Kecamatan Praya" == "praya"
    $s = preg_replace('/\b(kecamatan|kec\.?|kelurahan|desa|kabupaten|kab\.?|kota)\b/u', ' ', $s);
    $s = preg_replace('/[^a-z0-9\s]/u', ' ', $s);
    $s = preg_replace('/\s+/u', ' ', $s);
    return trim($s);
}

/**
 * Indonesian phone -> canonical digits. Leading 0 -> 62; strips non-digits.
 * Returns '' when there is nothing usable.
 */
function lc_normalize_phone($s) {
    $d = preg_replace('/\D+/', '', (string)$s);
    if ($d === '') return '';
    if (strpos($d, '0') === 0)      $d = '62' . substr($d, 1);
    elseif (strpos($d, '62') !== 0 && strlen($d) >= 8 && strlen($d) <= 12) $d = '62' . $d;
    return $d;
}

// ─────────────────────────────────────────────────────────────────────
// PRICE CANONICALISATION (the per-are bug fix — docs/adr/0006, 0007)
// ─────────────────────────────────────────────────────────────────────

/** Land size is trustworthy enough to derive a total from a unit price. */
function lc_trustworthy_size_sqm($sqm) {
    $sqm = (int)$sqm;
    return $sqm > 0 && $sqm < 50000000; // < 5,000 ha — anything above is a parse error
}

/**
 * Turn a raw price + its unit label + land size into a canonical TOTAL price_idr.
 *
 * $raw_amount   number as read from source (IDR)
 * $unit_label   free text: '/are', 'Per Are', '/m', 'Per m²', 'Total', '' ...
 * $land_size_sqm  may be null
 *
 * Returns: array(price_idr, price_idr_per_sqm, price_label, flagged)
 *   - per-are/per-m² are multiplied by size to get the TOTAL (the bug fix).
 *   - if a unit price has no trustworthy size, price_idr is NULL and flagged=1
 *     (better Price-on-Request than a wrong total that poisons filters).
 */
function lc_canonical_price($raw_amount, $unit_label, $land_size_sqm) {
    $amount = (int)round((float)$raw_amount);
    $out = array('price_idr' => null, 'price_idr_per_sqm' => null, 'price_label' => 'Total', 'flagged' => 0);
    if ($amount <= 0) return $out;

    $label = mb_strtolower((string)$unit_label, 'UTF-8');
    $is_per_are = (strpos($label, '/are') !== false) || (strpos($label, 'per are') !== false) || ($label === 'per are');
    $is_per_sqm = !$is_per_are && ((strpos($label, '/m') !== false) || (strpos($label, 'per m') !== false) || (strpos($label, 'm²') !== false));

    $sqm_ok = lc_trustworthy_size_sqm($land_size_sqm);
    $sqm = (int)$land_size_sqm;

    if ($is_per_are) {
        $out['price_label'] = 'Per Are';
        $out['price_idr_per_sqm'] = (int)round($amount / 100); // 1 are = 100 m²
        if ($sqm_ok) {
            $are = $sqm / 100.0;
            $out['price_idr'] = (int)round($amount * $are);    // <-- the fix: total = per_are × are
        } else {
            $out['flagged'] = 1;                                // no size -> Price on Request
        }
    } elseif ($is_per_sqm) {
        $out['price_label'] = 'Per m²';
        $out['price_idr_per_sqm'] = $amount;
        if ($sqm_ok) {
            $out['price_idr'] = (int)round($amount * $sqm);
        } else {
            $out['flagged'] = 1;
        }
    } else {
        $out['price_label'] = 'Total';
        $out['price_idr'] = $amount;
        if ($sqm_ok) $out['price_idr_per_sqm'] = (int)round($amount / $sqm);
    }

    return $out;
}

/**
 * Any-currency amount -> canonical IDR via currency_rates (docs/adr/0006).
 * Used when a source posts a non-IDR price. Returns null on no rate.
 */
function lc_to_idr($db, $amount, $currency) {
    $currency = strtoupper(trim((string)$currency));
    $amount = (float)$amount;
    if ($amount <= 0) return null;
    if ($currency === '' || $currency === 'IDR') return (int)round($amount);
    $st = $db->prepare("SELECT rate FROM currency_rates WHERE from_currency = ? AND to_currency = 'IDR' LIMIT 1");
    $st->execute(array($currency));
    $rate = $st->fetchColumn();
    if (!$rate || (float)$rate <= 0) return null;
    return (int)round($amount * (float)$rate);
}

/** A change big enough to be suspicious rather than a normal price move. */
function lc_is_price_surprise($old_idr, $new_idr) {
    $old = (int)$old_idr; $new = (int)$new_idr;
    if ($old <= 0 || $new <= 0) return false;
    $ratio = $new > $old ? $new / $old : $old / $new;
    return $ratio >= 5.0;
}

// ─────────────────────────────────────────────────────────────────────
// AREA RESOLUTION (the wrong-location fix — docs/adr/0007)
// ─────────────────────────────────────────────────────────────────────

/**
 * Resolve area_key from structured/loose location candidates via area_aliases.
 * Tries each candidate string (kecamatan, desa, district, title) longest-first.
 * Returns area_key string, or null when nothing maps — caller queues a review,
 * NEVER defaults to 'praya'.
 */
function lc_resolve_area_key($db, array $candidates) {
    $norms = array();
    foreach ($candidates as $c) {
        $n = lc_normalize_area_text($c);
        if ($n !== '') $norms[$n] = strlen($n);
    }
    if (empty($norms)) return null;
    arsort($norms); // try most specific (longest) first

    $exact = $db->prepare("SELECT area_key FROM area_aliases WHERE alias_text = ? LIMIT 1");
    foreach (array_keys($norms) as $n) {
        $exact->execute(array($n));
        $k = $exact->fetchColumn();
        if ($k) return $k;
    }
    // Substring fallback: any alias contained in a candidate (e.g. full address line).
    $all = $db->query("SELECT alias_text, area_key FROM area_aliases ORDER BY CHAR_LENGTH(alias_text) DESC")->fetchAll();
    foreach (array_keys($norms) as $n) {
        foreach ($all as $a) {
            if (strpos($n, $a['alias_text']) !== false) return $a['area_key'];
        }
    }
    return null;
}

// ─────────────────────────────────────────────────────────────────────
// REVIEW QUEUE (docs/adr/0007)
// ─────────────────────────────────────────────────────────────────────

function lc_queue_review($db, $listing_id, $kind, $detail) {
    // De-dup: don't stack identical open items for the same listing+kind.
    if ($listing_id) {
        $chk = $db->prepare("SELECT id FROM listing_review_queue WHERE listing_id = ? AND kind = ? AND status = 'open' LIMIT 1");
        $chk->execute(array($listing_id, $kind));
        if ($chk->fetch()) return;
    }
    $ins = $db->prepare("INSERT INTO listing_review_queue (listing_id, kind, detail, status) VALUES (?, ?, ?, 'open')");
    $ins->execute(array($listing_id ?: null, $kind, json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)));
}

// ─────────────────────────────────────────────────────────────────────
// LOCKED FIELDS (docs/adr/0007 "Locked Field")
// ─────────────────────────────────────────────────────────────────────

function lc_locked_set($csv) {
    if (!$csv) return array();
    return array_filter(array_map('trim', explode(',', $csv)));
}
function lc_is_locked($csv, $field) {
    return in_array($field, lc_locked_set($csv), true);
}

// ─────────────────────────────────────────────────────────────────────
// AGENT RESOLUTION (cross-portal identity — docs/adr/0008)
// ─────────────────────────────────────────────────────────────────────

/** Is this "agent" really just the portal's own name? */
function lc_is_platform_name($name) {
    $n = mb_strtolower(trim((string)$name), 'UTF-8');
    if ($n === '') return false;
    return (bool)preg_match('/^(' . LC_PLATFORM_NAMES . ')$/u', $n);
}

/**
 * Resolve the canonical agent_id for a scraped seller, creating canonical
 * agent + agent_source rows as needed and merging by phone across portals.
 *
 * $a keys: name, agency, phone, source_site, source_agent_id, profile_url,
 *          photo_url, verified
 *
 * Returns int agent_id, or NULL when the listing should be detached
 * (platform placeholder).
 */
function lc_resolve_agent($db, array $a) {
    $name   = isset($a['name']) ? trim((string)$a['name']) : '';
    $agency = isset($a['agency']) ? trim((string)$a['agency']) : '';
    $site   = isset($a['source_site']) ? (string)$a['source_site'] : 'unknown';
    $src_id = isset($a['source_agent_id']) ? (string)$a['source_agent_id'] : '';
    $phone  = lc_normalize_phone(isset($a['phone']) ? $a['phone'] : '');
    $profile= isset($a['profile_url']) ? (string)$a['profile_url'] : null;
    $photo  = isset($a['photo_url']) ? (string)$a['photo_url'] : null;
    $verified = !empty($a['verified']) ? 1 : 0;

    // Platform placeholder -> detach the listing entirely (agent_id NULL).
    if (lc_is_platform_name($name) || lc_is_platform_name($agency)) return null;

    $display = $name !== '' ? $name : ($agency !== '' ? $agency : '');

    // Private seller: no usable identity at all -> shared hidden per-site bucket.
    $is_private = ($display === '' && $phone === '' && !$profile);
    if ($is_private) {
        return lc_get_private_seller_agent($db, $site);
    }
    if ($display === '') $display = ucfirst($site) . ' Agent';
    if ($src_id === '')  $src_id = $phone !== '' ? ('phone_' . $phone) : ('name_' . md5(mb_strtolower($display, 'UTF-8')));

    // 1) Known source tuple -> its canonical agent.
    $st = $db->prepare("SELECT agent_id FROM agent_sources WHERE source_site = ? AND source_agent_id = ? LIMIT 1");
    $st->execute(array($site, $src_id));
    $hit = $st->fetchColumn();
    if ($hit) {
        lc_touch_source_phone($db, $site, $src_id, $phone);
        return (int)lc_canonical_of($db, (int)$hit);
    }

    // 2) Phone match across portals -> merge onto that canonical agent.
    if ($phone !== '') {
        $st = $db->prepare("SELECT DISTINCT agent_id FROM agent_sources WHERE phone_digits = ?");
        $st->execute(array($phone));
        $rows = $st->fetchAll(PDO::FETCH_COLUMN);
        $canon = array();
        foreach ($rows as $r) $canon[(int)lc_canonical_of($db, (int)$r)] = true;
        if (count($canon) >= 1) {
            $agent_id = min(array_keys($canon)); // lowest id wins as canonical
            if (count($canon) > 1) {
                lc_queue_review($db, null, 'ambiguous_agent', array(
                    'phone' => $phone, 'display' => $display,
                    'candidate_agent_ids' => array_keys($canon), 'chosen' => $agent_id,
                ));
            }
            lc_add_source($db, $agent_id, $site, $src_id, $profile, $display, $phone);
            return $agent_id;
        }
    }

    // 3) New canonical agent + its first source.
    $agent_id = lc_create_agent($db, $display, $agency, $phone, $profile, $photo, $site, $src_id, $verified);
    lc_add_source($db, $agent_id, $site, $src_id, $profile, $display, $phone);
    return $agent_id;
}

/** Follow merged_into_agent_id to the surviving canonical row. */
function lc_canonical_of($db, $agent_id) {
    $seen = array();
    while ($agent_id && !isset($seen[$agent_id])) {
        $seen[$agent_id] = true;
        $st = $db->prepare("SELECT merged_into_agent_id FROM agents WHERE id = ?");
        $st->execute(array($agent_id));
        $into = $st->fetchColumn();
        if (!$into) break;
        $agent_id = (int)$into;
    }
    return (int)$agent_id;
}

function lc_get_private_seller_agent($db, $site) {
    $src_id = 'private_seller';
    $st = $db->prepare("SELECT agent_id FROM agent_sources WHERE source_site = ? AND source_agent_id = ? LIMIT 1");
    $st->execute(array($site, $src_id));
    $hit = $st->fetchColumn();
    if ($hit) return (int)$hit;

    $display = 'Private Seller (' . ucfirst($site) . ')';
    $agent_id = lc_create_agent($db, $display, '', '', null, null, $site, $src_id, 0, 'private_seller');
    lc_add_source($db, $agent_id, $site, $src_id, null, $display, '');
    return $agent_id;
}

function lc_create_agent($db, $display, $agency, $phone, $profile, $photo, $site, $src_id, $verified, $kind = 'agent') {
    $slug = lc_make_slug($display) . '-' . substr(md5($site . '|' . $src_id), 0, 6);
    $ins = $db->prepare(
        "INSERT INTO agents
            (user_id, slug, display_name, agency_name, bio, profile_photo_url, phone, whatsapp_number,
             email, website_url, areas_served, languages, is_verified, is_active,
             source_site, source_agent_id, source_profile_url, is_trusted, agent_type,
             agent_kind, first_seen_at)
         VALUES (NULL, ?, ?, ?, NULL, ?, ?, ?, NULL, NULL, 'lombok', 'Bahasa, English', ?, 1,
             ?, ?, ?, 0, NULL, ?, NOW())"
    );
    $ins->execute(array(
        $slug, $display, $agency !== '' ? $agency : null,
        $photo ?: null, $phone !== '' ? $phone : null, $phone !== '' ? $phone : null,
        $verified, $site, $src_id, $profile ?: null, $kind,
    ));
    return (int)$db->lastInsertId();
}

function lc_add_source($db, $agent_id, $site, $src_id, $profile, $display, $phone) {
    $ins = $db->prepare(
        "INSERT INTO agent_sources (agent_id, source_site, source_agent_id, source_profile_url, source_display_name, phone_digits)
         VALUES (?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            agent_id = VALUES(agent_id),
            source_profile_url = COALESCE(VALUES(source_profile_url), source_profile_url),
            source_display_name = COALESCE(VALUES(source_display_name), source_display_name),
            phone_digits = COALESCE(NULLIF(VALUES(phone_digits), ''), phone_digits)"
    );
    $ins->execute(array($agent_id, $site, $src_id, $profile ?: null, $display ?: null, $phone !== '' ? $phone : null));
}

function lc_touch_source_phone($db, $site, $src_id, $phone) {
    if ($phone === '') return;
    $db->prepare("UPDATE agent_sources SET phone_digits = ? WHERE source_site = ? AND source_agent_id = ? AND (phone_digits IS NULL OR phone_digits = '')")
       ->execute(array($phone, $site, $src_id));
}
