<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Modules\Administration\Infrastructure\Eloquent\HealthUnit;
use App\Modules\Laboratory\Infrastructure\Eloquent\LaboratoryIntegration;
use App\Support\Tenancy\TenantConnectionManager;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

final class AuthenticateSynclabResultWebhook
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly TenantConnectionManager $connectionManager,
    ) {}

    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $token = trim((string) $request->header('X-Synclab-Result-Token'));
        if ($token === '') {
            return new JsonResponse(['message' => 'Token de resultado ausente.'], 401);
        }

        // The token is the public lookup key for this anonymous endpoint. During
        // Phase 0 every tenant still shares the default physical connection.
        $integrationReference = DB::connection((string) config('database.default'))
            ->table('laboratory_integrations')
            ->where('provider', 'synclab')
            ->where('result_api_token_hash', hash('sha256', $token))
            ->first(['id', 'health_unit_id']);
        if ($integrationReference === null) {
            return new JsonResponse(['message' => 'Token de resultado inválido.'], 401);
        }

        $healthUnit = HealthUnit::query()->find($integrationReference->health_unit_id);
        if ($healthUnit === null) {
            return new JsonResponse(['message' => 'Token de resultado inválido.'], 401);
        }

        $previousHealthUnit = $this->tenantContext->isResolved()
            ? $this->tenantContext->healthUnit()
            : null;
        $previousConnection = $this->tenantContext->isResolved()
            ? $this->tenantContext->connectionName()
            : null;
        $this->tenantContext->reset();
        $this->tenantContext->resolve(
            $healthUnit,
            $this->connectionManager->connectionName($healthUnit),
        );

        try {
            $integration = LaboratoryIntegration::query()->find($integrationReference->id);
            if ($integration === null) {
                return new JsonResponse(['message' => 'Token de resultado inválido.'], 401);
            }
            if (! config('sync_sus.synclab.results_enabled')
                || ! $integration->is_active
                || ! $integration->result_sync_enabled) {
                return new JsonResponse(['message' => 'Recepção de resultados desabilitada.'], 403);
            }

            $request->attributes->set('laboratory_integration', $integration);
            $request->attributes->set('active_health_unit', $healthUnit);

            return $next($request);
        } finally {
            $this->tenantContext->reset();
            if ($previousHealthUnit !== null && $previousConnection !== null) {
                $this->tenantContext->resolve($previousHealthUnit, $previousConnection);
            }
        }
    }
}
