<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Audit\Infrastructure\Eloquent\SecurityAuditLog;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Professionals\Infrastructure\Eloquent\HealthProfessional;
use Database\Seeders\RolePermissionSeeder;
use Tests\Concerns\RefreshCoreAndTenantDatabase;
use Tests\TestCase;

final class EmployeeRegistrationTest extends TestCase
{
    use RefreshCoreAndTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    /** @return array<string, mixed> */
    private function receptionistPayload(string $cnes): array
    {
        return [
            'cnes_code' => $cnes,
            'name' => 'Ana Recepção',
            'cpf' => '52998224725',
            'birth_date' => '1995-05-10',
            'email' => 'ana.recepcao@example.test',
            'role' => 'receptionist',
            'password' => 'Temporary#Password2026',
            'password_confirmation' => 'Temporary#Password2026',
        ];
    }

    public function test_registration_screen_is_available_to_guests(): void
    {
        $this->get('/register')
            ->assertOk()
            ->assertSee('Cadastro de funcionário');
    }

    public function test_receptionist_self_registers_without_professional_profile(): void
    {
        $unit = $this->createHealthUnit();

        $response = $this->post('/register', $this->receptionistPayload($unit->organization->cnes_code));

        $response->assertRedirect(route('login'));
        $user = User::query()->where('email', 'ana.recepcao@example.test')->firstOrFail();
        $this->assertTrue($user->hasRole('receptionist'));
        $this->assertSame($unit->organization_id, $user->organization_id);
        $this->assertSame($unit->getKey(), $user->default_health_unit_id);
        $this->assertTrue($user->healthUnits()->whereKey($unit->getKey())->exists());
        $this->assertTrue($user->is_active);
        $this->assertFalse($user->must_change_password);
        $this->assertSame('52998224725', $user->cpf);
        $this->assertSame(0, HealthProfessional::query()->count());
        $this->assertDatabaseHas('security_audit_logs', [
            'action' => 'user.self_registered',
            'health_unit_id' => $unit->getKey(),
        ]);
        $audit = SecurityAuditLog::query()->where('action', 'user.self_registered')->firstOrFail();
        $this->assertNull($audit->user_id);
    }

    public function test_doctor_self_registration_creates_professional_profile_and_registration(): void
    {
        $unit = $this->createHealthUnit();
        $payload = $this->receptionistPayload($unit->organization->cnes_code);
        $payload['email'] = 'dra.silva@example.test';
        $payload['role'] = 'doctor';
        $payload['council_type'] = 'crm';
        $payload['registration_number'] = '123456';
        $payload['registration_state'] = 'sp';

        $this->post('/register', $payload)->assertRedirect(route('login'));

        $user = User::query()->where('email', 'dra.silva@example.test')->firstOrFail();
        $this->assertTrue($user->hasRole('doctor'));
        $professional = HealthProfessional::query()->where('user_id', $user->getKey())->firstOrFail();
        $this->assertSame('doctor', $professional->profession_type);
        $this->assertSame('Ana Recepção', $professional->full_name);
        $registration = $professional->registrations()->firstOrFail();
        $this->assertSame('CRM', $registration->council_type);
        $this->assertSame('SP', $registration->state);
        $this->assertSame('123456', $registration->registration_number);
        $this->assertTrue($registration->is_primary);
    }

    public function test_doctor_registration_requires_professional_registration_fields(): void
    {
        $unit = $this->createHealthUnit();
        $payload = $this->receptionistPayload($unit->organization->cnes_code);
        $payload['role'] = 'doctor';

        $this->post('/register', $payload)
            ->assertSessionHasErrors(['council_type', 'registration_number', 'registration_state']);

        $this->assertSame(0, User::query()->count());
    }

    public function test_unknown_cnes_is_rejected(): void
    {
        $this->post('/register', $this->receptionistPayload('0000000'))
            ->assertSessionHasErrors('cnes_code');

        $this->assertSame(0, User::query()->count());
    }

    public function test_manager_and_administrator_roles_are_not_selectable(): void
    {
        $unit = $this->createHealthUnit();

        foreach (['manager', 'administrator'] as $role) {
            $payload = $this->receptionistPayload($unit->organization->cnes_code);
            $payload['role'] = $role;

            $this->post('/register', $payload)->assertSessionHasErrors('role');
        }

        $this->assertSame(0, User::query()->count());
    }

    public function test_duplicate_cpf_within_the_same_organization_is_rejected(): void
    {
        $unit = $this->createHealthUnit();
        $this->post('/register', $this->receptionistPayload($unit->organization->cnes_code))
            ->assertRedirect(route('login'));

        $duplicate = $this->receptionistPayload($unit->organization->cnes_code);
        $duplicate['email'] = 'outra.pessoa@example.test';

        $this->post('/register', $duplicate)->assertSessionHasErrors('cpf');
        $this->assertSame(1, User::query()->count());
    }

    public function test_weak_password_is_rejected(): void
    {
        $unit = $this->createHealthUnit();
        $payload = $this->receptionistPayload($unit->organization->cnes_code);
        $payload['password'] = 'weak';
        $payload['password_confirmation'] = 'weak';

        $this->post('/register', $payload)->assertSessionHasErrors('password');
        $this->assertSame(0, User::query()->count());
    }

    public function test_registered_employee_can_authenticate_afterwards(): void
    {
        $unit = $this->createHealthUnit();
        $this->post('/register', $this->receptionistPayload($unit->organization->cnes_code))
            ->assertRedirect(route('login'));

        $this->post('/login', [
            'unit_code' => $unit->organization->cnes_code,
            'email' => 'ana.recepcao@example.test',
            'password' => 'Temporary#Password2026',
        ])->assertRedirect('/dashboard');

        $this->assertAuthenticated();
    }
}
