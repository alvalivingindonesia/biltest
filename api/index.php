<?php
/**
 * Build in Lombok — API
 * Single-file PHP API for shared hosting (HostPapa, Namecheap, etc.)
 *
 * Place this file at: /api/index.php
 * Requires: PHP 7.4+ and MySQL/MariaDB (both standard on shared hosts)
 *
 * URL patterns (mod_rewrite via .htaccess routes everything here):
 *   GET /api/providers        — paginated, filtered list
 *   GET /api/providers/:slug  — single provider detail
 *   GET /api/developers       — paginated, filtered list
 *   GET /api/developers/:slug — single developer detail
 *   GET /api/projects         — paginated, filtered list
 *   GET /api/projects/:slug   — single project detail
 *   GET /api/guides           — all published guides (small dataset)
 *   GET /api/guides/:slug     — single guide with full content
 *   GET /api/filters          — all filter options (groups, categories, areas)
 *   GET /api/search           — cross-entity text search
 */

// =============================================================
// CONFIG — loaded from private config outside public web root
// =============================================================

require_once('/home/rovin629/config/biltest_config.php');
define('DB_CHARSET', 'utf8mb4');

define('DEFAULT_PAGE_SIZE', 20);
define('MAX_PAGE_SIZE', 100);

// =============================================================
// BOOTSTRAP
// =============================================================

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Only GET requests
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_error(405, 'Method not allowed');
}

// =============================================================
// DATABASE CONNECTION
// =============================================================

function get_db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }
    return $pdo;
}

// =============================================================
// HELPERS
// =============================================================

function json_out($data, int $status = 200): void {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function json_error(int $status, string $message): void {
    json_out(['error' => $message], $status);
}

function get_page_params(): array {
    $page = max(1, (int)($_GET['page'] ?? 1));
    $per_page = min(MAX_PAGE_SIZE, max(1, (int)($_GET['per_page'] ?? DEFAULT_PAGE_SIZE)));
    $offset = ($page - 1) * $per_page;
    return [$page, $per_page, $offset];
}

function get_sort_param(array $allowed, string $default = 'name'): string {
    $sort = $_GET['sort'] ?? $default;
    return in_array($sort, $allowed) ? $sort : $default;
}

function get_sort_dir(): string {
    $dir = strtoupper($_GET['dir'] ?? 'ASC');
    return $dir === 'DESC' ? 'DESC' : 'ASC';
}

function paginated_response(array $items, int $total, int $page, int $per_page): array {
    return [
        'data' => $items,
        'meta' => [
            'total' => $total,
            'page' => $page,
            'per_page' => $per_page,
            'total_pages' => (int)ceil($total / $per_page),
        ],
    ];
}

/**
 * Attach tags to a list of entities.
 * $tag_table: e.g. 'provider_tags', $fk: e.g. 'provider_id'
 */
function attach_tags(array &$items, string $tag_table, string $fk): void {
    if (empty($items)) return;
    $ids = array_column($items, 'id');
    $placeholders = implode(',', array_fill(0, count($ids), '?'));

    $db = get_db();
    $stmt = $db->prepare("SELECT `{$fk}`, `tag` FROM `{$tag_table}` WHERE `{$fk}` IN ({$placeholders}) ORDER BY `tag`");
    $stmt->execute($ids);
    $rows = $stmt->fetchAll();

    $map = [];
    foreach ($rows as $r) {
        $map[$r[$fk]][] = $r['tag'];
    }
    foreach ($items as &$item) {
        $item['tags'] = $map[$item['id']] ?? [];
    }
}

/**
 * Attach primary image URL to entities.
 */
function attach_images(array &$items, string $entity_type): void {
    if (empty($items)) return;
    $ids = array_column($items, 'id');
    $placeholders = implode(',', array_fill(0, count($ids), '?'));

    $db = get_db();
    $stmt = $db->prepare(
        "SELECT entity_id, url, alt_text FROM images
         WHERE entity_type = ? AND entity_id IN ({$placeholders}) AND is_primary = 1"
    );
    $stmt->execute(array_merge([$entity_type], $ids));
    $rows = $stmt->fetchAll();

    $map = [];
    foreach ($rows as $r) {
        $map[$r['entity_id']] = ['url' => $r['url'], 'alt' => $r['alt_text']];
    }
    foreach ($items as &$item) {
        $item['image'] = $map[$item['id']] ?? null;
    }
}


/**
 * Attach categories to a list of entities (multi-category support).
 * $cat_table: e.g. 'provider_categories', $fk: e.g. 'provider_id'
 */
function attach_categories(array &$items, string $cat_table, string $fk): void {
    if (empty($items)) return;
    $ids = array_column($items, 'id');
    $placeholders = implode(',', array_fill(0, count($ids), '?'));

    $db = get_db();
    $stmt = $db->prepare("SELECT ct.`{$fk}`, ct.`category_key`, c.`label` FROM `{$cat_table}` ct JOIN categories c ON c.`key` = ct.category_key WHERE ct.`{$fk}` IN ({$placeholders}) ORDER BY c.sort_order");
    $stmt->execute($ids);
    $rows = $stmt->fetchAll();

    $map = [];
    foreach ($rows as $r) {
        $map[$r[$fk]][] = ['key' => $r['category_key'], 'label' => $r['label']];
    }
    foreach ($items as &$item) {
        $item['categories'] = $map[$item['id']] ?? [];
        // Backward compat: single category_key from first entry
        $item['category_key'] = $item['categories'][0]['key'] ?? ($item['category_key'] ?? '');
        $item['category_label'] = $item['categories'][0]['label'] ?? '';
    }
}


// =============================================================
// ROUTING
// =============================================================

$path = trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');
// Strip "api/" prefix if present
$path = preg_replace('#^api/?#', '', $path);
$segments = $path ? explode('/', $path) : [];

$resource = $segments[0] ?? '';
$slug = $segments[1] ?? null;

switch ($resource) {
    case 'providers':
        $slug ? handle_provider_detail($slug) : handle_providers_list();
        break;
    case 'developers':
        $slug ? handle_developer_detail($slug) : handle_developers_list();
        break;
    case 'projects':
        $slug ? handle_project_detail($slug) : handle_projects_list();
        break;
    case 'guides':
        $slug ? handle_guide_detail($slug) : handle_guides_list();
        break;
    case 'filters':
        handle_filters();
        break;
    case 'search':
        handle_search();
        break;
    case 'listings':
        $slug ? handle_listing_detail($slug) : handle_listings_list();
        break;
    case 'listing_counts':
        handle_listing_counts();
        break;
    case 'agents':
        $slug ? handle_agent_detail($slug) : handle_agents_list();
        break;
    default:
        json_out([
            'name' => 'Build in Lombok API',
            'version' => '1.0',
            'endpoints' => ['providers', 'developers', 'projects', 'guides', 'filters', 'search', 'listings', 'agents'],
        ]);
}


// =============================================================
// PROVIDERS
// =============================================================

function handle_providers_list(): void {
    $db = get_db();
    [$page, $per_page, $offset] = get_page_params();

    $where = ['p.is_active = 1'];
    $params = [];

    // Filter: group
    if (!empty($_GET['group'])) {
        $where[] = 'p.group_key = ?';
        $params[] = $_GET['group'];
    }
    // Filter: category (via junction table)
    if (!empty($_GET['category'])) {
        $where[] = 'EXISTS (SELECT 1 FROM provider_categories pc WHERE pc.provider_id = p.id AND pc.category_key = ?)';
        $params[] = $_GET['category'];
    }
    // Filter: area
    if (!empty($_GET['area'])) {
        $where[] = 'p.area_key = ?';
        $params[] = $_GET['area'];
    }
    // Filter: region (returns all areas within a region)
    if (!empty($_GET['region'])) {
        $where[] = 'p.area_key IN (SELECT `key` FROM areas WHERE region_key = ?)';
        $params[] = $_GET['region'];
    }
    // Filter: featured only
    if (isset($_GET['featured']) && $_GET['featured'] === '1') {
        $where[] = 'p.is_featured = 1';
    }
    // Filter: trusted only
    if (isset($_GET['trusted']) && $_GET['trusted'] === '1') {
        $where[] = 'p.is_trusted = 1';
    }
    // Filter: text search
    if (!empty($_GET['q'])) {
        $where[] = 'MATCH(p.name, p.short_description, p.description) AGAINST(? IN BOOLEAN MODE)';
        $params[] = $_GET['q'];
    }
    // Filter: tag
    if (!empty($_GET['tag'])) {
        $where[] = 'EXISTS (SELECT 1 FROM provider_tags pt WHERE pt.provider_id = p.id AND pt.tag = ?)';
        $params[] = $_GET['tag'];
    }

    $where_sql = implode(' AND ', $where);

    // Sort
    $sort = get_sort_param(['name', 'google_rating', 'created_at'], 'name');
    $sort_col = "p.{$sort}";
    // Featured always first
    $order = "p.is_trusted DESC, p.is_featured DESC, {$sort_col} " . get_sort_dir();

    // Count
    $count_stmt = $db->prepare("SELECT COUNT(*) FROM providers p WHERE {$where_sql}");
    $count_stmt->execute($params);
    $total = (int)$count_stmt->fetchColumn();

    // Fetch
    $stmt = $db->prepare(
        "SELECT p.id, p.slug, p.name, p.group_key, p.area_key,
                p.short_description, p.address, p.google_rating, p.google_review_count,
                p.phone, p.whatsapp_number, p.website_url, p.languages,
                p.instagram_url, p.facebook_url, p.profile_photo_url, p.logo_url,
                p.hero_image_url, p.image_url_2, p.image_url_3, p.image_url_4,
                p.is_featured, p.is_trusted, p.badge,
                g.label AS group_label, a.label AS area_label, a.region_key
         FROM providers p
         LEFT JOIN `groups` g ON g.`key` = p.group_key
         LEFT JOIN areas a ON a.`key` = p.area_key
         WHERE {$where_sql}
         ORDER BY {$order}
         LIMIT ? OFFSET ?"
    );
    $all_params = array_merge($params, [$per_page, $offset]);
    $stmt->execute($all_params);
    $items = $stmt->fetchAll();

    attach_tags($items, 'provider_tags', 'provider_id');
    attach_images($items, 'provider');
    attach_categories($items, 'provider_categories', 'provider_id');

    json_out(paginated_response($items, $total, $page, $per_page));
}

function handle_provider_detail(string $slug): void {
    $db = get_db();
    $stmt = $db->prepare(
        "SELECT p.*, g.label AS group_label, a.label AS area_label
         FROM providers p
         LEFT JOIN `groups` g ON g.`key` = p.group_key
         LEFT JOIN areas a ON a.`key` = p.area_key
         WHERE p.slug = ? AND p.is_active = 1"
    );
    $stmt->execute([$slug]);
    $item = $stmt->fetch();

    if (!$item) json_error(404, 'Provider not found');

    // Categories (multi)
    $cats = $db->prepare("SELECT c.`key`, c.label FROM provider_categories pc JOIN categories c ON c.`key` = pc.category_key WHERE pc.provider_id = ? ORDER BY c.sort_order");
    $cats->execute([$item['id']]);
    $item['categories'] = $cats->fetchAll();
    // Backward compat: set category_key and category_label from first
    $item['category_key'] = $item['categories'][0]['key'] ?? ($item['category_key'] ?? '');
    $item['category_label'] = $item['categories'][0]['label'] ?? '';

    // Tags
    $t = $db->prepare("SELECT tag FROM provider_tags WHERE provider_id = ? ORDER BY tag");
    $t->execute([$item['id']]);
    $item['tags'] = $t->fetchAll(PDO::FETCH_COLUMN);

    // Images
    $i = $db->prepare("SELECT url, alt_text, sort_order FROM images WHERE entity_type = 'provider' AND entity_id = ? ORDER BY sort_order");
    $i->execute([$item['id']]);
    $item['images'] = $i->fetchAll();

    json_out(['data' => $item]);
}


// =============================================================
// DEVELOPERS
// =============================================================

function handle_developers_list(): void {
    $db = get_db();
    [$page, $per_page, $offset] = get_page_params();

    $where = ['d.is_active = 1'];
    $params = [];

    if (!empty($_GET['area'])) {
        $where[] = 'EXISTS (SELECT 1 FROM developer_areas da WHERE da.developer_id = d.id AND da.area_key = ?)';
        $params[] = $_GET['area'];
    }
    if (!empty($_GET['region'])) {
        $where[] = 'EXISTS (SELECT 1 FROM developer_areas da WHERE da.developer_id = d.id AND da.area_key IN (SELECT `key` FROM areas WHERE region_key = ?))';
        $params[] = $_GET['region'];
    }
    if (!empty($_GET['project_type'])) {
        $where[] = 'EXISTS (SELECT 1 FROM developer_project_types dpt WHERE dpt.developer_id = d.id AND dpt.project_type_key = ?)';
        $params[] = $_GET['project_type'];
    }
    if (isset($_GET['featured']) && $_GET['featured'] === '1') {
        $where[] = 'd.is_featured = 1';
    }
    if (!empty($_GET['q'])) {
        $where[] = 'd.name LIKE ?';
        $params[] = '%' . $_GET['q'] . '%';
    }

    $where_sql = implode(' AND ', $where);
    $sort = get_sort_param(['name', 'google_rating', 'min_ticket_usd', 'created_at'], 'name');
    $order = "d.is_featured DESC, d.{$sort} " . get_sort_dir();

    $count_stmt = $db->prepare("SELECT COUNT(*) FROM developers d WHERE {$where_sql}");
    $count_stmt->execute($params);
    $total = (int)$count_stmt->fetchColumn();

    $stmt = $db->prepare(
        "SELECT d.id, d.slug, d.name, d.short_description, d.min_ticket_usd,
                d.google_rating, d.google_review_count, d.phone, d.whatsapp_number,
                d.website_url, d.languages, d.is_featured, d.badge, d.profile_photo_url, d.logo_url,
                d.hero_image_url, d.image_url_2, d.image_url_3, d.image_url_4
         FROM developers d
         WHERE {$where_sql}
         ORDER BY {$order}
         LIMIT ? OFFSET ?"
    );
    $stmt->execute(array_merge($params, [$per_page, $offset]));
    $items = $stmt->fetchAll();

    attach_tags($items, 'developer_tags', 'developer_id');
    attach_images($items, 'developer');
    attach_categories($items, 'developer_categories', 'developer_id');

    // Attach area and project_type labels
    if (!empty($items)) {
        $ids = array_column($items, 'id');
        $ph = implode(',', array_fill(0, count($ids), '?'));

        $a = $db->prepare("SELECT da.developer_id, a.label FROM developer_areas da JOIN areas a ON a.`key` = da.area_key WHERE da.developer_id IN ({$ph})");
        $a->execute($ids);
        $area_map = [];
        foreach ($a->fetchAll() as $r) $area_map[$r['developer_id']][] = $r['label'];

        $pt = $db->prepare("SELECT dpt.developer_id, pt.label FROM developer_project_types dpt JOIN project_types pt ON pt.`key` = dpt.project_type_key WHERE dpt.developer_id IN ({$ph})");
        $pt->execute($ids);
        $pt_map = [];
        foreach ($pt->fetchAll() as $r) $pt_map[$r['developer_id']][] = $r['label'];

        foreach ($items as &$item) {
            $item['areas'] = $area_map[$item['id']] ?? [];
            $item['project_types'] = $pt_map[$item['id']] ?? [];
        }
    }

    json_out(paginated_response($items, $total, $page, $per_page));
}

function handle_developer_detail(string $slug): void {
    $db = get_db();
    $stmt = $db->prepare("SELECT * FROM developers WHERE slug = ? AND is_active = 1");
    $stmt->execute([$slug]);
    $item = $stmt->fetch();
    if (!$item) json_error(404, 'Developer not found');

    $t = $db->prepare("SELECT tag FROM developer_tags WHERE developer_id = ? ORDER BY tag");
    $t->execute([$item['id']]);
    $item['tags'] = $t->fetchAll(PDO::FETCH_COLUMN);

    // Categories (multi)
    $cats = $db->prepare("SELECT c.`key`, c.label FROM developer_categories dc JOIN categories c ON c.`key` = dc.category_key WHERE dc.developer_id = ? ORDER BY c.sort_order");
    $cats->execute([$item['id']]);
    $item['categories'] = $cats->fetchAll();

    $a = $db->prepare("SELECT a.`key`, a.label FROM developer_areas da JOIN areas a ON a.`key` = da.area_key WHERE da.developer_id = ?");
    $a->execute([$item['id']]);
    $item['areas'] = $a->fetchAll();

    $pt = $db->prepare("SELECT pt.`key`, pt.label FROM developer_project_types dpt JOIN project_types pt ON pt.`key` = dpt.project_type_key WHERE dpt.developer_id = ?");
    $pt->execute([$item['id']]);
    $item['project_types'] = $pt->fetchAll();

    // Associated projects
    $p = $db->prepare("SELECT id, slug, name, status_key, min_investment_usd, short_description FROM projects WHERE developer_id = ? AND is_active = 1 ORDER BY is_featured DESC, name");
    $p->execute([$item['id']]);
    $item['projects'] = $p->fetchAll();

    $i = $db->prepare("SELECT url, alt_text, sort_order FROM images WHERE entity_type = 'developer' AND entity_id = ? ORDER BY sort_order");
    $i->execute([$item['id']]);
    $item['images'] = $i->fetchAll();

    json_out(['data' => $item]);
}


// =============================================================
// PROJECTS
// =============================================================

function handle_projects_list(): void {
    $db = get_db();
    [$page, $per_page, $offset] = get_page_params();

    $where = ['p.is_active = 1'];
    $params = [];

    if (!empty($_GET['area'])) {
        $where[] = 'p.area_key = ?';
        $params[] = $_GET['area'];
    }
    if (!empty($_GET['region'])) {
        $where[] = 'p.area_key IN (SELECT `key` FROM areas WHERE region_key = ?)';
        $params[] = $_GET['region'];
    }
    if (!empty($_GET['type'])) {
        $where[] = 'p.project_type_key = ?';
        $params[] = $_GET['type'];
    }
    if (!empty($_GET['status'])) {
        $where[] = 'p.status_key = ?';
        $params[] = $_GET['status'];
    }
    if (!empty($_GET['developer_id'])) {
        $where[] = 'p.developer_id = ?';
        $params[] = (int)$_GET['developer_id'];
    }
    if (!empty($_GET['min_invest'])) {
        $where[] = 'p.min_investment_usd >= ?';
        $params[] = (int)$_GET['min_invest'];
    }
    if (!empty($_GET['max_invest'])) {
        $where[] = 'p.min_investment_usd <= ?';
        $params[] = (int)$_GET['max_invest'];
    }
    if (isset($_GET['featured']) && $_GET['featured'] === '1') {
        $where[] = 'p.is_featured = 1';
    }
    if (!empty($_GET['q'])) {
        $where[] = 'MATCH(p.name, p.short_description, p.description) AGAINST(? IN BOOLEAN MODE)';
        $params[] = $_GET['q'];
    }

    $where_sql = implode(' AND ', $where);
    $sort = get_sort_param(['name', 'min_investment_usd', 'created_at'], 'name');
    $order = "p.is_featured DESC, p.{$sort} " . get_sort_dir();

    $count_stmt = $db->prepare("SELECT COUNT(*) FROM projects p WHERE {$where_sql}");
    $count_stmt->execute($params);
    $total = (int)$count_stmt->fetchColumn();

    $stmt = $db->prepare(
        "SELECT p.id, p.slug, p.name, p.area_key, p.project_type_key, p.status_key,
                p.min_investment_usd, p.expected_yield_range, p.timeline_summary,
                p.short_description, p.info_contact_whatsapp, p.is_featured, p.badge, p.logo_url,
                d.name AS developer_name, d.slug AS developer_slug,
                a.label AS area_label, pt.label AS project_type_label, ps.label AS status_label
         FROM projects p
         LEFT JOIN developers d ON d.id = p.developer_id
         LEFT JOIN areas a ON a.`key` = p.area_key
         LEFT JOIN project_types pt ON pt.`key` = p.project_type_key
         LEFT JOIN project_statuses ps ON ps.`key` = p.status_key
         WHERE {$where_sql}
         ORDER BY {$order}
         LIMIT ? OFFSET ?"
    );
    $stmt->execute(array_merge($params, [$per_page, $offset]));
    $items = $stmt->fetchAll();

    attach_tags($items, 'project_tags', 'project_id');
    attach_images($items, 'project');

    json_out(paginated_response($items, $total, $page, $per_page));
}

function handle_project_detail(string $slug): void {
    $db = get_db();
    $stmt = $db->prepare(
        "SELECT p.*, d.name AS developer_name, d.slug AS developer_slug,
                a.label AS area_label, pt.label AS project_type_label, ps.label AS status_label
         FROM projects p
         LEFT JOIN developers d ON d.id = p.developer_id
         LEFT JOIN areas a ON a.`key` = p.area_key
         LEFT JOIN project_types pt ON pt.`key` = p.project_type_key
         LEFT JOIN project_statuses ps ON ps.`key` = p.status_key
         WHERE p.slug = ? AND p.is_active = 1"
    );
    $stmt->execute([$slug]);
    $item = $stmt->fetch();
    if (!$item) json_error(404, 'Project not found');

    $t = $db->prepare("SELECT tag FROM project_tags WHERE project_id = ? ORDER BY tag");
    $t->execute([$item['id']]);
    $item['tags'] = $t->fetchAll(PDO::FETCH_COLUMN);

    $i = $db->prepare("SELECT url, alt_text, sort_order FROM images WHERE entity_type = 'project' AND entity_id = ? ORDER BY sort_order");
    $i->execute([$item['id']]);
    $item['images'] = $i->fetchAll();

    json_out(['data' => $item]);
}


// =============================================================
// GUIDES
// =============================================================

function handle_guides_list(): void {
    $db = get_db();
    $stmt = $db->prepare(
        "SELECT id, slug, title, category, read_time, excerpt
         FROM guides WHERE is_published = 1 ORDER BY created_at DESC"
    );
    $stmt->execute();
    json_out(['data' => $stmt->fetchAll()]);
}

function handle_guide_detail(string $slug): void {
    $db = get_db();
    $stmt = $db->prepare("SELECT * FROM guides WHERE slug = ? AND is_published = 1");
    $stmt->execute([$slug]);
    $item = $stmt->fetch();
    if (!$item) json_error(404, 'Guide not found');
    json_out(['data' => $item]);
}


// =============================================================
// FILTERS (populate dropdowns on the frontend)
// =============================================================

function handle_filters(): void {
    $db = get_db();

    // Each lookup now also returns an optional `label_id` (Indonesian).
    // Client falls back to English `label` when `label_id` is NULL.
    // Wrapped in try/catch so pre-migration databases still work.
    try {
        $groups = $db->query("SELECT `key`, label, label_id FROM `groups` ORDER BY sort_order")->fetchAll();
    } catch (Exception $e) {
        $groups = $db->query("SELECT `key`, label FROM `groups` ORDER BY sort_order")->fetchAll();
    }
    try {
        $categories = $db->query("SELECT `key`, group_key, label, label_id FROM categories ORDER BY sort_order")->fetchAll();
    } catch (Exception $e) {
        $categories = $db->query("SELECT `key`, group_key, label FROM categories ORDER BY sort_order")->fetchAll();
    }
    try {
        $areas = $db->query("SELECT `key`, label, label_id, region_key FROM areas ORDER BY sort_order")->fetchAll();
    } catch (Exception $e) {
        $areas = $db->query("SELECT `key`, label, region_key FROM areas ORDER BY sort_order")->fetchAll();
    }

    // Regions
    $regions = [];
    try {
        $regions = $db->query("SELECT region_key, label, label_id, sort_order FROM area_regions ORDER BY sort_order")->fetchAll();
    } catch (Exception $e) {
        try {
            $regions = $db->query("SELECT region_key, label, sort_order FROM area_regions ORDER BY sort_order")->fetchAll();
        } catch (Exception $e2) { /* table may not exist yet */ }
    }
    try {
        $project_types = $db->query("SELECT `key`, label, label_id FROM project_types ORDER BY sort_order")->fetchAll();
    } catch (Exception $e) {
        $project_types = $db->query("SELECT `key`, label FROM project_types ORDER BY sort_order")->fetchAll();
    }
    try {
        $project_statuses = $db->query("SELECT `key`, label, label_id FROM project_statuses ORDER BY sort_order")->fetchAll();
    } catch (Exception $e) {
        $project_statuses = $db->query("SELECT `key`, label FROM project_statuses ORDER BY sort_order")->fetchAll();
    }
    $listing_types = [];
    try { $listing_types = $db->query("SELECT `key`, label, label_id FROM listing_types ORDER BY sort_order")->fetchAll(); }
    catch (Exception $e) {
        try { $listing_types = $db->query("SELECT `key`, label FROM listing_types ORDER BY sort_order")->fetchAll(); } catch (Exception $e2) {}
    }
    $land_certificate_types = [];
    try { $land_certificate_types = $db->query("SELECT `key`, label, label_id FROM land_certificate_types ORDER BY sort_order")->fetchAll(); }
    catch (Exception $e) {
        try { $land_certificate_types = $db->query("SELECT `key`, label FROM land_certificate_types ORDER BY sort_order")->fetchAll(); } catch (Exception $e2) {}
    }

    // Currency rates for frontend conversion
    $currency_rates = [];
    try {
        $cr = $db->query("SELECT from_currency, to_currency, rate FROM currency_rates")->fetchAll();
        foreach ($cr as $r) {
            $currency_rates[$r['from_currency'] . '_' . $r['to_currency']] = (float)$r['rate'];
        }
    } catch (Exception $e) {}

    // Feature Tags — DB table once migrated, canonical PHP list before that
    $feature_tags = [];
    try {
        $feature_tags = $db->query("SELECT `key`, label, label_id, applies_to FROM feature_tags ORDER BY sort_order")->fetchAll();
    } catch (Exception $e) {
        foreach (feature_tag_defs() as $key => $def) {
            $feature_tags[] = ['key' => $key, 'label' => $def['label'], 'label_id' => $def['label_id'], 'applies_to' => $def['applies_to']];
        }
    }

    json_out([
        'groups' => $groups,
        'categories' => $categories,
        'areas' => $areas,
        'regions' => $regions,
        'project_types' => $project_types,
        'project_statuses' => $project_statuses,
        'listing_types' => $listing_types,
        'land_certificate_types' => $land_certificate_types,
        'currency_rates' => $currency_rates,
        'feature_tags' => $feature_tags,
    ]);
}


// =============================================================
// CROSS-ENTITY SEARCH
// =============================================================

/**
 * Unified search across 6 entity types:
 *   providers, developers, projects, listings, agents, guides
 *
 * Each row carries a `score` = FULLTEXT relevance + small boosts for
 * is_featured / is_trusted / is_verified (see ADR-0001).
 *
 * Modes:
 *   default        — up to 100 rows per type (used by the /search page)
 *   ?palette=1     — capped at 6 rows per type (used by the command palette;
 *                    cuts backend work by ~94% on the high-volume path)
 *
 * Agents and guides use try/catch FULLTEXT-then-LIKE so the endpoint
 * keeps working even before the FULLTEXT migration has run on prod.
 *
 * Telemetry: every successful search (q ≥ 3 chars) is logged to
 * search_queries with a 2-second dedup window. Anonymous queries log
 * user_id = NULL. Failures are silently ignored — telemetry never breaks
 * the response.
 */
/**
 * Parse a free-text query into structured tokens by matching against DB vocabulary.
 * Tokens that match known areas, listing types, or provider categories are extracted
 * as structured filters; remaining words become free-text for LIKE search.
 *
 * This lets "Land Kuta" → {listing_type_keys:['land'], area_keys:['kuta'], text:[]}
 * and "notaris senggigi" → {category_keys:['notaris'], area_keys:['senggigi'], text:[]}
 * work correctly even when listing content is in Indonesian.
 */
function _parse_search_tokens(PDO $db, string $q): array {
    $raw_tokens = preg_split('/\s+/u', trim($q), -1, PREG_SPLIT_NO_EMPTY);

    // Build area vocabulary: key → key, and label → key (case-insensitive)
    $area_vocab = [];
    foreach ($db->query("SELECT `key`, LOWER(label) AS lbl FROM areas")->fetchAll() as $r) {
        $area_vocab[$r['key']] = $r['key'];
        $area_vocab[$r['lbl']] = $r['key'];
    }

    // Build listing-type vocabulary + Indonesian synonyms
    $type_vocab = ['tanah' => 'land', 'vila' => 'villa', 'rumah' => 'house', 'kavling' => 'land'];
    try {
        $lt_rows = $db->query("SELECT `key`, LOWER(label) AS lbl, LOWER(IFNULL(label_id,'')) AS lbl_id FROM listing_types")->fetchAll();
    } catch (PDOException $e) {
        $lt_rows = $db->query("SELECT `key`, LOWER(label) AS lbl, '' AS lbl_id FROM listing_types")->fetchAll();
    }
    foreach ($lt_rows as $r) {
        $type_vocab[$r['key']] = $r['key'];
        $type_vocab[$r['lbl']] = $r['key'];
        if ($r['lbl_id'] !== '') $type_vocab[$r['lbl_id']] = $r['key'];
    }

    // Build category vocabulary: key → key, label → key
    $cat_vocab = [];
    foreach ($db->query("SELECT `key`, LOWER(label) AS lbl FROM categories")->fetchAll() as $r) {
        $cat_vocab[$r['key']] = $r['key'];
        $cat_vocab[$r['lbl']] = $r['key'];
    }

    $area_keys = [];
    $type_keys = [];
    $cat_keys  = [];
    $text      = [];

    foreach ($raw_tokens as $tok) {
        $lower = mb_strtolower($tok, 'UTF-8');
        if (isset($area_vocab[$lower])) {
            $area_keys[] = $area_vocab[$lower];
        } elseif (isset($type_vocab[$lower])) {
            $type_keys[] = $type_vocab[$lower];
        } elseif (isset($cat_vocab[$lower])) {
            $cat_keys[] = $cat_vocab[$lower];
        } else {
            $text[] = $tok;
        }
    }

    return [
        'area_keys'         => array_values(array_unique($area_keys)),
        'listing_type_keys' => array_values(array_unique($type_keys)),
        'category_keys'     => array_values(array_unique($cat_keys)),
        'text_tokens'       => $text,
        'raw'               => $q,
    ];
}

/** Build a LIKE/structured WHERE clause for listings and return [sql_fragment, params]. */
function _listing_search_where(array $parsed): array {
    $conds  = [];
    $params = [];

    if (!empty($parsed['area_keys'])) {
        $ph = implode(',', array_fill(0, count($parsed['area_keys']), '?'));
        $conds[]  = "l.area_key IN ($ph)";
        $params   = array_merge($params, $parsed['area_keys']);
    }
    if (!empty($parsed['listing_type_keys'])) {
        $ph = implode(',', array_fill(0, count($parsed['listing_type_keys']), '?'));
        $conds[]  = "l.listing_type_key IN ($ph)";
        $params   = array_merge($params, $parsed['listing_type_keys']);
    }

    $text_q = implode(' ', $parsed['text_tokens']);
    if ($text_q !== '') {
        $like     = '%' . $text_q . '%';
        $conds[]  = "(l.title LIKE ? OR l.short_description LIKE ? OR l.description LIKE ?)";
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    } elseif (empty($conds)) {
        // No structured match at all — fall back to raw LIKE on full query
        $like     = '%' . $parsed['raw'] . '%';
        $conds[]  = "(l.title LIKE ? OR l.short_description LIKE ? OR l.description LIKE ?)";
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }

    return [implode(' AND ', $conds), $params];
}

/** Build a WHERE clause for providers. All structured tokens are AND-ed together.
 *  "notaris mataram" → category=notaris AND area=mataram (not OR).
 *  Falls back to broad LIKE when nothing matches the vocabulary. */
function _provider_search_where(array $parsed): array {
    $conds  = [];
    $params = [];

    // Area — AND: provider must be in this area
    if (!empty($parsed['area_keys'])) {
        $ph = implode(',', array_fill(0, count($parsed['area_keys']), '?'));
        $conds[]  = "p.area_key IN ($ph)";
        $params   = array_merge($params, $parsed['area_keys']);
    }

    // Category — AND: provider must have this category
    if (!empty($parsed['category_keys'])) {
        $ph = implode(',', array_fill(0, count($parsed['category_keys']), '?'));
        $conds[] = "EXISTS (
            SELECT 1 FROM provider_categories pc
            WHERE pc.provider_id = p.id AND pc.category_key IN ($ph)
        )";
        $params = array_merge($params, $parsed['category_keys']);
    }

    // Free text — AND: remaining unrecognised words must appear in name or description
    $text_q = implode(' ', $parsed['text_tokens']);
    if ($text_q !== '') {
        $like     = '%' . $text_q . '%';
        $conds[]  = "(p.name LIKE ? OR p.short_description LIKE ? OR p.description LIKE ?)";
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }

    // Nothing matched vocabulary — broad LIKE + tag/category LIKE fallback
    if (empty($conds)) {
        $like     = '%' . $parsed['raw'] . '%';
        $conds[]  = "(p.name LIKE ? OR p.short_description LIKE ? OR p.description LIKE ?
                      OR EXISTS (
                          SELECT 1 FROM provider_categories pc
                          JOIN categories c ON c.`key` = pc.category_key
                          WHERE pc.provider_id = p.id AND (c.`key` LIKE ? OR c.label LIKE ?)
                      )
                      OR EXISTS (
                          SELECT 1 FROM provider_tags pt
                          WHERE pt.provider_id = p.id AND pt.tag LIKE ?
                      ))";
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }

    return [implode(' AND ', $conds), $params];
}

function handle_search(): void {
    $q = trim($_GET['q'] ?? '');
    if (strlen($q) < 2) json_error(400, 'Search query must be at least 2 characters');

    $db = get_db();
    $is_palette = !empty($_GET['palette']);
    $default_limit = $is_palette ? 6 : 100;
    $limit = min(100, max(1, (int)($_GET['limit'] ?? $default_limit)));

    $parsed  = _parse_search_tokens($db, $q);
    $results = [];

    // ---------------------------------------------------------------
    // Providers — structured category/area + LIKE text fallback
    // ---------------------------------------------------------------
    list($p_where, $p_params) = _provider_search_where($parsed);
    $like = '%' . $q . '%';
    $stmt = $db->prepare(
        "SELECT 'provider' AS type,
                p.id, p.slug, p.name, p.short_description AS excerpt,
                p.google_rating, p.google_review_count,
                p.area_key AS area, p.languages,
                p.whatsapp_number, p.phone,
                p.logo_url, p.profile_photo_url,
                p.is_trusted, p.is_featured, p.badge,
                a.label AS area_label,
                (IF(p.is_featured = 1, 0.5, 0) + IF(p.is_trusted = 1, 0.3, 0)) AS score
         FROM providers p
         LEFT JOIN areas a ON a.`key` = p.area_key
         WHERE p.is_active = 1 AND $p_where
         ORDER BY score DESC, p.name ASC
         LIMIT ?"
    );
    $stmt->execute(array_merge($p_params, [$limit]));
    $providers = $stmt->fetchAll();
    attach_tags($providers, 'provider_tags', 'provider_id');
    attach_categories($providers, 'provider_categories', 'provider_id');
    $results = array_merge($results, $providers);

    // ---------------------------------------------------------------
    // Developers — LIKE with area filter
    // ---------------------------------------------------------------
    $d_conds  = ['d.is_active = 1'];
    $d_params = [];
    if (!empty($parsed['area_keys'])) {
        $ph = implode(',', array_fill(0, count($parsed['area_keys']), '?'));
        $d_conds[]  = "EXISTS (SELECT 1 FROM developer_areas da WHERE da.developer_id = d.id AND da.area_key IN ($ph))";
        $d_params   = array_merge($d_params, $parsed['area_keys']);
    }
    $dev_text = implode(' ', $parsed['text_tokens']) ?: $q;
    $dev_like = '%' . $dev_text . '%';
    $d_conds[]  = "(d.name LIKE ? OR d.short_description LIKE ? OR d.description LIKE ?)";
    $d_params[] = $dev_like;
    $d_params[] = $dev_like;
    $d_params[] = $dev_like;
    $stmt = $db->prepare(
        "SELECT 'developer' AS type,
                d.id, d.slug, d.name, d.short_description AS excerpt,
                d.google_rating, d.google_review_count,
                d.languages, d.logo_url, d.profile_photo_url,
                d.whatsapp_number, d.phone,
                d.is_featured, d.badge,
                IF(d.is_featured = 1, 0.5, 0) AS score
         FROM developers d
         WHERE " . implode(' AND ', $d_conds) . "
         ORDER BY score DESC, d.name ASC
         LIMIT ?"
    );
    $stmt->execute(array_merge($d_params, [$limit]));
    $results = array_merge($results, $stmt->fetchAll());

    // ---------------------------------------------------------------
    // Projects — LIKE with area filter
    // ---------------------------------------------------------------
    $pr_conds  = ['p.is_active = 1'];
    $pr_params = [];
    if (!empty($parsed['area_keys'])) {
        $ph = implode(',', array_fill(0, count($parsed['area_keys']), '?'));
        $pr_conds[]  = "p.area_key IN ($ph)";
        $pr_params   = array_merge($pr_params, $parsed['area_keys']);
    }
    $proj_text = implode(' ', $parsed['text_tokens']) ?: $q;
    $proj_like = '%' . $proj_text . '%';
    $pr_conds[]  = "(p.name LIKE ? OR p.short_description LIKE ? OR p.description LIKE ?)";
    $pr_params[] = $proj_like;
    $pr_params[] = $proj_like;
    $pr_params[] = $proj_like;
    $stmt = $db->prepare(
        "SELECT 'project' AS type,
                p.id, p.slug, p.name, p.short_description AS excerpt,
                NULL AS google_rating, NULL AS google_review_count,
                p.logo_url, p.logo_url AS profile_photo_url,
                p.area_key AS area, a.label AS area_label,
                IF(p.is_featured = 1, 0.5, 0) AS score
         FROM projects p
         LEFT JOIN areas a ON a.`key` = p.area_key
         WHERE " . implode(' AND ', $pr_conds) . "
         ORDER BY score DESC, p.name ASC
         LIMIT ?"
    );
    $stmt->execute(array_merge($pr_params, [$limit]));
    $results = array_merge($results, $stmt->fetchAll());

    // ---------------------------------------------------------------
    // Listings — structured area/type + LIKE text fallback
    // ---------------------------------------------------------------
    list($l_where, $l_params) = _listing_search_where($parsed);
    $stmt = $db->prepare(
        "SELECT 'listing' AS type,
                l.id, l.slug, l.title AS name, l.title,
                l.short_description AS excerpt,
                l.price_usd, l.price_idr, l.price_eur, l.price_aud, l.price_label,
                l.land_size_sqm, l.land_size_are, l.building_size_sqm,
                l.bedrooms, l.bathrooms,
                l.listing_type_key, l.area_key AS area, a.label AS area_label,
                l.certificate_type_key, l.location_detail,
                l.source_url, l.source_site,
                l.photo_urls, l.is_featured,
                lt.label AS listing_type_label,
                lct.label AS certificate_type_label,
                NULL AS google_rating, NULL AS google_review_count,
                IF(l.is_featured = 1, 0.5, 0) AS score
         FROM listings l
         LEFT JOIN areas a ON a.`key` = l.area_key
         LEFT JOIN listing_types lt ON lt.`key` = l.listing_type_key
         LEFT JOIN land_certificate_types lct ON lct.`key` = l.certificate_type_key
         WHERE l.status = 'active' AND l.is_approved = 1
           AND $l_where
         ORDER BY score DESC, l.created_at DESC
         LIMIT ?"
    );
    $stmt->execute(array_merge($l_params, [$limit]));
    $listings_found = $stmt->fetchAll();
    attach_listing_primary_image($listings_found);
    $results = array_merge($results, $listings_found);

    // ---------------------------------------------------------------
    // Agents — FULLTEXT with verified boost; LIKE fallback if no index
    // ---------------------------------------------------------------
    $agents = _search_agents($db, $q, $limit);
    $results = array_merge($results, $agents);

    // ---------------------------------------------------------------
    // Guides — FULLTEXT then created_at; LIKE fallback if no index
    // ---------------------------------------------------------------
    $guides = _search_guides($db, $q, $limit);
    $results = array_merge($results, $guides);

    // PDO returns tinyint/bit columns as strings; cast boolean fields to int
    // so JS truthiness checks ('0' is truthy) work correctly in the browser.
    $bool_fields = ['is_trusted', 'is_featured', 'is_verified', 'is_active', 'is_approved'];
    foreach ($results as &$r) {
        foreach ($bool_fields as $f) {
            if (array_key_exists($f, $r)) $r[$f] = (int)$r[$f];
        }
    }
    unset($r);

    // Fire-and-forget telemetry — never blocks the response
    _search_log_query($db, $q, count($results));

    json_out(['data' => $results, 'query' => $q, 'palette' => $is_palette]);
}

/** Agents: try FULLTEXT, fall back to LIKE if the index isn't there yet. */
function _search_agents(PDO $db, string $q, int $limit): array {
    try {
        $stmt = $db->prepare(
            "SELECT 'agent' AS type,
                    ag.id, ag.slug, ag.display_name AS name, ag.bio AS excerpt,
                    ag.agency_name, ag.areas_served, ag.languages,
                    ag.google_rating, ag.google_review_count,
                    ag.whatsapp_number, ag.phone,
                    NULL AS logo_url, ag.profile_photo_url,
                    ag.is_verified,
                    (MATCH(ag.display_name, ag.agency_name, ag.bio) AGAINST(? IN BOOLEAN MODE)
                     + IF(ag.is_verified = 1, 0.3, 0)) AS score
             FROM agents ag
             WHERE ag.is_active = 1" . _agent_visible_sql($db) . "
               AND MATCH(ag.display_name, ag.agency_name, ag.bio) AGAINST(? IN BOOLEAN MODE)
             ORDER BY score DESC
             LIMIT ?"
        );
        $stmt->execute([$q, $q, $limit]);
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        // FULLTEXT index missing — degrade to LIKE so search still works
        $like = '%' . $q . '%';
        $stmt = $db->prepare(
            "SELECT 'agent' AS type,
                    ag.id, ag.slug, ag.display_name AS name, ag.bio AS excerpt,
                    ag.agency_name, ag.areas_served, ag.languages,
                    ag.google_rating, ag.google_review_count,
                    ag.whatsapp_number, ag.phone,
                    NULL AS logo_url, ag.profile_photo_url,
                    ag.is_verified,
                    IF(ag.is_verified = 1, 0.3, 0) AS score
             FROM agents ag
             WHERE ag.is_active = 1" . _agent_visible_sql($db) . "
               AND (ag.display_name LIKE ? OR ag.agency_name LIKE ? OR ag.bio LIKE ?)
             ORDER BY ag.is_verified DESC, ag.display_name ASC
             LIMIT ?"
        );
        $stmt->execute([$like, $like, $like, $limit]);
        return $stmt->fetchAll();
    }
}

/** Guides: try FULLTEXT, fall back to LIKE if the index isn't there yet. */
function _search_guides(PDO $db, string $q, int $limit): array {
    try {
        $stmt = $db->prepare(
            "SELECT 'guide' AS type,
                    g.id, g.slug, g.title AS name, g.excerpt,
                    g.category, g.read_time, g.created_at,
                    MATCH(g.title, g.excerpt, g.content) AGAINST(? IN BOOLEAN MODE) AS score
             FROM guides g
             WHERE g.is_published = 1
               AND MATCH(g.title, g.excerpt, g.content) AGAINST(? IN BOOLEAN MODE)
             ORDER BY score DESC, g.created_at DESC
             LIMIT ?"
        );
        $stmt->execute([$q, $q, $limit]);
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        $like = '%' . $q . '%';
        $stmt = $db->prepare(
            "SELECT 'guide' AS type,
                    g.id, g.slug, g.title AS name, g.excerpt,
                    g.category, g.read_time, g.created_at,
                    0 AS score
             FROM guides g
             WHERE g.is_published = 1
               AND (g.title LIKE ? OR g.excerpt LIKE ? OR g.content LIKE ?)
             ORDER BY g.created_at DESC
             LIMIT ?"
        );
        $stmt->execute([$like, $like, $like, $limit]);
        return $stmt->fetchAll();
    }
}

/**
 * Log a search query for future "Popular Searches" analysis (ADR-0001, Q18).
 * Skips short queries and 2-second duplicates from the same session.
 * Silent on any failure — telemetry must never break the response.
 */
function _search_log_query(PDO $db, string $q, int $result_count): void {
    if (strlen($q) < 3) return;
    try {
        // Pick up user_id from session if one is already open (login flows
        // start the session in api/user.php). We don't open a new session
        // here — anonymous searches log NULL.
        $uid = null;
        if (function_exists('session_status') && session_status() === PHP_SESSION_ACTIVE) {
            $uid = $_SESSION['user_id'] ?? null;
        }
        // 2-second dedup: only INSERT if no matching row exists in the
        // last 2 seconds. <=> is MySQL's NULL-safe equality operator.
        $stmt = $db->prepare(
            "INSERT INTO search_queries (query, result_count, user_id)
             SELECT ?, ?, ?
             FROM DUAL
             WHERE NOT EXISTS (
                 SELECT 1 FROM search_queries
                 WHERE query = ?
                   AND (user_id <=> ?)
                   AND created_at > NOW() - INTERVAL 2 SECOND
             )"
        );
        $stmt->execute([
            mb_substr($q, 0, 200), $result_count, $uid,
            mb_substr($q, 0, 200), $uid,
        ]);
    } catch (PDOException $e) {
        // Table may not exist yet (migration not run). Stay silent.
    }
}


// =============================================================
// LISTINGS
// =============================================================

/**
 * Canonical Feature Tags (see CONTEXT.md). Each entry: label (EN), label_id (ID),
 * applies_to ('all' or comma list of listing_type keys), and the bilingual
 * keywords used both by the migration backfill and the transitional LIKE
 * fallback while listing_tags is still empty.
 * The DB table feature_tags (created by migration) overrides labels/sort;
 * this array keeps the API fully functional before the migration runs.
 */
function feature_tag_defs(): array {
    $built = 'villa,house,apartment,commercial,long_term_rental';
    return [
        'beachfront'      => ['label' => 'Beachfront',      'label_id' => 'Tepi Pantai',        'applies_to' => 'all',
                              'keywords' => ['beachfront', 'beach front', 'tepi pantai', 'pinggir pantai', 'depan pantai']],
        'ocean_view'      => ['label' => 'Ocean View',      'label_id' => 'Pemandangan Laut',   'applies_to' => 'all',
                              'keywords' => ['ocean view', 'sea view', 'seaview', 'pemandangan laut', 'view laut']],
        'mountain_view'   => ['label' => 'Mountain View',   'label_id' => 'Pemandangan Gunung', 'applies_to' => 'all',
                              'keywords' => ['mountain view', 'rinjani view', 'pemandangan gunung', 'view gunung']],
        'rice_field_view' => ['label' => 'Rice Field View', 'label_id' => 'Pemandangan Sawah',  'applies_to' => 'all',
                              'keywords' => ['rice field', 'ricefield', 'rice paddy', 'paddy view', 'sawah']],
        'cliff_top'       => ['label' => 'Cliff Top',       'label_id' => 'Atas Tebing',        'applies_to' => 'all',
                              'keywords' => ['cliff', 'clifftop', 'tebing']],
        'near_airport'    => ['label' => 'Near Airport',    'label_id' => 'Dekat Bandara',      'applies_to' => 'all',
                              'keywords' => ['airport', 'bandara']],
        'pool'            => ['label' => 'Swimming Pool',   'label_id' => 'Kolam Renang',       'applies_to' => $built,
                              'keywords' => ['swimming pool', 'private pool', 'kolam renang']],
        'furnished'       => ['label' => 'Furnished',       'label_id' => 'Berperabot',         'applies_to' => $built,
                              'keywords' => ['fully furnished', 'full furnished', 'semi furnished', 'berperabot', 'full furnish']],
    ];
}

/** True once the migration backfill has populated listing_tags. */
function listing_tags_populated(): bool {
    static $populated = null;
    if ($populated === null) {
        try {
            $populated = (bool)get_db()->query("SELECT 1 FROM listing_tags LIMIT 1")->fetchColumn();
        } catch (Exception $e) {
            $populated = false;
        }
    }
    return $populated;
}

/**
 * Shared WHERE builder for listings list + listing_counts.
 * $skip lets the counts endpoint exclude location filters so the map
 * always shows the full geographic distribution of the current search.
 */
function build_listing_filters(array $skip = []): array {
    $where = ["l.status = 'active'", 'l.is_approved = 1'];
    $params = [];

    // Filter: listing_type
    if (!empty($_GET['listing_type']) && !in_array('listing_type', $skip)) {
        $where[] = 'l.listing_type_key = ?';
        $params[] = $_GET['listing_type'];
    }
    // Filter: area
    if (!empty($_GET['area']) && !in_array('area', $skip)) {
        $where[] = 'l.area_key = ?';
        $params[] = $_GET['area'];
    }
    // Filter: region
    if (!empty($_GET['region']) && !in_array('region', $skip)) {
        $where[] = 'l.area_key IN (SELECT `key` FROM areas WHERE region_key = ?)';
        $params[] = $_GET['region'];
    }
    // Filter: price range (USD) — legacy callers only; canonical filtering is IDR
    if (!empty($_GET['min_price_usd'])) {
        $where[] = 'l.price_usd >= ?';
        $params[] = (int)$_GET['min_price_usd'];
    }
    if (!empty($_GET['max_price_usd'])) {
        $where[] = 'l.price_usd <= ?';
        $params[] = (int)$_GET['max_price_usd'];
    }
    // Filter: price range (canonical IDR — see docs/adr/0006)
    if (!empty($_GET['min_price_idr'])) {
        $where[] = 'l.price_idr >= ?';
        $params[] = (int)$_GET['min_price_idr'];
    }
    if (!empty($_GET['max_price_idr'])) {
        $where[] = 'l.price_idr <= ?';
        $params[] = (int)$_GET['max_price_idr'];
    }
    // Filter: land size range — only match listings that HAVE a land size
    if (!empty($_GET['min_size']) || !empty($_GET['max_size'])) {
        $where[] = 'l.land_size_sqm IS NOT NULL AND l.land_size_sqm > 0';
    }
    if (!empty($_GET['min_size'])) {
        $where[] = 'l.land_size_sqm >= ?';
        $params[] = (int)$_GET['min_size'];
    }
    if (!empty($_GET['max_size'])) {
        $where[] = 'l.land_size_sqm <= ?';
        $params[] = (int)$_GET['max_size'];
    }
    // Filter: building size range
    if (!empty($_GET['min_building_size'])) {
        $where[] = 'l.building_size_sqm >= ?';
        $params[] = (int)$_GET['min_building_size'];
    }
    if (!empty($_GET['max_building_size'])) {
        $where[] = 'l.building_size_sqm <= ?';
        $params[] = (int)$_GET['max_building_size'];
    }
    // Filter: certificate type
    if (!empty($_GET['certificate_type'])) {
        $where[] = 'l.certificate_type_key = ?';
        $params[] = $_GET['certificate_type'];
    }
    // Filter: bedrooms
    if (!empty($_GET['min_beds'])) {
        $where[] = 'l.bedrooms >= ?';
        $params[] = (int)$_GET['min_beds'];
    }
    // Filter: bathrooms
    if (!empty($_GET['min_baths'])) {
        $where[] = 'l.bathrooms >= ?';
        $params[] = (int)$_GET['min_baths'];
    }
    // Filter: specific agent
    if (!empty($_GET['agent_id'])) {
        $where[] = 'l.agent_id = ?';
        $params[] = (int)$_GET['agent_id'];
    }
    // Filter: featured
    if (isset($_GET['featured']) && $_GET['featured'] === '1') {
        $where[] = 'l.is_featured = 1';
    }
    // Filter: Feature Tags (comma-separated canonical keys, AND semantics).
    // Uses listing_tags once the backfill migration has run; until then falls
    // back to a bilingual keyword scan so the UI works pre-migration.
    if (!empty($_GET['tags'])) {
        $defs = feature_tag_defs();
        $tags = array_filter(array_map('trim', explode(',', $_GET['tags'])));
        $use_table = listing_tags_populated();
        foreach ($tags as $tag) {
            if (!isset($defs[$tag])) continue; // unknown tag — ignore, never error
            if ($use_table) {
                $where[] = 'EXISTS (SELECT 1 FROM listing_tags ltg WHERE ltg.listing_id = l.id AND ltg.tag = ?)';
                $params[] = $tag;
            } else {
                $conds = [];
                foreach ($defs[$tag]['keywords'] as $kw) {
                    $conds[] = "(l.title LIKE ? OR l.short_description LIKE ? OR l.description LIKE ?)";
                    $like = '%' . $kw . '%';
                    array_push($params, $like, $like, $like);
                }
                $where[] = '(' . implode(' OR ', $conds) . ')';
            }
        }
    }
    // Filter: fulltext search
    if (!empty($_GET['q'])) {
        $where[] = 'MATCH(l.title, l.short_description, l.description) AGAINST(? IN BOOLEAN MODE)';
        $params[] = $_GET['q'];
    }

    return [implode(' AND ', $where), $params];
}

/**
 * Aggregate listing counts per Region and Area for the interactive map.
 * Honours every active filter EXCEPT location ones, so the map shows where
 * the current search's inventory lives across the whole island.
 */
function handle_listing_counts(): void {
    try {
        $db = get_db();
        [$where_sql, $params] = build_listing_filters(['area', 'region']);
        $stmt = $db->prepare(
            "SELECT l.area_key, a.region_key, COUNT(*) AS c
             FROM listings l
             LEFT JOIN areas a ON a.`key` = l.area_key
             WHERE {$where_sql}
             GROUP BY l.area_key, a.region_key"
        );
        $stmt->execute($params);
        $areas = [];
        $regions = [];
        $total = 0;
        foreach ($stmt->fetchAll() as $row) {
            $n = (int)$row['c'];
            $total += $n;
            if (!empty($row['area_key'])) {
                $areas[$row['area_key']] = ($areas[$row['area_key']] ?? 0) + $n;
            }
            $rk = $row['region_key'] ?: 'other';
            $regions[$rk] = ($regions[$rk] ?? 0) + $n;
        }
        json_out(['regions' => $regions, 'areas' => $areas, 'total' => $total]);
    } catch (Exception $e) {
        json_out(['regions' => new stdClass(), 'areas' => new stdClass(), 'total' => 0, 'debug_error' => $e->getMessage()]);
    }
}

function handle_listings_list(): void {
    try {
        $db = get_db();
    [$page, $per_page, $offset] = get_page_params();

    [$where_sql, $params] = build_listing_filters();

    $sort = get_sort_param(['price_idr', 'price_usd', 'land_size_sqm', 'created_at', 'title'], 'created_at');
    $order = "l.is_featured DESC, l.{$sort} " . get_sort_dir();
    // Sorting by price must not bury priced listings under NULL "price on
    // request" rows when ascending.
    if ($sort === 'price_idr' || $sort === 'price_usd') {
        $order = "l.is_featured DESC, (l.{$sort} IS NULL) ASC, l.{$sort} " . get_sort_dir();
    }

    // Count
    $count_stmt = $db->prepare("SELECT COUNT(*) FROM listings l WHERE {$where_sql}");
    $count_stmt->execute($params);
    $total = (int)$count_stmt->fetchColumn();

    // Fetch with joins
    $stmt = $db->prepare(
        "SELECT l.id, l.slug, l.title, l.short_description, l.listing_type_key, l.area_key,
                l.price_usd, l.price_idr, l.price_eur, l.price_aud, l.price_idr_per_sqm, l.price_label,
                l.land_size_sqm, l.land_size_are, l.building_size_sqm,
                l.bedrooms, l.bathrooms,
                l.certificate_type_key, l.is_featured, l.agent_id,
                l.source_url, l.source_site, l.location_detail,
                l.photo_urls,
                ag.display_name AS agent_name, ag.slug AS agent_slug,
                lt.label AS listing_type_label,
                lct.label AS certificate_type_label,
                a.label AS area_label
         FROM listings l
         LEFT JOIN agents ag ON ag.id = l.agent_id
         LEFT JOIN listing_types lt ON lt.`key` = l.listing_type_key
         LEFT JOIN land_certificate_types lct ON lct.`key` = l.certificate_type_key
         LEFT JOIN areas a ON a.`key` = l.area_key
         WHERE {$where_sql}
         ORDER BY {$order}
         LIMIT ? OFFSET ?"
    );
    $stmt->execute(array_merge($params, [$per_page, $offset]));
    $items = $stmt->fetchAll();

    // Attach primary image
    attach_listing_primary_image($items);
    // Attach tags
    attach_tags($items, 'listing_tags', 'listing_id');

    json_out(paginated_response($items, $total, $page, $per_page));
    } catch (Exception $e) {
        json_out(array('data' => array(), 'meta' => array('total' => 0, 'page' => 1, 'per_page' => 20, 'total_pages' => 0), 'debug_error' => $e->getMessage()));
    }
}

function handle_listing_detail(string $slug): void {
    $db = get_db();
    $stmt = $db->prepare(
        "SELECT l.*,
                ag.display_name AS agent_name, ag.slug AS agent_slug,
                ag.agency_name, ag.phone AS agent_phone,
                ag.whatsapp_number AS agent_whatsapp, ag.email AS agent_email,
                ag.bio AS agent_bio, ag.languages AS agent_languages,
                lt.label AS listing_type_label,
                lct.label AS certificate_type_label,
                a.label AS area_label
         FROM listings l
         LEFT JOIN agents ag ON ag.id = l.agent_id
         LEFT JOIN listing_types lt ON lt.`key` = l.listing_type_key
         LEFT JOIN land_certificate_types lct ON lct.`key` = l.certificate_type_key
         LEFT JOIN areas a ON a.`key` = l.area_key
         WHERE l.slug = ? AND l.status = 'active' AND l.is_approved = 1"
    );
    $stmt->execute([$slug]);
    $item = $stmt->fetch();
    if (!$item) json_error(404, 'Listing not found');

    // All images
    $i = $db->prepare("SELECT id, url, alt_text, is_primary, sort_order FROM listing_images WHERE listing_id = ? ORDER BY is_primary DESC, sort_order ASC");
    $i->execute([$item['id']]);
    $item['images'] = $i->fetchAll();

    // Tags
    $t = $db->prepare("SELECT tag FROM listing_tags WHERE listing_id = ? ORDER BY tag");
    $t->execute([$item['id']]);
    $item['tags'] = $t->fetchAll(PDO::FETCH_COLUMN);

    json_out(['data' => $item]);
}

/**
 * Attach the primary listing image to a list of listings.
 */
function attach_listing_primary_image(array &$items): void {
    if (empty($items)) return;
    $ids = array_column($items, 'id');
    $placeholders = implode(',', array_fill(0, count($ids), '?'));

    $db = get_db();
    $stmt = $db->prepare(
        "SELECT listing_id, url, alt_text FROM listing_images
         WHERE listing_id IN ({$placeholders}) AND is_primary = 1"
    );
    $stmt->execute($ids);
    $rows = $stmt->fetchAll();

    $map = [];
    foreach ($rows as $r) {
        $map[$r['listing_id']] = ['url' => $r['url'], 'alt' => $r['alt_text']];
    }
    foreach ($items as &$item) {
        if (isset($map[$item['id']])) {
            $item['image'] = $map[$item['id']];
        } elseif (!empty($item['photo_urls'])) {
            $photos = json_decode($item['photo_urls'], true);
            if (is_array($photos) && !empty($photos[0])) {
                $item['image'] = ['url' => $photos[0], 'alt' => isset($item['title']) ? $item['title'] : ''];
            }
        } else {
            $item['image'] = null;
        }
    }
}


// =============================================================
// AGENTS
// =============================================================

/** Cached column-existence check so new columns degrade gracefully pre-migration. */
function _col_exists(PDO $db, string $table, string $col): bool {
    static $cache = [];
    $k = $table . '.' . $col;
    if (!array_key_exists($k, $cache)) {
        try {
            $s = $db->prepare("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1");
            $s->execute([$table, $col]);
            $cache[$k] = (bool)$s->fetchColumn();
        } catch (PDOException $e) { $cache[$k] = false; }
    }
    return $cache[$k];
}

/** WHERE fragment hiding non-browsable agents (hidden private sellers, merged dupes). */
function _agent_visible_sql(PDO $db): string {
    if (!_col_exists($db, 'agents', 'agent_kind')) return '';
    return " AND ag.agent_kind = 'agent' AND ag.merged_into_agent_id IS NULL";
}

function handle_agents_list(): void {
    $db = get_db();
    [$page, $per_page, $offset] = get_page_params();

    $has_rep = _col_exists($db, 'agents', 'reputation_tier');
    $where = ['ag.is_active = 1'];
    if ($has_rep) { $where[] = "ag.agent_kind = 'agent'"; $where[] = 'ag.merged_into_agent_id IS NULL'; }
    $params = [];

    // Filter: area served
    if (!empty($_GET['area'])) {
        $where[] = 'ag.areas_served LIKE ?';
        $params[] = '%' . $_GET['area'] . '%';
    }
    // Filter: verified
    if (isset($_GET['verified']) && in_array($_GET['verified'], ['0', '1'])) {
        $where[] = 'ag.is_verified = ?';
        $params[] = (int)$_GET['verified'];
    }
    // Filter: fulltext search
    if (!empty($_GET['q'])) {
        $where[] = 'MATCH(ag.display_name, ag.agency_name, ag.bio) AGAINST(? IN BOOLEAN MODE)';
        $params[] = $_GET['q'];
    }

    $where_sql = implode(' AND ', $where);
    $sort = get_sort_param(['display_name', 'created_at'], 'display_name');
    // Earned Reputation leads the directory ranking when available (ADR 0008).
    $rep_lead = $has_rep ? 'ag.reputation_score DESC, ' : '';
    $order = $rep_lead . "ag.is_verified DESC, ag.{$sort} " . get_sort_dir();

    // Count
    $count_stmt = $db->prepare("SELECT COUNT(*) FROM agents ag WHERE {$where_sql}");
    $count_stmt->execute($params);
    $total = (int)$count_stmt->fetchColumn();

    // Fetch
    $rep_cols = $has_rep
        ? "ag.reputation_tier, ag.reputation_score, ag.listings_total, ag.listings_active,"
        : "";
    $stmt = $db->prepare(
        "SELECT ag.id, ag.slug, ag.display_name, ag.agency_name, ag.bio,
                ag.phone, ag.whatsapp_number, ag.email, ag.website_url,
                ag.areas_served, ag.languages, ag.is_verified,
                ag.google_rating, ag.google_review_count, ag.profile_photo_url,
                {$rep_cols}
                (SELECT COUNT(*) FROM listings l WHERE l.agent_id = ag.id AND l.status = 'active') AS listing_count
         FROM agents ag
         WHERE {$where_sql}
         ORDER BY {$order}
         LIMIT ? OFFSET ?"
    );
    $stmt->execute(array_merge($params, [$per_page, $offset]));
    $items = $stmt->fetchAll();

    json_out(paginated_response($items, $total, $page, $per_page));
}

function handle_agent_detail(string $slug): void {
    $db = get_db();
    $stmt = $db->prepare(
        "SELECT ag.*
         FROM agents ag
         WHERE ag.slug = ? AND ag.is_active = 1" . _agent_visible_sql($db)
    );
    $stmt->execute([$slug]);
    $item = $stmt->fetch();
    if (!$item) json_error(404, 'Agent not found');

    // Active listings for this agent
    $l = $db->prepare(
        "SELECT l.id, l.slug, l.title, l.listing_type_key, l.area_key, l.price_usd,
                l.land_size_sqm, l.building_size_sqm, l.certificate_type_key, l.is_featured,
                lt.label AS listing_type_label, a.label AS area_label
         FROM listings l
         LEFT JOIN listing_types lt ON lt.`key` = l.listing_type_key
         LEFT JOIN areas a ON a.`key` = l.area_key
         WHERE l.agent_id = ? AND l.status = 'active'
         ORDER BY l.is_featured DESC, l.created_at DESC"
    );
    $l->execute([$item['id']]);
    $listings = $l->fetchAll();
    attach_listing_primary_image($listings);
    $item['listings'] = $listings;

    // Reviews summary
    $item['reviews'] = [
        'google_rating' => $item['google_rating'] ?? null,
        'google_review_count' => $item['google_review_count'] ?? null,
        'last_review_check' => $item['last_review_check'] ?? null,
    ];

    json_out(['data' => $item]);
}
