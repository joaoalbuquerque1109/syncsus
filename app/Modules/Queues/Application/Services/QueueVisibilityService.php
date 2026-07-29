<?php

declare(strict_types=1);

namespace App\Modules\Queues\Application\Services;

use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Queues\Infrastructure\Eloquent\Queue;
use Illuminate\Database\Eloquent\Builder;

final class QueueVisibilityService
{
    /** @param Builder<Queue> $query
     * @return Builder<Queue>
     */
    public function apply(Builder $query, User $user): Builder
    {
        if ($user->hasAnyRole(['administrator', 'manager', 'receptionist'])) {
            return $query;
        }

        $canTriage = $user->hasRole('triage_professional');
        $canMedical = $user->hasRole('doctor');
        if (! $canTriage && ! $canMedical) {
            return $query->whereRaw('1 = 0');
        }

        $specialtyIds = [];
        $professionalUnitIds = [];
        if ($canMedical) {
            $profile = $user->professionalProfile;
            if ($profile !== null && $profile->is_active && $profile->profession_type === 'doctor') {
                $specialtyIds = $profile->specialties()->pluck('specialties.id')->map(
                    static fn (mixed $id): int => (int) $id,
                )->all();
                $professionalUnitIds = $profile->healthUnits()->pluck('health_units.id')->map(
                    static fn (mixed $id): int => (int) $id,
                )->all();
            }
        }

        return $query->where(function (Builder $visibility) use (
            $canTriage,
            $canMedical,
            $specialtyIds,
            $professionalUnitIds,
        ): void {
            if ($canTriage) {
                $visibility->orWhereHas('department', fn (Builder $department) => $department->where('type', 'triage'));
            }
            if ($canMedical && $specialtyIds !== [] && $professionalUnitIds !== []) {
                $visibility->orWhere(function (Builder $medical) use ($specialtyIds, $professionalUnitIds): void {
                    $medical->whereHas('department', fn (Builder $department) => $department->where('type', 'medical'));
                    $medical->whereIn('specialty_id', $specialtyIds)
                        ->whereIn('health_unit_id', $professionalUnitIds);
                });
            }
        });
    }

    public function ensureCanAccess(Queue $queue, User $user): void
    {
        abort_unless($this->apply(Queue::query()->whereKey($queue->getKey()), $user)->exists(), 404);
    }
}
