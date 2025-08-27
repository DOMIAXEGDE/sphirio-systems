<?php
/**
 * Genesis Operating System (GOS)
 * Authentication Module
 */

/* ============================================================
 * AUTHENTICATION MODULE
 * ============================================================ */

/**
 * Load user database from JSON file
 */
function loadUserDB() {
    global $config;
    $userDBFile = $config['filesystem']['system_dir'] . '/users.json'; // Get path from config
    if (!file_exists($userDBFile)) {
        return [];
    }
    $data = json_decode(file_get_contents($userDBFile), true);
    return is_array($data) ? $data : [];
}

/**
 * Save user database to JSON file
 */
function saveUserDB(array $users) {
    global $config;
    $userDBFile = $config['filesystem']['system_dir'] . '/users.json'; // Get path from config
    file_put_contents($userDBFile, json_encode($users, JSON_PRETTY_PRINT));
}

/**
 * Check if the user is authenticated
 */
function isAuthenticated() {
    return isset($_SESSION['user']);
}

/**
 * Get current user information
 */
function getCurrentUser() {
    return $_SESSION['user'] ?? null;
}

/**
 * Checks if the current authenticated user is an administrator.
 */
function isAdmin(): bool
{
    $user = getCurrentUser();
    // Correctly checks if 'admin' is in the user's 'roles' array.
    return $user && in_array('admin', $user['roles'] ?? [], true);
}

/**
 * Get storage quota for a user in bytes
 */
function getUserQuota(string $username) {
    foreach (loadUserDB() as $u) {
        if ($u['username'] === $username) {
            return $u['quota'] ?? (10 * 1024 * 1024);
        }
    }
    return 10 * 1024 * 1024; // default 10 MB
}

/**
 * Calculate disk usage for a user directory
 */
function getUserDiskUsage(string $username) {
    global $config;
    $dir = $config['filesystem']['user_dir'] . '/' . $username;
    if (!file_exists($dir)) {
        return 0;
    }
    $size = 0;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        if ($file->isFile()) {
            $size += $file->getSize();
        }
    }
    return $size;
}

/**
 * Handle authentication API requests.
 */
function handleAuthAPI() {
    jsonHeader();

    $method = $_POST['method'] ?? $_GET['method'] ?? null;
    $params = $_POST['params'] ?? $_GET;

    if (is_string($params)) {
        $maybeJson = json_decode($params, true);
        if ($maybeJson !== null) { $params = $maybeJson; }
    }
    
    if (empty($params) && !empty($_POST)) {
        $params = $_POST;
    }

    if ($method === null) {
        http_response_code(400);
        echo json_encode(['success' => false, 'data' => null, 'message' => 'Missing method']);
        exit;
    }

    $result = [];
    switch ($method) {
        case 'login':
            $username = $params['username'] ?? null;
            $password = $params['password'] ?? null;
            if (!$username || !$password) {
                $result = ['success' => false, 'message' => 'Missing username or password'];
                break;
            }
            
            $users = loadUserDB();
            foreach ($users as $i => $record) {
                if ($record['username'] === $username && password_verify($password, $record['password'])) {
                    // Regenerate session ID first to prevent session fixation.
                    session_regenerate_id(true);

                    // This logic correctly handles both a single role string ('role') and an array of roles ('roles').
                    // It ensures the session always stores an array, fixing the permission bug.
                    $roles = [];
                    if (isset($record['roles']) && is_array($record['roles'])) {
                        $roles = $record['roles'];
                    } elseif (isset($record['role']) && is_string($record['role'])) {
                        $roles = [$record['role']]; // Convert singular role to an array
                    }
                    if (empty($roles)) {
                        $roles = ['user']; // Fallback to a default role
                    }

                    // Set the session data.
                    $_SESSION['user'] = [
                        'username' => $record['username'],
                        'name' => $record['name'],
                        'roles' => $roles, // Use the standardized roles array
                        'quota' => $record['quota'] ?? (10 * 1024 * 1024),
                        'lastLogin' => date('c')
                    ];
                    
                    // Update the user database.
                    $users[$i]['lastLogin'] = $_SESSION['user']['lastLogin'];
                    saveUserDB($users);

                    // Prepare the success response payload.
                    $responsePayload = [
                        'success' => true, 
                        'data' => [
                            'user' => $_SESSION['user'], 
                            'permissions' => getUserPermissions($_SESSION['user'])
                        ]
                    ];

                    session_write_close();

                    // Send the JSON response and terminate the script.
                    echo json_encode($responsePayload);
                    exit;
                }
            }
            $result = ['success' => false, 'message' => 'Invalid username or password'];
            break;

        case 'logout':
            session_unset();
            session_destroy();
            $result = ['success' => true, 'message' => 'Logged out successfully'];
            break;

        case 'validateToken':
            $user = getCurrentUser();
            if ($user) {
                // The function now returns the result instead of echoing, so we echo it here.
                echo json_encode(['success' => true, 'data' => ['valid' => true, 'user' => $user, 'permissions' => getUserPermissions($user)]]);
                exit;
            }
            echo json_encode(['success' => true, 'data' => ['valid' => false]]);
            exit;
            
        case 'getCurrentUser':
             $user = getCurrentUser();
             if ($user) {
                 $result = ['success' => true, 'data' => $user];
             } else {
                $result = ['success' => false, 'message' => 'Not authenticated'];
             }
             break;
        
        default:
            $result = ['success' => false, 'message' => 'Invalid method'];
    }

    echo json_encode($result);
    exit;
}

/**
 * Get permissions for a user based on their roles.
 */
function getUserPermissions(array $user): array
{
    // Base permissions for all authenticated users
    $permissions = [
        'app.launch.*',
        'filesystem.read.*',
        'filesystem.write.user.*',
        'filesystem.delete.user.*',
        'sandbox.execute.user'
    ];
    
    // Add permissions if the user has the 'admin' role
    if (in_array('admin', $user['roles'], true)) {
        $permissions = array_merge($permissions, [
            'filesystem.write.*',
            'filesystem.delete.*',
            'system.config.*',
            'system.admin.*',
            'sandbox.execute.*',
            'process.exec' // <-- ADD THIS MISSING PERMISSION
        ]);
    }
    
    // --- MODIFICATION START ---
    // Add developer permissions if the user has the 'developer' OR 'admin' role.
    // This correctly implements the rule that all administrators are also developers.
    if (in_array('developer', $user['roles'], true) || in_array('admin', $user['roles'], true)) {
        $permissions = array_merge($permissions, [
            'app.submit' // Example permission for developers
        ]);
    }
    // --- MODIFICATION END ---
    
    return array_unique($permissions);
}
