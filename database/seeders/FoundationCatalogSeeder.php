<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Administration\Infrastructure\Eloquent\HealthUnit;
use App\Modules\Administration\Infrastructure\Eloquent\Organization;
use Illuminate\Database\Seeder;

final class FoundationCatalogSeeder extends Seeder
{
    public function run(): void
    {
        $organization = Organization::query()->updateOrCreate(
            ['document_number' => '00000000000000'],
            [
                'code' => 'URGENCIA-CENTRAL',
                'legal_name' => 'Hospital Municipal Demonstrativo',
                'trade_name' => 'Hospital Demonstrativo',
                'timezone' => config('app.timezone'),
                'locale' => config('app.locale'),
                'is_active' => true,
            ],
        );

        HealthUnit::query()->updateOrCreate(
            ['organization_id' => $organization->getKey(), 'code' => 'URGENCIA-CENTRAL'],
            [
                'name' => 'Urgência Central',
                'city' => 'Município Demonstrativo',
                'state' => 'CE',
                'is_active' => true,
            ],
        );
    }
}
