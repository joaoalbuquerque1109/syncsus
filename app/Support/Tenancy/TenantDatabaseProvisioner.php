<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use App\Modules\Administration\Infrastructure\Eloquent\TenantDatabase;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use Illuminate\Support\Facades\Artisan;
use RuntimeException;
use Throwable;

final readonly class TenantDatabaseProvisioner
{
    public function __construct(
        private TenantConnectionManager $connections,
        private TenantSchemaHardener $schemaHardener,
        private TenantDatabaseLifecycle $lifecycle,
    ) {}

    public function provision(TenantDatabase $tenantDatabase, User $actor): TenantDatabase
    {
        $connectionName = $this->connections->assertDedicatedConnectionAvailable($tenantDatabase);

        try {
            $exitCode = Artisan::call('migrate', [
                '--database' => $connectionName,
                '--force' => true,
                '--no-interaction' => true,
            ]);
            if ($exitCode !== 0) {
                throw new RuntimeException('As migrations do banco piloto não foram concluídas.');
            }
            $removedForeignKeys = $this->schemaHardener->harden($connectionName);
            $tenantDatabase = $this->lifecycle->markProvisioningResult(
                $tenantDatabase,
                $actor,
                true,
                [
                    'connection' => $connectionName,
                    'removed_cross_database_foreign_keys' => $removedForeignKeys,
                ],
            );

            return $this->lifecycle->transition(
                $tenantDatabase,
                TenantDatabaseState::Shadow,
                $actor,
                ['connection' => $connectionName],
            );
        } catch (Throwable $exception) {
            $this->lifecycle->markProvisioningResult(
                $tenantDatabase,
                $actor,
                false,
                ['error_type' => $exception::class],
            );

            throw $exception;
        }
    }
}
