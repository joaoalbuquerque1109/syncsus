<?php

declare(strict_types=1);

namespace App\Modules\Medical\Application\Actions;

use App\Modules\Administration\Infrastructure\Eloquent\HealthUnit;
use App\Modules\Audit\Application\Actions\RecordAuditEventAction;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Laboratory\Application\Actions\PrepareLaboratoryTransmissionsAction;
use App\Modules\Laboratory\Infrastructure\Eloquent\LaboratoryExam;
use App\Modules\Medical\Application\Services\MedicalConsultationGuard;
use App\Modules\Medical\Infrastructure\Eloquent\ExamOrder;
use App\Modules\Medical\Infrastructure\Eloquent\MedicalConsultation;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

final readonly class CreateExamOrderAction
{
    public function __construct(
        private MedicalConsultationGuard $guard,
        private RecordAuditEventAction $audit,
        private PrepareLaboratoryTransmissionsAction $prepareTransmissions,
    ) {}

    /** @param array<string, mixed> $data */
    public function execute(
        MedicalConsultation $consultation,
        array $data,
        User $user,
        HealthUnit $unit,
        Request $request,
    ): ExamOrder {
        return DB::transaction(function () use ($consultation, $data, $user, $unit, $request): ExamOrder {
            $locked = $this->guard->lockDraft($consultation, $user, $unit, (int) $data['version']);
            $order = ExamOrder::query()->create([
                'organization_id' => $unit->organization_id,
                'health_unit_id' => $unit->getKey(),
                'encounter_id' => $locked->encounter_id,
                'medical_consultation_id' => $locked->getKey(),
                'requested_by' => $user->getKey(),
                'created_by' => $user->getKey(),
                'origin' => 'medical',
                'status' => 'pending',
                'priority' => $data['priority'],
                'clinical_indication' => $data['clinical_indication'],
                'notes' => $data['notes'] ?? null,
                'requested_at' => now(),
            ]);
            foreach ($data['items'] as $item) {
                $laboratoryExam = $this->resolveLaboratoryExam($item, $unit);
                $order->items()->create([
                    ...Arr::only($item, ['internal_code', 'exam_name', 'group', 'laterality', 'preparation', 'justification']),
                    'internal_code' => $laboratoryExam->external_code ?? ($item['internal_code'] ?? null),
                    'exam_name' => $laboratoryExam->name ?? $item['exam_name'],
                    'group' => $laboratoryExam === null ? $item['group'] : 'laboratory',
                    'preparation' => $laboratoryExam->collection_instructions ?? ($item['preparation'] ?? null),
                    'laboratory_integration_id' => $laboratoryExam?->laboratory_integration_id,
                    'laboratory_exam_id' => $laboratoryExam?->getKey(),
                    'external_exam_code' => $laboratoryExam?->external_code,
                    'catalog_version' => $laboratoryExam?->source_version,
                    'laboratory_snapshot' => $laboratoryExam === null ? null : [
                        'external_code' => $laboratoryExam->external_code,
                        'name' => $laboratoryExam->name,
                        'acronym' => $laboratoryExam->acronym,
                        'material' => $laboratoryExam->material?->name,
                        'collection_instructions' => $laboratoryExam->collection_instructions,
                    ],
                    'priority' => $item['priority'] ?? $data['priority'],
                    'status' => 'requested',
                ]);
            }
            $this->prepareTransmissions->execute($order, $unit);
            $this->guard->increment($locked);
            $this->audit->execute(
                'medical.exam_order_created',
                $request,
                $user,
                ['consultation' => $locked->public_id, 'exam_order' => $order->public_id, 'item_count' => count($data['items'])],
                (int) $unit->getKey(),
                (int) $locked->encounter->patient_id,
                (int) $locked->encounter_id,
            );

            return $order->load('items');
        });
    }

    /** @param array<string, mixed> $item */
    private function resolveLaboratoryExam(array $item, HealthUnit $unit): ?LaboratoryExam
    {
        $catalogId = $item['laboratory_exam_id'] ?? null;
        if ($catalogId === null || $catalogId === '') {
            return null;
        }

        return LaboratoryExam::query()
            ->with('material')
            ->whereKey($catalogId)
            ->where('is_active', true)
            ->whereHas('integration', fn ($query) => $query
                ->where('organization_id', $unit->organization_id)
                ->where('health_unit_id', $unit->getKey())
                ->where('is_active', true))
            ->firstOrFail();
    }
}
