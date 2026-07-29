<?php

declare(strict_types=1);

namespace App\Modules\Medical\Application\Actions;

use App\Modules\Administration\Infrastructure\Eloquent\HealthUnit;
use App\Modules\Audit\Application\Actions\RecordAuditEventAction;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Medical\Application\Services\MedicalConsultationGuard;
use App\Modules\Medical\Infrastructure\Eloquent\ClinicalNote;
use App\Modules\Medical\Infrastructure\Eloquent\MedicalConsultation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final readonly class CreateClinicalNoteAction
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
    ): ClinicalNote {
        return DB::transaction(function () use ($consultation, $data, $user, $unit, $request): ClinicalNote {
            $locked = $this->guard->lockDraft($consultation, $user, $unit, (int) $data['version']);
            $parentId = null;
            if (filled($data['parent_note_id'] ?? null)) {
                $parentId = ClinicalNote::query()
                    ->whereKey($data['parent_note_id'])
                    ->where('medical_consultation_id', $locked->getKey())
                    ->value('id');
            }
            $note = ClinicalNote::query()->create([
                'encounter_id' => $locked->encounter_id,
                'medical_consultation_id' => $locked->getKey(),
                'author_id' => $user->getKey(),
                'specialty_id' => $locked->specialty_id,
                'note_type' => $parentId === null ? $data['note_type'] : 'addendum',
                'content' => $data['content'],
                'clinical_at' => $data['clinical_at'] ?? now(),
                'status' => 'finalized',
                'finalized_at' => now(),
                'parent_note_id' => $parentId,
                'addendum_reason' => $data['addendum_reason'] ?? null,
            ]);
            $this->guard->increment($locked);
            $this->audit->execute(
                'medical.clinical_note_finalized',
                $request,
                $user,
                ['consultation' => $locked->public_id, 'note' => $note->public_id, 'note_type' => $note->note_type],
                (int) $unit->getKey(),
                (int) $locked->encounter->patient_id,
                (int) $locked->encounter_id,
            );

            return $note;
        });
    }
}
