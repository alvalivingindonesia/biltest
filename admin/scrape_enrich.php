<?php
/**
 * Build in Lombok — Admin: Website Enrichment Scraper (AJAX endpoint)
 * 
 * Called via AJAX from the console edit forms.
 * Fetches a website URL and returns any missing data fields.
 * Only fills in fields that are currently empty.
 *
 * POST JSON: { "url": "https://...", "existing": { "description": "...", "profile_photo_url": "...", ... } }
 * Returns JSON: { "found": { "description": "...", "logo_url": "...", ... }, "log": [...] }
 */
session_start();
require_once('/home/rovin629/config/biltest_config.php');

header('Content-Type: application/json');

// Auth check
if (empty($_SESSION['admin_auth'])) {
    echo json_encode(['error' => 'Not authenticated']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$url = trim($input['url'] ?? '');
$existing = $input['existing'] ?? [];

if (!$url) {
    echo json_encode(['error' => 'No URL provided']);
    exit;
}

// Normalize URL
if (!preg_match('#^https?://#i', $url)) {
    $url = 'https://' . $url;
}

$log = [];
$found = [];

$curl_opts = [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS => 3,
    CURLOPT_TIMEOUT => 10,
    CURLOPT_CONNECTTIMEOUT => 6,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
    CURLOPT_HTTPHEADER => ['Accept: text/html,application/xhtml+xml', 'Accept-Language: en-US,en;q=0.9,id;q=0.8'],
];

// ── Fetch main page ──
$ch = curl_init();
curl_setopt_array($ch, [CURLOPT_URL => $url] + $curl_opts);
$html = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($html === false || $http_code >= 400 || strlen($html) < 200) {
    echo json_encode(['error' => 'Could not fetch website (HTTP ' . $http_code . ')', 'found' => [], 'log' => ['Failed to fetch main page']]);
    exit;
}

$log[] = 'Fetched main page (' . strlen($html) . ' bytes)';

// ── Extract social links ──
$social_patterns = [
    'instagram_url' => '#https?://(?:www\.)?instagram\.com/[a-zA-Z0-9_.]+/?#i',
    'facebook_url'  => '#https?://(?:www\.)?(?:facebook|fb)\.com/[a-zA-Z0-9_./-]+/?#i',
    'youtube_url'   => '#https?://(?:www\.)?youtube\.com/(?:c/|channel/|@)[a-zA-Z0-9_.-]+/?#i',
    'tiktok_url'    => '#https?://(?:www\.)?tiktok\.com/@[a-zA-Z0-9_.]+/?#i',
    'linkedin_url'  => '#https?://(?:www\.)?linkedin\.com/(?:company|in)/[a-zA-Z0-9_./-]+/?#i',
];

foreach ($social_patterns as $key => $pattern) {
    if (!empty($existing[$key])) continue; // already have it
    if (preg_match($pattern, $html, $sm)) {
        $found[$key] = rtrim($sm[0], '/');
        $log[] = 'Found ' . $key . ': ' . $found[$key];
    }
}

// Also scan href attributes for socials
preg_match_all('/href=["\']([^"\']+)["\']/i', $html, $href_matches);
foreach ($href_matches[1] as $href) {
    $href = html_entity_decode($href, ENT_QUOTES, 'UTF-8');
    if (empty($existing['instagram_url']) && empty($found['instagram_url']) && preg_match('#instagram\.com/[a-zA-Z0-9_.]+#i', $href)) {
        $found['instagram_url'] = (strpos($href, 'http') === 0) ? rtrim($href, '/') : 'https://' . ltrim($href, '/');
    }
    if (empty($existing['facebook_url']) && empty($found['facebook_url']) && preg_match('#(?:facebook|fb)\.com/[a-zA-Z0-9_./]+#i', $href) && strpos($href, 'sharer') === false) {
        $found['facebook_url'] = (strpos($href, 'http') === 0) ? rtrim($href, '/') : 'https://' . ltrim($href, '/');
    }
    if (empty($existing['youtube_url']) && empty($found['youtube_url']) && preg_match('#youtube\.com/(?:c/|channel/|@)[a-zA-Z0-9_.-]+#i', $href)) {
        $found['youtube_url'] = (strpos($href, 'http') === 0) ? rtrim($href, '/') : 'https://' . ltrim($href, '/');
    }
    if (empty($existing['tiktok_url']) && empty($found['tiktok_url']) && preg_match('#tiktok\.com/@[a-zA-Z0-9_.]+#i', $href)) {
        $found['tiktok_url'] = (strpos($href, 'http') === 0) ? rtrim($href, '/') : 'https://' . ltrim($href, '/');
    }
    if (empty($existing['linkedin_url']) && empty($found['linkedin_url']) && preg_match('#linkedin\.com/(?:company|in)/[a-zA-Z0-9_.-]+#i', $href)) {
        $found['linkedin_url'] = (strpos($href, 'http') === 0) ? rtrim($href, '/') : 'https://' . ltrim($href, '/');
    }
}

// ── WhatsApp from links ──
if (empty($existing['whatsapp_number'])) {
    foreach ($href_matches[1] as $href) {
        $href = html_entity_decode($href, ENT_QUOTES, 'UTF-8');
        if (preg_match('#wa\.me/(\d+)#i', $href, $wa_m)) {
            $found['whatsapp_number'] = '+' . $wa_m[1];
            $log[] = 'Found WhatsApp: ' . $found['whatsapp_number'];
            break;
        }
        if (preg_match('#whatsapp\.com/send\?phone=(\d+)#i', $href, $wa_m)) {
            $found['whatsapp_number'] = '+' . $wa_m[1];
            $log[] = 'Found WhatsApp: ' . $found['whatsapp_number'];
            break;
        }
    }
}

// ── Phone numbers from tel: links ──
if (empty($existing['phone'])) {
    foreach ($href_matches[1] as $href) {
        $href = html_entity_decode($href, ENT_QUOTES, 'UTF-8');
        if (preg_match('#^tel:([\+\d\s\-().]+)#i', $href, $tel_m)) {
            $phone_clean = trim($tel_m[1]);
            if (strlen(preg_replace('/[^\d]/', '', $phone_clean)) >= 8) {
                $found['phone'] = $phone_clean;
                $log[] = 'Found phone: ' . $found['phone'];
                break;
            }
        }
    }
}

// ── Email from mailto: links ──
if (empty($existing['email'])) {
    foreach ($href_matches[1] as $href) {
        $href = html_entity_decode($href, ENT_QUOTES, 'UTF-8');
        if (preg_match('#^mailto:([a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,})#i', $href, $em_m)) {
            $found['email'] = $em_m[1];
            $log[] = 'Found email: ' . $found['email'];
            break;
        }
    }
}

// ── Extract meta description ──
$meta_desc = '';
if (preg_match('/<meta[^>]+name=["\']description["\'][^>]+content=["\']([^"\']{10,500})["\']/i', $html, $dm)) {
    $meta_desc = html_entity_decode(trim($dm[1]), ENT_QUOTES, 'UTF-8');
} elseif (preg_match('/<meta[^>]+property=["\']og:description["\'][^>]+content=["\']([^"\']{10,500})["\']/i', $html, $dm)) {
    $meta_desc = html_entity_decode(trim($dm[1]), ENT_QUOTES, 'UTF-8');
}

// ── Extract About Us section from main page ──
$about_text = '';
// Method 1: section/div with about class/id
if (preg_match('/<(?:section|div|article)[^>]*(?:id|class)=["\'][^"\']*(?:about-us|about_us|about-section|about-content|aboutUs|about)[^"\']*["\'][^>]*>(.*?)<\/(?:section|div|article)>/si', $html, $about_m)) {
    $about_text = strip_tags(preg_replace('/<(script|style)[^>]*>.*?<\/\1>/si', '', $about_m[1]));
}
// Method 2: heading followed by paragraphs
if (!$about_text && preg_match('/<h[1-3][^>]*>[^<]*(?:About\s*Us|Tentang\s*Kami|Who\s*We\s*Are|Our\s*Story|About\s*the\s*Company|Company\s*Profile)[^<]*<\/h[1-3]>\s*((?:<p[^>]*>.*?<\/p>\s*){1,6})/si', $html, $about_m)) {
    $about_text = strip_tags($about_m[1]);
}
if ($about_text) {
    $about_text = trim(preg_replace('/\s+/', ' ', $about_text));
    $log[] = 'Found about text on main page (' . strlen($about_text) . ' chars)';
}

// ── Try /about page if no good description found ──
$parsed_url = parse_url($url);
$base = ($parsed_url['scheme'] ?? 'https') . '://' . ($parsed_url['host'] ?? '');

if (strlen($about_text) < 50) {
    $about_paths = ['/about', '/about-us', '/about.html', '/tentang-kami', '/company', '/our-story'];
    foreach ($about_paths as $about_path) {
        $ch2 = curl_init();
        curl_setopt_array($ch2, [CURLOPT_URL => $base . $about_path] + $curl_opts);
        $about_html = curl_exec($ch2);
        $about_code = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
        curl_close($ch2);
        if ($about_html && $about_code < 400 && strlen($about_html) > 500) {
            $log[] = 'Fetched ' . $about_path . ' page (' . strlen($about_html) . ' bytes)';
            // Clean out scripts/styles/nav/footer
            $cleaned = preg_replace('/<(script|style|nav|header|footer)[^>]*>.*?<\/\1>/si', '', $about_html);
            // Try to extract main content paragraphs
            preg_match_all('/<p[^>]*>(.{20,2000}?)<\/p>/si', $cleaned, $p_matches);
            if (!empty($p_matches[1])) {
                $paragraphs = array_map(function($p) { return trim(strip_tags($p)); }, $p_matches[1]);
                $paragraphs = array_filter($paragraphs, function($p) { return strlen($p) > 30 && !preg_match('/cookie|privacy|©|copyright|subscribe|newsletter/i', $p); });
                $combined = implode(' ', array_slice(array_values($paragraphs), 0, 5));
                if (strlen($combined) > strlen($about_text)) {
                    $about_text = trim($combined);
                    $log[] = 'Extracted about text from ' . $about_path . ' (' . strlen($about_text) . ' chars)';
                }
            }
            // Also scan about page for social links we may have missed
            preg_match_all('/href=["\']([^"\']+)["\']/i', $about_html, $about_hrefs);
            foreach ($about_hrefs[1] as $href) {
                $href = html_entity_decode($href, ENT_QUOTES, 'UTF-8');
                if (empty($existing['instagram_url']) && empty($found['instagram_url']) && preg_match('#instagram\.com/[a-zA-Z0-9_.]+#i', $href))
                    $found['instagram_url'] = (strpos($href, 'http') === 0) ? rtrim($href, '/') : 'https://' . ltrim($href, '/');
                if (empty($existing['facebook_url']) && empty($found['facebook_url']) && preg_match('#(?:facebook|fb)\.com/[a-zA-Z0-9_./]+#i', $href) && strpos($href, 'sharer') === false)
                    $found['facebook_url'] = (strpos($href, 'http') === 0) ? rtrim($href, '/') : 'https://' . ltrim($href, '/');
                if (empty($existing['linkedin_url']) && empty($found['linkedin_url']) && preg_match('#linkedin\.com/(?:company|in)/[a-zA-Z0-9_.-]+#i', $href))
                    $found['linkedin_url'] = (strpos($href, 'http') === 0) ? rtrim($href, '/') : 'https://' . ltrim($href, '/');
            }
            break; // Use first successful about page
        }
    }
}

// Fill description fields
if (empty($existing['description']) && $about_text) {
    $found['description'] = mb_substr($about_text, 0, 2000);
}
if (empty($existing['short_description']) && ($about_text || $meta_desc)) {
    $desc_source = $about_text ?: $meta_desc;
    if (strlen($desc_source) > 160) {
        $short = substr($desc_source, 0, 200);
        if (preg_match('/^(.{60,160}[.!?])\s/', $short, $sm)) {
            $desc_source = $sm[1];
        } else {
            $desc_source = substr($short, 0, 160) . '...';
        }
    }
    $found['short_description'] = $desc_source;
}
// Also fill profile_description (used by providers/developers)
if (empty($existing['profile_description']) && $about_text) {
    $found['profile_description'] = mb_substr($about_text, 0, 500);
}

// ── Extract logo ──
if (empty($existing['logo_url'])) {
    $logo_url = '';
    // Method 1: Link rel="icon" (favicon — not ideal but better than nothing)
    // Method 2: Look for img tags with class/id/alt containing "logo"
    if (preg_match('/<img[^>]+(?:class|id|alt)=["\'][^"\']*logo[^"\']*["\'][^>]+src=["\']([^"\']+)["\']/i', $html, $logo_m)) {
        $logo_url = html_entity_decode($logo_m[1], ENT_QUOTES, 'UTF-8');
    }
    // Method 3: img with src containing "logo"
    if (!$logo_url && preg_match('/<img[^>]+src=["\']([^"\']*logo[^"\']+)["\']/i', $html, $logo_m)) {
        $logo_url = html_entity_decode($logo_m[1], ENT_QUOTES, 'UTF-8');
    }
    // Method 4: CSS background with logo in class
    if (!$logo_url && preg_match('/class=["\'][^"\']*logo[^"\']*["\'][^>]*style=["\'][^"\']*url\(["\']?([^"\')\s]+)/i', $html, $logo_m)) {
        $logo_url = html_entity_decode($logo_m[1], ENT_QUOTES, 'UTF-8');
    }
    // Method 5: Link with rel containing "icon" (site icon as fallback)
    if (!$logo_url && preg_match('/<link[^>]+rel=["\'][^"\']*(?:apple-touch-icon|icon)[^"\']*["\'][^>]+href=["\']([^"\']+)["\']/i', $html, $logo_m)) {
        $candidate = html_entity_decode($logo_m[1], ENT_QUOTES, 'UTF-8');
        // Only use favicons > 32px (apple-touch-icon is usually 180px)
        if (preg_match('/apple-touch-icon/i', $logo_m[0]) || preg_match('/sizes=["\']\d{3}/i', $logo_m[0])) {
            $logo_url = $candidate;
        }
    }
    if ($logo_url) {
        // Normalize relative URLs
        if (strpos($logo_url, '//') === 0) $logo_url = 'https:' . $logo_url;
        elseif (strpos($logo_url, '/') === 0) $logo_url = $base . $logo_url;
        elseif (strpos($logo_url, 'http') !== 0) $logo_url = $base . '/' . $logo_url;
        $found['logo_url'] = $logo_url;
        $log[] = 'Found logo: ' . $logo_url;
    }
}

// ── Extract profile photo / OG image ──
if (empty($existing['profile_photo_url'])) {
    $img_url = '';
    // Priority 1: og:image
    if (preg_match('/<meta[^>]+property=["\']og:image["\'][^>]+content=["\']([^"\']+)["\']/i', $html, $im)) {
        $img_url = html_entity_decode(trim($im[1]), ENT_QUOTES, 'UTF-8');
    }
    // Priority 2: twitter:image
    if (!$img_url && preg_match('/<meta[^>]+name=["\']twitter:image["\'][^>]+content=["\']([^"\']+)["\']/i', $html, $im)) {
        $img_url = html_entity_decode(trim($im[1]), ENT_QUOTES, 'UTF-8');
    }
    // Priority 3: First suitable large image
    if (!$img_url) {
        preg_match_all('/<img[^>]+>/i', $html, $img_tags);
        foreach ($img_tags[0] as $img_tag) {
            if (!preg_match('/src=["\']([^"\']+)["\']/i', $img_tag, $src_m)) continue;
            $candidate = html_entity_decode($src_m[1], ENT_QUOTES, 'UTF-8');
            // Skip SVGs, data URIs, tiny icons, tracking pixels
            if (preg_match('/^data:|\\.svg|pixel|track|spacer|blank|1x1|favicon|icon|logo/i', $candidate)) continue;
            if (preg_match('/width=["\']?(\d+)/i', $img_tag, $wm) && (int)$wm[1] < 80) continue;
            if (preg_match('/height=["\']?(\d+)/i', $img_tag, $hm) && (int)$hm[1] < 80) continue;
            $img_url = $candidate;
            break;
        }
    }
    if ($img_url) {
        if (strpos($img_url, '//') === 0) $img_url = 'https:' . $img_url;
        elseif (strpos($img_url, '/') === 0) $img_url = $base . $img_url;
        elseif (strpos($img_url, 'http') !== 0) $img_url = $base . '/' . $img_url;
        $found['profile_photo_url'] = $img_url;
        $log[] = 'Found profile image: ' . $img_url;
    }
}

// For agents: also map to profile_image_url
if (empty($existing['profile_image_url']) && !empty($found['profile_photo_url'])) {
    $found['profile_image_url'] = $found['profile_photo_url'];
}

echo json_encode([
    'found' => $found,
    'log' => $log,
    'fields_found' => count($found),
]);
