<?php

declare(strict_types=1);

namespace App\Modules\Laboratory\Infrastructure\Synclab;

use App\Modules\Laboratory\Application\Contracts\LaboratoryProviderClient;
use App\Modules\Laboratory\Application\Data\LaboratoryOrderPayload;
use App\Modules\Laboratory\Application\Data\LaboratorySubmissionResult;
use App\Modules\Laboratory\Application\Exceptions\InvalidLaboratoryOrder;
use App\Modules\Laboratory\Infrastructure\Eloquent\LaboratoryIntegration;
use Illuminate\Http\Client\Factory;

final readonly class SynclabClient implements LaboratoryProviderClient
{
    public function __construct(private Factory $http) {}

    public function submitOrder(
        LaboratoryIntegration $integration,
        LaboratoryOrderPayload $payload,
    ): LaboratorySubmissionResult {
        $baseUrl = rtrim((string) $integration->base_url, '/');
        $tenant = trim((string) $integration->external_tenant_code);
        $username = (string) $integration->username;
        $password = (string) $integration->password;

        if (! filter_var($baseUrl, FILTER_VALIDATE_URL) || ! str_starts_with($baseUrl, 'https://')) {
            throw new InvalidLaboratoryOrder('A URL HTTPS do Synclab nao foi configurada corretamente.');
        }
        if ($tenant === '' || $username === '' || $password === '') {
            throw new InvalidLaboratoryOrder('A identificacao externa e as credenciais do Synclab sao obrigatorias.');
        }

        $response = $this->http
            ->baseUrl($baseUrl)
            ->acceptJson()
            ->asJson()
            ->withBasicAuth($username, $password)
            ->connectTimeout((int) config('sync_sus.synclab.connect_timeout_seconds'))
            ->timeout((int) config('sync_sus.synclab.timeout_seconds'))
            ->post('/app/addrequisicao/'.rawurlencode($tenant), $payload->toArray());

        $decoded = $response->json();
        $body = is_array($decoded) ? $decoded : null;

        // Contrato atualmente confirmado: somente HTTP 200 representa aceite.
        return new LaboratorySubmissionResult(
            accepted: $response->status() === 200,
            httpStatus: $response->status(),
            response: $body,
            responseHash: hash('sha256', $response->body()),
        );
    }
}
