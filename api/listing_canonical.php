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

/**
 * A breadcrumb/category title like "Tanah Dijual di Lombok Tengah" — Lamudi's
 * generated heading, never the real listing name. Used to avoid overwriting a
 * good title with a generic one on re-check.
 */
function lc_is_generic_title($title) {
    $t = trim(mb_strtolower((string)$title, 'UTF-8'));
    if ($t === '' || mb_strlen($t) <= 6) return true;
    return (bool)preg_match('/^(tanah|rumah|vila|villa|apartemen|ruko|gudang|kavling|kapling|properti)\s+(dijual|disewa)\b/u', $t);
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

// Plausible Lombok land per-m² band (IDR). Calibrated to real prices:
//   remote/rural floor ≈ Rp 10rb/m²; typical good land up to ≈ Rp 15jt/m²;
//   absolute prime beachfront ceiling ≈ Rp 50jt/m². A per-m² above the HARD
//   ceiling is a unit-conversion error, not a real price. The SOFT ceiling
//   just marks "high — a human should verify", it does not reject.
if (!defined('LC_PERM2_MIN'))      define('LC_PERM2_MIN',      10000);          // 10 rb/m²
if (!defined('LC_PERM2_SOFT_MAX')) define('LC_PERM2_SOFT_MAX', 15000000);       // 15 jt/m²
if (!defined('LC_PERM2_HARD_MAX')) define('LC_PERM2_HARD_MAX', 50000000);       // 50 jt/m²
// No single Lombok plot realistically exceeds this as a TOTAL — above it, a
// computed total is the per-are/per-m² bug, not a real listing.
if (!defined('LC_TOTAL_HARD_MAX')) define('LC_TOTAL_HARD_MAX', 500000000000);   // Rp 500 B

/** Does a free-text unit label point at a per-are / per-m² unit price? */
function lc_label_is_per_are($label) {
    $l = mb_strtolower((string)$label, 'UTF-8');
    return (strpos($l, '/are') !== false) || (strpos($l, 'per are') !== false) || ($l === 'are');
}
function lc_label_is_per_sqm($label) {
    $l = mb_strtolower((string)$label, 'UTF-8');
    if (lc_label_is_per_are($l)) return false;
    return (strpos($l, '/m') !== false) || (strpos($l, 'per m') !== false) || (strpos($l, 'm²') !== false) || (strpos($l, 'm2') !== false);
}

/**
 * Infer the true canonical TOTAL price_idr from an amount whose unit is
 * UNCERTAIN (e.g. an existing row with an unreliable label), using land size +
 * the plausible per-m² band. Unlike lc_canonical_price() — which trusts a
 * freshly-scraped label — this decides what the number actually is from its
 * magnitude, so it never multiplies a number that is already a total.
 *
 * Strategy (conservative — never inflate without evidence):
 *   1. If reading the amount AS THE TOTAL gives a plausible per-m², it's a
 *      total. (Covers the common "Rp 9B mislabeled Per Are" case.)
 *   2. Only if the total reading is implausibly CHEAP do we consider that the
 *      amount is a per-are (or per-m²) UNIT and multiply by size — and only if
 *      that yields both a sane per-m² and a sane total.
 *   3. Anything left (too expensive even as a total, or size clearly wrong) is
 *      left untouched and sent to review — we never guess a total.
 *
 * Returns: array(
 *   total, per_sqm, per_are,  // ints or null
 *   interp,      // 'total' | 'per_are' | 'per_sqm' | 'unknown'
 *   confidence,  // 'ok' | 'verify' | 'review'
 *   note         // human-readable reason
 * )
 *
 * $is_land: the per-m² band is a LAND sanity gate only. A built property
 * (house/villa/apartment) is priced as a total that includes the building, so
 * land per-m² is meaningless — we keep the number as a total and never gate or
 * multiply it (only the absolute total ceiling still applies).
 */
function lc_infer_price($amount, $size_sqm, $label_hint = '', $is_land = true) {
    $amount = (int)round((float)$amount);
    $res = array('total'=>null,'per_sqm'=>null,'per_are'=>null,'interp'=>'unknown','confidence'=>'review','note'=>'');
    if ($amount <= 0) { $res['note'] = 'no amount'; return $res; }

    // ── Built property: price is a total incl. building. No per-m² gate. ──
    if (!$is_land) {
        $res['interp'] = 'total';
        $res['total']  = $amount;
        if (lc_trustworthy_size_sqm($size_sqm)) {
            // land per-m² shown for reference only — NOT used to accept/reject.
            $res['per_sqm'] = (int)round($amount / (int)$size_sqm);
            $res['per_are'] = (int)round($amount / (int)$size_sqm * 100);
        }
        if ($amount > LC_TOTAL_HARD_MAX) { $res['confidence'] = 'review'; $res['note'] = 'total above sane ceiling'; }
        else { $res['confidence'] = 'ok'; $res['note'] = 'built property — kept as total (land per-m² not gated)'; }
        return $res;
    }

    $size_ok = lc_trustworthy_size_sqm($size_sqm);
    $S = (int)$size_sqm;

    // ── No trustworthy size: can't sanity-check via per-m². Trust label only. ──
    if (!$size_ok) {
        if (lc_label_is_per_are($label_hint) || lc_label_is_per_sqm($label_hint)) {
            $res['note'] = 'unit price but no land size — cannot total';
            return $res; // interp unknown, confidence review
        }
        $res['interp'] = 'total'; $res['total'] = $amount;
        if ($amount > LC_TOTAL_HARD_MAX) { $res['confidence'] = 'review'; $res['note'] = 'total above sane ceiling, no size to check'; }
        else { $res['confidence'] = 'ok'; }
        return $res;
    }

    $perm2_total  = $amount / $S;            // amount read as the total
    $perm2_perare = $amount / 100.0;         // amount read as per-are (1 are = 100 m²)
    $perm2_persqm = $amount;                 // amount read as per-m²
    $total_perare = $amount * ($S / 100.0);
    $total_persqm = $amount * (float)$S;
    $inband = function($p){ return $p >= LC_PERM2_MIN && $p <= LC_PERM2_HARD_MAX; };

    // 1) The amount is already a plausible TOTAL — the common case. Keep it,
    //    never multiply. (Fixes the trillion-rupiah bug.)
    if ($inband($perm2_total)) {
        $res['interp']  = 'total';
        $res['total']   = $amount;
        $res['per_sqm'] = (int)round($perm2_total);
        $res['per_are'] = (int)round($perm2_total * 100);
        if ($perm2_total > LC_PERM2_SOFT_MAX) {
            $res['confidence'] = 'verify'; $res['note'] = 'high per-m² — verify';
        } elseif (lc_label_is_per_are($label_hint) && $inband($perm2_perare) && $total_perare <= LC_TOTAL_HARD_MAX) {
            // Source labelled it per-are AND that reading is also plausible —
            // we kept it as a total (no inflation) but a human should confirm.
            $res['confidence'] = 'verify'; $res['note'] = 'also plausible as per-are; kept as total';
        } else {
            $res['confidence'] = 'ok';
        }
        return $res;
    }

    // 2) Total reading is implausibly CHEAP — the amount may be a unit price.
    if ($perm2_total < LC_PERM2_MIN) {
        if ($inband($perm2_perare) && $total_perare <= LC_TOTAL_HARD_MAX) {
            $res['interp']  = 'per_are';
            $res['total']   = (int)round($total_perare);
            $res['per_sqm'] = (int)round($perm2_perare);
            $res['per_are'] = $amount;
            $res['confidence'] = 'verify';
            $res['note'] = 'total too cheap; read as per-are × size';
            return $res;
        }
        if ($inband($perm2_persqm) && $total_persqm <= LC_TOTAL_HARD_MAX) {
            $res['interp']  = 'per_sqm';
            $res['total']   = (int)round($total_persqm);
            $res['per_sqm'] = $amount;
            $res['per_are'] = (int)round($amount * 100);
            $res['confidence'] = 'verify';
            $res['note'] = 'total too cheap; read as per-m² × size';
            return $res;
        }
    }

    // 3) Nothing plausible (too expensive even as a total, or size is wrong).
    //    Leave the stored price alone; flag for a human.
    $res['interp']  = 'unknown';
    $res['total']   = $amount;
    $res['per_sqm'] = (int)round($perm2_total);
    $res['per_are'] = (int)round($perm2_total * 100);
    $res['note']    = ($perm2_total > LC_PERM2_HARD_MAX)
        ? 'per-m² above ceiling — land size or price looks wrong'
        : 'no plausible interpretation';
    return $res;
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
function lc_canonical_price($raw_amount, $unit_label, $land_size_sqm, $is_land = true) {
    $amount = (int)round((float)$raw_amount);
    $out = array('price_idr' => null, 'price_idr_per_sqm' => null, 'price_label' => 'Total', 'flagged' => 0);
    if ($amount <= 0) return $out;

    // Built property is a total incl. building — per-are/per-m² is a land-only
    // convention and the per-m² ceiling does not apply. Keep the number as-is.
    if (!$is_land) {
        $out['price_idr'] = $amount;
        if (lc_trustworthy_size_sqm($land_size_sqm)) $out['price_idr_per_sqm'] = (int)round($amount / (int)$land_size_sqm);
        if ($amount > LC_TOTAL_HARD_MAX) { $out['price_idr'] = null; $out['price_idr_per_sqm'] = null; $out['flagged'] = 1; }
        return $out;
    }

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

    // Sanity guard: even a freshly-scraped unit price can be a parse error.
    // If the computed total or its per-m² is beyond the plausible band, don't
    // trust it — Price on Request + flag rather than poison the price index.
    if ($out['price_idr'] !== null) {
        $perm2 = $sqm_ok ? ($out['price_idr'] / $sqm) : null;
        if ($out['price_idr'] > LC_TOTAL_HARD_MAX || ($perm2 !== null && $perm2 > LC_PERM2_HARD_MAX)) {
            $out['price_idr'] = null;
            $out['price_idr_per_sqm'] = null;
            $out['flagged'] = 1;
        }
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

/** Canonical IDR -> USD via currency_rates. Falls back to a sane default rate
 *  so the price-floor guard still works if the FX table is empty/unseeded. */
function lc_idr_to_usd($db, $idr) {
    $idr = (float)$idr;
    if ($idr <= 0) return 0.0;
    $rate = 0.0;
    try {
        $st = $db->prepare("SELECT rate FROM currency_rates WHERE from_currency='USD' AND to_currency='IDR' LIMIT 1");
        $st->execute();
        $rate = (float)$st->fetchColumn();
    } catch (Exception $e) { $rate = 0.0; }
    if ($rate <= 0) $rate = 16000.0;   // conservative fallback (≈ IDR per USD)
    return $idr / $rate;
}

/**
 * Final price-floor guard. A real property's TOTAL price is never below
 * ~USD $10,000 — if it is, the stored number is almost certainly a unit price:
 *   • < $10,000  → a per-ARE price stored as the total (rescale: amount × are)
 *   • < $100     → a per-M²  price stored as the total (rescale: amount × m²)
 * Land is rescaled by its size; a built property (or land with no trustworthy
 * size) can't be rescaled, so we null the price + flag it (Price on Request)
 * rather than show an absurd sub-$10k figure.
 *
 * Returns NULL when no change is needed (already ≥ $10k), else
 *   array(price_idr, price_idr_per_sqm, flagged, basis, note).
 */
function lc_enforce_min_price_floor($db, $total_idr, $size_sqm, $is_land = true) {
    if ($total_idr === null) return null;
    $total_idr = (int)$total_idr;
    if ($total_idr <= 0) return null;

    $usd = lc_idr_to_usd($db, $total_idr);
    if ($usd >= 10000) return null;                       // plausible total — leave it

    $size_ok = lc_trustworthy_size_sqm($size_sqm);
    if (!$is_land || !$size_ok) {
        // Can't rescale (built total, or no land size) — don't show a wrong price.
        return array('price_idr' => null, 'price_idr_per_sqm' => null, 'flagged' => 1,
                     'basis' => 'floor_unfixable',
                     'note'  => 'total below $10k and not rescalable — Price on Request');
    }

    $S = (int)$size_sqm;
    if ($usd < 100) { $fixed = (int)round($total_idr * $S);            $basis = 'per_sqm'; }
    else            { $fixed = (int)round($total_idr * ($S / 100.0));  $basis = 'per_are'; }

    // The rescaled total must itself be sane.
    if ($fixed > LC_TOTAL_HARD_MAX || lc_idr_to_usd($db, $fixed) < 1000) {
        return array('price_idr' => null, 'price_idr_per_sqm' => null, 'flagged' => 1,
                     'basis' => 'floor_implausible',
                     'note'  => 'rescaled price implausible — review');
    }
    return array('price_idr' => $fixed,
                 'price_idr_per_sqm' => (int)round($fixed / $S),
                 'flagged' => 0, 'basis' => $basis,
                 'note' => 'rescaled from sub-$10k total (' . $basis . ' × size)');
}

/**
 * Decide the listing type from the listing's EXPLICIT category + title WORD ORDER
 * — never from a stray "villa" mention. (The old logic substring-matched "villa"
 * over the title, so "Tanah view villa" became a villa.)
 *   • $hint  — the portal's stated category / LLM structured field / stored type
 *              (Tanah, Rumah, Villa, Apartemen, Ruko…): what the listing declares.
 *   • title word order — a real villa LEADS with "Villa…"; a plot whose blurb just
 *     name-drops a villa LEADS with "Tanah…". Whichever word comes first wins.
 *   • building size / bedrooms — corroboration, NOT a gate (we rarely captured a
 *     villa's building size, so absence of one must not demote a real villa).
 *
 * An explicit built category (villa/house/…) is trusted UNLESS the title leads
 * with a land word and there's no building evidence — that's the prose-villa we
 * want to demote to land.
 */
function lc_listing_type($hint, $title, $building_sqm = null, $bedrooms = null) {
    $h = mb_strtolower(trim((string)$hint), 'UTF-8');
    $t = ' ' . mb_strtolower((string)$title, 'UTF-8') . ' ';   // pad → cheap word-boundary
    $has_building = ((int)$building_sqm > 0) || ((int)$bedrooms > 0);

    $hint_type = '';
    if ($h !== '') {
        if (strpos($h, 'tanah') !== false || strpos($h, 'land') !== false || strpos($h, 'kavling') !== false || strpos($h, 'kapling') !== false || strpos($h, 'kebun') !== false) {
            $hint_type = 'land';
        } elseif (strpos($h, 'villa') !== false || strpos($h, 'vila') !== false) {
            $hint_type = 'villa';
        } elseif (strpos($h, 'rumah') !== false || strpos($h, 'house') !== false) {
            $hint_type = 'house';
        } elseif (strpos($h, 'apart') !== false) {
            $hint_type = 'apartment';
        } elseif (strpos($h, 'ruko') !== false || strpos($h, 'gudang') !== false || strpos($h, 'komersial') !== false || strpos($h, 'commercial') !== false) {
            $hint_type = 'commercial';
        }
    }

    // Earliest land word vs earliest built word in the title — word order is the
    // signal. "Tanah view villa" → land leads; "Villa + tanah luas" → villa leads.
    $first = function ($t, $words) {
        $best = PHP_INT_MAX;
        foreach ($words as $w) { $p = mb_strpos($t, $w); if ($p !== false && $p < $best) $best = $p; }
        return $best;
    };
    $pos_land  = $first($t, array(' tanah', ' kavling', ' kapling', ' kebun', ' lahan'));
    $pos_built = $first($t, array(' villa', ' vila', ' rumah', ' house', ' apartemen', ' ruko'));
    $title_leads_land = ($pos_land < $pos_built);

    $built = in_array($hint_type, array('villa', 'house', 'apartment', 'commercial'), true);
    if ($built) {
        // Trust the explicit built category — demote to land only for a plot whose
        // title leads with a land word AND that has no building evidence.
        if ($title_leads_land && !$has_building) return 'land';
        return $hint_type;
    }

    // Hint says land, or no category at all.
    if ($hint_type === 'land') return 'land';
    if ($has_building) return 'house';      // building present but uncategorised → house
    if ($title_leads_land) return 'land';
    return 'land';                           // no category, no building → plot (the common case)
}

/** A change big enough to be suspicious rather than a normal price move. */
function lc_is_price_surprise($old_idr, $new_idr) {
    $old = (int)$old_idr; $new = (int)$new_idr;
    if ($old <= 0 || $new <= 0) return false;
    $ratio = $new > $old ? $new / $old : $old / $new;
    return $ratio >= 5.0;
}

// ─────────────────────────────────────────────────────────────────────
// PRICE FROM DESCRIPTION (fallback when the structured price is missing/wrong)
// Many Lamudi listings bury the real total in the description ("Hanya 1,9 M",
// "Jual 200 juta/are", "Rp 1.900.000.000"). This parses Indonesian price
// phrasing so we can recover a trustworthy total when the card price failed.
// ─────────────────────────────────────────────────────────────────────

/** Parse one Indonesian-formatted number literal ("1,9", "1.900", "9.67"). */
function lc_parse_id_number($s) {
    $s = trim((string)$s);
    if ($s === '') return 0.0;
    if (strpos($s, ',') !== false) {
        // comma = decimal, dots = thousands  ("1.234,5" -> 1234.5)
        return (float) str_replace(',', '.', str_replace('.', '', $s));
    }
    $dots = substr_count($s, '.');
    if ($dots === 1) {
        $after = substr($s, strrpos($s, '.') + 1);
        if (strlen($after) <= 2) return (float)$s;            // decimal: "1.9", "9.67"
        return (float) str_replace('.', '', $s);              // thousands: "1.900"
    }
    if ($dots > 1) return (float) str_replace('.', '', $s);   // "1.900.000.000"
    return (float)$s;
}

/**
 * All price candidates found in free text. Each: array(amount, unit, raw)
 * where unit is 'total' | 'per_are' | 'per_sqm'. A bare number only counts as
 * a price if it has a scale word (m/juta/miliar/ribu), an Rp prefix, or is a
 * large dotted figure — so land SIZES ("9.67 are", "967 m2") are not mistaken
 * for prices.
 */
function lc_prices_from_text($text) {
    $t = ' ' . mb_strtolower((string)$text, 'UTF-8') . ' ';
    $t = str_replace(array("\n", "\r", "\t"), ' ', $t);
    $out = array();
    $re = '/(rp\.?\s*)?([0-9][0-9.,]*)\s*(miliar|milyar|juta|jt|ribu|rb|m(?![²2a-z0-9]))?\s*(\/\s*are|per\s+are|\/\s*m2|\/\s*m²|per\s+m2|per\s+m²)?/u';
    if (!preg_match_all($re, $t, $ms, PREG_SET_ORDER)) return $out;
    $mult = array('miliar'=>1e9,'milyar'=>1e9,'m'=>1e9,'juta'=>1e6,'jt'=>1e6,'ribu'=>1e3,'rb'=>1e3);
    foreach ($ms as $m) {
        $numStr = $m[2];
        $scale  = isset($m[3]) ? $m[3] : '';
        $perun  = isset($m[4]) ? $m[4] : '';
        $hasRp  = isset($m[1]) && trim($m[1]) !== '';
        $val = lc_parse_id_number($numStr);
        if ($val <= 0) continue;
        $amount = $scale !== '' ? $val * $mult[$scale] : $val;
        $amount = (int)round($amount);
        // qualify as a price (not a size/bedroom/etc.)
        $qualifies = ($scale !== '') || $hasRp || ($amount >= 50000000);
        if (!$qualifies || $amount < 1000000) continue;
        $unit = (stripos($perun, 'are') !== false) ? 'per_are'
              : ((stripos($perun, 'm') !== false) ? 'per_sqm' : 'total');
        $out[] = array('amount' => $amount, 'unit' => $unit, 'raw' => trim($m[0]));
    }
    return $out;
}

/**
 * Best TOTAL price recoverable from free text, sanity-checked against land size
 * and the Lombok per-m² band. Prefers an explicit total ("Hanya 1,9 M") over a
 * per-are unit; returns array(total, unit, amount, raw) or null.
 */
function lc_best_total_from_text($text, $size_sqm = null) {
    $cands = lc_prices_from_text($text);
    if (!$cands) return null;
    $size_ok = lc_trustworthy_size_sqm($size_sqm);
    $S = (int)$size_sqm;
    $best = null;
    foreach ($cands as $c) {
        if ($c['unit'] === 'total')        $total = $c['amount'];
        elseif ($c['unit'] === 'per_are')  $total = $size_ok ? (int)round($c['amount'] * ($S / 100.0)) : null;
        else                               $total = $size_ok ? (int)round($c['amount'] * $S) : null;
        if ($total === null) continue;
        if ($total < 50000000 || $total > LC_TOTAL_HARD_MAX) continue;        // 50jt .. 500B
        if ($size_ok) {
            $perm2 = $total / $S;
            if ($perm2 < LC_PERM2_MIN || $perm2 > LC_PERM2_HARD_MAX) continue;
        }
        // prefer an explicit total; tiebreak the larger figure (headline price)
        $score = ($c['unit'] === 'total' ? 1e15 : 0) + $total;
        if ($best === null || $score > $best['score']) {
            $best = array('total' => $total, 'unit' => $c['unit'], 'amount' => $c['amount'], 'raw' => $c['raw'], 'score' => $score);
        }
    }
    if ($best) unset($best['score']);
    return $best;
}

// ─────────────────────────────────────────────────────────────────────
// AUTO-TAGS — mine the title/description for searchable features
// ─────────────────────────────────────────────────────────────────────

/**
 * Suggest feature tags for a listing from its title/short/long description.
 * Returns canonical feature_tags KEYS (beachfront, ocean_view, …) — the SAME
 * keys + bilingual keyword scans as the one-time backfill in
 * migrations/2026_06_12_map_filters_currency.sql, so ongoing ingest stays
 * consistent with the existing filter buttons. Pure — no DB.
 *
 * pool/furnished are built-property only (feature_tags.applies_to), so the
 * listing type gates them here too.
 */
function lc_suggest_tags($title, $description = '', $short_description = '', $listing_type_key = '') {
    $hay = mb_strtolower(trim((string)$title . ' ' . (string)$short_description . ' ' . (string)$description), 'UTF-8');
    if ($hay === '') return array();
    $built = in_array($listing_type_key, array('villa','house','apartment','commercial','long_term_rental'), true);

    // canonical key => regex (mirrors the SQL REGEXP backfill)
    $rules = array(
        'beachfront'      => 'beachfront|beach front|tepi pantai|pinggir pantai|depan pantai',
        'ocean_view'      => 'ocean view|sea view|seaview|oceanview|pemandangan laut|view laut',
        'mountain_view'   => 'mountain view|rinjani view|view of (mount )?rinjani|pemandangan gunung|view gunung',
        'rice_field_view' => 'rice ?field|rice ?paddy|paddy view|sawah',
        'cliff_top'       => 'cliff ?top|clifftop|cliff front|on the cliff|cliff edge|tebing',
        'near_airport'    => 'airport|bandara',
    );
    $out = array();
    foreach ($rules as $key => $re) {
        if (preg_match('/' . $re . '/u', $hay)) $out[] = $key;
    }
    if ($built) {
        if (preg_match('/swimming ?pool|private pool|plunge pool|kolam renang/u', $hay)) $out[] = 'pool';
        if (preg_match('/(fully|full|semi)? ?furnish(ed)?|berperabot/u', $hay) && !preg_match('/unfurnished/u', $hay)) $out[] = 'furnished';
    }
    return array_values(array_unique($out));
}

/**
 * Persist auto tags into listing_tags (listing_id, tag). Existence-checked
 * (the table has no UNIQUE key — the SQL backfill uses NOT EXISTS), so it never
 * duplicates an existing tag, auto or manual. Returns count newly inserted.
 * No-op if the table is absent.
 */
function lc_save_tags($db, $listing_id, array $tags) {
    if (!$listing_id || !$tags) return 0;
    try {
        $chk = $db->prepare("SELECT 1 FROM listing_tags WHERE listing_id = ? AND tag = ? LIMIT 1");
        $ins = $db->prepare("INSERT INTO listing_tags (listing_id, tag) VALUES (?, ?)");
    } catch (Exception $e) { return 0; }
    $n = 0;
    foreach ($tags as $t) {
        $t = trim((string)$t);
        if ($t === '') continue;
        try {
            $chk->execute(array($listing_id, $t));
            if ($chk->fetchColumn()) continue;
            $ins->execute(array($listing_id, $t));
            $n++;
        } catch (Exception $e) {}
    }
    return $n;
}

// ─────────────────────────────────────────────────────────────────────
// AREA RESOLUTION (the wrong-location fix — docs/adr/0007)
// ─────────────────────────────────────────────────────────────────────

/**
 * Resolve area_key from location candidates via area_aliases. Candidates are
 * tried IN THE GIVEN ORDER (most reliable first — e.g. structured location,
 * then title) and the FIRST that maps wins. Within a candidate: exact match,
 * then a word-boundary alias match (so "near kuta" still resolves but "kutai"
 * does not, and a long description doesn't outrank a clean structured field).
 * Returns area_key, or null when nothing maps — caller decides, NEVER defaults.
 */
function lc_resolve_area_key($db, array $candidates) {
    $r = lc_resolve_location($db, $candidates);
    return $r['area_key'];
}

/**
 * Resolve BOTH place_key and area_key from candidates via area_aliases (which
 * now carries place_key). A Place implies its Area. Returns
 * array(area_key, place_key) — either may be null. Place tier (docs/adr/0010).
 */
function lc_resolve_location($db, array $candidates) {
    // Tolerate the pre-migration DB (no place_key column yet).
    static $pcol = null;
    if ($pcol === null) {
        try { $db->query("SELECT place_key FROM area_aliases LIMIT 1"); $pcol = 'place_key'; }
        catch (Exception $e) { $pcol = 'NULL'; }
    }
    $sel = "area_key, $pcol AS place_key";
    $exact = $db->prepare("SELECT $sel FROM area_aliases WHERE alias_text = ? LIMIT 1");
    $all = null;
    foreach ($candidates as $c) {
        $n = lc_normalize_area_text($c);
        if ($n === '') continue;
        $exact->execute(array($n));
        $row = $exact->fetch();
        if ($row && $row['area_key']) return array('area_key' => $row['area_key'], 'place_key' => $row['place_key'] ?: null);
        if ($all === null) $all = $db->query("SELECT alias_text, area_key, $pcol AS place_key FROM area_aliases ORDER BY CHAR_LENGTH(alias_text) DESC")->fetchAll();
        foreach ($all as $a) {
            if (preg_match('/\b' . preg_quote($a['alias_text'], '/') . '\b/u', $n)) {
                return array('area_key' => $a['area_key'], 'place_key' => $a['place_key'] ?: null);
            }
        }
    }
    return array('area_key' => null, 'place_key' => null);
}

/** Cached column-existence check (tolerate pre-migration DB). */
function lc_col_exists($db, $table, $col) {
    static $cache = array();
    $k = "$table.$col";
    if (!isset($cache[$k])) {
        try { $db->query("SELECT `$col` FROM `$table` LIMIT 1"); $cache[$k] = true; }
        catch (Exception $e) { $cache[$k] = false; }
    }
    return $cache[$k];
}

/** Validate an area_key against the curated areas table (cached). */
function lc_area_exists($db, $key) {
    static $cache = array();
    if ($key === null || $key === '') return false;
    if (!isset($cache[$key])) {
        $st = $db->prepare("SELECT 1 FROM areas WHERE `key` = ? LIMIT 1");
        $st->execute(array($key));
        $cache[$key] = (bool)$st->fetchColumn();
    }
    return $cache[$key];
}

/** A Place's parent area_key (validates the place exists), or null. */
function lc_place_area($db, $place_key) {
    static $cache = array();
    if ($place_key === null || $place_key === '') return null;
    if (!array_key_exists($place_key, $cache)) {
        try {
            $st = $db->prepare("SELECT area_key FROM places WHERE place_key = ? AND is_active = 1 LIMIT 1");
            $st->execute(array($place_key));
            $cache[$place_key] = $st->fetchColumn() ?: null;
        } catch (Exception $e) { $cache[$place_key] = null; } // pre-migration: no places table
    }
    return $cache[$place_key];
}

/**
 * Like lc_resolve_area_key but reports WHICH tier resolved it, so callers can
 * trust a title/structured match more than a loose description mention.
 * Returns array(key, source) where source is 'title'|'structured'|'description'|null.
 */
function lc_resolve_area_with_source($db, $structured, $title, $description) {
    $tiers = array('title' => $title, 'structured' => $structured, 'description' => $description);
    foreach ($tiers as $src => $txt) {
        if ($txt === null || $txt === '') continue;
        $k = lc_resolve_area_key($db, array($txt));
        if ($k) return array('key' => $k, 'source' => $src);
    }
    return array('key' => null, 'source' => null);
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
/** Add a field to a listing's locked_fields so the Worker stops overwriting it. */
function lc_lock_field($db, $listing_id, $field) {
    $st = $db->prepare("SELECT locked_fields FROM listings WHERE id = ?");
    $st->execute(array($listing_id));
    $set = lc_locked_set($st->fetchColumn());
    if (!in_array($field, $set, true)) {
        $set[] = $field;
        $db->prepare("UPDATE listings SET locked_fields = ? WHERE id = ?")
           ->execute(array(implode(',', $set), $listing_id));
    }
}

// ─────────────────────────────────────────────────────────────────────
// CHANGE HISTORY (admin review/revert panel)
// ─────────────────────────────────────────────────────────────────────

/**
 * Record one field change (old→new) for a listing. No-op when nothing changed
 * or the table is absent. Keep this to meaningful content fields, not liveness
 * timestamps.
 */
function lc_record_revision($db, $listing_id, $field, $old, $new, $source = 'worker') {
    $o = $old === null ? null : (string)$old;
    $n = $new === null ? null : (string)$new;
    if ($o === $n) return;
    try {
        $db->prepare("INSERT INTO listing_revisions (listing_id, field, old_value, new_value, source) VALUES (?, ?, ?, ?, ?)")
           ->execute(array($listing_id, $field, $o, $n, $source));
    } catch (Exception $e) { /* table not migrated yet — ignore */ }
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
