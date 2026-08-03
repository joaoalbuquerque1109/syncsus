<?php

declare(strict_types=1);

namespace App\Modules\Medical\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Administration\Infrastructure\Eloquent\HealthUnit;
use App\Modules\Laboratory\Infrastructure\Eloquent\LaboratoryExam;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class LaboratoryExamSearchController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $unit = $request->attributes->get('active_health_unit');
        abort_unless($unit instanceof HealthUnit, 403);
        $data = $request->validate(['q' => ['required', 'string', 'min:2', 'max:80']]);
        $term = trim(str_replace(['%', '_'], '', (string) $data['q']));

        if (mb_strlen($term) < 2) {
            return response()->json(['data' => []]);
        }

        $normalized = mb_strtoupper($term);
        $exams = LaboratoryExam::query()
            ->with('material:id,name,container,cap_color')
            ->select([
                'laboratory_exams.id', 'laboratory_exams.laboratory_integration_id',
                'laboratory_exams.laboratory_material_id', 'laboratory_exams.external_code',
                'laboratory_exams.acronym', 'laboratory_exams.name', 'laboratory_exams.short_name',
                'laboratory_exams.sus_procedure_code', 'laboratory_exams.collection_instructions',
            ])
            ->where('laboratory_exams.is_active', true)
            ->whereHas('integration', fn ($query) => $query
                ->where('organization_id', $unit->organization_id)
                ->where('health_unit_id', $unit->getKey())
                ->where('is_active', true))
            ->where(function ($query) use ($normalized, $term): void {
                $query->where('external_code', 'like', $normalized.'%')
                    ->orWhere('acronym', 'like', $normalized.'%')
                    ->orWhere('sus_procedure_code', 'like', $normalized.'%')
                    ->orWhere('name', 'like', '%'.$term.'%')
                    ->orWhere('short_name', 'like', '%'.$term.'%')
                    ->orWhere('synonyms', 'like', '%'.$term.'%');
            })
            ->orderByRaw(
                'CASE WHEN external_code = ? THEN 0 WHEN acronym = ? THEN 1 WHEN external_code LIKE ? THEN 2 ELSE 3 END',
                [$normalized, $normalized, $normalized.'%'],
            )
            ->orderBy('name')
            ->limit(20)
            ->get()
            ->map(fn (LaboratoryExam $exam): array => [
                'id' => $exam->getKey(),
                'code' => $exam->external_code,
                'procedure_code' => $exam->sus_procedure_code,
                'acronym' => $exam->acronym,
                'name' => $exam->name,
                'label' => trim(
                    ($exam->acronym ? $exam->acronym.' - ' : '')
                    .$exam->name
                    .($exam->sus_procedure_code ? ' - SUS '.$exam->sus_procedure_code : ''),
                ),
                'material' => $exam->material?->name,
                'container' => $exam->material?->container,
                'cap_color' => $exam->material?->cap_color,
                'preparation' => $exam->collection_instructions,
            ]);

        return response()->json(['data' => $exams]);
    }
}
