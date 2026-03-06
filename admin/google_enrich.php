<?php
/**
 * Build in Lombok — Admin: Enrichment Tool (AJAX endpoint)
 */
error_reporting(0);
ini_set('display_errors', '0');
session_start();
require_once('/home/rovin629/config/biltest_config.php');

header('Content-Type: application/json');

if (empty($_SESSION['admin_auth'])) {
    echo json_encode(array('error' => 'Not authenticated'));
    exit;
}

$raw = file_get_contents('php://input');
$input = json_decode($raw, true);
if (!is_array($input)) { $input = array(); }
$action = isset($input['action']) ? $input['action'] : '';

function get_db() {
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO('mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4', DB_USER, DB_PASS, array(
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ));
    }
    return $pdo;
}

function table_has_col($db, $table, $col) {
    static $cache = array();
    $key = $table . '.' . $col;
    if (!isset($cache[$key])) {
        $cols = $db->query("SHOW COLUMNS FROM `{$table}`")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($cols as $c) { $cache[$table . '.' . $c] = true; }
        if (!isset($cache[$key])) { $cache[$key] = false; }
    }
    return $cache[$key];
}

// ═══════════════════════════════════════════════════════════════
// ACTION: find_missing
// ═══════════════════════════════════════════════════════════════
if ($action === 'find_missing') {
    try {
        $min_reviews = isset($input['min_reviews']) ? (int)$input['min_reviews'] : 10;
        $db = get_db();
        $entities = array();

        // Build safe SELECT for each table — only pick columns that exist
        $tables = array(
            'providers'  => array('name_col' => 'name',         'type' => 'provider'),
            'developers' => array('name_col' => 'name',         'type' => 'developer'),
            'agents'     => array('name_col' => 'display_name', 'type' => 'agent'),
        );

        $want_cols = array('profile_photo_url','logo_url','instagram_url','facebook_url','phone',
                           'website_url','google_maps_url','short_description');

        foreach ($tables as $tbl => $info) {
            $select = "id, {$info['name_col']} AS name, google_review_count, google_rating";
            $available = array();
            foreach ($want_cols as $wc) {
                if (table_has_col($db, $tbl, $wc)) {
                    $select .= ", {$wc}";
                    $available[] = $wc;
                }
            }
            $select .= ", '{$info['type']}' AS entity_type";

            $rows = $db->prepare("
                SELECT {$select}
                FROM {$tbl}
                WHERE google_review_count >= ?
                  AND (profile_photo_url IS NULL OR profile_photo_url = '')
                  AND is_active = 1
                ORDER BY google_review_count DESC
            ");
            $rows->execute(array($min_reviews));
            foreach ($rows->fetchAll() as $r) {
                // Fill missing cols with empty string so JS doesn't break
                foreach ($want_cols as $wc) {
                    if (!isset($r[$wc])) { $r[$wc] = ''; }
                }
                $entities[] = $r;
            }
        }

        echo json_encode(array('entities' => $entities, 'total' => count($entities)));
    } catch (Exception $ex) {
        echo json_encode(array('error' => 'DB error: ' . $ex->getMessage()));
    }
    exit;
}

// ═══════════════════════════════════════════════════════════════
// ACTION: quick_save
// ═══════════════════════════════════════════════════════════════
if ($action === 'quick_save') {
    try {
        $entity_type = isset($input['entity_type']) ? $input['entity_type'] : '';
        $entity_id   = isset($input['entity_id'])   ? (int)$input['entity_id'] : 0;
        $fields      = isset($input['fields'])       ? $input['fields'] : array();

        if (!$entity_type || !$entity_id || empty($fields)) {
            echo json_encode(array('error' => 'Missing entity_type, entity_id, or fields'));
            exit;
        }

        $db = get_db();
        $table = '';
        if ($entity_type === 'provider')      { $table = 'providers'; }
        elseif ($entity_type === 'developer') { $table = 'developers'; }
        elseif ($entity_type === 'agent')     { $table = 'agents'; }
        else { echo json_encode(array('error' => 'Invalid entity_type')); exit; }

        $updates = array();
        $params = array();
        $saved_fields = array();
        foreach ($fields as $col => $val) {
            $val = trim($val);
            if ($val === '') continue;
            if (!preg_match('/^[a-z_]+$/', $col)) continue;
            if (!table_has_col($db, $table, $col)) continue;
            $updates[] = "`{$col}` = ?";
            $params[] = $val;
            $saved_fields[] = $col;
        }

        if (empty($updates)) {
            echo json_encode(array('error' => 'No valid fields to save'));
            exit;
        }

        $params[] = $entity_id;
        $db->prepare("UPDATE `{$table}` SET " . implode(', ', $updates) . " WHERE id = ?")->execute($params);

        echo json_encode(array('ok' => true, 'saved' => count($updates), 'fields' => $saved_fields));
    } catch (Exception $ex) {
        echo json_encode(array('error' => 'DB error: ' . $ex->getMessage()));
    }
    exit;
}

echo json_encode(array('error' => 'Unknown action'));
