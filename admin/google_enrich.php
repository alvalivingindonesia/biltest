<?php
/**
 * Build in Lombok — Admin: Google Search Enrichment (AJAX endpoint)
 *
 * When an entity has no website, this tool searches Google for the business name
 * and extracts: social links, profile images, descriptions, logos.
 *
 * POST JSON:
 *   { "name": "Toko Cipta Baru", "entity_type": "provider", "existing": {...} }
 *   OR for batch mode:
 *   { "action": "find_missing", "min_reviews": 10 }
 *
 * Returns JSON:
 *   { "found": { ... }, "log": [...] }
 *   OR for batch: { "entities": [...] }
 */
session_start();
require_once('/home/rovin629/config/biltest_config.php');

header('Content-Type: application/json');

if (empty($_SESSION['admin_auth'])) {
    echo json_encode(['error' => 'Not authenticated']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

$curl_opts = [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS => 3,
    CURLOPT_TIMEOUT => 12,
    CURLOPT_CONNECTTIMEOUT => 6,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
    CURLOPT_HTTPHEADER => ['Accept: text/html,application/xhtml+xml', 'Accept-Language: en-US,en;q=0.9,id;q=0.8'],
];

// ═══════════════════════════════════════════════════════════════
// ACTION: find_missing — returns entities with 10+ reviews but no image
// ═══════════════════════════════════════════════════════════════
if (($input['action'] ?? '') === 'find_missing') {
    $min_reviews = (int)($input['min_reviews'] ?? 10);
    $db = get_db();
    $entities = [];

    // Providers without image
    $rows = $db->prepare("
        SELECT id, name, google_review_count, google_rating, website_url, 'provider' AS entity_type
        FROM providers
        WHERE google_review_count >= ?
          AND (profile_photo_url IS NULL OR profile_photo_url = '')
          AND is_active = 1
        ORDER BY google_review_count DESC
    ");
    $rows->execute([$min_reviews]);
    foreach ($rows->fetchAll() as $r) { $entities[] = $r; }

    // Developers without image
    $rows = $db->prepare("
        SELECT id, name, google_review_count, google_rating, website_url, 'developer' AS entity_type
        FROM developers
        WHERE google_review_count >= ?
          AND (profile_photo_url IS NULL OR profile_photo_url = '')
          AND is_active = 1
        ORDER BY google_review_count DESC
    ");
    $rows->execute([$min_reviews]);
    foreach ($rows->fetchAll() as $r) { $entities[] = $r; }

    // Agents without image
    $rows = $db->prepare("
        SELECT id, display_name AS name, google_review_count, google_rating, website_url, 'agent' AS entity_type
        FROM agents
        WHERE google_review_count >= ?
          AND (profile_photo_url IS NULL OR profile_photo_url = '')
          AND is_active = 1
        ORDER BY google_review_count DESC
    ");
    $rows->execute([$min_reviews]);
    foreach ($rows->fetchAll() as $r) { $entities[] = $r; }

    echo json_encode(['entities' => $entities, 'total' => count($entities)]);
    exit;
}

// ═══════════════════════════════════════════════════════════════
// ACTION: enrich_save — search Google, find data, and save to DB
// ═══════════════════════════════════════════════════════════════
if (($input['action'] ?? '') === 'enrich_save') {
    $entity_type = $input['entity_type'] ?? '';
    $entity_id = (int)($input['entity_id'] ?? 0);
    if (!$entity_type || !$entity_id) {
        echo json_encode(['error' => 'Missing entity_type or entity_id']);
        exit;
    }

    $db = get_db();
    $table = '';
    $name_col = 'name';
    $photo_col = 'profile_photo_url';
    if ($entity_type === 'provider') { $table = 'providers'; }
    elseif ($entity_type === 'developer') { $table = 'developers'; }
    elseif ($entity_type === 'agent') { $table = 'agents'; $name_col = 'display_name'; $photo_col = 'profile_photo_url'; }
    else { echo json_encode(['error' => 'Invalid entity_type']); exit; }

    $row = $db->query("SELECT * FROM {$table} WHERE id={$entity_id}")->fetch();
    if (!$row) { echo json_encode(['error' => 'Entity not found']); exit; }

    $name = $row[$name_col] ?? '';
    $existing = $row;
    $found = google_search_enrich($name, $existing, $curl_opts);

    // Save found fields to DB
    if (!empty($found['found'])) {
        $updates = [];
        $params = [];
        foreach ($found['found'] as $col => $val) {
            // Only update columns that exist in the table and are currently empty
            if (isset($row[$col]) && (empty($row[$col]) || ($col === 'description' && strlen($val) > strlen($row[$col] ?? '')))) {
                $updates[] = "`{$col}` = ?";
                $params[] = $val;
            }
        }
        if ($updates) {
            $params[] = $entity_id;
            $sql = "UPDATE `{$table}` SET " . implode(', ', $updates) . " WHERE id = ?";
            $db->prepare($sql)->execute($params);
            $found['saved'] = count($updates);
            $found['log'][] = 'Saved ' . count($updates) . ' field(s) to database';
        } else {
            $found['saved'] = 0;
            $found['log'][] = 'No new fields to save (all columns already populated or not applicable)';
        }
    }

    echo json_encode($found);
    exit;
}

// ═══════════════════════════════════════════════════════════════
// DEFAULT: Single Google search enrichment (returns data, does not save)
// ═══════════════════════════════════════════════════════════════
$name = trim($input['name'] ?? '');
$existing = $input['existing'] ?? [];

if (!$name) {
    echo json_encode(['error' => 'No business name provided']);
    exit;
}

$result = google_search_enrich($name, $existing, $curl_opts);
echo json_encode($result);
exit;


// ═══════════════════════════════════════════════════════════════
// FUNCTIONS
// ═══════════════════════════════════════════════════════════════

function get_db() {
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO('mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4', DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }
    return $pdo;
}

/**
 * Search Google for a business name and extract useful info.
 * Uses Google's search results HTML to find social links, images, descriptions.
 */
function google_search_enrich($name, $existing, $curl_opts) {
    $log = [];
    $found = [];

    // Append "Lombok" to help locate the right business
    $search_query = $name . ' Lombok';
    $google_url = 'https://www.google.com/search?q=' . urlencode($search_query) . '&hl=en&gl=id&num=10';

    $ch = curl_init();
    curl_setopt_array($ch, [CURLOPT_URL => $google_url] + $curl_opts);
    $html = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($html === false || $http_code >= 400 || strlen($html) < 500) {
        return ['found' => [], 'log' => ['Google search failed (HTTP ' . $http_code . ')'], 'fields_found' => 0];
    }

    $log[] = 'Google search completed (' . strlen($html) . ' bytes)';

    // ── Extract social media links from search results ──
    $social_patterns = [
        'instagram_url' => '#https?://(?:www\.)?instagram\.com/[a-zA-Z0-9_.]+/?(?:\?|"|\'|&|$)#i',
        'facebook_url'  => '#https?://(?:www\.)?(?:facebook|fb)\.com/[a-zA-Z0-9_./-]+/?(?:\?|"|\'|&|$)#i',
        'tiktok_url'    => '#https?://(?:www\.)?tiktok\.com/@[a-zA-Z0-9_.]+/?(?:\?|"|\'|&|$)#i',
        'youtube_url'   => '#https?://(?:www\.)?youtube\.com/(?:c/|channel/|@)[a-zA-Z0-9_.-]+/?(?:\?|"|\'|&|$)#i',
        'linkedin_url'  => '#https?://(?:www\.)?linkedin\.com/(?:company|in)/[a-zA-Z0-9_./-]+/?(?:\?|"|\'|&|$)#i',
    ];

    foreach ($social_patterns as $key => $pattern) {
        if (!empty($existing[$key])) continue;
        if (preg_match($pattern, $html, $sm)) {
            $url = rtrim(preg_replace('/[\?"\'&]$/', '', $sm[0]), '/');
            // Skip share/sharer/login links
            if (strpos($url, 'sharer') !== false || strpos($url, '/login') !== false) continue;
            $found[$key] = $url;
            $log[] = 'Found ' . $key . ': ' . $url;
        }
    }

    // ── Extract website URL if missing ──
    if (empty($existing['website_url'])) {
        // Look for a website link in the knowledge panel area
        // Google often shows the business website in results
        preg_match_all('#href="(https?://[^"]+)"#i', $html, $all_links);
        foreach ($all_links[1] as $link) {
            $link = html_entity_decode($link, ENT_QUOTES, 'UTF-8');
            // Skip Google's own URLs, social media, directories
            if (preg_match('#google\.|facebook\.|instagram\.|youtube\.|tiktok\.|linkedin\.|twitter\.|tripadvisor\.|yelp\.|maps\.|tokopedia\.|shopee\.|bukalapak\.|goo\.gl#i', $link)) continue;
            if (preg_match('#/search\?|/url\?|webcache|translate\.google#i', $link)) continue;
            // This could be their website
            $found['website_url'] = $link;
            $log[] = 'Found website: ' . $link;
            break;
        }
    }

    // ── Extract description from search snippets ──
    if (empty($existing['description']) || strlen($existing['description'] ?? '') < 50) {
        // Google search result snippets are inside specific elements
        // Try to get the knowledge panel description or search snippets
        $snippets = [];

        // Method 1: Knowledge panel description (div with data-attrid containing description)
        if (preg_match_all('/<span[^>]*>([^<]{50,500})<\/span>/i', $html, $span_matches)) {
            foreach ($span_matches[1] as $span_text) {
                $clean = html_entity_decode(strip_tags($span_text), ENT_QUOTES, 'UTF-8');
                $clean = trim(preg_replace('/\s+/', ' ', $clean));
                // Filter out junk
                if (strlen($clean) < 50) continue;
                if (preg_match('/cookie|privacy|©|sign in|log in|cached|similar/i', $clean)) continue;
                // Check if it mentions the business name (relevance check)
                $name_words = array_filter(explode(' ', strtolower($name)), function($w) { return strlen($w) > 2; });
                $relevance = 0;
                foreach ($name_words as $w) {
                    if (stripos($clean, $w) !== false) $relevance++;
                }
                if ($relevance > 0 || count($name_words) <= 1) {
                    $snippets[] = $clean;
                }
            }
        }

        if ($snippets) {
            // Pick the longest relevant snippet
            usort($snippets, function($a, $b) { return strlen($b) - strlen($a); });
            $best = $snippets[0];
            if (strlen($best) > strlen($existing['description'] ?? '')) {
                $found['description'] = mb_substr($best, 0, 2000);
                $log[] = 'Found description from search results (' . strlen($best) . ' chars)';
            }
            // Also make a short description if missing
            if (empty($existing['short_description'])) {
                $short = $best;
                if (strlen($short) > 160) {
                    if (preg_match('/^(.{60,160}[.!?])\s/', $short, $sm2)) {
                        $short = $sm2[1];
                    } else {
                        $short = mb_substr($short, 0, 160) . '...';
                    }
                }
                $found['short_description'] = $short;
            }
        }
    }

    // ── Extract Google Maps business photo ──
    // Google often embeds a thumbnail of the business from Maps
    if (empty($existing['profile_photo_url'])) {
        $photo_url = '';

        // Method 1: lh5/lh3 googleusercontent images (Google Maps business photos)
        if (preg_match('#(https://lh[35]\.googleusercontent\.com/[a-zA-Z0-9_/=\-]+)#i', $html, $gm)) {
            $photo_url = html_entity_decode($gm[1], ENT_QUOTES, 'UTF-8');
            $log[] = 'Found Google Maps photo: ' . substr($photo_url, 0, 80);
        }

        // Method 2: encrypted-tbn images (Google search thumbnails)
        if (!$photo_url && preg_match('#(https://encrypted-tbn\d+\.gstatic\.com/images\?q=tbn:[a-zA-Z0-9_&;=\-]+)#i', $html, $gm)) {
            $photo_url = html_entity_decode($gm[1], ENT_QUOTES, 'UTF-8');
            $log[] = 'Found Google thumbnail: ' . substr($photo_url, 0, 80);
        }

        if ($photo_url) {
            $found['profile_photo_url'] = $photo_url;
        }
    }

    // ── Try Google Image search for logo ──
    if (empty($existing['logo_url'])) {
        $logo_query = $name . ' logo';
        $img_url = 'https://www.google.com/search?q=' . urlencode($logo_query) . '&tbm=isch&hl=en&gl=id';

        $ch2 = curl_init();
        curl_setopt_array($ch2, [CURLOPT_URL => $img_url] + $curl_opts);
        $img_html = curl_exec($ch2);
        $img_code = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
        curl_close($ch2);

        if ($img_html && $img_code < 400 && strlen($img_html) > 500) {
            $log[] = 'Google Image search completed for logo';
            // Extract image URLs from results
            // Google Images embeds image URLs in JSON within the page
            if (preg_match_all('#\["(https://[^"]+\.(?:jpg|jpeg|png|webp)(?:\?[^"]*)?)"#i', $img_html, $img_matches)) {
                foreach ($img_matches[1] as $candidate) {
                    $candidate = html_entity_decode($candidate, ENT_QUOTES, 'UTF-8');
                    // Skip Google's own images
                    if (preg_match('#gstatic\.com|google\.com|googleapis\.com#i', $candidate)) continue;
                    // Skip tiny images
                    if (preg_match('#\b(icon|favicon|pixel|1x1)\b#i', $candidate)) continue;
                    $found['logo_url'] = $candidate;
                    $log[] = 'Found logo image: ' . substr($candidate, 0, 80);
                    break;
                }
            }
        }
    }

    // ── Phone number from search results ──
    if (empty($existing['phone'])) {
        // Google knowledge panel often shows phone numbers
        if (preg_match('#(?:Phone|Tel|Telepon)[:\s]*([+\d][\d\s\-().]{7,20}\d)#i', $html, $pm)) {
            $found['phone'] = trim($pm[1]);
            $log[] = 'Found phone: ' . $found['phone'];
        }
        // Also try Indonesian phone format
        if (empty($found['phone']) && preg_match('#(?:0|\\+62)[\d\s\-]{8,15}#', $html, $pm)) {
            $phone = preg_replace('/\s+/', '', $pm[0]);
            if (strlen(preg_replace('/[^\d]/', '', $phone)) >= 9) {
                $found['phone'] = $phone;
                $log[] = 'Found phone: ' . $phone;
            }
        }
    }

    return [
        'found' => $found,
        'log' => $log,
        'fields_found' => count($found),
    ];
}
