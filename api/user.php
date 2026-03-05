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
