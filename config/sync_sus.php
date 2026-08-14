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
        // Enable only after the Synclab operator has approved the additive
        // public identifier fields documented in synclab_contract.php.
        'public_identifiers_enabled' => (bool) env('SYNC_SUS_SYNCLAB_PUBLIC_IDENTIFIERS_ENABLED', false),
        'catalog_path' => env('SYNC_SUS_SYNCLAB_CATALOG_PATH', database_path('data/synclab_exams.csv')),
        'base_url' => env('SYNC_SUS_SYNCLAB_BASE_URL', 'https://synclabweb.unisync.com.br'),
        'unit_code' => env('SYNC_SUS_SYNCLAB_UNIT_CODE'),
        'cnes' => env('SYNC_SUS_SYNCLAB_CNES'),
        'username' => env('SYNC_SUS_SYNCLAB_USERNAME'),
        'password' => env('SYNC_SUS_SYNCLAB_PASSWORD'),
        'queue' => env('SYNC_SUS_SYNCLAB_QUEUE', 'integrations'),
        'connect_timeout_seconds' => max(1, (int) env('SYNC_SUS_SYNCLAB_CONNECT_TIMEOUT', 5)),
        'timeout_seconds' => max(5, (int) env('SYNC_SUS_SYNCLAB_TIMEOUT', 30)),
        // Inbound result reception remains disabled until the Synclab operator
        // has configured the authenticated webhook for this installation.
        'results_enabled' => (bool) env('SYNC_SUS_SYNCLAB_RESULTS_ENABLED', false),
        // Off by default: the static bearer token alone proves possession but not
        // payload authenticity. Enable only after Synclab starts sending
        // X-Synclab-Result-Signature (HMAC-SHA256 of the raw body, same shared
        // token as key) — turning this on before that breaks real result intake.
        'require_result_signature' => (bool) env('SYNC_SUS_SYNCLAB_REQUIRE_RESULT_SIGNATURE', false),
        // Future outbound phases remain explicitly disabled.
        'samples_enabled' => false,
        'barcodes_enabled' => false,
    ],
];
