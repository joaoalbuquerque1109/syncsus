<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Modules\Laboratory\Infrastructure\Eloquent\LaboratoryIntegration;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class AuthenticateSynclabResultWebhook
{
    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $token = trim((string) $request->header('X-Synclab-Result-Token'));
        if ($token === '') {
            return new JsonResponse(['message' => 'Token de resultado ausente.'], 401);
        }

        $integration = LaboratoryIntegration::query()
            ->where('provider', 'synclab')
            ->where('result_api_token_hash', hash('sha256', $token))
            ->first();
        if ($integration === null) {
            return new JsonResponse(['message' => 'Token de resultado inválido.'], 401);
        }
        if (! config('sync_sus.synclab.results_enabled')
            || ! $integration->is_active
            || ! $integration->result_sync_enabled) {
            return new JsonResponse(['message' => 'Recepção de resultados desabilitada.'], 403);
        }

        $request->attributes->set('laboratory_integration', $integration);

        return $next($request);
    }
}
