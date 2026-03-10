<?php
/**
 * Build in Lombok — Admin: Property Listing Scraper
 *
 * Password-protected admin tool for importing property listings
 * from external sites (Rumah123.com, etc.)
 *
 * Place at: /admin/scrape_listings.php
 * SECURITY: Not linked from any menu. Access via direct URL only.
 */

session_start();
require_once('/home/rovin629/config/biltest_config.php');

// ─── AUTH CHECK ──────────────────────────────────────────────────────
$auth_error = '';
if (isset($_POST['login'])) {
    $u = $_POST['username'] ?? '';
    $p = $_POST['password'] ?? '';
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

// ─── HELPER: Fetch URL with cURL (polite, with delays) ──────────────
function fetch_url($url) {
    $ch = curl_init();
    curl_setopt_array($ch, array(
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        CURLOPT_HTTPHEADER => array(
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
            'Accept-Language: id-ID,id;q=0.9,en-US;q=0.8,en;q=0.7',
            'Cache-Control: no-cache',
            'Connection: keep-alive',
        ),
        CURLOPT_ENCODING => 'gzip, deflate',
        CURLOPT_SSL_VERIFYPEER => false,
    ));
    $html = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code >= 400 || $html === false) {
        return false;
    }
    return $html;
}

// ─── HELPER: Output a progress line (flushed immediately) ────────────
function emit($msg, $type = 'info') {
    $cls = 'log-' . $type;
    echo '<div class="log-line ' . $cls . '">' . htmlspecialchars($msg) . '</div>';
    echo str_pad('', 4096) . "\n"; // padding to force flush
    if (ob_get_level()) ob_flush();
    flush();
}

function emit_html($html) {
    echo $html;
    echo str_pad('', 4096) . "\n";
    if (ob_get_level()) ob_flush();
    flush();
}

// ─── AREA DETECTION ──────────────────────────────────────────────────
// Maps Rumah123 district/kecamatan names to our area_key system
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

    // More specific matches first
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

    // Default for Lombok Tengah searches
    return 'praya';
}

// ─── DETECT LOCATION DETAIL ─────────────────────────────────────────
// Extracts granular location names from title/description
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

    // Check from most specific to least
    foreach ($locations as $label => $keywords) {
        foreach ($keywords as $kw) {
            if (strpos($combined, $kw) !== false) {
                return $label;
            }
        }
    }

    // Fall back to district name from Rumah123
    if ($district) {
        return ucwords(strtolower(str_replace(array('-', '_'), ' ', $district)));
    }

    return 'Lombok Tengah';
}

// ─── PARSE PRICE ────────────────────────────────────────────────────
function parse_price_idr($price_text) {
    $price_text = strtolower(trim($price_text));
    $price_text = str_replace(array('.', ','), '', $price_text);
    $price_text = preg_replace('/\s+/', ' ', $price_text);

    // Remove "rp" prefix
    $price_text = preg_replace('/^rp\s*/', '', $price_text);

    $number = 0;
    if (preg_match('/(\d+)\s*miliar/', $price_text, $m)) {
        $number = intval($m[1]) * 1000000000;
    } elseif (preg_match('/(\d+)\s*juta/', $price_text, $m)) {
        $number = intval($m[1]) * 1000000;
    } elseif (preg_match('/(\d+)/', $price_text, $m)) {
        $number = intval($m[1]);
        // If small number, probably in millions or billions
        if ($number < 1000) {
            $number = $number * 1000000; // assume juta
        }
    }

    return $number > 0 ? $number : null;
}

// Convert IDR to USD (approximate)
function idr_to_usd($idr) {
    if (!$idr) return null;
    $rate = 15800; // approximate IDR/USD rate
    return (int)round($idr / $rate);
}

// ─── DETECT LISTING TYPE ────────────────────────────────────────────
function detect_listing_type($title, $property_type_text) {
    $combined = strtolower($title . ' ' . $property_type_text);
    if (strpos($combined, 'villa') !== false) return 'villa';
    if (strpos($combined, 'rumah') !== false || strpos($combined, 'house') !== false) return 'house';
    if (strpos($combined, 'apartment') !== false || strpos($combined, 'apartemen') !== false) return 'apartment';
    if (strpos($combined, 'tanah') !== false || strpos($combined, 'land') !== false) return 'land';
    if (strpos($combined, 'ruko') !== false || strpos($combined, 'komersial') !== false) return 'commercial';
    return 'land'; // default for our search
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


// ═══════════════════════════════════════════════════════════════════
// RUMAH123 SCRAPER
// ═══════════════════════════════════════════════════════════════════

function scrape_rumah123($search_type, $max_listings) {
    $db = get_db();

    // Search URLs for Lombok Tengah
    $base_urls = array();
    if ($search_type === 'land' || $search_type === 'all') {
        $base_urls[] = array('url' => 'https://www.rumah123.com/jual/lombok-tengah/tanah/', 'type' => 'land');
    }
    if ($search_type === 'houses' || $search_type === 'all') {
        $base_urls[] = array('url' => 'https://www.rumah123.com/jual/lombok-tengah/rumah/', 'type' => 'house');
    }

    $total_imported = 0;
    $total_skipped = 0;
    $total_updated = 0;
    $total_errors = 0;
    $seen_source_ids = array(); // Track source IDs for dedup within this run

    foreach ($base_urls as $search) {
        $base_url = $search['url'];
        $default_type = $search['type'];

        emit("Starting search: {$base_url}", 'info');
        $page = 1;
        $consecutive_empty = 0;

        while ($total_imported + $total_skipped + $total_updated < $max_listings * 3 && $total_imported + $total_updated < $max_listings) {
            $url = $page === 1 ? $base_url : $base_url . '?page=' . $page;
            emit("Fetching page {$page}: {$url}", 'info');

            $html = fetch_url($url);
            if (!$html) {
                emit("Failed to fetch page {$page}. Stopping.", 'error');
                break;
            }

            // Extract listing URLs from search results
            $listing_urls = array();
            // Match both /properti/ links (individual) — skip /perumahan-baru/ (estates)
            if (preg_match_all('/"(https?:\/\/www\.rumah123\.com\/properti\/[^"]+)"/', $html, $matches)) {
                foreach ($matches[1] as $lurl) {
                    // Skip perumahan-baru (housing estates)
                    if (strpos($lurl, 'perumahan-baru') !== false) continue;
                    // Deduplicate within page
                    if (!in_array($lurl, $listing_urls)) {
                        $listing_urls[] = $lurl;
                    }
                }
            }

            if (empty($listing_urls)) {
                $consecutive_empty++;
                emit("No listings found on page {$page}.", 'warn');
                if ($consecutive_empty >= 2) {
                    emit("Two consecutive empty pages. Done with this search.", 'info');
                    break;
                }
                $page++;
                sleep(rand(2, 4));
                continue;
            }

            $consecutive_empty = 0;
            emit("Found " . count($listing_urls) . " listing(s) on page {$page}.", 'info');

            foreach ($listing_urls as $listing_url) {
                if ($total_imported + $total_updated >= $max_listings) {
                    emit("Reached max listings limit ({$max_listings}). Stopping.", 'success');
                    break 3;
                }

                // Extract source listing ID from URL (e.g., las8951048)
                $source_id = '';
                if (preg_match('/(las\d+|hos\d+)\/?$/', $listing_url, $idm)) {
                    $source_id = $idm[1];
                } elseif (preg_match('/-(las\d+|hos\d+)\/?/', $listing_url, $idm)) {
                    $source_id = $idm[1];
                }

                // Skip if we've already processed this source ID in this run
                if ($source_id && in_array($source_id, $seen_source_ids)) {
                    emit("  Duplicate in this run: {$source_id}. Skipping.", 'warn');
                    $total_skipped++;
                    continue;
                }
                if ($source_id) {
                    $seen_source_ids[] = $source_id;
                }

                // Check if already in DB
                if ($source_id) {
                    $existing = $db->prepare("SELECT id, price_idr FROM listings WHERE source_site = 'rumah123' AND source_listing_id = ?");
                    $existing->execute(array($source_id));
                    $ex = $existing->fetch();
                    // We'll handle update-if-cheaper below
                }

                // Polite delay between detail page fetches: 2-5 seconds
                sleep(rand(2, 5));

                emit("  Fetching detail: {$listing_url}", 'info');
                $detail_html = fetch_url($listing_url);
                if (!$detail_html) {
                    emit("  Failed to fetch detail page. Skipping.", 'error');
                    $total_errors++;
                    continue;
                }

                // ─── Parse detail page ─────────────────────────────────
                $data = parse_rumah123_detail($detail_html, $listing_url, $default_type);
                if (!$data) {
                    emit("  Could not parse listing data. Skipping.", 'error');
                    $total_errors++;
                    continue;
                }

                $data['source_listing_id'] = $source_id;

                // ─── Handle agent ──────────────────────────────────────
                $agent_id = null;
                if (!empty($data['agent_name'])) {
                    $agent_id = upsert_agent($db, $data);
                }

                // ─── Handle listing ────────────────────────────────────
                if (isset($ex) && $ex) {
                    // Listing exists — only update if new price is lower
                    $new_price = $data['price_idr'];
                    $old_price = intval($ex['price_idr']);
                    if ($new_price && $old_price && $new_price < $old_price) {
                        $upd = $db->prepare("UPDATE listings SET price_idr = ?, price_usd = ?, price_idr_per_sqm = ?, updated_at = NOW() WHERE id = ?");
                        $upd->execute(array($new_price, idr_to_usd($new_price), $data['price_idr_per_sqm'], $ex['id']));
                        emit("  Updated price: " . $data['title'] . " (lower price found)", 'success');
                        $total_updated++;
                    } else {
                        emit("  Already in DB: " . ($data['title'] ? substr($data['title'], 0, 60) : $source_id) . ". Skipping.", 'warn');
                        $total_skipped++;
                    }
                    unset($ex);
                    continue;
                }
                unset($ex);

                // ─── Insert new listing ────────────────────────────────
                $result = insert_listing($db, $data, $agent_id);
                if ($result === 'inserted') {
                    $total_imported++;
                    emit_html('<div class="log-line log-success">✓ <strong>' . htmlspecialchars(substr($data['title'], 0, 70)) . '</strong> — '
                        . htmlspecialchars($data['price_display']) . ' — '
                        . htmlspecialchars($data['location_detail'])
                        . ($data['land_size_sqm'] ? ' — ' . number_format($data['land_size_sqm']) . ' m²' : '')
                        . ' [' . $total_imported . '/' . $max_listings . ']</div>');
                } elseif ($result === 'duplicate') {
                    $total_skipped++;
                    emit("  Duplicate: " . substr($data['title'], 0, 60), 'warn');
                } else {
                    $total_errors++;
                    emit("  Error inserting: " . substr($data['title'], 0, 60), 'error');
                }
            }

            $page++;
            // Polite delay between pages: 3-6 seconds
            sleep(rand(3, 6));
        }
    }

    emit("", 'info');
    emit("═══════════════════════════════════════════════════", 'info');
    emit("COMPLETE: {$total_imported} imported, {$total_updated} updated, {$total_skipped} skipped, {$total_errors} errors", 'success');
    emit("═══════════════════════════════════════════════════", 'info');
}


// ─── PARSE RUMAH123 DETAIL PAGE ─────────────────────────────────────
function parse_rumah123_detail($html, $url, $default_type) {
    $data = array(
        'source_url' => $url,
        'title' => '',
        'price_display' => '',
        'price_idr' => null,
        'price_usd' => null,
        'price_label' => '',
        'price_idr_per_sqm' => null,
        'listing_type_key' => $default_type,
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

    // ─── Title ─────────────────────────────────────────────
    // Try og:title first
    if (preg_match('/<meta\s+property="og:title"\s+content="([^"]+)"/i', $html, $m)) {
        $data['title'] = html_entity_decode(trim($m[1]), ENT_QUOTES, 'UTF-8');
    } elseif (preg_match('/<h1[^>]*>([^<]+)<\/h1>/i', $html, $m)) {
        $data['title'] = html_entity_decode(trim($m[1]), ENT_QUOTES, 'UTF-8');
    }
    if (!$data['title']) return null;

    // ─── Price ─────────────────────────────────────────────
    // Look for price in structured data or visible text
    if (preg_match('/"price"\s*:\s*"?(\d+)"?/', $html, $m)) {
        $data['price_idr'] = intval($m[1]);
    }
    // Also check og:price or visible price patterns
    if (preg_match('/"priceCurrency"\s*:\s*"IDR"/i', $html)) {
        // structured data price already captured above
    }
    // Fallback: look for Rp in page
    if (!$data['price_idr']) {
        if (preg_match('/Rp\s*([\d.,]+)\s*(Miliar|Juta|Ribu)?/i', $html, $pm)) {
            $num = str_replace(array('.', ','), '', $pm[1]);
            $mult = 1;
            if (isset($pm[2])) {
                $unit = strtolower($pm[2]);
                if ($unit === 'miliar') $mult = 1000000000;
                elseif ($unit === 'juta') $mult = 1000000;
                elseif ($unit === 'ribu') $mult = 1000;
            }
            $data['price_idr'] = intval($num) * $mult;
        }
    }
    // Price per are/sqm label
    if (preg_match('/\/are|per are/i', $html)) {
        $data['price_label'] = 'Per Are';
    } elseif (preg_match('/\/m²|per m/i', $html)) {
        $data['price_label'] = 'Per m²';
    } elseif (preg_match('/total/i', $html)) {
        $data['price_label'] = 'Total';
    }

    $data['price_usd'] = idr_to_usd($data['price_idr']);
    $data['price_display'] = $data['price_idr'] ? 'Rp ' . number_format($data['price_idr'], 0, ',', '.') : 'Price on request';

    // ─── Land & building size ──────────────────────────────
    // "Luas Tanah" or "LT:" patterns
    if (preg_match('/(?:Luas Tanah|LT)\s*:?\s*([\d.,]+)\s*m/i', $html, $m)) {
        $data['land_size_sqm'] = intval(str_replace(array('.', ','), '', $m[1]));
    } elseif (preg_match('/(\d[\d.,]*)\s*m²?\s*\(?\s*[\d.,]*\s*are/i', $html, $m)) {
        $data['land_size_sqm'] = intval(str_replace(array('.', ','), '', $m[1]));
    }
    // "Luas Bangunan" or "LB:"
    if (preg_match('/(?:Luas Bangunan|LB)\s*:?\s*([\d.,]+)\s*m/i', $html, $m)) {
        $data['building_size_sqm'] = intval(str_replace(array('.', ','), '', $m[1]));
    }

    // Price per sqm
    if ($data['price_idr'] && $data['land_size_sqm'] && $data['land_size_sqm'] > 0) {
        $data['price_idr_per_sqm'] = intval($data['price_idr'] / $data['land_size_sqm']);
    }

    // ─── Bedrooms & bathrooms ──────────────────────────────
    if (preg_match('/(\d+)\s*(?:Kamar Tidur|KT|bedroom)/i', $html, $m)) {
        $data['bedrooms'] = intval($m[1]);
    }
    if (preg_match('/(\d+)\s*(?:Kamar Mandi|KM|bathroom)/i', $html, $m)) {
        $data['bathrooms'] = intval($m[1]);
    }

    // ─── Certificate type ──────────────────────────────────
    if (preg_match('/Sertifikat\s*:?\s*([^<\n]+)/i', $html, $m)) {
        $data['certificate_type_key'] = detect_certificate($m[1]);
    }

    // ─── Listing type ──────────────────────────────────────
    $property_type_text = '';
    if (preg_match('/Tipe Properti\s*:?\s*([^<\n]+)/i', $html, $m)) {
        $property_type_text = trim($m[1]);
    }
    $data['listing_type_key'] = detect_listing_type($data['title'], $property_type_text);

    // ─── Location / district ───────────────────────────────
    // From URL: /properti/lombok-tengah-pujut/...
    if (preg_match('/\/properti\/lombok-tengah-([^\/]+)\//', $url, $m)) {
        $data['district'] = str_replace('-', ' ', $m[1]);
    } elseif (preg_match('/\/properti\/([^\/]+)\//', $url, $m)) {
        $data['district'] = str_replace('-', ' ', $m[1]);
    }
    // Also check visible location text
    if (preg_match('/"addressLocality"\s*:\s*"([^"]+)"/i', $html, $m)) {
        $data['district'] = $m[1];
    }

    // ─── Description ───────────────────────────────────────
    // Try structured data description
    if (preg_match('/"description"\s*:\s*"((?:[^"\\\\]|\\\\.)*)"/i', $html, $m)) {
        $desc = stripcslashes($m[1]);
        $desc = strip_tags($desc);
        $desc = preg_replace('/\s+/', ' ', trim($desc));
        if (strlen($desc) > 30) {
            $data['description'] = $desc;
        }
    }
    // Fallback: og:description
    if (empty($data['description'])) {
        if (preg_match('/<meta\s+(?:property|name)="(?:og:)?description"\s+content="([^"]+)"/i', $html, $m)) {
            $data['description'] = html_entity_decode(trim($m[1]), ENT_QUOTES, 'UTF-8');
        }
    }
    $data['short_description'] = $data['description'] ? mb_substr($data['description'], 0, 200) : $data['title'];

    // ─── Location detection ────────────────────────────────
    $data['area_key'] = detect_area_key($data['district'], $data['title'], $data['description']);
    $data['location_detail'] = detect_location_detail($data['district'], $data['title'], $data['description']);

    // ─── Photos ────────────────────────────────────────────
    // Look for image URLs from Rumah123's CDN
    if (preg_match_all('/https?:\/\/picture\.rumah123\.com\/[^"\'<>\s]+\.(?:jpg|jpeg|png|webp)/i', $html, $pm)) {
        $unique_photos = array();
        foreach ($pm[0] as $photo_url) {
            // Get the higher resolution version
            $photo_url = preg_replace('/\/\d+x\d+[^\/]*\//', '/720x420-crop/', $photo_url);
            if (!in_array($photo_url, $unique_photos)) {
                $unique_photos[] = $photo_url;
            }
            if (count($unique_photos) >= 3) break; // Only grab 3 for now
        }
        $data['photos'] = $unique_photos;
    }

    // ─── Agent info ────────────────────────────────────────
    // Agent name from the listing agent section
    if (preg_match('/agen-properti\/[^\/]+\/([^\/]+)-(\d+)\//i', $html, $m)) {
        $data['agent_source_id'] = $m[2];
        $data['agent_profile_url'] = 'https://www.rumah123.com/agen-properti/' . $m[0];
        // Reconstruct name from slug
        $name_slug = $m[1];
        $data['agent_name'] = ucwords(str_replace('-', ' ', $name_slug));
    }
    // Try to find agent name more directly
    if (preg_match('/"name"\s*:\s*"([^"]+)"[^}]*"@type"\s*:\s*"RealEstateAgent"/i', $html, $m)) {
        $data['agent_name'] = html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
    } elseif (preg_match('/"@type"\s*:\s*"RealEstateAgent"[^}]*"name"\s*:\s*"([^"]+)"/i', $html, $m)) {
        $data['agent_name'] = html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
    }

    // Agent phone
    if (preg_match('/"telephone"\s*:\s*"([^"]+)"/i', $html, $m)) {
        $data['agent_phone'] = trim($m[1]);
    } elseif (preg_match('/(\+62[\d\s-]{8,15})/', $html, $m)) {
        $data['agent_phone'] = preg_replace('/[\s-]/', '', $m[1]);
    }

    // Agent photo
    if (preg_match('/agen.*?<img[^>]+src="(https?:\/\/[^"]+)"[^>]*>/is', $html, $m)) {
        if (strpos($m[1], 'rumah123') !== false || strpos($m[1], 'r123') !== false) {
            $data['agent_photo_url'] = $m[1];
        }
    }

    // Agent type
    if (preg_match('/(?:Agen\s+)?Independen/i', $html)) {
        $data['agent_type'] = 'Independen';
    } elseif (preg_match('/Agen\s+Kantor/i', $html)) {
        $data['agent_type'] = 'Agen Kantor';
    }

    // Verified/trusted
    if (preg_match('/verified|terverifikasi/i', $html)) {
        $data['agent_verified'] = true;
    }

    return $data;
}


// ─── UPSERT AGENT ───────────────────────────────────────────────────
function upsert_agent($db, $data) {
    $source_id = $data['agent_source_id'];
    if (!$source_id && $data['agent_name']) {
        // Generate a pseudo source_id from name
        $source_id = 'name_' . md5(strtolower($data['agent_name']));
    }
    if (!$source_id) return null;

    // Check if agent exists
    $stmt = $db->prepare("SELECT id FROM agents WHERE source_site = 'rumah123' AND source_agent_id = ?");
    $stmt->execute(array($source_id));
    $existing = $stmt->fetch();

    if ($existing) {
        // Update phone if we have new data
        if ($data['agent_phone']) {
            $db->prepare("UPDATE agents SET phone = COALESCE(NULLIF(phone, ''), ?) WHERE id = ?")->execute(array($data['agent_phone'], $existing['id']));
        }
        return intval($existing['id']);
    }

    // Insert new agent
    $slug = make_slug($data['agent_name']);

    // Ensure slug is unique
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
        $data['agent_photo_url'] ?: null,
        $data['agent_phone'] ?: null,
        $data['agent_phone'] ?: null, // whatsapp same as phone
        $data['agent_verified'] ? 1 : 0,
        $source_id,
        $data['agent_profile_url'] ?: null,
        $data['agent_verified'] ? 1 : 0,
        $data['agent_type'] ?: null,
    ));

    $agent_id = intval($db->lastInsertId());
    emit("    → Agent added: " . $data['agent_name'] . ($data['agent_verified'] ? ' ✓ Verified' : ''), 'success');
    return $agent_id;
}


// ─── INSERT LISTING ─────────────────────────────────────────────────
function insert_listing($db, $data, $agent_id) {
    $slug = make_slug($data['title']);

    // Ensure slug uniqueness
    $slug_check = $db->prepare("SELECT COUNT(*) FROM listings WHERE slug = ?");
    $slug_check->execute(array($slug));
    if ($slug_check->fetchColumn() > 0) {
        $slug = $slug . '-' . substr(md5($data['source_listing_id'] . time()), 0, 6);
    }

    // Encode photos as JSON array for the TEXT column
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
            $data['price_label'] ?: null,
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
        emit("    DB Error: " . $e->getMessage(), 'error');
        return 'error';
    }
}


// ═══════════════════════════════════════════════════════════════════
// HANDLE SCRAPE REQUEST
// ═══════════════════════════════════════════════════════════════════

$running = false;
if (isset($_POST['start_scrape'])) {
    $running = true;
    $scrape_site = $_POST['scrape_site'] ?? 'rumah123';
    $search_type = $_POST['search_type'] ?? 'all';
    $max_listings = max(1, min(2000, intval($_POST['max_listings'] ?? 30)));
}

?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<meta name="robots" content="noindex,nofollow">
<title>Listing Scraper — Build in Lombok Admin</title>
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',system-ui,sans-serif;background:#0f172a;color:#e2e8f0;min-height:100vh}

.header{background:linear-gradient(135deg,#0c7c84,#065f65);padding:20px 28px;border-bottom:1px solid rgba(255,255,255,.1)}
.header h1{font-size:1.3rem;font-weight:700;color:#fff}
.header p{font-size:.8rem;color:rgba(255,255,255,.7);margin-top:4px}
.header-nav{display:flex;gap:12px;margin-top:10px}
.header-nav a{color:rgba(255,255,255,.8);font-size:.78rem;text-decoration:none;padding:4px 10px;border-radius:4px;background:rgba(255,255,255,.1)}
.header-nav a:hover{background:rgba(255,255,255,.2)}

.container{max-width:1100px;margin:24px auto;padding:0 20px}

.card{background:#1e293b;border-radius:10px;padding:24px;margin-bottom:20px;border:1px solid #334155}
.card h2{font-size:1.1rem;font-weight:600;color:#f1f5f9;margin-bottom:16px;display:flex;align-items:center;gap:8px}
.card h2 .icon{width:28px;height:28px;border-radius:6px;display:flex;align-items:center;justify-content:center;font-size:1rem}

.site-card{display:flex;align-items:center;gap:16px;background:#0f172a;border-radius:8px;padding:16px;margin-bottom:12px;border:1px solid #334155}
.site-card .site-logo{width:48px;height:48px;border-radius:8px;background:#e11d48;display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;font-size:.85rem}
.site-card .site-info{flex:1}
.site-card .site-info h3{font-size:.95rem;color:#f1f5f9}
.site-card .site-info p{font-size:.75rem;color:#94a3b8;margin-top:2px}

form.scrape-form{display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;margin-top:12px}
.form-group{display:flex;flex-direction:column;gap:4px}
.form-group label{font-size:.72rem;color:#94a3b8;text-transform:uppercase;letter-spacing:.04em;font-weight:600}
.form-group select,.form-group input[type="number"]{background:#0f172a;border:1px solid #475569;color:#e2e8f0;padding:8px 12px;border-radius:6px;font-size:.85rem;min-width:120px}
.form-group select:focus,.form-group input:focus{outline:none;border-color:#0c7c84}

.btn{padding:8px 20px;border:none;border-radius:6px;font-size:.85rem;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:6px;transition:all .2s}
.btn-primary{background:#0c7c84;color:#fff}
.btn-primary:hover{background:#0a6a70}
.btn-primary:disabled{opacity:.5;cursor:not-allowed}

.log-container{background:#0a0f1a;border-radius:8px;padding:16px;max-height:600px;overflow-y:auto;font-family:'SF Mono',Consolas,'Courier New',monospace;font-size:.78rem;line-height:1.7;border:1px solid #1e293b}
.log-line{padding:1px 0}
.log-info{color:#94a3b8}
.log-success{color:#22c55e}
.log-warn{color:#f59e0b}
.log-error{color:#ef4444}
.log-success strong{color:#4ade80}

.stats-bar{display:flex;gap:16px;margin-bottom:16px;flex-wrap:wrap}
.stat{background:#0f172a;border-radius:8px;padding:12px 16px;border:1px solid #334155;min-width:100px;text-align:center}
.stat .num{font-size:1.5rem;font-weight:700;color:#0c7c84}
.stat .label{font-size:.7rem;color:#94a3b8;text-transform:uppercase;letter-spacing:.04em;margin-top:2px}

.future-site{opacity:.4;pointer-events:none;position:relative}
.future-site::after{content:'Coming Soon';position:absolute;right:16px;top:50%;transform:translateY(-50%);background:#334155;color:#94a3b8;padding:3px 10px;border-radius:4px;font-size:.7rem;font-weight:600}
</style>
</head>
<body>

<div class="header">
    <h1>Property Listing Scraper</h1>
    <p>Import property listings from external sites into the Build in Lombok database.</p>
    <div class="header-nav">
        <a href="console.php">← Admin Console</a>
        <a href="import.php">Google Maps Importer</a>
        <a href="scrape_enrich.php">Scrape & Enrich</a>
        <a href="?logout=1">Logout</a>
    </div>
</div>

<div class="container">

<?php if (!$running): ?>

    <div class="card">
        <h2><span class="icon" style="background:#0c7c84;">🌐</span> Listing Sources</h2>
        <p style="font-size:.8rem;color:#94a3b8;margin-bottom:16px;">Select a source site to import property listings from. Listings are automatically de-duplicated.</p>

        <!-- Rumah123 -->
        <div class="site-card">
            <div class="site-logo" style="background:#e11d48;">R123</div>
            <div class="site-info">
                <h3>Rumah123.com</h3>
                <p>Indonesia's leading property portal — Land, villas, and houses in Lombok Tengah.</p>
            </div>
        </div>
        <form method="POST" class="scrape-form">
            <input type="hidden" name="start_scrape" value="1">
            <input type="hidden" name="scrape_site" value="rumah123">
            <div class="form-group">
                <label>Search Type</label>
                <select name="search_type">
                    <option value="all">Land + Houses/Villas</option>
                    <option value="land">Land Only (Tanah)</option>
                    <option value="houses">Houses/Villas Only</option>
                </select>
            </div>
            <div class="form-group">
                <label>Max Listings</label>
                <input type="number" name="max_listings" value="30" min="1" max="2000" style="width:100px;">
            </div>
            <button type="submit" class="btn btn-primary">▶ Start Scraping</button>
        </form>

        <!-- Future sites -->
        <div class="site-card future-site" style="margin-top:20px;">
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

<?php else: ?>

    <div class="card">
        <h2><span class="icon" style="background:#e11d48;">R123</span> Scraping Rumah123.com</h2>
        <div class="stats-bar">
            <div class="stat"><div class="label">Source</div><div class="num" style="font-size:1rem;">Rumah123</div></div>
            <div class="stat"><div class="label">Type</div><div class="num" style="font-size:1rem;"><?= htmlspecialchars($search_type) ?></div></div>
            <div class="stat"><div class="label">Max Listings</div><div class="num"><?= $max_listings ?></div></div>
        </div>

        <div class="log-container" id="log">
<?php
    // Start output buffering for real-time progress
    if (ob_get_level()) ob_end_flush();
    ob_implicit_flush(true);

    scrape_rumah123($search_type, $max_listings);
?>
        </div>

        <div style="margin-top:16px;text-align:center;">
            <a href="scrape_listings.php" class="btn btn-primary">← Back to Scraper</a>
            <a href="console.php?s=listings" class="btn btn-primary" style="background:#334155;margin-left:8px;">View Listings in Console</a>
        </div>
    </div>

<?php endif; ?>

</div>

<script>
// Auto-scroll log to bottom
var logEl = document.getElementById('log');
if (logEl) {
    var observer = new MutationObserver(function() {
        logEl.scrollTop = logEl.scrollHeight;
    });
    observer.observe(logEl, { childList: true, subtree: true });
}
</script>

</body>
</html>
<?php

// ─── LOGIN PAGE ──────────────────────────────────────────────────────
function show_login($error = '') {
?><!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<meta name="robots" content="noindex,nofollow"><title>Listing Scraper Login</title>
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
    <h1>Listing Scraper</h1>
    <p>Build in Lombok — Admin Tool</p>
    <?php if ($error): ?><div class="err"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <form method="POST">
        <label>Username</label><input type="text" name="username" required autofocus>
        <label>Password</label><input type="password" name="password" required>
        <button type="submit" name="login" value="1">Login</button>
    </form>
</div>
</body></html>
<?php } ?>
