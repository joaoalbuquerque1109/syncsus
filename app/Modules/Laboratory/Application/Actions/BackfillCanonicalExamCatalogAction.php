<?php

declare(strict_types=1);

namespace App\Modules\Laboratory\Application\Actions;

use App\Modules\Laboratory\Domain\Enums\ExamMappingMatchType;
use App\Modules\Laboratory\Infrastructure\Eloquent\Exam;
use App\Modules\Laboratory\Infrastructure\Eloquent\ExamMapping;
use App\Modules\Laboratory\Infrastructure\Eloquent\HealthUnitExam;
use App\Modules\Laboratory\Infrastructure\Eloquent\LaboratoryExam;
use RuntimeException;

final readonly class BackfillCanonicalExamCatalogAction
{
    /**
     * @return array{processed: int, exams_created: int, mappings_created: int, availabilities_created: int}
     */
    public function execute(): array
    {
        $result = [
            'processed' => 0,
            'exams_created' => 0,
            'mappings_created' => 0,
            'availabilities_created' => 0,
        ];

        LaboratoryExam::query()
            ->with('integration:id,organization_id,health_unit_id')
            ->where('is_active', true)
            ->orderBy('id')
            ->chunkById(250, function ($laboratoryExams) use (&$result): void {
                $integrationIds = $laboratoryExams
                    ->pluck('laboratory_integration_id')
                    ->unique()
                    ->values();
                $externalCodes = $laboratoryExams
                    ->pluck('external_code')
                    ->unique()
                    ->values();
                $mappingIndex = ExamMapping::query()
                    ->whereIn('laboratory_integration_id', $integrationIds)
                    ->whereIn('external_code', $externalCodes)
                    ->get()
                    ->keyBy(fn (ExamMapping $mapping): string => $this->indexKey(
                        $mapping->laboratory_integration_id,
                        $mapping->external_code,
                    ));
                $healthUnitIds = $laboratoryExams
                    ->pluck('integration')
                    ->filter()
                    ->pluck('health_unit_id')
                    ->unique()
                    ->values();
                $examIds = $mappingIndex->pluck('exam_id')->unique()->values();
                $availabilityIndex = HealthUnitExam::query()
                    ->whereIn('exam_id', $examIds)
                    ->whereIn('health_unit_id', $healthUnitIds)
                    ->get()
                    ->keyBy(fn (HealthUnitExam $availability): string => $this->indexKey(
                        $availability->exam_id,
                        $availability->health_unit_id,
                    ));

                foreach ($laboratoryExams as $laboratoryExam) {
                    $integration = $laboratoryExam->integration;
                    if ($integration === null) {
                        throw new RuntimeException("Integração ausente para o exame laboratorial {$laboratoryExam->getKey()}.");
                    }

                    $result['processed']++;
                    $mappingKey = $this->indexKey($integration->getKey(), $laboratoryExam->external_code);
                    $mapping = $mappingIndex->get($mappingKey);

                    if (! $mapping instanceof ExamMapping) {
                        $exam = Exam::query()->create([
                            'organization_id' => $integration->organization_id,
                            'name' => $laboratoryExam->name,
                            'sus_procedure_code' => $laboratoryExam->sus_procedure_code,
                            'is_active' => true,
                        ]);
                        $result['exams_created']++;

                        $mapping = ExamMapping::query()->create([
                            'exam_id' => $exam->getKey(),
                            'laboratory_integration_id' => $integration->getKey(),
                            'external_code' => $laboratoryExam->external_code,
                            'external_name_snapshot' => $laboratoryExam->name,
                            'match_type' => ExamMappingMatchType::Exact,
                            'match_confidence' => 1,
                            'mapped_at' => now(),
                            'is_active' => true,
                        ]);
                        $result['mappings_created']++;
                        $mappingIndex->put($mappingKey, $mapping);
                    }

                    if (! $mapping->is_active) {
                        continue;
                    }

                    $availabilityKey = $this->indexKey($mapping->exam_id, $integration->health_unit_id);
                    if ($availabilityIndex->has($availabilityKey)) {
                        continue;
                    }
                    $availability = HealthUnitExam::query()->firstOrCreate(
                        [
                            'exam_id' => $mapping->exam_id,
                            'health_unit_id' => $integration->health_unit_id,
                        ],
                        [
                            'is_enabled' => true,
                            'enabled_at' => now(),
                        ],
                    );
                    if ($availability->wasRecentlyCreated) {
                        $result['availabilities_created']++;
                    }
                    $availabilityIndex->put($availabilityKey, $availability);
                }
            });

        return $result;
    }

    private function indexKey(int|string $first, int|string $second): string
    {
        return json_encode([(string) $first, (string) $second], JSON_THROW_ON_ERROR);
    }
}
