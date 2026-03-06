<?php
/**
 * Build in Lombok — Review Update Script
 *
 * Fetches up-to-date Google ratings for providers, developers, and agents
 * that have a google_maps_url and haven't been checked in the last 7 days.
 *
 * USAGE:
 *   CLI  : php reviews.php
 *   Web  : /api/reviews.php?key=YOUR_REVIEW_CRON_KEY
 *
 * Schedule (cPanel cron):
 *   0 3 * * * php /home/rovin629/subdomain/biltest.roving-i.com.au/api/reviews.php
 */

// ─── AUTH ──────────────────────────────────────────────────────────
$is_cli = (PHP_SAPI === 'cli');

if (!$is_cli) {
    header('Content-Type: text/plain; charset=utf-8');
}

require_once('/home/rovin629/config/biltest_config.php');

// Web access requires a ?key= parameter matching REVIEW_CRON_KEY
if (!$is_cli) {
    if (!defined('REVIEW_CRON_KEY') || !REVIEW_CRON_KEY) {
        http_response_code(403);
        exit("Review cron key not configured.\n");
    }
    $supplied_key = $_GET['key'] ?? '';
    if (!hash_equals(REVIEW_CRON_KEY, $supplied_key)) {
        http_response_code(403);
        exit("Invalid key.\n");
    }
}

// ─── DATABASE ─────────────────────────────────────────────────────
function get_db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO('mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4', DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }
    return $pdo;
}

// ─── HELPERS ──────────────────────────────────────────────────────
function log_line(string $msg): void {
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n";
    echo $line;
}

/**
 * Try to extract a Google Place ID from a Google Maps URL.
 * Works for URLs like:
 *   https://maps.google.com/?cid=123456
 *   https://www.google.com/maps/place/Name/@lat,lng,zoom/data=...
 */
function extract_place_id_from_url(string $url): ?string {
    // Explicit place_id in URL
    if (preg_match('/place_id=([A-Za-z0-9_-]+)/', $url, $m)) {
        return $m[1];
    }
    return null;
}

/**
 * Use Google Places API to fetch rating for a place_id.
 * Returns ['rating' => float, 'user_ratings_total' => int] or null on failure.
 */
function fetch_rating_from_places_api(string $place_id, string $api_key): ?array {
    $url = 'https://maps.googleapis.com/maps/api/place/details/json?'
         . http_build_query([
             'place_id' => $place_id,
             'fields'   => 'rating,user_ratings_total',
             'key'      => $api_key,
         ]);

    $ctx      = stream_context_create(['http' => ['timeout' => 15, 'ignore_errors' => true]]);
    $response = @file_get_contents($url, false, $ctx);
    if (!$response) return null;

    $data = json_decode($response, true);
    if (($data['status'] ?? '') !== 'OK') return null;

    $result = $data['result'] ?? [];
    if (!isset($result['rating'])) return null;

    return [
        'rating'             => (float)$result['rating'],
        'user_ratings_total' => (int)($result['user_ratings_total'] ?? 0),
    ];
}

/**
 * Fallback: try to regex-extract a rating from the raw Google Maps page HTML.
 * This is unreliable and may stop working if Google changes their markup.
 * Returns ['rating' => float, 'user_ratings_total' => int] or null.
 */
function fetch_rating_from_html(string $maps_url): ?array {
    $ctx = stream_context_create([
        'http' => [
            'timeout'     => 15,
            'user_agent'  => 'Mozilla/5.0 (compatible; BuildInLombok/1.0)',
            'ignore_errors' => true,
        ],
    ]);
    $html = @file_get_contents($maps_url, false, $ctx);
    if (!$html) return null;

    // e.g. "4.5 stars" or ratingValue":4.5
    $rating = null;
    $count  = null;

    if (preg_match('/"ratingValue"\s*:\s*"?([\d.]+)"?/', $html, $m)) {
        $rating = (float)$m[1];
    } elseif (preg_match('/\b([1-5]\.\d)\s*(?:stars?|★)/i', $html, $m)) {
        $rating = (float)$m[1];
    }

    if (preg_match('/"reviewCount"\s*:\s*"?(\d+)"?/', $html, $m)) {
        $count = (int)$m[1];
    }

    if ($rating === null) return null;

    return ['rating' => $rating, 'user_ratings_total' => $count ?? 0];
}

// ─── MAIN ─────────────────────────────────────────────────────────

$use_places_api = defined('GOOGLE_PLACES_API_KEY') && GOOGLE_PLACES_API_KEY;
$api_key        = $use_places_api ? GOOGLE_PLACES_API_KEY : null;

log_line('Review update starting. Places API: ' . ($use_places_api ? 'yes' : 'no (fallback)'));

$db = get_db();

// Entity types: table, entity_type key for log
$entity_configs = [
    ['table' => 'providers',  'type' => 'provider'],
    ['table' => 'developers', 'type' => 'developer'],
    ['table' => 'agents',     'type' => 'agent'],
];

$total_checked = 0;
$total_updated = 0;

foreach ($entity_configs as $cfg) {
    $table = $cfg['table'];
    $type  = $cfg['type'];

    // Find entities that need a check (never checked OR older than 7 days)
    $stmt = $db->query(
        "SELECT id, google_maps_url, google_place_id, google_rating, google_review_count
         FROM `{$table}`
         WHERE google_maps_url IS NOT NULL
           AND google_maps_url != ''
           AND (last_review_check IS NULL OR last_review_check < DATE_SUB(NOW(), INTERVAL 7 DAY))"
    );
    $entities = $stmt->fetchAll();

    log_line("  {$table}: found " . count($entities) . " to check.");

    foreach ($entities as $entity) {
        $total_checked++;
        $entity_id  = (int)$entity['id'];
        $maps_url   = $entity['google_maps_url'];
        $place_id   = $entity['google_place_id'] ?: extract_place_id_from_url($maps_url);

        $result = null;

        // Try Places API first
        if ($use_places_api && $place_id) {
            $result = fetch_rating_from_places_api($place_id, $api_key);
            if ($result) log_line("    [{$type} #{$entity_id}] Places API → rating={$result['rating']}, count={$result['user_ratings_total']}");
        }

        // Fallback: scrape HTML
        if (!$result) {
            $result = fetch_rating_from_html($maps_url);
            if ($result) log_line("    [{$type} #{$entity_id}] HTML fallback → rating={$result['rating']}, count={$result['user_ratings_total']}");
        }

        // Record that we checked regardless
        $db->prepare("UPDATE `{$table}` SET last_review_check = NOW() WHERE id = ?")->execute([$entity_id]);

        if (!$result) {
            log_line("    [{$type} #{$entity_id}] Could not fetch rating.");
            $db->prepare(
                "INSERT INTO review_update_log (entity_type, entity_id, requested_by, status, created_at)
                 VALUES (?, ?, 0, 'failed', NOW())"
            )->execute([$type, $entity_id]);
            continue;
        }

        $new_rating = $result['rating'];
        $new_count  = $result['user_ratings_total'];
        $old_rating = $entity['google_rating'];
        $old_count  = $entity['google_review_count'];

        // Update entity
        $db->prepare(
            "UPDATE `{$table}` SET google_rating = ?, google_review_count = ?, last_review_check = NOW() WHERE id = ?"
        )->execute([$new_rating, $new_count, $entity_id]);

        // Log the update
        $db->prepare(
            "INSERT INTO review_update_log
                (entity_type, entity_id, requested_by, status, old_rating, new_rating, old_count, new_count, updated_at, created_at)
             VALUES (?, ?, 0, 'done', ?, ?, ?, ?, NOW(), NOW())"
        )->execute([$type, $entity_id, $old_rating, $new_rating, $old_count, $new_count]);

        $total_updated++;

        if ($old_rating != $new_rating || $old_count != $new_count) {
            log_line("    [{$type} #{$entity_id}] Updated: {$old_rating}({$old_count}) → {$new_rating}({$new_count})");
        }
    }
}

log_line("Done. Checked: {$total_checked}, Updated: {$total_updated}.");
