<?php
/**
 * Build in Lombok — nightly Agent Reputation recompute (docs/adr/0008)
 *
 * Earned trust from listing volume + tenure (first seen) + current active
 * count. Deliberately independent of the manual is_verified / is_trusted
 * flags. Counts distinct listings EVER seen (expired/sold included — the
 * soft-delete rule preserves them) so reputation doesn't evaporate on expiry.
 *
 * Run via cPanel cron, nightly (after the Worker's run):
 *   /usr/local/bin/php /home/rovin629/public_html/api/cron_reputation.php
 * Web access needs ?token=<CRON_REPUTATION_TOKEN> from private config.
 */

require_once('/home/rovin629/config/biltest_config.php');
require_once(__DIR__ . '/reputation.php');

if (php_sapi_name() !== 'cli') {
    $token = isset($_GET['token']) ? $_GET['token'] : '';
    if (!defined('CRON_REPUTATION_TOKEN') || $token === '' || !hash_equals(CRON_REPUTATION_TOKEN, $token)) {
        http_response_code(403); echo "Forbidden\n"; exit;
    }
    header('Content-Type: text/plain; charset=utf-8');
}

try {
    $db = new PDO('mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4', DB_USER, DB_PASS, array(
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ));
} catch (Exception $e) { echo "DB connection failed.\n"; exit; }

$n = bil_recompute_reputation($db);
echo "OK — reputation recomputed for {$n} agents.\n";
