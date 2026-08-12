<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Laboratory\Application\Actions\BackfillCanonicalExamCatalogAction;
use App\Modules\Laboratory\Application\Actions\ImportExamGroupsAction;
use App\Modules\Laboratory\Application\Actions\ResolveExamCatalogCandidateAction;
use App\Modules\Laboratory\Application\Actions\ResolveExamGroupImportConflictAction;
use App\Modules\Laboratory\Domain\Enums\ExamCatalogMatchStatus;
use App\Modules\Laboratory\Domain\Enums\ExamMappingMatchType;
use App\Modules\Laboratory\Infrastructure\Eloquent\Exam;
use App\Modules\Laboratory\Infrastructure\Eloquent\ExamCatalogImportCandidate;
use App\Modules\Laboratory\Infrastructure\Eloquent\ExamGroup;
use App\Modules\Laboratory\Infrastructure\Eloquent\ExamGroupImportConflict;
use App\Modules\Laboratory\Infrastructure\Eloquent\ExamMapping;
use App\Modules\Laboratory\Infrastructure\Eloquent\LaboratoryIntegration;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

final class LaboratoryCatalogSharedSqliteTransactionTest extends TestCase
{
    private string $databasePath;

    protected function setUp(): void
    {
        parent::setUp();
        $directory = storage_path('framework/testing');
        File::ensureDirectoryExists($directory);
        $this->databasePath = $directory.'/catalog-shared-'.Str::ulid().'.sqlite';
        touch($this->databasePath);
        $connection = [
            'driver' => 'sqlite',
            'database' => $this->databasePath,
            'prefix' => '',
            'foreign_key_constraints' => false,
            'busy_timeout' => 100,
            'journal_mode' => 'WAL',
            'synchronous' => 'NORMAL',
            'transaction_mode' => 'DEFERRED',
        ];
        config([
            'database.default' => 'tenant_test',
            'database.connections.core' => $connection,
            'database.connections.tenant_test' => $connection,
            'tenancy.legacy_connection' => 'tenant_test',
        ]);
        DB::purge('core');
        DB::purge('tenant_test');
        $this->artisan('migrate:fresh', ['--database' => 'tenant_test', '--force' => true])
            ->assertSuccessful();
    }

    protected function tearDown(): void
    {
        if (app()->bound(TenantContext::class)) {
            app(TenantContext::class)->reset();
        }
        DB::purge('core');
        DB::purge('tenant_test');
        if (isset($this->databasePath)
            && str_starts_with($this->databasePath, storage_path('framework/testing/catalog-shared-'))) {
            @unlink($this->databasePath);
            @unlink($this->databasePath.'-shm');
            @unlink($this->databasePath.'-wal');
        }
        parent::tearDown();
    }

    public function test_catalog_actions_do_not_lock_when_core_and_tenant_share_one_sqlite_file(): void
    {
        $unit = $this->createHealthUnit('SHARED-CATALOG');
        $actor = $this->createPlatformAdministrator();
        $integration = LaboratoryIntegration::query()->create([
            'organization_id' => $unit->organization_id,
            'health_unit_id' => $unit->getKey(),
            'provider' => 'synclab',
            'is_active' => true,
        ]);
        $raw = $integration->exams()->create([
            'external_code' => 'RAW',
            'name' => 'Exame bruto',
            'source_version' => 'shared-file',
            'content_hash' => hash('sha256', 'RAW|Exame bruto'),
        ]);

        $backfill = app(BackfillCanonicalExamCatalogAction::class)->execute();

        $this->assertSame([
            'processed' => 1,
            'exams_created' => 1,
            'mappings_created' => 1,
            'availabilities_created' => 1,
        ], $backfill);
        $this->assertTrue(ExamMapping::query()->where('external_code', 'RAW')->exists());

        $unmatched = $integration->exams()->create([
            'external_code' => 'NEW',
            'name' => 'Exame canônico novo',
            'source_version' => 'shared-file',
            'content_hash' => hash('sha256', 'NEW|Exame canônico novo'),
        ]);
        $candidate = ExamCatalogImportCandidate::query()->create([
            'organization_id' => $unit->organization_id,
            'laboratory_integration_id' => $integration->getKey(),
            'laboratory_exam_id' => $unmatched->getKey(),
            'match_status' => ExamCatalogMatchStatus::Unmatched,
            'reason' => 'no_canonical_match',
            'source_version' => 'shared-file',
            'source_hash' => $unmatched->content_hash,
        ]);

        $resolvedCandidate = app(ResolveExamCatalogCandidateAction::class)
            ->execute($candidate, 'create', $actor, enableForUnit: true);

        $this->assertSame('create', $resolvedCandidate->resolution);
        $this->assertTrue(ExamMapping::query()->where('external_code', 'NEW')->exists());

        $examA = $this->mappedExam($integration, 'A', 'Hemograma');
        $examB = $this->mappedExam($integration, 'B', 'Glicose');
        $import = app(ImportExamGroupsAction::class)->execute(
            $integration,
            base_path('tests/Fixtures/synclab_exam_groups.csv'),
        );

        $this->assertSame(['created' => 1, 'unchanged' => 0, 'conflicts' => 1], $import);
        $this->assertEqualsCanonicalizing(
            [$examA->getKey(), $examB->getKey()],
            ExamGroup::query()->where('normalized_name', 'PRE OPERATORIO')->sole()->items()->pluck('exam_id')->all(),
        );

        $conflict = ExamGroupImportConflict::query()->create([
            'organization_id' => $unit->organization_id,
            'laboratory_integration_id' => $integration->getKey(),
            'external_group_code' => 'NEW-GROUP',
            'external_name' => 'Grupo criado na resolução',
            'normalized_name' => 'GRUPO CRIADO NA RESOLUCAO',
            'conflict_type' => 'manual_review',
            'source_items' => [
                ['external_code' => 'A', 'display_order' => 1, 'exam_id' => $examA->getKey()],
                ['external_code' => 'B', 'display_order' => 2, 'exam_id' => $examB->getKey()],
            ],
            'source_hash' => hash('sha256', 'NEW-GROUP'),
            'status' => 'pending',
        ]);

        $resolvedConflict = app(ResolveExamGroupImportConflictAction::class)
            ->execute($conflict, 'accept', $actor);

        $this->assertSame('resolved', $resolvedConflict->status);
        $this->assertNotNull($resolvedConflict->exam_group_id);
        $this->assertEqualsCanonicalizing(
            [$examA->getKey(), $examB->getKey()],
            ExamGroup::query()->findOrFail($resolvedConflict->exam_group_id)->items()->pluck('exam_id')->all(),
        );
    }

    private function mappedExam(LaboratoryIntegration $integration, string $externalCode, string $name): Exam
    {
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
            'is_active' => true,
        ]);

        return $exam;
    }
}
