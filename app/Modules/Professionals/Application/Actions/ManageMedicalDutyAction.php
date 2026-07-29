<?php

declare(strict_types=1);

namespace App\Modules\Professionals\Application\Actions;

use App\Modules\Administration\Infrastructure\Eloquent\HealthUnit;
use App\Modules\Audit\Application\Actions\RecordAuditEventAction;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Professionals\Application\Services\MedicalDutyService;
use App\Modules\Professionals\Infrastructure\Eloquent\MedicalShiftAttendance;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class ManageMedicalDutyAction
{
    public function __construct(
        private MedicalDutyService $duty,
        private RecordAuditEventAction $audit,
    ) {}

    public function checkIn(User $user, HealthUnit $unit, Request $request): MedicalShiftAttendance
    {
        $this->ensureEligibleDoctor($user, $unit);

        return DB::transaction(function () use ($user, $unit, $request): MedicalShiftAttendance {
            $attendance = MedicalShiftAttendance::query()
                ->where('health_unit_id', $unit->getKey())
                ->where('user_id', $user->getKey())
                ->whereDate('duty_date', $this->duty->dutyDate($unit))
                ->lockForUpdate()
                ->first();
            if (! $attendance instanceof MedicalShiftAttendance) {
                $attendance = MedicalShiftAttendance::query()->create([
                    'organization_id' => $unit->organization_id,
                    'health_unit_id' => $unit->getKey(),
                    'user_id' => $user->getKey(),
                    'duty_date' => $this->duty->dutyDate($unit),
                    'checked_in_at' => now(),
                ]);
            } elseif ($attendance->checked_out_at !== null) {
                $attendance->update([
                    'checked_in_at' => now(),
                    'checked_out_at' => null,
                    'checked_out_by' => null,
                    'checkout_reason' => null,
                ]);
            }
            $this->audit->execute(
                'medical_duty.checked_in',
                $request,
                $user,
                ['attendance' => $attendance->public_id, 'duty_date' => $this->duty->dutyDate($unit)],
                (int) $unit->getKey(),
            );

            return $attendance->fresh() ?? $attendance;
        });
    }

    public function checkOut(
        User $user,
        HealthUnit $unit,
        string $reason,
        Request $request,
    ): MedicalShiftAttendance {
        $this->ensureEligibleDoctor($user, $unit);
        if ($this->duty->hasActiveConsultation($user, $unit)) {
            throw ValidationException::withMessages([
                'reason' => 'Finalize os atendimentos médicos em andamento antes de encerrar o plantão.',
            ]);
        }

        return DB::transaction(function () use ($user, $unit, $reason, $request): MedicalShiftAttendance {
            $attendance = MedicalShiftAttendance::query()
                ->where('health_unit_id', $unit->getKey())
                ->where('user_id', $user->getKey())
                ->whereDate('duty_date', $this->duty->dutyDate($unit))
                ->whereNull('checked_out_at')
                ->lockForUpdate()
                ->first();
            if (! $attendance instanceof MedicalShiftAttendance) {
                throw ValidationException::withMessages([
                    'reason' => 'Não há check-in ativo para encerrar nesta unidade.',
                ]);
            }
            $attendance->update([
                'checked_out_at' => now(),
                'checked_out_by' => $user->getKey(),
                'checkout_reason' => $reason,
            ]);
            $this->audit->execute(
                'medical_duty.checked_out',
                $request,
                $user,
                ['attendance' => $attendance->public_id, 'reason' => $reason],
                (int) $unit->getKey(),
            );

            return $attendance->fresh() ?? $attendance;
        });
    }

    private function ensureEligibleDoctor(User $user, HealthUnit $unit): void
    {
        $profile = $user->professionalProfile()
            ->where('organization_id', $unit->organization_id)
            ->where('profession_type', 'doctor')
            ->where('is_active', true)
            ->whereHas('healthUnits', fn ($query) => $query->whereKey($unit->getKey()))
            ->first();
        if (! $user->hasRole('doctor') || $profile === null || ! $profile->specialties()->exists()) {
            throw ValidationException::withMessages([
                'medical_duty' => 'O check-in exige médico ativo, vinculado ao local e com especialidade cadastrada.',
            ]);
        }
    }
}
