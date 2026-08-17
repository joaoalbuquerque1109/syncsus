<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use App\Modules\Administration\Infrastructure\Eloquent\HealthUnit;
use LogicException;

final class TenantContext
{
    private ?HealthUnit $healthUnit = null;

    private ?string $connectionName = null;

    public function resolve(HealthUnit $healthUnit, string $connectionName): void
    {
        if ($this->healthUnit !== null || $this->connectionName !== null) {
            if ($this->healthUnit?->is($healthUnit) && $this->connectionName === $connectionName) {
                return;
            }

            throw new LogicException('Tenant context is immutable until it is explicitly reset.');
        }

        $this->healthUnit = $healthUnit;
        $this->connectionName = $connectionName;
    }

    public function isResolved(): bool
    {
        return $this->healthUnit !== null && $this->connectionName !== null;
    }

    public function healthUnit(): HealthUnit
    {
        return $this->healthUnit ?? throw new TenantContextNotResolvedException;
    }

    public function connectionName(): string
    {
        return $this->connectionName ?? throw new TenantContextNotResolvedException;
    }

    public function reset(): void
    {
        $this->healthUnit = null;
        $this->connectionName = null;
    }
}
