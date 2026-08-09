<?php

declare(strict_types=1);

namespace Tests;

use App\Modules\Administration\Infrastructure\Eloquent\HealthUnit;
use App\Modules\Administration\Infrastructure\Eloquent\Organization;
use App\Modules\Administration\Infrastructure\Eloquent\Specialty;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Professionals\Application\Services\ProfessionalOperationalAssignments;
use App\Modules\Professionals\Infrastructure\Eloquent\HealthProfessional;
use App\Modules\Queues\Infrastructure\Eloquent\Queue;
use App\Support\Tenancy\TenantConnectionManager;
use App\Support\Tenancy\TenantContext;
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
        $cnes = str_pad((string) (abs(crc32($code)) % 10000000), 7, '0', STR_PAD_LEFT);
        $organization = Organization::query()->create([
            'code' => $code,
            'cnes_code' => $cnes,
            'legal_name' => "Organização {$code}",
            'trade_name' => "Hospital {$code}",
            'timezone' => 'America/Fortaleza',
            'locale' => 'pt_BR',
            'is_active' => true,
        ]);

        $unit = HealthUnit::query()->create([
            'organization_id' => $organization->getKey(),
            'code' => $code,
            'cnes_code' => $cnes,
            'name' => "Unidade {$code}",
            'is_active' => true,
        ]);
        $this->activateTenant($unit);

        return $unit;
    }

    /** @return array{health_unit: HealthUnit} */
    protected function createCoreFixtures(string $code = 'CORE'): array
    {
        return ['health_unit' => $this->createHealthUnit($code)];
    }

    /** @return array{health_unit: HealthUnit, connection: string} */
    protected function createTenantFixtures(HealthUnit $unit): array
    {
        $connection = $this->activateTenant($unit);

        return ['health_unit' => $unit, 'connection' => $connection];
    }

    protected function activateTenant(HealthUnit $unit): string
    {
        $context = app(TenantContext::class);
        $connection = app(TenantConnectionManager::class)->connectionName($unit);
        $context->reset();
        $context->resolve($unit, $connection);

        return $connection;
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

    protected function registerDoctor(User $user, HealthUnit $unit): void
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
        $queues = Queue::query()
            ->where('health_unit_id', $unit->getKey())
            ->where('specialty_id', $specialty->getKey())
            ->where('is_active', true)
            ->whereHas('department', fn ($query) => $query->where('type', 'medical'))
            ->get();
        app(ProfessionalOperationalAssignments::class)->syncWithoutDetaching(
            $profile,
            $queues->modelKeys(),
            $queues->flatMap(fn (Queue $queue) => $queue->servicePoints()->pluck('service_points.id'))->unique()->all(),
        );
    }

    protected function registerTriageProfessional(User $user, HealthUnit $unit): void
    {
        $profile = HealthProfessional::query()->firstOrCreate(
            ['organization_id' => $unit->organization_id, 'user_id' => $user->getKey()],
            [
                'institutional_code' => 'ENF-'.$user->getKey(),
                'profession_type' => 'nurse',
                'full_name' => $user->name,
                'is_active' => true,
                'created_by' => $user->getKey(),
                'updated_by' => $user->getKey(),
            ],
        );
        $profile->healthUnits()->syncWithoutDetaching([$unit->getKey()]);
        $queues = Queue::query()
            ->where('health_unit_id', $unit->getKey())
            ->where('is_active', true)
            ->whereHas('department', fn ($query) => $query->where('type', 'triage'))
            ->get();
        app(ProfessionalOperationalAssignments::class)->syncWithoutDetaching(
            $profile,
            $queues->modelKeys(),
            $queues->flatMap(fn (Queue $queue) => $queue->servicePoints()->pluck('service_points.id'))->unique()->all(),
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
