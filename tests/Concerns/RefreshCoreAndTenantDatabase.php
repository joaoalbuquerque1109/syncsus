<?php

declare(strict_types=1);

namespace Tests\Concerns;

use Illuminate\Foundation\Testing\RefreshDatabase;

trait RefreshCoreAndTenantDatabase
{
    use RefreshDatabase;

    protected function migrateDatabases()
    {
        $this->artisan('migrate:fresh', [
            '--database' => 'core',
            '--path' => (string) config('tenancy.migrations.core_path'),
            ...$this->migrateFreshUsing(),
        ]);

        $this->artisan('migrate:fresh', [
            '--database' => 'tenant_test',
            '--path' => (string) config('tenancy.migrations.tenant_path'),
            ...$this->migrateFreshUsing(),
        ]);
    }
}
