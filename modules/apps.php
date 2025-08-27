<?php
/**
 * Genesis Operating System (GOS)
 * Applications Module
 */

// --- Helper Functions ---

function isDeveloper(string $username): bool {
    global $config;
    return in_array($username, $config['security']['developer_users'] ?? [], true);
}

function loadAppSubmissions(): array {
    global $config;
    $file = $config['filesystem']['system_dir'] . '/app_submissions.json';
    if (!file_exists($file)) return [];
    $data = json_decode(file_get_contents($file), true);
    return is_array($data) ? $data : [];
}

function saveAppSubmissions(array $submissions): void {
    global $config;
    $file = $config['filesystem']['system_dir'] . '/app_submissions.json';
    file_put_contents($file, json_encode($submissions, JSON_PRETTY_PRINT));
}

function validateAppManifest(array $data): bool {
    $required = ['id', 'title', 'entry'];
    foreach ($required as $key) {
        if (empty($data[$key]) || !is_string($data[$key])) {
            return false;
        }
    }
    if (!preg_match('/^[A-Za-z0-9_-]+$/', $data['id'])) {
        return false;
    }
    if (isset($data['permissions']) && !is_array($data['permissions'])) {
        return false;
    }
    return true;
}

function discoverAppManifests(): array {
    global $config;
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $list = [];
    $appDir = $config['filesystem']['apps_dir'];
    try {
        if (is_dir($appDir)) {
            foreach (new DirectoryIterator($appDir) as $dir) {
                if ($dir->isDot() || !$dir->isDir()) continue;
                $manifestPath = $dir->getPathname() . '/app.json';
                if (!is_file($manifestPath)) continue;
                $data = json_decode(file_get_contents($manifestPath), true);
                if (is_array($data) && validateAppManifest($data) && $data['id'] === $dir->getBasename()) {
                    $list[] = $data;
                }
            }
        }
    } catch (Throwable $e) {
        error_log('[discoverAppManifests] ' . $e);
    }
    return $cache = $list;
}

function loadAppManifest(string $id): ?array {
    global $config;
    $file = $config['filesystem']['apps_dir'] . "/$id/app.json";
    if (!is_file($file)) {
        return null;
    }
    $json = json_decode(file_get_contents($file), true);
    return (is_array($json) && validateAppManifest($json)) ? $json : null;
}

function builtinApps(): array {
    return [
        'desktop' => ['id' => 'desktop', 'title' => 'Desktop', 'description' => 'Genesis-OS desktop shell', 'icon' => '🖥️', 'category' => 'System', 'version' => '1.0.0', 'entry' => 'desktopApp', 'permissions' => [], 'window' => ['fullscreen' => true]],
        'mathematicalSandbox' => ['id' => 'mathematicalSandbox', 'title' => 'Mathematical Sandbox', 'description' => 'A grid-based environment for mathematical exploration', 'icon' => 'M²', 'category' => 'Science', 'version' => '1.0.0', 'entry' => 'mathematicalSandboxApp', 'permissions' => [], 'window' => ['width' => 900, 'height' => 650, 'resizable' => true], 'help' => '<p>Create grids and evaluate expressions.</p>'],
        'fileManager' => ['id' => 'fileManager', 'title' => 'File Manager', 'description' => 'Browse and manage files', 'icon' => '📁', 'category' => 'System', 'version' => '1.0.0', 'entry' => 'fileManagerApp', 'permissions' => ['filesystem.read.*', 'filesystem.write.*'], 'window' => ['width' => 900, 'height' => 650, 'resizable' => true], 'help' => '<p>Manage your files.</p>'],
        'terminal' => ['id' => 'terminal', 'title' => 'Terminal', 'description' => 'Command-line interface', 'icon' => '💻', 'category' => 'System', 'version' => '1.0.0', 'entry' => 'terminalApp', 'permissions' => ['filesystem.read.*', 'filesystem.write.*', 'process.exec'], 'window' => ['width' => 800, 'height' => 550, 'resizable' => true], 'help' => '<p>Type <code>help</code> for commands.</p>'],
        'editor' => ['id' => 'editor', 'title' => 'Code Editor', 'description' => 'Edit text and code files', 'icon' => '📝', 'category' => 'Development', 'version' => '1.0.0', 'entry' => 'editorApp', 'permissions' => ['filesystem.read.*', 'filesystem.write.*'], 'window' => ['width' => 1000, 'height' => 700, 'resizable' => true], 'help' => '<p>Use Ctrl+S to save.</p>'],
        'settings' => ['id' => 'settings', 'title' => 'Settings', 'description' => 'Configure system settings', 'icon' => '⚙️', 'category' => 'System', 'version' => '1.0.0', 'entry' => 'settingsApp', 'permissions' => [], 'window' => ['width' => 600, 'height' => 500, 'resizable' => true], 'help' => '<p>Adjust system preferences.</p>'],
        'appStore' => ['id' => 'appStore', 'title' => 'App Store', 'description' => 'Install and manage applications', 'icon' => '🛒', 'category' => 'System', 'version' => '1.0.0', 'entry' => 'appStoreApp', 'permissions' => [], 'window' => ['width' => 1000, 'height' => 700, 'resizable' => true], 'help' => '<p>Browse and manage applications.</p>'],
        'diagnostics' => ['id' => 'diagnostics', 'title' => 'Diagnostics', 'description' => 'Run system tests', 'icon' => '🧪', 'category' => 'System', 'version' => '1.0.0', 'entry' => 'diagnosticsApp', 'permissions' => [], 'window' => ['width' => 700, 'height' => 500, 'resizable' => true], 'adminOnly' => true, 'help' => '<p>Admin tool to run tests.</p>'],
        'dev-center' => ['id' => 'dev-center', 'title' => 'Developer Center', 'description' => 'Submit your applications.', 'icon' => '🧬', 'category' => 'Development', 'version' => '1.0.0', 'entry' => 'devCenterApp', 'permissions' => [], 'window' => ['width' => 800, 'height' => 700, 'resizable' => true], 'developerOnly' => true, 'help' => '<p>Submit your apps for review.</p>']
    ];
}


// --- API-Callable Functions ---

function listApps(array $params): array
{
    try {
        $catalogue = [];
        $currentUser = getCurrentUser(); // This returns an array like ['username' => 'admin'] or null

        // Correctly determine user roles
        $isAdmin = $currentUser ? isAdmin() : false;
        $isDeveloper = $currentUser ? isDeveloper($currentUser['username']) : false;

        // 1. Add all built-in apps to the catalogue first.
        foreach (builtinApps() as $appId => $app) {
            // Hide admin-only apps from non-admins
            if (($app['adminOnly'] ?? false) && !$isAdmin) {
                continue;
            }
            // Hide developer-only apps from non-developers
            if (($app['developerOnly'] ?? false) && !$isDeveloper) {
                continue;
            }
            $catalogue[$appId] = $app;
        }
       
        // 2. Add external apps.
        foreach (discoverAppManifests() as $app) {
            if (($app['adminOnly'] ?? false) && !$isAdmin) {
                continue;
            }
             // Also check developerOnly for external apps
            if (($app['developerOnly'] ?? false) && !$isDeveloper) {
                continue;
            }
            $catalogue[$app['id']] = $app;
        }

        // 3. Ensure all apps in the final catalogue have all required default keys.
        foreach ($catalogue as $appId => $app) {
            $catalogue[$appId] = array_merge(
                [
                    'description' => '',
                    'icon'        => '📦',
                    'category'    => 'Misc',
                    'version'     => '0.0.0',
                    'entry'       => null,
                    'permissions' => [],
                    'window'      => ['width'=>800,'height'=>600,'resizable'=>true]
                ],
                $app
            );
        }

        return array_values($catalogue);

    } catch (Throwable $e) {
        error_log('[listApps] '.$e);
        http_response_code(500);
        return ['error'=>'Internal server error'];
    }
}

function getAppInfo(array $params): array {
    $id = $params['id'] ?? null;
    if (!$id) return ['error' => 'App ID not provided.'];
    
    $manifest = loadAppManifest($id);
    if ($manifest !== null) return $manifest;

    $builtins = builtinApps();
    if (isset($builtins[$id])) return $builtins[$id];
    
    return ['error' => 'App not found'];
}

function listSubmissions(array $params): array {
    if (!isAdmin()) return ['error' => 'Access denied'];
    return loadAppSubmissions();
}

/**
 * Submits a new application for administrative review.
 * @param array $params Must contain 'manifest' (JSON string) and 'code' (string).
 * @return array An empty array on success, or an array with an 'error' key on failure.
 */
function submitApp(array $params): array {
    $currentUser = getCurrentUser();
    // CORRECTED: Pass the username string from the user array.
    if (!$currentUser || !isDeveloper($currentUser['username'])) {
        return ['error' => 'Access denied. Developer role required.'];
    }

    $manifestJson = $params['manifest'] ?? '';
    $code = $params['code'] ?? '';

    if (empty($manifestJson) || empty($code)) {
        return ['error' => 'Manifest and code cannot be empty.'];
    }

    $manifest = json_decode($manifestJson, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return ['error' => 'Invalid JSON in manifest.'];
    }

    // Additional validation for manifest content
    if (empty($manifest['id']) || empty($manifest['name']) || empty($manifest['entry_point'])) {
        return ['error' => 'Manifest is missing required fields: id, name, entry_point.'];
    }

    $submissions = loadAppSubmissions();
    $submissions[] = [
        'manifest' => $manifest,
        'code' => $code,
        'submitted_by' => $currentUser['username'], // Store username string, not the whole object
        'submitted_at' => date('c')
    ];
    saveAppSubmissions($submissions);

    return []; // Success
}

/**
 * Approves a pending application submission.
 * @param array $params Must contain the 'id' of the application to approve.
 * @return array A success message or an error.
 */
function approveSubmission(array $params): array
{
    global $config;
    if (!isAdmin()) {
        return ['error' => 'Access denied'];
    }
    
    $id = $params['id'] ?? null;
    if (!$id) {
        return ['error' => 'Application ID not provided.'];
    }

    $subs = loadAppSubmissions();
    foreach ($subs as $i => $sub) {
        if (($sub['manifest']['id'] ?? '') === $id) {
            $dir = $config['filesystem']['apps_dir'] . "/$id";
            if (!file_exists($dir)) {
                mkdir($dir, 0755, true);
            }
            file_put_contents($dir . '/app.json', json_encode($sub['manifest'], JSON_PRETTY_PRINT));
            file_put_contents($dir . '/app.js', $sub['code'] ?? '');
            array_splice($subs, $i, 1);
            saveAppSubmissions($subs);
            return ['message' => 'App approved'];
        }
    }
    return ['error' => 'Submission not found'];
}

/**
 * Rejects a pending application submission.
 * @param array $params Must contain the 'id' of the application to reject.
 * @return array A success message or an error.
 */
function rejectSubmission(array $params): array
{
    if (!isAdmin()) {
        return ['error' => 'Access denied'];
    }
    
    $id = $params['id'] ?? null;
    if (!$id) {
        return ['error' => 'Application ID not provided.'];
    }

    $subs = loadAppSubmissions();
    foreach ($subs as $i => $sub) {
        if (($sub['manifest']['id'] ?? '') === $id) {
            array_splice($subs, $i, 1);
            saveAppSubmissions($subs);
            return ['message' => 'App rejected'];
        }
    }
    return ['error' => 'Submission not found'];
}


// --- API Router and Handler ---

function routeAppsCallSafely(string $method, array $params) {
    $callableFunctions = ['listApps', 'getAppInfo', 'listSubmissions', 'submitApp', 'approveSubmission', 'rejectSubmission'];
    if (in_array($method, $callableFunctions, true) && function_exists($method)) {
        return $method($params);
    }
    return ['error' => "Unknown or non-callable method: $method"];
}

function handleAppsAPI(): void {
    jsonHeader();
    $method = $_POST['method'] ?? $_GET['method'] ?? 'listApps';
    $params = $_POST['params'] ?? $_GET['params'] ?? [];
    if (is_string($params)) {
        $maybe = json_decode($params, true);
        if (json_last_error() === JSON_ERROR_NONE) $params = $maybe;
    }
    $success = false;
    $data = null;
    $message = null;
    try {
        ob_start();
        $payload = routeAppsCallSafely($method, $params);
        $noise = ob_get_clean();
        if ($noise !== '') error_log('[AppsAPI] Noise: '.$noise);
        $isError = !is_array($payload) || isset($payload['error']);
        $success = !$isError;
        $data = $success ? $payload : null;
        $message = $isError ? ($payload['error'] ?? 'An unknown error occurred.') : null;
    } catch (Throwable $e) {
        if (ob_get_level() > 0) ob_end_clean();
        error_log('[AppsAPI] Exception: '.$e);
        $message = 'Internal server error.';
    }
    echo json_encode(['success' => $success, 'data' => $data, 'message' => $message]);
    exit;
}