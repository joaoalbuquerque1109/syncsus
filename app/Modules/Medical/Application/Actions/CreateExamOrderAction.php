<?php

declare(strict_types=1);

namespace App\Modules\Medical\Application\Actions;

use App\Modules\Administration\Infrastructure\Eloquent\HealthUnit;
use App\Modules\Audit\Application\Actions\RecordAuditEventAction;
use App\Modules\Identity\Infrastructure\Eloquent\User;
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
                'encounter_id' => $locked->encounter_id,
                'medical_consultation_id' => $locked->getKey(),
                'requested_by' => $user->getKey(),
                'priority' => $data['priority'],
                'clinical_indication' => $data['clinical_indication'],
                'notes' => $data['notes'] ?? null,
                'requested_at' => now(),
            ]);
            foreach ($data['items'] as $item) {
                $order->items()->create([
                    ...Arr::only($item, ['internal_code', 'exam_name', 'group', 'laterality', 'preparation', 'justification']),
                    'priority' => $item['priority'] ?? $data['priority'],
                    'status' => 'requested',
                ]);
            }
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
}
