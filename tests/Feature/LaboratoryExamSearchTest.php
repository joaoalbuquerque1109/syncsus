<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Laboratory\Infrastructure\Eloquent\LaboratoryIntegration;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class LaboratoryExamSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_doctor_searches_only_the_active_units_laboratory_catalog(): void
    {
        $unit = $this->createHealthUnit('CENTRAL');
        $otherUnit = $this->createHealthUnit('NORTH');
        $this->seed(RolePermissionSeeder::class);
        $doctor = $this->createUserWithUnit($unit, ['must_change_password' => false]);
        $doctor->assignRole('doctor');
        $integration = LaboratoryIntegration::query()->create([
            'organization_id' => $unit->organization_id,
            'health_unit_id' => $unit->getKey(),
            'provider' => 'synclab',
            'is_active' => true,
        ]);
        $otherIntegration = LaboratoryIntegration::query()->create([
            'organization_id' => $otherUnit->organization_id,
            'health_unit_id' => $otherUnit->getKey(),
            'provider' => 'synclab',
            'is_active' => true,
        ]);
        $integration->exams()->create([
            'external_code' => '127',
            'sus_procedure_code' => '0202020380',
            'acronym' => 'HEM',
            'name' => 'Hemograma completo',
            'synonyms' => ['hemograma'],
        ]);
        $otherIntegration->exams()->create([
            'external_code' => '999',
            'acronym' => 'HEM-OUTRO',
            'name' => 'Hemograma de outra unidade',
        ]);

        $this->actingAs($doctor)
            ->withSession(['active_health_unit_id' => $unit->getKey()])
            ->getJson(route('medical.laboratory-exams.search', ['q' => 'hemograma']))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.code', '127')
            ->assertJsonPath('data.0.procedure_code', '0202020380')
            ->assertJsonPath('data.0.name', 'Hemograma completo');
    }
}
