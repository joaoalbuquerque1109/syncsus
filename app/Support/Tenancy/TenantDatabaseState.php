<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

enum TenantDatabaseState: string
{
    case Legacy = 'LEGACY';
    case Shadow = 'SHADOW';
    case Validating = 'VALIDATING';
    case Cutover = 'CUTOVER';
    case Tenant = 'TENANT';
    case Rollback = 'ROLLBACK';

    public function usesDedicatedConnection(): bool
    {
        return in_array($this, [self::Cutover, self::Tenant], true);
    }

    public function mirrorsToDedicatedConnection(): bool
    {
        return in_array($this, [self::Shadow, self::Validating], true);
    }

    public function canTransitionTo(self $target): bool
    {
        return match ($this) {
            self::Legacy => $target === self::Shadow,
            self::Shadow => in_array($target, [self::Validating, self::Rollback], true),
            self::Validating => in_array($target, [self::Cutover, self::Rollback], true),
            self::Cutover => in_array($target, [self::Tenant, self::Rollback], true),
            self::Rollback => $target === self::Legacy,
            self::Tenant => false,
        };
    }
}
