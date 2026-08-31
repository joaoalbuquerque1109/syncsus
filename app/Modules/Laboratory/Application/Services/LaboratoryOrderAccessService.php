<?php

declare(strict_types=1);

namespace App\Modules\Laboratory\Application\Services;

use App\Modules\Administration\Infrastructure\Eloquent\HealthUnit;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Medical\Infrastructure\Eloquent\ExamOrder;
use Illuminate\Database\Eloquent\Builder;

final class LaboratoryOrderAccessService
{
    /**
     * Mesma regra de visibilidade de canView(), em forma de filtro de query -
     * usado por telas que listam pedidos/resultados em vez de checar um só.
     *
     * @param  Builder<ExamOrder>  $query
     * @return Builder<ExamOrder>
     */
    public function applyVisibilityScope(Builder $query, User $user, HealthUnit $unit): Builder
    {
        $query->where('organization_id', $unit->organization_id)
            ->where('health_unit_id', $unit->getKey());

        if ($user->isPlatformAdministrator()) {
            return $query;
        }

        if ($user->hasRole('manager')) {
            return $user->can('administration.manage') ? $query : $query->whereRaw('1 = 0');
        }

        if ($user->hasRole('receptionist')) {
            return $query->where('origin', 'reception');
        }

        return $query->where('requested_by', $user->getKey());
    }

    public function canView(User $user, ExamOrder $order, HealthUnit $unit): bool
    {
        if (! $this->belongsToUnit($order, $unit)) {
            return false;
        }

        if ($user->isPlatformAdministrator()) {
            return true;
        }

        if ($user->hasRole('manager')) {
            return $user->can('administration.manage');
        }

        if ($user->hasRole('receptionist')) {
            return $order->origin === 'reception';
        }

        return $user->hasRole('doctor')
            && (int) $order->requested_by === (int) $user->getKey();
    }

    public function canCancel(User $user, ExamOrder $order, HealthUnit $unit): bool
    {
        return $user->can('laboratory.orders.cancel')
            && $this->canView($user, $order, $unit)
            && ! $user->hasRole('manager');
    }

    public function canViewClinicalDetails(User $user): bool
    {
        return $user->isPlatformAdministrator() || ! $user->hasRole('manager');
    }

    private function belongsToUnit(ExamOrder $order, HealthUnit $unit): bool
    {
        return (int) $order->organization_id === (int) $unit->organization_id
            && (int) $order->health_unit_id === (int) $unit->getKey();
    }
}
