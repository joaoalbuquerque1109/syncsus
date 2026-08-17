<?php

use Illuminate\Support\Str;
use Pdo\Mysql;

return [

    /*
    |--------------------------------------------------------------------------
    | Default Database Connection Name
    |--------------------------------------------------------------------------
    |
    | Here you may specify which of the database connections below you wish
    | to use as your default connection for database operations. This is
    | the connection which will be utilized unless another connection
    | is explicitly specified when you execute a query / statement.
    |
    */

    'default' => env('DB_CONNECTION', 'sqlite'),

    /*
    |--------------------------------------------------------------------------
    | Database Connections
    |--------------------------------------------------------------------------
    |
    | Below are all of the database connections defined for your application.
    | An example configuration is provided for each database system which
    | is supported by Laravel. You're free to add / remove connections.
    |
    */

    'connections' => [

        'sqlite' => [
            'driver' => 'sqlite',
            'url' => env('DB_URL'),
            'database' => env('DB_DATABASE', database_path('database.sqlite')),
            'prefix' => '',
            'foreign_key_constraints' => env('DB_FOREIGN_KEYS', true),
            'busy_timeout' => env('DB_BUSY_TIMEOUT'),
            'journal_mode' => env('DB_JOURNAL_MODE'),
            'synchronous' => env('DB_SYNCHRONOUS'),
            'transaction_mode' => env('DB_TRANSACTION_MODE', 'DEFERRED'),
        ],

        'mysql' => [
            'driver' => 'mysql',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '3306'),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'unix_socket' => env('DB_SOCKET', ''),
            'charset' => env('DB_CHARSET', 'utf8mb4'),
            'collation' => env('DB_COLLATION', 'utf8mb4_unicode_ci'),
            // Sem isso, a sessão MySQL assume o time_zone padrão do servidor (o
            // container roda em UTC) enquanto o PHP manda horário já formatado em
            // America/Fortaleza (config('app.timezone')) — colunas TIMESTAMP
            // interpretam/convertem errado, gerando um desvio de 3h entre o que a
            // aplicação grava e o que fica realmente armazenado.
            'timezone' => env('DB_TIMEZONE', '-03:00'),
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => null,
            'options' => extension_loaded('pdo_mysql') ? array_filter([
                (PHP_VERSION_ID >= 80500 ? Mysql::ATTR_SSL_CA : PDO::MYSQL_ATTR_SSL_CA) => env('MYSQL_ATTR_SSL_CA'),
            ]) : [],
        ],

        'mariadb' => [
            'driver' => 'mariadb',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '3306'),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'unix_socket' => env('DB_SOCKET', ''),
            'charset' => env('DB_CHARSET', 'utf8mb4'),
            'collation' => env('DB_COLLATION', 'utf8mb4_unicode_ci'),
            'timezone' => env('DB_TIMEZONE', '-03:00'),
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => null,
            'options' => extension_loaded('pdo_mysql') ? array_filter([
                (PHP_VERSION_ID >= 80500 ? Mysql::ATTR_SSL_CA : PDO::MYSQL_ATTR_SSL_CA) => env('MYSQL_ATTR_SSL_CA'),
            ]) : [],
        ],

        'pgsql' => [
            'driver' => 'pgsql',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '5432'),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => env('DB_CHARSET', 'utf8'),
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'sslmode' => env('DB_SSLMODE', 'prefer'),
        ],

        'sqlsrv' => [
            'driver' => 'sqlsrv',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', 'localhost'),
            'port' => env('DB_PORT', '1433'),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => env('DB_CHARSET', 'utf8'),
            'prefix' => '',
            'prefix_indexes' => true,
            // 'encrypt' => env('DB_ENCRYPT', 'yes'),
            // 'trust_server_certificate' => env('DB_TRUST_SERVER_CERTIFICATE', 'false'),
        ],

        /*
        | Phase 0: Core and tenant connections are logical routing boundaries only.
        | They intentionally point to the same physical database as the default
        | connection. TenantConnectionManager owns the tenant template lifecycle.
        */
        'core' => [
            'driver' => env('CORE_DB_CONNECTION', env('DB_CONNECTION', 'sqlite')),
            'url' => env('CORE_DB_URL', env('DB_URL')),
            'host' => env('CORE_DB_HOST', env('DB_HOST', '127.0.0.1')),
            'port' => env('CORE_DB_PORT', env('DB_PORT', '3306')),
            'database' => env('CORE_DB_DATABASE', env('DB_DATABASE', database_path('database.sqlite'))),
            'username' => env('CORE_DB_USERNAME', env('DB_USERNAME', 'root')),
            'password' => env('CORE_DB_PASSWORD', env('DB_PASSWORD', '')),
            'unix_socket' => env('CORE_DB_SOCKET', env('DB_SOCKET', '')),
            'charset' => env('CORE_DB_CHARSET', env('DB_CHARSET', 'utf8mb4')),
            'collation' => env('CORE_DB_COLLATION', env('DB_COLLATION', 'utf8mb4_unicode_ci')),
            'timezone' => env('CORE_DB_TIMEZONE', env('DB_TIMEZONE', '-03:00')),
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => null,
            'foreign_key_constraints' => env('CORE_DB_FOREIGN_KEYS', env('DB_FOREIGN_KEYS', true)),
            'busy_timeout' => env('DB_BUSY_TIMEOUT'),
            'journal_mode' => env('DB_JOURNAL_MODE'),
            'synchronous' => env('DB_SYNCHRONOUS'),
            'transaction_mode' => env('DB_TRANSACTION_MODE', 'DEFERRED'),
            'options' => extension_loaded('pdo_mysql') ? array_filter([
                (PHP_VERSION_ID >= 80500 ? Mysql::ATTR_SSL_CA : PDO::MYSQL_ATTR_SSL_CA) => env('MYSQL_ATTR_SSL_CA'),
            ]) : [],
        ],

        'tenant_template' => [
            'driver' => env('DB_CONNECTION', 'sqlite'),
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '3306'),
            'database' => env('DB_DATABASE', database_path('database.sqlite')),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'unix_socket' => env('DB_SOCKET', ''),
            'charset' => env('DB_CHARSET', 'utf8mb4'),
            'collation' => env('DB_COLLATION', 'utf8mb4_unicode_ci'),
            'timezone' => env('DB_TIMEZONE', '-03:00'),
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => null,
            'foreign_key_constraints' => env('DB_FOREIGN_KEYS', true),
            'busy_timeout' => env('DB_BUSY_TIMEOUT'),
            'journal_mode' => env('DB_JOURNAL_MODE'),
            'synchronous' => env('DB_SYNCHRONOUS'),
            'transaction_mode' => env('DB_TRANSACTION_MODE', 'DEFERRED'),
            'options' => extension_loaded('pdo_mysql') ? array_filter([
                (PHP_VERSION_ID >= 80500 ? Mysql::ATTR_SSL_CA : PDO::MYSQL_ATTR_SSL_CA) => env('MYSQL_ATTR_SSL_CA'),
            ]) : [],
        ],

        // Defaults to SQLite for the fast local/CI run. CI also runs the same suite
        // with TENANT_TEST_DB_CONNECTION=mysql against a real MySQL service to catch
        // the cross-connection bugs SQLite's :memory: isolation structurally hides.
        'tenant_test' => [
            'driver' => env('TENANT_TEST_DB_CONNECTION', 'sqlite'),
            'url' => env('TENANT_TEST_DB_URL'),
            'host' => env('TENANT_TEST_DB_HOST', '127.0.0.1'),
            'port' => env('TENANT_TEST_DB_PORT', '3306'),
            'database' => env('TENANT_TEST_DB_DATABASE', ':memory:'),
            'username' => env('TENANT_TEST_DB_USERNAME', 'root'),
            'password' => env('TENANT_TEST_DB_PASSWORD', ''),
            'charset' => env('TENANT_TEST_DB_CHARSET', 'utf8mb4'),
            'collation' => env('TENANT_TEST_DB_COLLATION', 'utf8mb4_unicode_ci'),
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => null,
            // The transitional schema still contains legacy integer FKs that
            // cross the future Core/Tenant boundary. Public IDs are validated
            // in application services until those columns are retired.
            'foreign_key_constraints' => false,
            'transaction_mode' => 'DEFERRED',
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Migration Repository Table
    |--------------------------------------------------------------------------
    |
    | This table keeps track of all the migrations that have already run for
    | your application. Using this information, we can determine which of
    | the migrations on disk haven't actually been run on the database.
    |
    */

    'migrations' => [
        'table' => 'migrations',
        'update_date_on_publish' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Redis Databases
    |--------------------------------------------------------------------------
    |
    | Redis is an open source, fast, and advanced key-value store that also
    | provides a richer body of commands than a typical key-value system
    | such as Memcached. You may define your connection settings here.
    |
    */

    'redis' => [

        'client' => env('REDIS_CLIENT', 'phpredis'),

        'options' => [
            'cluster' => env('REDIS_CLUSTER', 'redis'),
            'prefix' => env('REDIS_PREFIX', Str::slug((string) env('APP_NAME', 'laravel')).'-database-'),
            'persistent' => env('REDIS_PERSISTENT', false),
        ],

        'default' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_DB', '0'),
            'timeout' => env('REDIS_CONNECT_TIMEOUT', 1.0),
            'read_timeout' => env('REDIS_READ_TIMEOUT', 1.0),
            'max_retries' => env('REDIS_MAX_RETRIES', 3),
            'backoff_algorithm' => env('REDIS_BACKOFF_ALGORITHM', 'decorrelated_jitter'),
            'backoff_base' => env('REDIS_BACKOFF_BASE', 100),
            'backoff_cap' => env('REDIS_BACKOFF_CAP', 1000),
        ],

        'cache' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_CACHE_DB', '1'),
            'timeout' => env('REDIS_CONNECT_TIMEOUT', 1.0),
            'read_timeout' => env('REDIS_READ_TIMEOUT', 1.0),
            'max_retries' => env('REDIS_MAX_RETRIES', 3),
            'backoff_algorithm' => env('REDIS_BACKOFF_ALGORITHM', 'decorrelated_jitter'),
            'backoff_base' => env('REDIS_BACKOFF_BASE', 100),
            'backoff_cap' => env('REDIS_BACKOFF_CAP', 1000),
        ],

        'session' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_SESSION_DB', '2'),
            'timeout' => env('REDIS_CONNECT_TIMEOUT', 1.0),
            'read_timeout' => env('REDIS_READ_TIMEOUT', 1.0),
            'max_retries' => env('REDIS_MAX_RETRIES', 3),
            'backoff_algorithm' => env('REDIS_BACKOFF_ALGORITHM', 'decorrelated_jitter'),
            'backoff_base' => env('REDIS_BACKOFF_BASE', 100),
            'backoff_cap' => env('REDIS_BACKOFF_CAP', 1000),
        ],

        'queue' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_QUEUE_DB', '3'),
            'timeout' => env('REDIS_CONNECT_TIMEOUT', 1.0),
            'read_timeout' => env('REDIS_READ_TIMEOUT', 1.0),
            'max_retries' => env('REDIS_MAX_RETRIES', 3),
            'backoff_algorithm' => env('REDIS_BACKOFF_ALGORITHM', 'decorrelated_jitter'),
            'backoff_base' => env('REDIS_BACKOFF_BASE', 100),
            'backoff_cap' => env('REDIS_BACKOFF_CAP', 1000),
        ],

    ],

];
