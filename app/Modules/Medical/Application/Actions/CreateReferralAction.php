<?php

declare(strict_types=1);

namespace App\Modules\Medical\Application\Actions;

use App\Modules\Administration\Infrastructure\Eloquent\HealthUnit;
use App\Modules\Audit\Application\Actions\RecordAuditEventAction;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Medical\Application\Services\MedicalConsultationGuard;
use App\Modules\Medical\Infrastructure\Eloquent\MedicalConsultation;
use App\Modules\Medical\Infrastructure\Eloquent\Referral;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final readonly class CreateReferralAction
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
    ): Referral {
        return DB::transaction(function () use ($consultation, $data, $user, $unit, $request): Referral {
            $locked = $this->guard->lockDraft($consultation, $user, $unit, (int) $data['version']);
            $referral = Referral::query()->create([
                'encounter_id' => $locked->encounter_id,
                'medical_consultation_id' => $locked->getKey(),
                'requested_by' => $user->getKey(),
                'specialty_id' => $data['specialty_id'] ?? null,
                'referral_type' => $data['referral_type'],
                'destination' => $data['destination'],
                'recipient_professional' => $data['recipient_professional'] ?? null,
                'reason' => $data['reason'],
                'clinical_summary' => $data['clinical_summary'],
                'priority' => $data['priority'],
                'diagnostic_hypothesis' => $data['diagnostic_hypothesis'] ?? null,
                'guidance' => $data['guidance'] ?? null,
                'status' => 'issued',
                'issued_at' => now(),
            ]);
            $this->guard->increment($locked);
            $this->audit->execute(
                'medical.referral_issued',
                $request,
                $user,
                ['consultation' => $locked->public_id, 'referral' => $referral->public_id, 'type' => $referral->referral_type],
                (int) $unit->getKey(),
                (int) $locked->encounter->patient_id,
                (int) $locked->encounter_id,
            );

            return $referral;
        });
    }
}
