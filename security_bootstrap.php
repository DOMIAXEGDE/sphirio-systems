<?php
/**
 * security_bootstrap.php
 *
 * Production:  - Redirects HTTP -> HTTPS
 *              - Sends HSTS (optionally ;preload)
 *              - Locks down headers + CSP with nonces
 * Development: - Allows HTTP for php -S / reverse-proxied local
 *              - Relaxes CSP (no nonces; inline/eval allowed by default)
 *
 * Env toggles (optional):
 *   APP_ENV=production|development|auto      (default: auto-detect)
 *   APP_DEBUG=1|0                            (default: 0 in prod, 1 in dev)
 *   APP_HSTS_PRELOAD=1|0                     (default: 0)
 *   APP_CSP_ALLOW_INLINE_DEV=1|0             (default: 1 in dev, 0 in prod)
 *   APP_COEP=1|0                             (default: 0; set 1 only if you need COEP)
 */

///////////////////////////////
// 1) ENV & REQUEST CONTEXT  //
///////////////////////////////

/** Normalize host/addr */
$host    = strtolower($_SERVER['HTTP_HOST'] ?? '');
$remote  = $_SERVER['REMOTE_ADDR'] ?? '';

/** Localhost-ish detection (localhost, 127.0.0.1, ::1, *.local, *.test) */
$isLocalHost = false;
if ($host === 'localhost' || str_starts_with($host, 'localhost:')) $isLocalHost = true;
if ($host === '127.0.0.1'   || str_starts_with($host, '127.0.0.1:')) $isLocalHost = true;
if ($host === '[::1]'       || str_starts_with($host, '[::1]:'))     $isLocalHost = true;
if (in_array($remote, ['127.0.0.1', '::1'], true))                   $isLocalHost = true;
foreach (['.localhost', '.local', '.test'] as $tld) {
    if (!$isLocalHost && str_ends_with($host, $tld)) $isLocalHost = true; // covers dev.local
}

/** HTTPS detection (direct + typical proxies/CDN) */
$isHttps =
    (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (($_SERVER['SERVER_PORT'] ?? '') === '443')
    || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https')
    || (isset($_SERVER['HTTP_CF_VISITOR']) && stripos($_SERVER['HTTP_CF_VISITOR'], '"https"') !== false);

/** Decide environment */
$env = getenv('APP_ENV') ?: 'auto';
if ($env === 'auto') {
    $env = $isLocalHost ? 'development' : 'production';
}
$isProd = ($env === 'production');

/** Debug flag */
$debug = getenv('APP_DEBUG');
$debug = ($debug !== false) ? ($debug === '1' || strtolower($debug) === 'true') : !$isProd;

/** Convenience: scheme + URL builders */
$scheme = $isHttps ? 'https' : 'http';
$requestUri = $_SERVER['REQUEST_URI'] ?? '/';
function _current_url_https(): string {
    $h = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $u = $_SERVER['REQUEST_URI'] ?? '/';
    return 'https://' . $h . $u;
}

///////////////////////////////////////
// 2) ERROR VISIBILITY (dev vs prod) //
///////////////////////////////////////
if ($debug) {
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
    error_reporting(E_ALL & ~E_NOTICE & ~E_STRICT & ~E_DEPRECATED);
}

/** Never advertise PHP version */
@ini_set('expose_php', '0');
@header_remove('X-Powered-By');

///////////////////////////////////////////////////////////
// 3) ENFORCE HTTPS IN PRODUCTION (allow HTTP localhost) //
///////////////////////////////////////////////////////////
if ($isProd && !$isHttps) {
    // Only redirect safe methods to reduce risk of breaking non-idempotent calls
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    if (in_array($method, ['GET', 'HEAD'], true)) {
        // 308 preserves method/URL; 301 also fine for GET/HEAD
        header('Location: ' . _current_url_https(), true, 308);
        exit;
    }
    // For non-GET/HEAD, refuse over plain HTTP in prod
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo "This endpoint requires HTTPS.";
    exit;
}

////////////////////////////////////
// 4) HSTS (only when truly HTTPS) //
////////////////////////////////////
if ($isProd && $isHttps && !$isLocalHost) {
    $preload = (getenv('APP_HSTS_PRELOAD') === '1');
    $hsts = 'max-age=31536000; includeSubDomains' . ($preload ? '; preload' : '');
    header('Strict-Transport-Security: ' . $hsts);
}

////////////////////////////////////////////////////////
// 5) SESSION & COOKIE DEFAULTS (secure when possible) //
////////////////////////////////////////////////////////
/**
 * Call before session_start(). If you already started sessions elsewhere, no harm.
 */
$cookieSecure = $isProd && $isHttps; // dev on http: allow non-secure cookies, prod requires secure
$cookieParams = [
    'lifetime' => 0,               // session cookie
    'path'     => '/',
    'domain'   => '',              // default current host
    'secure'   => $cookieSecure,
    'httponly' => true,
    'samesite' => 'Lax',
];
if (PHP_SESSION_ACTIVE !== session_status()) {
    session_set_cookie_params($cookieParams);
    // Prevent session fixation; use strict mode + secure entropy
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');
    ini_set('session.cookie_secure', $cookieSecure ? '1' : '0');
    // You may still need to call session_start() in your app bootstrap.
}

/** Helper to set app cookies safely */
function set_secure_cookie(
    string $name,
    string $value,
    int $ttlSeconds = 0,
    ?string $path = '/',
    ?string $domain = null,
    ?bool $secure = null,
    bool $httpOnly = true,
    string $sameSite = 'Lax'
): bool {
    $secure = $secure ?? (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    $opts = [
        'expires'  => $ttlSeconds > 0 ? (time() + $ttlSeconds) : 0,
        'path'     => $path ?? '/',
        'domain'   => $domain ?? '',
        'secure'   => $secure,
        'httponly' => $httpOnly,
        'samesite' => $sameSite,
    ];
    return setcookie($name, $value, $opts);
}

/////////////////////////////////////////
// 6) CONTENT SECURITY POLICY + NONCE  //
/////////////////////////////////////////
/**
 * Use security_csp_nonce() in templates for inline <script>/<style> in PROD:
 *   <script nonce="<?= security_csp_nonce() ?>">/* your inline JS * /</script>
 * In DEV the function returns '' and you don't need the attribute.
 */
static $__CSP_NONCE = null;
function security_csp_nonce(): string {
    global $__CSP_NONCE, $isProd;
    if (!$isProd) return ''; // dev: no nonce so inline works without warnings
    if ($__CSP_NONCE === null) {
        $__CSP_NONCE = rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '=');
    }
    return $__CSP_NONCE;
}

$allowInlineDev = getenv('APP_CSP_ALLOW_INLINE_DEV');
$allowInlineDev = ($allowInlineDev === false)
    ? (!$isProd)            // default: allow inline in dev, not in prod
    : ($allowInlineDev === '1');

$cspDirectives = [
    "default-src 'self'",
    "base-uri 'self'",
    "frame-ancestors 'none'",
    "object-src 'none'",
    "img-src 'self' data: blob: https:",
    "font-src 'self' data: https:",
    "connect-src 'self' https: wss:",
    "media-src 'self' https:",
    "form-action 'self'",
    "manifest-src 'self'",
];

// Build script/style-src depending on env
if ($isProd) {
    $nonce = security_csp_nonce();
    $scriptSrc = ["'self'", "'nonce-{$nonce}'"];
    $styleSrc  = ["'self'", "'nonce-{$nonce}'"];
    // If you load from CDNs, append their origins here, e.g.: $scriptSrc[] = 'https://cdn.jsdelivr.net';
} else {
    // DEV: do NOT include a nonce; allow inline + eval for fast iteration
    $scriptSrc = ["'self'"];
    $styleSrc  = ["'self'"];
    if ($allowInlineDev) {
        $scriptSrc[] = "'unsafe-inline'";
        $scriptSrc[] = "'unsafe-eval'";
        $styleSrc[]  = "'unsafe-inline'";
    }
}

$cspDirectives[] = 'script-src ' . implode(' ', $scriptSrc);
$cspDirectives[] = 'style-src '  . implode(' ', $styleSrc);

header('Content-Security-Policy: ' . implode('; ', $cspDirectives));

///////////////////////////////////////////
// 7) OTHER HARDENING SECURITY HEADERS   //
///////////////////////////////////////////
// MIME sniffing
header('X-Content-Type-Options: nosniff');
// Referrer privacy
header('Referrer-Policy: strict-origin-when-cross-origin');
// Minimal permissions (adjust as needed)
header('Permissions-Policy: accelerometer=(), autoplay=(), camera=(), display-capture=(), fullscreen=(), geolocation=(), gyroscope=(), microphone=(), payment=(), usb=()');
// Clickjacking (redundant with frame-ancestors, kept for legacy)
header('X-Frame-Options: DENY');

// Isolation (enable COOP; COEP optional, can break embeddings)
header('Cross-Origin-Opener-Policy: same-origin');
if (getenv('APP_COEP') === '1') {
    header('Cross-Origin-Embedder-Policy: require-corp');
    header('Cross-Origin-Resource-Policy: same-origin');
} else {
    header('Cross-Origin-Resource-Policy: same-site');
}

// Cache defaults: dynamic pages should not be cached by intermediaries.
// Adjust on endpoints serving static assets.
if (!headers_sent() && !isset($GLOBALS['__CACHE_HEADERS_SET'])) {
    header('Cache-Control: no-store, no-cache, must-revalidate, private');
    header('Pragma: no-cache');
    $GLOBALS['__CACHE_HEADERS_SET'] = true;
}

//////////////////////////////////////////////
// 8) OPTIONAL: “secure by default” helpers //
//////////////////////////////////////////////

/** Force JSON responses to declare charset */
function json_headers(): void {
    header('Content-Type: application/json; charset=utf-8');
}

/** Simple 405 guard for unexpected methods */
function allow_methods(array $allowed): void {
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    if (!in_array($method, $allowed, true)) {
        header('Allow: ' . implode(', ', $allowed));
        http_response_code(405);
        exit;
    }
}

/** CSRF check placeholder (wire this to your framework/session token) */
function require_csrf_token(?string $tokenFromClient): void {
    if (!$tokenFromClient || !isset($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $tokenFromClient)) {
        http_response_code(419); // Authentication Timeout
        echo 'Invalid CSRF token.';
        exit;
    }
}

/** Regenerate session ID safely (e.g., on login privilege change) */
function session_regenerate_strict(): void {
    if (PHP_SESSION_ACTIVE === session_status()) {
        session_regenerate_id(true);
    }
}

/** Utility for building absolute URLs with current host */
function url(string $path = '/'): string {
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $scheme = $isHttps ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $path   = ($path === '') ? '/' : $path;
    return $scheme . '://' . $host . $path;
}

// Expose a couple of flags if your app wants to branch
$GLOBALS['APP_IS_PROD']   = $isProd;
$GLOBALS['APP_IS_HTTPS']  = $isHttps;
$GLOBALS['APP_CSP_NONCE'] = security_csp_nonce();

/* End of security_bootstrap.php */
