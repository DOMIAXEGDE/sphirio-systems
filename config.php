<?php
/**
 * Genesis Operating System (GOS)
 * Configuration File
 *
 * This file should ONLY return the configuration array.
 * It should not execute any logic or have any side effects.
 */

return [
    'system' => [
        'name' => 'Genesis OS',
        'version' => '1.0.0',
        'build' => '20250704',
        'debug' => true
    ],
    'security' => [
        'admin_users' => ['admin'],
        // NEW: Add a roles configuration for developers
        'developer_users' => ['admin', 'developer'], // 'admin' is also a developer
        'sandbox_enabled' => true,
        'sandbox_memory_limit' => '64M',
        'sandbox_time_limit' => 5 // seconds
    ],
    'filesystem' => [
        'use_local' => true, // Set to false to use localStorage only
        'root_dir' => __DIR__ . '/gos_files',
        'user_dir' => __DIR__ . '/gos_files/users',
        // We removed the APP_DIR constant and just define the path directly.
        'apps_dir' => __DIR__ . '/gos_files/apps',
        'system_dir' => __DIR__ . '/gos_files/system',
        'temp_dir' => __DIR__ . '/gos_files/temp'
    ],
    'ui' => [
        'theme' => 'vintage', // vintage, dark, light, blue
        'animations' => true,
        'fontSize' => 'medium',
        'language' => 'en'
    ]
];