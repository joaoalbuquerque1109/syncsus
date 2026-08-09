<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Laboratory\Application\Actions\ImportExamGroupsAction;
use App\Modules\Laboratory\Application\Actions\ResolveExamGroupImportConflictAction;
use App\Modules\Laboratory\Domain\Enums\ExamMappingMatchType;
use App\Modules\Laboratory\Infrastructure\Eloquent\Exam;
use App\Modules\Laboratory\Infrastructure\Eloquent\ExamGroup;
use App\Modules\Laboratory\Infrastructure\Eloquent\ExamGroupImportConflict;
use App\Modules\Laboratory\Infrastructure\Eloquent\ExamMapping;
use App\Modules\Laboratory\Infrastructure\Eloquent\LaboratoryIntegration;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ExamGroupImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_group_import_requires_all_mappings_and_never_overwrites_local_composition_silently(): void
    {
        $unit = $this->createHealthUnit('GROUPS');
        $this->seed(RolePermissionSeeder::class);
        $manager = $this->createUserWithUnit($unit, ['must_change_password' => false]);
        $manager->assignRole('manager');
        $integration = LaboratoryIntegration::query()->create([
            'organization_id' => $unit->organization_id,
            'health_unit_id' => $unit->getKey(),
            'provider' => 'synclab',
            'is_active' => true,
        ]);
        $examA = $this->mappedExam($integration, 'A', 'Hemograma');
        $examB = $this->mappedExam($integration, 'B', 'Glicose');
        $localExam = Exam::query()->create([
            'organization_id' => $unit->organization_id,
            'name' => 'Exame acrescentado localmente',
        ]);
        $path = base_path('tests/Fixtures/synclab_exam_groups.csv');

        $firstImport = app(ImportExamGroupsAction::class)->execute($integration, $path);

        $this->assertSame(['created' => 1, 'unchanged' => 0, 'conflicts' => 1], $firstImport);
        $group = ExamGroup::query()->where('normalized_name', 'PRE OPERATORIO')->sole();
        $this->assertEqualsCanonicalizing(
            [$examA->getKey(), $examB->getKey()],
            $group->items()->pluck('exam_id')->all(),
        );
        $this->assertDatabaseMissing('exam_groups', ['normalized_name' => 'GRUPO PENDENTE']);
        $this->assertDatabaseHas('exam_group_import_conflicts', [
            'external_group_code' => 'PEND',
            'conflict_type' => 'missing_mappings',
            'status' => 'pending',
        ]);

        $group->items()->create(['exam_id' => $localExam->getKey(), 'display_order' => 3]);
        $secondImport = app(ImportExamGroupsAction::class)->execute($integration, $path);

        $this->assertSame(2, $secondImport['conflicts']);
        $this->assertTrue($group->fresh()?->items()->where('exam_id', $localExam->getKey())->exists());
        $compositionConflict = ExamGroupImportConflict::query()
            ->where('conflict_type', 'composition_mismatch')
            ->sole();
        $resolved = app(ResolveExamGroupImportConflictAction::class)->execute(
            $compositionConflict,
            'accept',
            $manager,
        );

        $this->assertSame('resolved', $resolved->status);
        $this->assertSame('accept', $resolved->decision);
        $this->assertEqualsCanonicalizing(
            [$examA->getKey(), $examB->getKey()],
            $group->fresh()?->items()->pluck('exam_id')->all(),
        );
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $manager->getKey(),
            'health_unit_id' => $unit->getKey(),
            'action' => 'laboratory.exam_group_import_conflict_resolved',
        ]);
    }

    private function mappedExam(
        LaboratoryIntegration $integration,
        string $externalCode,
        string $name,
    ): Exam {
        $exam = Exam::query()->create([
            'organization_id' => $integration->organization_id,
            'name' => $name,
        ]);
        ExamMapping::query()->create([
            'exam_id' => $exam->getKey(),
            'laboratory_integration_id' => $integration->getKey(),
            'external_code' => $externalCode,
            'external_name_snapshot' => $name,
            'match_type' => ExamMappingMatchType::Exact,
            'mapped_at' => now(),
        ]);

        return $exam;
    }
}
