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
    $order = "p.is_featured DESC, {$sort_col} " . get_sort_dir();

    // Count
    $count_stmt = $db->prepare("SELECT COUNT(*) FROM providers p WHERE {$where_sql}");
    $count_stmt->execute($params);
    $total = (int)$count_stmt->fetchColumn();

    // Fetch
    $stmt = $db->prepare(
        "SELECT p.id, p.slug, p.name, p.group_key, p.area_key,
                p.short_description, p.address, p.google_rating, p.google_review_count,
                p.phone, p.whatsapp_number, p.website_url, p.languages,
                p.instagram_url, p.facebook_url, p.profile_photo_url,
                p.is_featured, p.badge,
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
                d.website_url, d.languages, d.is_featured, d.badge, d.profile_photo_url
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
                p.short_description, p.info_contact_whatsapp, p.is_featured, p.badge,
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

    $groups = $db->query("SELECT `key`, label FROM `groups` ORDER BY sort_order")->fetchAll();
    $categories = $db->query("SELECT `key`, group_key, label FROM categories ORDER BY sort_order")->fetchAll();
    $areas = $db->query("SELECT `key`, label, region_key FROM areas ORDER BY sort_order")->fetchAll();

    // Regions
    $regions = [];
    try {
        $regions = $db->query("SELECT region_key, label, sort_order FROM area_regions ORDER BY sort_order")->fetchAll();
    } catch (Exception $e) { /* table may not exist yet */ }
    $project_types = $db->query("SELECT `key`, label FROM project_types ORDER BY sort_order")->fetchAll();
    $project_statuses = $db->query("SELECT `key`, label FROM project_statuses ORDER BY sort_order")->fetchAll();
    $listing_types = [];
    try { $listing_types = $db->query("SELECT `key`, label FROM listing_types ORDER BY sort_order")->fetchAll(); } catch (Exception $e) {}
    $land_certificate_types = [];
    try { $land_certificate_types = $db->query("SELECT `key`, label FROM land_certificate_types ORDER BY sort_order")->fetchAll(); } catch (Exception $e) {}

    json_out([
        'groups' => $groups,
        'categories' => $categories,
        'areas' => $areas,
        'regions' => $regions,
        'project_types' => $project_types,
        'project_statuses' => $project_statuses,
        'listing_types' => $listing_types,
        'land_certificate_types' => $land_certificate_types,
    ]);
}


// =============================================================
// CROSS-ENTITY SEARCH
// =============================================================

function handle_search(): void {
    $q = trim($_GET['q'] ?? '');
    if (strlen($q) < 2) json_error(400, 'Search query must be at least 2 characters');

    $db = get_db();
    $limit = min(10, max(1, (int)($_GET['limit'] ?? 10)));

    $results = [];

    // Providers
    $stmt = $db->prepare(
        "SELECT 'provider' AS type, slug, name, short_description AS excerpt, google_rating
         FROM providers
         WHERE is_active = 1 AND MATCH(name, short_description, description) AGAINST(? IN BOOLEAN MODE)
         LIMIT ?"
    );
    $stmt->execute([$q, $limit]);
    $results = array_merge($results, $stmt->fetchAll());

    // Developers
    $stmt = $db->prepare(
        "SELECT 'developer' AS type, slug, name, short_description AS excerpt, google_rating
         FROM developers
         WHERE is_active = 1 AND MATCH(name, short_description, description) AGAINST(? IN BOOLEAN MODE)
         LIMIT ?"
    );
    $stmt->execute([$q, $limit]);
    $results = array_merge($results, $stmt->fetchAll());

    // Projects
    $stmt = $db->prepare(
        "SELECT 'project' AS type, slug, name, short_description AS excerpt, NULL AS google_rating
         FROM projects
         WHERE is_active = 1 AND MATCH(name, short_description, description) AGAINST(? IN BOOLEAN MODE)
         LIMIT ?"
    );
    $stmt->execute([$q, $limit]);
    $results = array_merge($results, $stmt->fetchAll());

    // Listings
    $stmt = $db->prepare(
        "SELECT 'listing' AS type, l.slug, l.title AS name, l.short_description AS excerpt, NULL AS google_rating
         FROM listings l
         WHERE l.status = 'active' AND l.is_approved = 1
           AND MATCH(l.title, l.short_description, l.description) AGAINST(? IN BOOLEAN MODE)
         LIMIT ?"
    );
    $stmt->execute([$q, $limit]);
    $results = array_merge($results, $stmt->fetchAll());

    json_out(['data' => $results, 'query' => $q]);
}


// =============================================================
// LISTINGS
// =============================================================

function handle_listings_list(): void {
    try {
        $db = get_db();
    [$page, $per_page, $offset] = get_page_params();

    $where = ["l.status = 'active'", 'l.is_approved = 1'];
    $params = [];

    // Filter: listing_type
    if (!empty($_GET['listing_type'])) {
        $where[] = 'l.listing_type_key = ?';
        $params[] = $_GET['listing_type'];
    }
    // Filter: area
    if (!empty($_GET['area'])) {
        $where[] = 'l.area_key = ?';
        $params[] = $_GET['area'];
    }
    // Filter: region
    if (!empty($_GET['region'])) {
        $where[] = 'l.area_key IN (SELECT `key` FROM areas WHERE region_key = ?)';
        $params[] = $_GET['region'];
    }
    // Filter: price range (USD)
    if (!empty($_GET['min_price_usd'])) {
        $where[] = 'l.price_usd >= ?';
        $params[] = (int)$_GET['min_price_usd'];
    }
    if (!empty($_GET['max_price_usd'])) {
        $where[] = 'l.price_usd <= ?';
        $params[] = (int)$_GET['max_price_usd'];
    }
    // Filter: size range
    if (!empty($_GET['min_size'])) {
        $where[] = 'l.land_size_sqm >= ?';
        $params[] = (int)$_GET['min_size'];
    }
    if (!empty($_GET['max_size'])) {
        $where[] = 'l.land_size_sqm <= ?';
        $params[] = (int)$_GET['max_size'];
    }
    // Filter: certificate type
    if (!empty($_GET['certificate_type'])) {
        $where[] = 'l.certificate_type_key = ?';
        $params[] = $_GET['certificate_type'];
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
    // Filter: fulltext search
    if (!empty($_GET['q'])) {
        $where[] = 'MATCH(l.title, l.short_description, l.description) AGAINST(? IN BOOLEAN MODE)';
        $params[] = $_GET['q'];
    }

    $where_sql = implode(' AND ', $where);

    $sort = get_sort_param(['price_usd', 'land_size_sqm', 'created_at', 'title'], 'created_at');
    $order = "l.is_featured DESC, l.{$sort} " . get_sort_dir();

    // Count
    $count_stmt = $db->prepare("SELECT COUNT(*) FROM listings l WHERE {$where_sql}");
    $count_stmt->execute($params);
    $total = (int)$count_stmt->fetchColumn();

    // Fetch with joins
    $stmt = $db->prepare(
        "SELECT l.id, l.slug, l.title, l.short_description, l.listing_type_key, l.area_key,
                l.price_usd, l.price_idr, l.land_size_sqm, l.build_size_sqm,
                l.certificate_type_key, l.is_featured, l.badge, l.agent_id,
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
        json_out(paginated_response([], 0, 1, 20));
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
        $item['image'] = $map[$item['id']] ?? null;
    }
}


// =============================================================
// AGENTS
// =============================================================

function handle_agents_list(): void {
    $db = get_db();
    [$page, $per_page, $offset] = get_page_params();

    $where = ['ag.is_active = 1'];
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
    $order = "ag.is_verified DESC, ag.{$sort} " . get_sort_dir();

    // Count
    $count_stmt = $db->prepare("SELECT COUNT(*) FROM agents ag WHERE {$where_sql}");
    $count_stmt->execute($params);
    $total = (int)$count_stmt->fetchColumn();

    // Fetch
    $stmt = $db->prepare(
        "SELECT ag.id, ag.slug, ag.display_name, ag.agency_name, ag.bio,
                ag.phone, ag.whatsapp_number, ag.email, ag.website_url,
                ag.areas_served, ag.languages, ag.is_verified, ag.badge,
                ag.google_rating, ag.google_review_count, ag.profile_image_url,
                (SELECT COUNT(*) FROM listings l WHERE l.agent_id = ag.id AND l.status = 'active' AND l.is_approved = 1) AS active_listing_count
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
         WHERE ag.slug = ? AND ag.is_active = 1"
    );
    $stmt->execute([$slug]);
    $item = $stmt->fetch();
    if (!$item) json_error(404, 'Agent not found');

    // Active listings for this agent
    $l = $db->prepare(
        "SELECT l.id, l.slug, l.title, l.listing_type_key, l.area_key, l.price_usd,
                l.land_size_sqm, l.build_size_sqm, l.certificate_type_key, l.is_featured,
                lt.label AS listing_type_label, a.label AS area_label
         FROM listings l
         LEFT JOIN listing_types lt ON lt.`key` = l.listing_type_key
         LEFT JOIN areas a ON a.`key` = l.area_key
         WHERE l.agent_id = ? AND l.status = 'active' AND l.is_approved = 1
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
