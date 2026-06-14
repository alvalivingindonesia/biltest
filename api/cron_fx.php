<?php
/**
 * Build in Lombok — daily FX rate refresh (docs/adr/0006)
 *
 * Updates the currency_rates table (all 12 pairs across IDR/USD/EUR/AUD)
 * from the free, key-less frankfurter.app API (ECB reference rates).
 * On any failure it exits WITHOUT touching the table, so the site keeps
 * serving the last known rates.
 *
 * Run via cPanel cron, once daily (path = the deployed subdomain dir per
 * .cpanel.yml; CLI runs need no token):
 *   /usr/local/bin/php /home/rovin629/subdomains/biltest.roving-i.com.au/api/cron_fx.php
 *
 * Browser access is blocked unless the private config defines
 * CRON_FX_TOKEN and the request supplies ?token=<that value>.
 */

require_once('/home/rovin629/config/biltest_config.php');

// ---- Access control: CLI always allowed; web needs the config token ----
if (php_sapi_name() !== 'cli') {
    $token = isset($_GET['token']) ? $_GET['token'] : '';
    if (!defined('CRON_FX_TOKEN') || $token === '' || !hash_equals(CRON_FX_TOKEN, $token)) {
        http_response_code(403);
        echo "Forbidden\n";
        exit;
    }
    header('Content-Type: text/plain; charset=utf-8');
}

$currencies = array('IDR', 'USD', 'EUR', 'AUD');

// ---- Fetch EUR-based rates ----
$url = 'https://api.frankfurter.app/latest?from=EUR&to=IDR,USD,AUD';
$raw = false;
$ctx = stream_context_create(array('http' => array('timeout' => 15)));
$raw = @file_get_contents($url, false, $ctx);
if ($raw === false && function_exists('curl_init')) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    $raw = curl_exec($ch);
    curl_close($ch);
}
if ($raw === false) {
    echo "FX fetch failed — keeping last known rates.\n";
    exit;
}

$data = json_decode($raw, true);
if (!is_array($data) || empty($data['rates']['IDR']) || empty($data['rates']['USD']) || empty($data['rates']['AUD'])) {
    echo "FX response malformed — keeping last known rates.\n";
    exit;
}

// EUR-based table including EUR itself
$eur_to = array(
    'EUR' => 1.0,
    'IDR' => (float)$data['rates']['IDR'],
    'USD' => (float)$data['rates']['USD'],
    'AUD' => (float)$data['rates']['AUD'],
);
foreach ($eur_to as $c => $v) {
    if ($v <= 0) { echo "Bad rate for {$c} — keeping last known rates.\n"; exit; }
}

// ---- Write all 12 pairs ----
try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER, DB_PASS,
        array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION)
    );
} catch (Exception $e) {
    echo "DB connection failed — keeping last known rates.\n";
    exit;
}

$updated = 0;
$upd = $pdo->prepare("UPDATE currency_rates SET rate = ? WHERE from_currency = ? AND to_currency = ?");
$ins = $pdo->prepare("INSERT INTO currency_rates (from_currency, to_currency, rate) VALUES (?, ?, ?)");

foreach ($currencies as $from) {
    foreach ($currencies as $to) {
        if ($from === $to) continue;
        // rate(A→B) = EUR→B / EUR→A
        $rate = $eur_to[$to] / $eur_to[$from];
        $upd->execute(array($rate, $from, $to));
        if ($upd->rowCount() === 0) {
            // Row may exist with an identical rate (rowCount 0 on no-change) —
            // only insert when it genuinely doesn't exist.
            $chk = $pdo->prepare("SELECT COUNT(*) FROM currency_rates WHERE from_currency = ? AND to_currency = ?");
            $chk->execute(array($from, $to));
            if ((int)$chk->fetchColumn() === 0) {
                $ins->execute(array($from, $to, $rate));
            }
        }
        $updated++;
    }
}

echo "OK — {$updated} pairs refreshed (date " . (isset($data['date']) ? $data['date'] : '?') . ", USD→IDR " . round($eur_to['IDR'] / $eur_to['USD']) . ").\n";
