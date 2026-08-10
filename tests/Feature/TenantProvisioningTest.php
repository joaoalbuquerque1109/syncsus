<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Administration\Application\Actions\ProvisionTenantAction;
use App\Modules\Administration\Infrastructure\Eloquent\HealthUnit;
use App\Modules\Administration\Infrastructure\Eloquent\Organization;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use Database\Seeders\RolePermissionSeeder;
use RuntimeException;
use Tests\Concerns\RefreshCoreAndTenantDatabase;
use Tests\TestCase;

final class TenantProvisioningTest extends TestCase
{
    use RefreshCoreAndTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_global_administrator_can_provision_complete_tenant(): void
    {
        $administrator = $this->createPlatformAdministrator();
        $administrator->assignRole('administrator');

        $response = $this->actingAs($administrator)->post(route('administration.tenants.store'), [
            'cnes_code' => '6612547',
            'legal_name' => 'Fundo Municipal de Saúde de São Caetano',
            'trade_name' => 'Unidade São Caetano',
            'document_number' => '12.345.678/0001-90',
            'city' => 'São Caetano',
            'state' => 'PE',
            'manager_name' => 'Gestora da Unidade',
            'manager_email' => 'gestora@saocaetano.test',
            'manager_password' => 'Temporary#Password2026',
            'manager_password_confirmation' => 'Temporary#Password2026',
        ]);

        $organization = Organization::query()->where('cnes_code', '6612547')->firstOrFail();
        $unit = $organization->healthUnits()->firstOrFail();
        $manager = User::query()->where('organization_id', $organization->getKey())->firstOrFail();

        $response->assertRedirect(route('administration.tenants.index'));
        $this->assertSame('6612547', $organization->code);
        $this->assertSame('6612547', $unit->cnes_code);
        $this->assertTrue($manager->hasRole('manager'));
        $this->assertTrue($manager->healthUnits()->whereKey($unit->getKey())->exists());
        $this->assertSame($unit->getKey(), $manager->default_health_unit_id);
        $this->assertTrue($manager->must_change_password);
        $this->assertDatabaseCount('specialties', 3);
        $this->assertDatabaseCount('arrival_methods', 4);
        $this->assertDatabaseCount('entry_types', 3);
        $this->assertDatabaseCount('queues', 4);
        $this->assertDatabaseCount('panels', 1);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'organization.provisioned',
            'health_unit_id' => $unit->getKey(),
        ]);
    }

    public function test_non_platform_user_cannot_access_tenant_provisioning(): void
    {
        $unit = $this->createHealthUnit();
        $manager = $this->createUserWithUnit($unit, ['must_change_password' => false]);
        $manager->assignRole('manager');

        $this->actingAs($manager)->get(route('administration.tenants.index'))->assertForbidden();
        $this->actingAs($manager)->post(route('administration.tenants.store'), [])->assertForbidden();
    }

    public function test_cnes_cannot_be_reused_by_another_tenant(): void
    {
        $administrator = $this->createPlatformAdministrator();
        $administrator->assignRole('administrator');
        $this->createHealthUnit('CNES-EXISTENTE')->organization->update(['cnes_code' => '6612547']);

        $this->actingAs($administrator)->post(route('administration.tenants.store'), [
            'cnes_code' => '6612547',
            'legal_name' => 'Outra organização',
            'trade_name' => 'Outra unidade',
            'manager_name' => 'Outra gestora',
            'manager_email' => 'outra@example.test',
            'manager_password' => 'Temporary#Password2026',
            'manager_password_confirmation' => 'Temporary#Password2026',
        ])->assertSessionHasErrors('cnes_code');

        $this->assertDatabaseCount('organizations', 1);
    }

    public function test_additional_unit_cannot_be_created_inside_an_existing_tenant(): void
    {
        $administrator = $this->createPlatformAdministrator();
        $administrator->assignRole('administrator');
        $unit = $this->createHealthUnit();

        $this->actingAs($administrator)
            ->withSession(['active_health_unit_id' => $unit->getKey()])
            ->post(route('administration.catalogs.store', 'health-units'), [
                'organization_id' => $unit->organization_id,
                'code' => 'SEGUNDA-UNIDADE',
                'name' => 'Segunda unidade',
                'cnes_code' => '7654321',
            ])
            ->assertSessionHasErrors('health_unit');

        $this->assertDatabaseCount('health_units', 1);
    }

    public function test_organization_and_unit_are_not_persisted_when_provisioning_fails_partway(): void
    {
        HealthUnit::created(function (): void {
            throw new RuntimeException('Falha simulada depois de criar a unidade.');
        });

        try {
            app(ProvisionTenantAction::class)->execute([
                'cnes_code' => '9988776',
                'legal_name' => 'Organização Falha',
                'trade_name' => 'Unidade Falha',
                'manager_name' => 'Gestor',
                'manager_email' => 'gestor@falha.test',
                'manager_password' => 'Temporary#Password2026',
            ]);
            $this->fail('Deveria ter propagado a falha simulada.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Falha simulada depois de criar a unidade.', $exception->getMessage());
        }

        $this->assertSame(0, Organization::query()->where('cnes_code', '9988776')->count());
        $this->assertDatabaseMissing('health_units', ['cnes_code' => '9988776']);
    }

    public function test_global_administrator_without_units_is_sent_to_initial_provisioning(): void
    {
        $administrator = $this->createPlatformAdministrator([
            'email' => 'admin@syncsus.local',
            'password' => 'Demo#SyncHOSP2026',
        ]);
        $administrator->assignRole('administrator');

        $this->post(route('login.store'), [
            'unit_code' => config('sync_sus.admin.access_code'),
            'email' => 'admin@syncsus.local',
            'password' => 'Demo#SyncHOSP2026',
        ])->assertRedirect(route('administration.tenants.index', absolute: false));
    }
}
