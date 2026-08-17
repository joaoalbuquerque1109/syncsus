<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use App\Modules\Administration\Infrastructure\Eloquent\HealthUnit;
use Illuminate\Http\Request;

interface TenantResolver
{
    public function resolve(Request $request): HealthUnit;
}
