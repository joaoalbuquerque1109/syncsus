<?php

declare(strict_types=1);

namespace App\Modules\Audit\Application\Services;

final class AuditContextSanitizer
{
    /** @var list<string> */
    private const SENSITIVE_KEY_FRAGMENTS = [
        'password',
        'passwd',
        'secret',
        'token',
        'authorization',
        'cookie',
        'session',
        'app_key',
        'db_password',
    ];

    /** @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function sanitize(array $context): array
    {
        $sanitized = [];
        foreach ($context as $key => $value) {
            $normalizedKey = mb_strtolower((string) $key);
            if ($this->isSensitive($normalizedKey)) {
                $sanitized[$key] = '[REDACTED]';

                continue;
            }
            $sanitized[$key] = is_array($value) ? $this->sanitize($value) : $value;
        }

        return $sanitized;
    }

    private function isSensitive(string $key): bool
    {
        foreach (self::SENSITIVE_KEY_FRAGMENTS as $fragment) {
            if (str_contains($key, $fragment)) {
                return true;
            }
        }

        return false;
    }
}
