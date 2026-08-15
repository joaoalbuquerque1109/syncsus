<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Administration\Infrastructure\Eloquent\HealthUnit;
use App\Modules\Laboratory\Application\Actions\BackfillCanonicalExamCatalogAction;
use App\Support\Tenancy\TenantConnectionManager;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Seeder;

final class CanonicalExamCatalogBackfillSeeder extends Seeder
{
    public function run(BackfillCanonicalExamCatalogAction $action): void
    {
        foreach (HealthUnit::query()->get() as $unit) {
            $context = app(TenantContext::class);
            $context->reset();
            $context->resolve($unit, app(TenantConnectionManager::class)->connectionName($unit));
            $action->execute();
        }
    }
}
