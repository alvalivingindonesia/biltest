<?php
/**
 * GitHub Webhook Receiver for Build in Lombok
 * 
 * Place this at: /deploy/webhook.php on HostPapa
 * GitHub sends a POST here on every push → this script pulls the latest files.
 *
 * SETUP:
 * 1. Upload this file to /home/rovin629/public_html/biltest.roving-i.com.au/deploy/webhook.php
 * 2. In GitHub repo → Settings → Webhooks → Add webhook:
 *    - Payload URL: https://biltest.roving-i.com.au/deploy/webhook.php
 *    - Content type: application/json
 *    - Secret: (set the same value as WEBHOOK_SECRET below)
 *    - Events: Just the push event
 * 3. Make sure the repo is cloned on HostPapa first (one-time SSH setup):
 *    cd /home/rovin629/public_html/biltest.roving-i.com.au
 *    git init
 *    git remote add origin https://github.com/YOUR_USER/YOUR_REPO.git
 *    git pull origin main
 * 4. For private repos, use a GitHub Personal Access Token in the remote URL:
 *    git remote set-url origin https://YOUR_TOKEN@github.com/YOUR_USER/YOUR_REPO.git
 */

// ============================================================
// CONFIG
// ============================================================

// Shared secret — set the same value in GitHub webhook settings
define('WEBHOOK_SECRET', 'bil-deploy-2026');

// Branch to deploy
define('DEPLOY_BRANCH', 'main');

// Absolute path to the site root on HostPapa
define('SITE_ROOT', '/home/rovin629/subdomains/biltest.roving-i.com.au');

// Log file
define('LOG_FILE', SITE_ROOT . '/deploy/deploy.log');

// Files/folders to NEVER overwrite from git (they exist only on the server)
$PROTECTED = [
    'deploy/webhook.php',
    'deploy/deploy.log',
];

// ============================================================
// SECURITY
// ============================================================

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die('Method not allowed');
}

// Verify GitHub signature
$payload = file_get_contents('php://input');
$sig_header = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';

if (!$sig_header) {
    http_response_code(403);
    die('No signature');
}

$expected = 'sha256=' . hash_hmac('sha256', $payload, WEBHOOK_SECRET);
if (!hash_equals($expected, $sig_header)) {
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

// Change to site directory and pull
chdir(SITE_ROOT);

// Reset any local changes and pull
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

// Write log (append)
file_put_contents(LOG_FILE, implode("\n", $log) . "\n", FILE_APPEND | LOCK_EX);

// Respond to GitHub
http_response_code(200);
header('Content-Type: application/json');
echo json_encode(['status' => 'deployed', 'time' => date('c')]);

