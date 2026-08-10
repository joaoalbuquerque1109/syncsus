<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use App\Modules\Administration\Infrastructure\Eloquent\HealthUnit;
use App\Modules\Administration\Infrastructure\Eloquent\TenantDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use LogicException;

final class TenantConnectionManager
{
    /** @var array<int, TenantDatabase|null> */
    private array $tenantDatabases = [];

    public function connectionName(HealthUnit $healthUnit): string
    {
        $tenantDatabase = $this->tenantDatabase($healthUnit);
        if ($tenantDatabase !== null && $tenantDatabase->stateEnum()->usesDedicatedConnection()) {
            return $this->dedicatedConnectionName($tenantDatabase);
        }

        return $this->legacyConnectionName();
    }

    public function legacyConnectionName(): string
    {
        $connection = trim((string) config('tenancy.legacy_connection'));
        if ($connection === '' || ! is_array(config("database.connections.{$connection}"))) {
            throw new LogicException('A conexão legada de unidades não está configurada.');
        }

        return $connection;
    }

    public function tenantDatabase(HealthUnit $healthUnit): ?TenantDatabase
    {
        if (! $healthUnit->exists || $healthUnit->getKey() === null) {
            return null;
        }

        $unitId = (int) $healthUnit->getKey();
        if (! array_key_exists($unitId, $this->tenantDatabases)) {
            $this->tenantDatabases[$unitId] = TenantDatabase::query()
                ->where('health_unit_id', $unitId)
                ->first();
        }

        return $this->tenantDatabases[$unitId];
    }

    public function forget(HealthUnit|TenantDatabase $tenant): void
    {
        $unitId = $tenant instanceof TenantDatabase
            ? (int) $tenant->health_unit_id
            : (int) $tenant->getKey();
        unset($this->tenantDatabases[$unitId]);
    }

    public function shadowConnectionName(HealthUnit $healthUnit): ?string
    {
        $tenantDatabase = $this->tenantDatabase($healthUnit);

        return $tenantDatabase?->stateEnum()->mirrorsToDedicatedConnection() === true
            ? $this->dedicatedConnectionName($tenantDatabase)
            : null;
    }

    public function dedicatedConnectionName(HealthUnit|TenantDatabase $tenant): string
    {
        $tenantDatabase = $tenant instanceof TenantDatabase
            ? $tenant
            : ($this->tenantDatabase($tenant) ?? throw new LogicException('A unidade não possui banco piloto registrado.'));
        $profile = config("tenancy.database_profiles.{$tenantDatabase->connection_profile}");
        if (! is_array($profile)) {
            throw new LogicException("O perfil de conexão '{$tenantDatabase->connection_profile}' não está configurado.");
        }

        $template = config('database.connections.tenant_template');
        if (! is_array($template)) {
            throw new LogicException('O template de conexão Tenant não está configurado.');
        }

        $configuration = array_replace($template, $profile);
        if (filled($tenantDatabase->database_name)) {
            $configuration['database'] = $tenantDatabase->database_name;
        }
        if (blank($configuration['driver'] ?? null) || blank($configuration['database'] ?? null)) {
            throw new LogicException('O perfil do banco piloto não define driver e database.');
        }
        $legacy = config('database.connections.'.$this->legacyConnectionName());
        if (! is_array($legacy) || ($legacy['driver'] ?? null) !== $configuration['driver']) {
            throw new LogicException('LEGACY e banco piloto precisam usar o mesmo driver durante o double-write.');
        }
        if ($configuration['driver'] !== 'sqlite'
            && ($legacy['url'] ?? null) === ($configuration['url'] ?? null)
            && ($legacy['host'] ?? null) === ($configuration['host'] ?? null)
            && ($legacy['port'] ?? null) === ($configuration['port'] ?? null)
            && ($legacy['database'] ?? null) === $configuration['database']) {
            throw new LogicException('O perfil piloto aponta para o mesmo banco físico da conexão LEGACY.');
        }

        $connectionName = 'tenant_'.strtolower((string) $tenantDatabase->public_id);
        if (Config::get("database.connections.{$connectionName}") !== $configuration) {
            DB::purge($connectionName);
            Config::set("database.connections.{$connectionName}", $configuration);
        }

        return $connectionName;
    }

    public function assertDedicatedConnectionAvailable(HealthUnit|TenantDatabase $tenant): string
    {
        $connectionName = $this->dedicatedConnectionName($tenant);
        DB::connection($connectionName)->getPdo();

        return $connectionName;
    }
}
