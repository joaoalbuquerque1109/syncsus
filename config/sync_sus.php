<?php

declare(strict_types=1);

return [
    'seed_demo_data' => (bool) env('SYNC_SUS_SEED_DEMO', false),
    'admin' => [
        'name' => env('SYNC_SUS_ADMIN_NAME'),
        'email' => env('SYNC_SUS_ADMIN_EMAIL'),
        'password' => env('SYNC_SUS_ADMIN_PASSWORD'),
        'access_code' => mb_strtoupper((string) env('SYNC_SUS_ADMIN_ACCESS_CODE', 'ADMIN')),
    ],
    'backup_retention_days' => (int) env('SYNC_SUS_BACKUP_RETENTION_DAYS', 14),
    'backup_path' => env('SYNC_SUS_BACKUP_PATH', storage_path('app/backups')),
    'panel_poll_seconds' => (int) env('SYNC_SUS_PANEL_POLL_SECONDS', 2),
    'panel_heartbeat_seconds' => max(5, (int) env('SYNC_SUS_PANEL_HEARTBEAT_SECONDS', 15)),
    'queue_poll_seconds' => max(2, (int) env('SYNC_SUS_QUEUE_POLL_SECONDS', 5)),
    'dashboard_poll_seconds' => (int) env('SYNC_SUS_DASHBOARD_POLL_SECONDS', 15),
    'performance_cache' => [
        'enabled' => (bool) env('SYNC_SUS_PERFORMANCE_CACHE_ENABLED', true),
        'dashboard_seconds' => max(1, (int) env('SYNC_SUS_DASHBOARD_CACHE_SECONDS', 3)),
        'navigation_seconds' => max(5, (int) env('SYNC_SUS_NAVIGATION_CACHE_SECONDS', 60)),
        'panel_configuration_seconds' => max(5, (int) env('SYNC_SUS_PANEL_CONFIGURATION_CACHE_SECONDS', 60)),
    ],
    'vite_dev_server_origins' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env(
            'SYNC_SUS_VITE_DEV_SERVER_ORIGINS',
            'http://localhost:5173,http://127.0.0.1:5173,http://[::1]:5173',
        )),
    ))),
    'require_https' => (bool) env('SYNC_SUS_REQUIRE_HTTPS', false),
    'trusted_hosts' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('SYNC_SUS_TRUSTED_HOSTS', 'localhost,127.0.0.1')),
    ))),
    'trusted_proxies' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('SYNC_SUS_TRUSTED_PROXIES', '')),
    ))),
    'max_concurrent_sessions' => max(1, (int) env('SYNC_SUS_MAX_CONCURRENT_SESSIONS', 1)),
    'synclab' => [
        'enabled' => (bool) env('SYNC_SUS_SYNCLAB_ENABLED', false),
        'connect_timeout_seconds' => max(1, (int) env('SYNC_SUS_SYNCLAB_CONNECT_TIMEOUT', 5)),
        'timeout_seconds' => max(5, (int) env('SYNC_SUS_SYNCLAB_TIMEOUT', 30)),
    ],
];
