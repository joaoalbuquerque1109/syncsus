<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Administration\Application\Actions\ProvisionTenantAction;
use App\Modules\Administration\Application\Jobs\ProvisionTenantDatabaseJob;
use App\Modules\Administration\Infrastructure\Eloquent\HealthUnit;
use App\Modules\Administration\Infrastructure\Eloquent\Organization;
use App\Modules\Administration\Infrastructure\Eloquent\TenantDatabase;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Support\Tenancy\TenantConnectionManager;
use App\Support\Tenancy\TenantDatabaseLifecycle;
use App\Support\Tenancy\TenantDatabaseState;
use App\Support\Tenancy\TenantInfrastructureProvisioner;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Queue;
use LogicException;
use RuntimeException;
use Tests\Concerns\RefreshCoreAndTenantDatabase;
use Tests\TestCase;

final class TenantProvisioningTest extends TestCase
{
    use RefreshCoreAndTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
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
        $this->assertFalse($unit->is_active);
        $this->assertTrue($manager->hasRole('manager'));
        $this->assertTrue($manager->healthUnits()->whereKey($unit->getKey())->exists());
        $this->assertSame($unit->getKey(), $manager->default_health_unit_id);
        $this->assertTrue($manager->must_change_password);
        $tenantDatabase = TenantDatabase::query()->where('health_unit_id', $unit->getKey())->firstOrFail();
        $this->assertSame('native', $tenantDatabase->provisioning_mode);
        $this->assertSame('credentials_staged', $tenantDatabase->infrastructure_status);
        $this->assertSame('tu_'.strtolower((string) $unit->public_id), $tenantDatabase->runtime_username);
        $this->assertLessThanOrEqual(32, strlen((string) $tenantDatabase->runtime_username));
        $this->assertNotEmpty(Crypt::decryptString((string) $tenantDatabase->encrypted_runtime_password));
        Queue::assertPushed(ProvisionTenantDatabaseJob::class, function (ProvisionTenantDatabaseJob $job) use ($unit): bool {
            $this->assertFalse(property_exists($job, 'actorUserPublicId'));

            return $job->healthUnitPublicId === (string) $unit->public_id;
        });
        $this->assertDatabaseCount('specialties', 3);
        $this->assertDatabaseCount('arrival_methods', 4);
        $this->assertDatabaseCount('entry_types', 3);
        $this->assertDatabaseCount('queues', 4);
        $this->assertDatabaseCount('panels', 1);
        $this->assertDatabaseHas('security_audit_logs', [
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

    public function test_native_registration_is_local_and_admin_credentials_are_blocked_in_web_process(): void
    {
        $unit = $this->createHealthUnit('NATIVE-LOCAL');
        $actor = $this->createPlatformAdministrator();
        $database = app(TenantDatabaseLifecycle::class)->registerNative($unit, $actor);

        $this->assertSame('credentials_staged', $database->infrastructure_status);
        $this->assertSame('tu_'.strtolower((string) $unit->public_id), $database->runtime_username);
        $this->assertSame(29, strlen((string) $database->runtime_username));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('worker de provisionamento isolado');
        app(TenantInfrastructureProvisioner::class)->provision($database);
    }

    public function test_native_connection_uses_the_credentials_staged_for_its_exact_database(): void
    {
        $unit = $this->createHealthUnit('NATIVE-CONNECTION');
        $actor = $this->createPlatformAdministrator();
        $database = app(TenantDatabaseLifecycle::class)->registerNative($unit, $actor);
        $database->update(['infrastructure_status' => 'grants_applied']);
        config()->set('tenancy.native_provisioning.runtime_connection', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => false,
            'transaction_mode' => 'DEFERRED',
        ]);

        $name = app(TenantConnectionManager::class)->dedicatedConnectionName($database->refresh());
        $configuration = config("database.connections.{$name}");

        $this->assertSame($database->database_name, $configuration['database']);
        $this->assertSame($database->runtime_username, $configuration['username']);
        $this->assertSame(
            Crypt::decryptString((string) $database->encrypted_runtime_password),
            $configuration['password'],
        );
    }

    public function test_native_worker_refuses_ddl_without_explicit_partial_revokes_expectation(): void
    {
        $unit = $this->createHealthUnit('NATIVE-ENVIRONMENT');
        $actor = $this->createPlatformAdministrator();
        $database = app(TenantDatabaseLifecycle::class)->registerNative($unit, $actor);
        config()->set('tenancy.native_provisioning.worker_enabled', true);
        config()->set('tenancy.native_provisioning.expected_partial_revokes');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('TENANT_PROVISIONING_EXPECTED_PARTIAL_REVOKES');
        app(TenantInfrastructureProvisioner::class)->provision($database);
    }

    public function test_native_unit_is_activated_only_when_lifecycle_reaches_tenant(): void
    {
        $unit = $this->createHealthUnit('NATIVE-ACTIVATION');
        $unit->update(['is_active' => false]);
        $actor = $this->createPlatformAdministrator();
        $lifecycle = app(TenantDatabaseLifecycle::class);
        $database = $lifecycle->registerNative($unit, $actor);
        $database->update([
            'state' => TenantDatabaseState::Cutover,
            'infrastructure_status' => 'grants_applied',
        ]);

        $this->assertFalse($unit->refresh()->is_active);
        $lifecycle->transition($database->refresh(), TenantDatabaseState::Tenant, $actor);
        $this->assertTrue($unit->refresh()->is_active);
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
            ], $this->createPlatformAdministrator());
            $this->fail('Deveria ter propagado a falha simulada.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Falha simulada depois de criar a unidade.', $exception->getMessage());
        }

        $this->assertSame(0, Organization::query()->where('cnes_code', '9988776')->count());
        $this->assertDatabaseMissing('health_units', ['cnes_code' => '9988776']);
        $this->assertSame(0, TenantDatabase::query()->count());
        Queue::assertNothingPushed();
    }

    public function test_core_transaction_rolls_back_unit_and_credentials_when_native_registration_fails(): void
    {
        $actor = $this->createPlatformAdministrator();
        TenantDatabase::created(function (): void {
            throw new RuntimeException('Falha simulada depois de registrar as credenciais.');
        });

        try {
            app(ProvisionTenantAction::class)->execute([
                'cnes_code' => '8877665',
                'legal_name' => 'Organização Credencial Falha',
                'trade_name' => 'Unidade Credencial Falha',
                'manager_name' => 'Gestora',
                'manager_email' => 'gestora.credencial@falha.test',
                'manager_password' => 'Temporary#Password2026',
            ], $actor);
            $this->fail('Deveria ter propagado a falha depois do registro nativo.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Falha simulada depois de registrar as credenciais.', $exception->getMessage());
        }

        $this->assertSame(0, Organization::query()->where('cnes_code', '8877665')->count());
        $this->assertDatabaseMissing('health_units', ['cnes_code' => '8877665']);
        $this->assertSame(0, TenantDatabase::query()->count());
        Queue::assertNothingPushed();
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
