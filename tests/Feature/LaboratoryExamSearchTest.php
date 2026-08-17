<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Laboratory\Domain\Enums\ExamMappingMatchType;
use App\Modules\Laboratory\Infrastructure\Eloquent\Exam;
use App\Modules\Laboratory\Infrastructure\Eloquent\ExamMapping;
use App\Modules\Laboratory\Infrastructure\Eloquent\HealthUnitExam;
use App\Modules\Laboratory\Infrastructure\Eloquent\LaboratoryIntegration;
use Database\Seeders\RolePermissionSeeder;
use Tests\Concerns\RefreshCoreAndTenantDatabase;
use Tests\TestCase;

final class LaboratoryExamSearchTest extends TestCase
{
    use RefreshCoreAndTenantDatabase;

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
        $exam = $integration->exams()->create([
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
        $canonicalExam = Exam::query()->create([
            'organization_id' => $unit->organization_id,
            'name' => $exam->name,
            'sus_procedure_code' => $exam->sus_procedure_code,
        ]);
        ExamMapping::query()->create([
            'exam_id' => $canonicalExam->getKey(),
            'laboratory_integration_id' => $integration->getKey(),
            'external_code' => $exam->external_code,
            'external_name_snapshot' => $exam->name,
            'match_type' => ExamMappingMatchType::Exact,
            'mapped_at' => now(),
        ]);
        HealthUnitExam::query()->create([
            'exam_id' => $canonicalExam->getKey(),
            'health_unit_id' => $unit->getKey(),
            'is_enabled' => true,
            'enabled_at' => now(),
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

    public function test_exam_without_enabled_health_unit_availability_is_not_searchable(): void
    {
        $unit = $this->createHealthUnit('CENTRAL');
        $this->seed(RolePermissionSeeder::class);
        $doctor = $this->createUserWithUnit($unit, ['must_change_password' => false]);
        $doctor->assignRole('doctor');
        $integration = LaboratoryIntegration::query()->create([
            'organization_id' => $unit->organization_id,
            'health_unit_id' => $unit->getKey(),
            'provider' => 'synclab',
            'is_active' => true,
        ]);
        $laboratoryExam = $integration->exams()->create([
            'external_code' => '127',
            'name' => 'Hemograma completo',
        ]);
        $canonicalExam = Exam::query()->create([
            'organization_id' => $unit->organization_id,
            'name' => $laboratoryExam->name,
        ]);
        ExamMapping::query()->create([
            'exam_id' => $canonicalExam->getKey(),
            'laboratory_integration_id' => $integration->getKey(),
            'external_code' => $laboratoryExam->external_code,
            'external_name_snapshot' => $laboratoryExam->name,
            'match_type' => ExamMappingMatchType::Exact,
            'mapped_at' => now(),
        ]);
        HealthUnitExam::query()->create([
            'exam_id' => $canonicalExam->getKey(),
            'health_unit_id' => $unit->getKey(),
            'is_enabled' => false,
        ]);

        $this->actingAs($doctor)
            ->withSession(['active_health_unit_id' => $unit->getKey()])
            ->getJson(route('medical.laboratory-exams.search', ['q' => 'hemograma']))
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }
}
