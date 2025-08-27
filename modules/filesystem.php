<?php
/**
 * Genesis Operating System (GOS)
 * Filesystem Module
 *
 * This module handles all API requests related to file and directory
 * operations, enforcing permissions based on the user's session.
 */

/* ============================================================
 * HELPER FUNCTIONS
 * ============================================================ */

/**
 * Resolves a virtual OS path to a real server path.
 *
 * @param string $virtualPath The virtual path (e.g., /users/admin/Desktop).
 * @return string The corresponding absolute path on the server's filesystem.
 */
function resolvePath(string $virtualPath): string {
    global $config;
    
    // Normalize the path to remove trailing slashes and resolve relative parts.
    $virtualPath = '/' . trim($virtualPath, '/');
    $parts = array_filter(explode('/', $virtualPath), 'strlen');
    $absolutes = [];
    
    foreach ($parts as $part) {
        if ($part === '.') {
            continue;
        }
        if ($part === '..') {
            array_pop($absolutes);
        } else {
            $absolutes[] = $part;
        }
    }
    
    $normalizedPath = '/' . implode('/', $absolutes);
    
    // Map the normalized virtual path to its real directory on the server.
    if ($config['filesystem']['use_local']) {
        if (strpos($normalizedPath, '/users/') === 0) return $config['filesystem']['user_dir'] . substr($normalizedPath, 6);
        if (strpos($normalizedPath, '/apps/') === 0) return $config['filesystem']['apps_dir'] . substr($normalizedPath, 5);
        if (strpos($normalizedPath, '/system/') === 0) return $config['filesystem']['system_dir'] . substr($normalizedPath, 7);
        if (strpos($normalizedPath, '/temp/') === 0) return $config['filesystem']['temp_dir'] . substr($normalizedPath, 5);
        return $config['filesystem']['root_dir'] . $normalizedPath;
    }
    
    return $normalizedPath;
}

/**
 * Checks if the current user has permission to access a given path.
 * This is the primary security gate for all filesystem operations.
 *
 * @param string $virtualPath The virtual path being accessed.
 * @param bool $writeAccess Set to true if the operation is destructive (write, delete).
 * @return bool True if access is granted, false otherwise.
 */
function canAccessPath(string $virtualPath, bool $writeAccess = false): bool {
    $user = getCurrentUser();
    if (!$user) return false;

    // Admins have universal access.
    if (isAdmin()) {
        return true;
    }

    // Handle write operations first.
    if ($writeAccess) {
        // Check for developer-specific write access to their own app folder.
        if (hasPermission('filesystem.write.apps.self')) {
            if (strpos($virtualPath, '/apps/' . $user['username'] . '/') === 0 || $virtualPath === '/apps/' . $user['username']) {
                return true;
            }
        }
        // Check for general user write access to their home directory.
        if (hasPermission('filesystem.write.user.*')) {
             if (strpos($virtualPath, '/users/' . $user['username']) === 0) {
                return true;
            }
        }
        // If no specific write permission matches, deny access.
        return false;
    }
    
    // Handle read operations.
    if (hasPermission('filesystem.read.*')) {
        return true;
    }
    
    return false;
}

/**
 * Retrieves a user's storage quota from the database.
 * @param string $username The user's username.
 * @return int The user's quota in bytes.
 */
function getUserQuota(string $username): int {
    $users = loadUserDB(); // This function is available from auth.php
    foreach ($users as $user) {
        if ($user['username'] === $username) {
            return $user['quota'] ?? 0;
        }
    }
    return 0; // Default to 0 if user not found
}

/**
 * Calculates the total disk usage for a specific user.
 * @param string $username The user's username.
 * @return int The total size of the user's files in bytes.
 */
function getUserDiskUsage(string $username): int {
    global $config;
    $userDir = $config['filesystem']['user_dir'] . '/' . $username;
    if (!is_dir($userDir)) {
        return 0;
    }

    $totalSize = 0;
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($userDir, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if ($file->isFile()) {
            $totalSize += $file->getSize();
        }
    }
    return $totalSize;
}

/* ============================================================
 * API HANDLER
 * ============================================================ */

/**
 * Handles all incoming API requests for the filesystem module.
 */
function handleFileSystemAPI() {
    global $config;
    $method = $_POST['method'] ?? '';
    $params = $_POST['params'] ?? [];
    if (is_string($params)) {
        $params = json_decode($params, true) ?? [];
    }
    
    $path = $params['path'] ?? null;
    // ✅ FIXED: Correctly check for methods that do not require a path.
    if ($path === null && !in_array($method, ['backupUserData', 'restoreUserData'])) {
        return ['success' => false, 'message' => 'Path parameter is missing.'];
    }

    $writeAccess = in_array($method, ['writeFile', 'deleteFile', 'createDirectory', 'restoreUserData']);

    // ✅ FIXED: Correctly check permissions for path-based methods.
    if ($path !== null && !canAccessPath($path, $writeAccess)) {
        return ['success' => false, 'message' => 'Access Denied'];
    }

    $useLocalStorage = !$config['filesystem']['use_local'];

    switch ($method) {
        case 'readFile':
            if ($useLocalStorage) {
                return ['success' => true, 'data' => ['path' => $path, 'useLocalStorage' => true]];
            }
            $realPath = resolvePath($path);
            if (file_exists($realPath) && is_file($realPath)) {
                return ['success' => true, 'data' => [
                    'content' => file_get_contents($realPath),
                    'size' => filesize($realPath),
                    'modified' => filemtime($realPath)
                ]];
            }
            return ['success' => false, 'message' => 'File not found'];

        case 'writeFile':
            if ($useLocalStorage) {
                return ['success' => true, 'data' => ['path' => $path, 'useLocalStorage' => true]];
            }
            $realPath = resolvePath($path);
            $user = getCurrentUser();
            $quota = getUserQuota($user['username']);
            $usageBefore = getUserDiskUsage($user['username']);
            $existingSize = file_exists($realPath) ? filesize($realPath) : 0;
            $newSize = strlen($params['content'] ?? '');
            if ($usageBefore - $existingSize + $newSize > $quota && !isAdmin()) {
                return ['success' => false, 'message' => 'User quota exceeded'];
            }
            $dir = dirname($realPath);
            if (!is_dir($dir)) mkdir($dir, 0755, true);
            if (file_put_contents($realPath, $params['content'] ?? '') !== false) {
                return ['success' => true, 'data' => [
                    'path' => $path, 'size' => filesize($realPath), 'modified' => filemtime($realPath)
                ]];
            }
            return ['success' => false, 'message' => 'Failed to write file'];

        case 'deleteFile':
            if ($useLocalStorage) {
                return ['success' => true, 'data' => ['path' => $path, 'useLocalStorage' => true]];
            }
            $realPath = resolvePath($path);
            if (file_exists($realPath)) {
                if (is_file($realPath)) {
                    if (unlink($realPath)) return ['success' => true];
                } elseif (is_dir($realPath)) {
                    if (count(scandir($realPath)) <= 2) {
                        if (rmdir($realPath)) return ['success' => true];
                    } else {
                        return ['success' => false, 'message' => 'Directory is not empty'];
                    }
                }
            }
            return ['success' => false, 'message' => 'File or directory not found'];

        case 'listDirectory':
            if ($useLocalStorage) {
                return ['success' => true, 'data' => ['path' => $path, 'useLocalStorage' => true]];
            }
            $realPath = resolvePath($path);
            if (is_dir($realPath)) {
                $items = array_diff(scandir($realPath), ['.', '..']);
                $files = []; $dirs = [];
                foreach ($items as $item) {
                    $itemPath = $realPath . '/' . $item;
                    $itemData = ['name' => $item, 'path' => rtrim($path, '/') . '/' . $item, 'modified' => filemtime($itemPath)];
                    if (is_file($itemPath)) {
                        $itemData['type'] = 'file';
                        $itemData['size'] = filesize($itemPath);
                        $itemData['extension'] = pathinfo($item, PATHINFO_EXTENSION);
                        $files[] = $itemData;
                    } else {
                        $itemData['type'] = 'directory';
                        $dirs[] = $itemData;
                    }
                }
                sort($dirs); sort($files);
                return ['success' => true, 'data' => array_merge($dirs, $files)];
            }
            return ['success' => false, 'message' => 'Directory not found'];

        case 'createDirectory':
            if ($useLocalStorage) {
                return ['success' => true, 'data' => ['path' => $path, 'useLocalStorage' => true]];
            }
            $realPath = resolvePath($path);
            if (!file_exists($realPath)) {
                if (mkdir($realPath, 0755, true)) return ['success' => true];
                return ['success' => false, 'message' => 'Failed to create directory'];
            }
            return ['success' => false, 'message' => 'Directory already exists'];

        case 'backupUserData':
            if ($useLocalStorage) {
                return ['success' => true, 'data' => ['useLocalStorage' => true]];
            }
            $user = getCurrentUser();
            $base = $config['filesystem']['user_dir'] . '/' . $user['username'];
            $files = [];
            if (file_exists($base)) {
                $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
                foreach ($iterator as $file) {
                    $rel = substr($file->getPathname(), strlen($base));
                    $vpath = '/users/' . $user['username'] . $rel;
                    if ($file->isDir()) {
                        $files[] = ['path' => $vpath, 'type' => 'directory'];
                    } else {
                        $files[] = ['path' => $vpath, 'type' => 'file', 'content' => base64_encode(file_get_contents($file->getPathname()))];
                    }
                }
            }
            return ['success' => true, 'data' => $files];

        case 'restoreUserData':
            if ($useLocalStorage) {
                return ['success' => true, 'data' => ['useLocalStorage' => true]];
            }
            if (isset($params['files']) && is_array($params['files'])) {
                $user = getCurrentUser();
                $base = $config['filesystem']['user_dir'] . '/' . $user['username'];
                foreach ($params['files'] as $item) {
                    $virtualPath = $item['path'] ?? '';
                    if (strpos($virtualPath, '/users/' . $user['username']) !== 0) continue;
                    $real = resolvePath($virtualPath);
                    if ($item['type'] === 'directory') {
                        if (!file_exists($real)) mkdir($real, 0755, true);
                    } elseif ($item['type'] === 'file') {
                        $quota = getUserQuota($user['username']);
                        $usage = getUserDiskUsage($user['username']);
                        $data = base64_decode($item['content']);
                        $existing = file_exists($real) ? filesize($real) : 0;
                        if ($usage - $existing + strlen($data) > $quota && !isAdmin()) {
                            return ['success' => false, 'message' => 'User quota exceeded'];
                        }
                        $dir = dirname($real);
                        if (!file_exists($dir)) mkdir($dir, 0755, true);
                        file_put_contents($real, $data);
                    }
                }
                return ['success' => true];
            }
            return ['success' => false, 'message' => 'No files provided for restore.'];

        default:
            return ['success' => false, 'message' => 'Invalid filesystem method'];
    }
}
