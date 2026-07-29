<?php

declare(strict_types=1);

namespace App\Modules\Audit\Application\Actions;

use App\Modules\Audit\Application\Services\AuditContextSanitizer;
use App\Modules\Audit\Infrastructure\Eloquent\AuditLog;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use Illuminate\Http\Request;

final class RecordAuditEventAction
{
    public function __construct(private readonly AuditContextSanitizer $sanitizer) {}

    /**
     * @param  array<string, mixed>  $context
     */
    public function execute(
        string $action,
        Request $request,
        ?User $user = null,
        array $context = [],
        ?int $healthUnitId = null,
        ?int $patientId = null,
        ?int $encounterId = null,
    ): AuditLog {
        $safeContext = $this->sanitizer->sanitize([
            'user_role' => $user?->getRoleNames()->first(),
            ...$context,
        ]);

        return AuditLog::query()->create([
            'user_id' => $user?->getKey(),
            'health_unit_id' => $healthUnitId,
            'patient_id' => $patientId,
            'encounter_id' => $encounterId,
            'action' => $action,
            'context' => $safeContext,
            'ip_address' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 1000),
            'occurred_at' => now(),
        ]);
    }
}
