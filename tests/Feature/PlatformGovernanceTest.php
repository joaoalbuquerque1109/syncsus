<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Identity\Infrastructure\Eloquent\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

final class PlatformGovernanceTest extends TestCase
{
    use RefreshDatabase;

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
