<?php

declare(strict_types=1);

namespace App\Modules\Triage\Application\Actions;

use App\Modules\Administration\Infrastructure\Eloquent\HealthUnit;
use App\Modules\Audit\Application\Actions\RecordAuditEventAction;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Triage\Domain\Enums\TriageAssessmentStatus;
use App\Modules\Triage\Infrastructure\Eloquent\TriageAssessment;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class SaveTriageDraftAction
{
    public function __construct(private RecordAuditEventAction $audit) {}

    /** @param array<string, mixed> $data */
    public function execute(TriageAssessment $assessment, array $data, User $user, HealthUnit $unit, Request $request): TriageAssessment
    {
        return DB::transaction(function () use ($assessment, $data, $user, $unit, $request): TriageAssessment {
            $locked = TriageAssessment::query()->with('encounter')->whereKey($assessment->getKey())->lockForUpdate()->firstOrFail();
            $this->ensureEditable($locked, $user, (int) $data['version']);
            $locked->update([
                ...Arr::only($data, [
                    'chief_complaint', 'symptom_onset', 'brief_history', 'pain_scale',
                    'has_reported_allergies', 'reported_allergies', 'uses_medications',
                    'current_medications', 'known_conditions', 'pregnancy_status',
                    'fall_risk', 'requires_isolation', 'isolation_reason', 'violence_signs',
                    'violence_notes', 'initial_exam', 'observations',
                ]),
                'lock_version' => $locked->version() + 1,
            ]);
            $this->audit->execute(
                'triage.draft_saved',
                $request,
                $user,
                ['triage' => $locked->public_id, 'version' => $locked->version()],
                (int) $unit->getKey(),
                (int) $locked->encounter->patient_id,
                (int) $locked->encounter_id,
            );

            return $locked->fresh() ?? $locked;
        });
    }

    private function ensureEditable(TriageAssessment $assessment, User $user, int $version): void
    {
        if ($assessment->statusEnum() !== TriageAssessmentStatus::Draft) {
            throw ValidationException::withMessages(['status' => 'A triagem foi finalizada e não pode ser editada. Registre um adendo.']);
        }
        if (! $user->canManageClinicalRecordOwnedBy($assessment->professional_id)) {
            throw ValidationException::withMessages(['professional' => 'Somente o profissional responsável ou o administrador pode alterar este rascunho.']);
        }
        if ($assessment->version() !== $version) {
            throw ValidationException::withMessages(['version' => 'O rascunho foi atualizado em outra sessão. Recarregue a página.']);
        }
    }
}
