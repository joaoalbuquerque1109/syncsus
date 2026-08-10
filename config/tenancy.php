<?php

use Pdo\Mysql;

$profiles = json_decode((string) env('TENANT_DATABASE_PROFILES', '{}'), true);

return [
    'legacy_connection' => env('TENANT_LEGACY_CONNECTION', env('DB_CONNECTION', 'sqlite')),
    'database_profiles' => is_array($profiles) ? $profiles : [],
    'native_provisioning' => [
        'queue' => env('TENANT_PROVISIONING_QUEUE', 'tenant-provisioning'),
        'worker_enabled' => env('TENANT_PROVISIONING_WORKER', false),
        'runtime_host' => env('TENANT_RUNTIME_ACCOUNT_HOST', ''),
        'runtime_connection' => [
            'driver' => 'mysql',
            'url' => null,
            'host' => env('TENANT_RUNTIME_DB_HOST', env('DB_HOST', '127.0.0.1')),
            'port' => env('TENANT_RUNTIME_DB_PORT', env('DB_PORT', '3306')),
            'unix_socket' => env('TENANT_RUNTIME_DB_SOCKET', env('DB_SOCKET', '')),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => null,
            'options' => extension_loaded('pdo_mysql') ? array_filter([
                (PHP_VERSION_ID >= 80500 ? Mysql::ATTR_SSL_CA : PDO::MYSQL_ATTR_SSL_CA) => env('TENANT_RUNTIME_SSL_CA'),
            ]) : [],
        ],
        // These values belong exclusively to the isolated provisioning worker.
        'administrative_connection' => [
            'driver' => 'mysql',
            'url' => null,
            'host' => env('TENANT_PROVISIONING_HOST'),
            'port' => env('TENANT_PROVISIONING_PORT', '3306'),
            'database' => env('TENANT_PROVISIONING_DATABASE', 'mysql'),
            'username' => env('TENANT_PROVISIONING_USERNAME'),
            'password' => env('TENANT_PROVISIONING_PASSWORD'),
            'unix_socket' => env('TENANT_PROVISIONING_SOCKET', ''),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => null,
            'options' => extension_loaded('pdo_mysql') ? array_filter([
                (PHP_VERSION_ID >= 80500 ? Mysql::ATTR_SSL_CA : PDO::MYSQL_ATTR_SSL_CA) => env('TENANT_PROVISIONING_SSL_CA'),
            ]) : [],
        ],
    ],
];
