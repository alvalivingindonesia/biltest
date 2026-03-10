<?php
/**
 * Build in Lombok — Admin: Property Listing Importer
 *
 * Paste-based importer for Rumah123.com and other property sites.
 * User views page source in their browser and pastes it here.
 *
 * Place at: /admin/scrape_listings.php
 * SECURITY: Not linked from any menu. Access via direct URL only.
 */

session_start();
require_once('/home/rovin629/config/biltest_config.php');

// ─── AUTH CHECK ──────────────────────────────────────────────────────
$auth_error = '';
if (isset($_POST['login'])) {
    $u = isset($_POST['username']) ? $_POST['username'] : '';
    $p = isset($_POST['password']) ? $_POST['password'] : '';
    if ($u === ADMIN_USER && $p === ADMIN_PASS) {
        $_SESSION['admin_auth'] = true;
    } else {
        $auth_error = 'Invalid credentials.';
    }
}
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: scrape_listings.php');
    exit;
}
if (empty($_SESSION['admin_auth'])) {
    show_login($auth_error);
    exit;
}

// ─── DATABASE ────────────────────────────────────────────────────────
function get_db() {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
        $pdo = new PDO($dsn, DB_USER, DB_PASS, array(
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ));
    }
    return $pdo;
}

// ─── HELPER: Make slug ───────────────────────────────────────────────
function make_slug($text) {
    $text = strtolower(trim($text));
    $text = preg_replace('/[^a-z0-9\s-]/', '', $text);
    $text = preg_replace('/[\s-]+/', '-', $text);
    return substr($text, 0, 150);
}

// ─── AREA DETECTION ──────────────────────────────────────────────────
function detect_area_key($district, $title, $description) {
    $combined = strtolower($district . ' ' . $title . ' ' . $description);

    $area_map = array(
        'kuta' => array('kuta', 'mandalika', 'tanjung aan', 'tanjung_aan', 'tampah'),
        'selong_belanak' => array('selong belanak', 'selong_belanak', 'belanak'),
        'ekas' => array('ekas', 'east lombok', 'jerowaru'),
        'senggigi' => array('senggigi', 'batu layar', 'batulayar', 'batu bolong'),
        'mataram' => array('mataram', 'cakranegara', 'ampenan', 'sekarbela', 'sandubaya'),
        'north_lombok' => array('north lombok', 'tanjung', 'gangga', 'bayan', 'kayangan', 'senaru'),
        'gili_islands' => array('gili', 'gili trawangan', 'gili air', 'gili meno'),
        'mawi' => array('mawi'),
        'are_guling' => array('are guling', 'are_guling'),
        'gerupuk' => array('gerupuk'),
        'sekotong' => array('sekotong'),
        'praya' => array('praya', 'lombok tengah', 'central lombok'),
        'other_lombok' => array('lombok'),
    );

    $priority_order = array('kuta', 'selong_belanak', 'mawi', 'are_guling', 'gerupuk', 'ekas',
                           'senggigi', 'mataram', 'north_lombok', 'gili_islands', 'sekotong', 'praya', 'other_lombok');

    foreach ($priority_order as $area_key) {
        if (isset($area_map[$area_key])) {
            foreach ($area_map[$area_key] as $keyword) {
                if (strpos($combined, $keyword) !== false) {
                    return $area_key;
                }
            }
        }
    }

    return 'praya';
}

// ─── DETECT LOCATION DETAIL ─────────────────────────────────────────
function detect_location_detail($district, $title, $description) {
    $combined = strtolower($title . ' ' . $description);

    $locations = array(
        'Kuta Mandalika' => array('kuta mandalika', 'kuta lombok'),
        'Kuta' => array('kuta'),
        'Selong Belanak' => array('selong belanak', 'belanak'),
        'Are Guling' => array('are guling'),
        'Mawi' => array('mawi'),
        'Mawun' => array('mawun'),
        'Gerupuk' => array('gerupuk'),
        'Tanjung Aan' => array('tanjung aan'),
        'Ekas' => array('ekas'),
        'Tampah' => array('tampah'),
        'Tampah Hills' => array('tampah hills'),
        'Torok' => array('torok'),
        'Seger' => array('seger'),
        'Telong-Elong' => array('telong'),
        'Bumbang' => array('bumbang'),
        'Areguling' => array('areguling'),
        'Prabu' => array('prabu'),
        'Setangi' => array('setangi'),
        'Penujak' => array('penujak'),
        'Batujai' => array('batujai'),
        'Sade' => array('sade'),
        'Rambitan' => array('rambitan'),
        'Pujut' => array('pujut'),
        'Praya' => array('praya'),
        'Sengkol' => array('sengkol'),
        'Batu Jangkih' => array('batu jangkih', 'batujangkih'),
        'Mertak' => array('mertak'),
        'Pengembur' => array('pengembur'),
        'Batukliang' => array('batukliang'),
        'Jonggat' => array('jonggat'),
        'Kopang' => array('kopang'),
        'Janapria' => array('janapria'),
        'Jerowaru' => array('jerowaru'),
    );

    foreach ($locations as $label => $keywords) {
        foreach ($keywords as $kw) {
            if (strpos($combined, $kw) !== false) {
                return $label;
            }
        }
    }

    if ($district) {
        return ucwords(strtolower(str_replace(array('-', '_'), ' ', $district)));
    }

    return 'Lombok Tengah';
}

// ─── DETECT LISTING TYPE ────────────────────────────────────────────
function detect_listing_type($title, $property_type_text) {
    $combined = strtolower($title . ' ' . $property_type_text);
    if (strpos($combined, 'villa') !== false) return 'villa';
    if (strpos($combined, 'rumah') !== false || strpos($combined, 'house') !== false) return 'house';
    if (strpos($combined, 'apartment') !== false || strpos($combined, 'apartemen') !== false) return 'apartment';
    if (strpos($combined, 'tanah') !== false || strpos($combined, 'land') !== false) return 'land';
    if (strpos($combined, 'ruko') !== false || strpos($combined, 'komersial') !== false) return 'commercial';
    return 'land';
}

// ─── DETECT CERTIFICATE TYPE ────────────────────────────────────────
function detect_certificate($text) {
    $text = strtolower($text);
    if (strpos($text, 'shm') !== false || strpos($text, 'hak milik') !== false) return 'shm';
    if (strpos($text, 'hgb') !== false || strpos($text, 'hak guna') !== false) return 'hgb';
    if (strpos($text, 'hak pakai') !== false) return 'hak_pakai';
    if (strpos($text, 'girik') !== false || strpos($text, 'letter c') !== false) return 'girik';
    if (strpos($text, 'adat') !== false) return 'adat';
    return null;
}

// ─── PARSE PRICE ────────────────────────────────────────────────────
function parse_price_idr($price_text) {
    $price_text = strtolower(trim($price_text));
    $price_text = str_replace(array('.', ','), '', $price_text);
    $price_text = preg_replace('/\s+/', ' ', $price_text);
    $price_text = preg_replace('/^rp\s*/', '', $price_text);

    $number = 0;
    if (preg_match('/(\d+)\s*miliar/', $price_text, $m)) {
        $number = intval($m[1]) * 1000000000;
    } elseif (preg_match('/(\d+)\s*juta/', $price_text, $m)) {
        $number = intval($m[1]) * 1000000;
    } elseif (preg_match('/(\d+)/', $price_text, $m)) {
        $number = intval($m[1]);
        if ($number < 1000) {
            $number = $number * 1000000;
        }
    }

    return $number > 0 ? $number : null;
}

function idr_to_usd($idr) {
    if (!$idr) return null;
    $rate = 15800;
    return (int)round($idr / $rate);
}


// ═══════════════════════════════════════════════════════════════════
// EXTRACT LISTINGS FROM PASTED HTML
// Rumah123 uses Next.js App Router with RSC (React Server Components).
// Listing data is in self.__next_f.push() flight data chunks.
// ═══════════════════════════════════════════════════════════════════

function extract_rsc_listings($html_source) {
    // Step 1: Extract all self.__next_f.push() chunks
    $chunks = array();
    if (preg_match_all('/self\.__next_f\.push\(\[1,"(.*?)"\]\)/s', $html_source, $matches)) {
        $chunks = $matches[1];
    }

    if (empty($chunks)) {
        return null;
    }

    // Step 2: Combine and unescape the RSC payload
    $combined = implode('', $chunks);
    // Unescape JSON string escaping (the chunks are double-escaped)
    $combined = str_replace('\\"', '"', $combined);
    $combined = str_replace('\\\\', '\\', $combined);
    $combined = str_replace('\\n', "\n", $combined);
    $combined = str_replace('\\r', "\r", $combined);

    // Step 3: Find listing objects by their unique structure
    // Each listing has: {"slug":"/properti/...", ... "originId":{"formattedValue":"las..."},...}
    $listings = array();
    $offset = 0;

    while (($pos = strpos($combined, '{"slug":"/properti/', $offset)) !== false) {
        // Parse the JSON object by counting braces
        $depth = 0;
        $end = $pos;
        $len = strlen($combined);
        for ($i = $pos; $i < $len && $i < $pos + 15000; $i++) {
            $ch = $combined[$i];
            if ($ch === '{') {
                $depth++;
            } elseif ($ch === '}') {
                $depth--;
                if ($depth === 0) {
                    $end = $i + 1;
                    break;
                }
            }
        }

        if ($depth !== 0) {
            // Couldn't balance braces — skip
            $offset = $pos + 20;
            continue;
        }

        $json_str = substr($combined, $pos, $end - $pos);

        // Try to decode as JSON
        $obj = json_decode($json_str, true);
        if ($obj !== null && isset($obj['originId']['formattedValue']) && isset($obj['title'])) {
            $listings[] = $obj;
        }

        $offset = $end;
    }

    return $listings;
}


// Fallback: extract from rendered HTML when RSC data is not available
function extract_html_listings($html_source) {
    $listings = array();

    // Find all listing links
    if (!preg_match_all('/href="(\/properti\/lombok-tengah[^"]+-(las\d+|hos\d+)\/?)"/', $html_source, $link_matches)) {
        return $listings;
    }

    $seen_ids = array();
    foreach ($link_matches[1] as $idx => $url) {
        $source_id = $link_matches[2][$idx];
        if (in_array($source_id, $seen_ids)) continue;
        $seen_ids[] = $source_id;

        // Build a basic listing from whatever HTML context we can find
        $listing = array(
            'slug' => $url,
            'originId' => array('formattedValue' => $source_id),
            'url' => $url,
            'title' => '',
            'price' => array('display' => '', 'offer' => 0),
            'location' => array('text' => '', 'district' => array('name' => '')),
            'attributes' => array('landSize' => null, 'certification' => null, 'bedrooms' => null, 'bathrooms' => null, 'buildingSize' => null),
            'medias' => array(),
            'agent' => null,
            'propertyType' => array('formattedValue' => 'Tanah'),
            'shortDescription' => '',
        );

        // Try to extract title from nearby h2/h3 tag
        $title_pattern = '/title="([^"]+)"[^>]*>\s*<h[23][^>]*>[^<]*<\/h[23]>.*?' . preg_quote($source_id, '/') . '/';
        if (preg_match('/title="([^"]+)"[^>]*>[^<]*' . preg_quote($source_id, '/') . '/', $html_source, $tm)) {
            $listing['title'] = html_entity_decode($tm[1], ENT_QUOTES, 'UTF-8');
        }

        if ($listing['title']) {
            $listings[] = $listing;
        }
    }

    return $listings;
}


// ═══════════════════════════════════════════════════════════════════
// PARSE ONE RSC LISTING OBJECT
// ═══════════════════════════════════════════════════════════════════

function parse_rsc_listing($item) {
    $data = array(
        'source_url' => '',
        'source_listing_id' => '',
        'title' => '',
        'price_display' => '',
        'price_idr' => null,
        'price_usd' => null,
        'price_label' => '',
        'price_idr_per_sqm' => null,
        'listing_type_key' => 'land',
        'land_size_sqm' => null,
        'building_size_sqm' => null,
        'bedrooms' => null,
        'bathrooms' => null,
        'certificate_type_key' => null,
        'district' => '',
        'description' => '',
        'short_description' => '',
        'location_detail' => '',
        'area_key' => 'praya',
        'photos' => array(),
        'agent_name' => '',
        'agent_photo_url' => '',
        'agent_type' => '',
        'agent_phone' => '',
        'agent_profile_url' => '',
        'agent_source_id' => '',
        'agent_verified' => false,
    );

    // ─── ID & URL ─────────────────────────────────────────
    $id = isset($item['originId']['formattedValue']) ? $item['originId']['formattedValue'] : '';
    $data['source_listing_id'] = $id;

    // Skip housing estate / perumahan-baru listings (nps prefix)
    if (strpos($id, 'nps') === 0) {
        return null;
    }

    // Build source URL from slug or url field
    $slug = isset($item['slug']) ? $item['slug'] : '';
    $item_url = isset($item['url']) ? $item['url'] : $slug;
    if ($item_url) {
        if (strpos($item_url, 'http') !== 0) {
            $item_url = 'https://www.rumah123.com' . $item_url;
        }
        $data['source_url'] = $item_url;
    }

    // Skip perumahan-baru URLs (housing estates)
    if (strpos($slug, 'perumahan-baru') !== false || strpos($data['source_url'], 'perumahan-baru') !== false) {
        return null;
    }

    // ─── Title ────────────────────────────────────────────
    $data['title'] = isset($item['title']) ? trim($item['title']) : '';
    if (!$data['title']) return null;

    // ─── Price ────────────────────────────────────────────
    // RSC structure: price.display ("Rp 275 Juta /are"), price.offer (total IDR)
    $price = isset($item['price']) ? $item['price'] : array();
    if (is_array($price)) {
        $price_display_text = isset($price['display']) ? $price['display'] : '';
        $price_offer = isset($price['offer']) ? intval($price['offer']) : 0;
        $price_total_display = isset($price['totalDisplay']) ? $price['totalDisplay'] : '';
        $price_per_meter = isset($price['offerLandPerMeter']) ? intval($price['offerLandPerMeter']) : 0;

        // The "offer" field is the total price in IDR
        if ($price_offer > 0) {
            $data['price_idr'] = $price_offer;
        } elseif ($price_total_display) {
            $data['price_idr'] = parse_price_idr($price_total_display);
        } elseif ($price_display_text) {
            $data['price_idr'] = parse_price_idr($price_display_text);
        }

        // Price label from display text
        if (strpos($price_display_text, '/are') !== false) {
            $data['price_label'] = 'Per Are';
        } elseif (strpos($price_display_text, '/m') !== false) {
            $data['price_label'] = 'Per m²';
        } else {
            $data['price_label'] = 'Total';
        }

        // Use the display price text
        if ($price_display_text) {
            $data['price_display'] = $price_display_text;
        } elseif ($data['price_idr']) {
            $data['price_display'] = 'Rp ' . number_format($data['price_idr'], 0, ',', '.');
        } else {
            $data['price_display'] = 'Price on request';
        }

        // Price per sqm
        if ($price_per_meter > 0) {
            $data['price_idr_per_sqm'] = $price_per_meter;
        }
    }

    $data['price_usd'] = idr_to_usd($data['price_idr']);

    // ─── Land & Building Size ──────────────────────────────
    $attrs = isset($item['attributes']) ? $item['attributes'] : array();

    if (isset($attrs['landSize']['value'])) {
        $data['land_size_sqm'] = intval($attrs['landSize']['value']);
    }
    if (isset($attrs['buildingSize']['value'])) {
        $data['building_size_sqm'] = intval($attrs['buildingSize']['value']);
    }

    // Price per sqm (calculate if we have total price and land size)
    if ($data['price_idr'] && $data['land_size_sqm'] && $data['land_size_sqm'] > 0 && $data['price_label'] === 'Total') {
        $data['price_idr_per_sqm'] = intval($data['price_idr'] / $data['land_size_sqm']);
    }

    // ─── Bedrooms & Bathrooms ──────────────────────────────
    if (isset($attrs['bedrooms']['value'])) {
        $data['bedrooms'] = intval($attrs['bedrooms']['value']);
    }
    if (isset($attrs['bathrooms']['value'])) {
        $data['bathrooms'] = intval($attrs['bathrooms']['value']);
    }

    // ─── Certificate ──────────────────────────────────────
    if (isset($attrs['certification']['formattedValue'])) {
        $cert_text = $attrs['certification']['formattedValue'];
        $data['certificate_type_key'] = detect_certificate($cert_text);
    }

    // ─── Property Type ────────────────────────────────────
    $prop_type = '';
    if (isset($item['propertyType']['formattedValue'])) {
        $prop_type = $item['propertyType']['formattedValue'];
    } elseif (isset($item['propertyType']) && is_string($item['propertyType'])) {
        $prop_type = $item['propertyType'];
    }
    $data['listing_type_key'] = detect_listing_type($data['title'], $prop_type);

    // ─── Location ─────────────────────────────────────────
    $district = '';
    if (isset($item['location']['district']['name'])) {
        $district = $item['location']['district']['name'];
    } elseif (isset($item['location']['text'])) {
        $district = $item['location']['text'];
    }
    $data['district'] = $district;

    // ─── Description ──────────────────────────────────────
    $desc = '';
    if (isset($item['shortDescription'])) {
        $desc = strip_tags($item['shortDescription']);
        $desc = preg_replace('/\s+/', ' ', trim($desc));
    } elseif (isset($item['description'])) {
        $desc = strip_tags($item['description']);
        $desc = preg_replace('/\s+/', ' ', trim($desc));
    }
    $data['description'] = $desc;
    $data['short_description'] = $desc ? mb_substr($desc, 0, 200) : $data['title'];

    // Location & area detection
    $data['area_key'] = detect_area_key($data['district'], $data['title'], $data['description']);
    $data['location_detail'] = detect_location_detail($data['district'], $data['title'], $data['description']);

    // ─── Photos ───────────────────────────────────────────
    // RSC structure: medias[].mediaInfo[].mediaUrl
    $photos = array();
    if (isset($item['medias']) && is_array($item['medias'])) {
        foreach ($item['medias'] as $media) {
            if (isset($media['mediaInfo']) && is_array($media['mediaInfo'])) {
                foreach ($media['mediaInfo'] as $mi) {
                    $url = isset($mi['mediaUrl']) ? $mi['mediaUrl'] : '';
                    if (!$url && isset($mi['formatUrl'])) {
                        // formatUrl is a template like "https://.../{width}x{height}-{type}/..."
                        $url = str_replace(array('{width}', '{height}', '{type}'), array('720', '420', 'crop'), $mi['formatUrl']);
                    }
                    if ($url) {
                        if (strpos($url, 'http') !== 0) {
                            $url = 'https:' . $url;
                        }
                        $photos[] = $url;
                    }
                    if (count($photos) >= 3) break;
                }
            }
            if (count($photos) >= 3) break;
        }
    }
    $data['photos'] = $photos;

    // ─── Agent / Developer Info ────────────────────────────
    $agent = isset($item['agent']) ? $item['agent'] : null;

    if ($agent && is_array($agent)) {
        $data['agent_name'] = isset($agent['name']) ? trim($agent['name']) : '';

        // Agent ID from originId
        if (isset($agent['originId'])) {
            $data['agent_source_id'] = $agent['originId'];
        } elseif (isset($agent['id'])) {
            $data['agent_source_id'] = $agent['id'];
        }

        // Agent photo from medias[].mediaInfo[].mediaUrl
        if (isset($agent['medias']) && is_array($agent['medias'])) {
            foreach ($agent['medias'] as $am) {
                if (isset($am['mediaInfo']) && is_array($am['mediaInfo'])) {
                    foreach ($am['mediaInfo'] as $ami) {
                        if (isset($ami['mediaUrl']) && $ami['mediaUrl']) {
                            $aurl = $ami['mediaUrl'];
                            if (strpos($aurl, 'http') !== 0) $aurl = 'https:' . $aurl;
                            $data['agent_photo_url'] = $aurl;
                            break 2;
                        }
                    }
                }
            }
        }

        // Phone/WhatsApp from contacts[] array
        if (isset($agent['contacts']) && is_array($agent['contacts'])) {
            foreach ($agent['contacts'] as $contact) {
                $ctype = isset($contact['type']) ? strtolower($contact['type']) : '';
                $cval = isset($contact['value']) ? $contact['value'] : '';
                if ($cval && ($ctype === 'phone' || $ctype === 'whatsapp' || $ctype === 'mobile')) {
                    $data['agent_phone'] = $cval;
                    break;
                }
            }
        }

        // Agent type (marketerType)
        if (isset($agent['marketerType']['value'])) {
            $data['agent_type'] = $agent['marketerType']['value'];
        } elseif (isset($agent['marketerType']['id'])) {
            $data['agent_type'] = $agent['marketerType']['id'];
        }
    }

    return $data;
}


// ═══════════════════════════════════════════════════════════════════
// UPSERT AGENT
// ═══════════════════════════════════════════════════════════════════

function upsert_agent($db, $data) {
    $source_id = $data['agent_source_id'];
    if (!$source_id && $data['agent_name']) {
        $source_id = 'name_' . md5(strtolower($data['agent_name']));
    }
    if (!$source_id) return null;

    $stmt = $db->prepare("SELECT id FROM agents WHERE source_site = 'rumah123' AND source_agent_id = ?");
    $stmt->execute(array($source_id));
    $existing = $stmt->fetch();

    if ($existing) {
        if ($data['agent_phone']) {
            $db->prepare("UPDATE agents SET phone = COALESCE(NULLIF(phone, ''), ?) WHERE id = ?")->execute(array($data['agent_phone'], $existing['id']));
        }
        return intval($existing['id']);
    }

    $slug = make_slug($data['agent_name']);
    $slug_check = $db->prepare("SELECT COUNT(*) FROM agents WHERE slug = ?");
    $slug_check->execute(array($slug));
    if ($slug_check->fetchColumn() > 0) {
        $slug = $slug . '-' . substr(md5($source_id), 0, 6);
    }

    $ins = $db->prepare(
        "INSERT INTO agents (user_id, slug, display_name, agency_name, bio, profile_photo_url, phone, whatsapp_number, email, website_url,
                             areas_served, languages, is_verified, is_active, source_site, source_agent_id, source_profile_url, is_trusted, agent_type)
         VALUES (NULL, ?, ?, NULL, NULL, ?, ?, ?, NULL, NULL, 'lombok_tengah', 'Bahasa, English', ?, 1, 'rumah123', ?, ?, ?, ?)"
    );
    $ins->execute(array(
        $slug,
        $data['agent_name'],
        $data['agent_photo_url'] ? $data['agent_photo_url'] : null,
        $data['agent_phone'] ? $data['agent_phone'] : null,
        $data['agent_phone'] ? $data['agent_phone'] : null,
        $data['agent_verified'] ? 1 : 0,
        $source_id,
        $data['agent_profile_url'] ? $data['agent_profile_url'] : null,
        $data['agent_verified'] ? 1 : 0,
        $data['agent_type'] ? $data['agent_type'] : null,
    ));

    return intval($db->lastInsertId());
}


// ═══════════════════════════════════════════════════════════════════
// INSERT LISTING
// ═══════════════════════════════════════════════════════════════════

function insert_listing($db, $data, $agent_id) {
    $slug = make_slug($data['title']);

    $slug_check = $db->prepare("SELECT COUNT(*) FROM listings WHERE slug = ?");
    $slug_check->execute(array($slug));
    if ($slug_check->fetchColumn() > 0) {
        $slug = $slug . '-' . substr(md5($data['source_listing_id'] . time()), 0, 6);
    }

    $photo_urls_json = !empty($data['photos']) ? json_encode(array_values($data['photos'])) : null;

    try {
        $ins = $db->prepare(
            "INSERT INTO listings (slug, agent_id, listing_type_key, status, title, short_description, description,
                                   area_key, location_detail, price_idr, price_usd, price_label, price_idr_per_sqm,
                                   land_size_sqm, land_size_are, certificate_type_key,
                                   building_size_sqm, bedrooms, bathrooms,
                                   is_featured, is_approved, source_site, source_url, source_listing_id, source_scraped_at,
                                   photo_urls)
             VALUES (?, ?, ?, 'active', ?, ?, ?,
                     ?, ?, ?, ?, ?, ?,
                     ?, ?, ?,
                     ?, ?, ?,
                     0, 1, 'rumah123', ?, ?, NOW(),
                     ?)"
        );

        $land_size_are = $data['land_size_sqm'] ? round($data['land_size_sqm'] / 100, 2) : null;

        $params = array(
            $slug,
            $agent_id,
            $data['listing_type_key'],
            $data['title'],
            $data['short_description'],
            $data['description'],
            $data['area_key'],
            $data['location_detail'],
            $data['price_idr'],
            $data['price_usd'],
            $data['price_label'] ? $data['price_label'] : null,
            $data['price_idr_per_sqm'],
            $data['land_size_sqm'],
            $land_size_are,
            $data['certificate_type_key'],
            $data['building_size_sqm'],
            $data['bedrooms'],
            $data['bathrooms'],
            $data['source_url'],
            $data['source_listing_id'],
            $photo_urls_json,
        );

        $ins->execute($params);
        return 'inserted';
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate') !== false) {
            return 'duplicate';
        }
        return 'error: ' . $e->getMessage();
    }
}


// ═══════════════════════════════════════════════════════════════════
// HANDLE AJAX IMPORT REQUEST
// ═══════════════════════════════════════════════════════════════════

if (isset($_POST['action']) && $_POST['action'] === 'import_paste') {
    header('Content-Type: application/json');

    $source = isset($_POST['html_source']) ? $_POST['html_source'] : '';
    $max_listings = max(1, min(2000, intval(isset($_POST['max_listings']) ? $_POST['max_listings'] : 30)));
    $search_type = isset($_POST['search_type']) ? $_POST['search_type'] : 'all';

    if (strlen($source) < 100) {
        echo json_encode(array('success' => false, 'error' => 'Pasted content is too short. Make sure you copy the full page source.'));
        exit;
    }

    // Extract listings from RSC flight data
    $raw_listings = extract_rsc_listings($source);

    // Fallback: try HTML extraction if RSC parsing found nothing
    if (empty($raw_listings)) {
        $raw_listings = extract_html_listings($source);
    }

    if (empty($raw_listings)) {
        echo json_encode(array('success' => false, 'error' => 'Could not find any listings in the pasted content. Make sure you right-click the Rumah123 search results page and choose "View Page Source", then Select All and Copy everything.'));
        exit;
    }

    // No pagination from RSC — user pastes one page at a time
    $pagination = array('currentPage' => 1, 'totalPages' => 1);

    $db = get_db();
    $results = array();
    $imported = 0;
    $updated = 0;
    $skipped = 0;
    $errors = 0;
    $skipped_estates = 0;

    foreach ($raw_listings as $item) {
        if ($imported + $updated >= $max_listings) break;

        $data = parse_rsc_listing($item);
        if (!$data) {
            $skipped_estates++;
            continue;
        }

        // Filter by search type
        if ($search_type === 'land' && !in_array($data['listing_type_key'], array('land'))) {
            $skipped++;
            $results[] = array('status' => 'skip_type', 'title' => $data['title'], 'msg' => 'Not land — skipped');
            continue;
        }
        if ($search_type === 'houses' && !in_array($data['listing_type_key'], array('house', 'villa', 'apartment'))) {
            $skipped++;
            $results[] = array('status' => 'skip_type', 'title' => $data['title'], 'msg' => 'Not house/villa — skipped');
            continue;
        }

        $source_id = $data['source_listing_id'];

        // Check for existing listing
        $existing = null;
        if ($source_id) {
            $stmt = $db->prepare("SELECT id, price_idr FROM listings WHERE source_site = 'rumah123' AND source_listing_id = ?");
            $stmt->execute(array($source_id));
            $existing = $stmt->fetch();
        }

        if ($existing) {
            // Update if new price is lower
            $new_price = $data['price_idr'];
            $old_price = intval($existing['price_idr']);
            if ($new_price && $old_price && $new_price < $old_price) {
                $upd = $db->prepare("UPDATE listings SET price_idr = ?, price_usd = ?, price_idr_per_sqm = ?, updated_at = NOW() WHERE id = ?");
                $upd->execute(array($new_price, idr_to_usd($new_price), $data['price_idr_per_sqm'], $existing['id']));
                $updated++;
                $results[] = array('status' => 'updated', 'title' => $data['title'], 'msg' => 'Price updated (lower)');
            } else {
                $skipped++;
                $results[] = array('status' => 'exists', 'title' => $data['title'], 'msg' => 'Already in database');
            }
            continue;
        }

        // Handle agent
        $agent_id = null;
        if (!empty($data['agent_name'])) {
            $agent_id = upsert_agent($db, $data);
        }

        // Insert listing
        $result = insert_listing($db, $data, $agent_id);
        if ($result === 'inserted') {
            $imported++;
            $results[] = array(
                'status' => 'imported',
                'title' => $data['title'],
                'price' => $data['price_display'],
                'location' => $data['location_detail'],
                'size' => $data['land_size_sqm'] ? number_format($data['land_size_sqm']) . ' m²' : '-',
                'type' => $data['listing_type_key'],
                'agent' => $data['agent_name'] ? $data['agent_name'] : '-',
                'photos' => count($data['photos']),
                'msg' => 'Imported'
            );
        } elseif ($result === 'duplicate') {
            $skipped++;
            $results[] = array('status' => 'exists', 'title' => $data['title'], 'msg' => 'Duplicate');
        } else {
            $errors++;
            $results[] = array('status' => 'error', 'title' => $data['title'], 'msg' => $result);
        }
    }

    echo json_encode(array(
        'success' => true,
        'imported' => $imported,
        'updated' => $updated,
        'skipped' => $skipped,
        'skipped_estates' => $skipped_estates,
        'errors' => $errors,
        'total_in_page' => count($raw_listings),
        'pagination' => $pagination,
        'results' => $results
    ));
    exit;
}


// ═══════════════════════════════════════════════════════════════════
// HANDLE CLEAR RUMAH123 DATA REQUEST
// ═══════════════════════════════════════════════════════════════════

if (isset($_POST['action']) && $_POST['action'] === 'clear_rumah123') {
    header('Content-Type: application/json');
    $db = get_db();
    $stmt = $db->prepare("DELETE FROM listings WHERE source_site = 'rumah123'");
    $stmt->execute();
    $deleted = $stmt->rowCount();
    echo json_encode(array('success' => true, 'deleted' => $deleted));
    exit;
}

// ═══════════════════════════════════════════════════════════════════
// HANDLE COUNT REQUEST
// ═══════════════════════════════════════════════════════════════════

if (isset($_POST['action']) && $_POST['action'] === 'get_counts') {
    header('Content-Type: application/json');
    $db = get_db();
    $stmt = $db->query("SELECT COUNT(*) as cnt FROM listings WHERE source_site = 'rumah123'");
    $row = $stmt->fetch();
    echo json_encode(array('success' => true, 'count' => intval($row['cnt'])));
    exit;
}


// ═══════════════════════════════════════════════════════════════════
// HTML PAGE
// ═══════════════════════════════════════════════════════════════════
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<meta name="robots" content="noindex,nofollow">
<title>Property Importer — Build in Lombok Admin</title>
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',system-ui,sans-serif;background:#0f172a;color:#e2e8f0;min-height:100vh}

.header{background:linear-gradient(135deg,#0c7c84,#065f65);padding:20px 28px;border-bottom:1px solid rgba(255,255,255,.1)}
.header h1{font-size:1.3rem;font-weight:700;color:#fff}
.header p{font-size:.8rem;color:rgba(255,255,255,.7);margin-top:4px}
.header-nav{display:flex;gap:12px;margin-top:10px;flex-wrap:wrap}
.header-nav a{color:rgba(255,255,255,.8);font-size:.78rem;text-decoration:none;padding:4px 10px;border-radius:4px;background:rgba(255,255,255,.1)}
.header-nav a:hover{background:rgba(255,255,255,.2)}

.container{max-width:1100px;margin:24px auto;padding:0 20px}

.card{background:#1e293b;border-radius:10px;padding:24px;margin-bottom:20px;border:1px solid #334155}
.card h2{font-size:1.1rem;font-weight:600;color:#f1f5f9;margin-bottom:16px;display:flex;align-items:center;gap:8px}
.card h2 .icon{width:28px;height:28px;border-radius:6px;display:flex;align-items:center;justify-content:center;font-size:1rem}

.site-card{display:flex;align-items:center;gap:16px;background:#0f172a;border-radius:8px;padding:16px;margin-bottom:12px;border:1px solid #334155}
.site-card .site-logo{width:48px;height:48px;border-radius:8px;display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;font-size:.85rem;flex-shrink:0}
.site-card .site-info{flex:1}
.site-card .site-info h3{font-size:.95rem;color:#f1f5f9}
.site-card .site-info p{font-size:.75rem;color:#94a3b8;margin-top:2px}

.form-row{display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;margin:12px 0}
.form-group{display:flex;flex-direction:column;gap:4px}
.form-group label{font-size:.72rem;color:#94a3b8;text-transform:uppercase;letter-spacing:.04em;font-weight:600}
.form-group select,.form-group input[type="number"]{background:#0f172a;border:1px solid #475569;color:#e2e8f0;padding:8px 12px;border-radius:6px;font-size:.85rem;min-width:120px}
.form-group select:focus,.form-group input:focus{outline:none;border-color:#0c7c84}

.btn{padding:8px 20px;border:none;border-radius:6px;font-size:.85rem;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:6px;transition:all .2s}
.btn-primary{background:#0c7c84;color:#fff}
.btn-primary:hover{background:#0a6a70}
.btn-primary:disabled{opacity:.5;cursor:not-allowed}
.btn-danger{background:#dc2626;color:#fff}
.btn-danger:hover{background:#b91c1c}
.btn-sm{padding:5px 12px;font-size:.78rem}

.steps{margin:16px 0}
.step{display:flex;gap:12px;margin-bottom:14px;align-items:flex-start}
.step-num{width:28px;height:28px;border-radius:50%;background:#0c7c84;color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:.8rem;flex-shrink:0}
.step-text{font-size:.85rem;color:#cbd5e1;line-height:1.5}
.step-text strong{color:#f1f5f9}
.step-text code{background:#0f172a;padding:2px 6px;border-radius:3px;font-size:.78rem;color:#22d3ee}
.step-text a{color:#22d3ee;text-decoration:none}
.step-text a:hover{text-decoration:underline}

textarea.paste-area{width:100%;height:200px;background:#0a0f1a;border:2px dashed #334155;border-radius:8px;color:#94a3b8;font-family:'SF Mono',Consolas,'Courier New',monospace;font-size:.75rem;padding:12px;resize:vertical;transition:border-color .2s}
textarea.paste-area:focus{outline:none;border-color:#0c7c84;color:#e2e8f0}
textarea.paste-area.drag-over{border-color:#22d3ee;background:#0c1825}

.import-status{margin-top:16px;display:none}
.import-status.active{display:block}

.progress-bar{height:6px;background:#1e293b;border-radius:3px;overflow:hidden;margin:8px 0}
.progress-bar .fill{height:100%;background:#0c7c84;border-radius:3px;transition:width .3s;width:0}

.stats-bar{display:flex;gap:12px;margin:16px 0;flex-wrap:wrap}
.stat{background:#0f172a;border-radius:8px;padding:10px 14px;border:1px solid #334155;min-width:80px;text-align:center}
.stat .num{font-size:1.3rem;font-weight:700;color:#0c7c84}
.stat .label{font-size:.65rem;color:#94a3b8;text-transform:uppercase;letter-spacing:.04em;margin-top:2px}
.stat.imported .num{color:#22c55e}
.stat.updated .num{color:#3b82f6}
.stat.skipped .num{color:#f59e0b}
.stat.errors .num{color:#ef4444}

.result-list{max-height:400px;overflow-y:auto;font-size:.78rem;margin-top:12px}
.result-item{padding:6px 10px;border-bottom:1px solid #1e293b;display:flex;gap:8px;align-items:center}
.result-item:last-child{border-bottom:none}
.result-item .badge{padding:2px 8px;border-radius:3px;font-size:.68rem;font-weight:600;text-transform:uppercase;flex-shrink:0}
.badge-imported{background:#065f46;color:#34d399}
.badge-updated{background:#1e3a5f;color:#60a5fa}
.badge-exists{background:#422006;color:#fbbf24}
.badge-skip{background:#334155;color:#94a3b8}
.badge-error{background:#450a0a;color:#fca5a5}
.result-item .r-title{flex:1;color:#e2e8f0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.result-item .r-meta{color:#94a3b8;flex-shrink:0;text-align:right;font-size:.72rem}

.db-count{font-size:.85rem;color:#94a3b8;margin-bottom:12px}
.db-count strong{color:#0c7c84}

.url-links{margin:12px 0;display:flex;flex-wrap:wrap;gap:6px}
.url-link{background:#0f172a;border:1px solid #334155;border-radius:4px;padding:4px 10px;font-size:.72rem;color:#22d3ee;text-decoration:none;display:inline-flex;align-items:center;gap:4px}
.url-link:hover{border-color:#0c7c84;background:#0c1825}

.future-site{opacity:.4;pointer-events:none;position:relative}
.future-site::after{content:'Coming Soon';position:absolute;right:16px;top:50%;transform:translateY(-50%);background:#334155;color:#94a3b8;padding:3px 10px;border-radius:4px;font-size:.7rem;font-weight:600}

.help-toggle{font-size:.78rem;color:#64748b;cursor:pointer;margin-top:8px;display:inline-block}
.help-toggle:hover{color:#94a3b8}
.help-detail{display:none;margin-top:8px;padding:12px;background:#0a0f1a;border-radius:6px;font-size:.78rem;color:#94a3b8;line-height:1.6;border:1px solid #1e293b}
.help-detail.show{display:block}

.spinner{display:inline-block;width:16px;height:16px;border:2px solid rgba(255,255,255,.3);border-top-color:#fff;border-radius:50%;animation:spin .6s linear infinite}
@keyframes spin{to{transform:rotate(360deg)}}

.toast{position:fixed;bottom:24px;right:24px;background:#22c55e;color:#fff;padding:12px 20px;border-radius:8px;font-size:.85rem;font-weight:600;box-shadow:0 4px 16px rgba(0,0,0,.3);display:none;z-index:100;animation:slideUp .3s ease}
.toast.error{background:#dc2626}
@keyframes slideUp{from{transform:translateY(20px);opacity:0}to{transform:translateY(0);opacity:1}}
</style>
</head>
<body>

<div class="header">
    <h1>Property Listing Importer</h1>
    <p>Import property listings by pasting page source from external sites.</p>
    <div class="header-nav">
        <a href="console.php">← Admin Console</a>
        <a href="import.php">Google Maps Importer</a>
        <a href="scrape_enrich.php">Scrape & Enrich</a>
        <a href="?logout=1">Logout</a>
    </div>
</div>

<div class="container">

    <!-- Current DB count -->
    <div class="db-count">Rumah123 listings in database: <strong id="dbCount">...</strong></div>

    <!-- ═══ Rumah123 ═══ -->
    <div class="card">
        <h2><span class="icon" style="background:#e11d48;">R123</span> Rumah123.com</h2>

        <div class="site-card">
            <div class="site-logo" style="background:#e11d48;">R123</div>
            <div class="site-info">
                <h3>Paste & Import from Rumah123</h3>
                <p>Copy page source from Rumah123 search results and paste below to import listings.</p>
            </div>
        </div>

        <!-- Instructions -->
        <div class="steps">
            <div class="step">
                <div class="step-num">1</div>
                <div class="step-text">Open one of these search pages in your browser:
                    <div class="url-links">
                        <a href="https://www.rumah123.com/jual/lombok-tengah/tanah/" target="_blank" class="url-link">🏝 Land (Tanah)</a>
                        <a href="https://www.rumah123.com/jual/lombok-tengah/rumah/" target="_blank" class="url-link">🏠 Houses (Rumah)</a>
                        <a href="https://www.rumah123.com/jual/lombok-tengah/tanah/?page=2" target="_blank" class="url-link">📄 Land Page 2</a>
                        <a href="https://www.rumah123.com/jual/lombok-tengah/tanah/?page=3" target="_blank" class="url-link">📄 Land Page 3</a>
                    </div>
                </div>
            </div>
            <div class="step">
                <div class="step-num">2</div>
                <div class="step-text"><strong>Right-click</strong> anywhere on the page and select <strong>"View Page Source"</strong> (or press <code>Ctrl+U</code> / <code>Cmd+Option+U</code>).</div>
            </div>
            <div class="step">
                <div class="step-num">3</div>
                <div class="step-text">Press <code>Ctrl+A</code> to <strong>Select All</strong>, then <code>Ctrl+C</code> to <strong>Copy</strong>.</div>
            </div>
            <div class="step">
                <div class="step-num">4</div>
                <div class="step-text"><strong>Paste</strong> into the box below and click <strong>Import</strong>. Repeat for additional pages.</div>
            </div>
        </div>

        <span class="help-toggle" onclick="toggleHelp()">ℹ Having trouble? Click here for help</span>
        <div class="help-detail" id="helpDetail">
            <strong>Why paste?</strong> Rumah123 uses Cloudflare protection which blocks automated requests. By pasting the page source from your browser, we bypass this limitation while still extracting all listing data accurately from the page's embedded JSON.<br><br>
            <strong>What data is extracted?</strong> Title, price, land size, building size, location, property type, certificate, photos, agent info, and listing URL.<br><br>
            <strong>Duplicates?</strong> The importer automatically checks for duplicates using the Rumah123 listing ID. If a listing already exists and the new price is lower, the price is updated.<br><br>
            <strong>Multiple pages?</strong> Each Rumah123 search page shows ~20 listings. Paste one page at a time — the tool handles deduplication across pastes.
        </div>

        <!-- Settings & paste area -->
        <div class="form-row">
            <div class="form-group">
                <label>Search Type Filter</label>
                <select id="searchType">
                    <option value="all">All Types</option>
                    <option value="land">Land Only (Tanah)</option>
                    <option value="houses">Houses/Villas Only</option>
                </select>
            </div>
            <div class="form-group">
                <label>Max Listings Per Paste</label>
                <input type="number" id="maxListings" value="30" min="1" max="200">
            </div>
        </div>

        <textarea class="paste-area" id="pasteArea" placeholder="Paste the full page source here...&#10;&#10;Right-click the Rumah123 search results page → View Page Source → Select All → Copy → Paste here"></textarea>

        <div class="form-row" style="margin-top:12px;">
            <button class="btn btn-primary" id="importBtn" onclick="doImport()">📥 Import Listings</button>
            <button class="btn btn-danger btn-sm" id="clearBtn" onclick="doClear()" style="margin-left:auto;">🗑 Clear All Rumah123 Data</button>
        </div>

        <!-- Results area -->
        <div class="import-status" id="importStatus">
            <div class="progress-bar"><div class="fill" id="progressFill"></div></div>
            <div id="statusText" style="font-size:.8rem;color:#94a3b8;margin:8px 0;"></div>

            <div class="stats-bar" id="statsBar" style="display:none;">
                <div class="stat imported"><div class="num" id="statImported">0</div><div class="label">Imported</div></div>
                <div class="stat updated"><div class="num" id="statUpdated">0</div><div class="label">Updated</div></div>
                <div class="stat skipped"><div class="num" id="statSkipped">0</div><div class="label">Skipped</div></div>
                <div class="stat errors"><div class="num" id="statErrors">0</div><div class="label">Errors</div></div>
            </div>

            <div class="result-list" id="resultList"></div>
        </div>
    </div>

    <!-- ═══ Future sites ═══ -->
    <div class="card">
        <h2><span class="icon" style="background:#334155;">🌐</span> Other Sources</h2>

        <div class="site-card future-site">
            <div class="site-logo" style="background:#3b82f6;">OLX</div>
            <div class="site-info">
                <h3>OLX.co.id</h3>
                <p>Marketplace listings — Land and property in Lombok area.</p>
            </div>
        </div>

        <div class="site-card future-site">
            <div class="site-logo" style="background:#1877f2;">FB</div>
            <div class="site-info">
                <h3>Facebook Marketplace</h3>
                <p>Community listings — Land and property from local sellers.</p>
            </div>
        </div>
    </div>

</div>

<div class="toast" id="toast"></div>

<script>
/* global vars */
var importRunning = false;

/* Helpers */
function $(id) { return document.getElementById(id); }

function showToast(msg, isError) {
    var t = $('toast');
    t.textContent = msg;
    t.className = 'toast' + (isError ? ' error' : '');
    t.style.display = 'block';
    setTimeout(function() { t.style.display = 'none'; }, 4000);
}

function toggleHelp() {
    var el = $('helpDetail');
    el.className = el.className.indexOf('show') >= 0 ? 'help-detail' : 'help-detail show';
}

function badgeClass(status) {
    if (status === 'imported') return 'badge-imported';
    if (status === 'updated') return 'badge-updated';
    if (status === 'exists') return 'badge-exists';
    if (status === 'error') return 'badge-error';
    return 'badge-skip';
}

function badgeLabel(status) {
    if (status === 'imported') return 'NEW';
    if (status === 'updated') return 'UPD';
    if (status === 'exists') return 'SKIP';
    if (status === 'error') return 'ERR';
    return 'SKIP';
}

/* Load DB count on page load */
function loadCount() {
    var xhr = new XMLHttpRequest();
    xhr.open('POST', 'scrape_listings.php', true);
    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
    xhr.onload = function() {
        try {
            var res = JSON.parse(xhr.responseText);
            if (res.success) {
                $('dbCount').textContent = res.count;
            }
        } catch(e) {}
    };
    xhr.send('action=get_counts');
}
loadCount();

/* Import */
function doImport() {
    if (importRunning) return;

    var source = $('pasteArea').value.trim();
    if (!source) {
        showToast('Please paste the page source first.', true);
        return;
    }
    if (source.length < 500) {
        showToast('Content seems too short. Copy the FULL page source.', true);
        return;
    }

    importRunning = true;
    $('importBtn').disabled = true;
    $('importBtn').innerHTML = '<span class="spinner"></span> Importing...';

    var statusEl = $('importStatus');
    statusEl.className = 'import-status active';
    $('statusText').textContent = 'Parsing page source and extracting listings...';
    $('progressFill').style.width = '30%';
    $('statsBar').style.display = 'none';
    $('resultList').innerHTML = '';

    var formData = new FormData();
    formData.append('action', 'import_paste');
    formData.append('html_source', source);
    formData.append('max_listings', $('maxListings').value);
    formData.append('search_type', $('searchType').value);

    var xhr = new XMLHttpRequest();
    xhr.open('POST', 'scrape_listings.php', true);
    xhr.onload = function() {
        importRunning = false;
        $('importBtn').disabled = false;
        $('importBtn').innerHTML = '📥 Import Listings';
        $('progressFill').style.width = '100%';

        try {
            var res = JSON.parse(xhr.responseText);

            if (!res.success) {
                $('statusText').innerHTML = '<span style="color:#ef4444;">Error: ' + res.error + '</span>';
                showToast('Import failed', true);
                return;
            }

            // Show stats
            $('statsBar').style.display = 'flex';
            $('statImported').textContent = res.imported;
            $('statUpdated').textContent = res.updated;
            $('statSkipped').textContent = res.skipped;
            $('statErrors').textContent = res.errors;

            var pageInfo = '';
            if (res.pagination && res.pagination.totalPages > 1) {
                pageInfo = ' (Page ' + res.pagination.currentPage + ' of ' + res.pagination.totalPages + ')';
            }

            $('statusText').innerHTML = 'Done! Found <strong>' + res.total_in_page + '</strong> listings in this page' + pageInfo + '.'
                + (res.skipped_estates > 0 ? ' <span style="color:#f59e0b;">' + res.skipped_estates + ' housing estates skipped.</span>' : '');

            // Show results
            var html = '';
            for (var i = 0; i < res.results.length; i++) {
                var r = res.results[i];
                var meta = '';
                if (r.price) meta += r.price;
                if (r.size && r.size !== '-') meta += ' · ' + r.size;
                if (r.location) meta += ' · ' + r.location;

                html += '<div class="result-item">'
                    + '<span class="badge ' + badgeClass(r.status) + '">' + badgeLabel(r.status) + '</span>'
                    + '<span class="r-title">' + (r.title || '').replace(/</g, '&lt;') + '</span>'
                    + (meta ? '<span class="r-meta">' + meta.replace(/</g, '&lt;') + '</span>' : '')
                    + '</div>';
            }
            $('resultList').innerHTML = html;

            if (res.imported > 0) {
                showToast(res.imported + ' listing(s) imported!', false);
            } else {
                showToast('No new listings to import.', false);
            }

            // Refresh count
            loadCount();

            // Clear textarea
            $('pasteArea').value = '';

        } catch(e) {
            $('statusText').innerHTML = '<span style="color:#ef4444;">Error parsing response: ' + e.message + '</span>';
            showToast('Import failed', true);
        }
    };
    xhr.onerror = function() {
        importRunning = false;
        $('importBtn').disabled = false;
        $('importBtn').innerHTML = '📥 Import Listings';
        $('statusText').innerHTML = '<span style="color:#ef4444;">Network error. Please try again.</span>';
        showToast('Network error', true);
    };
    xhr.send(formData);
}

/* Clear all Rumah123 data */
function doClear() {
    if (!confirm('Are you sure you want to DELETE ALL Rumah123 listings from the database? This cannot be undone.')) return;

    var xhr = new XMLHttpRequest();
    xhr.open('POST', 'scrape_listings.php', true);
    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
    xhr.onload = function() {
        try {
            var res = JSON.parse(xhr.responseText);
            if (res.success) {
                showToast(res.deleted + ' listing(s) deleted.', false);
                loadCount();
            }
        } catch(e) {
            showToast('Error deleting data.', true);
        }
    };
    xhr.send('action=clear_rumah123');
}

/* Drag-drop support for the textarea */
var pa = $('pasteArea');
pa.addEventListener('dragover', function(e) {
    e.preventDefault();
    pa.className = 'paste-area drag-over';
});
pa.addEventListener('dragleave', function() {
    pa.className = 'paste-area';
});
pa.addEventListener('drop', function(e) {
    e.preventDefault();
    pa.className = 'paste-area';
    var text = e.dataTransfer.getData('text');
    if (text) {
        pa.value = text;
    }
});
</script>

</body>
</html>
<?php

// ─── LOGIN PAGE ──────────────────────────────────────────────────────
function show_login($error = '') {
?><!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<meta name="robots" content="noindex,nofollow"><title>Property Importer Login</title>
<style>
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',system-ui,sans-serif;background:#0f172a;display:flex;align-items:center;justify-content:center;min-height:100vh}
.lc{background:#1e293b;border-radius:10px;padding:40px;box-shadow:0 2px 12px rgba(0,0,0,.3);width:100%;max-width:360px;border:1px solid #334155}
h1{font-size:1.3rem;margin-bottom:6px;color:#f1f5f9}p{color:#94a3b8;font-size:.85rem;margin-bottom:20px}
label{display:block;font-size:.8rem;font-weight:600;text-transform:uppercase;letter-spacing:.03em;color:#94a3b8;margin-bottom:4px}
input{width:100%;padding:10px 12px;border:1px solid #475569;border-radius:6px;font-size:.95rem;margin-bottom:14px;box-sizing:border-box;background:#0f172a;color:#e2e8f0}
button{width:100%;padding:11px;background:#0c7c84;color:#fff;border:none;border-radius:6px;font-size:.95rem;font-weight:600;cursor:pointer}
button:hover{background:#0a6a70}.err{background:#fee2e2;color:#dc2626;padding:10px;border-radius:6px;margin-bottom:14px;font-size:.85rem}
</style></head><body>
<div class="lc">
    <h1>Property Importer</h1>
    <p>Build in Lombok — Admin Tool</p>
    <?php if ($error): ?><div class="err"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
    <form method="POST">
        <label>Username</label><input type="text" name="username" required autofocus>
        <label>Password</label><input type="password" name="password" required>
        <button type="submit" name="login" value="1">Login</button>
    </form>
</div>
</body></html>
<?php } ?>
