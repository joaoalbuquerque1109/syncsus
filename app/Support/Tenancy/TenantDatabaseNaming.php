<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use InvalidArgumentException;

final class TenantDatabaseNaming
{
    public static function databaseName(string $publicId): string
    {
        return 'sync_hosp_u'.self::normalizedUlid($publicId);
    }

    public static function runtimeUsername(string $publicId): string
    {
        return 'tu_'.self::normalizedUlid($publicId);
    }

    public static function assertValidDatabaseName(string $databaseName): void
    {
        if (! preg_match('/^sync_hosp_u[0-9a-hjkmnp-tv-z]{26}$/', $databaseName)) {
            throw new InvalidArgumentException('Nome de banco dedicado inválido.');
        }
    }

    public static function assertValidUsername(string $username): void
    {
        if (strlen($username) > 32 || ! preg_match('/^tu_[0-9a-hjkmnp-tv-z]{26}$/', $username)) {
            throw new InvalidArgumentException('Nome de usuário MySQL da unidade inválido.');
        }
    }

    private static function normalizedUlid(string $publicId): string
    {
        $ulid = strtolower(str_replace('-', '', trim($publicId)));
        if (! preg_match('/^[0-9a-hjkmnp-tv-z]{26}$/', $ulid)) {
            throw new InvalidArgumentException('ULID público da unidade inválido.');
        }

        return $ulid;
    }
}
