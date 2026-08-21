<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Laboratory\Domain\Enums\ExamCatalogMatchStatus;
use App\Modules\Laboratory\Infrastructure\Eloquent\ExamCatalogImportCandidate;
use App\Modules\Laboratory\Infrastructure\Eloquent\LaboratoryExam;
use App\Modules\Laboratory\Infrastructure\Eloquent\LaboratoryIntegration;
use Database\Seeders\SynclabExamCatalogSeeder;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\RefreshCoreAndTenantDatabase;
use Tests\TestCase;

final class SynclabExamCatalogSeederTest extends TestCase
{
    use RefreshCoreAndTenantDatabase;

    public function test_it_imports_only_parent_exams_and_preserves_valid_sus_codes(): void
    {
        config()->set('sync_sus.synclab.unit_code', 'CENTRAL');
        config()->set('sync_sus.synclab.cnes', '6612547');
        config()->set('sync_sus.synclab.enabled', false);

        $unit = $this->createHealthUnit('CENTRAL');
        $this->seed(SynclabExamCatalogSeeder::class);

        $integration = LaboratoryIntegration::query()->sole();
        $this->assertSame($unit->getKey(), $integration->health_unit_id);
        $this->assertTrue($integration->is_active);
        $this->assertFalse($integration->transmission_enabled);
        $this->assertFalse($integration->result_sync_enabled);
        $this->assertSame('6612547', $unit->fresh()?->cnes_code);

        $exams = LaboratoryExam::query()
            ->where('laboratory_integration_id', $integration->getKey())
            ->where('is_active', true)
            ->get();

        $this->assertCount(123, $exams);
        $this->assertSame(
            '0202020380',
            $exams->firstWhere('external_code', '127')?->sus_procedure_code,
        );
        $this->assertTrue($exams->whereNotNull('sus_procedure_code')->every(
            static fn (LaboratoryExam $exam): bool => strlen((string) $exam->sus_procedure_code) === 10,
        ));
    }

    public function test_it_does_not_overwrite_or_deactivate_manually_managed_exams(): void
    {
        $unit = $this->createHealthUnit('CENTRAL');
        $this->seed(SynclabExamCatalogSeeder::class);
        $integration = LaboratoryIntegration::query()->sole();
        $overridden = $integration->exams()->where('external_code', '127')->sole();
        $overridden->update([
            'name' => 'Hemograma configurado pela unidade',
            'is_active' => false,
            'source_version' => null,
            'content_hash' => null,
        ]);
        $integration->exams()->create([
            'external_code' => 'LOCAL-01',
            'name' => 'Exame cadastrado manualmente',
            'is_active' => true,
            'source_version' => null,
        ]);
        $integration->update(['result_sync_enabled' => true]);

        $this->seed(SynclabExamCatalogSeeder::class);

        $this->assertDatabaseHas('laboratory_exams', [
            'id' => $overridden->getKey(),
            'name' => 'Hemograma configurado pela unidade',
            'is_active' => false,
            'source_version' => null,
        ]);
        $this->assertDatabaseHas('laboratory_exams', [
            'laboratory_integration_id' => $integration->getKey(),
            'external_code' => 'LOCAL-01',
            'name' => 'Exame cadastrado manualmente',
            'is_active' => true,
        ]);
        $this->assertSame(124, $integration->exams()->count());
        $this->assertSame($unit->getKey(), $integration->health_unit_id);
        $this->assertTrue($integration->fresh()?->result_sync_enabled);
        $this->assertDatabaseMissing('exam_catalog_import_candidates', [
            'laboratory_exam_id' => $overridden->getKey(),
        ]);
    }

    public function test_catalog_accepts_a_changed_number_of_parent_rows_and_creates_review_candidates(): void
    {
        config()->set('sync_sus.synclab.catalog_path', base_path('tests/Fixtures/synclab_exam_catalog_small.csv'));
        $this->createHealthUnit('SMALL-CATALOG');

        $this->seed(SynclabExamCatalogSeeder::class);

        $this->assertDatabaseCount('laboratory_exams', 2);
        $this->assertDatabaseCount('exam_catalog_import_candidates', 2);
        $this->assertSame(
            [ExamCatalogMatchStatus::Unmatched],
            ExamCatalogImportCandidate::query()->pluck('match_status')->unique()->values()->all(),
        );
    }

    public function test_unchanged_second_import_uses_one_batched_lookup_and_does_not_rewrite_exams(): void
    {
        $this->createHealthUnit('IDEMPOTENT-CATALOG');
        $this->seed(SynclabExamCatalogSeeder::class);

        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            $this->seed(SynclabExamCatalogSeeder::class);
            $queries = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }

        $laboratoryExamQueries = collect($queries)->filter(
            static fn (array $query): bool => str_contains(strtolower($query['query']), 'laboratory_exams'),
        );
        $perItemLookups = $laboratoryExamQueries->filter(
            static fn (array $query): bool => str_starts_with(strtolower(ltrim($query['query'])), 'select')
                && str_contains(strtolower($query['query']), 'external_code')
                && str_contains(strtolower($query['query']), 'limit 1'),
        );
        $batchedLookups = $laboratoryExamQueries->filter(
            static fn (array $query): bool => str_starts_with(strtolower(ltrim($query['query'])), 'select')
                && str_contains(strtolower($query['query']), 'laboratory_integration_id')
                && ! str_contains(strtolower($query['query']), 'limit'),
        );
        $writes = $laboratoryExamQueries->filter(
            static fn (array $query): bool => preg_match('/^\s*(insert|update)/i', $query['query']) === 1,
        );

        $this->assertCount(0, $perItemLookups);
        $this->assertCount(1, $batchedLookups);
        $this->assertCount(1, $writes);
        $this->assertStringContainsString('not in', strtolower($writes->first()['query']));
        $this->assertDatabaseCount('laboratory_exams', 123);
    }
}
