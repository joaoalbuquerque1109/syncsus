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
        $username = (string) $integration->username;
        $password = (string) $integration->password;
        $cnes = preg_replace('/\D/', '', (string) data_get(
            $payload->toArray(),
            'pedido_lab.pedido.cnesUnidadeExecutante',
        ));

        if (! filter_var($baseUrl, FILTER_VALIDATE_URL) || ! str_starts_with($baseUrl, 'https://')) {
            throw new InvalidLaboratoryOrder('A URL HTTPS do Synclab nao foi configurada corretamente.');
        }
        if (strlen($cnes) !== 7 || $username === '' || $password === '') {
            throw new InvalidLaboratoryOrder('O CNES e as credenciais do Synclab sao obrigatorios.');
        }

        $response = $this->http
            ->baseUrl($baseUrl)
            ->acceptJson()
            ->asJson()
            ->withBasicAuth($username, $password)
            ->connectTimeout((int) config('sync_sus.synclab.connect_timeout_seconds'))
            ->timeout((int) config('sync_sus.synclab.timeout_seconds'))
            ->post('/app/addrequisicao/'.$cnes, $payload->toArray());

        $decoded = $response->json();
        $body = is_array($decoded) ? $decoded : null;

        $acceptedStatuses = config('synclab_contract.confirmed.accepted_http_statuses', [200]);

        return new LaboratorySubmissionResult(
            accepted: is_array($acceptedStatuses) && in_array($response->status(), $acceptedStatuses, true),
            httpStatus: $response->status(),
            response: $body,
            responseHash: hash('sha256', $response->body()),
        );
    }
}
