<?php

declare(strict_types=1);

namespace App\Modules\Medical\Application\Actions;

use App\Modules\Administration\Infrastructure\Eloquent\HealthUnit;
use App\Modules\Audit\Application\Actions\RecordAuditEventAction;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Medical\Application\Services\MedicalConsultationGuard;
use App\Modules\Medical\Infrastructure\Eloquent\MedicalConsultation;
use App\Modules\Medical\Infrastructure\Eloquent\Prescription;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

final readonly class CreatePrescriptionAction
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
    ): Prescription {
        return DB::transaction(function () use ($consultation, $data, $user, $unit, $request): Prescription {
            $locked = $this->guard->lockDraft($consultation, $user, $unit, (int) $data['version']);
            $prescription = Prescription::query()->create([
                'encounter_id' => $locked->encounter_id,
                'medical_consultation_id' => $locked->getKey(),
                'professional_id' => $user->getKey(),
                'prescription_type' => $data['prescription_type'],
                'status' => 'finalized',
                'general_instructions' => $data['general_instructions'] ?? null,
                'version' => 1,
                'finalized_at' => now(),
            ]);
            foreach ($data['items'] as $index => $item) {
                $prescription->items()->create([
                    ...Arr::except($item, ['is_immediate', 'is_as_needed']),
                    'is_immediate' => (bool) ($item['is_immediate'] ?? false),
                    'is_as_needed' => (bool) ($item['is_as_needed'] ?? false),
                    'display_order' => $index + 1,
                ]);
            }
            $this->guard->increment($locked);
            $this->audit->execute(
                'medical.prescription_finalized',
                $request,
                $user,
                ['consultation' => $locked->public_id, 'prescription' => $prescription->public_id, 'item_count' => count($data['items'])],
                (int) $unit->getKey(),
                (int) $locked->encounter->patient_id,
                (int) $locked->encounter_id,
            );

            return $prescription->load('items');
        });
    }
}
