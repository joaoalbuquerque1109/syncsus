<?php

declare(strict_types=1);

namespace App\Modules\Laboratory\Application\Actions;

use App\Modules\Administration\Infrastructure\Eloquent\HealthUnit;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Laboratory\Application\Services\ExamNameNormalizer;
use App\Modules\Laboratory\Infrastructure\Eloquent\Exam;
use App\Modules\Laboratory\Infrastructure\Eloquent\ExamGroup;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class SaveExamGroupAction
{
    public function __construct(
        private ExamNameNormalizer $normalizer,
        private SyncExamGroupItemsAction $syncItems,
        private RecordLaboratoryCatalogAuditAction $audit,
    ) {}

    /** @param array{name: string, items: list<array{exam_id: int}>, is_active: bool} $data */
    public function execute(
        HealthUnit $unit,
        User $actor,
        array $data,
        ?ExamGroup $group = null,
    ): ExamGroup {
        $this->authorize($unit, $actor, $group);

        return DB::transaction(function () use ($unit, $actor, $data, $group): ExamGroup {
            if ($group !== null) {
                $group = ExamGroup::query()->lockForUpdate()->findOrFail($group->getKey());
            }

            $examIds = collect($data['items'])
                ->pluck('exam_id')
                ->map(fn (mixed $id): int => (int) $id)
                ->unique()
                ->values()
                ->all();
            if ($examIds === [] || count($examIds) !== count($data['items']) || Exam::query()
                ->where('organization_id', $unit->organization_id)
                ->whereIn('id', $examIds)
                ->count() !== count($examIds)) {
                throw ValidationException::withMessages([
                    'items' => 'Todos os exames devem pertencer à organização ativa e não podem se repetir.',
                ]);
            }

            $normalizedName = $this->normalizer->normalize($data['name']);
            $duplicate = ExamGroup::query()
                ->where('organization_id', $unit->organization_id)
                ->where('normalized_name', $normalizedName)
                ->when($group !== null, fn ($query) => $query->where('id', '!=', $group->getKey()))
                ->exists();
            if ($duplicate) {
                throw ValidationException::withMessages([
                    'name' => 'Já existe um grupo de exames com este nome nesta organização.',
                ]);
            }

            $group ??= new ExamGroup;
            $before = $group->exists
                ? $group->items()->with('exam:id,public_id')->get()->pluck('exam.public_id')->filter()->values()->all()
                : [];
            $group->fill([
                'organization_id' => $unit->organization_id,
                'name' => trim($data['name']),
                'normalized_name' => $normalizedName,
                'is_active' => $data['is_active'],
            ]);
            try {
                $group->save();
            } catch (QueryException $exception) {
                if (! $this->isDuplicateNameViolation($exception)) {
                    throw $exception;
                }
                throw ValidationException::withMessages([
                    'name' => 'Já existe um grupo de exames com este nome nesta organização.',
                ]);
            }

            $displayOrders = [];
            foreach ($examIds as $displayOrder => $examId) {
                $displayOrders[$examId] = $displayOrder;
            }
            $this->syncItems->execute($group, $examIds, $displayOrders);
            $group->load('items.exam');
            $after = $group->items()
                ->with('exam:id,public_id')
                ->get()
                ->pluck('exam.public_id')
                ->filter()
                ->values()
                ->all();
            $this->audit->execute(
                'laboratory.exam_group_saved',
                $actor,
                (int) $unit->getKey(),
                [
                    'source' => 'web',
                    'group' => $group->public_id,
                    'name' => $group->name,
                    'is_active' => $group->is_active,
                    'before_items' => $before,
                    'after_items' => $after,
                ],
            );

            return $group;
        });
    }

    private function authorize(HealthUnit $unit, User $actor, ?ExamGroup $group): void
    {
        $sameOrganization = (int) $actor->organization_id === (int) $unit->organization_id;
        $groupBelongsToOrganization = $group === null
            || (int) $group->organization_id === (int) $unit->organization_id;
        if (! $actor->is_active
            || ! $groupBelongsToOrganization
            || (! $actor->isPlatformAdministrator() && (! $sameOrganization || ! $actor->can('administration.manage')))) {
            throw new AuthorizationException('Usuário sem permissão para salvar grupos desta organização.');
        }
    }

    private function isDuplicateNameViolation(QueryException $exception): bool
    {
        $message = mb_strtolower($exception->getMessage());

        return str_contains($message, 'exam_group_organization_name_unique')
            || (str_contains($message, 'exam_groups.organization_id')
                && str_contains($message, 'exam_groups.normalized_name'));
    }
}
