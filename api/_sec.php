<?php
/**
 * Build in Lombok — shared security bootstrap.
 *
 * Include as the FIRST executable line of every API and admin entry point,
 * BEFORE the private-config require_once and BEFORE any session_start()/output:
 *
 *     require_once(__DIR__ . '/_sec.php');          // api/*.php
 *     require_once(__DIR__ . '/../api/_sec.php');   // admin/*.php
 *
 * Closes / underpins: SEC-008 (CSRF), SEC-010/053 (SSRF-safe fetch),
 * SEC-011 (cookie flags), SEC-021/022/023 (generic errors),
 * SEC-024 (session fixation), SEC-041 (logout), SEC-055 (display_errors),
 * SEC-056 (response headers).
 *
 * Defines functions and sets error directives only — it never emits output, so
 * a direct GET of this file is harmless.
 */

// ── Error hardening (SEC-055 / SEC-021 / SEC-022 / SEC-023) ──────────────────
// First, so a fatal in the config require_once that follows can never render the
// private path/line to the client. Diagnostics go to the server log only.
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

// ── Session cookie hardening (SEC-011) ──────────────────────────────────────
// Use instead of bare session_start(). Pass 'Strict' for admin tools.
function sec_session_start(string $samesite = 'Lax'): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    $https = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        || ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443);
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => $https,
        'httponly' => true,
        'samesite' => $samesite,
    ]);
    session_start();
}

// ── Session fixation prevention (SEC-024) ───────────────────────────────────
// Call right after verifying credentials, before writing auth state.
function sec_session_regenerate(): void {
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
    }
}

// ── Full logout: clear state + expire cookie (SEC-041) ──────────────────────
function sec_session_destroy(): void {
    $_SESSION = [];
    if (session_status() === PHP_SESSION_ACTIVE) {
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires'  => time() - 42000,
                'path'     => $p['path'] ?: '/',
                'domain'   => $p['domain'] ?? '',
                'secure'   => $p['secure'] ?? true,
                'httponly' => true,
                'samesite' => $p['samesite'] ?: 'Lax',
            ]);
        }
        session_destroy();
    }
}

// ── CSRF (SEC-008) ───────────────────────────────────────────────────────────
function sec_csrf_token(): string {
    if (session_status() !== PHP_SESSION_ACTIVE) return '';
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function sec_csrf_validate(): bool {
    if (empty($_SESSION['csrf_token'])) return false;
    $sent = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
    if ($sent === null && isset($_POST['csrf_token'])) {
        $sent = $_POST['csrf_token'];
    }
    if ($sent === null) {
        // JSON body fallback (php://input is re-readable for non-multipart bodies).
        $raw = file_get_contents('php://input');
        if ($raw !== '' && $raw !== false) {
            $j = json_decode($raw, true);
            if (is_array($j) && isset($j['csrf_token'])) $sent = $j['csrf_token'];
        }
    }
    return is_string($sent) && $sent !== '' && hash_equals($_SESSION['csrf_token'], $sent);
}

/**
 * Enforce CSRF on any state-changing (non-GET) request. On failure emits a
 * JSON 403 and exits, unless $on_fail is given (e.g. for HTML admin pages).
 */
function sec_require_csrf(?callable $on_fail = null): void {
    $m = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    if ($m === 'GET' || $m === 'HEAD' || $m === 'OPTIONS') return;
    if (sec_csrf_validate()) return;
    if ($on_fail) { $on_fail(); return; }
    if (!headers_sent()) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode(['error' => 'csrf_failed']);
    exit;
}

// ── Hardening response headers (SEC-056) ────────────────────────────────────
function sec_api_headers(bool $no_store = false): void {
    if (headers_sent()) return;
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
    if ($no_store) header('Cache-Control: no-store');
}

// ── Generic JSON exception handler (SEC-021 / SEC-022 / SEC-023) ─────────────
function sec_install_json_exception_handler(): void {
    set_exception_handler(function ($e) {
        error_log('[biltest] Uncaught ' . get_class($e) . ': ' . $e->getMessage()
            . ' @ ' . $e->getFile() . ':' . $e->getLine());
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode(['error' => 'server_error']);
        exit;
    });
}

// ── Output helpers (PHP-side escaping; SEC-004/015/016/028) ──────────────────
function sec_esc($s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Return $u only if its scheme is safe for an href/src, else ''. */
function sec_url($u): string {
    $u = trim((string)$u);
    if ($u === '') return '';
    $scheme = parse_url($u, PHP_URL_SCHEME);
    if ($scheme === null || $scheme === false || $scheme === '') {
        // No scheme: allow a relative path, but reject anything that smuggles a
        // scheme we didn't whitelist (e.g. "java\tscript:").
        if (preg_match('#^[a-z][a-z0-9+.\-]*:#i', $u)) return '';
        return $u;
    }
    return in_array(strtolower($scheme), ['http', 'https', 'tel', 'mailto'], true) ? $u : '';
}

// ── SSRF-safe outbound fetch (SEC-010 / SEC-053) ────────────────────────────
/** True if $ip is loopback/private/link-local/reserved (incl. cloud metadata). */
function sec_ip_is_blocked(string $ip): bool {
    return filter_var(
        $ip,
        FILTER_VALIDATE_IP,
        FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
    ) === false;
}

/** Resolve $host to public IPs; null (fail closed) if any address is blocked. */
function sec_resolve_safe(string $host): ?array {
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        return sec_ip_is_blocked($host) ? null : [$host];
    }
    $ips = @gethostbynamel($host) ?: [];
    $aaaa = @dns_get_record($host, DNS_AAAA) ?: [];
    foreach ($aaaa as $r) {
        if (!empty($r['ipv6'])) $ips[] = $r['ipv6'];
    }
    if (!$ips) return null;
    foreach ($ips as $ip) {
        if (sec_ip_is_blocked($ip)) return null;
    }
    return array_values(array_unique($ips));
}

function sec_abs_url(string $rel, string $base): ?string {
    if (parse_url($rel, PHP_URL_SCHEME)) return $rel;
    $b = parse_url($base);
    if (!$b || empty($b['scheme']) || empty($b['host'])) return null;
    $origin = $b['scheme'] . '://' . $b['host'] . (isset($b['port']) ? ':' . $b['port'] : '');
    if (strpos($rel, '//') === 0) return $b['scheme'] . ':' . $rel;
    if ($rel !== '' && $rel[0] === '/') return $origin . $rel;
    $path = isset($b['path']) ? preg_replace('#/[^/]*$#', '/', $b['path']) : '/';
    return $origin . $path . $rel;
}

/**
 * Fetch a URL with SSRF protection: scheme http/https only, ports 80/443 only,
 * DNS pinned to a validated public IP (anti-rebind), TLS verified, redirects
 * followed manually with per-hop re-validation, response size capped.
 * Returns ['ok'=>bool,'status'=>int,'body'=>string,'error'=>string].
 */
function safe_fetch(string $url, array $opt = []): array {
    $fail = function ($msg) { return ['ok' => false, 'status' => 0, 'body' => '', 'error' => $msg]; };
    if (!function_exists('curl_init')) return $fail('no_curl');
    $maxBytes  = (int)($opt['max_bytes'] ?? 2 * 1024 * 1024);
    $maxRedirs = (int)($opt['max_redirects'] ?? 3);
    $timeout   = (int)($opt['timeout'] ?? 12);
    $ua        = (string)($opt['user_agent'] ?? 'BuildInLombok/1.0 (+https://biltest.roving-i.com.au)');

    $current = $url;
    for ($hop = 0; $hop <= $maxRedirs; $hop++) {
        $parts = parse_url($current);
        if ($parts === false || empty($parts['scheme']) || empty($parts['host'])) return $fail('bad_url');
        $scheme = strtolower($parts['scheme']);
        if ($scheme !== 'http' && $scheme !== 'https') return $fail('bad_scheme');
        $host = $parts['host'];
        $port = (int)($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
        if ($port !== 80 && $port !== 443) return $fail('bad_port');
        $ips = sec_resolve_safe($host);
        if ($ips === null) return $fail('blocked_host');
        $ip = $ips[0];

        $body = '';
        $location = '';
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $current,
            CURLOPT_RESOLVE        => [$host . ':' . $port . ':' . $ip], // pin → validated IP
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,                              // manual, re-validated
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CONNECTTIMEOUT => $timeout,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_USERAGENT      => $ua,
            CURLOPT_HEADERFUNCTION => function ($c, $h) use (&$location) {
                if (stripos($h, 'location:') === 0) $location = trim(substr($h, 9));
                return strlen($h);
            },
            CURLOPT_WRITEFUNCTION  => function ($c, $data) use (&$body, $maxBytes) {
                $body .= $data;
                return strlen($body) > $maxBytes ? 0 : strlen($data);
            },
        ]);
        curl_exec($ch);
        $errno  = curl_errno($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($errno === CURLE_WRITE_ERROR && strlen($body) >= $maxBytes) {
            return ['ok' => true, 'status' => $status, 'body' => substr($body, 0, $maxBytes), 'error' => 'truncated'];
        }
        if ($errno !== 0) return $fail('fetch_error');

        if ($status >= 300 && $status < 400 && $location !== '') {
            $next = sec_abs_url($location, $current);
            if ($next === null) return $fail('bad_redirect');
            $current = $next;
            continue;
        }
        return ['ok' => true, 'status' => $status, 'body' => $body, 'error' => ''];
    }
    return $fail('too_many_redirects');
}

// ── Admin auth (SEC-013) ────────────────────────────────────────────────────
// Constant-time checks. Prefer a bcrypt ADMIN_PASS_HASH in the private config;
// fall back to a constant-time compare of the legacy plaintext ADMIN_PASS so
// existing admin logins keep working until the hash is set (no `===`).
function sec_admin_user_ok($candidate): bool {
    return defined('ADMIN_USER') && hash_equals((string)ADMIN_USER, (string)$candidate);
}
function sec_admin_password_ok($candidate): bool {
    if (defined('ADMIN_PASS_HASH') && ADMIN_PASS_HASH !== '') {
        return password_verify((string)$candidate, (string)ADMIN_PASS_HASH);
    }
    if (defined('ADMIN_PASS')) {
        return hash_equals((string)ADMIN_PASS, (string)$candidate);
    }
    return false;
}

// ── Best-effort rate limiting (SEC-012 / SEC-039) ───────────────────────────
// File-based fixed-window counter. Fails OPEN on storage errors so it can never
// lock out everyone, but throttles the common brute-force / abuse case.
function sec_rate_dir(): string {
    $d = sys_get_temp_dir() . '/biltest_rl';
    if (!is_dir($d)) @mkdir($d, 0700, true);
    return $d;
}
// Returns true if the request is WITHIN the limit (allow), false if exceeded.
function sec_rate_ok(string $bucket, string $id, int $limit, int $window): bool {
    $key  = preg_replace('/[^a-zA-Z0-9_.-]/', '_', $bucket . '_' . $id);
    $file = sec_rate_dir() . '/' . substr(hash('sha256', $key), 0, 40) . '.json';
    $now  = time();
    $fh = @fopen($file, 'c+');
    if (!$fh) return true; // fail open
    @flock($fh, LOCK_EX);
    $start = $now; $count = 0;
    $raw = stream_get_contents($fh);
    if ($raw) {
        $d = json_decode($raw, true);
        if (is_array($d)) { $start = (int)($d['start'] ?? $now); $count = (int)($d['count'] ?? 0); }
    }
    if ($now - $start >= $window) { $start = $now; $count = 0; } // window rolled over
    $count++;
    $allowed = $count <= $limit;
    rewind($fh); ftruncate($fh, 0);
    fwrite($fh, json_encode(['start' => $start, 'count' => $count]));
    @flock($fh, LOCK_UN); fclose($fh);
    return $allowed;
}
function sec_client_ip(): string {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    return is_string($ip) ? $ip : '0.0.0.0';
}

// ── Same-origin enforcement for state-changing requests (SEC-008) ───────────
// Primary CSRF defenses are the SameSite session cookie (sec_session_start) and
// this Origin/Referer check. The classic cross-site auto-POST always carries a
// foreign Origin and is rejected; the same-origin SPA passes. Non-browser
// callers (the Node worker) send neither header and are allowed here — they are
// authenticated by their own key, not the session cookie.
function sec_request_origin_ok(): bool {
    $m = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    if ($m === 'GET' || $m === 'HEAD' || $m === 'OPTIONS') return true;
    $host = (string)($_SERVER['HTTP_HOST'] ?? '');
    $origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
    if ($origin !== '') {
        $oh = parse_url($origin, PHP_URL_HOST);
        return is_string($oh) && strcasecmp($oh, $host) === 0;
    }
    $ref = (string)($_SERVER['HTTP_REFERER'] ?? '');
    if ($ref !== '') {
        $rh = parse_url($ref, PHP_URL_HOST);
        return is_string($rh) && strcasecmp($rh, $host) === 0;
    }
    return true; // no Origin/Referer present — SameSite cookie still applies
}
function sec_require_same_origin(?callable $on_fail = null): void {
    if (sec_request_origin_ok()) return;
    if ($on_fail) { $on_fail(); return; }
    if (!headers_sent()) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode(['error' => 'csrf_failed']);
    exit;
}
