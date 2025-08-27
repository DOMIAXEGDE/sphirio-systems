<?php
/**
 * Genesis Operating System (GOS)
 * Configuration File
 */

return [
    'system' => [
        'name' => 'Genesis OS',
        'version' => '1.0.0',
        'build' => '20250704',
        'debug' => true
    ],
    'security' => [
        // ✅ NEW: Add a flag to control developer registration.
        'allow_developer_registration' => true,
        'sandbox_enabled' => true,
        'sandbox_memory_limit' => '64M',
        'sandbox_time_limit' => 5 // seconds
    ],
    'filesystem' => [
        'use_local' => true,
        'root_dir' => __DIR__ . '/gos_files',
        'user_dir' => __DIR__ . '/gos_files/users',
        'apps_dir' => __DIR__ . '/gos_files/apps',
        'system_dir' => __DIR__ . '/gos_files/system',
        'temp_dir' => __DIR__ . '/gos_files/temp'
    ],
    'ui' => [
        'theme' => 'vintage',
        'animations' => true,
        'fontSize' => 'medium',
        'language' => 'en'
    ]
];
