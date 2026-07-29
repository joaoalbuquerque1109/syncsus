<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Administration\Infrastructure\Eloquent\Specialty;
use App\Modules\Professionals\Application\Services\MedicalDutyService;
use App\Modules\Professionals\Infrastructure\Eloquent\HealthProfessional;
use Database\Seeders\OperationalCatalogSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class MedicalDutyTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_eligible_checked_in_doctor_is_available_in_the_unit_today(): void
    {
        $unit = $this->createHealthUnit('PLANTAO-A');
        $this->seed([RolePermissionSeeder::class, OperationalCatalogSeeder::class]);
        $doctor = $this->createUserWithUnit($unit, ['must_change_password' => false]);
        $doctor->assignRole('doctor');
        $profile = HealthProfessional::query()->create([
            'organization_id' => $unit->organization_id,
            'user_id' => $doctor->getKey(),
            'institutional_code' => 'MED-PLANTAO-A',
            'profession_type' => 'doctor',
            'full_name' => 'Dra. Presente',
            'is_active' => true,
            'created_by' => $doctor->getKey(),
            'updated_by' => $doctor->getKey(),
        ]);
        $profile->healthUnits()->attach($unit);
        $profile->specialties()->attach(
            Specialty::query()->where('organization_id', $unit->organization_id)->firstOrFail(),
        );
        $session = ['active_health_unit_id' => $unit->getKey()];

        $this->actingAs($doctor)->withSession($session)
            ->post(route('medical-duty.check-in'))
            ->assertRedirect()
            ->assertSessionHas('success');

        $attendance = app(MedicalDutyService::class)->current($doctor, $unit);
        $this->assertNotNull($attendance);
        $this->assertNull($attendance?->checked_out_at);
        $this->assertTrue(
            app(MedicalDutyService::class)->availableDoctors($unit)->contains($doctor),
        );
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'medical_duty.checked_in',
            'user_id' => $doctor->getKey(),
            'health_unit_id' => $unit->getKey(),
        ]);

        $this->actingAs($doctor)->withSession($session)
            ->post(route('medical-duty.check-out'), ['reason' => 'Fim do plantão'])
            ->assertRedirect();

        $this->assertFalse(app(MedicalDutyService::class)->isCheckedIn($doctor, $unit));
        $this->assertTrue(app(MedicalDutyService::class)->availableDoctors($unit)->isEmpty());
    }

    public function test_doctor_without_complete_professional_registration_cannot_check_in(): void
    {
        $unit = $this->createHealthUnit('PLANTAO-B');
        $this->seed(RolePermissionSeeder::class);
        $doctor = $this->createUserWithUnit($unit, ['must_change_password' => false]);
        $doctor->assignRole('doctor');

        $this->actingAs($doctor)
            ->withSession(['active_health_unit_id' => $unit->getKey()])
            ->post(route('medical-duty.check-in'))
            ->assertSessionHasErrors('medical_duty');
    }
}
