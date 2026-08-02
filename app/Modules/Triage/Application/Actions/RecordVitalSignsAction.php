<?php

declare(strict_types=1);

namespace App\Modules\Triage\Application\Actions;

use App\Modules\Administration\Infrastructure\Eloquent\HealthUnit;
use App\Modules\Audit\Application\Actions\RecordAuditEventAction;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Triage\Application\Services\VitalSignTechnicalValidator;
use App\Modules\Triage\Domain\Enums\TriageAssessmentStatus;
use App\Modules\Triage\Infrastructure\Eloquent\TriageAssessment;
use App\Modules\Triage\Infrastructure\Eloquent\VitalSignMeasurement;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class RecordVitalSignsAction
{
    public function __construct(
        private VitalSignTechnicalValidator $validator,
        private RecordAuditEventAction $audit,
    ) {}

    /** @param array<string, mixed> $data */
    public function execute(TriageAssessment $assessment, array $data, User $user, HealthUnit $unit, Request $request): VitalSignMeasurement
    {
        return DB::transaction(function () use ($assessment, $data, $user, $unit, $request): VitalSignMeasurement {
            $locked = TriageAssessment::query()->with('encounter')->whereKey($assessment->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->statusEnum() !== TriageAssessmentStatus::Draft) {
                throw ValidationException::withMessages(['status' => 'Não é possível incluir sinais vitais em uma triagem finalizada.']);
            }
            if (! $user->canManageClinicalRecordOwnedBy($locked->professional_id)) {
                throw ValidationException::withMessages(['professional' => 'Somente o profissional responsável ou o administrador pode registrar esta aferição.']);
            }
            if ($locked->version() !== (int) $data['version']) {
                throw ValidationException::withMessages(['version' => 'A triagem foi atualizada. Recarregue antes de registrar a aferição.']);
            }

            $result = $this->validator->validate($data, (bool) ($data['confirm_outside_ranges'] ?? false));
            $weight = $result['values']['weight_kg'];
            $heightCm = $result['values']['height_cm'];
            $bmi = null;
            if (is_float($weight) && is_float($heightCm) && $heightCm > 0) {
                $bmi = round($weight / (($heightCm / 100) ** 2), 2);
            }

            $measurement = VitalSignMeasurement::query()->create([
                'triage_assessment_id' => $locked->getKey(),
                'encounter_id' => $locked->encounter_id,
                'recorded_by' => $user->getKey(),
                'source' => 'triage',
                'measured_at' => $data['measured_at'] ?? now(),
                ...$result['values'],
                'bmi' => $bmi,
                'blood_type' => $data['blood_type'] ?? null,
                'clinical_alerts' => $data['clinical_alerts'] ?? null,
                'technical_alerts' => $result['alerts'] === [] ? null : $result['alerts'],
                'range_configuration_version' => $result['version'],
                'notes' => $data['notes'] ?? null,
            ]);
            $locked->increment('lock_version');
            $this->audit->execute(
                'triage.vital_signs_recorded',
                $request,
                $user,
                ['triage' => $locked->public_id, 'measurement' => $measurement->public_id, 'technical_alert_count' => count($result['alerts'])],
                (int) $unit->getKey(),
                (int) $locked->encounter->patient_id,
                (int) $locked->encounter_id,
            );

            return $measurement;
        });
    }
}
