<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use App\Modules\Administration\Infrastructure\Eloquent\HealthUnit;
use Illuminate\Http\Request;
use LogicException;

final class ActiveHealthUnitTenantResolver implements TenantResolver
{
    public function resolve(Request $request): HealthUnit
    {
        $healthUnit = $request->attributes->get('active_health_unit');

        if (! $healthUnit instanceof HealthUnit) {
            throw new LogicException('EnsureActiveHealthUnit must authorize the unit before tenant resolution.');
        }

        return $healthUnit;
    }
}
