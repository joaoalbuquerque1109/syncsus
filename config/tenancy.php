<?php

$profiles = json_decode((string) env('TENANT_DATABASE_PROFILES', '{}'), true);

return [
    'legacy_connection' => env('TENANT_LEGACY_CONNECTION', env('DB_CONNECTION', 'sqlite')),
    'database_profiles' => is_array($profiles) ? $profiles : [],
];
