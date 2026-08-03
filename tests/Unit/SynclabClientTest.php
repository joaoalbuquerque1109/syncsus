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
            'https://synclab.example/app/addrequisicao/10' => Http::response(['id' => '123'], 201),
        ]);
        $integration = new LaboratoryIntegration([
            'base_url' => 'https://synclab.example',
            'external_tenant_code' => '10',
            'username' => 'user',
            'password' => 'secret',
        ]);

        $result = app(SynclabClient::class)->submitOrder(
            $integration,
            new LaboratoryOrderPayload(['ordem_servico' => '123']),
        );

        $this->assertFalse($result->accepted);
        $this->assertSame(201, $result->httpStatus);
    }

    public function test_client_uses_basic_auth_and_unit_tenant_code(): void
    {
        Http::fake([
            'https://synclab.example/app/addrequisicao/UNIT-10' => Http::response(['ok' => true], 200),
        ]);
        $integration = new LaboratoryIntegration([
            'base_url' => 'https://synclab.example/',
            'external_tenant_code' => 'UNIT-10',
            'username' => 'user',
            'password' => 'secret',
        ]);

        $result = app(SynclabClient::class)->submitOrder(
            $integration,
            new LaboratoryOrderPayload(['ordem_servico' => '123']),
        );

        $this->assertTrue($result->accepted);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://synclab.example/app/addrequisicao/UNIT-10'
            && $request->hasHeader('Authorization', 'Basic '.base64_encode('user:secret'))
            && $request['pedido_lab']['ordem_servico'] === '123');
    }
}
