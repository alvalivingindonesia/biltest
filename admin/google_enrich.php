<?php
/**
 * Build in Lombok — Admin: Enrichment Tool (AJAX endpoint)
 *
 * Strategy: Use the entity's existing Google Maps URL + direct social lookups.
 * Search engines block server-side requests; Google Maps pages are more accessible.
 *
 * POST JSON actions:
 *   { "action": "find_missing", "min_reviews": 10 }
 *   { "action": "enrich_save", "entity_type": "provider", "entity_id": 73 }
 *   { "name": "Toko Cipta Baru", "existing": {...} }   (single, no save)
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
    CURLOPT_MAXREDIRS => 5,
    CURLOPT_TIMEOUT => 15,
    CURLOPT_CONNECTTIMEOUT => 8,
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
        SELECT id, name, google_review_count, google_rating, website_url, google_maps_url, 'provider' AS entity_type
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
        SELECT id, name, google_review_count, google_rating, website_url, google_maps_url, 'developer' AS entity_type
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
        SELECT id, display_name AS name, google_review_count, google_rating, website_url, google_maps_url, 'agent' AS entity_type
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
// ACTION: enrich_save — enrich and save to DB
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
    if ($entity_type === 'provider') { $table = 'providers'; }
    elseif ($entity_type === 'developer') { $table = 'developers'; }
    elseif ($entity_type === 'agent') { $table = 'agents'; $name_col = 'display_name'; }
    else { echo json_encode(['error' => 'Invalid entity_type']); exit; }

    $row = $db->query("SELECT * FROM {$table} WHERE id={$entity_id}")->fetch();
    if (!$row) { echo json_encode(['error' => 'Entity not found']); exit; }

    $name = $row[$name_col] ?? '';
    $existing = $row;
    $result = enrich_entity($name, $existing, $curl_opts);

    // Save found fields to DB
    if (!empty($result['found'])) {
        $col_check = $db->query("SHOW COLUMNS FROM `{$table}`")->fetchAll(PDO::FETCH_COLUMN);
        $valid_cols = array_flip($col_check);

        $updates = [];
        $params = [];
        foreach ($result['found'] as $col => $val) {
            if (!isset($valid_cols[$col])) {
                $result['log'][] = 'Skipped ' . $col . ' (column not in ' . $table . ')';
                continue;
            }
            $current = $row[$col];
            $is_empty = ($current === null || $current === '' || $current === '0');
            $is_desc_upgrade = ($col === 'description' && strlen($val) > strlen($current ?? ''));
            if ($is_empty || $is_desc_upgrade) {
                $updates[] = "`{$col}` = ?";
                $params[] = $val;
                $result['log'][] = 'Will save ' . $col;
            } else {
                $result['log'][] = 'Skipped ' . $col . ' (already has value)';
            }
        }
        if ($updates) {
            $params[] = $entity_id;
            $sql = "UPDATE `{$table}` SET " . implode(', ', $updates) . " WHERE id = ?";
            $db->prepare($sql)->execute($params);
            $result['saved'] = count($updates);
            $result['log'][] = 'Saved ' . count($updates) . ' field(s) to database';
        } else {
            $result['saved'] = 0;
            $result['log'][] = 'No new fields to save';
        }
    }

    echo json_encode($result);
    exit;
}

// ═══════════════════════════════════════════════════════════════
// DEFAULT: Single enrichment (returns data, does not save)
// ═══════════════════════════════════════════════════════════════
$name = trim($input['name'] ?? '');
$existing = $input['existing'] ?? [];

if (!$name) {
    echo json_encode(['error' => 'No business name provided']);
    exit;
}

$result = enrich_entity($name, $existing, $curl_opts);
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
 * Helper: fetch a URL via cURL, return [html, http_code] or [false, code]
 */
function fetch_url($url, $curl_opts) {
    $ch = curl_init();
    curl_setopt_array($ch, [CURLOPT_URL => $url] + $curl_opts);
    $html = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$html, $code];
}

/**
 * Main enrichment function.
 *
 * Strategy (in order):
 *   1. Google Maps URL (if available) — fetch the page for photos/details
 *   2. Google Places photo via CID/Place ID from the Maps URL
 *   3. Direct Instagram profile lookup by business name
 *   4. Direct Facebook page lookup by business name
 */
function enrich_entity($name, $existing, $curl_opts) {
    $log = [];
    $found = [];

    $google_maps_url = $existing['google_maps_url'] ?? '';

    // ══════════════════════════════════════════════════════════
    // STEP 1: Fetch Google Maps page (most reliable source)
    // ══════════════════════════════════════════════════════════
    if ($google_maps_url) {
        $log[] = 'Fetching Google Maps URL: ' . substr($google_maps_url, 0, 80);
        list($maps_html, $maps_code) = fetch_url($google_maps_url, $curl_opts);

        if ($maps_html && $maps_code < 400 && strlen($maps_html) > 2000) {
            $log[] = 'Google Maps page loaded (' . strlen($maps_html) . ' bytes)';

            // ── Photo from Google Maps ──
            // Google Maps pages embed business photos as lh5/lh3 googleusercontent URLs
            if (empty($existing['profile_photo_url'])) {
                $photo_patterns = [
                    // Google Maps business photos (high quality)
                    '#(https://lh[35]\\.googleusercontent\\.com/p/[a-zA-Z0-9_/=\\-]+)#i',
                    // Generic googleusercontent
                    '#(https://lh[35]\\.googleusercontent\\.com/[a-zA-Z0-9_/=\\-]+)#i',
                    // Street view / photo thumbnails
                    '#(https://streetviewpixels[a-zA-Z0-9._/-]+\\.googleusercontent\\.com/[a-zA-Z0-9_/=\\-]+)#i',
                    // geo photos
                    '#(https://geo[0-9]*\\.ggpht\\.com/[a-zA-Z0-9_/=\\-?&]+)#i',
                ];
                foreach ($photo_patterns as $pp) {
                    if (preg_match($pp, $maps_html, $pm)) {
                        $found['profile_photo_url'] = html_entity_decode($pm[1], ENT_QUOTES, 'UTF-8');
                        $log[] = 'Found photo from Maps page';
                        break;
                    }
                }
            }

            // ── Website from Google Maps ──
            if (empty($existing['website_url'])) {
                // Maps pages sometimes embed the business website
                if (preg_match('#"website":"(https?://[^"]+)"#i', $maps_html, $wm)) {
                    $found['website_url'] = html_entity_decode($wm[1], ENT_QUOTES, 'UTF-8');
                    $log[] = 'Found website from Maps: ' . $found['website_url'];
                }
            }

            // ── Phone from Google Maps ──
            if (empty($existing['phone'])) {
                // Google Maps JSON often contains phone
                if (preg_match('#"phone(?:Number)?"\s*:\s*"([+\d][\d\s\-().]{7,20}\d)"#i', $maps_html, $phm)) {
                    $found['phone'] = trim($phm[1]);
                    $log[] = 'Found phone from Maps: ' . $found['phone'];
                }
                // Also try formatted phone in visible text
                if (empty($found['phone']) && preg_match('#(?:0|\+62)[\d\s\-]{8,15}#', $maps_html, $phm)) {
                    $phone = preg_replace('/\s+/', '', $phm[0]);
                    if (strlen(preg_replace('/[^\d]/', '', $phone)) >= 9) {
                        $found['phone'] = $phone;
                        $log[] = 'Found phone from Maps text: ' . $phone;
                    }
                }
            }

            // ── Address from Google Maps ──
            if (empty($existing['address'])) {
                if (preg_match('#"address(?:Line)?"\s*:\s*"([^"]{10,200})"#i', $maps_html, $am)) {
                    $found['address'] = html_entity_decode($am[1], ENT_QUOTES, 'UTF-8');
                    $log[] = 'Found address from Maps';
                }
            }

            // ── Social links from Google Maps (they sometimes appear) ──
            $social_domains = [
                'instagram_url' => 'instagram.com',
                'facebook_url'  => 'facebook.com',
                'linkedin_url'  => 'linkedin.com',
                'youtube_url'   => 'youtube.com',
                'tiktok_url'    => 'tiktok.com',
            ];
            preg_match_all('#https?://(?:www\.)?(?:facebook|fb|instagram|linkedin|youtube|tiktok)\.com/[a-zA-Z0-9_./@-]+#i', $maps_html, $social_matches);
            foreach ($social_matches[0] as $surl) {
                $surl = rtrim(html_entity_decode($surl, ENT_QUOTES, 'UTF-8'), '/');
                foreach ($social_domains as $key => $domain) {
                    if (empty($existing[$key]) && empty($found[$key]) && stripos($surl, $domain) !== false) {
                        // Skip generic paths
                        if (preg_match('#/(sharer|share|login|signup|help|policies|watch)#i', $surl)) continue;
                        $found[$key] = $surl;
                        $log[] = 'Found ' . $key . ' from Maps: ' . $surl;
                    }
                }
            }
        } else {
            $log[] = 'Google Maps fetch failed (HTTP ' . $maps_code . ', ' . strlen($maps_html ?: '') . ' bytes)';
        }
    } else {
        $log[] = 'No Google Maps URL available';
    }

    // ══════════════════════════════════════════════════════════
    // STEP 2: Try Google Maps Place photo API-style URL
    // ══════════════════════════════════════════════════════════
    if (empty($found['profile_photo_url']) && empty($existing['profile_photo_url']) && $google_maps_url) {
        // Extract CID or place identifier from the maps URL
        $place_photo_url = '';
        // /maps/place/... URLs often have a data= param with encoded CID
        // We can try the Maps thumbnail endpoint
        if (preg_match('#/maps/place/([^/?]+)#i', $google_maps_url, $place_match)) {
            $place_name = urldecode($place_match[1]);
            $log[] = 'Trying Maps search for place: ' . $place_name;
            // Use Maps search to find a photo
            $maps_search = 'https://www.google.com/maps/search/' . urlencode($place_name . ' Lombok') . '/';
            list($ms_html, $ms_code) = fetch_url($maps_search, $curl_opts);
            if ($ms_html && $ms_code < 400) {
                if (preg_match('#(https://lh[35]\.googleusercontent\.com/p/[a-zA-Z0-9_/=\-]+)#i', $ms_html, $mpm)) {
                    $found['profile_photo_url'] = html_entity_decode($mpm[1], ENT_QUOTES, 'UTF-8');
                    $log[] = 'Found photo from Maps search redirect';
                }
            }
        }
    }

    // ══════════════════════════════════════════════════════════
    // STEP 3: Try direct Instagram lookup
    // ══════════════════════════════════════════════════════════
    if (empty($existing['instagram_url']) && empty($found['instagram_url'])) {
        // Build a likely Instagram handle from the business name
        $ig_handles = build_social_handles($name);
        foreach ($ig_handles as $handle) {
            $ig_url = 'https://www.instagram.com/' . $handle . '/';
            list($ig_html, $ig_code) = fetch_url($ig_url, $curl_opts);
            // Instagram returns 200 for valid profiles, 404 for non-existent
            if ($ig_code === 200 && $ig_html && strlen($ig_html) > 5000) {
                // Verify it's an actual profile (not a login/redirect page)
                if (stripos($ig_html, '"@type":"ProfilePage"') !== false
                    || stripos($ig_html, 'profile_pic_url') !== false
                    || preg_match('#<title>[^<]*@' . preg_quote($handle, '#') . '#i', $ig_html)) {
                    $found['instagram_url'] = 'https://www.instagram.com/' . $handle;
                    $log[] = 'Found Instagram profile: @' . $handle;

                    // Try to get profile pic from Instagram
                    if (empty($found['profile_photo_url']) && empty($existing['profile_photo_url'])) {
                        if (preg_match('#"profile_pic_url(?:_hd)?"\s*:\s*"(https?://[^"]+)"#i', $ig_html, $igpm)) {
                            $pic = str_replace('\/', '/', $igpm[1]);
                            $found['profile_photo_url'] = $pic;
                            $log[] = 'Found profile photo from Instagram';
                        }
                    }
                    break;
                }
            }
            // Small delay to be polite
            usleep(300000); // 300ms
        }
        if (empty($found['instagram_url'])) {
            $log[] = 'No Instagram profile found (tried: ' . implode(', ', $ig_handles) . ')';
        }
    }

    // ══════════════════════════════════════════════════════════
    // STEP 4: If still no photo, try fetching the website directly
    // ══════════════════════════════════════════════════════════
    $website = $found['website_url'] ?? ($existing['website_url'] ?? '');
    if (empty($found['profile_photo_url']) && empty($existing['profile_photo_url']) && $website) {
        $log[] = 'Trying website for photo/logo: ' . $website;
        list($site_html, $site_code) = fetch_url($website, $curl_opts);
        if ($site_html && $site_code < 400 && strlen($site_html) > 500) {
            // Look for og:image or logo
            if (preg_match('#<meta[^>]+property=["\']og:image["\'][^>]+content=["\'](https?://[^"\']+)["\']#i', $site_html, $ogm)) {
                $found['profile_photo_url'] = html_entity_decode($ogm[1], ENT_QUOTES, 'UTF-8');
                $log[] = 'Found og:image from website';
            } elseif (preg_match('#<meta[^>]+content=["\'](https?://[^"\']+)["\'][^>]+property=["\']og:image["\']#i', $site_html, $ogm)) {
                $found['profile_photo_url'] = html_entity_decode($ogm[1], ENT_QUOTES, 'UTF-8');
                $log[] = 'Found og:image from website (alt order)';
            }

            // Logo from website
            if (empty($found['logo_url']) && empty($existing['logo_url'])) {
                if (preg_match('#<(?:img|link)[^>]+(?:logo|brand)[^>]+(?:src|href)=["\'](https?://[^"\']+)["\']#i', $site_html, $lgm)) {
                    $found['logo_url'] = html_entity_decode($lgm[1], ENT_QUOTES, 'UTF-8');
                    $log[] = 'Found logo from website';
                }
            }

            // Social links from the website itself
            $site_social = [
                'instagram_url' => '#href=["\'](https?://(?:www\.)?instagram\.com/[a-zA-Z0-9_.]+/?)["\']#i',
                'facebook_url'  => '#href=["\'](https?://(?:www\.)?(?:facebook|fb)\.com/[a-zA-Z0-9_./-]+/?)["\']#i',
                'linkedin_url'  => '#href=["\'](https?://(?:www\.)?linkedin\.com/(?:company|in)/[a-zA-Z0-9_./-]+/?)["\']#i',
                'tiktok_url'    => '#href=["\'](https?://(?:www\.)?tiktok\.com/@[a-zA-Z0-9_.]+/?)["\']#i',
            ];
            foreach ($site_social as $sk => $sp) {
                if (empty($existing[$sk]) && empty($found[$sk])) {
                    if (preg_match($sp, $site_html, $ssm)) {
                        $url = rtrim(html_entity_decode($ssm[1], ENT_QUOTES, 'UTF-8'), '/');
                        if (strpos($url, 'sharer') === false && strpos($url, '/login') === false) {
                            $found[$sk] = $url;
                            $log[] = 'Found ' . $sk . ' from website';
                        }
                    }
                }
            }
        } else {
            $log[] = 'Website fetch failed (HTTP ' . $site_code . ')';
        }
    }

    return [
        'found' => $found,
        'log' => $log,
        'fields_found' => count($found),
    ];
}

/**
 * Build likely social media handles from a business name.
 * Returns array of handles to try (lowercase, cleaned).
 */
function build_social_handles($name) {
    $handles = [];
    $clean = strtolower(trim($name));

    // Remove common Indonesian business prefixes/suffixes
    $clean = preg_replace('/^(toko|cv\.?|pt\.?|ud\.?)\s+/i', '', $clean);
    $clean = preg_replace('/\s+(lombok|bali|indonesia|ntb)$/i', '', $clean);

    // Version 1: just underscores
    $h1 = preg_replace('/[^a-z0-9]/', '_', $clean);
    $h1 = preg_replace('/_+/', '_', trim($h1, '_'));
    if (strlen($h1) >= 3) $handles[] = $h1;

    // Version 2: no separators
    $h2 = preg_replace('/[^a-z0-9]/', '', $clean);
    if (strlen($h2) >= 3 && $h2 !== $h1) $handles[] = $h2;

    // Version 3: dots instead of spaces
    $h3 = preg_replace('/\s+/', '.', trim(preg_replace('/[^a-z0-9\s]/', '', $clean)));
    if (strlen($h3) >= 3 && $h3 !== $h1 && $h3 !== $h2) $handles[] = $h3;

    // Version 4: original name with _lombok suffix
    if (strlen($h1) >= 3) $handles[] = $h1 . '_lombok';

    // Version 5: first word + "lombok"
    $words = explode(' ', $clean);
    if (count($words) > 1) {
        $fw = preg_replace('/[^a-z0-9]/', '', $words[0]);
        if (strlen($fw) >= 3) $handles[] = $fw . 'lombok';
    }

    return array_unique(array_slice($handles, 0, 4)); // max 4 attempts
}
