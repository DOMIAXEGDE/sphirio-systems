<?php
/**
 * Genesis Operating System (GOS)
 * Users Module
 */
/* ============================================================
 *  USERS MODULE
 * ============================================================ */

/**
 * Handle users API requests
 */
function handleUsersAPI() {
    $method = $_POST['method'] ?? '';
    $params = $_POST['params'] ?? [];
    
    // Parse params if it's a JSON string
    if (is_string($params)) {
        $params = json_decode($params, true) ?? [];
    }
    
    // Only admins can access user data
    if (!isAdmin() && $method !== 'getCurrentUser') {
        return ['success' => false, 'message' => 'Access denied'];
    }
    
    switch ($method) {
        case 'getCurrentUser':
            $user = getCurrentUser();
            if ($user) {
                return ['success' => true, 'data' => $user];
            }
            return ['success' => false, 'message' => 'Not authenticated'];
            
        case 'listUsers':
            $users = loadUserDB();
            foreach ($users as &$u) {
                unset($u['password']);
            }

            return ['success' => true, 'data' => $users];
    }

    return ['success' => false, 'message' => 'Invalid method or parameters'];
}