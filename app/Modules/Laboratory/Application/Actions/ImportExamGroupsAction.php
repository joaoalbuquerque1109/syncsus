<?php

declare(strict_types=1);

namespace App\Modules\Laboratory\Application\Actions;

use App\Modules\Laboratory\Application\Services\CatalogReader;
use App\Modules\Laboratory\Application\Services\ExamNameNormalizer;
use App\Modules\Laboratory\Infrastructure\Eloquent\ExamGroup;
use App\Modules\Laboratory\Infrastructure\Eloquent\ExamGroupImportConflict;
use App\Modules\Laboratory\Infrastructure\Eloquent\ExamMapping;
use App\Modules\Laboratory\Infrastructure\Eloquent\LaboratoryIntegration;
use Illuminate\Support\Str;
use RuntimeException;
use SplFileObject;

final readonly class ImportExamGroupsAction
{
    public function __construct(
        private ExamNameNormalizer $normalizer,
        private CatalogReader $catalog,
    ) {}

    /** @return array{created: int, unchanged: int, conflicts: int} */
    public function execute(LaboratoryIntegration $integration, string $path): array
    {
        if (! is_file($path)) {
            throw new RuntimeException('Arquivo CSV de grupos de exames não encontrado.');
        }

        $counts = ['created' => 0, 'unchanged' => 0, 'conflicts' => 0];
        foreach ($this->groups($path) as $sourceGroup) {
            $normalizedName = $this->normalizer->normalize($sourceGroup['name']);
            $group = ExamGroup::query()
                ->where('organization_id', $integration->organization_id)
                ->where('normalized_name', $normalizedName)
                ->with('items')
                ->first();
            $codes = collect($sourceGroup['items'])->pluck('external_code')->unique()->values();
            $mappings = ExamMapping::query()
                ->where('laboratory_integration_id', $integration->getKey())
                ->where('is_active', true)
                ->whereIn('exam_public_id', $this->catalog
                    ->activeExamPublicIdsForOrganization((int) $integration->organization_id))
                ->whereIn('external_code', $codes)
                ->get()
                ->keyBy('external_code');
            $missingCodes = $codes->reject(fn (string $code): bool => $mappings->has($code))->values()->all();
            $sourceItems = collect($sourceGroup['items'])->map(fn (array $item): array => [
                ...$item,
                'exam_id' => $mappings->get($item['external_code'])?->exam_id,
            ])->all();

            if ($missingCodes !== []) {
                $this->recordConflict(
                    $integration,
                    $group,
                    $sourceGroup,
                    $normalizedName,
                    'missing_mappings',
                    $sourceItems,
                    $missingCodes,
                );
                $counts['conflicts']++;

                continue;
            }

            $sourceExamIds = collect($sourceItems)->pluck('exam_id')->map(fn (mixed $id): int => (int) $id)->unique()->values();
            if ($group === null) {
                $group = ExamGroup::query()->create([
                    'organization_id' => $integration->organization_id,
                    'name' => $sourceGroup['name'],
                    'normalized_name' => $normalizedName,
                    'is_active' => true,
                ]);
                foreach ($sourceItems as $index => $item) {
                    $group->items()->create([
                        'exam_id' => $item['exam_id'],
                        'display_order' => $item['display_order'],
                    ]);
                }
                $counts['created']++;

                continue;
            }

            $currentExamIds = $group->items->pluck('exam_id')->map(fn (mixed $id): int => (int) $id)->unique()->values();
            if ($currentExamIds->sort()->values()->all() === $sourceExamIds->sort()->values()->all()) {
                $counts['unchanged']++;

                continue;
            }

            $this->recordConflict(
                $integration,
                $group,
                $sourceGroup,
                $normalizedName,
                'composition_mismatch',
                $sourceItems,
                [],
            );
            $counts['conflicts']++;
        }

        return $counts;
    }

    /**
     * @return list<array{code: string, name: string, items: list<array{external_code: string, display_order: int}>}>
     */
    private function groups(string $path): array
    {
        $file = new SplFileObject($path, 'r');
        $file->setCsvControl(';');
        $headers = $file->fgetcsv();
        if (! is_array($headers)) {
            throw new RuntimeException('Cabeçalho inválido no CSV de grupos.');
        }
        $headers = array_map(fn (mixed $header): string => $this->header((string) $header), $headers);
        $groups = [];
        while (! $file->eof()) {
            $values = $file->fgetcsv();
            if (! is_array($values) || count($values) !== count($headers)) {
                continue;
            }
            $row = array_combine($headers, array_map(static fn (mixed $value): string => trim((string) $value), $values));
            $code = $this->field($row, ['group_code', 'codigo_grupo', 'codigogrupo']);
            $name = $this->field($row, ['group_name', 'nome_grupo', 'nomegrupo']);
            $examCode = $this->field($row, ['exam_external_code', 'codigo_exame', 'codigoexame']);
            if ($name === '' || $examCode === '') {
                continue;
            }
            $key = $code !== '' ? 'code:'.$code : 'name:'.$this->normalizer->normalize($name);
            $groups[$key] ??= ['code' => $code, 'name' => $name, 'items' => []];
            $displayOrder = (int) $this->field($row, ['display_order', 'ordem', 'ordem_exibicao']);
            $groups[$key]['items'][$examCode] = [
                'external_code' => $examCode,
                'display_order' => max(0, $displayOrder),
            ];
        }

        return array_values(array_map(static function (array $group): array {
            $group['items'] = array_values($group['items']);

            return $group;
        }, $groups));
    }

    /** @param array<string, string> $row
     * @param  list<string>  $keys
     */
    private function field(array $row, array $keys): string
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $row)) {
                return trim($row[$key]);
            }
        }

        return '';
    }

    private function header(string $header): string
    {
        return Str::of(ltrim($header, "\xEF\xBB\xBF"))->ascii()->lower()->replaceMatches('/[^a-z0-9]+/', '_')->trim('_')->toString();
    }

    /**
     * @param  array{code: string, name: string, items: list<array{external_code: string, display_order: int}>}  $sourceGroup
     * @param  list<array{external_code: string, display_order: int, exam_id: mixed}>  $sourceItems
     * @param  list<string>  $missingCodes
     */
    private function recordConflict(
        LaboratoryIntegration $integration,
        ?ExamGroup $group,
        array $sourceGroup,
        string $normalizedName,
        string $type,
        array $sourceItems,
        array $missingCodes,
    ): void {
        $currentExamIds = $group?->items->pluck('exam_id')->map(fn (mixed $id): int => (int) $id)->values()->all() ?? [];
        $sourceExamIds = collect($sourceItems)->pluck('exam_id')->filter()->map(fn (mixed $id): int => (int) $id)->unique()->values();
        $hashPayload = [
            'code' => $sourceGroup['code'],
            'name' => $sourceGroup['name'],
            'items' => $sourceItems,
            'current' => $currentExamIds,
            'missing' => $missingCodes,
        ];
        $sourceHash = hash('sha256', json_encode($hashPayload, JSON_THROW_ON_ERROR));
        ExamGroupImportConflict::query()->firstOrCreate([
            'laboratory_integration_id' => $integration->getKey(),
            'source_hash' => $sourceHash,
        ], [
            'organization_id' => $integration->organization_id,
            'exam_group_id' => $group?->getKey(),
            'external_group_code' => $sourceGroup['code'] !== '' ? $sourceGroup['code'] : null,
            'external_name' => $sourceGroup['name'],
            'normalized_name' => $normalizedName,
            'conflict_type' => $type,
            'source_items' => $sourceItems,
            'current_items' => $currentExamIds,
            'missing_external_codes' => $missingCodes,
            'added_exam_ids' => $sourceExamIds->diff($currentExamIds)->values()->all(),
            'removed_exam_ids' => collect($currentExamIds)->diff($sourceExamIds)->values()->all(),
            'status' => 'pending',
        ]);
    }
}
