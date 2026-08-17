<?php

declare(strict_types=1);

namespace App\Modules\Laboratory\Application\Actions;

use App\Modules\Audit\Application\Services\AuditContextSanitizer;
use App\Modules\Audit\Infrastructure\Eloquent\AuditLog;
use App\Modules\Identity\Infrastructure\Eloquent\User;

final readonly class RecordLaboratoryCatalogAuditAction
{
    public function __construct(private AuditContextSanitizer $sanitizer) {}

    /** @param array<string, mixed> $context */
    public function execute(
        string $action,
        User $actor,
        int $healthUnitId,
        array $context,
    ): AuditLog {
        return AuditLog::query()->create([
            'user_id' => $actor->getKey(),
            'health_unit_id' => $healthUnitId,
            'action' => $action,
            'context' => $this->sanitizer->sanitize([
                'source' => 'console',
                'user_role' => $actor->getRoleNames()->first(),
                ...$context,
            ]),
            'occurred_at' => now(),
        ]);
    }
}
