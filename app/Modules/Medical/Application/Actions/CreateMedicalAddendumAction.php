<?php

declare(strict_types=1);

namespace App\Modules\Medical\Application\Actions;

use App\Modules\Administration\Infrastructure\Eloquent\HealthUnit;
use App\Modules\Audit\Application\Actions\RecordAuditEventAction;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Medical\Domain\Enums\MedicalConsultationStatus;
use App\Modules\Medical\Infrastructure\Eloquent\MedicalAddendum;
use App\Modules\Medical\Infrastructure\Eloquent\MedicalConsultation;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final readonly class CreateMedicalAddendumAction
{
    public function __construct(private RecordAuditEventAction $audit) {}

    public function execute(
        MedicalConsultation $consultation,
        string $reason,
        string $content,
        User $user,
        HealthUnit $unit,
        Request $request,
    ): MedicalAddendum {
        $consultation->loadMissing('encounter');
        if ($consultation->statusEnum() !== MedicalConsultationStatus::Finalized) {
            throw ValidationException::withMessages(['status' => 'Adendos são permitidos somente após a finalização.']);
        }
        $addendum = $consultation->addenda()->create([
            'author_id' => $user->getKey(),
            'reason' => $reason,
            'content' => $content,
            'recorded_at' => now(),
        ]);
        $this->audit->execute(
            'medical.addendum_created',
            $request,
            $user,
            ['consultation' => $consultation->public_id, 'addendum' => $addendum->public_id],
            (int) $unit->getKey(),
            (int) $consultation->encounter->patient_id,
            (int) $consultation->encounter_id,
        );

        return $addendum;
    }
}
