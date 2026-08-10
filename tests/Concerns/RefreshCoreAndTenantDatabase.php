<?php

declare(strict_types=1);

namespace Tests\Concerns;

use Illuminate\Foundation\Testing\RefreshDatabase;

trait RefreshCoreAndTenantDatabase
{
    use RefreshDatabase {
        migrateDatabases as migrateTenantDatabase;
    }

    protected function migrateDatabases()
    {
        $this->artisan('migrate:fresh', [
            '--database' => 'core',
            ...$this->migrateFreshUsing(),
        ]);

        $this->migrateTenantDatabase();
    }
}
