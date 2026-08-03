<?php

declare(strict_types=1);

namespace App\Modules\Laboratory\Application\Actions;

use App\Modules\Audit\Application\Services\AuditContextSanitizer;
use App\Modules\Audit\Infrastructure\Eloquent\AuditLog;
use App\Modules\Laboratory\Infrastructure\Eloquent\LaboratoryOrderTransmission;

final readonly class RecordLaboratoryTransmissionAuditAction
{
    public function __construct(private AuditContextSanitizer $sanitizer) {}

    /** @param array<string, mixed> $context */
    public function execute(
        LaboratoryOrderTransmission $transmission,
        string $action,
        array $context = [],
    ): void {
        $transmission->loadMissing('order.encounter');
        $order = $transmission->order;

        AuditLog::query()->create([
            'user_id' => null,
            'health_unit_id' => $transmission->health_unit_id,
            'patient_id' => $order->encounter->patient_id,
            'encounter_id' => $order->encounter_id,
            'action' => $action,
            'context' => $this->sanitizer->sanitize([
                'source' => 'synclab_worker',
                'exam_order' => $order->public_id,
                'transmission' => $transmission->public_id,
                ...$context,
            ]),
            'ip_address' => null,
            'user_agent' => 'SYNC SUS Synclab worker',
            'occurred_at' => now(),
        ]);
    }
}
