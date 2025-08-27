<?php
/**
 * Genesis Operating System (GOS)
 * Bootstrapping File
 */

// Start output buffering to prevent header issues
ob_start();

// Load Configuration
$config = require_once 'config.php';

/* -------------------------------------------------------------
 * REQUIRED: Accept JSON bodies as if they were normal $_POST data
 * ------------------------------------------------------------- */
//if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_SERVER['CONTENT_TYPE']) && strpos($_SERVER['CONTENT_TYPE'], 'application/json') === 0) {
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && !empty($_SERVER['CONTENT_TYPE']) && strpos($_SERVER['CONTENT_TYPE'], 'application/json') === 0) {
    $raw = file_get_contents('php://input');
    if ($raw !== false && $raw !== '') {
        $json = json_decode($raw, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($json)) {
            // Merge so that explicit form fields can still override
            $_POST = array_merge($json, $_POST);
        }
    }
}

/* -------------------------------------------------------------
 * REQUIRED: Robust Error & Exception Handling
 * ------------------------------------------------------------- */
$errorLogFile = $config['filesystem']['system_dir'] . '/error.log';
ini_set('log_errors', '1');
ini_set('error_log', $errorLogFile);

set_error_handler(function($severity, $message, $file, $line) {
    $entry = '['.date('Y-m-d H:i:s')."] PHP [$severity] $message in $file:$line";
    error_log($entry);
    return false; // Allow default PHP handler to run as well
});

set_exception_handler(function($e) use ($errorLogFile) {
    $entry = '['.date('Y-m-d H:i:s')."] Uncaught Exception: {$e->getMessage()} in {$e->getFile()}:{$e->getLine()}\n".$e->getTraceAsString();
    error_log($entry);
});

register_shutdown_function(function() use ($errorLogFile) {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        $entry = '['.date('Y-m-d H:i:s')."] Fatal Error: {$err['message']} in {$err['file']}:{$err['line']}";
        file_put_contents($errorLogFile, $entry."\n", FILE_APPEND);
    }
});

/* -------------------------------------------------------------
 * Session and Filesystem Initialization
 * ------------------------------------------------------------- */
$secureCookie = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => $secureCookie,
    'httponly' => true,
    'samesite' => 'Lax'
]);
session_start();

// Include Core Modules
require_once 'modules/auth.php';
require_once 'modules/filesystem.php';
require_once 'modules/apps.php';
require_once 'modules/users.php';
require_once 'modules/system.php';
require_once 'modules/sandbox.php';

// Create required directories if filesystem is enabled
if ($config['filesystem']['use_local']) {
    foreach ($config['filesystem'] as $key => $dir) {
        // Use is_dir for a more reliable check
        if (strpos($key, 'dir') !== false && !is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
    }
}

// Helper for consistent JSON headers, to be used in genesis-os.php
function jsonHeader(): void {
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
    }
}