<?php
/**
 * Genesis Operating System (GOS)
 * Applications Module
 *
 * This module manages the application catalogue, including listing built-in and
 * installed applications, and handling the submission/approval process for
 * new developer applications.
 */

/* ============================================================
 * HELPER FUNCTIONS
 * ============================================================ */

/**
 * Loads the list of pending app submissions from its JSON file.
 * @return array A list of submission records.
 */
function loadAppSubmissions(): array {
    global $config;
    $file = $config['filesystem']['system_dir'] . '/app_submissions.json';
    if (!file_exists($file)) return [];
    $data = json_decode(file_get_contents($file), true);
    return is_array($data) ? $data : [];
}

/**
 * Saves the list of pending app submissions to its JSON file.
 * @param array $submissions The array of submissions to save.
 */
function saveAppSubmissions(array $submissions): void {
    global $config;
    $file = $config['filesystem']['system_dir'] . '/app_submissions.json';
    file_put_contents($file, json_encode($submissions, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

/**
 * Validates the structure of an application manifest.
 * @param array $data The manifest data to validate.
 * @return bool True if the manifest is valid.
 */
function validateAppManifest(array $data): bool {
    $required = ['id', 'title', 'entry'];
    foreach ($required as $key) {
        if (empty($data[$key]) || !is_string($data[$key])) return false;
    }
    if (!preg_match('/^[A-Za-z0-9_-]+$/', $data['id'])) return false;
    if (isset($data['permissions']) && !is_array($data['permissions'])) return false;
    return true;
}

/**
 * Discovers application manifests from the filesystem.
 * ✅ MODIFIED: Now adds a '_path' property to each manifest for correct frontend routing.
 * @return array A list of manifest arrays from installed applications.
 */
function discoverAppManifests(): array {
    global $config;
    static $cache = null;
    if ($cache !== null) return $cache;

    $list = [];
    $appDir = $config['filesystem']['apps_dir'];
    try {
        if (is_dir($appDir)) {
            foreach (new DirectoryIterator($appDir) as $dir) {
                if ($dir->isDot() || !$dir->isDir()) continue;

                // ✅ MODIFIED: Find any .json file instead of the hardcoded 'app.json'.
                $manifests = glob($dir->getPathname() . '/*.json');
                if (empty($manifests)) continue; // Skip if no JSON file is found.
                
                $manifestPath = $manifests[0]; // Use the first JSON file found.
                
                $data = json_decode(file_get_contents($manifestPath), true);
                $appId = $dir->getBasename();

                if (is_array($data) && validateAppManifest($data) && $data['id'] === $appId) {
                    $data['_path'] = 'gos_files/apps/' . $appId . '/';
                    $list[] = $data;
                }
            }
        }
    } catch (Throwable $e) {
        error_log('[discoverAppManifests] ' . $e);
    }
    return $cache = $list;
}

// In apps.php

/**
 * Loads a single application manifest by its ID.
 * ✅ MODIFIED: Now dynamically finds the .json file instead of assuming 'app.json'.
 * @param string $id The ID of the application.
 * @return array|null The manifest data or null if not found.
 */
function loadAppManifest(string $id): ?array {
    global $config;
    $appDir = $config['filesystem']['apps_dir'] . "/$id";

    // Find any .json file in the directory.
    $manifests = glob($appDir . '/*.json');
    if (empty($manifests)) {
        return null; // No manifest file found.
    }
    
    $file = $manifests[0]; // Use the first one found.

    if (!is_file($file)) return null;

    $json = json_decode(file_get_contents($file), true);
    return (is_array($json) && validateAppManifest($json)) ? $json : null;
}

/**
 * Defines the list of applications that are built-in to the OS.
 * @return array An associative array of built-in app manifests.
 */
function builtinApps(): array {
    return [
        'desktop' => ['id' => 'desktop', 'title' => 'Desktop', 'description' => 'Genesis-OS desktop shell', 'icon' => '🖥️', 'category' => 'System', 'version' => '1.0.0', 'entry' => 'desktopApp', 'permissions' => [], 'window' => ['fullscreen' => true]],
        'mathematicalSandbox' => ['id' => 'mathematicalSandbox', 'title' => 'Mathematical Sandbox', 'description' => 'A grid-based environment for mathematical exploration', 'icon' => 'M²', 'category' => 'Science', 'version' => '1.0.0', 'entry' => 'mathematicalSandboxApp', 'permissions' => [], 'window' => ['width' => 900, 'height' => 650, 'resizable' => true], 'help' => '<p>Create grids and evaluate expressions.</p>'],
        'fileManager' => ['id' => 'fileManager', 'title' => 'File Manager', 'description' => 'Browse and manage files', 'icon' => '📁', 'category' => 'System', 'version' => '1.0.0', 'entry' => 'fileManagerApp', 'permissions' => ['filesystem.read.*', 'filesystem.write.*', 'process.exec'], 'window' => ['width' => 900, 'height' => 650, 'resizable' => true], 'help' => '<p>Manage your files.</p>'],
        'terminal' => ['id' => 'terminal', 'title' => 'Terminal', 'description' => 'Command-line interface', 'icon' => '💻', 'category' => 'System', 'version' => '1.0.0', 'entry' => 'terminalApp', 'permissions' => ['filesystem.read.*', 'filesystem.write.*', 'process.exec'], 'window' => ['width' => 800, 'height' => 550, 'resizable' => true], 'help' => '<p>Type <code>help</code> for commands.</p>'],
        'editor' => ['id' => 'editor', 'title' => 'Code Editor', 'description' => 'Edit text and code files', 'icon' => '📝', 'category' => 'Development', 'version' => '1.0.0', 'entry' => 'editorApp', 'permissions' => ['filesystem.read.*', 'filesystem.write.*'], 'window' => ['width' => 1000, 'height' => 700, 'resizable' => true], 'help' => '<p>Use Ctrl+S to save.</p>'],
        'settings' => ['id' => 'settings', 'title' => 'Settings', 'description' => 'Configure system settings', 'icon' => '⚙️', 'category' => 'System', 'version' => '1.0.0', 'entry' => 'settingsApp', 'permissions' => [], 'window' => ['width' => 600, 'height' => 500, 'resizable' => true], 'help' => '<p>Adjust system preferences.</p>'],
        'appStore' => ['id' => 'appStore', 'title' => 'App Store', 'description' => 'Install and manage applications', 'icon' => '🛒', 'category' => 'System', 'version' => '1.0.0', 'entry' => 'appStoreApp', 'permissions' => [], 'window' => ['width' => 1000, 'height' => 700, 'resizable' => true], 'help' => '<p>Browse and manage applications.</p>'],
        'diagnostics' => ['id' => 'diagnostics', 'title' => 'Diagnostics', 'description' => 'Run system tests', 'icon' => '🧪', 'category' => 'System', 'version' => '1.0.0', 'entry' => 'diagnosticsApp', 'permissions' => [], 'window' => ['width' => 700, 'height' => 500, 'resizable' => true], 'adminOnly' => true, 'help' => '<p>Admin tool to run tests.</p>'],
        'dev-center' => ['id' => 'dev-center', 'title' => 'Developer Center', 'description' => 'Submit your applications.', 'icon' => '🧬', 'category' => 'Development', 'version' => '1.0.0', 'entry' => 'devCenterApp', 'permissions' => [], 'window' => ['width' => 800, 'height' => 700, 'resizable' => true], 'developerOnly' => true, 'help' => '<p>Submit your apps for review.</p>']
    ];
}

/* ============================================================
 * API-CALLABLE FUNCTIONS
 * ============================================================ */

function listApps(): array {
    $catalogue = [];
    $user = getCurrentUser();
    $isDeveloper = $user && in_array('developer', $user['roles'] ?? []);

    foreach (builtinApps() as $appId => $app) {
        if (($app['adminOnly'] ?? false) && !isAdmin()) continue;
        if (($app['developerOnly'] ?? false) && !$isDeveloper) continue;
        $catalogue[$appId] = $app;
    }
    
    foreach (discoverAppManifests() as $app) {
        if (($app['adminOnly'] ?? false) && !isAdmin()) continue;
        if (($app['developerOnly'] ?? false) && !$isDeveloper) continue;
        $catalogue[$app['id']] = $app;
    }

    return array_values($catalogue);
}

/**
 * Loads and returns the manifest for a single application by its ID.
 * This is called when an application is launched.
 *
 * ✅ MODIFIED: This function now correctly adds the `_path` property for
 * non-builtin apps, ensuring the frontend can resolve the entry point.
 *
 * @param array $params An array containing the 'id' of the app.
 * @return array|null The manifest data or null if not found.
 */
function getAppInfo(array $params): ?array {
    $id = $params['id'] ?? null;
    if (!$id) {
        return null;
    }

    // First, check if it's a built-in application.
    $builtInApps = builtinApps();
    if (isset($builtInApps[$id])) {
        return $builtInApps[$id];
    }

    // If not built-in, load it from the filesystem.
    $manifest = loadAppManifest($id);
    if ($manifest) {
        // This is the crucial step: add the web-accessible path.
        $manifest['_path'] = 'gos_files/apps/' . $id . '/';
        return $manifest;
    }

    // If not found anywhere, return null.
    return null;
}

function listSubmissions(): array {
    return loadAppSubmissions();
}

function submitApp(array $params): array {
    $currentUser = getCurrentUser();
    $manifestJson = $params['manifest'] ?? '';
    $code = $params['code'] ?? '';

    if (empty($manifestJson) || empty($code)) {
        return ['success' => false, 'message' => 'Manifest and code cannot be empty.'];
    }
    $manifest = json_decode($manifestJson, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return ['success' => false, 'message' => 'Invalid JSON in manifest.'];
    }
    if (!validateAppManifest($manifest)) {
        return ['success' => false, 'message' => 'Manifest validation failed.'];
    }

    $submissions = loadAppSubmissions();
    $submissions[] = [
        'manifest' => $manifest,
        'code' => $code,
        'submitted_by' => $currentUser['username'],
        'submitted_at' => date('c')
    ];
    saveAppSubmissions($submissions);

    return ['success' => true, 'message' => 'Application submitted successfully.'];
}

function approveSubmission(array $params): array {
    global $config;
    $id = $params['id'] ?? null;
    if (!$id) return ['success' => false, 'message' => 'Application ID not provided.'];

    $submissions = loadAppSubmissions();
    foreach ($submissions as $i => $sub) {
        if (($sub['manifest']['id'] ?? '') === $id) {
            $dir = $config['filesystem']['apps_dir'] . "/$id";
            if (!is_dir($dir)) mkdir($dir, 0755, true);
            
            $scriptFilename = "$id.js";
            $manifestFilename = "$id.json";

            // ✅ CRITICAL FIX: Update the 'entry' field within the manifest data itself.
            $sub['manifest']['entry'] = $scriptFilename;
            
            // Now, save the MODIFIED manifest data to the correctly named file.
            file_put_contents($dir . '/' . $manifestFilename, json_encode($sub['manifest'], JSON_PRETTY_PRINT));
            file_put_contents($dir . '/' . $scriptFilename, $sub['code'] ?? '');
            
            array_splice($submissions, $i, 1);
            saveAppSubmissions($submissions);
            return ['success' => true, 'message' => 'App approved'];
        }
    }
    return ['success' => false, 'message' => 'Submission not found'];
}

function rejectSubmission(array $params): array {
    $id = $params['id'] ?? null;
    if (!$id) return ['success' => false, 'message' => 'Application ID not provided.'];

    $submissions = loadAppSubmissions();
    $initialCount = count($submissions);
    
    // ✅ FIXED: Replaced arrow function (PHP 7.4+) with a standard anonymous function
    // for broader compatibility with PHP 7.0+ environments.
    $submissions = array_filter($submissions, function($sub) use ($id) {
        return ($sub['manifest']['id'] ?? '') !== $id;
    });

    if (count($submissions) < $initialCount) {
        saveAppSubmissions(array_values($submissions));
        return ['success' => true, 'message' => 'App rejected'];
    }
    return ['success' => false, 'message' => 'Submission not found'];
}

/**
 * Securely serves a requested asset (like app.js) for a specific application.
 * This prevents direct file access and handles security checks.
 */
function getAppAsset(array $params): void {
    global $config;
    $appId = $params['appId'] ?? null;
    $file = $params['file'] ?? null;

    if (!$appId || !$file) {
        header("HTTP/1.1 400 Bad Request");
        echo "/* Missing parameters */";
        exit;
    }

    if (preg_match('/^[A-Za-z0-9_-]+$/', $appId) !== 1 || strpos($file, '..') !== false || $file[0] === '/') {
        header("HTTP/1.1 403 Forbidden");
        echo "/* Invalid path */";
        exit;
    }
    
    $assetPath = $config['filesystem']['apps_dir'] . "/$appId/$file";

    if (!is_file($assetPath)) {
        header("HTTP/1.1 404 Not Found");
        echo "/* Asset not found */";
        exit;
    }

    // ✅ FINAL FIX: Add headers to ensure a clean response and allow cross-origin access.
    // This resolves the final browser security error.
    if (ob_get_level()) {
        ob_end_clean(); // Clear any previous output buffers.
    }
    header("Access-Control-Allow-Origin: *"); // Allow the script to be loaded from any origin.
    header('Content-Type: application/javascript; charset=utf-8');
    
    readfile($assetPath);
    exit;
}


/* ============================================================
 * API ROUTER AND HANDLER
 * ============================================================ */

/**
 * Handles all incoming API requests for the applications module.
 * It routes requests to the appropriate function after checking permissions.
 */
function handleAppsAPI(): void {
    $method = $_POST['method'] ?? $_GET['method'] ?? 'listApps';

    // ✅ FINAL FIX: Use $_GET for the asset request, and $_POST['params'] for all other API calls.
    // This allows the server to correctly handle both types of requests.
    if ($method === 'getAppAsset') {
        $params = $_GET;
    } else {
        $params = $_POST['params'] ?? [];
        if (is_string($params)) {
            $params = json_decode($params, true) ?? [];
        }
    }
    
    // The rest of the function's logic can now correctly dispatch the request.
    if ($method === 'getAppAsset') {
        getAppAsset($params); // This function handles its own exit.
        return;
    }

    jsonHeader();
    $response = null;
    
    try {
        switch ($method) {
            case 'listApps':
                $response = ['success' => true, 'data' => listApps()];
                break;
            case 'getAppInfo':
                $data = getAppInfo($params);
                $response = $data 
                    ? ['success' => true, 'data' => $data]
                    : ['success' => false, 'message' => 'App not found.'];
                break;
            case 'listSubmissions':
                if (!isAdmin()) throw new Exception('Access Denied');
                $response = ['success' => true, 'data' => listSubmissions()];
                break;
            case 'submitApp':
                if (!hasPermission('app.submit')) throw new Exception('Access Denied');
                $response = submitApp($params);
                break;
            case 'approveSubmission':
                if (!isAdmin()) throw new Exception('Access Denied');
                $response = approveSubmission($params);
                break;
            case 'rejectSubmission':
                if (!isAdmin()) throw new Exception('Access Denied');
                $response = rejectSubmission($params);
                break;
            default:
                $response = ['success' => false, 'message' => "Unknown method: $method"];
        }
    } catch (Throwable $e) {
        error_log("[AppsAPI] Exception in method '$method': " . $e->getMessage());
        $response = ['success' => false, 'message' => $e->getMessage()];
    }

    echo json_encode($response);
    exit;
}