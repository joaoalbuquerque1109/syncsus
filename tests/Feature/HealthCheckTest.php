<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Storage;
use Tests\Concerns\RefreshCoreAndTenantDatabase;
use Tests\TestCase;

final class HealthCheckTest extends TestCase
{
    use RefreshCoreAndTenantDatabase;

    public function test_liveness_endpoint_is_public(): void
    {
        $this->getJson('/health/live')
            ->assertOk()
            ->assertExactJson(['status' => 'ok', 'service' => 'sync-sus']);
    }

    public function test_readiness_checks_database_and_private_storage(): void
    {
        Storage::fake('local_private');

        $this->getJson('/health/ready')
            ->assertOk()
            ->assertExactJson(['status' => 'ready']);
    }
}
