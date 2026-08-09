<?php

declare(strict_types=1);

namespace App\Modules\Laboratory\Application\Actions;

use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Laboratory\Domain\Enums\ExamCatalogMatchStatus;
use App\Modules\Laboratory\Domain\Enums\ExamMappingMatchType;
use App\Modules\Laboratory\Infrastructure\Eloquent\Exam;
use App\Modules\Laboratory\Infrastructure\Eloquent\ExamCatalogImportCandidate;
use App\Modules\Laboratory\Infrastructure\Eloquent\ExamMapping;
use App\Modules\Laboratory\Infrastructure\Eloquent\HealthUnitExam;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

final readonly class ResolveExamCatalogCandidateAction
{
    public function __construct(private RecordLaboratoryCatalogAuditAction $audit) {}

    public function execute(
        ExamCatalogImportCandidate $candidate,
        string $decision,
        User $actor,
        ?Exam $selectedExam = null,
        bool $enableForUnit = false,
    ): ExamCatalogImportCandidate {
        return DB::transaction(function () use (
            $candidate,
            $decision,
            $actor,
            $selectedExam,
            $enableForUnit,
        ): ExamCatalogImportCandidate {
            $candidate = ExamCatalogImportCandidate::query()
                ->with(['laboratoryExam', 'integration', 'suggestedExam', 'existingMapping.exam'])
                ->lockForUpdate()
                ->findOrFail($candidate->getKey());
            $this->authorize($candidate, $actor);
            if ($candidate->resolution !== null) {
                throw new LogicException('Este candidato de catálogo já foi resolvido.');
            }

            $beforeMapping = $candidate->existingMapping?->exam?->public_id;
            $mapping = match ($decision) {
                'confirm' => $this->confirm($candidate, $actor),
                'create' => $this->createCanonicalExam($candidate, $actor),
                'keep_existing' => $this->keepExisting($candidate),
                'remap' => $this->remap($candidate, $actor, $selectedExam),
                'ignore' => null,
                default => throw new InvalidArgumentException('Decisão inválida para o matching de catálogo.'),
            };

            if ($enableForUnit && $mapping !== null) {
                HealthUnitExam::query()->updateOrCreate([
                    'exam_id' => $mapping->exam_id,
                    'health_unit_id' => $candidate->integration->health_unit_id,
                ], [
                    'is_enabled' => true,
                    'enabled_at' => now(),
                    'enabled_by' => $actor->getKey(),
                ]);
            }

            $candidate->forceFill([
                'existing_mapping_id' => $mapping?->getKey() ?? $candidate->existing_mapping_id,
                'resolution' => $decision,
                'resolved_by' => $actor->getKey(),
                'resolved_at' => now(),
            ])->save();
            $mapping?->loadMissing('exam');
            $this->audit->execute(
                'laboratory.exam_catalog_match_resolved',
                $actor,
                (int) $candidate->integration->health_unit_id,
                [
                    'candidate' => $candidate->public_id,
                    'decision' => $decision,
                    'match_status' => $candidate->match_status->value,
                    'external_code' => $candidate->laboratoryExam->external_code,
                    'before_exam' => $beforeMapping,
                    'after_exam' => $mapping?->exam?->public_id,
                    'enabled_for_unit' => $enableForUnit,
                ],
            );

            return $candidate->refresh();
        });
    }

    private function authorize(ExamCatalogImportCandidate $candidate, User $actor): void
    {
        $sameOrganization = (int) $actor->organization_id === (int) $candidate->organization_id;
        if (! $actor->is_active || (! $actor->isPlatformAdministrator() && (! $sameOrganization || ! $actor->can('administration.manage')))) {
            throw new AuthorizationException('Usuário sem permissão para revisar o catálogo desta organização.');
        }
    }

    private function confirm(ExamCatalogImportCandidate $candidate, User $actor): ExamMapping
    {
        if ($candidate->match_status !== ExamCatalogMatchStatus::Probable || $candidate->suggestedExam === null) {
            throw new LogicException('A confirmação exige um match provável com exame sugerido.');
        }

        return $this->saveMapping($candidate, $candidate->suggestedExam, ExamMappingMatchType::Probable, $actor);
    }

    private function createCanonicalExam(ExamCatalogImportCandidate $candidate, User $actor): ExamMapping
    {
        if ($candidate->match_status !== ExamCatalogMatchStatus::Unmatched) {
            throw new LogicException('A criação de exame canônico exige um candidato sem correspondência.');
        }
        $exam = Exam::query()->create([
            'organization_id' => $candidate->organization_id,
            'name' => $candidate->laboratoryExam->name,
            'sus_procedure_code' => $candidate->laboratoryExam->sus_procedure_code,
            'is_active' => true,
        ]);

        return $this->saveMapping($candidate, $exam, ExamMappingMatchType::Manual, $actor);
    }

    private function keepExisting(ExamCatalogImportCandidate $candidate): ExamMapping
    {
        if ($candidate->match_status !== ExamCatalogMatchStatus::Conflict
            || $candidate->existingMapping === null
            || ! $candidate->existingMapping->is_active) {
            throw new LogicException('Não existe mapping ativo para preservar neste conflito.');
        }

        return $candidate->existingMapping;
    }

    private function remap(
        ExamCatalogImportCandidate $candidate,
        User $actor,
        ?Exam $selectedExam,
    ): ExamMapping {
        $exam = $selectedExam ?? $candidate->suggestedExam;
        if ($candidate->match_status !== ExamCatalogMatchStatus::Conflict || $exam === null) {
            throw new LogicException('O remapeamento exige um conflito e um exame canônico selecionado.');
        }
        if ((int) $exam->organization_id !== (int) $candidate->organization_id) {
            throw new LogicException('O exame selecionado pertence a outra organização.');
        }
        if (! $exam->is_active) {
            throw new LogicException('O exame selecionado está inativo.');
        }

        return $this->saveMapping($candidate, $exam, ExamMappingMatchType::Manual, $actor);
    }

    private function saveMapping(
        ExamCatalogImportCandidate $candidate,
        Exam $exam,
        ExamMappingMatchType $matchType,
        User $actor,
    ): ExamMapping {
        return ExamMapping::query()->updateOrCreate([
            'laboratory_integration_id' => $candidate->laboratory_integration_id,
            'external_code' => $candidate->laboratoryExam->external_code,
        ], [
            'exam_id' => $exam->getKey(),
            'external_name_snapshot' => $candidate->laboratoryExam->name,
            'match_type' => $matchType,
            'match_confidence' => $candidate->match_confidence,
            'mapped_by' => $actor->getKey(),
            'mapped_at' => now(),
            'is_active' => true,
        ]);
    }
}
