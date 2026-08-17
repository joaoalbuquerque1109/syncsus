<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Patients\Infrastructure\Eloquent\Patient;
use App\Modules\Queues\Infrastructure\Eloquent\Queue;
use Database\Seeders\OperationalCatalogSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Str;
use LogicException;
use Tests\Concerns\RefreshCoreAndTenantDatabase;
use Tests\TestCase;

final class PlatformGovernanceTest extends TestCase
{
    use RefreshCoreAndTenantDatabase;

    public function test_platform_administrator_is_an_unrestricted_superuser_in_any_active_unit(): void
    {
        $firstUnit = $this->createHealthUnit('FIRST');
        $secondUnit = $this->createHealthUnit('SECOND');
        $this->seed(OperationalCatalogSeeder::class);
        $administrator = $this->createPlatformAdministrator();

        $this->assertFalse($administrator->roles()->exists());
        $this->assertTrue($administrator->can('patients.create'));
        $this->assertTrue($administrator->can('administration.manage'));

        $this->actingAs($administrator)
            ->withSession(['active_health_unit_id' => $firstUnit->getKey()])
            ->get(route('patients.create'))
            ->assertOk();

        $secondUnitQueue = Queue::query()->where('health_unit_id', $secondUnit->getKey())->firstOrFail();
        $this->actingAs($administrator)
            ->withSession(['active_health_unit_id' => $secondUnit->getKey()])
            ->get(route('queues.index'))
            ->assertOk()
            ->assertSee($secondUnitQueue->name);

        $this->actingAs($administrator)
            ->withSession(['active_health_unit_id' => $firstUnit->getKey()])
            ->post(route('patients.store'), [
                'idempotency_key' => (string) Str::ulid(),
                'full_name' => 'Paciente do Administrador',
                'birth_date' => '1990-05-10',
                'sex' => 'female',
                'mother_name' => 'Mãe do Paciente',
            ])
            ->assertRedirect();

        $this->actingAs($administrator)
            ->withSession(['active_health_unit_id' => $secondUnit->getKey()])
            ->post(route('patients.provisional.store'), [
                'sex' => 'unknown',
                'estimated_age' => 40,
                'estimated_age_range' => 'adult',
                'provisional_description' => 'Paciente sem identificação no momento do atendimento.',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('patients', [
            'full_name' => 'Paciente do Administrador',
            'organization_id' => $firstUnit->organization_id,
            'reference_health_unit_id' => $firstUnit->getKey(),
        ]);
        $this->assertDatabaseHas('patients', [
            'is_provisional' => true,
            'organization_id' => $secondUnit->organization_id,
            'reference_health_unit_id' => $secondUnit->getKey(),
        ]);
        $this->assertDatabaseCount('patients', 2);
        $this->assertNull($administrator->fresh()?->organization_id);
        $this->assertFalse($administrator->healthUnits()->exists());
        $this->assertSame(2, Patient::query()->count());
    }

    public function test_manager_administers_only_own_organization_and_last_manager_is_protected(): void
    {
        $unit = $this->createHealthUnit('MANAGED');
        $otherUnit = $this->createHealthUnit('OTHER');
        $this->seed(RolePermissionSeeder::class);
        $manager = $this->createUserWithUnit($unit, ['must_change_password' => false]);
        $manager->assignRole('manager');
        $otherUser = $this->createUserWithUnit($otherUnit);
        $session = ['active_health_unit_id' => $unit->getKey()];

        $this->actingAs($manager)->withSession($session)
            ->get(route('administration.users.index'))
            ->assertOk()
            ->assertDontSee($otherUser->email);
        $this->actingAs($manager)->withSession($session)
            ->put(route('administration.users.update', $manager->public_id), [
                'name' => $manager->name,
                'email' => $manager->email,
                'roles' => ['manager'],
                'health_unit_ids' => [$unit->getKey()],
                'default_health_unit_id' => $unit->getKey(),
                'is_active' => '0',
            ])
            ->assertStatus(422);
        $this->assertTrue($manager->fresh()?->is_active);
    }

    public function test_regular_user_requires_organization_and_administrator_role_cannot_be_delegated(): void
    {
        $unit = $this->createHealthUnit('RULES');
        $this->seed(RolePermissionSeeder::class);
        $manager = $this->createUserWithUnit($unit, ['must_change_password' => false]);
        $manager->assignRole('manager');

        $this->actingAs($manager)
            ->withSession(['active_health_unit_id' => $unit->getKey()])
            ->post(route('administration.users.store'), [
                'name' => 'Administrador indevido',
                'email' => 'indevido@example.test',
                'password' => 'Temporary#Password2026',
                'roles' => ['administrator'],
                'health_unit_ids' => [$unit->getKey()],
                'default_health_unit_id' => $unit->getKey(),
                'is_active' => '1',
            ])
            ->assertSessionHasErrors('roles.0');

        $this->expectException(LogicException::class);
        User::factory()->create([
            'organization_id' => null,
            'platform_admin_slot' => null,
        ]);
    }
}
