<?php

declare(strict_types=1);

namespace App\Modules\Identity\Presentation\Http\Middleware;

use App\Modules\Administration\Infrastructure\Eloquent\HealthUnit;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Support\Tenancy\TenantConnectionManager;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantResolver;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

final class EnsureActiveHealthUnit
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly TenantResolver $tenantResolver,
        private readonly TenantConnectionManager $connectionManager,
    ) {}

    /** @param  Closure(Request): Response  $next */
    public function handle(Request $request, Closure $next): Response
    {
        $previous = $this->tenantContext->isResolved()
            ? [$this->tenantContext->healthUnit(), $this->tenantContext->connectionName()]
            : null;
        $this->tenantContext->reset();
        try {
            $user = $request->user();
            abort_unless($user instanceof User, 401);

            if ($request->expectsJson()) {
                $activeUnit = $this->resolveActiveUnitForJsonRequest($request, $user);
                $this->setRequestContext($request, $activeUnit);

                return $next($request);
            }

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

            $this->setRequestContext($request, $activeUnit);
            View::share([
                'activeHealthUnit' => $activeUnit,
                'availableHealthUnits' => $units,
                'organizationHasManager' => $this->organizationHasManager($activeUnit),
            ]);

            return $next($request);
        } finally {
            $this->restoreContext($previous);
        }
    }

    private function resolveActiveUnitForJsonRequest(Request $request, User $user): HealthUnit
    {
        $activeUnitId = (int) $request->session()->get('active_health_unit_id', 0);
        $query = $user->isPlatformAdministrator()
            ? HealthUnit::query()
            : $user->healthUnits()->where('health_units.organization_id', $user->organization_id);

        $query
            ->with('organization')
            ->where('health_units.is_active', true)
            ->whereHas('organization', fn ($query) => $query->where('is_active', true));

        if ($activeUnitId > 0) {
            $activeUnit = $query->where('health_units.id', $activeUnitId)->first();
            abort_unless($activeUnit instanceof HealthUnit, 404, 'A unidade ativa nao esta disponivel para este usuario.');

            return $activeUnit;
        }

        $activeUnit = $query->orderBy('health_units.name')->first();
        abort_unless($activeUnit instanceof HealthUnit, 403, 'Seu usuario nao possui vinculo com uma unidade ativa.');
        $request->session()->put('active_health_unit_id', $activeUnit->getKey());

        return $activeUnit;
    }

    private function setRequestContext(Request $request, HealthUnit $activeUnit): void
    {
        $request->attributes->set('active_health_unit', $activeUnit);
        $request->attributes->set('active_organization', $activeUnit->organization);
        $resolvedUnit = $this->tenantResolver->resolve($request);
        $this->tenantContext->resolve($resolvedUnit, $this->connectionManager->connectionName($resolvedUnit));
    }

    private function organizationHasManager(HealthUnit $activeUnit): bool
    {
        $resolve = static fn (): bool => User::query()
            ->where('organization_id', $activeUnit->organization_id)
            ->where('is_active', true)
            ->whereHas('roles', fn ($query) => $query->where('name', 'manager'))
            ->exists();

        if (! config('sync_sus.performance_cache.enabled', true)) {
            return $resolve();
        }

        return Cache::remember(
            "syncsus:organization:{$activeUnit->organization_id}:has-manager",
            (int) config('sync_sus.performance_cache.navigation_seconds', 60),
            $resolve,
        );
    }

    /** @param array{HealthUnit, string}|null $previous */
    private function restoreContext(?array $previous): void
    {
        $this->tenantContext->reset();
        if ($previous !== null) {
            $this->tenantContext->resolve($previous[0], $previous[1]);
        }
    }
}
