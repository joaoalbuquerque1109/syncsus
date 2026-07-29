<?php

declare(strict_types=1);

namespace App\Modules\Identity\Presentation\Http\Middleware;

use App\Modules\Administration\Infrastructure\Eloquent\HealthUnit;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Professionals\Application\Services\MedicalDutyService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

final class EnsureActiveHealthUnit
{
    public function __construct(private readonly MedicalDutyService $medicalDuty) {}

    /** @param  Closure(Request): Response  $next */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $units = $user->isPlatformAdministrator()
            ? HealthUnit::query()
                ->with('organization')
                ->where('health_units.is_active', true)
                ->whereHas('organization', fn ($query) => $query->where('is_active', true))
                ->orderBy('health_units.name')
                ->get()
            : $user->healthUnits()
                ->with('organization')
                ->where('health_units.organization_id', $user->organization_id)
                ->where('health_units.is_active', true)
                ->whereHas('organization', fn ($query) => $query->where('is_active', true))
                ->orderBy('health_units.name')
                ->get();

        abort_if($units->isEmpty(), 403, 'Seu usuário não possui vínculo com uma unidade ativa.');

        $activeUnitId = (int) $request->session()->get('active_health_unit_id', 0);
        $activeUnit = $units->firstWhere('id', $activeUnitId);

        if (! $activeUnit instanceof HealthUnit) {
            abort_if($activeUnitId > 0, 404, 'A unidade ativa nao esta disponivel para este usuario.');
            $activeUnit = $user->isPlatformAdministrator()
                ? $units->first()
                : ($units->firstWhere('id', $user->default_health_unit_id) ?? $units->first());
            $request->session()->put('active_health_unit_id', $activeUnit->getKey());
        }

        $request->attributes->set('active_health_unit', $activeUnit);
        $request->attributes->set('active_organization', $activeUnit->organization);
        View::share([
            'activeHealthUnit' => $activeUnit,
            'availableHealthUnits' => $units,
            'organizationHasManager' => User::query()
                ->where('organization_id', $activeUnit->organization_id)
                ->where('is_active', true)
                ->whereHas('roles', fn ($query) => $query->where('name', 'manager'))
                ->exists(),
            'medicalDutyAttendance' => $user->hasRole('doctor')
                ? $this->medicalDuty->current($user, $activeUnit)
                : null,
        ]);

        return $next($request);
    }
}
