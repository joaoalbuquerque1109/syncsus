<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Laboratory\Application\Data\LaboratoryOrderPayload;
use App\Modules\Laboratory\Infrastructure\Eloquent\LaboratoryIntegration;
use App\Modules\Laboratory\Infrastructure\Synclab\SynclabClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class SynclabClientTest extends TestCase
{
    public function test_only_http_200_is_considered_accepted(): void
    {
        Http::fake([
            'https://synclab.example/app/addrequisicao/1234567' => Http::response(['id' => '123'], 201),
        ]);
        $integration = new LaboratoryIntegration([
            'base_url' => 'https://synclab.example',
        ]);

        $result = app(SynclabClient::class)->submitOrder(
            $integration,
            new LaboratoryOrderPayload([
                'ordem_servico' => '123',
                'pedido' => ['cnesUnidadeExecutante' => 1234567],
            ]),
        );

        $this->assertFalse($result->accepted);
        $this->assertSame(201, $result->httpStatus);
    }

    public function test_client_sends_no_authorization_header_and_uses_health_unit_cnes(): void
    {
        Http::fake([
            'https://synclab.example/app/addrequisicao/6612547' => Http::response(['ok' => true], 200),
        ]);
        $integration = new LaboratoryIntegration([
            'base_url' => 'https://synclab.example/',
        ]);

        $result = app(SynclabClient::class)->submitOrder(
            $integration,
            new LaboratoryOrderPayload([
                'ordem_servico' => '123',
                'pedido' => ['cnesUnidadeExecutante' => 6612547],
            ]),
        );

        $this->assertTrue($result->accepted);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://synclab.example/app/addrequisicao/6612547'
            && ! $request->hasHeader('Authorization')
            && $request['pedido_lab']['ordem_servico'] === '123');
    }
}
