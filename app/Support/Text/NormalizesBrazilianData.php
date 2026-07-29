<?php

declare(strict_types=1);

namespace App\Support\Text;

use Illuminate\Support\Str;

final class NormalizesBrazilianData
{
    public static function digits(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        return preg_replace('/\D+/', '', $value) ?: null;
    }

    public static function name(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        return mb_strtoupper(preg_replace('/\s+/', ' ', Str::ascii(trim($value))) ?? '');
    }

    public static function phone(?string $value): ?string
    {
        return self::digits($value);
    }
}
