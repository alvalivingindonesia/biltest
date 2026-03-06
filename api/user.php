<?php
/**
 * Build in Lombok — User API
 * Handles: registration, email verification, login, favorites, claims, submissions
 *
 * Place at: /api/user.php
 * Requires: PHP 7.4+, MySQL, mail() function on shared hosting
 *
 * Endpoints (all via POST except where noted):
 *   POST /api/user.php?action=register
 *   GET  /api/user.php?action=verify&token=xxx
 *   POST /api/user.php?action=login
 *   POST /api/user.php?action=logout
 *   GET  /api/user.php?action=me           — get current user
 *   POST /api/user.php?action=update_profile
 *   POST /api/user.php?action=forgot_password
 *   POST /api/user.php?action=reset_password
 *
 *   GET  /api/user.php?action=favorites     — list user favorites
 *   POST /api/user.php?action=toggle_fav    — add/remove favorite
 *
 *   POST /api/user.php?action=claim_listing
 *   GET  /api/user.php?action=my_claims
 *
 *   POST /api/user.php?action=submit_listing
 *   GET  /api/user.php?action=my_submissions
 */

session_start();
require_once('/home/rovin629/config/biltest_config.php');

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Credentials: true');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ─── DB ──────────────────────────────────────────────────────────
function get_db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO('mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4', DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }
    return $pdo;
}

function json_out($data, int $status = 200): void {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function json_error(int $status, string $message): void {
    json_out(['error' => $message], $status);
}

function get_current_user_id(): ?int {
    return $_SESSION['user_id'] ?? null;
}

function require_auth(): int {
    $uid = get_current_user_id();
    if (!$uid) json_error(401, 'Please log in to continue.');
    return $uid;
}

function get_post_data(): array {
    $ct = $_SERVER['CONTENT_TYPE'] ?? '';
    if (stripos($ct, 'application/json') !== false) {
        return json_decode(file_get_contents('php://input'), true) ?: [];
    }
    return $_POST;
}

function generate_token(int $length = 32): string {
    return bin2hex(random_bytes($length));
}

function send_verification_email(string $email, string $display_name, string $token): bool {
    $site_url = 'https://biltest.roving-i.com.au';
    $verify_url = $site_url . '/api/user.php?action=verify&token=' . urlencode($token);

    $subject = 'Verify your Build in Lombok account';
    $body = "Hi {$display_name},\n\n"
          . "Welcome to Build in Lombok! Please verify your email address by clicking the link below:\n\n"
          . "{$verify_url}\n\n"
          . "This link expires in 24 hours.\n\n"
          . "If you didn't create this account, you can safely ignore this email.\n\n"
          . "— Build in Lombok Team";

    $headers = "From: noreply@roving-i.com.au\r\n"
             . "Reply-To: noreply@roving-i.com.au\r\n"
             . "Content-Type: text/plain; charset=UTF-8\r\n";

    return @mail($email, $subject, $body, $headers);
}

function send_reset_email(string $email, string $display_name, string $token): bool {
    $site_url = 'https://biltest.roving-i.com.au';
    $reset_url = $site_url . '/#reset-password?token=' . urlencode($token);

    $subject = 'Reset your Build in Lombok password';
    $body = "Hi {$display_name},\n\n"
          . "You requested a password reset. Click the link below to set a new password:\n\n"
          . "{$reset_url}\n\n"
          . "This link expires in 1 hour.\n\n"
          . "If you didn't request this, you can safely ignore this email.\n\n"
          . "— Build in Lombok Team";

    $headers = "From: noreply@roving-i.com.au\r\n"
             . "Reply-To: noreply@roving-i.com.au\r\n"
             . "Content-Type: text/plain; charset=UTF-8\r\n";

    return @mail($email, $subject, $body, $headers);
}

// ─── ROUTING ─────────────────────────────────────────────────────
$action = $_GET['action'] ?? '';

switch ($action) {
    case 'register':       handle_register(); break;
    case 'verify':         handle_verify(); break;
    case 'login':          handle_login(); break;
    case 'logout':         handle_logout(); break;
    case 'me':             handle_me(); break;
    case 'update_profile': handle_update_profile(); break;
    case 'forgot_password': handle_forgot_password(); break;
    case 'reset_password': handle_reset_password(); break;
    case 'favorites':      handle_favorites(); break;
    case 'toggle_fav':     handle_toggle_fav(); break;
    case 'claim_listing':  handle_claim_listing(); break;
    case 'my_claims':      handle_my_claims(); break;
    case 'submit_listing': handle_submit_listing(); break;
    case 'my_submissions': handle_my_submissions(); break;
    // Social login
    case 'social_login':   handle_social_login(); break;
    // Agent profile
    case 'register_agent': handle_register_agent(); break;
    case 'update_agent':   handle_update_agent(); break;
    case 'my_agent':       handle_my_agent(); break;
    // Listing management
    case 'create_listing': handle_create_listing(); break;
    case 'update_listing': handle_update_listing(); break;
    case 'my_listings':    handle_my_listings(); break;
    case 'delete_listing': handle_delete_listing(); break;
    // Image management
    case 'upload_image':   handle_upload_image(); break;
    case 'delete_image':   handle_delete_image(); break;
    case 'set_primary_image': handle_set_primary_image(); break;
    // Review check
    case 'check_reviews':  handle_check_reviews(); break;
    default:               json_error(400, 'Unknown action');
}


// =================================================================
// AUTH ENDPOINTS
// =================================================================

function handle_register(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_error(405, 'POST required');
    $data = get_post_data();

    $email = trim(strtolower($data['email'] ?? ''));
    $password = $data['password'] ?? '';
    $display_name = trim($data['display_name'] ?? '');

    // Validation
    if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) json_error(400, 'Valid email required.');
    if (strlen($password) < 8) json_error(400, 'Password must be at least 8 characters.');
    if (!$display_name || strlen($display_name) < 2) json_error(400, 'Display name required (min 2 characters).');

    $db = get_db();

    // Check duplicate
    $exists = $db->prepare("SELECT id FROM users WHERE email = ?");
    $exists->execute([$email]);
    if ($exists->fetch()) json_error(409, 'An account with this email already exists.');

    // Create user
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $token = generate_token();
    $expires = date('Y-m-d H:i:s', strtotime('+24 hours'));

    $db->prepare(
        "INSERT INTO users (email, password_hash, display_name, verify_token, verify_expires)
         VALUES (?, ?, ?, ?, ?)"
    )->execute([$email, $hash, $display_name, $token, $expires]);

    $user_id = (int)$db->lastInsertId();

    // Send verification email
    $email_sent = send_verification_email($email, $display_name, $token);

    json_out([
        'success' => true,
        'message' => $email_sent
            ? 'Account created! Check your email to verify your address.'
            : 'Account created! We had trouble sending the verification email — please contact us.',
        'user_id' => $user_id,
    ], 201);
}


function handle_verify(): void {
    $token = $_GET['token'] ?? '';
    if (!$token) json_error(400, 'Verification token required.');

    $db = get_db();
    $stmt = $db->prepare("SELECT id, email, display_name, is_verified, verify_expires FROM users WHERE verify_token = ?");
    $stmt->execute([$token]);
    $user = $stmt->fetch();

    if (!$user) {
        // Redirect to site with error
        header('Location: https://biltest.roving-i.com.au/#verify-result?status=invalid');
        exit;
    }

    if ($user['is_verified']) {
        header('Location: https://biltest.roving-i.com.au/#verify-result?status=already');
        exit;
    }

    if (strtotime($user['verify_expires']) < time()) {
        header('Location: https://biltest.roving-i.com.au/#verify-result?status=expired');
        exit;
    }

    // Verify the user
    $db->prepare("UPDATE users SET is_verified = 1, verify_token = NULL, verify_expires = NULL WHERE id = ?")->execute([$user['id']]);

    header('Location: https://biltest.roving-i.com.au/#verify-result?status=success');
    exit;
}


function handle_login(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_error(405, 'POST required');
    $data = get_post_data();

    $email = trim(strtolower($data['email'] ?? ''));
    $password = $data['password'] ?? '';

    if (!$email || !$password) json_error(400, 'Email and password required.');

    $db = get_db();
    $stmt = $db->prepare("SELECT id, email, password_hash, display_name, is_verified, is_active, role FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        json_error(401, 'Invalid email or password.');
    }

    if (!$user['is_active']) json_error(403, 'This account has been deactivated.');

    if (!$user['is_verified']) {
        json_error(403, 'Please verify your email address first. Check your inbox for the verification link.');
    }

    // Set session
    $_SESSION['user_id'] = (int)$user['id'];
    $_SESSION['user_email'] = $user['email'];
    $_SESSION['user_role'] = $user['role'];

    // Update last login
    $db->prepare("UPDATE users SET last_login_at = NOW() WHERE id = ?")->execute([$user['id']]);

    json_out([
        'success' => true,
        'user' => [
            'id' => (int)$user['id'],
            'email' => $user['email'],
            'display_name' => $user['display_name'],
            'role' => $user['role'],
        ],
    ]);
}


function handle_logout(): void {
    session_destroy();
    json_out(['success' => true, 'message' => 'Logged out.']);
}


function handle_me(): void {
    $uid = get_current_user_id();
    if (!$uid) json_out(['user' => null]);

    $db = get_db();
    $stmt = $db->prepare("SELECT id, email, display_name, phone, whatsapp_number, role, created_at FROM users WHERE id = ? AND is_active = 1");
    $stmt->execute([$uid]);
    $user = $stmt->fetch();

    if (!$user) {
        session_destroy();
        json_out(['user' => null]);
    }

    // Check if user owns any providers
    $owns = $db->prepare("SELECT provider_id FROM provider_owners WHERE user_id = ?");
    $owns->execute([$uid]);
    $user['owned_providers'] = $owns->fetchAll(PDO::FETCH_COLUMN);

    json_out(['user' => $user]);
}


function handle_update_profile(): void {
    $uid = require_auth();
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_error(405, 'POST required');
    $data = get_post_data();

    $display_name = trim($data['display_name'] ?? '');
    $phone = trim($data['phone'] ?? '');
    $whatsapp = trim($data['whatsapp_number'] ?? '');

    if (!$display_name || strlen($display_name) < 2) json_error(400, 'Display name required.');

    $db = get_db();
    $db->prepare("UPDATE users SET display_name=?, phone=?, whatsapp_number=? WHERE id=?")
       ->execute([$display_name, $phone ?: null, $whatsapp ?: null, $uid]);

    json_out(['success' => true, 'message' => 'Profile updated.']);
}


function handle_forgot_password(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_error(405, 'POST required');
    $data = get_post_data();
    $email = trim(strtolower($data['email'] ?? ''));

    if (!$email) json_error(400, 'Email required.');

    $db = get_db();
    $stmt = $db->prepare("SELECT id, email, display_name FROM users WHERE email = ? AND is_active = 1");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    // Always return success to prevent email enumeration
    if ($user) {
        $token = generate_token();
        $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));
        $db->prepare("UPDATE users SET reset_token=?, reset_expires=? WHERE id=?")->execute([$token, $expires, $user['id']]);
        send_reset_email($user['email'], $user['display_name'], $token);
    }

    json_out(['success' => true, 'message' => 'If that email exists, a reset link has been sent.']);
}


function handle_reset_password(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_error(405, 'POST required');
    $data = get_post_data();

    $token = $data['token'] ?? '';
    $password = $data['password'] ?? '';

    if (!$token) json_error(400, 'Reset token required.');
    if (strlen($password) < 8) json_error(400, 'Password must be at least 8 characters.');

    $db = get_db();
    $stmt = $db->prepare("SELECT id FROM users WHERE reset_token = ? AND reset_expires > NOW()");
    $stmt->execute([$token]);
    $user = $stmt->fetch();

    if (!$user) json_error(400, 'Invalid or expired reset link.');

    $hash = password_hash($password, PASSWORD_DEFAULT);
    $db->prepare("UPDATE users SET password_hash=?, reset_token=NULL, reset_expires=NULL WHERE id=?")
       ->execute([$hash, $user['id']]);

    json_out(['success' => true, 'message' => 'Password reset successfully. You can now log in.']);
}


// =================================================================
// FAVORITES
// =================================================================

function handle_favorites(): void {
    $uid = require_auth();
    $db = get_db();

    $stmt = $db->prepare(
        "SELECT uf.entity_type, uf.entity_id, uf.created_at,
                CASE uf.entity_type
                  WHEN 'provider' THEN (SELECT name FROM providers WHERE id = uf.entity_id)
                  WHEN 'developer' THEN (SELECT name FROM developers WHERE id = uf.entity_id)
                  WHEN 'project' THEN (SELECT name FROM projects WHERE id = uf.entity_id)
                END AS entity_name,
                CASE uf.entity_type
                  WHEN 'provider' THEN (SELECT slug FROM providers WHERE id = uf.entity_id)
                  WHEN 'developer' THEN (SELECT slug FROM developers WHERE id = uf.entity_id)
                  WHEN 'project' THEN (SELECT slug FROM projects WHERE id = uf.entity_id)
                END AS entity_slug
         FROM user_favorites uf
         WHERE uf.user_id = ?
         ORDER BY uf.created_at DESC"
    );
    $stmt->execute([$uid]);
    json_out(['data' => $stmt->fetchAll()]);
}


function handle_toggle_fav(): void {
    $uid = require_auth();
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_error(405, 'POST required');
    $data = get_post_data();

    $entity_type = $data['entity_type'] ?? '';
    $entity_id = (int)($data['entity_id'] ?? 0);

    if (!in_array($entity_type, ['provider', 'developer', 'project'])) json_error(400, 'Invalid entity type.');
    if (!$entity_id) json_error(400, 'Entity ID required.');

    $db = get_db();

    // Check if already favorited
    $check = $db->prepare("SELECT 1 FROM user_favorites WHERE user_id=? AND entity_type=? AND entity_id=?");
    $check->execute([$uid, $entity_type, $entity_id]);

    if ($check->fetch()) {
        // Remove
        $db->prepare("DELETE FROM user_favorites WHERE user_id=? AND entity_type=? AND entity_id=?")->execute([$uid, $entity_type, $entity_id]);
        json_out(['success' => true, 'favorited' => false, 'message' => 'Removed from favorites.']);
    } else {
        // Add
        $db->prepare("INSERT INTO user_favorites (user_id, entity_type, entity_id) VALUES (?, ?, ?)")->execute([$uid, $entity_type, $entity_id]);
        json_out(['success' => true, 'favorited' => true, 'message' => 'Added to favorites.']);
    }
}


// =================================================================
// CLAIMS
// =================================================================

function handle_claim_listing(): void {
    $uid = require_auth();
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_error(405, 'POST required');
    $data = get_post_data();

    $provider_id = (int)($data['provider_id'] ?? 0);
    $business_role = trim($data['business_role'] ?? '');
    $proof_desc = trim($data['proof_description'] ?? '');
    $contact_phone = trim($data['contact_phone'] ?? '');

    if (!$provider_id) json_error(400, 'Provider ID required.');
    if (!$business_role) json_error(400, 'Your role at the business is required.');
    if (!$proof_desc) json_error(400, 'Please describe how you can prove ownership.');

    $db = get_db();

    // Check provider exists
    $prov = $db->prepare("SELECT id, name FROM providers WHERE id = ?");
    $prov->execute([$provider_id]);
    if (!$prov->fetch()) json_error(404, 'Provider not found.');

    // Check for existing pending claim
    $existing = $db->prepare("SELECT id FROM claim_requests WHERE user_id=? AND provider_id=? AND status='pending'");
    $existing->execute([$uid, $provider_id]);
    if ($existing->fetch()) json_error(409, 'You already have a pending claim for this listing.');

    // Check if already owned
    $owned = $db->prepare("SELECT 1 FROM provider_owners WHERE provider_id=? AND user_id=?");
    $owned->execute([$provider_id, $uid]);
    if ($owned->fetch()) json_error(409, 'You already own this listing.');

    $db->prepare(
        "INSERT INTO claim_requests (user_id, provider_id, business_role, proof_description, contact_phone)
         VALUES (?, ?, ?, ?, ?)"
    )->execute([$uid, $provider_id, $business_role, $proof_desc, $contact_phone ?: null]);

    json_out(['success' => true, 'message' => 'Claim submitted! Our team will review it and get back to you.'], 201);
}


function handle_my_claims(): void {
    $uid = require_auth();
    $db = get_db();

    $stmt = $db->prepare(
        "SELECT cr.id, cr.provider_id, p.name AS provider_name, cr.business_role,
                cr.status, cr.admin_notes, cr.created_at, cr.reviewed_at
         FROM claim_requests cr
         JOIN providers p ON p.id = cr.provider_id
         WHERE cr.user_id = ?
         ORDER BY cr.created_at DESC"
    );
    $stmt->execute([$uid]);
    json_out(['data' => $stmt->fetchAll()]);
}


// =================================================================
// SUBMISSIONS
// =================================================================

function handle_submit_listing(): void {
    $uid = require_auth();
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_error(405, 'POST required');
    $data = get_post_data();

    $name = trim($data['business_name'] ?? '');
    $group_key = $data['group_key'] ?? '';
    $category_keys = $data['category_keys'] ?? '';
    $area_key = $data['area_key'] ?? '';
    $short_desc = trim($data['short_description'] ?? '');

    if (!$name) json_error(400, 'Business name required.');
    if (!$group_key) json_error(400, 'Business group required.');
    if (!$category_keys) json_error(400, 'At least one specialty required.');
    if (!$area_key) json_error(400, 'Area required.');
    if (!$short_desc) json_error(400, 'Short description required.');

    $db = get_db();
    $db->prepare(
        "INSERT INTO listing_submissions (user_id, business_name, group_key, category_keys, area_key,
         short_description, address, phone, whatsapp_number, website_url, google_maps_url, languages)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    )->execute([
        $uid, $name, $group_key,
        is_array($category_keys) ? implode(',', $category_keys) : $category_keys,
        $area_key, $short_desc,
        trim($data['address'] ?? '') ?: null,
        trim($data['phone'] ?? '') ?: null,
        trim($data['whatsapp_number'] ?? '') ?: null,
        trim($data['website_url'] ?? '') ?: null,
        trim($data['google_maps_url'] ?? '') ?: null,
        trim($data['languages'] ?? 'Bahasa only'),
    ]);

    json_out(['success' => true, 'message' => 'Listing submitted for review! We\'ll check it and add it to the directory.'], 201);
}


function handle_my_submissions(): void {
    $uid = require_auth();
    $db = get_db();

    $stmt = $db->prepare(
        "SELECT id, business_name, group_key, category_keys, area_key,
                status, admin_notes, created_at, reviewed_at
         FROM listing_submissions
         WHERE user_id = ?
         ORDER BY created_at DESC"
    );
    $stmt->execute([$uid]);
    json_out(['data' => $stmt->fetchAll()]);
}

// =================================================================
// HELPERS FOR AGENT / LISTING
// =================================================================

/**
 * Generate a URL-safe slug from a name, ensuring uniqueness in a given table.
 */
function slug_from_name(string $name, string $table, string $col = 'slug'): string {
    $base = strtolower(trim($name));
    $base = preg_replace('/[^a-z0-9]+/', '-', $base);
    $base = trim($base, '-');
    if ($base === '') $base = 'item';

    $db = get_db();
    $slug = $base;
    $i = 0;
    while (true) {
        $check = $db->prepare("SELECT 1 FROM `{$table}` WHERE `{$col}` = ?");
        $check->execute([$slug]);
        if (!$check->fetch()) break;
        $i++;
        $slug = $base . '-' . $i;
    }
    return $slug;
}

/**
 * Return the agent record for a given user_id, or null.
 */
function get_agent_for_user(int $user_id): ?array {
    $db = get_db();
    $stmt = $db->prepare("SELECT * FROM agents WHERE user_id = ? LIMIT 1");
    $stmt->execute([$user_id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * Require auth + agent profile. Returns agent_id.
 */
function require_agent(): int {
    $uid = require_auth();
    $agent = get_agent_for_user($uid);
    if (!$agent) json_error(403, 'You need an agent profile to do this.');
    return (int)$agent['id'];
}


// =================================================================
// SOCIAL LOGIN
// =================================================================

function handle_social_login(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_error(405, 'POST required');
    $data = get_post_data();

    $provider    = trim($data['provider'] ?? '');
    $token       = trim($data['token'] ?? '');
    $email       = trim(strtolower($data['email'] ?? ''));
    $name        = trim($data['name'] ?? '');
    $provider_id = trim($data['provider_id'] ?? '');
    $avatar_url  = trim($data['avatar_url'] ?? '');

    if (!in_array($provider, ['google', 'facebook', 'instagram'])) json_error(400, 'Invalid provider.');
    if (!$provider_id) json_error(400, 'provider_id required.');
    if (!$name) json_error(400, 'name required.');

    $id_col = $provider . '_id'; // google_id, facebook_id, instagram_id

    $db = get_db();

    // 1. Check by social ID
    $stmt = $db->prepare("SELECT * FROM users WHERE `{$id_col}` = ? LIMIT 1");
    $stmt->execute([$provider_id]);
    $user = $stmt->fetch();

    if (!$user && $email) {
        // 2. Check by email — link social account to existing user
        $stmt = $db->prepare("SELECT * FROM users WHERE email = ? AND is_active = 1 LIMIT 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user) {
            // Link the social account
            $db->prepare("UPDATE users SET `{$id_col}` = ?, auth_provider = ?, avatar_url = ? WHERE id = ?")
               ->execute([$provider_id, $provider, $avatar_url ?: null, $user['id']]);
        }
    }

    if (!$user) {
        // 3. Create new user
        $db->prepare(
            "INSERT INTO users (email, password_hash, display_name, is_verified, is_active, auth_provider, `{$id_col}`, avatar_url)
             VALUES (?, NULL, ?, 1, 1, ?, ?, ?)"
        )->execute([
            $email ?: null,
            $name,
            $provider,
            $provider_id,
            $avatar_url ?: null,
        ]);
        $new_id = (int)$db->lastInsertId();
        $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$new_id]);
        $user = $stmt->fetch();
    }

    if (!$user['is_active']) json_error(403, 'This account has been deactivated.');

    // Start session
    $_SESSION['user_id']    = (int)$user['id'];
    $_SESSION['user_email'] = $user['email'] ?? '';
    $_SESSION['user_role']  = $user['role'] ?? 'user';

    $db->prepare("UPDATE users SET last_login_at = NOW() WHERE id = ?")->execute([$user['id']]);

    json_out([
        'success' => true,
        'user' => [
            'id'           => (int)$user['id'],
            'email'        => $user['email'],
            'display_name' => $user['display_name'],
            'role'         => $user['role'] ?? 'user',
            'avatar_url'   => $user['avatar_url'] ?? null,
        ],
    ]);
}


// =================================================================
// AGENT PROFILE
// =================================================================

function handle_register_agent(): void {
    $uid = require_auth();
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_error(405, 'POST required');

    // Ensure they don't already have a profile
    if (get_agent_for_user($uid)) json_error(409, 'You already have an agent profile.');

    $data = get_post_data();
    $display_name = trim($data['display_name'] ?? '');
    $area_key     = trim($data['area_key'] ?? '');

    if (!$display_name || strlen($display_name) < 2) json_error(400, 'display_name required (min 2 chars).');

    $slug = slug_from_name($display_name, 'agents');

    $db = get_db();
    $db->prepare(
        "INSERT INTO agents (user_id, slug, display_name, agency_name, bio, phone, whatsapp_number,
                             email, website_url, areas_served, languages, google_maps_url, is_active, is_verified)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, 0)"
    )->execute([
        $uid,
        $slug,
        $display_name,
        trim($data['agency_name'] ?? '') ?: null,
        trim($data['bio'] ?? '') ?: null,
        trim($data['phone'] ?? '') ?: null,
        trim($data['whatsapp_number'] ?? '') ?: null,
        trim($data['email'] ?? '') ?: null,
        trim($data['website_url'] ?? '') ?: null,
        trim($data['areas_served'] ?? '') ?: null,
        trim($data['languages'] ?? '') ?: null,
        trim($data['google_maps_url'] ?? '') ?: null,
    ]);

    $agent_id = (int)$db->lastInsertId();
    $stmt = $db->prepare("SELECT * FROM agents WHERE id = ?");
    $stmt->execute([$agent_id]);

    json_out(['success' => true, 'agent' => $stmt->fetch()], 201);
}

function handle_update_agent(): void {
    $uid = require_auth();
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_error(405, 'POST required');

    $agent = get_agent_for_user($uid);
    if (!$agent) json_error(404, 'No agent profile found for your account.');

    $data = get_post_data();
    $display_name = trim($data['display_name'] ?? $agent['display_name']);
    if (!$display_name || strlen($display_name) < 2) json_error(400, 'display_name required (min 2 chars).');

    $db = get_db();
    $db->prepare(
        "UPDATE agents SET
            display_name = ?, agency_name = ?, bio = ?, phone = ?, whatsapp_number = ?,
            email = ?, website_url = ?, areas_served = ?, languages = ?, google_maps_url = ?
         WHERE id = ?"
    )->execute([
        $display_name,
        trim($data['agency_name'] ?? '') ?: null,
        trim($data['bio'] ?? '') ?: null,
        trim($data['phone'] ?? '') ?: null,
        trim($data['whatsapp_number'] ?? '') ?: null,
        trim($data['email'] ?? '') ?: null,
        trim($data['website_url'] ?? '') ?: null,
        trim($data['areas_served'] ?? '') ?: null,
        trim($data['languages'] ?? '') ?: null,
        trim($data['google_maps_url'] ?? '') ?: null,
        $agent['id'],
    ]);

    json_out(['success' => true, 'message' => 'Agent profile updated.']);
}

function handle_my_agent(): void {
    $uid = require_auth();
    $agent = get_agent_for_user($uid);
    json_out(['agent' => $agent]);
}


// =================================================================
// LISTING MANAGEMENT
// =================================================================

function handle_create_listing(): void {
    $uid      = require_auth();
    $agent_id = require_agent();
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_error(405, 'POST required');

    $data = get_post_data();

    $listing_type_key = trim($data['listing_type_key'] ?? '');
    $title            = trim($data['title'] ?? '');
    $short_desc       = trim($data['short_description'] ?? '');
    $area_key         = trim($data['area_key'] ?? '');

    if (!$listing_type_key) json_error(400, 'listing_type_key required.');
    if (!$title || strlen($title) < 3) json_error(400, 'title required (min 3 chars).');
    if (!$short_desc) json_error(400, 'short_description required.');
    if (!$area_key) json_error(400, 'area_key required.');

    $slug = slug_from_name($title, 'listings');

    $db = get_db();
    $db->prepare(
        "INSERT INTO listings (agent_id, slug, listing_type_key, title, short_description, description,
                               area_key, price_usd, price_idr, land_size_sqm, build_size_sqm,
                               certificate_type_key, google_maps_url, address, status, is_approved, is_featured)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'draft', 0, 0)"
    )->execute([
        $agent_id,
        $slug,
        $listing_type_key,
        $title,
        $short_desc,
        trim($data['description'] ?? '') ?: null,
        $area_key,
        !empty($data['price_usd']) ? (int)$data['price_usd'] : null,
        !empty($data['price_idr']) ? (int)$data['price_idr'] : null,
        !empty($data['land_size_sqm']) ? (float)$data['land_size_sqm'] : null,
        !empty($data['build_size_sqm']) ? (float)$data['build_size_sqm'] : null,
        trim($data['certificate_type_key'] ?? '') ?: null,
        trim($data['google_maps_url'] ?? '') ?: null,
        trim($data['address'] ?? '') ?: null,
    ]);

    $listing_id = (int)$db->lastInsertId();
    $stmt = $db->prepare("SELECT * FROM listings WHERE id = ?");
    $stmt->execute([$listing_id]);

    json_out(['success' => true, 'listing' => $stmt->fetch()], 201);
}

function handle_update_listing(): void {
    $uid      = require_auth();
    $agent_id = require_agent();
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_error(405, 'POST required');

    $data       = get_post_data();
    $listing_id = (int)($data['listing_id'] ?? 0);
    if (!$listing_id) json_error(400, 'listing_id required.');

    $db = get_db();
    // Ownership check
    $stmt = $db->prepare("SELECT * FROM listings WHERE id = ? AND agent_id = ?");
    $stmt->execute([$listing_id, $agent_id]);
    $listing = $stmt->fetch();
    if (!$listing) json_error(404, 'Listing not found or you do not own it.');

    $title      = trim($data['title'] ?? $listing['title']);
    $short_desc = trim($data['short_description'] ?? $listing['short_description']);
    $area_key   = trim($data['area_key'] ?? $listing['area_key']);

    if (strlen($title) < 3) json_error(400, 'title too short.');

    $db->prepare(
        "UPDATE listings SET
            listing_type_key = ?, title = ?, short_description = ?, description = ?,
            area_key = ?, price_usd = ?, price_idr = ?, land_size_sqm = ?, build_size_sqm = ?,
            certificate_type_key = ?, google_maps_url = ?, address = ?
         WHERE id = ?"
    )->execute([
        trim($data['listing_type_key'] ?? $listing['listing_type_key']),
        $title,
        $short_desc,
        trim($data['description'] ?? '') ?: ($listing['description'] ?? null),
        $area_key,
        !empty($data['price_usd']) ? (int)$data['price_usd'] : ($listing['price_usd'] ?? null),
        !empty($data['price_idr']) ? (int)$data['price_idr'] : ($listing['price_idr'] ?? null),
        !empty($data['land_size_sqm']) ? (float)$data['land_size_sqm'] : ($listing['land_size_sqm'] ?? null),
        !empty($data['build_size_sqm']) ? (float)$data['build_size_sqm'] : ($listing['build_size_sqm'] ?? null),
        trim($data['certificate_type_key'] ?? '') ?: ($listing['certificate_type_key'] ?? null),
        trim($data['google_maps_url'] ?? '') ?: ($listing['google_maps_url'] ?? null),
        trim($data['address'] ?? '') ?: ($listing['address'] ?? null),
        $listing_id,
    ]);

    json_out(['success' => true, 'message' => 'Listing updated.']);
}

function handle_my_listings(): void {
    $uid      = require_auth();
    $agent_id = require_agent();

    $db = get_db();
    $stmt = $db->prepare(
        "SELECT l.*, lt.label AS listing_type_label, a.label AS area_label,
                (SELECT COUNT(*) FROM listing_images li WHERE li.listing_id = l.id) AS image_count
         FROM listings l
         LEFT JOIN listing_types lt ON lt.`key` = l.listing_type_key
         LEFT JOIN areas a ON a.`key` = l.area_key
         WHERE l.agent_id = ?
         ORDER BY l.created_at DESC"
    );
    $stmt->execute([$agent_id]);
    json_out(['data' => $stmt->fetchAll()]);
}

function handle_delete_listing(): void {
    $uid      = require_auth();
    $agent_id = require_agent();
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_error(405, 'POST required');

    $data       = get_post_data();
    $listing_id = (int)($data['listing_id'] ?? 0);
    if (!$listing_id) json_error(400, 'listing_id required.');

    $db = get_db();
    $stmt = $db->prepare("SELECT id FROM listings WHERE id = ? AND agent_id = ?");
    $stmt->execute([$listing_id, $agent_id]);
    if (!$stmt->fetch()) json_error(404, 'Listing not found or you do not own it.');

    // Soft-delete
    $db->prepare("UPDATE listings SET status = 'expired' WHERE id = ?")->execute([$listing_id]);

    json_out(['success' => true, 'message' => 'Listing deleted.']);
}


// =================================================================
// IMAGE UPLOAD
// =================================================================

function handle_upload_image(): void {
    $uid      = require_auth();
    $agent_id = require_agent();
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_error(405, 'POST required');

    $listing_id = (int)($_POST['listing_id'] ?? 0);
    if (!$listing_id) json_error(400, 'listing_id required.');

    // Ownership check
    $db = get_db();
    $stmt = $db->prepare("SELECT id FROM listings WHERE id = ? AND agent_id = ?");
    $stmt->execute([$listing_id, $agent_id]);
    if (!$stmt->fetch()) json_error(404, 'Listing not found or you do not own it.');

    // Max 10 images per listing
    $count = $db->prepare("SELECT COUNT(*) FROM listing_images WHERE listing_id = ?");
    $count->execute([$listing_id]);
    if ((int)$count->fetchColumn() >= 10) json_error(400, 'Maximum 10 images per listing reached.');

    // File validation
    if (empty($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
        json_error(400, 'No file uploaded or upload error.');
    }

    $file    = $_FILES['image'];
    $maxSize = 5 * 1024 * 1024; // 5MB
    if ($file['size'] > $maxSize) json_error(400, 'File too large. Maximum 5MB.');

    // Validate MIME type
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime  = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    $allowed_mimes = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    if (!array_key_exists($mime, $allowed_mimes)) {
        json_error(400, 'Only JPEG, PNG, and WebP images are allowed.');
    }

    $ext      = $allowed_mimes[$mime];
    $filename = time() . '_' . bin2hex(random_bytes(6)) . '.' . $ext;

    // Build save path
    $upload_dir = '/home/rovin629/subdomain/biltest.roving-i.com.au/uploads/listings/' . $listing_id . '/';
    if (!is_dir($upload_dir)) {
        if (!mkdir($upload_dir, 0755, true)) {
            json_error(500, 'Could not create upload directory.');
        }
    }

    $dest = $upload_dir . $filename;
    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        json_error(500, 'Failed to save uploaded file.');
    }

    $url = '/uploads/listings/' . $listing_id . '/' . $filename;

    // Determine if first image (make it primary automatically)
    $is_first = $db->prepare("SELECT COUNT(*) FROM listing_images WHERE listing_id = ?");
    $is_first->execute([$listing_id]);
    $is_primary = (int)$is_first->fetchColumn() === 0 ? 1 : 0;

    $db->prepare(
        "INSERT INTO listing_images (listing_id, url, alt_text, is_primary, sort_order)
         VALUES (?, ?, '', ?, (SELECT COALESCE(MAX(sort_order), 0) + 1 FROM listing_images li2 WHERE li2.listing_id = ?))"
    )->execute([$listing_id, $url, $is_primary, $listing_id]);

    $image_id = (int)$db->lastInsertId();

    json_out(['success' => true, 'image' => ['id' => $image_id, 'url' => $url, 'is_primary' => (bool)$is_primary]], 201);
}

function handle_delete_image(): void {
    $uid      = require_auth();
    $agent_id = require_agent();
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_error(405, 'POST required');

    $data     = get_post_data();
    $image_id = (int)($data['image_id'] ?? 0);
    if (!$image_id) json_error(400, 'image_id required.');

    $db = get_db();

    // Ownership check via listings join
    $stmt = $db->prepare(
        "SELECT li.url, li.listing_id FROM listing_images li
         JOIN listings l ON l.id = li.listing_id
         WHERE li.id = ? AND l.agent_id = ?"
    );
    $stmt->execute([$image_id, $agent_id]);
    $img = $stmt->fetch();
    if (!$img) json_error(404, 'Image not found or you do not own this listing.');

    // Delete file from disk
    $file_path = '/home/rovin629/subdomain/biltest.roving-i.com.au' . $img['url'];
    if (is_file($file_path)) @unlink($file_path);

    $db->prepare("DELETE FROM listing_images WHERE id = ?")->execute([$image_id]);

    // If deleted image was primary, auto-assign next available
    $next = $db->prepare("SELECT id FROM listing_images WHERE listing_id = ? ORDER BY sort_order ASC LIMIT 1");
    $next->execute([$img['listing_id']]);
    $next_img = $next->fetch();
    if ($next_img) {
        $db->prepare("UPDATE listing_images SET is_primary = 1 WHERE id = ?")->execute([$next_img['id']]);
    }

    json_out(['success' => true, 'message' => 'Image deleted.']);
}

function handle_set_primary_image(): void {
    $uid      = require_auth();
    $agent_id = require_agent();
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_error(405, 'POST required');

    $data     = get_post_data();
    $image_id = (int)($data['image_id'] ?? 0);
    if (!$image_id) json_error(400, 'image_id required.');

    $db = get_db();

    // Ownership check
    $stmt = $db->prepare(
        "SELECT li.listing_id FROM listing_images li
         JOIN listings l ON l.id = li.listing_id
         WHERE li.id = ? AND l.agent_id = ?"
    );
    $stmt->execute([$image_id, $agent_id]);
    $img = $stmt->fetch();
    if (!$img) json_error(404, 'Image not found or you do not own this listing.');

    $listing_id = $img['listing_id'];

    // Unset all primaries for this listing, then set the chosen one
    $db->prepare("UPDATE listing_images SET is_primary = 0 WHERE listing_id = ?")->execute([$listing_id]);
    $db->prepare("UPDATE listing_images SET is_primary = 1 WHERE id = ?")->execute([$image_id]);

    json_out(['success' => true, 'message' => 'Primary image updated.']);
}


// =================================================================
// REVIEW CHECK
// =================================================================

function handle_check_reviews(): void {
    $uid = require_auth();
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_error(405, 'POST required');

    $data        = get_post_data();
    $entity_type = trim($data['entity_type'] ?? '');
    $entity_id   = (int)($data['entity_id'] ?? 0);

    $allowed = ['provider', 'developer', 'agent'];
    if (!in_array($entity_type, $allowed)) json_error(400, 'entity_type must be one of: ' . implode(', ', $allowed));
    if (!$entity_id) json_error(400, 'entity_id required.');

    // Verify the requestor is allowed: must be the agent or provider owner
    $uid_check = require_auth();

    $db = get_db();

    // Get entity
    $table   = $entity_type === 'agent' ? 'agents' : $entity_type . 's';
    $stmt    = $db->prepare("SELECT id, google_maps_url, google_rating, google_review_count, google_place_id FROM `{$table}` WHERE id = ? LIMIT 1");
    $stmt->execute([$entity_id]);
    $entity = $stmt->fetch();
    if (!$entity) json_error(404, ucfirst($entity_type) . ' not found.');

    // Permission check
    $allowed_to_check = false;
    if ($entity_type === 'agent') {
        $agent = get_agent_for_user($uid_check);
        if ($agent && (int)$agent['id'] === $entity_id) $allowed_to_check = true;
    } elseif ($entity_type === 'provider') {
        $own = $db->prepare("SELECT 1 FROM provider_owners WHERE provider_id = ? AND user_id = ?");
        $own->execute([$entity_id, $uid_check]);
        if ($own->fetch()) $allowed_to_check = true;
    }
    // Admin bypass
    if (($_SESSION['user_role'] ?? '') === 'admin') $allowed_to_check = true;

    if (!$allowed_to_check) json_error(403, 'You are not authorised to request review updates for this entity.');

    // Log the request
    $db->prepare(
        "INSERT INTO review_update_log (entity_type, entity_id, requested_by, status, created_at)
         VALUES (?, ?, ?, 'pending', NOW())"
    )->execute([$entity_type, $entity_id, $uid_check]);

    // Try Google Places API if key and place_id are available
    $api_result = null;
    if (!empty($entity['google_place_id']) && defined('GOOGLE_PLACES_API_KEY') && GOOGLE_PLACES_API_KEY) {
        $url = 'https://maps.googleapis.com/maps/api/place/details/json?place_id='
             . urlencode($entity['google_place_id'])
             . '&fields=rating,user_ratings_total&key='
             . urlencode(GOOGLE_PLACES_API_KEY);

        $ctx      = stream_context_create(['http' => ['timeout' => 10]]);
        $response = @file_get_contents($url, false, $ctx);
        if ($response) {
            $parsed = json_decode($response, true);
            if (isset($parsed['result'])) {
                $rating = $parsed['result']['rating'] ?? null;
                $count  = $parsed['result']['user_ratings_total'] ?? null;
                if ($rating !== null) {
                    $db->prepare(
                        "UPDATE `{$table}` SET google_rating = ?, google_review_count = ?, last_review_check = NOW() WHERE id = ?"
                    )->execute([$rating, $count, $entity_id]);
                    $db->prepare("UPDATE review_update_log SET status='done', new_rating=?, new_count=?, updated_at=NOW() WHERE entity_type=? AND entity_id=? AND status='pending' ORDER BY id DESC LIMIT 1")
                       ->execute([$rating, $count, $entity_type, $entity_id]);
                    $api_result = ['rating' => $rating, 'review_count' => $count];
                }
            }
        }
    }

    json_out([
        'success'       => true,
        'message'       => $api_result
            ? 'Review data updated from Google Places API.'
            : 'Review check requested. Data will be updated shortly.',
        'current_rating'       => $api_result ? $api_result['rating'] : ($entity['google_rating'] ?? null),
        'current_review_count' => $api_result ? $api_result['review_count'] : ($entity['google_review_count'] ?? null),
    ]);
}
