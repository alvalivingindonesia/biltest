<?php
/**
 * Build in Lombok — Admin: Google Maps HTML Importer
 * 
 * Password-protected admin page for importing Google Maps search results.
 * Place at: /admin/import.php
 * 
 * SECURITY: This page is not linked from any menu. Access via direct URL only.
 *           Protected by session-based password. robots.txt blocks /admin/.
 */

session_start();

// ─── CONFIG — loaded from private config outside public web root ─────
require_once('/home/rovin629/config/biltest_config.php');
// Config file defines: ADMIN_USER, ADMIN_PASS_HASH, DB_HOST, DB_NAME, DB_USER, DB_PASS

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
    header('Location: import.php');
    exit;
}
if (empty($_SESSION['admin_auth'])) {
    show_login($auth_error);
    exit;
}

// ─── CATEGORY TREE (must match schema.sql) ───────────────────────────
$CATEGORY_TREE = [
    'builders_trades' => [
        'label' => 'Builders & Trades',
        'cats' => [
            'general_contractor' => 'General Contractor',
            'carpenter' => 'Carpenter / Joiner',
            'mason' => 'Mason / Concrete Worker',
            'roofer' => 'Roofer',
            'plumber' => 'Plumber',
            'electrician' => 'Electrician',
            'painter' => 'Painter / Finisher',
            'tiler' => 'Tiler',
        ],
    ],
    'professional_services' => [
        'label' => 'Professional Services',
        'cats' => [
            'architect' => 'Architect',
            'interior_designer' => 'Interior Designer',
            'structural_engineer' => 'Structural Engineer',
            'mep_engineer' => 'MEP Engineer',
            'civil_engineer' => 'Civil Engineer',
            'quantity_surveyor' => 'Quantity Surveyor / Cost Consultant',
            'project_manager' => 'Project Manager / Construction Manager',
        ],
    ],
    'specialist_contractors' => [
        'label' => 'Specialist Contractors',
        'cats' => [
            'pool_contractor' => 'Pool Builder / Pool Contractor',
            'solar_installer' => 'Solar / PV Installer',
            'waterproofing' => 'Waterproofing Specialist',
            'glazing_contractor' => 'Windows & Doors / Glazing Contractor',
            'metalwork_contractor' => 'Steel / Welding / Metalwork Contractor',
            'hvac_contractor' => 'Air-conditioning / HVAC Contractor',
            'landscaping_contractor' => 'Landscaping Contractor',
        ],
    ],
    'suppliers_materials' => [
        'label' => 'Suppliers & Materials',
        'cats' => [
            'building_materials_store' => 'General Building Materials Store',
            'timber_workshop' => 'Timber & Carpentry Workshop',
            'tiles_stone_supplier' => 'Tiles & Stone Finishes Supplier',
            'sanitary_supplier' => 'Sanitary Ware & Plumbing Fixtures Supplier',
            'lighting_supplier' => 'Lighting & Electrical Fixtures Supplier',
            'sand_supplier' => 'Sand Supplier',
            'gravel_supplier' => 'Gravel & Riverstone Supplier',
            'aggregate_supplier' => 'Crushed Stone / Aggregate Supplier',
            'earth_fill_supplier' => 'Earth Fill / Compacted Fill Supplier',
            'topsoil_supplier' => 'Topsoil & Landscaping Materials Supplier',
        ],
    ],
];

// ─── KEYWORD → CATEGORY MAPPING ─────────────────────────────────────
// Maps Google Maps category text AND business name keywords to our categories.
// Includes Indonesian (Bahasa) translations.
$KEYWORD_MAP = [
    // --- Builders & Trades ---
    'general_contractor' => [
        'general contractor', 'kontraktor umum', 'kontraktor', 'construction company',
        'building contractor', 'perusahaan konstruksi', 'home builder', 'pembangun rumah',
        'construction contractor', 'kontraktor bangunan',
    ],
    'carpenter' => [
        'carpenter', 'joiner', 'tukang kayu', 'woodworker', 'woodwork', 'furniture maker',
        'furniture manufacturer', 'furniture store', 'furniture wholesaler', 'millwork',
        'kitchen furniture', 'fitted furniture', 'tukang mebel', 'mebel', 'meubel',
        'cabinet maker', 'cabinetry', 'tukang lemari',
    ],
    'mason' => [
        'mason', 'tukang batu', 'concrete worker', 'bricklayer', 'tukang semen',
        'tukang beton', 'concrete contractor',
    ],
    'roofer' => [
        'roofer', 'roofing', 'tukang atap', 'atap',
    ],
    'plumber' => [
        'plumber', 'plumbing', 'tukang ledeng', 'tukang pipa', 'pipa air',
        'sanitary installer',
    ],
    'electrician' => [
        'electrician', 'electrical', 'tukang listrik', 'listrik', 'electrical contractor',
        'lighting contractor', 'instalasi listrik',
    ],
    'painter' => [
        'painter', 'painting', 'tukang cat', 'finisher', 'finishing',
        'tukang finishing', 'wallpaper',
    ],
    'tiler' => [
        'tiler', 'tiling', 'tukang keramik', 'tukang ubin', 'tile installer',
    ],

    // --- Professional Services ---
    'architect' => [
        'architect', 'arsitek', 'architecture', 'arsitektur', 'architectural designer',
        'architectural', 'studio arsitektur', 'design architect',
    ],
    'interior_designer' => [
        'interior designer', 'interior design', 'desain interior', 'desainer interior',
        'interior architect', 'interior decorator', 'interior construction',
        'interior', 'dekorasi interior',
    ],
    'structural_engineer' => [
        'structural engineer', 'insinyur struktur', 'structural', 'struktur',
    ],
    'mep_engineer' => [
        'mep engineer', 'mep', 'mechanical electrical', 'insinyur mep',
    ],
    'civil_engineer' => [
        'civil engineer', 'insinyur sipil', 'teknik sipil',
    ],
    'quantity_surveyor' => [
        'quantity surveyor', 'cost consultant', 'qs', 'estimator', 'penghitung biaya',
    ],
    'project_manager' => [
        'project manager', 'construction manager', 'manajer proyek',
        'manajer konstruksi', 'site manager',
    ],

    // --- Specialist Contractors ---
    'pool_contractor' => [
        'pool builder', 'pool contractor', 'pool specialist', 'swimming pool',
        'kolam renang', 'tukang kolam',
    ],
    'solar_installer' => [
        'solar', 'pv installer', 'solar panel', 'surya', 'panel surya', 'photovoltaic',
    ],
    'waterproofing' => [
        'waterproofing', 'waterproof', 'anti bocor', 'tukang waterproofing',
    ],
    'glazing_contractor' => [
        'windows', 'doors', 'glazing', 'glass', 'aluminium', 'aluminum',
        'kaca', 'pintu', 'jendela', 'kusen', 'door shop',
    ],
    'metalwork_contractor' => [
        'steel', 'welding', 'welder', 'metalwork', 'metal fabrication',
        'tukang las', 'las', 'besi', 'ironwork',
    ],
    'hvac_contractor' => [
        'hvac', 'air conditioning', 'ac', 'pendingin ruangan', 'tukang ac',
    ],
    'landscaping_contractor' => [
        'landscaping', 'landscaper', 'garden', 'taman', 'tukang taman',
        'landscape contractor', 'pertamanan',
    ],

    // --- Suppliers & Materials ---
    'building_materials_store' => [
        'building materials', 'hardware store', 'bahan bangunan', 'toko bangunan',
        'material supplier', 'building supply', 'home improvement',
        'toko material', 'depot bangunan',
    ],
    'timber_workshop' => [
        'timber', 'lumber', 'kayu', 'sawmill', 'wood processing',
        'penggergajian', 'bengkel kayu',
    ],
    'tiles_stone_supplier' => [
        'tiles', 'stone', 'ceramic', 'porcelain', 'marble', 'granite',
        'keramik', 'batu alam', 'ubin', 'marmer',
    ],
    'sanitary_supplier' => [
        'sanitary', 'plumbing fixtures', 'bathroom', 'toilet', 'sanitasi',
        'sanitary ware', 'kamar mandi',
    ],
    'lighting_supplier' => [
        'lighting', 'electrical fixtures', 'lamp', 'lampu', 'toko lampu',
        'light fixture',
    ],
    'sand_supplier' => [
        'sand supplier', 'pasir', 'toko pasir',
    ],
    'gravel_supplier' => [
        'gravel', 'riverstone', 'kerikil', 'batu kali', 'batu sungai',
    ],
    'aggregate_supplier' => [
        'aggregate', 'crushed stone', 'batu pecah', 'split',
    ],
    'earth_fill_supplier' => [
        'earth fill', 'compacted fill', 'tanah urug', 'urugan',
    ],
    'topsoil_supplier' => [
        'topsoil', 'landscaping materials', 'tanah subur',
    ],
];

// ─── FUNCTIONS ───────────────────────────────────────────────────────

function get_db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }
    return $pdo;
}

function slugify(string $text): string {
    $text = strtolower(trim($text));
    $text = preg_replace('/[^a-z0-9\s-]/', '', $text);
    $text = preg_replace('/[\s-]+/', '-', $text);
    return trim($text, '-');
}

/**
 * Try to auto-detect a category from Google Maps category text + business name.
 * Returns ['group_key' => ..., 'category_key' => ..., 'confidence' => 'high'|'medium'|'low']
 */
function detect_category(string $gmaps_category, string $business_name, array $keyword_map, array $category_tree): array {
    $gmaps_lower = strtolower(html_entity_decode($gmaps_category));
    $name_lower = strtolower(html_entity_decode($business_name));
    $combined = $gmaps_lower . ' ' . $name_lower;

    $best_cat = null;
    $best_score = 0;
    $best_confidence = 'low';

    foreach ($keyword_map as $cat_key => $keywords) {
        foreach ($keywords as $kw) {
            $score = 0;
            // Check Google Maps category (higher weight)
            if (stripos($gmaps_lower, $kw) !== false) {
                // Exact match of full category string
                if ($gmaps_lower === $kw) {
                    $score = 100;
                } else {
                    $score = 70 + (strlen($kw) / strlen($gmaps_lower)) * 20;
                }
            }
            // Check business name (lower weight)
            if (stripos($name_lower, $kw) !== false) {
                $score = max($score, 40 + (strlen($kw) / strlen($name_lower)) * 20);
            }

            if ($score > $best_score) {
                $best_score = $score;
                $best_cat = $cat_key;
            }
        }
    }

    if (!$best_cat) {
        return ['group_key' => '', 'category_key' => '', 'confidence' => 'none'];
    }

    // Find group for this category
    $group_key = '';
    foreach ($category_tree as $gk => $gdata) {
        if (isset($gdata['cats'][$best_cat])) {
            $group_key = $gk;
            break;
        }
    }

    $confidence = 'low';
    if ($best_score >= 70) $confidence = 'high';
    elseif ($best_score >= 40) $confidence = 'medium';

    return ['group_key' => $group_key, 'category_key' => $best_cat, 'confidence' => $confidence];
}

/**
 * Detect area from GPS coordinates using bounding boxes.
 * Lombok approximate zones based on well-known landmarks.
 */
function detect_area_from_coords(float $lat, float $lng): string {
    // Bounding boxes: [south_lat, north_lat, west_lng, east_lng]
    $zones = [
        'kuta'            => [-8.95, -8.87, 116.15, 116.35],  // South coast: Kuta, Gerupuk, Tanjung Aan
        'selong_belanak'  => [-8.92, -8.85, 116.05, 116.15],  // Selong Belanak, Mawun area
        'ekas'            => [-8.88, -8.78, 116.38, 116.55],  // Ekas Bay
        'senggigi'        => [-8.55, -8.45, 116.02, 116.12],  // Senggigi coast
        'gili_islands'    => [-8.38, -8.32, 116.02, 116.10],  // Gili T, Gili Air, Gili Meno
        'north_lombok'    => [-8.42, -8.28, 116.10, 116.45],  // Tanjung, Bangsal, Senaru
        'mataram'         => [-8.65, -8.52, 116.05, 116.18],  // Mataram city area
    ];

    foreach ($zones as $area_key => $box) {
        if ($lat >= $box[0] && $lat <= $box[1] && $lng >= $box[2] && $lng <= $box[3]) {
            return $area_key;
        }
    }

    // If on Lombok island but no specific zone matched
    if ($lat >= -9.0 && $lat <= -8.2 && $lng >= 115.8 && $lng <= 116.7) {
        return 'other_lombok';
    }

    return '';
}

/**
 * Parse a Google Maps saved HTML file and extract business listings.
 * Uses article-boundary splitting for reliable per-listing isolation.
 */
function parse_gmaps_html(string $html): array {
    $listings = [];

    // Split by article boundaries — each listing is a <div role="article" aria-label="NAME">
    // This is far more reliable than splitting on rating labels.
    preg_match_all(
        '/<div[^>]*role="article"[^>]*aria-label="([^"]+)"/',
        $html, $art_matches, PREG_OFFSET_CAPTURE
    );

    if (empty($art_matches[0])) return $listings;

    $article_count = count($art_matches[0]);

    for ($a = 0; $a < $article_count; $a++) {
        $name = html_entity_decode($art_matches[1][$a][0], ENT_QUOTES, 'UTF-8');
        $start = $art_matches[0][$a][1];
        $end = ($a + 1 < $article_count) ? $art_matches[0][$a + 1][1] : strlen($html);
        $chunk = substr($html, $start, $end - $start);

        // Skip ad entries
        if (stripos($name, 'Why this ad') !== false) continue;

        // ── Rating ──
        if (!preg_match('/aria-label="(\d+\.\d+) stars (\d+) Reviews?"/', $chunk, $rm)) {
            continue; // No rating = skip (we need rating for import filters)
        }
        $rating = (float)$rm[1];
        $reviews = (int)$rm[2];

        // ── Google Maps place URL & coordinates ──
        $gmaps_url = '';
        $latitude = null;
        $longitude = null;
        if (preg_match('/href="(https:\/\/www\.google\.com\/maps\/place\/[^"]+)"/', $chunk, $um)) {
            $gmaps_url = html_entity_decode($um[1], ENT_QUOTES, 'UTF-8');
            if (preg_match('/!3d(-?\d+\.\d+)!4d(-?\d+\.\d+)/', $gmaps_url, $coord)) {
                $latitude = (float)$coord[1];
                $longitude = (float)$coord[2];
            }
        }

        // ── Extract visible text fragments from chunk ──
        preg_match_all('/>[^<]{3,120}</', $chunk, $text_matches);
        $texts = [];
        foreach ($text_matches[0] as $t) {
            $t = trim(strip_tags('<div' . $t . '/div>'));
            $t = html_entity_decode($t, ENT_QUOTES, 'UTF-8');
            if (strlen($t) > 2 && !preg_match('/[={}]|function|var |window|typeof|script/i', $t)) {
                $texts[] = $t;
            }
        }

        // Parse fields: skip past rating text + (count) to get category, address, phone
        $gmaps_category = '';
        $address = '';
        $phone = '';

        $field_start = 0;
        foreach ($texts as $idx => $t) {
            if (preg_match('/^\(\d+\)$/', $t)) {
                $field_start = $idx + 1;
                break;
            }
        }
        $remaining = array_slice($texts, $field_start);

        foreach ($remaining as $idx => $t) {
            if ($idx === 0 && !preg_match('/^(Jl\.|Gg\.|Jalan|Desa|\d|Open|Closed|·)/', $t)) {
                $gmaps_category = $t;
                continue;
            }
            if (!$address && preg_match('/(Jl\.|Gg\.|Jalan|No\.|Blok|Desa|Komplek|\+[A-Z0-9])/', $t)) {
                $address = $t;
            }
            if (!$phone && preg_match('/^0\d{2,4}[-\s.]?\d{3,4}[-\s.]?\d{3,6}$/', $t)) {
                $phone = $t;
            }
        }

        // ── Also extract phone from UsdlK span (Google's phone class) ──
        if (!$phone && preg_match('/class="UsdlK"[^>]*>([^<]+)</', $chunk, $ph_m)) {
            $candidate = trim(html_entity_decode($ph_m[1], ENT_QUOTES, 'UTF-8'));
            if (preg_match('/^0\d{2,4}[-\s.]?\d{3,4}[-\s.]?\d{3,6}$/', $candidate)) {
                $phone = $candidate;
            }
        }

        // ── Website URL ──
        $website_url = '';
        // Method 1: "Website" action button (data-value="Website" ... href="...")
        if (preg_match('/data-value="Website"[^>]*href="([^"]+)"/', $chunk, $ws_m)) {
            $website_url = html_entity_decode($ws_m[1], ENT_QUOTES, 'UTF-8');
        }
        // Method 2: aria-label visit website
        if (!$website_url && preg_match('/aria-label="Visit[^"]*website"[^>]*href="([^"]+)"/i', $chunk, $ws_m)) {
            $website_url = html_entity_decode($ws_m[1], ENT_QUOTES, 'UTF-8');
        }
        // Method 3: Fallback — text-based domain detection
        if (!$website_url) {
            foreach ($remaining as $t) {
                $t_trimmed = trim($t);
                if (preg_match('#^https?://#i', $t_trimmed)) {
                    $website_url = $t_trimmed;
                    break;
                }
                if (preg_match('/^[a-z0-9][a-z0-9\-]*\.(com|co\.id|id|net|org|io|biz|info|co|xyz)(\.[a-z]{2,3})?(\/.*)?$/i', $t_trimmed)) {
                    $website_url = 'https://' . rtrim($t_trimmed, '.');
                    break;
                }
            }
        }

        // ── Area detection: text then coordinates ──
        $detected_area = '';
        $search_text = strtolower($address . ' ' . $name . ' ' . $gmaps_category . ' ' . implode(' ', array_slice($remaining, 0, 12)));
        $seg_text_lower = strtolower(strip_tags($chunk));
        $search_text .= ' ' . $seg_text_lower;

        $area_patterns = [
            'selong_belanak'  => ['selong belanak', 'belanak', 'mawun', 'tampah'],
            'kuta'            => ['kuta', 'kuta lombok', 'kuta selatan', 'tanjung aan', 'gerupuk', 'are guling'],
            'senggigi'        => ['senggigi', 'batu layar', 'batulayar', 'mangsit', 'kerandangan', 'lendang luar'],
            'ekas'            => ['ekas', 'awang', 'tanjung ringgit', 'kaliantan'],
            'north_lombok'    => ['tanjung', 'gondang', 'senaru', 'bayan', 'lombok utara', 'north lombok', 'medana', 'sire', 'bangsal', 'pemenang'],
            'gili_islands'    => ['gili trawangan', 'gili air', 'gili meno', 'gili'],
            'mataram'         => ['mataram', 'ampenan', 'cakranegara', 'bertais', 'pagutan', 'sekarbela', 'kediri', 'labuapi'],
            'other_lombok'    => ['praya', 'lombok tengah', 'central lombok', 'lombok barat', 'west lombok',
                                  'gerung', 'lembar', 'sekotong', 'lombok timur', 'east lombok', 'labuhan',
                                  'sukarara', 'jonggat', 'kopang', 'pujut', 'batukliang'],
        ];
        foreach ($area_patterns as $area_key => $keywords) {
            foreach ($keywords as $kw) {
                if (strpos($search_text, $kw) !== false) {
                    if ($kw === 'selong' && strpos($search_text, 'belanak') === false) {
                        $detected_area = 'other_lombok';
                    } else {
                        $detected_area = $area_key;
                    }
                    break 2;
                }
            }
        }
        if (!$detected_area && $latitude !== null && $longitude !== null) {
            $detected_area = detect_area_from_coords($latitude, $longitude);
        }
        if (!$detected_area) {
            $detected_area = 'mataram';
        }

        $listings[] = [
            'name' => $name,
            'rating' => $rating,
            'reviews' => $reviews,
            'gmaps_category' => $gmaps_category,
            'address' => $address,
            'phone' => $phone,
            'gmaps_url' => $gmaps_url,
            'latitude' => $latitude,
            'longitude' => $longitude,
            'website_url' => $website_url,
            'detected_area' => $detected_area,
        ];
    }

    return $listings;
}


// ─── HANDLE SAVE TO DB ───────────────────────────────────────────────
$save_message = '';
$save_errors = [];
if (isset($_POST['save_to_db']) && !empty($_POST['items'])) {
    $import_type = $_POST['import_type'] ?? 'provider';
    try {
        $db = get_db();
        $db->beginTransaction();

        if ($import_type === 'developer') {
            // ─── DEVELOPER SAVE ──────────────────────────────
            $insert_dev = $db->prepare(
                "INSERT INTO developers (slug, name, short_description, description,
                    google_maps_url, google_rating, google_review_count,
                    phone, whatsapp_number, website_url, languages, is_featured, badge, is_active)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, NULL, 1)
                 ON DUPLICATE KEY UPDATE
                    google_rating = VALUES(google_rating),
                    google_review_count = VALUES(google_review_count),
                    updated_at = CURRENT_TIMESTAMP"
            );

            $insert_tag = $db->prepare(
                "INSERT IGNORE INTO developer_tags (developer_id, tag) VALUES (?, ?)"
            );

            $insert_area = $db->prepare(
                "INSERT IGNORE INTO developer_areas (developer_id, area_key) VALUES (?, ?)"
            );

            $saved_count = 0;
            $updated_count = 0;
            foreach ($_POST['items'] as $idx => $item) {
                if (empty($item['selected'])) continue;

                $name = trim($item['name']);
                $slug = slugify($name);
                $short_desc = trim($item['short_description']);
                $description = trim($item['description']) ?: $short_desc;
                $gmaps_url = trim($item['gmaps_url']);
                $rating = (float)$item['rating'];
                $review_count = (int)$item['reviews'];
                $phone = trim($item['phone']);
                $whatsapp = trim($item['whatsapp'] ?? '');
                $website = trim($item['website_url'] ?? '');
                $languages = trim($item['languages'] ?? 'Bahasa only');
                $area = $item['area_key'] ?: 'mataram';

                // Overwrite existing record
                if (!empty($item['overwrite']) && !empty($item['existing_id'])) {
                    $upd = $db->prepare(
                        "UPDATE developers SET name=?, short_description=?, description=?,
                            google_maps_url=?, google_rating=?, google_review_count=?,
                            phone=?, whatsapp_number=?, website_url=?, languages=?,
                            updated_at=CURRENT_TIMESTAMP
                         WHERE id=?"
                    );
                    $upd->execute([
                        $name, $short_desc, $description,
                        $gmaps_url, $rating, $review_count,
                        $phone, $whatsapp, $website, $languages,
                        (int)$item['existing_id'],
                    ]);
                    // Update area
                    $del_area = $db->prepare("DELETE FROM developer_areas WHERE developer_id = ?");
                    $del_area->execute([(int)$item['existing_id']]);
                    $insert_area->execute([(int)$item['existing_id'], $area]);
                    $updated_count++;
                    continue;
                }

                // New record: duplicate slug check
                $check = $db->prepare("SELECT COUNT(*) FROM developers WHERE slug = ?");
                $check->execute([$slug]);
                if ($check->fetchColumn() > 0) {
                    $suffix = 2;
                    while (true) {
                        $check->execute([$slug . '-' . $suffix]);
                        if ($check->fetchColumn() == 0) break;
                        $suffix++;
                    }
                    $slug .= '-' . $suffix;
                }

                $insert_dev->execute([
                    $slug, $name, $short_desc, $description,
                    $gmaps_url, $rating, $review_count,
                    $phone, $whatsapp, $website, $languages,
                ]);

                $dev_id = $db->lastInsertId();

                // Add area
                if ($area) {
                    $insert_area->execute([$dev_id, $area]);
                }

                // Add tag from Google Maps category
                if (!empty($item['gmaps_category'])) {
                    $insert_tag->execute([$dev_id, $item['gmaps_category']]);
                }

                $saved_count++;
            }

            $db->commit();
            $parts = [];
            if ($saved_count) $parts[] = "saved {$saved_count} new";
            if ($updated_count) $parts[] = "updated {$updated_count} existing";
            $save_message = "Successfully " . implode(' and ', $parts) . " developer(s).";

        } else {
            // ─── PROVIDER SAVE ────────────────────────────────
            $insert_provider = $db->prepare(
                "INSERT INTO providers (slug, name, group_key, category_key, area_key, short_description, description,
                    address, latitude, longitude, google_maps_url, google_rating, google_review_count,
                    phone, whatsapp_number, website_url, languages, is_featured, badge, is_active)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, NULL, 1)
                 ON DUPLICATE KEY UPDATE
                    google_rating = VALUES(google_rating),
                    google_review_count = VALUES(google_review_count),
                    updated_at = CURRENT_TIMESTAMP"
            );

            $insert_tag = $db->prepare(
                "INSERT IGNORE INTO provider_tags (provider_id, tag) VALUES (?, ?)"
            );

            $insert_pcat = $db->prepare(
                "INSERT IGNORE INTO provider_categories (provider_id, category_key) VALUES (?, ?)"
            );

            $saved_count = 0;
            $updated_count = 0;
            foreach ($_POST['items'] as $idx => $item) {
                if (empty($item['selected'])) continue;

                $name = trim($item['name']);
                $slug = slugify($name);
                $group = $item['group_key'];
                $category = $item['category_key'];
                $area = $item['area_key'] ?: 'mataram';
                $short_desc = trim($item['short_description']);
                $description = trim($item['description']) ?: $short_desc;
                $address = trim($item['address']);
                $lat = $item['latitude'] ?: null;
                $lng = $item['longitude'] ?: null;
                $gmaps_url = trim($item['gmaps_url']);
                $rating = (float)$item['rating'];
                $review_count = (int)$item['reviews'];
                $phone = trim($item['phone']);
                $whatsapp = trim($item['whatsapp'] ?? '');
                $website = trim($item['website_url'] ?? '');
                $languages = trim($item['languages'] ?? 'Bahasa only');

                if (!$group || !$category) {
                    $save_errors[] = "Skipped '{$name}': no category assigned.";
                    continue;
                }

                // Overwrite existing record
                if (!empty($item['overwrite']) && !empty($item['existing_id'])) {
                    $ex_id = (int)$item['existing_id'];
                    $upd = $db->prepare(
                        "UPDATE providers SET name=?, group_key=?, category_key=?, area_key=?,
                            short_description=?, description=?, address=?, latitude=?, longitude=?,
                            google_maps_url=?, google_rating=?, google_review_count=?,
                            phone=?, whatsapp_number=?, website_url=?, languages=?,
                            updated_at=CURRENT_TIMESTAMP
                         WHERE id=?"
                    );
                    $upd->execute([
                        $name, $group, $category, $area,
                        $short_desc, $description, $address, $lat, $lng,
                        $gmaps_url, $rating, $review_count,
                        $phone, $whatsapp, $website, $languages,
                        $ex_id,
                    ]);
                    // Update junction table
                    $db->prepare("DELETE FROM provider_categories WHERE provider_id=?")->execute([$ex_id]);
                    $insert_pcat->execute([$ex_id, $category]);
                    $updated_count++;
                    continue;
                }

                // New record: duplicate slug check
                $check = $db->prepare("SELECT COUNT(*) FROM providers WHERE slug = ?");
                $check->execute([$slug]);
                if ($check->fetchColumn() > 0) {
                    $suffix = 2;
                    while (true) {
                        $check->execute([$slug . '-' . $suffix]);
                        if ($check->fetchColumn() == 0) break;
                        $suffix++;
                    }
                    $slug .= '-' . $suffix;
                }

                $insert_provider->execute([
                    $slug, $name, $group, $category, $area,
                    $short_desc, $description, $address, $lat, $lng,
                    $gmaps_url, $rating, $review_count,
                    $phone, $whatsapp, $website, $languages,
                ]);

                $provider_id = $db->lastInsertId();

                // Write to junction table
                $insert_pcat->execute([$provider_id, $category]);

                if (!empty($item['gmaps_category'])) {
                    $insert_tag->execute([$provider_id, $item['gmaps_category']]);
                }

                $saved_count++;
            }

            $db->commit();
            $parts = [];
            if ($saved_count) $parts[] = "saved {$saved_count} new";
            if ($updated_count) $parts[] = "updated {$updated_count} existing";
            $save_message = "Successfully " . implode(' and ', $parts) . " provider(s).";
        }
    } catch (Exception $e) {
        if (isset($db) && $db->inTransaction()) $db->rollBack();
        $save_message = "Database error: " . $e->getMessage();
    }
}


// ─── HANDLE FILE UPLOAD & PARSE ──────────────────────────────────────
$parsed = null;
$parse_stats = null;
if (isset($_POST['parse']) && isset($_FILES['gmaps_file'])) {
    $file = $_FILES['gmaps_file'];
    if ($file['error'] === UPLOAD_ERR_OK && $file['size'] > 0) {
        $html = file_get_contents($file['tmp_name']);

        // Extract search query from the HTML title
        $search_query = '';
        if (preg_match('/<title>([^<]+)<\/title>/', $html, $tm)) {
            $search_query = preg_replace('/\s*-\s*Google Maps$/', '', html_entity_decode($tm[1]));
        }

        $min_reviews = (int)($_POST['min_reviews'] ?? 2);
        $min_rating = (float)($_POST['min_rating'] ?? 3.0);

        $raw = parse_gmaps_html($html);

        // Apply filters and detect categories
        $auto_approved = [];
        $needs_review = [];
        $rejected = [];

        foreach ($raw as $item) {
            // Skip ads
            if (stripos($item['name'], 'Why this ad') !== false) {
                $rejected[] = array_merge($item, ['reason' => 'Ad listing']);
                continue;
            }

            // Check minimum reviews
            if ($item['reviews'] < $min_reviews) {
                $rejected[] = array_merge($item, ['reason' => "Only {$item['reviews']} review(s) (min: {$min_reviews})"]);
                continue;
            }
            // Check minimum rating
            if ($item['rating'] < $min_rating) {
                $rejected[] = array_merge($item, ['reason' => "Rating {$item['rating']} (min: {$min_rating})"]);
                continue;
            }

            // Detect category
            $detection = detect_category($item['gmaps_category'], $item['name'], $KEYWORD_MAP, $CATEGORY_TREE);
            $item['detected_group'] = $detection['group_key'];
            $item['detected_category'] = $detection['category_key'];
            $item['confidence'] = $detection['confidence'];
            // Carry through parsed website + area
            $item['website_url'] = $item['website_url'] ?? '';
            $item['detected_area'] = $item['detected_area'] ?? 'mataram';

            if ($detection['confidence'] === 'high') {
                $auto_approved[] = $item;
            } else {
                $needs_review[] = $item;
            }
        }

        // ─── Check for existing DB entries ─────────────────────────
        $import_type = $_POST['import_type'] ?? 'provider';
        $all_passed = array_merge($auto_approved, $needs_review);
        $existing_count = 0;
        try {
            $db = get_db();
            $table = ($import_type === 'developer') ? 'developers' : 'providers';
            foreach ($all_passed as &$item) {
                $item['existing'] = null;
                // Match by google_maps_url first (most reliable)
                if (!empty($item['gmaps_url'])) {
                    $stmt = $db->prepare("SELECT * FROM `{$table}` WHERE google_maps_url = ? LIMIT 1");
                    $stmt->execute([$item['gmaps_url']]);
                    $existing = $stmt->fetch();
                    if ($existing) {
                        $item['existing'] = $existing;
                        $existing_count++;
                        continue;
                    }
                }
                // Fallback: match by name (case-insensitive)
                $stmt = $db->prepare("SELECT * FROM `{$table}` WHERE LOWER(name) = LOWER(?) LIMIT 1");
                $stmt->execute([trim($item['name'])]);
                $existing = $stmt->fetch();
                if ($existing) {
                    $item['existing'] = $existing;
                    $existing_count++;
                }
            }
            unset($item);
            // Re-split after enrichment
            $auto_approved = [];
            $needs_review = [];
            foreach ($all_passed as $item) {
                if ($item['confidence'] === 'high') {
                    $auto_approved[] = $item;
                } else {
                    $needs_review[] = $item;
                }
            }
        } catch (Exception $e) {
            // DB check is non-fatal; continue without duplicate info
        }

        $parsed = [
            'search_query' => $search_query,
            'import_type' => $import_type,
            'auto_approved' => $auto_approved,
            'needs_review' => $needs_review,
            'rejected' => $rejected,
        ];

        $parse_stats = [
            'total' => count($raw),
            'passed_filter' => count($auto_approved) + count($needs_review),
            'auto_matched' => count($auto_approved),
            'needs_review' => count($needs_review),
            'rejected' => count($rejected),
            'existing' => $existing_count,
        ];
    }
}


// ─── RENDER ──────────────────────────────────────────────────────────
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title>Admin — Import | Build in Lombok</title>
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', system-ui, sans-serif; background: #f5f5f5; color: #1a1a1a; line-height: 1.5; }
.container { max-width: 1200px; margin: 0 auto; padding: 16px; }
h1 { font-size: 1.5rem; margin-bottom: 8px; }
h2 { font-size: 1.15rem; margin: 24px 0 12px; padding-bottom: 6px; border-bottom: 2px solid #0c7c84; }
h3 { font-size: 1rem; margin: 16px 0 8px; }

.topbar { background: #0c7c84; color: #fff; padding: 12px 16px; display: flex; align-items: center; justify-content: space-between; }
.topbar a { color: #fff; text-decoration: none; opacity: 0.8; font-size: 0.85rem; }
.topbar a:hover { opacity: 1; }

.card { background: #fff; border-radius: 8px; padding: 20px; margin-bottom: 16px; box-shadow: 0 1px 3px rgba(0,0,0,0.08); }

.form-row { display: flex; gap: 12px; align-items: flex-end; flex-wrap: wrap; margin-bottom: 12px; }
.form-group { display: flex; flex-direction: column; gap: 4px; }
.form-group label { font-size: 0.8rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.03em; color: #555; }
input[type="file"], input[type="text"], input[type="number"], input[type="password"], select, textarea {
    padding: 8px 10px; border: 1px solid #d0d0d0; border-radius: 5px; font-size: 0.9rem; font-family: inherit;
}
textarea { width: 100%; resize: vertical; min-height: 44px; }
select { min-width: 160px; }

.btn { padding: 8px 18px; border: none; border-radius: 5px; font-size: 0.9rem; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; }
.btn-primary { background: #0c7c84; color: #fff; }
.btn-primary:hover { background: #0a6a70; }
.btn-success { background: #16a34a; color: #fff; }
.btn-success:hover { background: #138a3e; }
.btn-sm { padding: 5px 12px; font-size: 0.8rem; }
.btn-outline { background: transparent; border: 1px solid #d0d0d0; color: #333; }
.btn-outline:hover { background: #f0f0f0; }

.stats { display: flex; gap: 16px; flex-wrap: wrap; margin: 16px 0; }
.stat { padding: 12px 20px; border-radius: 6px; text-align: center; min-width: 100px; }
.stat-num { font-size: 1.5rem; font-weight: 700; }
.stat-label { font-size: 0.75rem; text-transform: uppercase; opacity: 0.8; }
.stat-blue { background: #e0f2fe; color: #0369a1; }
.stat-green { background: #dcfce7; color: #16a34a; }
.stat-yellow { background: #fef9c3; color: #a16207; }
.stat-red { background: #fee2e2; color: #dc2626; }

table { width: 100%; border-collapse: collapse; font-size: 0.85rem; }
th, td { padding: 8px 10px; text-align: left; border-bottom: 1px solid #eee; vertical-align: top; }
th { font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.03em; color: #666; background: #fafafa; position: sticky; top: 0; }
tr:hover { background: #fafafa; }
.table-wrap { max-height: 600px; overflow-y: auto; border: 1px solid #eee; border-radius: 6px; }

.badge { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 0.72rem; font-weight: 600; }
.badge-high { background: #dcfce7; color: #16a34a; }
.badge-medium { background: #fef9c3; color: #a16207; }
.badge-low { background: #fee2e2; color: #dc2626; }
.badge-none { background: #f3f4f6; color: #6b7280; }
.badge-exists { background: #dbeafe; color: #1d4ed8; }

.row-exists { background: #eff6ff !important; border-left: 3px solid #3b82f6; }
.row-exists:hover { background: #dbeafe !important; }
.diff-cell { position: relative; }
.diff-old { display: block; font-size: 0.68rem; color: #dc2626; text-decoration: line-through; margin-top: 2px; }

.msg { padding: 12px 16px; border-radius: 6px; margin-bottom: 16px; }
.msg-success { background: #dcfce7; color: #16a34a; border: 1px solid #bbf7d0; }
.msg-error { background: #fee2e2; color: #dc2626; border: 1px solid #fecaca; }
.msg-info { background: #e0f2fe; color: #0369a1; border: 1px solid #bae6fd; }

.editable { background: #fffff0; border: 1px solid #e5e5c0; }
.checkbox-cell { text-align: center; }
input[type="checkbox"] { width: 18px; height: 18px; cursor: pointer; }
.truncate { max-width: 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.small-select { font-size: 0.8rem; padding: 4px 6px; max-width: 180px; }
.small-input { font-size: 0.8rem; padding: 4px 6px; width: 100%; }
.select-all-row { padding: 8px 10px; background: #f9fafb; border-bottom: 2px solid #ddd; }
</style>
</head>
<body>

<div class="topbar">
    <div><strong>Build in Lombok</strong> — Admin Import</div>
    <a href="?logout=1">Logout</a>
</div>

<div class="container">

<?php if ($save_message): ?>
    <div class="msg <?= strpos($save_message, 'error') !== false ? 'msg-error' : 'msg-success' ?>">
        <?= htmlspecialchars($save_message) ?>
    </div>
    <?php foreach ($save_errors as $err): ?>
        <div class="msg msg-error"><?= htmlspecialchars($err) ?></div>
    <?php endforeach; ?>
<?php endif; ?>

<!-- UPLOAD FORM -->
<div class="card">
    <h1>Import Google Maps Listings</h1>
    <p style="color:#666;margin-bottom:16px;">Upload a saved Google Maps search results HTML file. Listings are parsed, filtered, and categorized automatically.</p>

    <form method="POST" enctype="multipart/form-data">
        <div class="form-row">
            <div class="form-group">
                <label>Import As</label>
                <select name="import_type" style="min-width:140px;">
                    <option value="provider" <?= ($_POST['import_type'] ?? 'provider') === 'provider' ? 'selected' : '' ?>>Provider</option>
                    <option value="developer" <?= ($_POST['import_type'] ?? '') === 'developer' ? 'selected' : '' ?>>Developer</option>
                </select>
            </div>
            <div class="form-group">
                <label>Google Maps HTML File</label>
                <input type="file" name="gmaps_file" accept=".html,.htm" required>
            </div>
            <div class="form-group">
                <label>Min Reviews</label>
                <input type="number" name="min_reviews" value="2" min="0" max="100" style="width:80px;">
            </div>
            <div class="form-group">
                <label>Min Rating</label>
                <input type="number" name="min_rating" value="3.0" min="1" max="5" step="0.1" style="width:80px;">
            </div>
            <div class="form-group">
                <label>&nbsp;</label>
                <button type="submit" name="parse" class="btn btn-primary">Parse & Preview</button>
            </div>
        </div>
    </form>
</div>

<?php if ($parsed): ?>
<!-- PARSE RESULTS -->
<div class="card">
    <h2>Parse Results: "<?= htmlspecialchars($parsed['search_query']) ?>" <span style="font-size:0.8rem;font-weight:400;color:#666;">— importing as <?= $parsed['import_type'] === 'developer' ? 'Developer' : 'Provider' ?></span></h2>

    <div class="stats">
        <div class="stat stat-blue">
            <div class="stat-num"><?= $parse_stats['total'] ?></div>
            <div class="stat-label">Total Found</div>
        </div>
        <div class="stat stat-green">
            <div class="stat-num"><?= $parse_stats['auto_matched'] ?></div>
            <div class="stat-label">Auto-Matched</div>
        </div>
        <div class="stat stat-yellow">
            <div class="stat-num"><?= $parse_stats['needs_review'] ?></div>
            <div class="stat-label">Needs Review</div>
        </div>
        <div class="stat stat-red">
            <div class="stat-num"><?= $parse_stats['rejected'] ?></div>
            <div class="stat-label">Rejected</div>
        </div>
        <?php if ($parse_stats['existing'] > 0): ?>
        <div class="stat" style="background:#dbeafe;color:#1d4ed8;">
            <div class="stat-num"><?= $parse_stats['existing'] ?></div>
            <div class="stat-label">Already in DB</div>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- SAVE FORM -->
<form method="POST" id="saveForm">
    <input type="hidden" name="save_to_db" value="1">
    <input type="hidden" name="import_type" value="<?= htmlspecialchars($parsed['import_type'] ?? 'provider') ?>">

<?php
    // Build flat category list for dropdown
    $flat_cats = [];
    foreach ($CATEGORY_TREE as $gk => $gdata) {
        foreach ($gdata['cats'] as $ck => $cl) {
            $flat_cats[] = ['group_key' => $gk, 'group_label' => $gdata['label'], 'cat_key' => $ck, 'cat_label' => $cl];
        }
    }

    $all_items = array_merge($parsed['auto_approved'], $parsed['needs_review']);
    if (!empty($all_items)):
?>

<!-- ITEMS TO IMPORT -->
<div class="card">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">
        <h2 style="margin:0;border:none;">Listings to Import (<?= count($all_items) ?>)</h2>
        <div style="display:flex;gap:8px;">
            <button type="button" class="btn btn-sm btn-outline" onclick="toggleAll(true)">Select All</button>
            <button type="button" class="btn btn-sm btn-outline" onclick="toggleAll(false)">Deselect All</button>
            <button type="submit" class="btn btn-success">Save Selected to Database</button>
        </div>
    </div>

    <div class="table-wrap" style="margin-top:12px;">
    <table>
        <thead>
            <tr>
                <th style="width:40px;">✓</th>
                <th>Name</th>
                <th>Status</th>
                <th>Rating</th>
                <th>Links</th>
                <th>Maps Category</th>
                <?php if (($parsed['import_type'] ?? 'provider') === 'provider'): ?>
                <th>Our Category</th>
                <th>Match</th>
                <?php endif; ?>
                <th>Short Description</th>
                <th>Address</th>
                <th>Phone</th>
                <th>Website</th>
                <th>Area</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($all_items as $idx => $item):
            $is_auto = $item['confidence'] === 'high';
            $gk = $item['detected_group'];
            $ck = $item['detected_category'];
            $ex = $item['existing'] ?? null; // existing DB record or null
            $is_dup = ($ex !== null);

            // Build diff list for existing items
            $diffs = [];
            if ($is_dup) {
                if (abs((float)$ex['google_rating'] - $item['rating']) > 0.01)
                    $diffs['rating'] = $ex['google_rating'];
                if ((int)$ex['google_review_count'] !== $item['reviews'])
                    $diffs['reviews'] = $ex['google_review_count'];
                $ex_phone = $ex['phone'] ?? '';
                if ($item['phone'] && $ex_phone !== $item['phone'])
                    $diffs['phone'] = $ex_phone;
                $ex_website = $ex['website_url'] ?? '';
                if ($item['website_url'] && $ex_website !== $item['website_url'])
                    $diffs['website'] = $ex_website;
                if (($parsed['import_type'] ?? 'provider') === 'provider') {
                    $ex_cat = $ex['category_key'] ?? '';
                    if ($ck && $ex_cat && $ex_cat !== $ck)
                        $diffs['category'] = $ex_cat;
                    $ex_area = $ex['area_key'] ?? '';
                    if ($item['detected_area'] && $ex_area !== $item['detected_area'])
                        $diffs['area'] = $ex_area;
                }
            }

            $row_class = $is_dup ? 'row-exists' : (!$is_auto ? '' : '');
            $row_bg = !$is_dup && !$is_auto ? 'background:#fffbeb;' : '';
        ?>
            <tr class="<?= $row_class ?>" style="<?= $row_bg ?>">
                <td class="checkbox-cell">
                    <input type="checkbox" name="items[<?= $idx ?>][selected]" value="1" <?= ($is_auto && !$is_dup) ? 'checked' : '' ?>>
                    <?php if ($is_dup): ?>
                        <input type="hidden" name="items[<?= $idx ?>][overwrite]" value="0">
                        <input type="hidden" name="items[<?= $idx ?>][existing_id]" value="<?= (int)$ex['id'] ?>">
                    <?php endif; ?>
                </td>
                <td>
                    <input type="text" class="small-input editable" name="items[<?= $idx ?>][name]"
                           value="<?= htmlspecialchars($item['name']) ?>" style="min-width:160px;">
                    <input type="hidden" name="items[<?= $idx ?>][rating]" value="<?= $item['rating'] ?>">
                    <input type="hidden" name="items[<?= $idx ?>][reviews]" value="<?= $item['reviews'] ?>">
                    <input type="hidden" name="items[<?= $idx ?>][gmaps_url]" value="<?= htmlspecialchars($item['gmaps_url']) ?>">
                    <input type="hidden" name="items[<?= $idx ?>][latitude]" value="<?= $item['latitude'] ?>">
                    <input type="hidden" name="items[<?= $idx ?>][longitude]" value="<?= $item['longitude'] ?>">
                    <input type="hidden" name="items[<?= $idx ?>][gmaps_category]" value="<?= htmlspecialchars($item['gmaps_category']) ?>">
                </td>
                <td style="white-space:nowrap;">
                    <?php if ($is_dup): ?>
                        <span class="badge badge-exists">In DB</span>
                        <?php if (!empty($diffs)): ?>
                            <br><span style="font-size:0.65rem;color:#b45309;">Δ <?= implode(', ', array_keys($diffs)) ?></span>
                            <br><label style="font-size:0.68rem;cursor:pointer;color:#1d4ed8;">
                                <input type="checkbox" name="items[<?= $idx ?>][overwrite]" value="1"
                                       style="width:13px;height:13px;" onchange="this.parentElement.parentElement.parentElement.querySelector('[name*=selected]').checked=this.checked">
                                Overwrite
                            </label>
                        <?php else: ?>
                            <br><span style="font-size:0.65rem;color:#16a34a;">Identical</span>
                        <?php endif; ?>
                    <?php else: ?>
                        <span class="badge badge-high">New</span>
                    <?php endif; ?>
                </td>
                <td>
                    ★ <?= $item['rating'] ?> <span style="color:#999;">(<?= $item['reviews'] ?>)</span>
                    <?php if (isset($diffs['rating']) || isset($diffs['reviews'])): ?>
                        <span class="diff-old">was ★<?= $ex['google_rating'] ?> (<?= $ex['google_review_count'] ?>)</span>
                    <?php endif; ?>
                </td>
                <td style="white-space:nowrap;">
                    <?php if ($item['gmaps_url']): ?>
                        <a href="<?= htmlspecialchars($item['gmaps_url']) ?>" target="_blank" rel="noopener" style="color:#0369a1;font-size:0.75rem;text-decoration:none;" title="Open Google Maps">📍 Maps</a>
                    <?php endif; ?>
                </td>
                <td><span style="color:#666;font-size:0.8rem;"><?= htmlspecialchars($item['gmaps_category']) ?></span></td>
                <?php if (($parsed['import_type'] ?? 'provider') === 'provider'): ?>
                <td>
                    <select class="small-select" name="items[<?= $idx ?>][category_key]" onchange="updateGroup(this, <?= $idx ?>)">
                        <option value="">— Select —</option>
                        <?php foreach ($flat_cats as $fc): ?>
                            <option value="<?= $fc['cat_key'] ?>"
                                    data-group="<?= $fc['group_key'] ?>"
                                    <?= $ck === $fc['cat_key'] ? 'selected' : '' ?>>
                                <?= $fc['group_label'] ?> → <?= $fc['cat_label'] ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <input type="hidden" name="items[<?= $idx ?>][group_key]" id="group_<?= $idx ?>" value="<?= htmlspecialchars($gk) ?>">
                </td>
                <td>
                    <span class="badge badge-<?= $item['confidence'] ?>"><?= ucfirst($item['confidence']) ?></span>
                </td>
                <?php endif; ?>
                <td>
                    <input type="text" class="small-input editable" name="items[<?= $idx ?>][short_description]"
                           value="<?= htmlspecialchars($item['gmaps_category'] . ' in ' . ($item['address'] ?: 'Lombok')) ?>"
                           style="min-width:180px;">
                    <input type="hidden" name="items[<?= $idx ?>][description]" value="">
                </td>
                <td>
                    <input type="text" class="small-input editable" name="items[<?= $idx ?>][address]"
                           value="<?= htmlspecialchars($item['address']) ?>" style="min-width:140px;">
                </td>
                <td class="diff-cell">
                    <input type="text" class="small-input editable" name="items[<?= $idx ?>][phone]"
                           value="<?= htmlspecialchars($item['phone']) ?>" style="width:110px;">
                    <?php if (isset($diffs['phone'])): ?>
                        <span class="diff-old">was: <?= htmlspecialchars($diffs['phone'] ?: '(empty)') ?></span>
                    <?php endif; ?>
                    <input type="hidden" name="items[<?= $idx ?>][whatsapp]" value="">
                    <input type="hidden" name="items[<?= $idx ?>][languages]" value="Bahasa only">
                </td>
                <td class="diff-cell">
                    <?php $ws = htmlspecialchars($item['website_url'] ?? ''); ?>
                    <input type="text" class="small-input editable" name="items[<?= $idx ?>][website_url]"
                           value="<?= $ws ?>" placeholder="https://..." style="width:140px;">
                    <?php if ($ws): ?>
                        <a href="<?= $ws ?>" target="_blank" rel="noopener" style="font-size:0.7rem;color:#0369a1;">🔗 open</a>
                    <?php endif; ?>
                    <?php if (isset($diffs['website'])): ?>
                        <span class="diff-old">was: <?= htmlspecialchars($diffs['website'] ?: '(empty)') ?></span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php $det_area = $item['detected_area'] ?? 'mataram'; ?>
                    <select class="small-select" name="items[<?= $idx ?>][area_key]" style="max-width:120px;">
                        <?php
                        $area_opts = [
                            'mataram' => 'Mataram',
                            'kuta' => 'Kuta',
                            'senggigi' => 'Senggigi',
                            'selong_belanak' => 'Selong Belanak',
                            'ekas' => 'Ekas',
                            'north_lombok' => 'North Lombok',
                            'gili_islands' => 'Gili Islands',
                            'other_lombok' => 'Other Lombok',
                        ];
                        foreach ($area_opts as $ak => $al): ?>
                            <option value="<?= $ak ?>" <?= $det_area === $ak ? 'selected' : '' ?>><?= $al ?></option>
                        <?php endforeach; ?>
                    </select>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>

    <div style="margin-top:16px;display:flex;justify-content:flex-end;gap:8px;">
        <button type="submit" class="btn btn-success">Save Selected to Database</button>
    </div>
</div>

<?php endif; ?>

<!-- REJECTED -->
<?php if (!empty($parsed['rejected'])): ?>
<div class="card">
    <h2 style="color:#dc2626;">Rejected (<?= count($parsed['rejected']) ?>)</h2>
    <p style="color:#666;margin-bottom:12px;">These listings did not meet the minimum review/rating criteria.</p>
    <div class="table-wrap">
    <table>
        <thead>
            <tr><th>Name</th><th>Rating</th><th>Reviews</th><th>Reason</th></tr>
        </thead>
        <tbody>
        <?php foreach ($parsed['rejected'] as $r): ?>
            <tr>
                <td><?= htmlspecialchars($r['name']) ?></td>
                <td>★ <?= $r['rating'] ?></td>
                <td><?= $r['reviews'] ?></td>
                <td style="color:#dc2626;"><?= htmlspecialchars($r['reason']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>
<?php endif; ?>

</form>

<?php endif; /* end if parsed */ ?>

</div><!-- container -->

<script>
function toggleAll(checked) {
    document.querySelectorAll('input[type="checkbox"][name*="[selected]"]').forEach(cb => cb.checked = checked);
}
function updateGroup(sel, idx) {
    const opt = sel.options[sel.selectedIndex];
    const gk = opt ? opt.getAttribute('data-group') || '' : '';
    document.getElementById('group_' + idx).value = gk;
}
</script>

</body>
</html>

<?php
// ─── LOGIN PAGE ──────────────────────────────────────────────────────
function show_login(string $error = ''): void {
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title>Admin Login | Build in Lombok</title>
<style>
body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', system-ui, sans-serif; background: #f5f5f5; display: flex; align-items: center; justify-content: center; min-height: 100vh; }
.login-card { background: #fff; border-radius: 10px; padding: 40px; box-shadow: 0 2px 12px rgba(0,0,0,0.1); width: 100%; max-width: 360px; }
h1 { font-size: 1.3rem; margin-bottom: 6px; }
p { color: #666; font-size: 0.85rem; margin-bottom: 20px; }
label { display: block; font-size: 0.8rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.03em; color: #555; margin-bottom: 4px; }
input { width: 100%; padding: 10px 12px; border: 1px solid #d0d0d0; border-radius: 6px; font-size: 0.95rem; margin-bottom: 14px; box-sizing: border-box; }
button { width: 100%; padding: 11px; background: #0c7c84; color: #fff; border: none; border-radius: 6px; font-size: 0.95rem; font-weight: 600; cursor: pointer; }
button:hover { background: #0a6a70; }
.error { background: #fee2e2; color: #dc2626; padding: 10px; border-radius: 6px; margin-bottom: 14px; font-size: 0.85rem; }
</style>
</head>
<body>
<div class="login-card">
    <h1>Admin Login</h1>
    <p>Build in Lombok — Import Tool</p>
    <?php if ($error): ?><div class="error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <form method="POST">
        <label>Username</label>
        <input type="text" name="username" required autofocus>
        <label>Password</label>
        <input type="password" name="password" required>
        <button type="submit" name="login">Log In</button>
    </form>
</div>
</body>
</html>
<?php
}
?>
