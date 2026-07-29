<?php

declare(strict_types=1);

namespace App\Modules\Professionals\Application\Services;

use App\Modules\Administration\Infrastructure\Eloquent\HealthUnit;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Medical\Domain\Enums\MedicalConsultationStatus;
use App\Modules\Medical\Infrastructure\Eloquent\MedicalConsultation;
use App\Modules\Professionals\Infrastructure\Eloquent\MedicalShiftAttendance;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

final class MedicalDutyService
{
    public function dutyDate(HealthUnit $unit): string
    {
        $timezone = (string) ($unit->organization?->timezone ?: config('app.timezone'));

        return CarbonImmutable::now($timezone)->toDateString();
    }

    public function current(User $user, HealthUnit $unit): ?MedicalShiftAttendance
    {
        return MedicalShiftAttendance::query()
            ->where('organization_id', $unit->organization_id)
            ->where('health_unit_id', $unit->getKey())
            ->where('user_id', $user->getKey())
            ->whereDate('duty_date', $this->dutyDate($unit))
            ->first();
    }

    public function isCheckedIn(User $user, HealthUnit $unit): bool
    {
        $attendance = $this->current($user, $unit);

        return $attendance instanceof MedicalShiftAttendance && $attendance->checked_out_at === null;
    }

    public function ensureCheckedIn(User $user, HealthUnit $unit): void
    {
        if (! $this->isCheckedIn($user, $unit)) {
            throw ValidationException::withMessages([
                'medical_duty' => 'Faça o check-in do plantão nesta unidade antes de operar a fila médica.',
            ]);
        }
    }

    /** @return Collection<int, User> */
    public function availableDoctors(HealthUnit $unit, ?int $specialtyId = null): Collection
    {
        return User::query()
            ->where('organization_id', $unit->organization_id)
            ->where('is_active', true)
            ->whereHas('healthUnits', fn (Builder $query) => $query->whereKey($unit->getKey()))
            ->whereHas('professionalProfile', function (Builder $query) use ($unit, $specialtyId): void {
                $query->where('organization_id', $unit->organization_id)
                    ->where('profession_type', 'doctor')
                    ->where('is_active', true)
                    ->whereHas('healthUnits', fn (Builder $units) => $units->whereKey($unit->getKey()));
                if ($specialtyId !== null) {
                    $query->whereHas('specialties', fn (Builder $specialties) => $specialties->whereKey($specialtyId));
                }
            })
            ->whereHas('roles', fn (Builder $query) => $query->where('name', 'doctor'))
            ->whereHas('medicalShiftAttendances', function (Builder $query) use ($unit): void {
                $query->where('health_unit_id', $unit->getKey())
                    ->whereDate('duty_date', $this->dutyDate($unit))
                    ->whereNull('checked_out_at');
            })
            ->with('professionalProfile.specialties')
            ->orderBy('name')
            ->get();
    }

    public function hasActiveConsultation(User $user, HealthUnit $unit): bool
    {
        return MedicalConsultation::query()
            ->where('professional_id', $user->getKey())
            ->where('status', MedicalConsultationStatus::Draft)
            ->whereHas('encounter', fn (Builder $query) => $query->where('health_unit_id', $unit->getKey()))
            ->exists();
    }
}
