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
        // Get actual column names from the table
        $col_check = $db->query("SHOW COLUMNS FROM `{$table}`")->fetchAll(PDO::FETCH_COLUMN);
        $valid_cols = array_flip($col_check);

        $updates = [];
        $params = [];
        foreach ($found['found'] as $col => $val) {
            // Only update columns that actually exist in the table
            if (!isset($valid_cols[$col])) {
                $found['log'][] = 'Skipped ' . $col . ' (column not in ' . $table . ')';
                continue;
            }
            // Only update if currently empty/null, or description is shorter
            $current = $row[$col];
            $is_empty = ($current === null || $current === '' || $current === '0');
            $is_desc_upgrade = ($col === 'description' && strlen($val) > strlen($current ?? ''));
            if ($is_empty || $is_desc_upgrade) {
                $updates[] = "`{$col}` = ?";
                $params[] = $val;
                $found['log'][] = 'Will save ' . $col;
            } else {
                $found['log'][] = 'Skipped ' . $col . ' (already has value)';
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
 * Search for a business name using multiple engines and extract useful info.
 * Tries Bing first (most reliable for server-side), DuckDuckGo as fallback.
 */
function google_search_enrich($name, $existing, $curl_opts) {
    $log = [];
    $found = [];
    $search_query = $name . ' Lombok Indonesia';
    $html = '';
    $source = '';

    // ── Try Bing (most reliable for server-side scraping) ──
    $bing_url = 'https://www.bing.com/search?q=' . urlencode($search_query) . '&setlang=en&cc=id&count=15';
    $ch = curl_init();
    curl_setopt_array($ch, [CURLOPT_URL => $bing_url] + $curl_opts);
    $bing_html = curl_exec($ch);
    $bing_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($bing_html && $bing_code < 400 && strlen($bing_html) > 1000
        && stripos($bing_html, 'captcha') === false
        && stripos($bing_html, 'unusual traffic') === false) {
        $html = $bing_html;
        $source = 'Bing';
        $log[] = 'Bing search OK (' . strlen($html) . ' bytes, HTTP ' . $bing_code . ')';
    } else {
        $log[] = 'Bing failed (HTTP ' . $bing_code . ', ' . strlen($bing_html ?: '') . ' bytes) — trying DuckDuckGo';
    }

    // ── Fallback: DuckDuckGo HTML ──
    if (!$html) {
        $ddg_url = 'https://html.duckduckgo.com/html/?q=' . urlencode($search_query);
        $ch = curl_init();
        curl_setopt_array($ch, [CURLOPT_URL => $ddg_url] + $curl_opts);
        $ddg_html = curl_exec($ch);
        $ddg_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($ddg_html && $ddg_code < 400 && strlen($ddg_html) > 1000) {
            $html = $ddg_html;
            $source = 'DuckDuckGo';
            $log[] = 'DuckDuckGo search OK (' . strlen($html) . ' bytes, HTTP ' . $ddg_code . ')';
        } else {
            $log[] = 'DuckDuckGo also failed (HTTP ' . $ddg_code . ', ' . strlen($ddg_html ?: '') . ' bytes)';
        }
    }

    // ── Last resort: Google ──
    if (!$html) {
        $google_url = 'https://www.google.com/search?q=' . urlencode($search_query) . '&hl=en&gl=id&num=10';
        $ch = curl_init();
        curl_setopt_array($ch, [CURLOPT_URL => $google_url] + $curl_opts);
        $g_html = curl_exec($ch);
        $g_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($g_html && $g_code < 400 && strlen($g_html) > 1000
            && stripos($g_html, 'unusual traffic') === false
            && stripos($g_html, 'captcha') === false) {
            $html = $g_html;
            $source = 'Google';
            $log[] = 'Google search OK (' . strlen($html) . ' bytes)';
        } else {
            $log[] = 'Google also failed/blocked (HTTP ' . $g_code . ')';
        }
    }

    if (!$html) {
        return ['found' => [], 'log' => $log, 'fields_found' => 0];
    }

    // ── Extract all links from the page ──
    // Bing/DDG/Google all have href links, DDG wraps them in uddg= param
    $all_urls = [];
    preg_match_all('#href="(https?://[^"]+)"#i', $html, $href_matches);
    foreach ($href_matches[1] as $raw) {
        $decoded = html_entity_decode($raw, ENT_QUOTES, 'UTF-8');
        // DuckDuckGo wraps real URLs in //duckduckgo.com/l/?uddg=...
        if (preg_match('#[?&]uddg=(https?[^&]+)#i', $decoded, $uddg)) {
            $decoded = urldecode($uddg[1]);
        }
        $all_urls[] = $decoded;
    }
    // Also extract URLs from plain text (Bing sometimes has them in cite tags etc)
    preg_match_all('#(https?://[a-zA-Z0-9_./-]+\.[a-zA-Z]{2,}[a-zA-Z0-9_./?&=%-]*)#i', $html, $text_urls);
    foreach ($text_urls[1] as $tu) {
        $all_urls[] = html_entity_decode($tu, ENT_QUOTES, 'UTF-8');
    }
    $all_urls = array_unique($all_urls);
    $log[] = 'Extracted ' . count($all_urls) . ' URLs from ' . $source . ' results';

    // ── Extract social media links ──
    $social_patterns = [
        'instagram_url' => '#^https?://(?:www\.)?instagram\.com/([a-zA-Z0-9_.]+)/?$#i',
        'facebook_url'  => '#^https?://(?:www\.)?(?:facebook|fb)\.com/([a-zA-Z0-9_./-]+)/?$#i',
        'tiktok_url'    => '#^https?://(?:www\.)?tiktok\.com/@([a-zA-Z0-9_.]+)/?$#i',
        'youtube_url'   => '#^https?://(?:www\.)?youtube\.com/(?:c/|channel/|@)([a-zA-Z0-9_.-]+)/?$#i',
        'linkedin_url'  => '#^https?://(?:www\.)?linkedin\.com/(?:company|in)/([a-zA-Z0-9_./-]+)/?$#i',
    ];

    foreach ($all_urls as $url_candidate) {
        // Clean trailing junk
        $url_clean = preg_replace('/[\?"\x27&;#].*$/', '', $url_candidate);
        $url_clean = rtrim($url_clean, '/');

        foreach ($social_patterns as $key => $pattern) {
            if (!empty($existing[$key]) || !empty($found[$key])) continue;
            if (preg_match($pattern, $url_clean . '/', $sm)) {
                $handle = $sm[1];
                // Skip generic/junk handles
                if (preg_match('/^(sharer|share|login|signup|help|about|privacy|policy|watch|explore|reels)$/i', $handle)) continue;
                $found[$key] = $url_clean;
                $log[] = 'Found ' . $key . ': ' . $url_clean;
            }
        }
    }

    // ── Extract website URL if missing ──
    if (empty($existing['website_url'])) {
        $skip_domains = '#(google\.|bing\.|duckduckgo\.|facebook\.|instagram\.|youtube\.|tiktok\.|linkedin\.|twitter\.|x\.com|tripadvisor\.|yelp\.|maps\.|tokopedia\.|shopee\.|bukalapak\.|wikipedia\.|goo\.gl|msn\.|yahoo\.)#i';
        foreach ($all_urls as $link) {
            $link = preg_replace('/[\?#].*$/', '', $link);
            if (preg_match($skip_domains, $link)) continue;
            if (preg_match('#/search|/url\?|webcache|translate\.|cache:|&amp;#i', $link)) continue;
            $found['website_url'] = $link;
            $log[] = 'Found website: ' . $link;
            break;
        }
    }

    // ── Extract description from snippets ──
    if (empty($existing['description']) || strlen($existing['description'] ?? '') < 50) {
        $snippets = [];
        // Generic snippet extraction — works for Bing, DDG and Google
        // Bing uses <p class="b_paractl">, DDG uses <a class="result__snippet">, both have <span>
        if (preg_match_all('#>([^<]{50,500})</#i', $html, $span_matches)) {
            $name_words = array_filter(explode(' ', strtolower($name)), function($w) { return strlen($w) > 2; });
            foreach ($span_matches[1] as $span_text) {
                $clean = html_entity_decode(strip_tags($span_text), ENT_QUOTES, 'UTF-8');
                $clean = trim(preg_replace('/\s+/', ' ', $clean));
                if (strlen($clean) < 50) continue;
                if (preg_match('/cookie|privacy|©|sign in|log in|cached|similar|javascript|stylesheet/i', $clean)) continue;
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
            usort($snippets, function($a, $b) { return strlen($b) - strlen($a); });
            $best = $snippets[0];
            if (strlen($best) > strlen($existing['description'] ?? '')) {
                $found['description'] = mb_substr($best, 0, 2000);
                $log[] = 'Found description (' . strlen($best) . ' chars)';
            }
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

    // ── Profile photo: try to find images in search results ──
    if (empty($existing['profile_photo_url'])) {
        // Bing/Google often embed thumbnails
        $photo_url = '';
        if (preg_match('#(https://lh[35]\.googleusercontent\.com/[a-zA-Z0-9_/=\-]+)#i', $html, $gm)) {
            $photo_url = html_entity_decode($gm[1], ENT_QUOTES, 'UTF-8');
            $log[] = 'Found Google Maps photo';
        }
        if (!$photo_url && preg_match('#(https://(?:tse|th)\d*\.mm\.bing\.net/th[^"\s]+)#i', $html, $bm)) {
            $photo_url = html_entity_decode($bm[1], ENT_QUOTES, 'UTF-8');
            $log[] = 'Found Bing thumbnail';
        }
        if (!$photo_url && preg_match('#(https://encrypted-tbn\d+\.gstatic\.com/images\?q=tbn:[a-zA-Z0-9_&;=\-]+)#i', $html, $gm)) {
            $photo_url = html_entity_decode($gm[1], ENT_QUOTES, 'UTF-8');
            $log[] = 'Found Google thumbnail';
        }
        if ($photo_url) {
            $found['profile_photo_url'] = $photo_url;
        }
    }

    // ── Logo: try Bing Image search (more permissive than Google) ──
    if (empty($existing['logo_url'])) {
        $logo_query = $name . ' logo';
        $img_url = 'https://www.bing.com/images/search?q=' . urlencode($logo_query) . '&form=HDRSC2';
        $ch2 = curl_init();
        curl_setopt_array($ch2, [CURLOPT_URL => $img_url] + $curl_opts);
        $img_html = curl_exec($ch2);
        $img_code = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
        curl_close($ch2);

        if ($img_html && $img_code < 400 && strlen($img_html) > 500) {
            $log[] = 'Bing Image search completed for logo';
            // Bing Images stores URLs in murl param or in JSON
            if (preg_match_all('#"murl":"(https?://[^"]+)"#i', $img_html, $img_matches)) {
                foreach ($img_matches[1] as $candidate) {
                    $candidate = html_entity_decode(str_replace('\\/', '/', $candidate), ENT_QUOTES, 'UTF-8');
                    if (preg_match('#gstatic\.com|google\.com|bing\.com|msn\.com#i', $candidate)) continue;
                    if (preg_match('#\b(icon|favicon|pixel|1x1)\b#i', $candidate)) continue;
                    $found['logo_url'] = $candidate;
                    $log[] = 'Found logo: ' . substr($candidate, 0, 80);
                    break;
                }
            }
        } else {
            $log[] = 'Bing Image search failed (HTTP ' . $img_code . ')';
        }
    }

    // ── Phone number ──
    if (empty($existing['phone'])) {
        if (preg_match('#(?:Phone|Tel|Telepon|Telp)[:\s]*([+\d][\d\s\-().]{7,20}\d)#i', $html, $pm)) {
            $found['phone'] = trim($pm[1]);
            $log[] = 'Found phone: ' . $found['phone'];
        }
        if (empty($found['phone']) && preg_match('#((?:0|\+62)[\d\s\-]{8,15})#', $html, $pm)) {
            $phone = preg_replace('/\s+/', '', $pm[1]);
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
