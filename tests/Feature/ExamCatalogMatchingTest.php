<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Laboratory\Application\Actions\MatchSynclabExamCatalogAction;
use App\Modules\Laboratory\Application\Actions\ResolveExamCatalogCandidateAction;
use App\Modules\Laboratory\Domain\Enums\ExamCatalogMatchStatus;
use App\Modules\Laboratory\Domain\Enums\ExamMappingMatchType;
use App\Modules\Laboratory\Infrastructure\Eloquent\Exam;
use App\Modules\Laboratory\Infrastructure\Eloquent\ExamCatalogImportCandidate;
use App\Modules\Laboratory\Infrastructure\Eloquent\ExamMapping;
use App\Modules\Laboratory\Infrastructure\Eloquent\LaboratoryIntegration;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\RefreshCoreAndTenantDatabase;
use Tests\TestCase;

final class ExamCatalogMatchingTest extends TestCase
{
    use RefreshCoreAndTenantDatabase;

    public function test_catalog_matching_classifies_exact_probable_unmatched_and_conflict_without_name_only_exact_match(): void
    {
        $unit = $this->createHealthUnit('MATCHING');
        $integration = $this->integration($unit->organization_id, $unit->getKey());
        $exactExam = $this->canonical((int) $unit->organization_id, 'Ácido úrico', '0202010120');
        $probableSusExam = $this->canonical((int) $unit->organization_id, 'Hemograma completo', '0202020380');
        $probableNameExam = $this->canonical((int) $unit->organization_id, 'Hemácia');
        $oldConflictExam = $this->canonical((int) $unit->organization_id, 'Exame anteriormente mapeado');
        $newConflictSuggestion = $this->canonical((int) $unit->organization_id, 'Glicose', '0202010473');
        $this->mapping($integration, $exactExam, 'EXACT', 'Ácido úrico');
        $this->mapping($integration, $oldConflictExam, 'CONFLICT', 'Exame antigo');
        $this->raw($integration, 'EXACT', 'Ácido úrico', '0202010120');
        $this->raw($integration, 'PROB-SUS', 'HEMOGRAMA COMPLETO', '0202020380');
        $this->raw($integration, 'PROB-NAME', 'Hemácias');
        $this->raw($integration, 'NONE', 'Exame inteiramente novo');
        $this->raw($integration, 'CONFLICT', 'Glicose', '0202010473');

        $result = app(MatchSynclabExamCatalogAction::class)->execute($integration);

        $this->assertSame(['exact' => 1, 'probable' => 2, 'unmatched' => 1, 'conflict' => 1], $result);
        $this->assertSame(
            'auto_applied',
            $this->candidate('EXACT')->resolution,
        );
        $this->assertSame(
            $probableSusExam->getKey(),
            $this->candidate('PROB-SUS')->suggested_exam_id,
        );
        $this->assertSame(
            ExamCatalogMatchStatus::Probable,
            $this->candidate('PROB-NAME')->match_status,
        );
        $this->assertNull($this->candidate('PROB-NAME')->resolution);
        $this->assertSame(
            ExamCatalogMatchStatus::Unmatched,
            $this->candidate('NONE')->match_status,
        );
        $this->assertSame(
            $newConflictSuggestion->getKey(),
            $this->candidate('CONFLICT')->suggested_exam_id,
        );
    }

    public function test_human_confirms_probable_match_with_audit_and_optional_unit_enablement(): void
    {
        $unit = $this->createHealthUnit('REVIEW');
        $this->seed(RolePermissionSeeder::class);
        $manager = $this->createUserWithUnit($unit, ['must_change_password' => false]);
        $manager->assignRole('manager');
        $integration = $this->integration($unit->organization_id, $unit->getKey());
        $canonical = $this->canonical((int) $unit->organization_id, 'Hemograma completo', '0202020380');
        $this->raw($integration, '127', 'HEMOGRAMA COMPLETO', '0202020380');
        app(MatchSynclabExamCatalogAction::class)->execute($integration);
        $candidate = $this->candidate('127');

        $resolved = app(ResolveExamCatalogCandidateAction::class)->execute(
            $candidate,
            'confirm',
            $manager,
            enableForUnit: true,
        );

        $mapping = ExamMapping::query()->sole();
        $this->assertSame('confirm', $resolved->resolution);
        $this->assertSame($canonical->getKey(), $mapping->exam_id);
        $this->assertSame(ExamMappingMatchType::Probable, $mapping->match_type);
        $this->assertSame($manager->getKey(), $mapping->mapped_by);
        $this->assertDatabaseHas('health_unit_exams', [
            'exam_id' => $canonical->getKey(),
            'health_unit_id' => $unit->getKey(),
            'is_enabled' => true,
            'enabled_by' => $manager->getKey(),
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $manager->getKey(),
            'health_unit_id' => $unit->getKey(),
            'action' => 'laboratory.exam_catalog_match_resolved',
        ]);
    }

    public function test_execute_transaction_runs_on_the_integrations_tenant_connection_not_the_default_connection(): void
    {
        $unit = $this->createHealthUnit('CONNTEST');
        $integration = $this->integration($unit->organization_id, $unit->getKey());
        $this->canonical((int) $unit->organization_id, 'Sódio');
        $this->raw($integration, 'CONN', 'Sódio');

        $beganOn = [];
        Event::listen(TransactionBeginning::class, function (TransactionBeginning $event) use (&$beganOn): void {
            $beganOn[] = $event->connectionName;
        });

        app(MatchSynclabExamCatalogAction::class)->execute($integration);

        $this->assertContains($integration->getConnectionName(), $beganOn);
        $this->assertNotContains('core', $beganOn);
    }

    private function integration(int $organizationId, int $healthUnitId): LaboratoryIntegration
    {
        return LaboratoryIntegration::query()->create([
            'organization_id' => $organizationId,
            'health_unit_id' => $healthUnitId,
            'provider' => 'synclab',
            'is_active' => true,
        ]);
    }

    private function canonical(int $organizationId, string $name, ?string $susCode = null): Exam
    {
        return Exam::query()->create([
            'organization_id' => $organizationId,
            'name' => $name,
            'sus_procedure_code' => $susCode,
        ]);
    }

    private function mapping(
        LaboratoryIntegration $integration,
        Exam $exam,
        string $externalCode,
        string $externalName,
    ): ExamMapping {
        return ExamMapping::query()->create([
            'exam_id' => $exam->getKey(),
            'laboratory_integration_id' => $integration->getKey(),
            'external_code' => $externalCode,
            'external_name_snapshot' => $externalName,
            'match_type' => ExamMappingMatchType::Exact,
            'mapped_at' => now(),
        ]);
    }

    private function raw(
        LaboratoryIntegration $integration,
        string $externalCode,
        string $name,
        ?string $susCode = null,
    ): void {
        $integration->exams()->create([
            'external_code' => $externalCode,
            'name' => $name,
            'sus_procedure_code' => $susCode,
            'source_version' => 'catalog-v2',
            'content_hash' => hash('sha256', $externalCode.'|'.$name.'|'.$susCode),
        ]);
    }

    private function candidate(string $externalCode): ExamCatalogImportCandidate
    {
        return ExamCatalogImportCandidate::query()
            ->whereHas('laboratoryExam', fn ($query) => $query->where('external_code', $externalCode))
            ->sole();
    }
}
