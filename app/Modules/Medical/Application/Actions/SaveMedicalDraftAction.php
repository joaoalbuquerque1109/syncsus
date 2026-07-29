<?php

declare(strict_types=1);

namespace App\Modules\Medical\Application\Actions;

use App\Modules\Administration\Infrastructure\Eloquent\HealthUnit;
use App\Modules\Audit\Application\Actions\RecordAuditEventAction;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Medical\Application\Services\MedicalConsultationGuard;
use App\Modules\Medical\Infrastructure\Eloquent\MedicalConsultation;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

final readonly class SaveMedicalDraftAction
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
    ): MedicalConsultation {
        return DB::transaction(function () use ($consultation, $data, $user, $unit, $request): MedicalConsultation {
            $locked = $this->guard->lockDraft($consultation, $user, $unit, (int) $data['version']);
            $locked->update([
                ...Arr::only($data, [
                    'chief_complaint', 'present_illness_history', 'personal_history', 'family_history',
                    'surgical_history', 'current_medications', 'allergies_summary', 'habits',
                    'gynecological_history', 'review_of_systems', 'additional_notes', 'conduct_summary',
                    'procedures_summary', 'guidance', 'requires_reassessment', 'physical_exam_justification',
                ]),
                'lock_version' => $locked->version() + 1,
            ]);
            $exam = Arr::only($data, [
                'general_state', 'consciousness', 'skin_mucosa', 'head_neck', 'respiratory',
                'cardiovascular', 'abdomen', 'neurological', 'musculoskeletal', 'extremities',
                'specific_findings', 'free_text',
            ]);
            if (collect($exam)->contains(fn (mixed $value): bool => filled($value))) {
                $locked->physicalExam()->updateOrCreate([], $exam);
            }
            $this->audit->execute(
                'medical.draft_saved',
                $request,
                $user,
                ['consultation' => $locked->public_id, 'version' => $locked->version()],
                (int) $unit->getKey(),
                (int) $locked->encounter->patient_id,
                (int) $locked->encounter_id,
            );

            return $locked->fresh(['physicalExam']) ?? $locked;
        });
    }
}
