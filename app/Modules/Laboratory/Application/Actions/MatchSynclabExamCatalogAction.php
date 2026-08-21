<?php

declare(strict_types=1);

namespace App\Modules\Laboratory\Application\Actions;

use App\Modules\Laboratory\Application\Services\ExamNameNormalizer;
use App\Modules\Laboratory\Domain\Enums\ExamCatalogMatchStatus;
use App\Modules\Laboratory\Infrastructure\Eloquent\Exam;
use App\Modules\Laboratory\Infrastructure\Eloquent\ExamCatalogImportCandidate;
use App\Modules\Laboratory\Infrastructure\Eloquent\ExamMapping;
use App\Modules\Laboratory\Infrastructure\Eloquent\HealthUnitExam;
use App\Modules\Laboratory\Infrastructure\Eloquent\LaboratoryExam;
use App\Modules\Laboratory\Infrastructure\Eloquent\LaboratoryIntegration;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final readonly class MatchSynclabExamCatalogAction
{
    private const PROBABLE_SIMILARITY = 0.85;

    public function __construct(private ExamNameNormalizer $normalizer) {}

    /** @return array{exact: int, probable: int, unmatched: int, conflict: int} */
    public function execute(LaboratoryIntegration $integration): array
    {
        return DB::connection($integration->getConnectionName())->transaction(function () use ($integration): array {
            $counts = ['exact' => 0, 'probable' => 0, 'unmatched' => 0, 'conflict' => 0];
            ExamCatalogImportCandidate::query()
                ->where('laboratory_integration_id', $integration->getKey())
                ->whereHas('laboratoryExam', fn ($query) => $query
                    ->where('is_active', false)
                    ->orWhereNull('source_version'))
                ->delete();
            $canonicalExams = Exam::query()
                ->forOrganization((int) $integration->organization_id)
                ->where('is_active', true)
                ->get(['id', 'name', 'sus_procedure_code']);

            LaboratoryExam::query()
                ->where('laboratory_integration_id', $integration->getKey())
                ->where('is_active', true)
                ->whereNotNull('source_version')
                ->orderBy('id')
                ->chunkById(250, function ($laboratoryExams) use ($integration, $canonicalExams, &$counts): void {
                    foreach ($laboratoryExams as $laboratoryExam) {
                        $status = $this->match($integration, $laboratoryExam, $canonicalExams);
                        $counts[$status->value]++;
                    }
                });

            return $counts;
        });
    }

    /** @param Collection<int, Exam> $canonicalExams */
    private function match(
        LaboratoryIntegration $integration,
        LaboratoryExam $laboratoryExam,
        Collection $canonicalExams,
    ): ExamCatalogMatchStatus {
        $mapping = ExamMapping::query()
            ->where('laboratory_integration_id', $integration->getKey())
            ->where('external_code', $laboratoryExam->external_code)
            ->first();
        $mappedExam = $mapping?->resolveExam();
        [$suggestedExam, $confidence, $reason, $ambiguous] = $this->suggest($laboratoryExam, $canonicalExams);

        if ($mapping !== null && $mapping->is_active && $mappedExam?->is_active) {
            $isConflict = ($suggestedExam !== null && (int) $suggestedExam->getKey() !== (int) $mapping->exam_id)
                || $ambiguous;
            if ($isConflict) {
                return $this->persistCandidate(
                    $integration,
                    $laboratoryExam,
                    ExamCatalogMatchStatus::Conflict,
                    $suggestedExam,
                    $mapping,
                    $confidence,
                    $ambiguous ? 'ambiguous_canonical_match' : 'external_code_mapping_disagrees',
                );
            }

            HealthUnitExam::query()->firstOrCreate([
                'exam_id' => $mapping->exam_id,
                'health_unit_id' => $integration->health_unit_id,
            ], [
                'exam_public_id' => $mappedExam->public_id,
                'is_enabled' => true,
                'enabled_at' => now(),
            ]);

            return $this->persistCandidate(
                $integration,
                $laboratoryExam,
                ExamCatalogMatchStatus::Exact,
                $mappedExam,
                $mapping,
                1.0,
                'existing_external_code_mapping',
                'auto_applied',
            );
        }

        if ($mapping !== null || $ambiguous) {
            return $this->persistCandidate(
                $integration,
                $laboratoryExam,
                ExamCatalogMatchStatus::Conflict,
                $suggestedExam,
                $mapping,
                $confidence,
                $mapping !== null ? 'inactive_external_code_mapping' : 'ambiguous_canonical_match',
            );
        }

        if ($suggestedExam !== null) {
            return $this->persistCandidate(
                $integration,
                $laboratoryExam,
                ExamCatalogMatchStatus::Probable,
                $suggestedExam,
                null,
                $confidence,
                $reason,
            );
        }

        return $this->persistCandidate(
            $integration,
            $laboratoryExam,
            ExamCatalogMatchStatus::Unmatched,
            null,
            null,
            null,
            'no_canonical_match',
        );
    }

    /**
     * @param  Collection<int, Exam>  $canonicalExams
     * @return array{0: ?Exam, 1: ?float, 2: string, 3: bool}
     */
    private function suggest(LaboratoryExam $laboratoryExam, Collection $canonicalExams): array
    {
        $susCode = trim((string) $laboratoryExam->sus_procedure_code);
        if ($susCode !== '') {
            $matches = $canonicalExams
                ->filter(fn (Exam $exam): bool => (string) $exam->sus_procedure_code === $susCode)
                ->map(fn (Exam $exam): array => [
                    'exam' => $exam,
                    'confidence' => $this->normalizer->similarity($laboratoryExam->name, $exam->name),
                ])
                ->filter(fn (array $match): bool => $match['confidence'] >= self::PROBABLE_SIMILARITY)
                ->sortByDesc('confidence')
                ->values();
            if ($matches->isNotEmpty()) {
                $top = $matches->first();
                $ambiguous = $matches->count() > 1
                    && $matches->filter(fn (array $match): bool => $match['confidence'] === $top['confidence'])->count() > 1;

                return [$ambiguous ? null : $top['exam'], $top['confidence'], 'sus_and_similar_name', $ambiguous];
            }
        }

        if ($susCode === '') {
            $normalized = $this->normalizer->normalize($laboratoryExam->name);
            $matches = $canonicalExams->filter(fn (Exam $exam): bool => $exam->sus_procedure_code === null
                && $this->normalizer->normalize($exam->name) === $normalized)->values();
            if ($matches->count() === 1) {
                return [$matches->first(), 1.0, 'normalized_name_without_sus', false];
            }
            if ($matches->count() > 1) {
                return [null, 1.0, 'ambiguous_canonical_match', true];
            }
        }

        return [null, null, 'no_canonical_match', false];
    }

    private function persistCandidate(
        LaboratoryIntegration $integration,
        LaboratoryExam $laboratoryExam,
        ExamCatalogMatchStatus $status,
        ?Exam $suggestedExam,
        ?ExamMapping $mapping,
        ?float $confidence,
        string $reason,
        ?string $automaticResolution = null,
    ): ExamCatalogMatchStatus {
        $sourceHash = $laboratoryExam->content_hash ?: hash('sha256', implode('|', [
            $laboratoryExam->external_code,
            $laboratoryExam->name,
            $laboratoryExam->sus_procedure_code,
            $laboratoryExam->source_version,
        ]));
        $candidate = ExamCatalogImportCandidate::query()
            ->firstOrNew(['laboratory_exam_id' => $laboratoryExam->getKey()]);
        $sameSuggestion = (int) ($candidate->suggested_exam_id ?? 0) === (int) ($suggestedExam?->getKey() ?? 0);
        $sameState = $candidate->exists
            && $candidate->source_hash === $sourceHash
            && $candidate->match_status === $status
            && $sameSuggestion;

        $candidate->fill([
            'organization_id' => $integration->organization_id,
            'laboratory_integration_id' => $integration->getKey(),
            'suggested_exam_id' => $suggestedExam?->getKey(),
            'existing_mapping_id' => $mapping?->getKey(),
            'match_status' => $status,
            'match_confidence' => $confidence,
            'reason' => $reason,
            'source_version' => $laboratoryExam->source_version,
            'source_hash' => $sourceHash,
        ]);
        if ($automaticResolution !== null) {
            $candidate->fill([
                'resolution' => $automaticResolution,
                'resolved_by' => null,
                'resolved_at' => now(),
            ]);
        } elseif (! $sameState) {
            $candidate->fill([
                'resolution' => null,
                'resolved_by' => null,
                'resolved_at' => null,
            ]);
        }
        $candidate->save();

        return $status;
    }
}
