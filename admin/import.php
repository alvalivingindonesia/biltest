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
            'legal_notary' => 'Lawyer / Notary',
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
    'legal_notary' => [
        'lawyer', 'notary', 'notaris', 'pengacara', 'law firm', 'kantor hukum',
        'legal', 'legal services', 'attorney', 'solicitor', 'advokat',
        'konsultan hukum', 'legal consultant', 'ppat', 'conveyancer',
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

// ─── AGENT KEYWORD MAP ───────────────────────────────────────────────
// Keywords that indicate a Google Maps entry is a real estate agent/agency
$AGENT_KEYWORDS = [
    'real estate agent', 'real estate agency', 'real estate', 'property agent',
    'property agency', 'estate agent', 'agen properti', 'agen real estate',
    'property consultant', 'konsultan properti', 'broker properti',
    'real estate broker', 'property broker', 'property company',
    'kantor properti', 'jual beli properti', 'jual beli tanah',
    'land agent', 'land broker', 'land for sale',
    'property investment', 'investasi properti',
    'rumah dijual', 'tanah dijual', 'villa for sale', 'jual villa',
    'real estate office', 'properti lombok', 'lombok property',
    'lombok real estate', 'bali real estate', 'property management',
];

// ─── DEVELOPER KEYWORD MAP ───────────────────────────────────────────
// Keywords that indicate a Google Maps entry is a developer (property developer)
$DEVELOPER_KEYWORDS = [
    'property developer', 'real estate developer', 'developer', 'pengembang',
    'housing developer', 'villa developer', 'resort developer',
    'property development', 'pengembang properti', 'pengembang perumahan',
    'residential developer', 'construction and development',
    'builder and developer', 'development company',
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
 * Auto-detect entity type: 'agent', 'developer', or 'provider'.
 * Checks agent/developer keywords first (more specific), then falls back to provider.
 * Returns ['type' => 'agent'|'developer'|'provider', 'type_confidence' => 'high'|'medium'|'low']
 */
function detect_entity_type(string $gmaps_category, string $business_name, array $agent_keywords, array $developer_keywords): array {
    $combined = strtolower(html_entity_decode($gmaps_category . ' ' . $business_name));
    
    // Check agent keywords (highest priority — most specific)
    $agent_score = 0;
    foreach ($agent_keywords as $kw) {
        if (stripos($combined, $kw) !== false) {
            $s = 50 + (strlen($kw) / max(strlen($combined), 1)) * 50;
            $agent_score = max($agent_score, $s);
        }
    }
    
    // Check developer keywords
    $dev_score = 0;
    foreach ($developer_keywords as $kw) {
        if (stripos($combined, $kw) !== false) {
            $s = 50 + (strlen($kw) / max(strlen($combined), 1)) * 50;
            $dev_score = max($dev_score, $s);
        }
    }
    
    // Agent beats developer if both match (e.g. "real estate agent" matches both "real estate" and "agent")
    if ($agent_score >= $dev_score && $agent_score >= 50) {
        return ['type' => 'agent', 'type_confidence' => ($agent_score >= 70 ? 'high' : 'medium')];
    }
    if ($dev_score >= 50) {
        return ['type' => 'developer', 'type_confidence' => ($dev_score >= 70 ? 'high' : 'medium')];
    }
    
    // Default: provider
    return ['type' => 'provider', 'type_confidence' => 'low'];
}

/**
 * Detect area from GPS coordinates using bounding boxes.
 * Lombok approximate zones based on well-known landmarks.
 */
function detect_area_from_coords(float $lat, float $lng): string {
    // Bounding boxes: [south_lat, north_lat, west_lng, east_lng]
    $zones = [
        'kuta'            => [-8.95, -8.87, 116.18, 116.30],
        'selong_belanak'  => [-8.92, -8.85, 116.05, 116.15],
        'mawi'            => [-8.91, -8.88, 116.02, 116.06],
        'mawun'           => [-8.92, -8.89, 116.07, 116.11],
        'are_guling'      => [-8.92, -8.89, 116.14, 116.18],
        'gerupuk'         => [-8.93, -8.89, 116.30, 116.38],
        'tanjung_aan'     => [-8.93, -8.90, 116.27, 116.32],
        'ekas'            => [-8.88, -8.78, 116.38, 116.55],
        'senggigi'        => [-8.55, -8.45, 116.02, 116.12],
        'gili_islands'    => [-8.38, -8.32, 116.02, 116.10],
        'north_lombok'    => [-8.42, -8.28, 116.10, 116.45],
        'senaru'          => [-8.35, -8.28, 116.35, 116.45],
        'tanjung'         => [-8.42, -8.35, 116.10, 116.22],
        'bangsal'         => [-8.40, -8.35, 116.05, 116.12],
        'mataram'         => [-8.65, -8.52, 116.05, 116.18],
        'sekotong'        => [-8.78, -8.68, 115.85, 116.05],
        'lembar'          => [-8.75, -8.68, 116.05, 116.12],
        'gerung'          => [-8.68, -8.60, 116.05, 116.12],
        'praya'           => [-8.78, -8.68, 116.22, 116.35],
        'labuhan_lombok'  => [-8.55, -8.48, 116.55, 116.68],
        'selong'          => [-8.68, -8.60, 116.48, 116.58],
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

// ─── REGION MAPPING ──────────────────────────────────────────────────
$REGION_MAP = [
    'south_lombok'  => ['kuta', 'selong_belanak', 'ekas', 'mawi', 'mawun', 'are_guling', 'gerupuk', 'tanjung_aan'],
    'west_lombok'   => ['mataram', 'senggigi', 'sekotong', 'lembar', 'gerung'],
    'central_lombok' => ['praya', 'jonggat', 'batukliang', 'other_lombok'],
    'east_lombok'   => ['labuhan_lombok', 'selong'],
    'north_lombok'  => ['north_lombok', 'senaru', 'tanjung', 'bangsal'],
    'gili_islands'  => ['gili_islands'],
];

function get_region_for_area(string $area_key): string {
    global $REGION_MAP;
    foreach ($REGION_MAP as $region => $areas) {
        if (in_array($area_key, $areas)) return $region;
    }
    return 'central_lombok';
}

// ─── AREA OPTIONS (grouped by region) ────────────────────────────────
$AREA_OPTIONS = [
    'south_lombok' => [
        'label' => 'South Lombok',
        'areas' => [
            'kuta' => 'Kuta',
            'selong_belanak' => 'Selong Belanak',
            'mawi' => 'Mawi',
            'mawun' => 'Mawun',
            'are_guling' => 'Are Guling',
            'gerupuk' => 'Gerupuk',
            'tanjung_aan' => 'Tanjung Aan',
            'ekas' => 'Ekas',
        ],
    ],
    'west_lombok' => [
        'label' => 'West Lombok',
        'areas' => [
            'mataram' => 'Mataram',
            'senggigi' => 'Senggigi',
            'sekotong' => 'Sekotong',
            'lembar' => 'Lembar',
            'gerung' => 'Gerung',
        ],
    ],
    'central_lombok' => [
        'label' => 'Central Lombok',
        'areas' => [
            'praya' => 'Praya',
            'jonggat' => 'Jonggat',
            'batukliang' => 'Batukliang',
            'other_lombok' => 'Other Central',
        ],
    ],
    'east_lombok' => [
        'label' => 'East Lombok',
        'areas' => [
            'labuhan_lombok' => 'Labuhan Lombok',
            'selong' => 'Selong',
        ],
    ],
    'north_lombok' => [
        'label' => 'North Lombok',
        'areas' => [
            'north_lombok' => 'North Lombok (General)',
            'senaru' => 'Senaru',
            'tanjung' => 'Tanjung',
            'bangsal' => 'Bangsal',
        ],
    ],
    'gili_islands' => [
        'label' => 'Gili Islands',
        'areas' => [
            'gili_islands' => 'Gili Islands',
        ],
    ],
];

/**
 * Extract social media links from a Google Maps HTML chunk.
 * Looks for links to Instagram, Facebook, YouTube, TikTok, LinkedIn.
 */
function extract_social_links_from_chunk(string $chunk): array {
    $socials = [
        'instagram_url' => '',
        'facebook_url'  => '',
        'youtube_url'   => '',
        'tiktok_url'    => '',
        'linkedin_url'  => '',
    ];

    // Extract all href values from the chunk
    preg_match_all('/href="([^"]+)"/i', $chunk, $href_matches);
    $urls = $href_matches[1] ?? [];

    // Also check visible text for social URLs
    preg_match_all('#(https?://(?:www\.)?(?:instagram|facebook|fb|youtube|tiktok|linkedin)\.com/[^\s<"]+)#i', $chunk, $text_urls);
    $urls = array_merge($urls, $text_urls[1] ?? []);

    foreach ($urls as $url) {
        $url = html_entity_decode($url, ENT_QUOTES, 'UTF-8');
        $host = strtolower(parse_url($url, PHP_URL_HOST) ?? '');

        if (!$socials['instagram_url'] && (strpos($host, 'instagram.com') !== false)) {
            $socials['instagram_url'] = $url;
        } elseif (!$socials['facebook_url'] && (strpos($host, 'facebook.com') !== false || strpos($host, 'fb.com') !== false)) {
            $socials['facebook_url'] = $url;
        } elseif (!$socials['youtube_url'] && (strpos($host, 'youtube.com') !== false || strpos($host, 'youtu.be') !== false)) {
            $socials['youtube_url'] = $url;
        } elseif (!$socials['tiktok_url'] && (strpos($host, 'tiktok.com') !== false)) {
            $socials['tiktok_url'] = $url;
        } elseif (!$socials['linkedin_url'] && (strpos($host, 'linkedin.com') !== false)) {
            $socials['linkedin_url'] = $url;
        }
    }

    return $socials;
}

/**
 * Extract the thumbnail/profile image URL from a Google Maps listing chunk.
 * Google Maps uses img tags or background-image styles within the article.
 */
function extract_gmaps_thumbnail(string $chunk): string {
    // Method 1: <img> with src containing googleusercontent or ggpht (Maps photos)
    if (preg_match('/<img[^>]+src="(https:\/\/lh[35]\.googleusercontent\.com\/[^"]+)"/i', $chunk, $m)) {
        return html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
    }
    // Method 2: background-image: url(...) with google photo CDN
    if (preg_match('/background-image:\s*url\(["\']?(https:\/\/lh[35]\.googleusercontent\.com\/[^"\')]+)/i', $chunk, $m)) {
        return html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
    }
    // Method 3: Any img with maps-related source
    if (preg_match('/<img[^>]+src="(https:\/\/streetviewpixels[^"]+)"/i', $chunk, $m)) {
        return html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
    }
    return '';
}

/**
 * Scrape a provider/developer website for social links, description, and profile image.
 * Uses cURL with a short timeout to avoid blocking the import.
 */
function scrape_website(string $url): array {
    $result = [
        'instagram_url' => '',
        'facebook_url'  => '',
        'youtube_url'   => '',
        'tiktok_url'    => '',
        'linkedin_url'  => '',
        'profile_photo_url' => '',
        'profile_description' => '',
        'scraped' => false,
    ];

    if (empty($url)) return $result;

    // Normalize URL
    if (!preg_match('#^https?://#i', $url)) {
        $url = 'https://' . $url;
    }

    $curl_opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        CURLOPT_HTTPHEADER => ['Accept: text/html,application/xhtml+xml', 'Accept-Language: en-US,en;q=0.9,id;q=0.8'],
    ];

    $ch = curl_init();
    curl_setopt_array($ch, [CURLOPT_URL => $url] + $curl_opts);
    $html = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($html === false || $http_code >= 400 || strlen($html) < 200) {
        return $result;
    }

    $result['scraped'] = true;

    // ── Extract social links (both URL patterns in text AND href attributes) ──
    $social_patterns = [
        'instagram_url' => '#https?://(?:www\.)?instagram\.com/[a-zA-Z0-9_.]+/?#i',
        'facebook_url'  => '#https?://(?:www\.)?(?:facebook|fb)\.com/[a-zA-Z0-9_./-]+/?#i',
        'youtube_url'   => '#https?://(?:www\.)?youtube\.com/(?:c/|channel/|@)[a-zA-Z0-9_.-]+/?#i',
        'tiktok_url'    => '#https?://(?:www\.)?tiktok\.com/@[a-zA-Z0-9_.]+/?#i',
        'linkedin_url'  => '#https?://(?:www\.)?linkedin\.com/(?:company|in)/[a-zA-Z0-9_./-]+/?#i',
    ];

    // Method 1: scan full HTML for social URLs (catches inline text, href, data attributes)
    foreach ($social_patterns as $key => $pattern) {
        if (preg_match($pattern, $html, $sm)) {
            $result[$key] = rtrim($sm[0], '/');
        }
    }

    // Method 2: explicitly scan all href attributes (catches encoded/escaped URLs)
    preg_match_all('/href=["\']([^"\']+)["\']/i', $html, $href_matches);
    foreach ($href_matches[1] as $href) {
        $href = html_entity_decode($href, ENT_QUOTES, 'UTF-8');
        if (empty($result['instagram_url']) && preg_match('#instagram\.com/[a-zA-Z0-9_.]+#i', $href)) {
            $result['instagram_url'] = (strpos($href, 'http') === 0) ? rtrim($href, '/') : 'https://' . ltrim($href, '/');
        }
        if (empty($result['facebook_url']) && preg_match('#(?:facebook|fb)\.com/[a-zA-Z0-9_./]+#i', $href) && strpos($href, 'sharer') === false) {
            $result['facebook_url'] = (strpos($href, 'http') === 0) ? rtrim($href, '/') : 'https://' . ltrim($href, '/');
        }
        if (empty($result['youtube_url']) && preg_match('#youtube\.com/(?:c/|channel/|@)[a-zA-Z0-9_.-]+#i', $href)) {
            $result['youtube_url'] = (strpos($href, 'http') === 0) ? rtrim($href, '/') : 'https://' . ltrim($href, '/');
        }
        if (empty($result['tiktok_url']) && preg_match('#tiktok\.com/@[a-zA-Z0-9_.]+#i', $href)) {
            $result['tiktok_url'] = (strpos($href, 'http') === 0) ? rtrim($href, '/') : 'https://' . ltrim($href, '/');
        }
        if (empty($result['linkedin_url']) && preg_match('#linkedin\.com/(?:company|in)/[a-zA-Z0-9_.-]+#i', $href)) {
            $result['linkedin_url'] = (strpos($href, 'http') === 0) ? rtrim($href, '/') : 'https://' . ltrim($href, '/');
        }
    }

    // Also detect WhatsApp links from href
    if (!isset($result['whatsapp_url'])) $result['whatsapp_url'] = '';
    foreach ($href_matches[1] as $href) {
        $href = html_entity_decode($href, ENT_QUOTES, 'UTF-8');
        if (empty($result['whatsapp_url']) && preg_match('#whatsapp\.com/send\?phone=(\d+)#i', $href, $wa_m)) {
            $result['whatsapp_url'] = '+' . $wa_m[1];
        }
    }

    // ── Extract meta description ──
    if (preg_match('/<meta[^>]+name=["\']description["\'][^>]+content=["\']([^"\']{10,500})["\']/', $html, $dm)) {
        $result['profile_description'] = html_entity_decode(trim($dm[1]), ENT_QUOTES, 'UTF-8');
    } elseif (preg_match('/<meta[^>]+property=["\']og:description["\'][^>]+content=["\']([^"\']{10,500})["\']/', $html, $dm)) {
        $result['profile_description'] = html_entity_decode(trim($dm[1]), ENT_QUOTES, 'UTF-8');
    }

    // ── Extract About Us section text for richer profile descriptions ──
    $about_text = '';
    // Look for common about section patterns in the HTML
    if (preg_match('/<(?:section|div|article)[^>]*(?:id|class)=["\'][^"\']*(?:about-us|about_us|about-section|about-content|aboutUs)[^"\']*["\'][^>]*>(.*?)<\/(?:section|div|article)>/si', $html, $about_m)) {
        $about_text = strip_tags(preg_replace('/<(script|style)[^>]*>.*?<\/\1>/si', '', $about_m[1]));
    }
    // Also try headings: <h1-h3>About Us</h1-h3> followed by <p> tags
    if (!$about_text && preg_match('/<h[1-3][^>]*>[^<]*(?:About\s*Us|Tentang\s*Kami|Who\s*We\s*Are|Our\s*Story)[^<]*<\/h[1-3]>\s*((?:<p[^>]*>.*?<\/p>\s*){1,4})/si', $html, $about_m)) {
        $about_text = strip_tags($about_m[1]);
    }
    if ($about_text) {
        $about_text = trim(preg_replace('/\s+/', ' ', $about_text));
        // Use about text if it's more substantial than the meta description
        if (strlen($about_text) > 30 && strlen($about_text) > strlen($result['profile_description'])) {
            $result['profile_description'] = mb_substr($about_text, 0, 500);
        }
    }

    // ── Try /about or /about-us page if no good description found ──
    if (strlen($result['profile_description']) < 50) {
        $parsed_url = parse_url($url);
        $base = ($parsed_url['scheme'] ?? 'https') . '://' . ($parsed_url['host'] ?? '');
        foreach (['/about', '/about-us', '/tentang-kami'] as $about_path) {
            $ch2 = curl_init();
            curl_setopt_array($ch2, [CURLOPT_URL => $base . $about_path] + $curl_opts);
            $about_html = curl_exec($ch2);
            $about_code = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
            curl_close($ch2);
            if ($about_html && $about_code < 400 && strlen($about_html) > 500) {
                // Extract paragraphs from about page
                $cleaned = preg_replace('/<(script|style|nav|header|footer)[^>]*>.*?<\/\1>/si', '', $about_html);
                preg_match_all('/<p[^>]*>(.{20,800}?)<\/p>/si', $cleaned, $p_matches);
                if (!empty($p_matches[1])) {
                    $paragraphs = array_map(function($p) { return trim(strip_tags($p)); }, $p_matches[1]);
                    $paragraphs = array_filter($paragraphs, function($p) { return strlen($p) > 30 && !preg_match('/cookie|privacy|©|copyright/i', $p); });
                    $combined = implode(' ', array_slice(array_values($paragraphs), 0, 3));
                    if (strlen($combined) > strlen($result['profile_description'])) {
                        $result['profile_description'] = mb_substr(trim($combined), 0, 500);
                    }
                }
                // Also scan about page for social links we might have missed
                preg_match_all('/href=["\']([^"\']+)["\']/i', $about_html, $about_hrefs);
                foreach ($about_hrefs[1] as $href) {
                    $href = html_entity_decode($href, ENT_QUOTES, 'UTF-8');
                    if (empty($result['instagram_url']) && preg_match('#instagram\.com/[a-zA-Z0-9_.]+#i', $href))
                        $result['instagram_url'] = (strpos($href, 'http') === 0) ? rtrim($href, '/') : 'https://' . ltrim($href, '/');
                    if (empty($result['facebook_url']) && preg_match('#(?:facebook|fb)\.com/[a-zA-Z0-9_./]+#i', $href) && strpos($href, 'sharer') === false)
                        $result['facebook_url'] = (strpos($href, 'http') === 0) ? rtrim($href, '/') : 'https://' . ltrim($href, '/');
                    if (empty($result['linkedin_url']) && preg_match('#linkedin\.com/(?:company|in)/[a-zA-Z0-9_.-]+#i', $href))
                        $result['linkedin_url'] = (strpos($href, 'http') === 0) ? rtrim($href, '/') : 'https://' . ltrim($href, '/');
                }
                break; // Stop after first successful about page
            }
        }
    }

    // ── Extract profile image: OG image → twitter:image → inline <img> fallback ──
    $found_img = '';
    // Priority 1: og:image
    if (preg_match('/<meta[^>]+property=["\']og:image["\'][^>]+content=["\']([^"\']+)["\']/', $html, $im)) {
        $found_img = html_entity_decode(trim($im[1]), ENT_QUOTES, 'UTF-8');
    }
    // Priority 2: twitter:image
    if (!$found_img && preg_match('/<meta[^>]+name=["\']twitter:image["\'][^>]+content=["\']([^"\']+)["\']/', $html, $im)) {
        $found_img = html_entity_decode(trim($im[1]), ENT_QUOTES, 'UTF-8');
    }
    // Priority 3: first suitable <img> — skip tiny icons, hero banners, tracking pixels
    if (!$found_img) {
        preg_match_all('/<img[^>]+>/i', $html, $img_tags);
        foreach ($img_tags[0] as $img_tag) {
            // Extract src
            if (!preg_match('/src=["\']([^"\']+)["\']/i', $img_tag, $src_m)) continue;
            $candidate = html_entity_decode($src_m[1], ENT_QUOTES, 'UTF-8');
            // Skip data URIs, tracking pixels, SVGs, tiny icons
            if (preg_match('/^data:|\.svg|pixel|track|spacer|blank|1x1|favicon|icon/i', $candidate)) continue;
            // Skip if explicit tiny dimensions (width/height < 50)
            if (preg_match('/width=["\']?(\d+)/i', $img_tag, $wm) && (int)$wm[1] < 50) continue;
            if (preg_match('/height=["\']?(\d+)/i', $img_tag, $hm) && (int)$hm[1] < 50) continue;
            // Skip enormous hero hints (class/id containing hero, banner, slider, bg)
            if (preg_match('/(?:class|id)=["\'][^"\']*(?:hero|banner|slider|carousel|background|bg-)[^"\']*["\']/i', $img_tag)) continue;
            // Accept this image
            $found_img = $candidate;
            break;
        }
    }
    // Normalize URL
    if ($found_img) {
        if (strpos($found_img, '//') === 0) $found_img = 'https:' . $found_img;
        elseif (strpos($found_img, '/') === 0) {
            $parsed = parse_url($url);
            $found_img = ($parsed['scheme'] ?? 'https') . '://' . ($parsed['host'] ?? '') . $found_img;
        }
        $result['profile_photo_url'] = $found_img;
    }

    return $result;
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

        // ── Rating ── (supports EN: "4.5 stars 3,000 Reviews" and ID: "4,5 bintang 3.000 Ulasan")
        if (!preg_match('/aria-label="([\d]+[.,]\d+)\s+(?:stars|bintang)\s+([\d.,]+)\s+(?:Reviews?|Ulasan)"/i', $chunk, $rm)) {
            continue; // No rating = skip (we need rating for import filters)
        }
        $rating = (float)str_replace(',', '.', $rm[1]);
        $reviews = (int)preg_replace('/[^\d]/', '', $rm[2]); // Strip thousand separators (3,000 or 3.000 → 3000)

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
            if (preg_match('/^\([\d.,]+\)$/', $t)) {
                $field_start = $idx + 1;
                break;
            }
        }
        $remaining = array_slice($texts, $field_start);

        foreach ($remaining as $idx => $t) {
            if ($idx === 0 && !preg_match('/^(Jl\.|Gg\.|Jalan|Desa|\d|Open|Closed|Buka|Tutup|·)/', $t)) {
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
        // Method 1: action button (EN: data-value="Website", ID: data-value="Situs Web")
        if (preg_match('/data-value="(?:Website|Situs Web)"[^>]*href="([^"]+)"/i', $chunk, $ws_m)) {
            $website_url = html_entity_decode($ws_m[1], ENT_QUOTES, 'UTF-8');
        }
        // Method 2: aria-label visit/kunjungi website/situs
        if (!$website_url && preg_match('/aria-label="(?:Visit|Kunjungi)[^"]*(?:website|situs)[^"]*"[^>]*href="([^"]+)"/i', $chunk, $ws_m)) {
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
            'mawi'            => ['mawi', 'mawi beach'],
            'mawun'           => ['mawun', 'mawun beach'],
            'are_guling'      => ['are guling'],
            'gerupuk'         => ['gerupuk'],
            'tanjung_aan'     => ['tanjung aan'],
            'selong_belanak'  => ['selong belanak', 'belanak', 'tampah'],
            'kuta'            => ['kuta', 'kuta lombok', 'kuta selatan'],
            'senggigi'        => ['senggigi', 'batu layar', 'batulayar', 'mangsit', 'kerandangan', 'lendang luar'],
            'ekas'            => ['ekas', 'awang', 'tanjung ringgit', 'kaliantan'],
            'senaru'          => ['senaru', 'bayan'],
            'tanjung'         => ['tanjung', 'gondang', 'medana', 'sire', 'pemenang'],
            'bangsal'         => ['bangsal'],
            'north_lombok'    => ['lombok utara', 'north lombok'],
            'gili_islands'    => ['gili trawangan', 'gili air', 'gili meno', 'gili'],
            'mataram'         => ['mataram', 'ampenan', 'cakranegara', 'bertais', 'pagutan', 'sekarbela', 'kediri', 'labuapi'],
            'sekotong'        => ['sekotong'],
            'lembar'          => ['lembar'],
            'gerung'          => ['gerung'],
            'praya'           => ['praya', 'pujut', 'kopang', 'sukarara'],
            'jonggat'         => ['jonggat'],
            'batukliang'      => ['batukliang'],
            'labuhan_lombok'  => ['labuhan lombok', 'labuhan', 'lombok timur', 'east lombok'],
            'selong'          => ['selong city', 'selong kota'],
            'other_lombok'    => ['lombok tengah', 'central lombok', 'lombok barat', 'west lombok'],
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

        // ── Social media links from Maps listing ──
        $socials = extract_social_links_from_chunk($chunk);

        // ── Thumbnail image from Maps listing ──
        $thumbnail = extract_gmaps_thumbnail($chunk);

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
            'instagram_url' => $socials['instagram_url'],
            'facebook_url' => $socials['facebook_url'],
            'youtube_url' => $socials['youtube_url'],
            'tiktok_url' => $socials['tiktok_url'],
            'linkedin_url' => $socials['linkedin_url'],
            'thumbnail_url' => $thumbnail,
        ];
    }

    return $listings;
}


// ─── HANDLE SAVE TO DB ───────────────────────────────────────────────
$save_message = '';
$save_errors = [];
if (isset($_POST['save_to_db']) && !empty($_POST['items'])) {
    try {
        $db = get_db();
        $db->beginTransaction();

        $saved_counts = ['provider' => 0, 'developer' => 0, 'agent' => 0];
        $updated_counts = ['provider' => 0, 'developer' => 0, 'agent' => 0];

        foreach ($_POST['items'] as $idx => $item) {
            if (empty($item['selected'])) continue;

            $item_type = $item['item_type'] ?? 'provider';
            $name = trim($item['name']);
            $slug = slugify($name);
            $short_desc = trim($item['short_description']);
            $description = trim($item['description']) ?: $short_desc;
            $gmaps_url = trim($item['gmaps_url']);
            $rating = (float)$item['rating'];
            $review_count = (int)preg_replace('/[^\d]/', '', $item['reviews']);
            $phone = trim($item['phone']);
            $whatsapp = trim($item['whatsapp'] ?? '');
            $website = trim($item['website_url'] ?? '');
            $instagram = trim($item['instagram_url'] ?? '');
            $facebook = trim($item['facebook_url'] ?? '');
            $tiktok = trim($item['tiktok_url'] ?? '');
            $youtube = trim($item['youtube_url'] ?? '');
            $linkedin = trim($item['linkedin_url'] ?? '');
            $profile_photo = trim($item['profile_photo_url'] ?? '');
            $profile_desc = trim($item['profile_description'] ?? '');
            $languages = trim($item['languages'] ?? 'Bahasa only');
            $area = $item['area_key'] ?: 'mataram';
            $address = trim($item['address'] ?? '');
            $lat = $item['latitude'] ?: null;
            $lng = $item['longitude'] ?: null;
            $is_featured = !empty($item['is_featured']) ? 1 : 0;
            $is_trusted = !empty($item['is_trusted']) ? 1 : 0;
            $is_verified = !empty($item['is_verified']) ? 1 : 0;

            // ── AGENT SAVE ──────────────────────────────
            if ($item_type === 'agent') {
                if (!empty($item['overwrite']) && !empty($item['existing_id'])) {
                    $upd = $db->prepare(
                        "UPDATE agents SET display_name=?, slug=?, agency_name=?, bio=?,
                            google_maps_url=?, google_rating=?, google_review_count=?,
                            phone=?, whatsapp_number=?, website_url=?, email=?,
                            areas_served=?, languages=?, profile_photo_url=?,
                            is_verified=?, updated_at=CURRENT_TIMESTAMP
                         WHERE id=?"
                    );
                    $upd->execute([
                        $name, $slug, $short_desc, $description,
                        $gmaps_url, $rating, $review_count,
                        $phone, $whatsapp, $website, '',
                        $area, $languages, $profile_photo, $is_verified,
                        (int)$item['existing_id'],
                    ]);
                    $updated_counts['agent']++;
                    continue;
                }

                // Duplicate slug check
                $check = $db->prepare("SELECT COUNT(*) FROM agents WHERE slug = ?");
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

                $db->prepare(
                    "INSERT INTO agents (slug, display_name, agency_name, bio,
                        google_maps_url, google_rating, google_review_count,
                        phone, whatsapp_number, website_url, email,
                        areas_served, languages, profile_photo_url,
                        is_verified, is_active)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)
                     ON DUPLICATE KEY UPDATE
                        google_rating = VALUES(google_rating),
                        google_review_count = VALUES(google_review_count),
                        updated_at = CURRENT_TIMESTAMP"
                )->execute([
                    $slug, $name, $short_desc, $description,
                    $gmaps_url, $rating, $review_count,
                    $phone, $whatsapp, $website, '',
                    $area, $languages, $profile_photo, $is_verified,
                ]);
                $saved_counts['agent']++;

            // ── DEVELOPER SAVE ──────────────────────────
            } elseif ($item_type === 'developer') {
                $is_featured_dev = $is_featured;

                if (!empty($item['overwrite']) && !empty($item['existing_id'])) {
                    $upd = $db->prepare(
                        "UPDATE developers SET name=?, short_description=?, description=?,
                            google_maps_url=?, google_rating=?, google_review_count=?,
                            phone=?, whatsapp_number=?, website_url=?,
                            instagram_url=?, facebook_url=?, tiktok_url=?, youtube_url=?, linkedin_url=?,
                            profile_photo_url=?, profile_description=?,
                            languages=?, is_featured=?, updated_at=CURRENT_TIMESTAMP
                         WHERE id=?"
                    );
                    $upd->execute([
                        $name, $short_desc, $description,
                        $gmaps_url, $rating, $review_count,
                        $phone, $whatsapp, $website,
                        $instagram, $facebook, $tiktok, $youtube, $linkedin,
                        $profile_photo, $profile_desc,
                        $languages, $is_featured_dev, (int)$item['existing_id'],
                    ]);
                    $del_area = $db->prepare("DELETE FROM developer_areas WHERE developer_id = ?");
                    $del_area->execute([(int)$item['existing_id']]);
                    $db->prepare("INSERT IGNORE INTO developer_areas (developer_id, area_key) VALUES (?, ?)")
                       ->execute([(int)$item['existing_id'], $area]);
                    $updated_counts['developer']++;
                    continue;
                }

                // Duplicate slug check
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

                $db->prepare(
                    "INSERT INTO developers (slug, name, short_description, description,
                        google_maps_url, google_rating, google_review_count,
                        phone, whatsapp_number, website_url,
                        instagram_url, facebook_url, tiktok_url, youtube_url, linkedin_url,
                        profile_photo_url, profile_description,
                        languages, is_featured, badge, is_active)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, 1)
                     ON DUPLICATE KEY UPDATE
                        google_rating = VALUES(google_rating),
                        google_review_count = VALUES(google_review_count),
                        updated_at = CURRENT_TIMESTAMP"
                )->execute([
                    $slug, $name, $short_desc, $description,
                    $gmaps_url, $rating, $review_count,
                    $phone, $whatsapp, $website,
                    $instagram, $facebook, $tiktok, $youtube, $linkedin,
                    $profile_photo, $profile_desc,
                    $languages, $is_featured_dev,
                ]);

                $dev_id = $db->lastInsertId();
                if ($area) {
                    $db->prepare("INSERT IGNORE INTO developer_areas (developer_id, area_key) VALUES (?, ?)")
                       ->execute([$dev_id, $area]);
                }
                if (!empty($item['gmaps_category'])) {
                    $db->prepare("INSERT IGNORE INTO developer_tags (developer_id, tag) VALUES (?, ?)")
                       ->execute([$dev_id, $item['gmaps_category']]);
                }
                $saved_counts['developer']++;

            // ── PROVIDER SAVE ───────────────────────────
            } else {
                $group = $item['group_key'];
                $category_keys = $item['category_keys'] ?? [];
                $category = $category_keys[0] ?? '';

                if (!$group || empty($category_keys)) {
                    $save_errors[] = "Skipped '{$name}': no category assigned.";
                    continue;
                }

                if (!empty($item['overwrite']) && !empty($item['existing_id'])) {
                    $ex_id = (int)$item['existing_id'];
                    $upd = $db->prepare(
                        "UPDATE providers SET name=?, group_key=?, category_key=?, area_key=?,
                            short_description=?, description=?, address=?, latitude=?, longitude=?,
                            google_maps_url=?, google_rating=?, google_review_count=?,
                            phone=?, whatsapp_number=?, website_url=?,
                            instagram_url=?, facebook_url=?, tiktok_url=?, youtube_url=?, linkedin_url=?,
                            profile_photo_url=?, profile_description=?,
                            languages=?, is_featured=?, is_trusted=?, updated_at=CURRENT_TIMESTAMP
                         WHERE id=?"
                    );
                    $upd->execute([
                        $name, $group, $category, $area,
                        $short_desc, $description, $address, $lat, $lng,
                        $gmaps_url, $rating, $review_count,
                        $phone, $whatsapp, $website,
                        $instagram, $facebook, $tiktok, $youtube, $linkedin,
                        $profile_photo, $profile_desc,
                        $languages, $is_featured, $is_trusted, $ex_id,
                    ]);
                    $db->prepare("DELETE FROM provider_categories WHERE provider_id=?")->execute([$ex_id]);
                    $cat_ins = $db->prepare("INSERT IGNORE INTO provider_categories (provider_id, category_key) VALUES (?, ?)");
                    foreach ($category_keys as $ckey) {
                        if ($ckey) $cat_ins->execute([$ex_id, $ckey]);
                    }
                    $updated_counts['provider']++;
                    continue;
                }

                // Duplicate slug check
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

                $db->prepare(
                    "INSERT INTO providers (slug, name, group_key, category_key, area_key, short_description, description,
                        address, latitude, longitude, google_maps_url, google_rating, google_review_count,
                        phone, whatsapp_number, website_url,
                        instagram_url, facebook_url, tiktok_url, youtube_url, linkedin_url,
                        profile_photo_url, profile_description,
                        languages, is_featured, is_trusted, badge, is_active)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, 1)
                     ON DUPLICATE KEY UPDATE
                        google_rating = VALUES(google_rating),
                        google_review_count = VALUES(google_review_count),
                        updated_at = CURRENT_TIMESTAMP"
                )->execute([
                    $slug, $name, $group, $category, $area,
                    $short_desc, $description, $address, $lat, $lng,
                    $gmaps_url, $rating, $review_count,
                    $phone, $whatsapp, $website,
                    $instagram, $facebook, $tiktok, $youtube, $linkedin,
                    $profile_photo, $profile_desc,
                    $languages, $is_featured, $is_trusted,
                ]);

                $provider_id = $db->lastInsertId();
                $cat_ins = $db->prepare("INSERT IGNORE INTO provider_categories (provider_id, category_key) VALUES (?, ?)");
                foreach ($category_keys as $ckey) {
                    if ($ckey) $cat_ins->execute([$provider_id, $ckey]);
                }
                if (!empty($item['gmaps_category'])) {
                    $db->prepare("INSERT IGNORE INTO provider_tags (provider_id, tag) VALUES (?, ?)")
                       ->execute([$provider_id, $item['gmaps_category']]);
                }
                $saved_counts['provider']++;
            }
        }

        $db->commit();
        $parts = [];
        foreach ($saved_counts as $type => $cnt) {
            if ($cnt) $parts[] = "saved {$cnt} new {$type}(s)";
        }
        foreach ($updated_counts as $type => $cnt) {
            if ($cnt) $parts[] = "updated {$cnt} existing {$type}(s)";
        }
        $save_message = "Successfully " . (implode(', ', $parts) ?: "processed items") . ".";
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

            // Detect category (for providers)
            $detection = detect_category($item['gmaps_category'], $item['name'], $KEYWORD_MAP, $CATEGORY_TREE);
            $item['detected_group'] = $detection['group_key'];
            $item['detected_category'] = $detection['category_key'];
            $item['confidence'] = $detection['confidence'];
            
            // Auto-detect entity type: agent, developer, or provider
            $type_det = detect_entity_type($item['gmaps_category'], $item['name'], $AGENT_KEYWORDS, $DEVELOPER_KEYWORDS);
            $item['detected_type'] = $type_det['type'];
            $item['type_confidence'] = $type_det['type_confidence'];
            // If provider category matched with high confidence, keep as provider even if type detection says low
            if ($type_det['type'] === 'provider' && $detection['confidence'] === 'high') {
                $item['type_confidence'] = 'high';
            }
            // Carry through parsed website + area + socials + thumbnail
            $item['website_url'] = $item['website_url'] ?? '';
            $item['detected_area'] = $item['detected_area'] ?? 'mataram';
            $item['detected_region'] = get_region_for_area($item['detected_area']);

            // Carry social links from Maps
            foreach (['instagram_url','facebook_url','youtube_url','tiktok_url','linkedin_url','thumbnail_url'] as $sk) {
                $item[$sk] = $item[$sk] ?? '';
            }
            $item['profile_description'] = '';
            $item['profile_photo_url'] = $item['thumbnail_url']; // default to Maps thumbnail
            $item['website_scraped'] = false;

            // ── Website scraping: enrich with data from their website ──
            $scan_websites = !empty($_POST['scan_websites']);
            if ($scan_websites && !empty($item['website_url'])) {
                $web_data = scrape_website($item['website_url']);
                $item['website_scraped'] = $web_data['scraped'];
                // Merge: website data fills in blanks, doesn't overwrite Maps data
                foreach (['instagram_url','facebook_url','youtube_url','tiktok_url','linkedin_url'] as $sk) {
                    if (empty($item[$sk]) && !empty($web_data[$sk])) {
                        $item[$sk] = $web_data[$sk];
                    }
                }
                // Merge WhatsApp from website if not already set from Maps
                if (empty($item['whatsapp']) && !empty($web_data['whatsapp_url'])) {
                    $item['whatsapp'] = $web_data['whatsapp_url'];
                }
                if (!empty($web_data['profile_description'])) {
                    $item['profile_description'] = $web_data['profile_description'];
                    // Override short_description with first 1-2 sentences from About Us
                    $desc = $web_data['profile_description'];
                    // Extract first ~160 chars, break at sentence boundary
                    if (strlen($desc) > 160) {
                        $short = substr($desc, 0, 200);
                        // Cut at last sentence-ending punctuation within range
                        if (preg_match('/^(.{60,160}[.!?])\s/', $short, $sm)) {
                            $desc = $sm[1];
                        } else {
                            $desc = substr($short, 0, 160) . '...';
                        }
                    }
                    $item['short_description_override'] = $desc;
                }
                if (!empty($web_data['profile_photo_url'])) {
                    $item['profile_photo_url'] = $web_data['profile_photo_url'];
                }
            }

            if ($detection['confidence'] === 'high') {
                $auto_approved[] = $item;
            } else {
                $needs_review[] = $item;
            }
        }

        // ─── Check for existing DB entries ─────────────────────────
        $all_passed = array_merge($auto_approved, $needs_review);
        $existing_count = 0;
        try {
            $db = get_db();
            $check_tables = ['provider' => 'providers', 'developer' => 'developers', 'agent' => 'agents'];
            foreach ($all_passed as &$item) {
                $item['existing'] = null;
                $item['existing_table'] = null;
                $item['existing_type_mismatch'] = false;
                // Check all 3 tables for duplicates
                foreach ($check_tables as $ttype => $tname) {
                    $name_col = ($ttype === 'agent') ? 'display_name' : 'name';
                    // Match by google_maps_url first (most reliable)
                    if (!empty($item['gmaps_url'])) {
                        $stmt = $db->prepare("SELECT * FROM `{$tname}` WHERE google_maps_url = ? LIMIT 1");
                        $stmt->execute([$item['gmaps_url']]);
                        $existing = $stmt->fetch();
                        if ($existing) {
                            $item['existing'] = $existing;
                            $item['existing_table'] = $ttype;
                            if ($ttype !== $item['detected_type']) {
                                $item['existing_type_mismatch'] = true;
                            }
                            $existing_count++;
                            break;
                        }
                    }
                    // Fallback: match by name
                    $stmt = $db->prepare("SELECT * FROM `{$tname}` WHERE LOWER({$name_col}) = LOWER(?) LIMIT 1");
                    $stmt->execute([trim($item['name'])]);
                    $existing = $stmt->fetch();
                    if ($existing) {
                        $item['existing'] = $existing;
                        $item['existing_table'] = $ttype;
                        if ($ttype !== $item['detected_type']) {
                            $item['existing_type_mismatch'] = true;
                        }
                        $existing_count++;
                        break;
                    }
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
.cat-multi { min-width: 220px; max-width: 260px; min-height: 72px; font-size: 0.75rem; }
.cat-multi option { padding: 2px 4px; }
.cat-multi option:checked { background: #0c7c84; color: #fff; }
.small-input { font-size: 0.8rem; padding: 4px 6px; width: 100%; }
.select-all-row { padding: 8px 10px; background: #f9fafb; border-bottom: 2px solid #ddd; }
</style>
</head>
<body>

<div class="topbar">
    <div><strong>Build in Lombok</strong> — Admin Import &nbsp; <a href="console.php" style="color:#fff;opacity:0.7;font-size:0.85rem;">← Back to Console</a></div>
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
                <label>Website Scan</label>
                <label style="font-size:0.85rem;font-weight:400;text-transform:none;letter-spacing:0;cursor:pointer;display:flex;align-items:center;gap:4px;">
                    <input type="checkbox" name="scan_websites" value="1" checked style="width:16px;height:16px;"> Scan websites for socials & info
                </label>
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
    <h2>Parse Results: "<?= htmlspecialchars($parsed['search_query']) ?>" <span style="font-size:0.8rem;font-weight:400;color:#666;">— auto-detected types per item</span></h2>

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
                <th>Photo</th>
                <th>Name</th>
                <th>Type</th>
                <th>Status</th>
                <th>Rating</th>
                <th>Links</th>
                <th>Maps Category</th>
                <th>Our Category</th>
                <th>Short Description</th>
                <th>Socials</th>
                <th>Address</th>
                <th>Phone</th>
                <th>Website</th>
                <th>Region / Area</th>
                <th>Flags</th>
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
                $ex_desc = trim($ex['description'] ?? '');
                $new_desc = trim($item['profile_description'] ?? '');
                if ($new_desc && $ex_desc !== $new_desc)
                    $diffs['description'] = $ex_desc;
                $ex_table = $item['existing_table'] ?? '';
                if ($ex_table === 'provider') {
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
                <td style="width:50px;">
                    <?php $photo = $item['profile_photo_url'] ?? ''; ?>
                    <?php if ($photo): ?>
                        <img src="<?= htmlspecialchars($photo) ?>" alt="" style="width:40px;height:40px;border-radius:4px;object-fit:cover;">
                    <?php else: ?>
                        <span style="display:inline-block;width:40px;height:40px;background:#e5e5e5;border-radius:4px;text-align:center;line-height:40px;color:#999;font-size:0.7rem;">No img</span>
                    <?php endif; ?>
                    <input type="hidden" name="items[<?= $idx ?>][profile_photo_url]" value="<?= htmlspecialchars($photo) ?>">
                    <input type="hidden" name="items[<?= $idx ?>][profile_description]" value="<?= htmlspecialchars($item['profile_description'] ?? '') ?>">
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
                    <?php if (!empty($item['profile_description'])): ?>
                        <div style="font-size:0.68rem;color:#666;margin-top:2px;max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?= htmlspecialchars($item['profile_description']) ?>">
                            <?= htmlspecialchars(mb_substr($item['profile_description'], 0, 60)) ?>&hellip;
                        </div>
                    <?php endif; ?>
                </td>
                <td style="white-space:nowrap;">
                    <?php $det_type = $item['detected_type'] ?? 'provider'; ?>
                    <select class="small-select" name="items[<?= $idx ?>][item_type]" style="width:90px;font-size:0.75rem;" onchange="toggleTypeFields(this, <?= $idx ?>)">
                        <option value="provider" <?= $det_type==='provider'?'selected':'' ?>>Provider</option>
                        <option value="developer" <?= $det_type==='developer'?'selected':'' ?>>Developer</option>
                        <option value="agent" <?= $det_type==='agent'?'selected':'' ?>>Agent</option>
                    </select>
                    <?php if (!empty($item['existing_type_mismatch'])): ?>
                        <br><span style="font-size:0.6rem;color:#dc2626;">⚠ exists as <?= $item['existing_table'] ?></span>
                    <?php endif; ?>
                </td>
                <td style="white-space:nowrap;">
                    <?php if ($is_dup): ?>
                        <span class="badge badge-exists">In DB (<?= $item['existing_table'] ?? '?' ?>)</span>
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
                <td class="cat-cell-<?= $idx ?>" <?= $det_type !== 'provider' ? 'style="opacity:0.3;"' : '' ?>>
                    <select class="small-select cat-multi" name="items[<?= $idx ?>][category_keys][]" multiple size="3" onchange="updateGroupMulti(this, <?= $idx ?>)">
                        <?php foreach ($flat_cats as $fc): ?>
                            <option value="<?= $fc['cat_key'] ?>"
                                    data-group="<?= $fc['group_key'] ?>"
                                    title="<?= htmlspecialchars($fc['group_label'] . ' → ' . $fc['cat_label']) ?>"
                                    <?= $ck === $fc['cat_key'] ? 'selected' : '' ?>>
                                <?= $fc['group_label'] ?> → <?= $fc['cat_label'] ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <input type="hidden" name="items[<?= $idx ?>][group_key]" id="group_<?= $idx ?>" value="<?= htmlspecialchars($gk) ?>">
                    <span class="badge badge-<?= $item['confidence'] ?>" style="margin-top:2px;display:inline-block;"><?= ucfirst($item['confidence']) ?></span>
                    <?php if ($det_type !== 'provider'): ?>
                        <br><span style="font-size:0.6rem;color:#999;">N/A for <?= $det_type ?></span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php $short_val = !empty($item['short_description_override']) ? $item['short_description_override'] : ($item['gmaps_category'] . ' in ' . ($item['address'] ?: 'Lombok')); ?>
                    <input type="text" class="small-input editable" name="items[<?= $idx ?>][short_description]"
                           value="<?= htmlspecialchars($short_val) ?>"
                           style="min-width:180px;">
                    <textarea class="small-input editable" name="items[<?= $idx ?>][description]"
                              style="min-width:180px;min-height:48px;margin-top:3px;font-size:0.75rem;resize:vertical;" placeholder="Full description (from About Us)"><?= htmlspecialchars($item['profile_description'] ?? '') ?></textarea>
                    <?php if (isset($diffs['description'])): ?>
                        <span class="diff-old" style="display:block;max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?= htmlspecialchars($diffs['description'] ?: '(empty)') ?>">was: <?= htmlspecialchars(mb_substr($diffs['description'] ?: '(empty)', 0, 40)) ?>&hellip;</span>
                    <?php endif; ?>
                </td>
                <td style="white-space:nowrap;font-size:0.72rem;">
                    <?php
                    $social_icons = [];
                    if (!empty($item['instagram_url'])) $social_icons[] = '<a href="'.htmlspecialchars($item['instagram_url']).'" target="_blank" rel="noopener" title="Instagram" style="color:#E1306C;">IG</a>';
                    if (!empty($item['facebook_url'])) $social_icons[] = '<a href="'.htmlspecialchars($item['facebook_url']).'" target="_blank" rel="noopener" title="Facebook" style="color:#1877F2;">FB</a>';
                    if (!empty($item['youtube_url'])) $social_icons[] = '<a href="'.htmlspecialchars($item['youtube_url']).'" target="_blank" rel="noopener" title="YouTube" style="color:#FF0000;">YT</a>';
                    if (!empty($item['tiktok_url'])) $social_icons[] = '<a href="'.htmlspecialchars($item['tiktok_url']).'" target="_blank" rel="noopener" title="TikTok" style="color:#000;">TT</a>';
                    if (!empty($item['linkedin_url'])) $social_icons[] = '<a href="'.htmlspecialchars($item['linkedin_url']).'" target="_blank" rel="noopener" title="LinkedIn" style="color:#0A66C2;">LI</a>';
                    echo $social_icons ? implode(' ', $social_icons) : '<span style="color:#ccc;">—</span>';
                    ?>
                    <?php if (!empty($item['website_scraped'])): ?>
                        <br><span style="font-size:0.6rem;color:#16a34a;">✓ site scraped</span>
                    <?php endif; ?>
                    <input type="hidden" name="items[<?= $idx ?>][instagram_url]" value="<?= htmlspecialchars($item['instagram_url'] ?? '') ?>">
                    <input type="hidden" name="items[<?= $idx ?>][facebook_url]" value="<?= htmlspecialchars($item['facebook_url'] ?? '') ?>">
                    <input type="hidden" name="items[<?= $idx ?>][youtube_url]" value="<?= htmlspecialchars($item['youtube_url'] ?? '') ?>">
                    <input type="hidden" name="items[<?= $idx ?>][tiktok_url]" value="<?= htmlspecialchars($item['tiktok_url'] ?? '') ?>">
                    <input type="hidden" name="items[<?= $idx ?>][linkedin_url]" value="<?= htmlspecialchars($item['linkedin_url'] ?? '') ?>">
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
                    <select class="small-select" name="items[<?= $idx ?>][area_key]" style="max-width:150px;">
                        <?php foreach ($AREA_OPTIONS as $rk => $rdata): ?>
                            <optgroup label="<?= $rdata['label'] ?>">
                            <?php foreach ($rdata['areas'] as $ak => $al): ?>
                                <option value="<?= $ak ?>" <?= $det_area === $ak ? 'selected' : '' ?>><?= $al ?></option>
                            <?php endforeach; ?>
                            </optgroup>
                        <?php endforeach; ?>
                    </select>
                </td>
                <td class="flags-cell-<?= $idx ?>">
                    <?php if ($det_type === 'agent'): ?>
                        <label style="font-size:0.75rem;white-space:nowrap;"><input type="checkbox" name="items[<?= $idx ?>][is_verified]" value="1"> Verified</label>
                    <?php elseif ($det_type === 'developer'): ?>
                        <label style="font-size:0.75rem;white-space:nowrap;"><input type="checkbox" name="items[<?= $idx ?>][is_featured]" value="1"> Featured</label>
                    <?php else: ?>
                        <label style="font-size:0.75rem;white-space:nowrap;"><input type="checkbox" name="items[<?= $idx ?>][is_featured]" value="1"> Featured</label><br>
                        <label style="font-size:0.75rem;white-space:nowrap;"><input type="checkbox" name="items[<?= $idx ?>][is_trusted]" value="1"> Trusted</label>
                    <?php endif; ?>
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
    document.querySelectorAll('input[type="checkbox"][name*="[selected]"]').forEach(function(cb) { cb.checked = checked; });
}
function updateGroup(sel, idx) {
    var opt = sel.options[sel.selectedIndex];
    var gk = opt ? opt.getAttribute('data-group') || '' : '';
    document.getElementById('group_' + idx).value = gk;
}
function updateGroupMulti(sel, idx) {
    var selected = [];
    for (var i = 0; i < sel.options.length; i++) {
        if (sel.options[i].selected) selected.push(sel.options[i]);
    }
    var gk = selected.length ? (selected[0].getAttribute('data-group') || '') : '';
    document.getElementById('group_' + idx).value = gk;
}
function toggleTypeFields(sel, idx) {
    var val = sel.value;
    var catCell = document.querySelector('.cat-cell-' + idx);
    var flagsCell = document.querySelector('.flags-cell-' + idx);
    // Category: only visible for providers
    if (catCell) {
        catCell.style.opacity = (val === 'provider') ? '1' : '0.3';
    }
    // Flags: rebuild based on type
    if (flagsCell) {
        var html = '';
        if (val === 'agent') {
            html = '<label style="font-size:0.75rem;white-space:nowrap;"><input type="checkbox" name="items[' + idx + '][is_verified]" value="1"> Verified</label>';
        } else if (val === 'developer') {
            html = '<label style="font-size:0.75rem;white-space:nowrap;"><input type="checkbox" name="items[' + idx + '][is_featured]" value="1"> Featured</label>';
        } else {
            html = '<label style="font-size:0.75rem;white-space:nowrap;"><input type="checkbox" name="items[' + idx + '][is_featured]" value="1"> Featured</label><br>';
            html += '<label style="font-size:0.75rem;white-space:nowrap;"><input type="checkbox" name="items[' + idx + '][is_trusted]" value="1"> Trusted</label>';
        }
        flagsCell.innerHTML = html;
    }
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
