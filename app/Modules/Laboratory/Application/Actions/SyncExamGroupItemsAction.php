<?php

declare(strict_types=1);

namespace App\Modules\Laboratory\Application\Actions;

use App\Modules\Laboratory\Infrastructure\Eloquent\ExamGroup;

final readonly class SyncExamGroupItemsAction
{
    /**
     * @param  list<int>  $examIds
     * @param  array<int, int>  $displayOrders
     */
    public function execute(
        ExamGroup $group,
        array $examIds,
        array $displayOrders = [],
        bool $preserveExisting = false,
    ): void {
        $examIds = array_values(array_unique($examIds));
        if (! $preserveExisting) {
            $group->items()->whereNotIn('exam_id', $examIds)->delete();
        }

        foreach ($examIds as $index => $examId) {
            $group->items()->updateOrCreate(['exam_id' => $examId], [
                'display_order' => $displayOrders[$examId] ?? $index,
            ]);
        }
    }
}
