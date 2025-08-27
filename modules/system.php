<?php
/**
 * Genesis Operating System (GOS)
 * System Module
 */

/* ============================================================
 * SYSTEM MODULE
 * ============================================================ */

/**
 * Handle system API requests
 */
function handleSystemAPI() {
    global $config;
    $method = $_POST['method'] ?? $_GET['method'] ?? '';
    $params = $_POST['params'] ?? [];

    // Parse params if it's a JSON string
    if (is_string($params)) {
        $params = json_decode($params, true) ?? [];
    }
    
    switch ($method) {
        case 'getSystemInfo':
            return [
                'success' => true,
                'data' => [
                    'name' => $config['system']['name'],
                    'version' => $config['system']['version'],
                    'build' => $config['system']['build'],
                    'php_version' => PHP_VERSION,
                    'server' => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown',
                    'debug_mode' => $config['system']['debug'],
                    'uptime' => time() - $_SERVER['REQUEST_TIME_FLOAT'],
                    'use_local_filesystem' => $config['filesystem']['use_local'],
                    // ✅ FIXED: Expose the entire security configuration block to the frontend.
                    // This ensures that any security flags needed by the client are available.
                    'security'  => $config['security'] ?? []
                ]
            ];
            
        case 'updateConfig':
            // Only admins can update config
            if (!isAdmin()) {
                return ['success' => false, 'message' => 'Access denied'];
            }
            
            if (isset($params['key']) && isset($params['value'])) {
                $key = $params['key'];
                $value = $params['value'];
                
                // Update config (in a real system, would save to a file/database)
                $keys = explode('.', $key);
                $ref = &$config;
                
                foreach ($keys as $k) {
                    if (!isset($ref[$k])) {
                        return ['success' => false, 'message' => 'Invalid config key'];
                    }
                    $ref = &$ref[$k];
                }
                
                $ref = $value;
                
                return ['success' => true, 'message' => 'Configuration updated'];
            }
            break;

        case 'logError':
            global $errorLogFile;
            if (isset($params['message'])) {
                $details = $params['details'] ?? '';
                $entry = '['.date('Y-m-d H:i:s')."] JS {$params['message']}";
                if ($details) { $entry .= "\n$details"; }
                file_put_contents($errorLogFile, $entry."\n", FILE_APPEND);
                return ['success' => true];
            }
            break;

        case 'getLanguagePack':
            $lang = $params['lang'] ?? ($config['ui']['language'] ?? 'en');
            $pack = loadLanguagePack($lang);
            if (empty($pack) && $lang !== 'en') {
                $pack = loadLanguagePack('en');
            }
            return ['success' => true, 'data' => $pack];

		case 'runSelfTests':
			if (!isAdmin()) {
				return ['success' => false, 'message' => 'Access denied'];
			}

			// This captures any stray output to prevent corrupting the JSON response.
			ob_start();

			$tests = [];
			$tests['php_version_ok'] = version_compare(PHP_VERSION, '7.4', '>=');
			$tests['user_dir_writable'] = is_writable($config['filesystem']['user_dir']);
			$tests['error_log_writable'] = is_writable($config['filesystem']['system_dir'] . '/error.log') || is_writable($config['filesystem']['system_dir']);
			$tests['sessions_enabled'] = session_status() === PHP_SESSION_ACTIVE;

			// Clean the buffer before returning the result.
			ob_end_clean();

			return ['success' => true, 'data' => $tests];
    }
    
    return ['success' => false, 'message' => 'Invalid method or parameters'];
}

/* ============================================================
 * SYSTEM INITIALIZATION
 * ============================================================ */
function initializeSystem() {
    global $config;

    if ($config['filesystem']['use_local']) {
        $readmePath = $config['filesystem']['root_dir'] . '/README.txt';
        $userDBFile = $config['filesystem']['system_dir'] . '/users.json';
        $appSubmissionsFile = $config['filesystem']['system_dir'] . '/app_submissions.json';
        $errorLogFile = $config['filesystem']['system_dir'] . '/error.log';
        
        // Default README
        if (!file_exists($readmePath)) {
            $readme = "Welcome to Genesis OS. Login with admin/00EITA00 or guest/dfcGigtm8*.";
            file_put_contents($readmePath, $readme);
        }

        // Default user database
        if (!file_exists($userDBFile)) {
			$defaultUsers = [
				[
					'username' => 'admin',
					'password' => password_hash('00EITA00', PASSWORD_DEFAULT),
					'roles' => ['admin', 'developer'],
					'name' => 'Administrator',
					'quota' => 20 * 1024 * 1024,
					'lastLogin' => null
				],
				[
					'username' => 'guest',
					'password' => password_hash('dfcGigtm8*', PASSWORD_DEFAULT),
					'roles' => ['user'],
					'name' => 'Guest User',
					'quota' => 10 * 1024 * 1024,
					'lastLogin' => null
				]
			];
            file_put_contents($userDBFile, json_encode($defaultUsers, JSON_PRETTY_PRINT));
        }

        // Default submissions file
        if (!file_exists($appSubmissionsFile)) {
            file_put_contents($appSubmissionsFile, json_encode([], JSON_PRETTY_PRINT));
        }

        // Default error log
        if (!file_exists($errorLogFile)) {
            file_put_contents($errorLogFile, "");
        }

        // User home directories
		$users = loadUserDB();
		foreach ($users as $u) {
			$home = $config['filesystem']['user_dir'] . '/' . $u['username'];
			$desktop = $home . '/Desktop';
			$documents = $home . '/Documents';

			if (!is_dir($home)) mkdir($home, 0755, true);
			if (!is_dir($desktop)) mkdir($desktop, 0755, true);
			if (!is_dir($documents)) mkdir($documents, 0755, true);
		}
    }
}

/**
 * Load a language pack
 */
function loadLanguagePack(string $code): array {
    global $config;
    $languageDir = $config['filesystem']['system_dir'] . '/lang';
    if(!is_dir($languageDir)) mkdir($languageDir, 0755, true);

    $file = "$languageDir/lang_{$code}.json";
    if (!file_exists($file)) {
        if ($code === 'en') { // Create default english pack if not exists
            $defaultLang = [
                'login_heading' => 'Genesis OS Login', 'username' => 'Username', 'password' => 'Password',
                'login_button' => 'Log In', 'login_missing' => 'Username and password are required.'
            ];
            file_put_contents($file, json_encode($defaultLang, JSON_PRETTY_PRINT));
            return $defaultLang;
        }
        return [];
    }
    $json = json_decode(file_get_contents($file), true);
    return is_array($json) ? $json : [];
}
