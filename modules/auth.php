<?php
/**
 * Genesis Operating System (GOS)
 * Authentication Module
 *
 * This module is the central authority for user authentication, session management,
 * and permission definition. It handles login, logout, registration, and session
 * validation, and it establishes the permissions that other modules will enforce.
 */

/* ============================================================
 * DATABASE HELPERS
 * ============================================================ */

/**
 * Loads the user database from the users.json file.
 * @return array A list of user records.
 */
function loadUserDB(): array {
    global $config;
    $userDBFile = $config['filesystem']['system_dir'] . '/users.json';
    if (!file_exists($userDBFile)) {
        return [];
    }
    $data = json_decode(file_get_contents($userDBFile), true);
    return is_array($data) ? $data : [];
}

/**
 * Saves the user database to the users.json file.
 * @param array $users The array of user records to save.
 */
function saveUserDB(array $users): void {
    global $config;
    $userDBFile = $config['filesystem']['system_dir'] . '/users.json';
    file_put_contents($userDBFile, json_encode($users, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

/* ============================================================
 * SESSION & PERMISSION HELPERS
 * ============================================================ */

/**
 * Checks if a user is currently authenticated.
 * @return bool True if a user is logged in, false otherwise.
 */
function isAuthenticated(): bool {
    return isset($_SESSION['user']);
}

/**
 * Retrieves the currently authenticated user's data from the session.
 * @return array|null The user's data array or null if not authenticated.
 */
function getCurrentUser(): ?array {
    return $_SESSION['user'] ?? null;
}

/**
 * Checks if the current user has the 'admin' role.
 * @return bool True if the user is an administrator.
 */
function isAdmin(): bool {
    $user = getCurrentUser();
    return $user && in_array('admin', $user['roles'] ?? [], true);
}

/**
 * Checks if the current user has a specific permission.
 * This is the central permission-checking function for the entire backend.
 * @param string $permission The permission string to check (e.g., 'filesystem.write.*').
 * @return bool True if the user has the permission.
 */
function hasPermission(string $permission): bool {
    if (!isAuthenticated()) {
        return false;
    }
    $permissions = $_SESSION['permissions'] ?? [];
    
    // Admins have all permissions implicitly.
    if (in_array('admin', $_SESSION['user']['roles'] ?? [])) {
        return true;
    }

    // Check for direct permission match.
    if (in_array($permission, $permissions, true)) {
        return true;
    }

    // Check for wildcard permissions (e.g., 'filesystem.write.*' grants 'filesystem.write.user').
    $parts = explode('.', $permission);
    while (count($parts) > 1) {
        array_pop($parts);
        $wildcard = implode('.', $parts) . '.*';
        if (in_array($wildcard, $permissions, true)) {
            return true;
        }
    }

    return false;
}

/**
 * Defines the complete set of permissions for a given user based on their roles.
 * @param array $user The user record.
 * @return array A list of permission strings.
 */
function getUserPermissions(array $user): array {
    // Base permissions for all authenticated users.
    $permissions = [
        'app.launch.*',
        'filesystem.read.*',
        'filesystem.write.user.*',
        'filesystem.delete.user.*',
        'sandbox.execute.user'
    ];
    
    // Add administrator permissions.
    if (in_array('admin', $user['roles'], true)) {
        $permissions = array_merge($permissions, [
            'filesystem.write.*',
            'filesystem.delete.*',
            'system.config.*',
            'system.admin.*',
            'sandbox.execute.*',
            'process.exec',
            'users.manage'
        ]);
    }
    
    // Add developer permissions.
    if (in_array('developer', $user['roles'], true)) {
        $permissions = array_merge($permissions, [
            'app.submit',
            'filesystem.write.apps.self',
            'filesystem.delete.apps.self',
            'filesystem.write.*',
			'process.exec'
        ]);
    }
    
    return array_unique($permissions);
}

/* ============================================================
 * API HANDLER
 * ============================================================ */

/**
 * Handles all incoming API requests for the authentication module.
 */
function handleAuthAPI(): array {
    global $config;

    $method = $_POST['method'] ?? $_GET['method'] ?? null;
    $params = $_POST['params'] ?? [];
    if (is_string($params)) {
        $params = json_decode($params, true) ?? [];
    }

    if ($method === null) {
        http_response_code(400);
        return ['success' => false, 'message' => 'Missing method'];
    }

    switch ($method) {
        case 'login':
            $username = $params['username'] ?? null;
            $password = $params['password'] ?? null;
            if (!$username || !$password) {
                return ['success' => false, 'message' => 'Missing username or password'];
            }
            
            $users = loadUserDB();
            foreach ($users as $i => $record) {
                if ($record['username'] === $username && password_verify($password, $record['password'])) {
                    $user = [
                        'username' => $record['username'],
                        'name' => $record['name'],
                        'roles' => $record['roles'] ?? ['user'],
                        'quota' => $record['quota'] ?? (10 * 1024 * 1024),
                    ];
                    $permissions = getUserPermissions($user);
                    
                    $_SESSION['user'] = $user;
                    $_SESSION['permissions'] = $permissions;
                    session_regenerate_id(true);
                    
                    $users[$i]['lastLogin'] = date('c');
                    saveUserDB($users);
                    
                    return ['success' => true, 'data' => ['user' => $user, 'permissions' => $permissions]];
                }
            }
            return ['success' => false, 'message' => 'Invalid username or password'];

        case 'register':
            if (!$config['security']['allow_developer_registration']) {
                return ['success' => false, 'message' => 'Developer registration is currently disabled.'];
            }
            $username = $params['username'] ?? null;
            $password = $params['password'] ?? null;
            $name = $params['name'] ?? null;

            if (!$username || !$password || !$name) {
                return ['success' => false, 'message' => 'Username, password, and name are required.'];
            }
            if (!preg_match('/^[a-zA-Z0-9_]{3,20}$/', $username)) {
                return ['success' => false, 'message' => 'Username must be 3-20 characters and contain only letters, numbers, and underscores.'];
            }
            if (strlen($password) < 8) {
                return ['success' => false, 'message' => 'Password must be at least 8 characters long.'];
            }

            $users = loadUserDB();
            foreach ($users as $user) {
                if ($user['username'] === $username) {
                    return ['success' => false, 'message' => 'Username is already taken.'];
                }
            }

            $newUser = [
                'username' => $username,
                'password' => password_hash($password, PASSWORD_DEFAULT),
                'roles' => ['developer'],
                'name' => htmlspecialchars($name),
                'quota' => 15 * 1024 * 1024,
                'lastLogin' => null
            ];
            $users[] = $newUser;
            saveUserDB($users);

            $userDir = $config['filesystem']['user_dir'] . '/' . $username;
            if (!is_dir($userDir)) {
                mkdir($userDir . '/Desktop', 0755, true);
                mkdir($userDir . '/Documents', 0755, true);
            }
            return ['success' => true, 'message' => 'Registration successful. You can now log in.'];

        case 'logout':
            // This provides a more robust logout suitable for HTTP and HTTPS.
            // First, unset all session data.
            $_SESSION = [];

            // Next, delete the session cookie from the browser.
            // This uses the session parameters (including the 'secure' flag set in bootstrap.php)
            // to ensure the cookie is properly targeted for deletion.
            if (ini_get("session.use_cookies")) {
                $params = session_get_cookie_params();
                setcookie(session_name(), '', time() - 42000,
                    $params["path"], $params["domain"],
                    $params["secure"], $params["httponly"]
                );
            }

            // Finally, destroy the session data on the server.
            session_destroy();
            
            return ['success' => true, 'message' => 'Logged out successfully'];

        case 'validateToken':
            $user = getCurrentUser();
            if ($user) {
                $permissions = $_SESSION['permissions'] ?? getUserPermissions($user);
                $_SESSION['permissions'] = $permissions; // Refresh permissions in session
                return ['success' => true, 'data' => ['valid' => true, 'user' => $user, 'permissions' => $permissions]];
            }
            return ['success' => true, 'data' => ['valid' => false]];
        
        default:
            return ['success' => false, 'message' => 'Invalid authentication method'];
    }
}