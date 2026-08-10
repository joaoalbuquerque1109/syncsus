<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use App\Modules\Administration\Infrastructure\Eloquent\HealthUnit;

final class TenantConnectionManager
{
    public function connectionName(HealthUnit $healthUnit): string
    {
        // Phase 0 deliberately keeps every production unit on the existing
        // physical database. Tests use a second PDO so cross-boundary queries
        // fail now instead of only after a physical tenant cutover.
        $healthUnit->getKey();

        if (app()->environment('testing')) {
            return 'tenant_test';
        }

        return (string) config('database.default');
    }
}
