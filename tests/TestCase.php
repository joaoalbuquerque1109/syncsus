<?php

declare(strict_types=1);

namespace Tests;

use App\Modules\Administration\Infrastructure\Eloquent\HealthUnit;
use App\Modules\Administration\Infrastructure\Eloquent\Organization;
use App\Modules\Administration\Infrastructure\Eloquent\Specialty;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Professionals\Infrastructure\Eloquent\HealthProfessional;
use App\Modules\Professionals\Infrastructure\Eloquent\MedicalShiftAttendance;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Hash;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    protected function createHealthUnit(string $code = 'CENTRAL'): HealthUnit
    {
        $organization = Organization::query()->create([
            'code' => $code,
            'legal_name' => "Organização {$code}",
            'trade_name' => "Hospital {$code}",
            'timezone' => 'America/Fortaleza',
            'locale' => 'pt_BR',
            'is_active' => true,
        ]);

        return HealthUnit::query()->create([
            'organization_id' => $organization->getKey(),
            'code' => $code,
            'name' => "Unidade {$code}",
            'is_active' => true,
        ]);
    }

    protected function createUserWithUnit(
        HealthUnit $unit,
        array $attributes = [],
        string $password = 'Initial#Password2026',
    ): User {
        $user = User::factory()->create([
            'password' => Hash::make($password),
            'organization_id' => $unit->organization_id,
            'default_health_unit_id' => $unit->getKey(),
            ...$attributes,
        ]);
        $user->healthUnits()->attach($unit);

        return $user;
    }

    protected function checkInDoctor(User $user, HealthUnit $unit): void
    {
        $specialty = Specialty::query()
            ->where('organization_id', $unit->organization_id)
            ->where('is_active', true)
            ->firstOrFail();
        $profile = HealthProfessional::query()->firstOrCreate(
            ['organization_id' => $unit->organization_id, 'user_id' => $user->getKey()],
            [
                'institutional_code' => 'MED-'.$user->getKey(),
                'profession_type' => 'doctor',
                'full_name' => $user->name,
                'is_active' => true,
                'created_by' => $user->getKey(),
                'updated_by' => $user->getKey(),
            ],
        );
        $profile->healthUnits()->syncWithoutDetaching([$unit->getKey()]);
        $profile->specialties()->syncWithoutDetaching([$specialty->getKey()]);
        MedicalShiftAttendance::query()->updateOrCreate(
            [
                'health_unit_id' => $unit->getKey(),
                'user_id' => $user->getKey(),
                'duty_date' => now()->toDateString(),
            ],
            [
                'organization_id' => $unit->organization_id,
                'checked_in_at' => now(),
                'checked_out_at' => null,
            ],
        );
    }

    protected function createPlatformAdministrator(array $attributes = []): User
    {
        return User::factory()->create([
            'organization_id' => null,
            'platform_admin_slot' => 1,
            'default_health_unit_id' => null,
            'must_change_password' => false,
            ...$attributes,
        ]);
    }
}
