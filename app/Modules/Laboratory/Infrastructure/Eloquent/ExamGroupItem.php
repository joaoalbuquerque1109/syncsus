<?php

declare(strict_types=1);

namespace App\Modules\Laboratory\Infrastructure\Eloquent;

use App\Support\Models\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

final class ExamGroupItem extends Model
{
    use HasPublicId;

    protected $guarded = [];

    protected static function booted(): void
    {
        self::saving(function (ExamGroupItem $item): void {
            $groupOrganizationId = ExamGroup::query()
                ->whereKey($item->exam_group_id)
                ->value('organization_id');
            $examOrganizationId = Exam::query()
                ->whereKey($item->exam_id)
                ->value('organization_id');
            if ($groupOrganizationId !== null
                && $examOrganizationId !== null
                && (string) $groupOrganizationId !== (string) $examOrganizationId) {
                throw new LogicException('O item do grupo deve pertencer à mesma organização do exame.');
            }
        });
    }

    /** @return BelongsTo<ExamGroup, $this> */
    public function group(): BelongsTo
    {
        return $this->belongsTo(ExamGroup::class, 'exam_group_id');
    }

    /** @return BelongsTo<Exam, $this> */
    public function exam(): BelongsTo
    {
        return $this->belongsTo(Exam::class);
    }
}
