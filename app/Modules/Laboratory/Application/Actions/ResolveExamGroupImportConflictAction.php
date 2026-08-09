<?php

declare(strict_types=1);

namespace App\Modules\Laboratory\Application\Actions;

use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Laboratory\Application\Services\CatalogReader;
use App\Modules\Laboratory\Infrastructure\Eloquent\ExamGroup;
use App\Modules\Laboratory\Infrastructure\Eloquent\ExamGroupImportConflict;
use App\Modules\Laboratory\Infrastructure\Eloquent\ExamMapping;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

final readonly class ResolveExamGroupImportConflictAction
{
    public function __construct(
        private RecordLaboratoryCatalogAuditAction $audit,
        private SyncExamGroupItemsAction $syncItems,
        private CatalogReader $catalog,
    ) {}

    public function execute(
        ExamGroupImportConflict $conflict,
        string $decision,
        User $actor,
    ): ExamGroupImportConflict {
        return DB::transaction(function () use ($conflict, $decision, $actor): ExamGroupImportConflict {
            $conflict = ExamGroupImportConflict::query()
                ->with('integration')
                ->lockForUpdate()
                ->findOrFail($conflict->getKey());
            $group = $conflict->resolveGroup()?->load('items.exam');
            $conflict->setRelation('group', $group);
            $this->authorize($conflict, $actor);
            if ($conflict->status !== 'pending') {
                throw new LogicException('Este conflito de grupo já foi resolvido.');
            }
            if (! in_array($decision, ['accept', 'ignore', 'merge'], true)) {
                throw new InvalidArgumentException('Decisão inválida para o conflito de grupo.');
            }

            $before = $group?->items->pluck('exam.public_id')->filter()->values()->all() ?? [];
            if ($decision !== 'ignore') {
                [$examIds, $displayOrders] = $this->resolveSourceItems($conflict);
                if ($group === null) {
                    $group = ExamGroup::query()->create([
                        'organization_id' => $conflict->organization_id,
                        'name' => $conflict->external_name,
                        'normalized_name' => $conflict->normalized_name,
                        'is_active' => true,
                    ]);
                }
                if ($decision === 'merge') {
                    $examIds = $group->items()->pluck('exam_id')->merge($examIds)->unique()->values()->all();
                }
                $this->syncItems->execute($group, $examIds, $displayOrders, $decision === 'merge');
            }

            $conflict->forceFill([
                'exam_group_id' => $group?->getKey(),
                'status' => 'resolved',
                'decision' => $decision,
                'resolved_by' => $actor->getKey(),
                'resolved_at' => now(),
            ])->save();
            $group?->load('items.exam');
            $after = $group?->items->pluck('exam.public_id')->filter()->values()->all() ?? $before;
            $this->audit->execute(
                'laboratory.exam_group_import_conflict_resolved',
                $actor,
                (int) $conflict->integration->health_unit_id,
                [
                    'conflict' => $conflict->public_id,
                    'group' => $group?->public_id,
                    'decision' => $decision,
                    'before_items' => $before,
                    'after_items' => $after,
                ],
            );

            $conflict->unsetRelation('group');

            return $conflict->refresh();
        });
    }

    private function authorize(ExamGroupImportConflict $conflict, User $actor): void
    {
        $sameOrganization = (int) $actor->organization_id === (int) $conflict->organization_id;
        if (! $actor->is_active || (! $actor->isPlatformAdministrator() && (! $sameOrganization || ! $actor->can('administration.manage')))) {
            throw new AuthorizationException('Usuário sem permissão para revisar grupos desta organização.');
        }
    }

    /** @return array{0: list<int>, 1: array<int, int>} */
    private function resolveSourceItems(ExamGroupImportConflict $conflict): array
    {
        $sourceItems = collect($conflict->source_items);
        $codes = $sourceItems->pluck('external_code')->unique()->values();
        $mappings = ExamMapping::query()
            ->where('laboratory_integration_id', $conflict->laboratory_integration_id)
            ->where('is_active', true)
            ->whereIn('exam_public_id', $this->catalog
                ->activeExamPublicIdsForOrganization((int) $conflict->organization_id))
            ->whereIn('external_code', $codes)
            ->get()
            ->keyBy('external_code');
        $missing = $codes->reject(fn (string $code): bool => $mappings->has($code))->values()->all();
        if ($missing !== []) {
            throw new LogicException('O grupo ainda possui exames sem mapping ativo: '.implode(', ', $missing));
        }

        $examIds = [];
        $displayOrders = [];
        foreach ($sourceItems as $index => $item) {
            $examId = (int) $mappings->get($item['external_code'])->exam_id;
            if (! in_array($examId, $examIds, true)) {
                $examIds[] = $examId;
                $displayOrders[$examId] = $item['display_order'];
            }
        }

        return [$examIds, $displayOrders];
    }
}
