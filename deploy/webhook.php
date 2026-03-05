<?php
/**
 * GitHub Webhook Receiver for Build in Lombok
 * Place this at: /deploy/webhook.php on HostPapa
 * GitHub sends a POST here on every push -> this script pulls the latest files.
 */

// ============================================================
// CONFIG
// ============================================================

define('WEBHOOK_SECRET', 'bil-deploy-2026');
define('DEPLOY_BRANCH', 'main');
define('SITE_ROOT', '/home/rovin629/subdomains/biltest.roving-i.com.au');
define('LOG_FILE', SITE_ROOT . '/deploy/deploy.log');

// ============================================================
// SECURITY
// ============================================================

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die('Method not allowed');
}

// Read raw body
$payload = file_get_contents('php://input');

// Get signature - check both formats (Apache may mangle header names)
$sig_header = '';
if (isset($_SERVER['HTTP_X_HUB_SIGNATURE_256'])) {
    $sig_header = $_SERVER['HTTP_X_HUB_SIGNATURE_256'];
} elseif (function_exists('getallheaders')) {
    $headers = getallheaders();
    foreach ($headers as $k => $v) {
        if (strtolower($k) === 'x-hub-signature-256') {
            $sig_header = $v;
            break;
        }
    }
}

if (!$sig_header) {
    // Log what headers we received for debugging
    $debug = "=== 403 No signature: " . date('Y-m-d H:i:s') . " ===\n";
    $debug .= "Headers received:\n";
    foreach ($_SERVER as $k => $v) {
        if (strpos($k, 'HTTP_') === 0) $debug .= "  $k: $v\n";
    }
    if (function_exists('getallheaders')) {
        $debug .= "getallheaders():\n";
        foreach (getallheaders() as $k => $v) {
            $debug .= "  $k: $v\n";
        }
    }
    $debug .= "\n";
    file_put_contents(LOG_FILE, $debug, FILE_APPEND | LOCK_EX);

    http_response_code(403);
    die('No signature');
}

$expected = 'sha256=' . hash_hmac('sha256', $payload, WEBHOOK_SECRET);
if (!hash_equals($expected, $sig_header)) {
    $debug = "=== 403 Bad signature: " . date('Y-m-d H:i:s') . " ===\n";
    $debug .= "Expected: $expected\n";
    $debug .= "Got:      $sig_header\n\n";
    file_put_contents(LOG_FILE, $debug, FILE_APPEND | LOCK_EX);

    http_response_code(403);
    die('Invalid signature');
}

// Parse payload
$data = json_decode($payload, true);
if (!$data) {
    http_response_code(400);
    die('Invalid JSON');
}

// Only deploy on pushes to the target branch
$ref = $data['ref'] ?? '';
if ($ref !== 'refs/heads/' . DEPLOY_BRANCH) {
    http_response_code(200);
    die('Ignored: not ' . DEPLOY_BRANCH . ' branch');
}

// ============================================================
// DEPLOY
// ============================================================

$log = [];
$log[] = '=== Deploy started: ' . date('Y-m-d H:i:s T') . ' ===';
$log[] = 'Commit: ' . ($data['head_commit']['id'] ?? 'unknown');
$log[] = 'By: ' . ($data['head_commit']['author']['name'] ?? 'unknown');
$log[] = 'Message: ' . ($data['head_commit']['message'] ?? '');

chdir(SITE_ROOT);

$commands = [
    'git fetch origin ' . DEPLOY_BRANCH . ' 2>&1',
    'git reset --hard origin/' . DEPLOY_BRANCH . ' 2>&1',
];

foreach ($commands as $cmd) {
    $output = [];
    $code = 0;
    exec($cmd, $output, $code);
    $log[] = "$ {$cmd}";
    $log[] = implode("\n", $output);
    if ($code !== 0) {
        $log[] = "ERROR: exit code {$code}";
        break;
    }
}

$log[] = '=== Deploy finished: ' . date('Y-m-d H:i:s T') . ' ===';
$log[] = '';

file_put_contents(LOG_FILE, implode("\n", $log) . "\n", FILE_APPEND | LOCK_EX);

http_response_code(200);
header('Content-Type: application/json');
echo json_encode(['status' => 'deployed', 'time' => date('c')]);
