<?php
/**
 * Genesis Operating System (GOS)
 * Filesystem Module
 */

// Functions:
// - handleFileSystemAPI

/* ============================================================
 *  FILESYSTEM MODULE
 * ============================================================ */

/**
 * Handle filesystem API requests
 */
function handleFileSystemAPI() {
    global $config;
    $method = $_POST['method'] ?? '';
    $params = $_POST['params'] ?? [];
    
    // Parse params if it's a JSON string
    if (is_string($params)) {
        $params = json_decode($params, true) ?? [];
    }
    
    // Convert virtual path to real path
    if (!function_exists('resolvePath')) {
        function resolvePath($virtualPath) {
            global $config;
            
            // Normalize the path
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
            
            // Map to real paths if using local filesystem
            if ($config['filesystem']['use_local']) {
                if (strpos($normalizedPath, '/users/') === 0) {
                    return $config['filesystem']['user_dir'] . substr($normalizedPath, 6);
                } elseif (strpos($normalizedPath, '/apps/') === 0) {
                    return $config['filesystem']['apps_dir'] . substr($normalizedPath, 5);
                } elseif (strpos($normalizedPath, '/system/') === 0) {
                    return $config['filesystem']['system_dir'] . substr($normalizedPath, 7);
                } elseif (strpos($normalizedPath, '/temp/') === 0) {
                    return $config['filesystem']['temp_dir'] . substr($normalizedPath, 5);
                } else {
                    return $config['filesystem']['root_dir'] . $normalizedPath;
                }
            }
            
            // Just return the normalized path for localStorage use
            return $normalizedPath;
        }
    }
    
    // Security check - only admins can access files outside their user directory
    if (!function_exists('canAccessPath')) {
        function canAccessPath($virtualPath, $writeAccess = false) {
            $user = getCurrentUser();
            
            // Admins can access anything
            if (isAdmin()) {
                return true;
            }
            
            // Users can read from the apps and system directories
            if (!$writeAccess && (strpos($virtualPath, '/apps/') === 0 || strpos($virtualPath, '/system/') === 0)) {
                return true;
            }
            
            // Users can only access their own user directory for writing
            if (strpos($virtualPath, '/users/' . $user['username']) === 0) {
                return true;
            }
            
            return false;
        }
    }
    
    switch ($method) {
        case 'readFile':
            if (isset($params['path'])) {
                $virtualPath = $params['path'];
                
                if (!canAccessPath($virtualPath)) {
                    return ['success' => false, 'message' => 'Access denied'];
                }
                
                if ($config['filesystem']['use_local']) {
                    $realPath = resolvePath($virtualPath);
                    
                    if (file_exists($realPath) && is_file($realPath)) {
                        $content = file_get_contents($realPath);
                        return [
                            'success' => true,
                            'data' => [
                                'content' => $content,
                                'size' => filesize($realPath),
                                'modified' => filemtime($realPath)
                            ]
                        ];
                    }
                    
                    return ['success' => false, 'message' => 'File not found'];
                } else {
                    // For client-side filesystem, just return success
                    // The client will handle the actual file read from localStorage
                    return [
                        'success' => true,
                        'data' => [
                            'path' => $virtualPath,
                            'useLocalStorage' => true
                        ]
                    ];
                }
            }
            break;
            
        case 'writeFile':
            if (isset($params['path']) && isset($params['content'])) {
                $virtualPath = $params['path'];

                if (!canAccessPath($virtualPath, true)) {
                    return ['success' => false, 'message' => 'Access denied'];
                }

                if ($config['filesystem']['use_local']) {
                    $user = getCurrentUser();
                    $quota = getUserQuota($user['username']);
                    $usageBefore = getUserDiskUsage($user['username']);

                    $realPath = resolvePath($virtualPath);
                    $dir = dirname($realPath);

                    if (!file_exists($dir)) {
                        mkdir($dir, 0755, true);
                    }

                    $existingSize = file_exists($realPath) ? filesize($realPath) : 0;
                    $newSize = strlen($params['content']);

                    if ($usageBefore - $existingSize + $newSize > $quota && !isAdmin()) {
                        return ['success' => false, 'message' => 'User quota exceeded'];
                    }

                    if (file_put_contents($realPath, $params['content']) !== false) {
                        return [
                            'success' => true,
                            'data' => [
                                'path' => $virtualPath,
                                'size' => filesize($realPath),
                                'modified' => filemtime($realPath)
                            ]
                        ];
                    }

                    return ['success' => false, 'message' => 'Failed to write file'];
                } else {

                    // For client-side filesystem, just return success
                    // The client will handle the actual file write to localStorage
                    return [
                        'success' => true,
                        'data' => [
                            'path' => $virtualPath,
                            'useLocalStorage' => true
                        ]
                    ];
                }
            }
            break;
            
        case 'deleteFile':
            if (isset($params['path'])) {
                $virtualPath = $params['path'];
                
                if (!canAccessPath($virtualPath, true)) {
                    return ['success' => false, 'message' => 'Access denied'];
                }
                
                if ($config['filesystem']['use_local']) {
                    $realPath = resolvePath($virtualPath);
                    
                    if (file_exists($realPath)) {
                        if (is_file($realPath)) {
                            if (unlink($realPath)) {
                                return ['success' => true];
                            }
                        } else if (is_dir($realPath)) {
                            // Only delete empty directories
                            if (count(scandir($realPath)) <= 2) { // . and ..
                                if (rmdir($realPath)) {
                                    return ['success' => true];
                                }
                            } else {
                                return ['success' => false, 'message' => 'Directory is not empty'];
                            }
                        }
                        
                        return ['success' => false, 'message' => 'Failed to delete file or directory'];
                    }
                    
                    return ['success' => false, 'message' => 'File or directory not found'];
                } else {
                    // For client-side filesystem, just return success
                    // The client will handle the actual file deletion from localStorage
                    return [
                        'success' => true,
                        'data' => [
                            'path' => $virtualPath,
                            'useLocalStorage' => true
                        ]
                    ];
                }
            }
            break;
            
        case 'listDirectory':
            if (isset($params['path'])) {
                $virtualPath = $params['path'];
                
                if (!canAccessPath($virtualPath)) {
                    return ['success' => false, 'message' => 'Access denied'];
                }
                
                if ($config['filesystem']['use_local']) {
                    $realPath = resolvePath($virtualPath);
                    
                    if (file_exists($realPath) && is_dir($realPath)) {
                        $items = scandir($realPath);
                        $items = array_diff($items, ['.', '..']);
                        
                        $files = [];
                        $dirs = [];
                        
                        foreach ($items as $item) {
                            $fullPath = $realPath . '/' . $item;
                            $itemData = [
                                'name' => $item,
                                'path' => $virtualPath . '/' . $item,
                                'modified' => filemtime($fullPath)
                            ];
                            
                            if (is_file($fullPath)) {
                                $itemData['type'] = 'file';
                                $itemData['size'] = filesize($fullPath);
                                $itemData['extension'] = pathinfo($item, PATHINFO_EXTENSION);
                                $files[] = $itemData;
                            } else {
                                $itemData['type'] = 'directory';
                                $dirs[] = $itemData;
                            }
                        }
                        
                        // Sort directories first, then files
                        usort($dirs, function($a, $b) { return strcmp($a['name'], $b['name']); });
                        usort($files, function($a, $b) { return strcmp($a['name'], $b['name']); });
                        
                        return [
                            'success' => true,
                            'data' => array_merge($dirs, $files)
                        ];
                    }
                    
                    return ['success' => false, 'message' => 'Directory not found'];
                } else {
                    // For client-side filesystem, just return success
                    // The client will handle the actual directory listing from localStorage
                    return [
                        'success' => true,
                        'data' => [
                            'path' => $virtualPath,
                            'useLocalStorage' => true
                        ]
                    ];
                }
            }
            break;
            
        case 'createDirectory':
            if (isset($params['path'])) {
                $virtualPath = $params['path'];
                
                if (!canAccessPath($virtualPath, true)) {
                    return ['success' => false, 'message' => 'Access denied'];
                }
                
                if ($config['filesystem']['use_local']) {
                    $realPath = resolvePath($virtualPath);
                    
                    if (!file_exists($realPath)) {
                        if (mkdir($realPath, 0755, true)) {
                            return ['success' => true];
                        }
                        
                        return ['success' => false, 'message' => 'Failed to create directory'];
                    }
                    
                    return ['success' => false, 'message' => 'Directory already exists'];
                } else {
                    // For client-side filesystem, just return success
                    // The client will handle the actual directory creation in localStorage
                    return [
                        'success' => true,
                        'data' => [
                            'path' => $virtualPath,
                            'useLocalStorage' => true
                        ]
                    ];

                }
            }
            break;

        case 'backupUserData':
            $user = getCurrentUser();
            if ($config['filesystem']['use_local']) {
                $base = $config['filesystem']['user_dir'] . '/' . $user['username'];
                $files = [];
                if (file_exists($base)) {
                    $iterator = new RecursiveIteratorIterator(
                        new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS),
                        RecursiveIteratorIterator::SELF_FIRST
                    );
                    foreach ($iterator as $file) {
                        $rel = substr($file->getPathname(), strlen($base));
                        $vpath = '/users/' . $user['username'] . $rel;
                        if ($file->isDir()) {
                            $files[] = ['path' => $vpath, 'type' => 'directory'];
                        } else {
                            $files[] = [
                                'path' => $vpath,
                                'type' => 'file',
                                'content' => base64_encode(file_get_contents($file->getPathname()))
                            ];
                        }
                    }
                }
                return ['success' => true, 'data' => $files];
            } else {
                return ['success' => true, 'data' => ['useLocalStorage' => true]];
            }

        case 'restoreUserData':
            if (isset($params['files']) && is_array($params['files'])) {
                $user = getCurrentUser();
                if ($config['filesystem']['use_local']) {
                    $base = $config['filesystem']['user_dir'] . '/' . $user['username'];
                    foreach ($params['files'] as $item) {
                        $virtualPath = $item['path'] ?? '';
                        if (strpos($virtualPath, '/users/' . $user['username']) !== 0) {
                            continue; // skip invalid paths
                        }
                        $real = resolvePath($virtualPath);
                        if ($item['type'] === 'directory') {
                            if (!file_exists($real)) {
                                mkdir($real, 0755, true);
                            }
                        } elseif ($item['type'] === 'file') {
                            $quota = getUserQuota($user['username']);
                            $usage = getUserDiskUsage($user['username']);
                            $data = base64_decode($item['content']);
                            $existing = file_exists($real) ? filesize($real) : 0;
                            if ($usage - $existing + strlen($data) > $quota && !isAdmin()) {
                                return ['success' => false, 'message' => 'User quota exceeded'];
                            }
                            $dir = dirname($real);
                            if (!file_exists($dir)) {
                                mkdir($dir, 0755, true);
                            }
                            file_put_contents($real, $data);
                        }
                    }
                    return ['success' => true];
                } else {
                    return ['success' => true, 'data' => ['useLocalStorage' => true]];
                }
            }
            break;
    }

    return ['success' => false, 'message' => 'Invalid method or parameters'];
}