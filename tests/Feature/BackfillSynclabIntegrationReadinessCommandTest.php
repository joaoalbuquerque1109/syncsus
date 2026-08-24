<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Laboratory\Infrastructure\Eloquent\LaboratoryIntegration;
use Tests\Concerns\RefreshCoreAndTenantDatabase;
use Tests\TestCase;

final class BackfillSynclabIntegrationReadinessCommandTest extends TestCase
{
    use RefreshCoreAndTenantDatabase;

    public function test_backfill_enables_transmission_for_a_unit_with_no_integration_row(): void
    {
        $unit = $this->createHealthUnit('BACKFILL-SYNCLAB-NEW');
        $unit->update(['cnes_code' => '6612547']);

        $exitCode = $this->artisan('sync-sus:backfill-synclab-readiness')->run();

        $this->assertSame(0, $exitCode);
        $this->activateTenant($unit);
        $integration = LaboratoryIntegration::query()->sole();
        $this->assertTrue($integration->transmission_enabled);
        $this->assertSame('6612547', $integration->external_tenant_code);
        $this->assertFalse($integration->hasCredentials());
    }

    public function test_backfill_upgrades_a_stub_row_left_by_the_catalog_seeder(): void
    {
        $unit = $this->createHealthUnit('BACKFILL-SYNCLAB-STUB');
        $unit->update(['cnes_code' => '6612548']);
        $this->activateTenant($unit);
        LaboratoryIntegration::query()->create([
            'organization_id' => $unit->organization_id,
            'health_unit_id' => $unit->getKey(),
            'provider' => 'synclab',
            'base_url' => 'https://synclabweb.unisync.com.br',
            'is_active' => true,
            'transmission_enabled' => false,
            'connection_status' => 'not_configured',
        ]);

        $this->artisan('sync-sus:backfill-synclab-readiness')->run();

        $this->activateTenant($unit);
        $integration = LaboratoryIntegration::query()->sole();
        $this->assertTrue($integration->transmission_enabled);
        $this->assertSame('6612548', $integration->external_tenant_code);
        $this->assertSame('configured', $integration->connection_status);
    }

    public function test_backfill_does_not_overwrite_a_unit_that_was_manually_configured(): void
    {
        $unit = $this->createHealthUnit('BACKFILL-SYNCLAB-MANUAL');
        $unit->update(['cnes_code' => '6612549']);
        $this->activateTenant($unit);
        LaboratoryIntegration::query()->create([
            'organization_id' => $unit->organization_id,
            'health_unit_id' => $unit->getKey(),
            'provider' => 'synclab',
            'base_url' => 'https://custom.example.com',
            'username' => 'manually-set-user',
            'password' => 'manually-set-password',
            'is_active' => true,
            'transmission_enabled' => false,
            'connection_status' => 'disabled',
        ]);

        $this->artisan('sync-sus:backfill-synclab-readiness')->run();

        $this->activateTenant($unit);
        $integration = LaboratoryIntegration::query()->sole();
        $this->assertFalse($integration->transmission_enabled);
        $this->assertSame('https://custom.example.com', $integration->base_url);
    }
}
