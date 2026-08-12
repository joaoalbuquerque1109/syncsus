<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Hash;
use Tests\Concerns\RefreshCoreAndTenantDatabase;
use Tests\TestCase;

final class PasswordChangeTest extends TestCase
{
    use RefreshCoreAndTenantDatabase;

    public function test_initial_password_must_be_changed_before_dashboard_access(): void
    {
        $unit = $this->createHealthUnit();
        $user = $this->createUserWithUnit($unit, ['must_change_password' => true]);

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertRedirect('/password/change');
    }

    public function test_platform_administrator_is_exempt_from_initial_password_change(): void
    {
        $this->createHealthUnit();
        $administrator = $this->createPlatformAdministrator([
            'must_change_password' => true,
        ]);

        $this->actingAs($administrator)
            ->get('/dashboard')
            ->assertOk();
    }

    public function test_user_can_change_password_and_action_is_audited(): void
    {
        $unit = $this->createHealthUnit();
        $user = $this->createUserWithUnit($unit, ['must_change_password' => true]);

        $this->actingAs($user)
            ->withSession(['active_health_unit_id' => $unit->getKey()])
            ->put('/password/change', [
                'current_password' => 'Initial#Password2026',
                'password' => 'Fresh#Password2026',
                'password_confirmation' => 'Fresh#Password2026',
            ])
            ->assertRedirect('/dashboard');

        $user->refresh();
        $this->assertFalse($user->must_change_password);
        $this->assertTrue(Hash::check('Fresh#Password2026', (string) $user->password));
        $this->assertNotNull($user->password_changed_at);
        $this->assertDatabaseHas('security_audit_logs', [
            'user_id' => $user->getKey(),
            'action' => 'user.password_changed',
        ]);
    }

    public function test_current_password_must_be_valid(): void
    {
        $unit = $this->createHealthUnit();
        $user = $this->createUserWithUnit($unit, ['must_change_password' => true]);

        $this->actingAs($user)
            ->put('/password/change', [
                'current_password' => 'Wrong#Password2026',
                'password' => 'Fresh#Password2026',
                'password_confirmation' => 'Fresh#Password2026',
            ])
            ->assertSessionHasErrors('current_password');

        $this->assertTrue($user->fresh()?->must_change_password);
    }
}
