<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Administration\Infrastructure\Eloquent\HealthUnit;
use App\Modules\Audit\Infrastructure\Eloquent\SecurityAuditLog;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\RefreshCoreAndTenantDatabase;
use Tests\TestCase;

final class AuthenticationTest extends TestCase
{
    use RefreshCoreAndTenantDatabase;

    public function test_login_screen_is_available_to_guests(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertSee('Acesse o seu plantão');
    }

    public function test_active_user_can_authenticate_and_login_is_audited(): void
    {
        $unit = $this->createHealthUnit();
        $user = $this->createUserWithUnit($unit, ['must_change_password' => false]);

        $response = $this->post('/login', [
            'unit_code' => $unit->organization->cnes_code,
            'email' => $user->email,
            'password' => 'Initial#Password2026',
        ]);

        $response->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);
        $this->assertSame($unit->getKey(), session('active_health_unit_id'));
        $this->assertDatabaseHas('security_audit_logs', [
            'user_id' => $user->getKey(),
            'action' => 'user.logged_in',
        ]);
        $this->assertNotNull($user->fresh()?->last_login_at);
    }

    public function test_invalid_credentials_are_rejected_and_audited_without_identifier(): void
    {
        $this->post('/login', [
            'unit_code' => 'UNIDADE-INEXISTENTE',
            'email' => 'desconhecido@example.test',
            'password' => 'Wrong#Password2026',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
        $audit = SecurityAuditLog::query()->where('action', 'user.login_failed')->firstOrFail();
        $this->assertNull($audit->user_id);
        $this->assertSame('invalid_credentials', $audit->context['reason']);
    }

    public function test_inactive_user_cannot_authenticate(): void
    {
        $unit = $this->createHealthUnit();
        $user = $this->createUserWithUnit($unit, ['is_active' => false]);

        $this->post('/login', [
            'unit_code' => $unit->organization->code,
            'email' => $user->email,
            'password' => 'Initial#Password2026',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
        $this->assertDatabaseHas('security_audit_logs', [
            'user_id' => $user->getKey(),
            'action' => 'user.login_failed',
        ]);
    }

    public function test_login_is_temporarily_limited_after_five_failures(): void
    {
        foreach (range(1, 5) as $_attempt) {
            $this->post('/login', [
                'unit_code' => 'CENTRAL',
                'email' => 'limitado@example.test',
                'password' => 'Wrong#Password2026',
            ])->assertSessionHasErrors('email');
        }

        $response = $this->post('/login', [
            'unit_code' => 'CENTRAL',
            'email' => 'limitado@example.test',
            'password' => 'Wrong#Password2026',
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertStringContainsString(
            'Muitas tentativas',
            (string) session('errors')?->first('email'),
        );
        $this->assertDatabaseCount('security_audit_logs', 5);
    }

    public function test_authenticated_user_can_logout_and_event_is_audited(): void
    {
        $unit = $this->createHealthUnit();
        $user = $this->createUserWithUnit($unit, ['must_change_password' => false]);

        $this->actingAs($user)
            ->withSession(['active_health_unit_id' => $unit->getKey()])
            ->post('/logout')
            ->assertRedirect('/login');

        $this->assertGuest();
        $this->assertDatabaseHas('security_audit_logs', [
            'user_id' => $user->getKey(),
            'action' => 'user.logged_out',
            'health_unit_id' => $unit->getKey(),
        ]);
    }

    public function test_password_hash_is_never_written_to_audit_context(): void
    {
        $unit = $this->createHealthUnit();
        $user = $this->createUserWithUnit($unit, ['must_change_password' => false]);

        $this->post('/login', [
            'unit_code' => $unit->organization->code,
            'email' => $user->email,
            'password' => 'Initial#Password2026',
        ]);

        $auditPayload = SecurityAuditLog::query()->get()->toJson();
        $this->assertStringNotContainsString('Initial#Password2026', $auditPayload);
        $this->assertTrue(Hash::check('Initial#Password2026', (string) $user->password));
    }

    public function test_same_email_can_authenticate_in_distinct_units_using_cnes(): void
    {
        $firstUnit = $this->createHealthUnit('UNIT-A');
        $secondUnit = $this->createHealthUnit('UNIT-B');
        $email = 'medico@rede.test';
        $this->createUserWithUnit($firstUnit, ['email' => $email]);
        $secondUser = $this->createUserWithUnit($secondUnit, [
            'email' => $email,
            'must_change_password' => false,
        ]);

        $this->post('/login', [
            'unit_code' => $secondUnit->organization->cnes_code,
            'email' => $email,
            'password' => 'Initial#Password2026',
        ])->assertRedirect('/dashboard');

        $this->assertAuthenticatedAs($secondUser);
        $this->assertSame($secondUnit->getKey(), session('active_health_unit_id'));
    }

    public function test_professional_with_multiple_units_enters_the_specific_unit_typed_by_its_own_cnes(): void
    {
        $firstUnit = $this->createHealthUnit('MULTI-A');
        $secondUnit = HealthUnit::query()->create([
            'organization_id' => $firstUnit->organization_id,
            'code' => 'MULTI-B',
            'cnes_code' => '9988776',
            'name' => 'Unidade MULTI-B',
            'is_active' => true,
        ]);
        $this->activateTenant($secondUnit);
        $user = $this->createUserWithUnit($firstUnit, ['must_change_password' => false]);
        // Simula a tela "Usuarios e acessos" concedendo login na segunda
        // unidade da mesma organizacao, sem trocar a unidade padrao dele.
        $user->healthUnits()->attach($secondUnit->getKey());

        $this->post('/login', [
            'unit_code' => $secondUnit->cnes_code,
            'email' => $user->email,
            'password' => 'Initial#Password2026',
        ])->assertRedirect('/dashboard');

        $this->assertAuthenticatedAs($user);
        $this->assertSame($secondUnit->getKey(), session('active_health_unit_id'));
    }

    public function test_professional_cannot_enter_a_sibling_unit_cnes_it_has_no_link_to(): void
    {
        $linkedUnit = $this->createHealthUnit('NOLINK-A');
        $siblingUnit = HealthUnit::query()->create([
            'organization_id' => $linkedUnit->organization_id,
            'code' => 'NOLINK-B',
            'cnes_code' => '9988770',
            'name' => 'Unidade NOLINK-B',
            'is_active' => true,
        ]);
        $this->activateTenant($siblingUnit);
        $user = $this->createUserWithUnit($linkedUnit, ['must_change_password' => false]);

        $this->post('/login', [
            'unit_code' => $siblingUnit->cnes_code,
            'email' => $user->email,
            'password' => 'Initial#Password2026',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
        $this->assertDatabaseHas('security_audit_logs', [
            'user_id' => $user->getKey(),
            'action' => 'user.login_failed',
            'context->reason' => 'unit_access_unavailable',
        ]);
    }

    public function test_global_administrator_authenticates_with_administrative_code_without_unit_link(): void
    {
        $this->createHealthUnit('GLOBAL-A');
        $administrator = $this->createPlatformAdministrator([
            'email' => 'global@example.test',
            'password' => 'Initial#Password2026',
            'must_change_password' => true,
        ]);

        $this->post('/login', [
            'unit_code' => 'ADMIN',
            'email' => 'global@example.test',
            'password' => 'Initial#Password2026',
        ])->assertRedirect('/dashboard');

        $this->assertAuthenticatedAs($administrator);
        $this->assertNull($administrator->organization_id);
        $this->assertDatabaseCount('health_unit_user', 0);
    }
}
