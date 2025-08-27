<?php
/**
 * Genesis Operating System (GOS)
 * Sandbox Module
 */
/* ============================================================
 *  SANDBOX MODULE
 * ============================================================ */

/**
 * Handle sandbox API requests
 */
function handleSandboxAPI() {
    global $config;
    
    // Use the JSON shim that's already processed the request body
    $method = $_POST['method'] ?? null;
    $params = $_POST['params'] ?? [];

    // Parse params if it's a JSON string
    if (is_string($params)) {
        $params = json_decode($params, true) ?? [];
    }
    
    // Only admins or users with specific permissions can execute sandboxed code
    $user = getCurrentUser();
    $allowedSandbox = isAdmin() || (isset($params['context']) && $params['context'] === 'user');
    
    if (!$allowedSandbox) {
        return ['success' => false, 'message' => 'Access denied for sandbox execution'];
    }
    
    switch ($method) {
        case 'execute':
            if (isset($params['code']) && is_string($params['code'])) {
                $code = $params['code'];
                $context = $params['context'] ?? 'default';
                
                // Set strict limits for sandboxed code
                $memoryLimit = $config['security']['sandbox_memory_limit'];
                $timeLimit = $config['security']['sandbox_time_limit'];
                
                // Create a temporary file to execute
                $tempFile = tempnam($config['filesystem']['temp_dir'], 'sandbox_');
                
                // Sanitize the code - make sure it can't break out of the sandbox
                $code = str_replace('?>', '', $code); // Remove PHP closing tag
                
                // Use a safer approach - create a separate file for the user code
                $userCodeFile = tempnam($config['filesystem']['temp_dir'], 'user_code_');
                file_put_contents($userCodeFile, $code);
                
                // Create a wrapper that catches errors and limits execution
                $wrapper = <<<'EOT'
<?php
// Set resource limits
ini_set('memory_limit', '{$memoryLimit}');
set_time_limit({$timeLimit});

// Capture output
ob_start();

// Define safe context variables if needed
$context = '{$context}';
$user = '{{USERNAME}}';

// Run the user code in a try-catch block
try {
    // Include the user code file
    include '{$userCodeFile}';
} catch (Throwable $e) {
    echo "Error: " . $e->getMessage();
}

// Get and clean output
$output = ob_get_clean();
echo json_encode(['output' => $output]);
EOT;

                // Make the wrapper template safer by replacing variables
                $wrapper = str_replace(
                    ['{$memoryLimit}', '{$timeLimit}', '{$context}', '{{USERNAME}}', '{$userCodeFile}'],
                    [$memoryLimit, $timeLimit, $context, ($user['username'] ?? 'anonymous'), $userCodeFile],
                    $wrapper
                );
                
                // Write the wrapper to the temp file
                file_put_contents($tempFile, $wrapper);
                
                // Execute in a separate process
                $descriptorspec = [
                    0 => ["pipe", "r"],  // stdin
                    1 => ["pipe", "w"],  // stdout
                    2 => ["pipe", "w"]   // stderr
                ];
                
                // Use disable_functions in the command line for extra safety
                $disableFunctions = 'system,exec,shell_exec,passthru,proc_open,popen,curl_exec,fsockopen';
                $process = proc_open("php -d disable_functions=$disableFunctions $tempFile", $descriptorspec, $pipes);
                
                if (is_resource($process)) {
                    // Close stdin
                    fclose($pipes[0]);
                    
                    // Get output
                    $output = stream_get_contents($pipes[1]);
                    $errors = stream_get_contents($pipes[2]);
                    
                    // Close pipes
                    fclose($pipes[1]);
                    fclose($pipes[2]);
                    
                    // Close process
                    $exitCode = proc_close($process);
                    
                    // Clean up temp files
                    @unlink($tempFile);
                    @unlink($userCodeFile);
                    
                    // Parse the output (should be JSON)
                    $result = json_decode($output, true);
                    
                    if ($result !== null) {
                        return [
                            'success' => true,
                            'data' => [
                                'output' => $result['output'],
                                'errors' => $errors,
                                'exitCode' => $exitCode
                            ]
                        ];
                    }
                    
                    return [
                        'success' => false,
                        'message' => 'Failed to parse sandbox output',
                        'data' => [
                            'raw' => $output,
                            'errors' => $errors,
                            'exitCode' => $exitCode
                        ]
                    ];
                }
                
                return ['success' => false, 'message' => 'Failed to create sandbox process'];
            }
            break;
    }
    
    return ['success' => false, 'message' => 'Invalid method or parameters'];
}