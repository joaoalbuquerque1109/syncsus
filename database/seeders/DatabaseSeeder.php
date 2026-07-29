<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

final class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            FoundationCatalogSeeder::class,
            OperationalCatalogSeeder::class,
            TriageCatalogSeeder::class,
            MedicalCatalogSeeder::class,
            RolePermissionSeeder::class,
        ]);

        if (config('sync_sus.seed_demo_data') && ! app()->isProduction()) {
            $this->call(DemoDataSeeder::class);

            return;
        }

        $this->call(AdminUserSeeder::class);
    }
}
