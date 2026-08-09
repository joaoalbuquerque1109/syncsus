<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

final class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->isProduction()) {
            $this->call(FoundationCatalogSeeder::class);
        }

        $this->call([
            SynclabExamCatalogSeeder::class,
            CanonicalExamCatalogBackfillSeeder::class,
            OperationalCatalogSeeder::class,
            TriageCatalogSeeder::class,
            MedicalCatalogSeeder::class,
            RolePermissionSeeder::class,
        ]);

        if (config('sync_sus.seed_demo_data') && ! app()->isProduction()) {
            $this->call(DemoDataSeeder::class);
        }

        $this->call(AdminUserSeeder::class);
    }
}
