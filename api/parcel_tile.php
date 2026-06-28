<?php
/**
 * Build in Lombok — Parcel (Bidang Tanah) tile proxy/cache (ADR 0013)
 *
 * Serves BHUMI "bhumi_persil" parcel-boundary tiles from OUR server. On a cache
 * miss it fetches that single 256x256 tile from BHUMI's WMS (which is referer-
 * gated) via the SSRF-safe fetch, stores it under PARCEL_TILE_DIR, and serves it.
 * Cached tiles are reused for 7 days (the weekly refresh), so BHUMI is only ever
 * touched to (re)fill the cache for areas actually viewed. Public boundary data
 * only — no owner names (those need login/GetFeatureInfo, which we never call).
 *
 * Hardened: z restricted to 15-19, x/y restricted to the Lombok tile window
 * (so it can't be abused as a general proxy), per-IP rate limit, fixed upstream
 * host, image/png only. URL: /api/parcel_tile.php?z={z}&x={x}&y={y}
 */
require_once(__DIR__ . '/_sec.php');
require_once('/home/rovin629/config/biltest_config.php');

// 1x1 transparent PNG — used for out-of-range / empty / upstream-fail tiles.
function ptile_blank() {
    header('Content-Type: image/png');
    header('Cache-Control: public, max-age=3600');
    echo base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
    exit;
}

$z = isset($_GET['z']) ? (int)$_GET['z'] : 0;
$x = isset($_GET['x']) ? (int)$_GET['x'] : -1;
$y = isset($_GET['y']) ? (int)$_GET['y'] : -1;

// Parcels only render usefully at high zoom; bound the range hard.
if ($z < 15 || $z > 19) ptile_blank();
$n = 1 << $z;
if ($x < 0 || $y < 0 || $x >= $n || $y >= $n) ptile_blank();

// Restrict to the Lombok / NTB tile window (anti open-proxy).
function ptile_lng2x($lng, $z) { return (int)floor(($lng + 180) / 360 * (1 << $z)); }
function ptile_lat2y($lat, $z) { $r = deg2rad($lat); return (int)floor((1 - log(tan($r) + 1 / cos($r)) / M_PI) / 2 * (1 << $z)); }
$xMin = ptile_lng2x(115.4, $z); $xMax = ptile_lng2x(117.3, $z);
$yA = ptile_lat2y(-7.9, $z); $yB = ptile_lat2y(-9.4, $z);
$yMin = min($yA, $yB); $yMax = max($yA, $yB);
if ($x < $xMin || $x > $xMax || $y < $yMin || $y > $yMax) ptile_blank();

if (!sec_rate_ok('parcel_tile', sec_client_ip(), 800, 60)) { http_response_code(429); exit; }

$dir = (defined('PARCEL_TILE_DIR') && PARCEL_TILE_DIR) ? PARCEL_TILE_DIR : '/home/rovin629/parcel_tiles';
$path = $dir . '/' . $z . '/' . $x . '/' . $y . '.png';
$ttl = 7 * 24 * 3600; // weekly refresh

// Serve fresh cached tile.
if (is_file($path) && filesize($path) > 0 && (time() - filemtime($path) < $ttl)) {
    header('Content-Type: image/png');
    header('Cache-Control: public, max-age=86400');
    readfile($path);
    exit;
}

// Cache miss / stale → fetch this one tile from BHUMI (EPSG:3857 bbox for z/x/y).
$world = 20037508.342789244;
$ts = ($world * 2) / $n;
$minx = -$world + $x * $ts;       $maxx = -$world + ($x + 1) * $ts;
$maxy = $world - $y * $ts;        $miny = $world - ($y + 1) * $ts;
$bbox = sprintf('%F,%F,%F,%F', $minx, $miny, $maxx, $maxy);
$u = 'https://bhumi.atrbpn.go.id/mprx/service?VERSION=1.3.0&REQUEST=GetMap'
   . '&FORMAT=image%2Fpng&TRANSPARENT=true&LAYERS=bhumi_persil&STYLES=&CRS=EPSG:3857'
   . '&WIDTH=256&HEIGHT=256&BBOX=' . rawurlencode($bbox);

$res = safe_fetch($u, array('timeout' => 12, 'max_bytes' => 1048576, 'referer' => 'https://bhumi.atrbpn.go.id/peta'));
if (!empty($res['ok']) && (int)$res['status'] === 200 && strncmp($res['body'], "\x89PNG", 4) === 0) {
    if (!is_dir(dirname($path))) { @mkdir(dirname($path), 0755, true); }
    @file_put_contents($path . '.tmp', $res['body']);
    @rename($path . '.tmp', $path); // atomic
    header('Content-Type: image/png');
    header('Cache-Control: public, max-age=86400');
    echo $res['body'];
    exit;
}

// Upstream failed (e.g. transient block) — serve blank, do NOT cache, retry later.
ptile_blank();
