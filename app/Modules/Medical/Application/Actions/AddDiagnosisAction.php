<?php

declare(strict_types=1);

namespace App\Modules\Medical\Application\Actions;

use App\Modules\Administration\Infrastructure\Eloquent\HealthUnit;
use App\Modules\Audit\Application\Actions\RecordAuditEventAction;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Medical\Application\Services\MedicalConsultationGuard;
use App\Modules\Medical\Infrastructure\Eloquent\Diagnosis;
use App\Modules\Medical\Infrastructure\Eloquent\DiagnosisCode;
use App\Modules\Medical\Infrastructure\Eloquent\MedicalConsultation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class AddDiagnosisAction
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
    ): Diagnosis {
        return DB::transaction(function () use ($consultation, $data, $user, $unit, $request): Diagnosis {
            $locked = $this->guard->lockDraft($consultation, $user, $unit, (int) $data['version']);
            if ((bool) $data['is_primary'] && $locked->diagnoses()->where('is_primary', true)->where('status', 'active')->exists()) {
                throw ValidationException::withMessages(['is_primary' => 'Já existe um diagnóstico principal ativo neste atendimento.']);
            }

            $catalog = null;
            if (filled($data['diagnosis_code_id'] ?? null)) {
                $catalog = DiagnosisCode::query()
                    ->whereKey($data['diagnosis_code_id'])
                    ->where('is_active', true)
                    ->firstOrFail();
            }
            $description = $catalog instanceof DiagnosisCode
                ? $catalog->description
                : $data['description'];
            $diagnosis = $locked->diagnoses()->create([
                'encounter_id' => $locked->encounter_id,
                'diagnosis_code_id' => $catalog?->getKey(),
                'code' => $catalog?->code,
                'description' => $description,
                'diagnosis_type' => $data['diagnosis_type'],
                'is_primary' => $data['is_primary'],
                'status' => 'active',
                'notes' => $data['notes'] ?? null,
                'diagnosed_by' => $user->getKey(),
                'diagnosed_at' => now(),
            ]);
            $this->guard->increment($locked);
            $this->audit->execute(
                'medical.diagnosis_recorded',
                $request,
                $user,
                ['consultation' => $locked->public_id, 'diagnosis_id' => $diagnosis->getKey(), 'has_catalog_code' => $catalog !== null],
                (int) $unit->getKey(),
                (int) $locked->encounter->patient_id,
                (int) $locked->encounter_id,
            );

            return $diagnosis;
        });
    }
}
