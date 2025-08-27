<?php
/**
 * Genesis Operating System (GOS)
 * Users Module
 */

/**
 * Handle users API requests.
 * This provides endpoints for administrators to manage user accounts.
 */
function handleUsersAPI() {
    $method = $_POST['method'] ?? '';
    $params = $_POST['params'] ?? [];
    
    if (is_string($params)) {
        $params = json_decode($params, true) ?? [];
    }
    
    // Most methods require admin privileges.
    if (!isAdmin()) {
         // Exception: A user can change their own password.
        if ($method === 'setPassword') {
            $user = getCurrentUser();
            // Allow if the target user is the current user.
            if (isset($params['username']) && $user['username'] === $params['username']) {
                return setPassword($params);
            }
        }
        return ['success' => false, 'message' => 'Access denied'];
    }

    // Admin-only functions
    switch ($method) {
        case 'listUsers':
            $users = loadUserDB();
            // Never expose passwords via the API.
            foreach ($users as &$u) {
                unset($u['password']);
            }
            return ['success' => true, 'data' => $users];

        case 'createUser':
            return createUser($params);
			
        // ✅ NEW: Added case for getting a single user's details.
        case 'getUserDetails':
            return getUserDetails($params);

        case 'updateUser':
            return updateUser($params);

        case 'deleteUser':
            return deleteUser($params);
        
        case 'setPassword':
            return setPassword($params);

        default:
            return ['success' => false, 'message' => 'Invalid user management method.'];
    }
}

/**
 * ✅ NEW: Retrieves a single user's details. (Admin only)
 */
function getUserDetails(array $params): array {
    $username = $params['username'] ?? null;
    if (!$username) {
        return ['success' => false, 'message' => 'Username is required.'];
    }

    $users = loadUserDB();
    foreach ($users as $user) {
        if ($user['username'] === $username) {
            unset($user['password']); // Never expose the password hash.
            return ['success' => true, 'data' => $user];
        }
    }

    return ['success' => false, 'message' => 'User not found.'];
}


/**
 * Creates a new user. (Admin only)
 */
function createUser(array $params): array {
    $username = $params['username'] ?? null;
    $password = $params['password'] ?? null;
    $name = $params['name'] ?? 'New User';
    $roles = $params['roles'] ?? ['user'];

    if (!$username || !$password) {
        return ['success' => false, 'message' => 'Username and password are required.'];
    }
    if (!is_array($roles)) {
        return ['success' => false, 'message' => 'Roles must be an array.'];
    }

    $users = loadUserDB();
    foreach ($users as $user) {
        if ($user['username'] === $username) {
            return ['success' => false, 'message' => 'Username already exists.'];
        }
    }

    $users[] = [
        'username' => $username,
        'password' => password_hash($password, PASSWORD_DEFAULT),
        'roles' => $roles,
        'name' => htmlspecialchars($name),
        'quota' => 10 * 1024 * 1024, // Default 10MB
        'lastLogin' => null
    ];
    saveUserDB($users);
    
    // Also create their home directory
    global $config;
    $userDir = $config['filesystem']['user_dir'] . '/' . $username;
    if (!is_dir($userDir)) {
        mkdir($userDir, 0755, true);
        mkdir($userDir . '/Desktop', 0755, true);
        mkdir($userDir . '/Documents', 0755, true);
    }

    return ['success' => true, 'message' => 'User created successfully.'];
}

/**
 * Updates an existing user's details. (Admin only)
 */
function updateUser(array $params): array {
    $username = $params['username'] ?? null;
    if (!$username) {
        return ['success' => false, 'message' => 'Username is required.'];
    }

    $users = loadUserDB();
    $found = false;
    foreach ($users as $i => &$user) {
        if ($user['username'] === $username) {
            $found = true;
            // Update name if provided
            if (isset($params['name'])) {
                $user['name'] = htmlspecialchars($params['name']);
            }
            // Update quota if provided
            if (isset($params['quota'])) {
                $user['quota'] = intval($params['quota']);
            }
            // Update roles if provided, with protection for the admin user
            if (isset($params['roles']) && is_array($params['roles'])) {
                if ($user['username'] === 'admin' && !in_array('admin', $params['roles'])) {
                    return ['success' => false, 'message' => 'Cannot remove admin role from the primary administrator.'];
                }
                $user['roles'] = $params['roles'];
            }
            break;
        }
    }

    if ($found) {
        saveUserDB($users);
        return ['success' => true, 'message' => 'User updated successfully.'];
    }

    return ['success' => false, 'message' => 'User not found.'];
}

/**
 * Deletes a user. (Admin only)
 */
function deleteUser(array $params): array {
    $username = $params['username'] ?? null;
    if (!$username) {
        return ['success' => false, 'message' => 'Username is required.'];
    }
    if ($username === 'admin') {
        return ['success' => false, 'message' => 'Cannot delete the primary administrator account.'];
    }

    $users = loadUserDB();
    $initialCount = count($users);
    $users = array_filter($users, fn($user) => $user['username'] !== $username);

    if (count($users) < $initialCount) {
        saveUserDB(array_values($users));
        // Optional: Recursively delete user's directory for complete removal.
        // Be very careful with this in a production environment.
        // global $config;
        // $userDir = $config['filesystem']['user_dir'] . '/' . $username;
        // if (is_dir($userDir)) { /* recursive delete logic here */ }
        return ['success' => true, 'message' => 'User deleted successfully.'];
    }

    return ['success' => false, 'message' => 'User not found.'];
}

/**
 * Sets a new password for a user.
 * Can be called by an admin for any user, or by a user for themselves.
 */
function setPassword(array $params): array {
    $username = $params['username'] ?? null;
    $newPassword = $params['newPassword'] ?? null;
    $currentPassword = $params['currentPassword'] ?? null;

    if (!$username || !$newPassword) {
        return ['success' => false, 'message' => 'Username and new password are required.'];
    }
    if (strlen($newPassword) < 8) {
        return ['success' => false, 'message' => 'New password must be at least 8 characters.'];
    }

    $currentUser = getCurrentUser();
    $isSelfChange = $currentUser['username'] === $username;

    $users = loadUserDB();
    $found = false;
    foreach ($users as $i => &$user) {
        if ($user['username'] === $username) {
            $found = true;
            // If user is changing their own password, verify the current one.
            if ($isSelfChange && !isAdmin()) {
                if (!$currentPassword || !password_verify($currentPassword, $user['password'])) {
                    return ['success' => false, 'message' => 'Incorrect current password.'];
                }
            }
            // Admins can change passwords without the current one.
            $user['password'] = password_hash($newPassword, PASSWORD_DEFAULT);
            break;
        }
    }

    if ($found) {
        saveUserDB($users);
        return ['success' => true, 'message' => 'Password updated successfully.'];
    }

    return ['success' => false, 'message' => 'User not found.'];
}
