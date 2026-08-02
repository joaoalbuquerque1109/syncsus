<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Professionals\Application\Queries\AvailableDoctorQuery;
use Database\Seeders\OperationalCatalogSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AvailableDoctorTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_registered_doctor_is_available_without_check_in(): void
    {
        $unit = $this->createHealthUnit('MEDICOS-A');
        $otherUnit = $this->createHealthUnit('MEDICOS-B');
        $this->seed([RolePermissionSeeder::class, OperationalCatalogSeeder::class]);

        $doctor = $this->createUserWithUnit($unit, ['must_change_password' => false]);
        $doctor->assignRole('doctor');
        $this->registerDoctor($doctor, $unit);

        $doctorWithoutRegistration = $this->createUserWithUnit($unit, ['must_change_password' => false]);
        $doctorWithoutRegistration->assignRole('doctor');
        $doctorFromAnotherUnit = $this->createUserWithUnit($otherUnit, ['must_change_password' => false]);
        $doctorFromAnotherUnit->assignRole('doctor');
        $this->registerDoctor($doctorFromAnotherUnit, $otherUnit);

        $available = app(AvailableDoctorQuery::class)->forUnit($unit);

        $this->assertTrue($available->contains($doctor));
        $this->assertFalse($available->contains($doctorWithoutRegistration));
        $this->assertFalse($available->contains($doctorFromAnotherUnit));
        $this->assertDatabaseCount('medical_shift_attendances', 0);
    }

    public function test_medical_duty_endpoints_no_longer_exist(): void
    {
        $unit = $this->createHealthUnit();
        $doctor = $this->createUserWithUnit($unit, ['must_change_password' => false]);

        $this->actingAs($doctor)
            ->withSession(['active_health_unit_id' => $unit->getKey()])
            ->post('/medical-duty/check-in')
            ->assertNotFound();
        $this->actingAs($doctor)
            ->withSession(['active_health_unit_id' => $unit->getKey()])
            ->post('/medical-duty/check-out')
            ->assertNotFound();
    }
}
