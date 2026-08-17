<?php

declare(strict_types=1);

namespace App\Modules\Audit\Application\Actions;

use App\Modules\Audit\Application\Services\AuditContextSanitizer;
use App\Modules\Audit\Infrastructure\Eloquent\AuditLog;
use App\Modules\Audit\Infrastructure\Eloquent\SecurityAuditLog;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

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
    ): AuditLog|SecurityAuditLog {
        $safeContext = $this->sanitizer->sanitize([
            'user_role' => $user?->getRoleNames()->first(),
            ...$context,
        ]);

        $attributes = [
            'user_id' => $user?->getKey(),
            'health_unit_id' => $healthUnitId,
            'action' => $action,
            'correlation_id' => $this->correlationId($request),
            'context' => $safeContext,
            'ip_address' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 1000),
            'occurred_at' => now(),
        ];

        if ($this->isSecurityAction($action)) {
            return SecurityAuditLog::query()->create($attributes);
        }

        return AuditLog::query()->create([
            ...$attributes,
            'patient_id' => $patientId,
            'encounter_id' => $encounterId,
        ]);
    }

    private function isSecurityAction(string $action): bool
    {
        return Str::startsWith($action, ['user.', 'professional.', 'organization.', 'tenant.', 'security.']);
    }

    private function correlationId(Request $request): string
    {
        $correlationId = $request->attributes->get('correlation_id');
        if (is_string($correlationId) && $correlationId !== '') {
            return $correlationId;
        }

        $correlationId = (string) Str::ulid();
        $request->attributes->set('correlation_id', $correlationId);

        return $correlationId;
    }
}
