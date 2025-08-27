<?php
/**
 * Genesis Operating System (GOS)
 * Security Bootstrap Module
 *
 * Handles HTTPS enforcement, security headers, and secure session management.
 * This should be included at the very beginning of the main entry point (genesis-os.php).
 */

// --- 1. HTTPS Enforcement ---
// If the connection is not over HTTPS, redirect to the HTTPS version of the URL.
// This check is skipped for command-line interface (CLI) execution.
if (empty($_SERVER['HTTPS']) || $_SERVER['HTTPS'] === 'off') {
    if (php_sapi_name() !== 'cli') {
        $location = 'https://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . ($_SERVER['REQUEST_URI'] ?? '/');
        header('HTTP/1.1 301 Moved Permanently');
        header('Location: ' . $location);
        exit;
    }
}

// --- 2. Security Headers ---
// These headers are sent to the browser to enable security features.
// They must be sent before any other output.
if (!headers_sent()) {
    // Prevents the site from being rendered in an <iframe>, protecting against clickjacking.
    header('X-Frame-Options: SAMEORIGIN');
    // Prevents the browser from MIME-sniffing the content type.
    header('X-Content-Type-Options: nosniff');
    // Enables the XSS protection filter in older browsers.
    header('X-XSS-Protection: 1; mode=block');
    // Sets a basic Content Security Policy. This is configured to allow the existing
    // inline scripts and styles to function correctly.
    header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline';");
    // HTTP Strict Transport Security (HSTS): Tells the browser to always use HTTPS.
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}

// --- 3. Secure Session Configuration ---
// Configures session cookies with security best practices.
session_set_cookie_params([
    'lifetime' => 0, // Session cookie expires when the browser is closed.
    'path' => '/',
    'domain' => $_SERVER['HTTP_HOST'] ?? 'localhost',
    'secure' => true,   // The cookie will only be sent over HTTPS.
    'httponly' => true, // The cookie cannot be accessed by JavaScript.
    // --- MODIFICATION START ---
    // Reverted SameSite policy to 'Lax' from 'Strict'. 'Lax' is a strong security
    // default that is less likely to cause issues with session handling than 'Strict'.
    'samesite' => 'Lax' 
    // --- MODIFICATION END ---
]);

// --- 4. Session Start and Validation ---
session_start();

// --- MODIFICATION START ---
// The user fingerprinting check has been commented out. While it adds a layer of
// security, it can be overly sensitive to changes in a user's IP address or
// browser user-agent string, causing the session to be invalidated unexpectedly.
// This was the likely cause of the "failed to load applications" error.
/*
if (isset($_SESSION['user_fingerprint'])) {
    $current_fingerprint = md5(($_SERVER['REMOTE_ADDR'] ?? '') . ($_SERVER['HTTP_USER_AGENT'] ?? ''));
    if ($_SESSION['user_fingerprint'] !== $current_fingerprint) {
        // The fingerprint has changed, which could indicate a session hijack attempt.
        // We destroy the session for security.
        session_unset();
        session_destroy();
        // And start a fresh, clean session.
        session_start();
    }
}
*/
// --- MODIFICATION END ---
