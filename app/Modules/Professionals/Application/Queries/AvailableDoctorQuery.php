<?php

declare(strict_types=1);

namespace App\Modules\Professionals\Application\Queries;

use App\Modules\Administration\Infrastructure\Eloquent\HealthUnit;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final class AvailableDoctorQuery
{
    /** @return Collection<int, User> */
    public function forUnit(HealthUnit $unit, ?int $specialtyId = null): Collection
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
            ->with('professionalProfile.specialties')
            ->orderBy('name')
            ->get();
    }
}
