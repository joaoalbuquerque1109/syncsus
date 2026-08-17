<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use App\Modules\Administration\Infrastructure\Eloquent\TenantDatabase;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use Throwable;

final readonly class TenantDatabaseProvisioner
{
    public function __construct(
        private TenantConnectionManager $connections,
        private TenantSchemaMigrator $schemaMigrator,
        private TenantDatabaseLifecycle $lifecycle,
    ) {}

    public function provision(TenantDatabase $tenantDatabase, User $actor): TenantDatabase
    {
        $connectionName = $this->connections->assertDedicatedConnectionAvailable($tenantDatabase);

        try {
            $schema = $this->schemaMigrator->migrate($tenantDatabase, $actor);
            $tenantDatabase = $this->lifecycle->markProvisioningResult(
                $tenantDatabase,
                $actor,
                true,
                [
                    'connection' => $connectionName,
                    'schema_signature' => $schema['signature'],
                    'tenant_migrations' => $schema['expected'],
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
