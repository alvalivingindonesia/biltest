<?php
/**
 * Build in Lombok — Admin: Enrichment Tool (AJAX endpoint)
 *
 * Server-side search engines block shared hosting IPs.
 * This tool provides:
 *   - find_missing: DB query to list entities needing enrichment
 *   - quick_save: saves fields submitted from the client-side UI
 *
 * The actual searching happens client-side (admin's browser opens
 * Google/Instagram in iframes or new tabs).
 */
session_start();
require_once('/home/rovin629/config/biltest_config.php');

header('Content-Type: application/json');

if (empty($_SESSION['admin_auth'])) {
    echo json_encode(['error' => 'Not authenticated']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

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

// ═══════════════════════════════════════════════════════════════
// ACTION: find_missing — entities with N+ reviews but no image
// ═══════════════════════════════════════════════════════════════
if (($input['action'] ?? '') === 'find_missing') {
    $min_reviews = (int)($input['min_reviews'] ?? 10);
    $db = get_db();
    $entities = [];

    $rows = $db->prepare("
        SELECT id, name, google_review_count, google_rating, website_url, google_maps_url,
            profile_photo_url, logo_url, instagram_url, facebook_url, phone,
            short_description, 'provider' AS entity_type
        FROM providers
        WHERE google_review_count >= ?
          AND (profile_photo_url IS NULL OR profile_photo_url = '')
          AND is_active = 1
        ORDER BY google_review_count DESC
    ");
    $rows->execute([$min_reviews]);
    foreach ($rows->fetchAll() as $r) { $entities[] = $r; }

    $rows = $db->prepare("
        SELECT id, name, google_review_count, google_rating, website_url, google_maps_url,
            profile_photo_url, logo_url, instagram_url, facebook_url, phone,
            short_description, 'developer' AS entity_type
        FROM developers
        WHERE google_review_count >= ?
          AND (profile_photo_url IS NULL OR profile_photo_url = '')
          AND is_active = 1
        ORDER BY google_review_count DESC
    ");
    $rows->execute([$min_reviews]);
    foreach ($rows->fetchAll() as $r) { $entities[] = $r; }

    $rows = $db->prepare("
        SELECT id, display_name AS name, google_review_count, google_rating, website_url, google_maps_url,
            profile_photo_url, logo_url, instagram_url, facebook_url, phone,
            short_description, 'agent' AS entity_type
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
// ACTION: quick_save — save fields for one entity
// ═══════════════════════════════════════════════════════════════
if (($input['action'] ?? '') === 'quick_save') {
    $entity_type = $input['entity_type'] ?? '';
    $entity_id = (int)($input['entity_id'] ?? 0);
    $fields = $input['fields'] ?? [];

    if (!$entity_type || !$entity_id || empty($fields)) {
        echo json_encode(['error' => 'Missing entity_type, entity_id, or fields']);
        exit;
    }

    $db = get_db();
    $table = '';
    if ($entity_type === 'provider') { $table = 'providers'; }
    elseif ($entity_type === 'developer') { $table = 'developers'; }
    elseif ($entity_type === 'agent') { $table = 'agents'; }
    else { echo json_encode(['error' => 'Invalid entity_type']); exit; }

    // Validate columns exist
    $col_check = $db->query("SHOW COLUMNS FROM `{$table}`")->fetchAll(PDO::FETCH_COLUMN);
    $valid_cols = array_flip($col_check);

    $updates = [];
    $params = [];
    $saved_fields = [];
    foreach ($fields as $col => $val) {
        $val = trim($val);
        if (!$val) continue;
        if (!isset($valid_cols[$col])) continue;
        // Only safe column names (alphanumeric + underscore)
        if (!preg_match('/^[a-z_]+$/', $col)) continue;
        $updates[] = "`{$col}` = ?";
        $params[] = $val;
        $saved_fields[] = $col;
    }

    if (!$updates) {
        echo json_encode(['error' => 'No valid fields to save']);
        exit;
    }

    $params[] = $entity_id;
    $db->prepare("UPDATE `{$table}` SET " . implode(', ', $updates) . " WHERE id = ?")->execute($params);

    echo json_encode(['ok' => true, 'saved' => count($updates), 'fields' => $saved_fields]);
    exit;
}

echo json_encode(['error' => 'Unknown action']);
