<?php

declare(strict_types=1);

namespace Tests\Feature;

use Database\Seeders\RolePermissionSeeder;
use Tests\Concerns\RefreshCoreAndTenantDatabase;
use Tests\TestCase;

final class HealthUnitScopeTest extends TestCase
{
    use RefreshCoreAndTenantDatabase;

    public function test_linked_user_can_render_dashboard(): void
    {
        $unit = $this->createHealthUnit();
        $user = $this->createUserWithUnit($unit, ['must_change_password' => false]);

        $this->actingAs($user)
            ->withSession(['active_health_unit_id' => $unit->getKey()])
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('Visão geral do plantão')
            ->assertSee($unit->name);
    }

    public function test_user_without_active_unit_link_cannot_access_dashboard(): void
    {
        $unit = $this->createHealthUnit();
        $user = $this->createUserWithUnit($unit, ['must_change_password' => false]);
        $user->healthUnits()->detach();

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertForbidden();
    }

    public function test_user_can_switch_to_a_linked_unit(): void
    {
        $firstUnit = $this->createHealthUnit('FIRST');
        $secondUnit = $this->createHealthUnit('SECOND');
        $user = $this->createUserWithUnit($firstUnit, ['must_change_password' => false]);
        $user->healthUnits()->attach($secondUnit);

        $this->actingAs($user)
            ->withSession(['active_health_unit_id' => $firstUnit->getKey()])
            ->put('/active-health-unit', ['health_unit' => $secondUnit->public_id])
            ->assertSessionHas('success');

        $this->assertSame($secondUnit->getKey(), session('active_health_unit_id'));
        $this->assertDatabaseHas('security_audit_logs', [
            'user_id' => $user->getKey(),
            'health_unit_id' => $secondUnit->getKey(),
            'action' => 'user.active_health_unit_changed',
        ]);
    }

    public function test_user_cannot_switch_to_an_unlinked_unit(): void
    {
        $linkedUnit = $this->createHealthUnit('LINKED');
        $unlinkedUnit = $this->createHealthUnit('UNLINKED');
        $user = $this->createUserWithUnit($linkedUnit, ['must_change_password' => false]);

        $this->actingAs($user)
            ->withSession(['active_health_unit_id' => $linkedUnit->getKey()])
            ->put('/active-health-unit', ['health_unit' => $unlinkedUnit->public_id])
            ->assertForbidden();

        $this->assertSame($linkedUnit->getKey(), session('active_health_unit_id'));
    }

    public function test_global_administrator_can_select_any_active_organization_without_link(): void
    {
        $firstUnit = $this->createHealthUnit('GLOBAL-FIRST');
        $secondUnit = $this->createHealthUnit('GLOBAL-SECOND');
        $this->seed(RolePermissionSeeder::class);
        $administrator = $this->createPlatformAdministrator();
        $administrator->assignRole('administrator');

        $this->actingAs($administrator)
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('Visualizar unidade')
            ->assertSee("Hospital GLOBAL-FIRST · Unidade GLOBAL-FIRST · CNES {$firstUnit->cnes_code}")
            ->assertSee("Hospital GLOBAL-SECOND · Unidade GLOBAL-SECOND · CNES {$secondUnit->cnes_code}");
        $this->actingAs($administrator)
            ->withSession(['active_health_unit_id' => $firstUnit->getKey()])
            ->put('/active-health-unit', ['health_unit' => $secondUnit->public_id])
            ->assertRedirect(route('dashboard'))
            ->assertSessionHas('success');

        $this->assertSame($secondUnit->getKey(), session('active_health_unit_id'));
        $this->assertDatabaseCount('health_unit_user', 0);

        $this->actingAs($administrator)
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('value="'.$secondUnit->public_id.'" selected', false)
            ->assertSee('Unidade GLOBAL-SECOND');
    }
}
