<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Audit\Infrastructure\Eloquent\AuditLog;
use App\Modules\Laboratory\Infrastructure\Eloquent\LaboratoryIntegration;
use Database\Seeders\RolePermissionSeeder;
use Tests\Concerns\RefreshCoreAndTenantDatabase;
use Tests\TestCase;

final class SynclabIntegrationAdministrationTest extends TestCase
{
    use RefreshCoreAndTenantDatabase;

    public function test_manager_configures_only_active_units_synclab_without_exposing_credentials(): void
    {
        config()->set('sync_sus.synclab.enabled', true);
        $unit = $this->createHealthUnit('CENTRAL');
        $otherUnit = $this->createHealthUnit('NORTH');
        $this->seed(RolePermissionSeeder::class);
        $manager = $this->createUserWithUnit($unit, ['must_change_password' => false]);
        $manager->assignRole('manager');
        $session = ['active_health_unit_id' => $unit->getKey()];

        $this->actingAs($manager)->withSession($session)
            ->get(route('administration.synclab.edit'))
            ->assertOk()
            ->assertSee('Integração Synclab')
            ->assertSee('Não configuradas');

        $this->actingAs($manager)->withSession($session)
            ->put(route('administration.synclab.update'), [
                'base_url' => 'https://synclabweb.unisync.com.br/',
                'cnes_code' => '6612547',
                'username' => 'integration-user',
                'password' => 'integration-secret',
                'transmission_enabled' => '1',
            ])
            ->assertRedirect(route('administration.synclab.edit'));

        $integration = LaboratoryIntegration::query()->sole();
        $this->assertSame($unit->getKey(), $integration->health_unit_id);
        $this->assertSame('6612547', $unit->fresh()->cnes_code);
        $this->assertTrue($integration->transmission_enabled);
        $this->assertTrue($integration->hasCredentials());
        $this->assertNotSame('integration-secret', $integration->getRawOriginal('password'));
        $this->assertDatabaseMissing('laboratory_integrations', ['health_unit_id' => $otherUnit->getKey()]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'laboratory.integration_updated']);

        $this->actingAs($manager)->withSession($session)
            ->get(route('administration.synclab.edit'))
            ->assertOk()
            ->assertSee('Configuradas e criptografadas')
            ->assertDontSee('integration-secret');
    }

    public function test_credentials_are_required_before_enabling_transmission(): void
    {
        $unit = $this->createHealthUnit('CENTRAL');
        $this->seed(RolePermissionSeeder::class);
        $manager = $this->createUserWithUnit($unit, ['must_change_password' => false]);
        $manager->assignRole('manager');

        $this->actingAs($manager)
            ->withSession(['active_health_unit_id' => $unit->getKey()])
            ->put(route('administration.synclab.update'), [
                'base_url' => 'https://synclabweb.unisync.com.br',
                'cnes_code' => '6612547',
                'transmission_enabled' => '1',
            ])
            ->assertSessionHasErrors('username');

        $this->assertDatabaseCount('laboratory_integrations', 0);
    }

    public function test_manager_rotates_result_token_without_persisting_plaintext_and_enables_reception(): void
    {
        $unit = $this->createHealthUnit('RESULTS');
        $this->seed(RolePermissionSeeder::class);
        $manager = $this->createUserWithUnit($unit, ['must_change_password' => false]);
        $manager->assignRole('manager');
        $integration = LaboratoryIntegration::query()->create([
            'organization_id' => $unit->organization_id,
            'health_unit_id' => $unit->getKey(),
            'provider' => 'synclab',
            'is_active' => true,
        ]);
        $session = ['active_health_unit_id' => $unit->getKey()];

        $response = $this->actingAs($manager)->withSession($session)
            ->post(route('administration.synclab.result-token.rotate'))
            ->assertRedirect(route('administration.synclab.edit'))
            ->assertSessionHas('synclab_result_token');
        $token = (string) $response->getSession()->get('synclab_result_token');

        $integration->refresh();
        $this->assertNotSame($token, $integration->result_api_token_hash);
        $this->assertSame(hash('sha256', $token), $integration->result_api_token_hash);
        $this->assertNotNull($integration->result_api_token_rotated_at);
        $this->assertStringNotContainsString($token, LaboratoryIntegration::query()->get()->toJson());
        $this->assertStringNotContainsString($token, (string) json_encode(
            AuditLog::query()->get()->toArray(),
        ));

        $this->actingAs($manager)->withSession($session)
            ->put(route('administration.synclab.update'), [
                'base_url' => 'https://synclabweb.unisync.com.br',
                'cnes_code' => $unit->cnes_code,
                'result_sync_enabled' => '1',
            ])
            ->assertRedirect(route('administration.synclab.edit'));

        $this->assertTrue($integration->fresh()?->result_sync_enabled);
        $this->assertDatabaseHas('audit_logs', ['action' => 'laboratory.result_token_rotated']);
    }
}
