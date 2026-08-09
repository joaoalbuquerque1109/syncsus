<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use App\Modules\Administration\Infrastructure\Eloquent\HealthUnit;

final class TenantConnectionManager
{
    public function connectionName(HealthUnit $healthUnit): string
    {
        // Phase 0 deliberately keeps every unit on the existing physical DB.
        // The HealthUnit parameter is part of the definitive contract for Phase 3.
        $healthUnit->getKey();

        return (string) config('database.default');
    }
}
