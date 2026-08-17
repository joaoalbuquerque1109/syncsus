<?php

declare(strict_types=1);

namespace App\Modules\Laboratory\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Administration\Infrastructure\Eloquent\HealthUnit;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Laboratory\Application\Actions\SaveExamGroupAction;
use App\Modules\Laboratory\Infrastructure\Eloquent\Exam;
use App\Modules\Laboratory\Infrastructure\Eloquent\ExamGroup;
use App\Modules\Laboratory\Presentation\Http\Requests\SaveExamGroupRequest;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class ExamGroupManagementController extends Controller
{
    public function index(Request $request): View
    {
        $unit = $this->unit($request);
        $this->authorizeActor($request, $unit);
        $editingGroup = $this->editingGroup($request, $unit);

        return view('administration.exam-groups.index', [
            'groups' => ExamGroup::query()
                ->with('items.exam')
                ->where('organization_id', $unit->organization_id)
                ->orderByDesc('is_active')
                ->orderBy('name')
                ->paginate(20)
                ->withQueryString(),
            'editingGroup' => $editingGroup,
            'formItems' => $this->formItems($request, $unit, $editingGroup),
        ]);
    }

    public function store(
        SaveExamGroupRequest $request,
        SaveExamGroupAction $action,
    ): RedirectResponse {
        $unit = $this->unit($request);
        $actor = $this->actor($request);
        $data = $request->validated();
        $data['is_active'] = $request->boolean('is_active');
        $action->execute($unit, $actor, $data);

        return redirect()->route('administration.exam-groups.index')
            ->with('success', 'Grupo de exames cadastrado.');
    }

    public function update(
        SaveExamGroupRequest $request,
        ExamGroup $examGroup,
        SaveExamGroupAction $action,
    ): RedirectResponse {
        $unit = $this->unit($request);
        abort_unless((int) $examGroup->organization_id === (int) $unit->organization_id, 404);
        $actor = $this->actor($request);
        $data = $request->validated();
        $data['is_active'] = $request->boolean('is_active');
        $action->execute($unit, $actor, $data, $examGroup);

        return redirect()->route('administration.exam-groups.index')
            ->with('success', 'Grupo de exames atualizado.');
    }

    public function searchExams(Request $request): JsonResponse
    {
        $unit = $this->unit($request);
        $this->authorizeActor($request, $unit);
        $data = $request->validate(['q' => ['required', 'string', 'min:2', 'max:80']]);
        $term = trim(str_replace(['%', '_'], '', (string) $data['q']));
        if (mb_strlen($term) < 2) {
            return response()->json(['data' => []]);
        }

        $code = mb_strtoupper($term);
        $exams = $this->examQuery($unit)
            ->where('exams.is_active', true)
            ->where(function ($query) use ($code, $term): void {
                $query->where('exams.sus_procedure_code', 'like', $code.'%')
                    ->orWhere('exams.name', 'like', '%'.$term.'%')
                    ->orWhere('sus_procedures.description', 'like', '%'.$term.'%');
            })
            ->orderByRaw(
                'CASE WHEN exams.sus_procedure_code = ? THEN 0 WHEN exams.sus_procedure_code LIKE ? THEN 1 ELSE 2 END',
                [$code, $code.'%'],
            )
            ->orderBy('exams.name')
            ->limit(20)
            ->get()
            ->map(fn (Exam $exam): array => $this->examPayload($exam));

        return response()->json(['data' => $exams]);
    }

    private function editingGroup(Request $request, HealthUnit $unit): ?ExamGroup
    {
        $publicId = trim((string) $request->query('edit'));
        if ($publicId === '') {
            return null;
        }

        return ExamGroup::query()
            ->with('items.exam')
            ->where('organization_id', $unit->organization_id)
            ->where('public_id', $publicId)
            ->firstOrFail();
    }

    /** @return list<array{id: int, label: string}> */
    private function formItems(Request $request, HealthUnit $unit, ?ExamGroup $editingGroup): array
    {
        $oldItems = $request->old('items');
        $examIds = is_array($oldItems)
            ? collect($oldItems)->pluck('exam_id')->map(fn (mixed $id): int => (int) $id)->filter()->values()->all()
            : ($editingGroup?->items->pluck('exam_id')->map(fn (mixed $id): int => (int) $id)->all() ?? []);

        if ($examIds === []) {
            return [];
        }

        $exams = $this->examQuery($unit)
            ->whereIn('exams.id', $examIds)
            ->get()
            ->keyBy(fn (Exam $exam): int => (int) $exam->getKey());

        return collect($examIds)
            ->map(function (int $examId) use ($exams): ?array {
                $exam = $exams->get($examId);

                return $exam instanceof Exam ? $this->examPayload($exam) : null;
            })
            ->filter()
            ->map(fn (array $exam): array => ['id' => $exam['id'], 'label' => $exam['label']])
            ->values()
            ->all();
    }

    /** @return Builder<Exam> */
    private function examQuery(HealthUnit $unit): Builder
    {
        return Exam::query()
            ->leftJoin('sus_procedures', function (JoinClause $join): void {
                $join->on('sus_procedures.code', '=', 'exams.sus_procedure_code')
                    ->where('sus_procedures.is_active', true);
            })
            ->where('exams.organization_id', $unit->organization_id)
            ->select([
                'exams.id',
                'exams.name',
                'exams.sus_procedure_code',
                'sus_procedures.description as sus_description',
            ]);
    }

    /** @return array{id: int, name: string, procedure_code: ?string, sus_description: ?string, label: string} */
    private function examPayload(Exam $exam): array
    {
        $procedureCode = filled($exam->sus_procedure_code) ? (string) $exam->sus_procedure_code : null;
        $susDescription = filled($exam->getAttribute('sus_description'))
            ? (string) $exam->getAttribute('sus_description')
            : null;
        $details = collect([
            $procedureCode !== null ? 'SUS '.$procedureCode : null,
            $susDescription,
        ])->filter()->join(' · ');

        return [
            'id' => (int) $exam->getKey(),
            'name' => (string) $exam->name,
            'procedure_code' => $procedureCode,
            'sus_description' => $susDescription,
            'label' => (string) $exam->name.($details !== '' ? ' — '.$details : ''),
        ];
    }

    private function authorizeActor(Request $request, HealthUnit $unit): void
    {
        $actor = $this->actor($request);
        $sameOrganization = (int) $actor->organization_id === (int) $unit->organization_id;
        abort_unless(
            $actor->is_active
            && ($actor->isPlatformAdministrator() || ($sameOrganization && $actor->can('administration.manage'))),
            403,
        );
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        return $actor;
    }

    private function unit(Request $request): HealthUnit
    {
        $unit = $request->attributes->get('active_health_unit');
        abort_unless($unit instanceof HealthUnit, 403);

        return $unit;
    }
}
