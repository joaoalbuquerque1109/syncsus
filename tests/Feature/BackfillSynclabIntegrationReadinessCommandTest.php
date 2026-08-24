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

    public function test_backfill_enables_a_stub_whose_external_tenant_code_already_matches_the_unit_cnes(): void
    {
        // Reproduz o bug real de producao: a tela de catalogo de exames
        // (CatalogManagementController) ja preenche external_tenant_code
        // automaticamente com o CNES da propria unidade ao criar a linha - isso
        // nao e uma configuracao manual e nao deveria bloquear o backfill.
        $unit = $this->createHealthUnit('BACKFILL-SYNCLAB-AUTOFILLED');
        $unit->update(['cnes_code' => '6612550']);
        $this->activateTenant($unit);
        LaboratoryIntegration::query()->create([
            'organization_id' => $unit->organization_id,
            'health_unit_id' => $unit->getKey(),
            'provider' => 'synclab',
            'base_url' => 'https://synclabweb.unisync.com.br',
            'external_tenant_code' => '6612550',
            'is_active' => true,
            'transmission_enabled' => false,
            'connection_status' => 'not_configured',
        ]);

        $this->artisan('sync-sus:backfill-synclab-readiness')->run();

        $this->activateTenant($unit);
        $integration = LaboratoryIntegration::query()->sole();
        $this->assertTrue($integration->transmission_enabled);
        $this->assertSame('configured', $integration->connection_status);
    }

    public function test_backfill_does_not_overwrite_a_unit_whose_external_tenant_code_diverges_from_its_cnes(): void
    {
        $unit = $this->createHealthUnit('BACKFILL-SYNCLAB-DIVERGENT');
        $unit->update(['cnes_code' => '6612551']);
        $this->activateTenant($unit);
        LaboratoryIntegration::query()->create([
            'organization_id' => $unit->organization_id,
            'health_unit_id' => $unit->getKey(),
            'provider' => 'synclab',
            'base_url' => 'https://synclabweb.unisync.com.br',
            'external_tenant_code' => 'SOME-OTHER-CODE',
            'is_active' => true,
            'transmission_enabled' => false,
            'connection_status' => 'not_configured',
        ]);

        $this->artisan('sync-sus:backfill-synclab-readiness')->run();

        $this->activateTenant($unit);
        $integration = LaboratoryIntegration::query()->sole();
        $this->assertFalse($integration->transmission_enabled);
        $this->assertSame('SOME-OTHER-CODE', $integration->external_tenant_code);
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
