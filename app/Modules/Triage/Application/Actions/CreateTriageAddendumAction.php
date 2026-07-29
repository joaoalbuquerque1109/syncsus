<?php

declare(strict_types=1);

namespace App\Modules\Triage\Application\Actions;

use App\Modules\Administration\Infrastructure\Eloquent\HealthUnit;
use App\Modules\Audit\Application\Actions\RecordAuditEventAction;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Triage\Domain\Enums\TriageAssessmentStatus;
use App\Modules\Triage\Infrastructure\Eloquent\TriageAddendum;
use App\Modules\Triage\Infrastructure\Eloquent\TriageAssessment;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final readonly class CreateTriageAddendumAction
{
    public function __construct(private RecordAuditEventAction $audit) {}

    public function execute(
        TriageAssessment $assessment,
        string $reason,
        string $content,
        User $user,
        HealthUnit $unit,
        Request $request,
    ): TriageAddendum {
        $assessment->loadMissing('encounter');
        if ($assessment->statusEnum() !== TriageAssessmentStatus::Finalized) {
            throw ValidationException::withMessages(['status' => 'Adendos são permitidos somente após a finalização.']);
        }
        $addendum = $assessment->addenda()->create([
            'author_id' => $user->getKey(),
            'reason' => $reason,
            'content' => $content,
            'recorded_at' => now(),
        ]);
        $this->audit->execute(
            'triage.addendum_created',
            $request,
            $user,
            ['triage' => $assessment->public_id, 'addendum' => $addendum->public_id],
            (int) $unit->getKey(),
            (int) $assessment->encounter->patient_id,
            (int) $assessment->encounter_id,
        );

        return $addendum;
    }
}
