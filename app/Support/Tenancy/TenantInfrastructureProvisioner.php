<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use App\Modules\Administration\Infrastructure\Eloquent\TenantDatabase;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use LogicException;
use Throwable;

final readonly class TenantInfrastructureProvisioner
{
    public function __construct(private TenantDatabaseLifecycle $lifecycle) {}

    public function provision(TenantDatabase $tenantDatabase): TenantDatabase
    {
        if ($tenantDatabase->provisioning_mode !== 'native') {
            throw new LogicException('Somente bancos nativos podem usar o provisionador de infraestrutura.');
        }
        if (! config('tenancy.native_provisioning.worker_enabled', false)) {
            throw new LogicException('A credencial administrativa só pode ser usada pelo worker de provisionamento isolado.');
        }

        $actor = $tenantDatabase->requestedBy()->firstOrFail();
        $databaseName = (string) $tenantDatabase->database_name;
        $username = (string) $tenantDatabase->runtime_username;
        $host = (string) $tenantDatabase->runtime_host;
        TenantDatabaseNaming::assertValidDatabaseName($databaseName);
        TenantDatabaseNaming::assertValidUsername($username);
        $this->assertValidAccountHost($host);
        if (blank($tenantDatabase->encrypted_runtime_password)) {
            throw new TenantDatabaseNotProvisionedException('A senha de runtime não foi registrada no Core.');
        }
        try {
            $password = Crypt::decryptString((string) $tenantDatabase->encrypted_runtime_password);
            $connection = $this->administrativeConnection();
            $this->assertServerEnvironment($connection);
            $pdo = $connection->getPdo();
            $account = $pdo->quote($username).'@'.$pdo->quote($host);
            $quotedPassword = $pdo->quote($password);

            $connection->unprepared("CREATE DATABASE IF NOT EXISTS `{$databaseName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $tenantDatabase = $this->lifecycle->markInfrastructureStatus($tenantDatabase, $actor, 'database_created');

            $connection->unprepared("CREATE USER IF NOT EXISTS {$account} IDENTIFIED BY {$quotedPassword} REQUIRE SSL");
            $connection->unprepared("ALTER USER {$account} IDENTIFIED BY {$quotedPassword} REQUIRE SSL");
            $tenantDatabase = $this->lifecycle->markInfrastructureStatus($tenantDatabase, $actor, 'user_configured');

            // DROP é necessário porque a migration baseline do Tenant remove, dentro do
            // próprio banco da unidade, cópias vazias de tabelas que pertencem ao Core
            // (TenantSchemaHardener::removeEmptyCoreCopies) — sem isso o provisionamento
            // nativo trava na primeira migration. O escopo continua limitado ao banco
            // desta unidade, nunca a `*.*`.
            $connection->unprepared(
                "GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, DROP, INDEX, REFERENCES ON `{$databaseName}`.* TO {$account}",
            );

            return $this->lifecycle->markInfrastructureStatus($tenantDatabase, $actor, 'grants_applied');
        } catch (Throwable $exception) {
            try {
                $this->lifecycle->markInfrastructureStatus($tenantDatabase, $actor, 'failed', [
                    'error_type' => $exception::class,
                ]);
            } catch (Throwable) {
                // Preserve the infrastructure failure that operators need to diagnose.
            }

            throw $exception;
        } finally {
            DB::purge('tenant_provisioning');
        }
    }

    private function administrativeConnection(): Connection
    {
        $expectedPartialRevokes = config('tenancy.native_provisioning.expected_partial_revokes');
        if (! is_string($expectedPartialRevokes)
            || ! in_array(strtoupper($expectedPartialRevokes), ['ON', 'OFF'], true)) {
            throw new LogicException('TENANT_PROVISIONING_EXPECTED_PARTIAL_REVOKES deve declarar ON ou OFF antes de executar DDL.');
        }

        $configuration = config('tenancy.native_provisioning.administrative_connection');
        if (! is_array($configuration)
            || blank($configuration['host'] ?? null)
            || blank($configuration['username'] ?? null)
            || blank($configuration['password'] ?? null)) {
            throw new LogicException('A conexão administrativa do worker não está configurada.');
        }
        if (($configuration['driver'] ?? null) !== 'mysql') {
            throw new LogicException('O provisionamento nativo exige uma conexão administrativa MySQL.');
        }

        DB::purge('tenant_provisioning');
        Config::set('database.connections.tenant_provisioning', $configuration);

        return DB::connection('tenant_provisioning');
    }

    private function assertServerEnvironment(Connection $connection): void
    {
        $versionResult = (array) $connection->selectOne('SELECT VERSION() AS version');
        $version = (string) ($versionResult['version'] ?? '');
        if (preg_match('/^(\d+\.\d+\.\d+)/', $version, $matches) !== 1
            || version_compare($matches[1], '8.0.0', '<')) {
            throw new LogicException('O provisionamento nativo exige MySQL 8.0 ou superior.');
        }

        $partialRevokesResult = (array) $connection->selectOne(
            'SELECT @@GLOBAL.partial_revokes AS partial_revokes',
        );
        $actualPartialRevokes = $this->normalizeBooleanSystemVariable(
            $partialRevokesResult['partial_revokes'] ?? null,
        );
        $expectedPartialRevokes = strtoupper((string) config(
            'tenancy.native_provisioning.expected_partial_revokes',
        ));
        if ($actualPartialRevokes !== $expectedPartialRevokes) {
            throw new LogicException('O valor global de partial_revokes difere do valor confirmado para o worker.');
        }

        if (config('tenancy.native_provisioning.require_tls', true)) {
            $tlsResult = (array) $connection->selectOne("SHOW SESSION STATUS LIKE 'Ssl_cipher'");
            $cipher = (string) ($tlsResult['Value'] ?? $tlsResult['value'] ?? '');
            if ($cipher === '') {
                throw new LogicException('A conexão administrativa do worker deve usar TLS.');
            }
        }
    }

    private function normalizeBooleanSystemVariable(mixed $value): string
    {
        return match (strtoupper((string) $value)) {
            '1', 'ON' => 'ON',
            '0', 'OFF' => 'OFF',
            default => throw new LogicException('O worker não conseguiu interpretar @@GLOBAL.partial_revokes.'),
        };
    }

    private function assertValidAccountHost(string $host): void
    {
        if ($host === '' || $host === '%' || strlen($host) > 255 || preg_match('/[\x00-\x1f\x7f\'"\\\\]/', $host)) {
            throw new LogicException('O host da credencial de runtime é inválido ou amplo demais.');
        }
    }
}
