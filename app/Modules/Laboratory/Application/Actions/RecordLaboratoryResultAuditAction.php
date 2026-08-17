<?php

declare(strict_types=1);

namespace App\Modules\Laboratory\Application\Actions;

use App\Modules\Audit\Application\Services\AuditContextSanitizer;
use App\Modules\Audit\Infrastructure\Eloquent\AuditLog;
use App\Modules\Laboratory\Infrastructure\Eloquent\LaboratoryResultIngestion;

final readonly class RecordLaboratoryResultAuditAction
{
    public function __construct(private AuditContextSanitizer $sanitizer) {}

    /** @param array<string, mixed> $context */
    public function execute(
        LaboratoryResultIngestion $ingestion,
        string $action,
        array $context = [],
    ): void {
        $ingestion->loadMissing(['integration', 'transmission.order.encounter']);
        $transmission = $ingestion->laboratory_order_transmission_id === null
            ? null
            : $ingestion->transmission;
        $order = $transmission?->order;

        AuditLog::query()->create([
            'user_id' => null,
            'health_unit_id' => $ingestion->integration->health_unit_id,
            'patient_id' => $order?->encounter?->patient_id,
            'encounter_id' => $order?->encounter_id,
            'action' => $action,
            'context' => $this->sanitizer->sanitize([
                'source' => 'synclab_result_webhook',
                'ingestion' => $ingestion->public_id,
                'transmission' => $transmission?->public_id,
                'external_order_number' => $ingestion->external_order_number,
                'external_exam_code' => $ingestion->external_exam_code,
                ...$context,
            ]),
            'ip_address' => null,
            'user_agent' => 'SYNC HOSP Synclab result worker',
            'occurred_at' => now(),
        ]);
    }
}
