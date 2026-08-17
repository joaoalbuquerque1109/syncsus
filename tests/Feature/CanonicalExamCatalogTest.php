<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Administration\Infrastructure\Eloquent\HealthUnit;
use App\Modules\Laboratory\Domain\Enums\ExamMappingMatchType;
use App\Modules\Laboratory\Infrastructure\Eloquent\Exam;
use App\Modules\Laboratory\Infrastructure\Eloquent\ExamMapping;
use App\Modules\Laboratory\Infrastructure\Eloquent\HealthUnitExam;
use App\Modules\Laboratory\Infrastructure\Eloquent\LaboratoryIntegration;
use Database\Seeders\CanonicalExamCatalogBackfillSeeder;
use Database\Seeders\SynclabExamCatalogSeeder;
use LogicException;
use Tests\Concerns\RefreshCoreAndTenantDatabase;
use Tests\TestCase;

final class CanonicalExamCatalogTest extends TestCase
{
    use RefreshCoreAndTenantDatabase;

    public function test_mappings_and_availability_are_scoped_by_unit_and_organization(): void
    {
        $unitA = $this->createHealthUnit('UNIT-A');
        $unitB = HealthUnit::query()->create([
            'organization_id' => $unitA->organization_id,
            'code' => 'UNIT-B',
            'cnes_code' => '7654321',
            'name' => 'Unidade B',
            'is_active' => true,
        ]);
        $foreignUnit = $this->createHealthUnit('FOREIGN');
        $integrationA = $this->integration($unitA);
        $integrationB = $this->integration($unitB);
        $foreignIntegration = $this->integration($foreignUnit);

        $mappingA = $this->mappedExam($unitA, $integrationA, 'A');
        $mappingB = $this->mappedExam($unitB, $integrationB, 'B');
        $foreignMapping = $this->mappedExam($foreignUnit, $foreignIntegration, 'FOREIGN');

        $this->assertEquals(
            [$mappingA->getKey()],
            ExamMapping::query()->forHealthUnit($unitA)->pluck('id')->all(),
        );
        $this->assertEquals(
            [$mappingB->getKey()],
            ExamMapping::query()->forHealthUnit($unitB)->pluck('id')->all(),
        );
        $this->assertNotContains(
            $foreignMapping->getKey(),
            ExamMapping::query()->forHealthUnit($unitA)->pluck('id')->all(),
        );
        $this->assertCount(1, HealthUnitExam::query()->forHealthUnit($unitA)->get());
        $this->assertCount(2, Exam::query()->forOrganization((int) $unitA->organization_id)->get());
        $this->assertCount(1, Exam::query()->forOrganization((int) $foreignUnit->organization_id)->get());
    }

    public function test_backfill_creates_exact_mappings_and_enabled_availability_idempotently(): void
    {
        $unit = $this->createHealthUnit('BACKFILL');
        $integration = $this->integration($unit);
        $active = $integration->exams()->create([
            'external_code' => '127',
            'name' => 'Hemograma completo',
            'sus_procedure_code' => '0202020380',
            'is_active' => true,
        ]);
        $integration->exams()->create([
            'external_code' => '999',
            'name' => 'Exame desativado',
            'is_active' => false,
        ]);

        $this->artisan('laboratory:backfill-exam-catalog')->assertExitCode(0);

        $mapping = ExamMapping::query()->sole();
        $mapping->setRelation('exam', $mapping->resolveExam());
        $availability = HealthUnitExam::query()->sole();
        $this->assertSame($active->external_code, $mapping->external_code);
        $this->assertSame($active->name, $mapping->external_name_snapshot);
        $this->assertSame(ExamMappingMatchType::Exact, $mapping->match_type);
        $this->assertSame($unit->organization_id, $mapping->exam->organization_id);
        $this->assertSame($unit->getKey(), $availability->health_unit_id);
        $this->assertTrue($availability->is_enabled);
        $this->assertNotNull($active->fresh()?->public_id);

        $this->artisan('laboratory:backfill-exam-catalog')->assertExitCode(0);

        $this->assertDatabaseCount('exams', 1);
        $this->assertDatabaseCount('exam_mappings', 1);
        $this->assertDatabaseCount('health_unit_exams', 1);
    }

    public function test_database_seed_chain_populates_the_canonical_catalog_without_manual_command(): void
    {
        $unit = $this->createHealthUnit('SEED-CHAIN');

        $this->seed([
            SynclabExamCatalogSeeder::class,
            CanonicalExamCatalogBackfillSeeder::class,
        ]);

        $integration = LaboratoryIntegration::query()->sole();
        $activeExamCount = $integration->exams()->where('is_active', true)->count();

        $this->assertGreaterThan(0, $activeExamCount);
        $this->assertSame($activeExamCount, Exam::query()->count());
        $this->assertSame($activeExamCount, ExamMapping::query()->count());
        $this->assertSame($activeExamCount, HealthUnitExam::query()->count());
        $this->assertSame(
            $activeExamCount,
            HealthUnitExam::query()
                ->where('health_unit_id', $unit->getKey())
                ->where('is_enabled', true)
                ->count(),
        );
    }

    public function test_catalog_rejects_cross_tenant_mapping_and_availability_without_unit_mapping(): void
    {
        $unit = $this->createHealthUnit('LOCAL');
        $foreignUnit = $this->createHealthUnit('FOREIGN');
        $foreignIntegration = $this->integration($foreignUnit);
        $exam = Exam::query()->create([
            'organization_id' => $unit->organization_id,
            'name' => 'Exame local',
        ]);

        try {
            ExamMapping::query()->create([
                'exam_id' => $exam->getKey(),
                'laboratory_integration_id' => $foreignIntegration->getKey(),
                'external_code' => 'INVALID',
                'external_name_snapshot' => 'Exame inválido',
                'match_type' => ExamMappingMatchType::Manual,
            ]);
            $this->fail('Era esperado que o mapping entre organizações fosse rejeitado.');
        } catch (LogicException $exception) {
            $this->assertSame(
                'O mapping de exame deve pertencer à mesma organização da integração.',
                $exception->getMessage(),
            );
        }

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Um exame só pode ser habilitado quando possui mapping ativo para a unidade.');
        HealthUnitExam::query()->create([
            'exam_id' => $exam->getKey(),
            'health_unit_id' => $unit->getKey(),
            'is_enabled' => true,
        ]);
    }

    private function integration(HealthUnit $unit): LaboratoryIntegration
    {
        return LaboratoryIntegration::query()->create([
            'organization_id' => $unit->organization_id,
            'health_unit_id' => $unit->getKey(),
            'provider' => 'synclab',
            'is_active' => true,
        ]);
    }

    private function mappedExam(
        HealthUnit $unit,
        LaboratoryIntegration $integration,
        string $externalCode,
    ): ExamMapping {
        $exam = Exam::query()->create([
            'organization_id' => $unit->organization_id,
            'name' => "Exame {$externalCode}",
        ]);
        $mapping = ExamMapping::query()->create([
            'exam_id' => $exam->getKey(),
            'laboratory_integration_id' => $integration->getKey(),
            'external_code' => $externalCode,
            'external_name_snapshot' => $exam->name,
            'match_type' => ExamMappingMatchType::Exact,
            'mapped_at' => now(),
        ]);
        HealthUnitExam::query()->create([
            'exam_id' => $exam->getKey(),
            'health_unit_id' => $unit->getKey(),
            'is_enabled' => true,
            'enabled_at' => now(),
        ]);

        return $mapping;
    }
}
