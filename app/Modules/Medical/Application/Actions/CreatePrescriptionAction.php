<?php

declare(strict_types=1);

namespace App\Modules\Medical\Application\Actions;

use App\Modules\Administration\Infrastructure\Eloquent\HealthUnit;
use App\Modules\Audit\Application\Actions\RecordAuditEventAction;
use App\Modules\Documents\Infrastructure\Eloquent\ClinicalDocument;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Medical\Application\Services\MedicalConsultationGuard;
use App\Modules\Medical\Infrastructure\Eloquent\MedicalConsultation;
use App\Modules\Medical\Infrastructure\Eloquent\Prescription;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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
            $replaced = null;
            if (filled($data['replaces_prescription_id'] ?? null)) {
                $replaced = Prescription::query()
                    ->where('public_id', $data['replaces_prescription_id'])
                    ->where('medical_consultation_id', $locked->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();
                abort_unless($user->canManageClinicalRecordOwnedBy($replaced->professional_id), 403);
                if ($replaced->status !== 'finalized') {
                    throw ValidationException::withMessages([
                        'replaces_prescription_id' => 'Somente uma prescrição vigente pode ser substituída.',
                    ]);
                }
            }
            $prescription = Prescription::query()->create([
                'encounter_id' => $locked->encounter_id,
                'medical_consultation_id' => $locked->getKey(),
                'professional_id' => $user->getKey(),
                'prescription_type' => $data['prescription_type'],
                'status' => 'finalized',
                'general_instructions' => $data['general_instructions'] ?? null,
                'version' => $replaced ? ((int) $replaced->version) + 1 : 1,
                'parent_prescription_id' => $replaced?->getKey(),
                'finalized_at' => now(),
            ]);
            foreach ($data['items'] as $index => $item) {
                $prescription->items()->create([
                    ...$item,
                    'is_immediate' => false,
                    'is_as_needed' => false,
                    'as_needed_condition' => null,
                    'display_order' => $index + 1,
                ]);
            }
            if ($replaced) {
                $reason = (string) $data['replacement_reason'];
                $replaced->update([
                    'status' => 'replaced',
                    'replaced_at' => now(),
                    'replaced_by' => $user->getKey(),
                    'replacement_reason' => $reason,
                ]);
                if (filled($replaced->document_id)) {
                    ClinicalDocument::query()
                        ->whereKey($replaced->document_id)
                        ->where('status', 'active')
                        ->update([
                            'status' => 'voided',
                            'voided_at' => now(),
                            'voided_by' => $user->getKey(),
                            'void_reason' => $reason,
                        ]);
                }
            }
            $this->guard->increment($locked);
            $this->audit->execute(
                $replaced ? 'medical.prescription_replaced' : 'medical.prescription_finalized',
                $request,
                $user,
                [
                    'consultation' => $locked->public_id,
                    'prescription' => $prescription->public_id,
                    'replaced_prescription' => $replaced?->public_id,
                    'item_count' => count($data['items']),
                ],
                (int) $unit->getKey(),
                (int) $locked->encounter->patient_id,
                (int) $locked->encounter_id,
            );

            return $prescription->load('items');
        });
    }
}
