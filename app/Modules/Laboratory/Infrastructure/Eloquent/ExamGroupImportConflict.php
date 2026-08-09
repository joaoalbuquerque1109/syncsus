<?php

declare(strict_types=1);

namespace App\Modules\Laboratory\Infrastructure\Eloquent;

use App\Modules\Administration\Infrastructure\Eloquent\Organization;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Support\Models\HasPublicId;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property list<array{external_code: string, display_order: int, exam_id: int|null}> $source_items
 * @property list<int>|null $current_items
 * @property list<string>|null $missing_external_codes
 * @property list<int>|null $added_exam_ids
 * @property list<int>|null $removed_exam_ids
 */
final class ExamGroupImportConflict extends Model
{
    use HasPublicId;

    protected $guarded = [];

    protected $attributes = [
        'status' => 'pending',
    ];

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<LaboratoryIntegration, $this> */
    public function integration(): BelongsTo
    {
        return $this->belongsTo(LaboratoryIntegration::class, 'laboratory_integration_id');
    }

    /** @return BelongsTo<ExamGroup, $this> */
    public function group(): BelongsTo
    {
        return $this->belongsTo(ExamGroup::class, 'exam_group_id');
    }

    /** @return BelongsTo<User, $this> */
    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    /**
     * @param  Builder<ExamGroupImportConflict>  $query
     * @return Builder<ExamGroupImportConflict>
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', 'pending');
    }

    protected function casts(): array
    {
        return [
            'source_items' => 'array',
            'current_items' => 'array',
            'missing_external_codes' => 'array',
            'added_exam_ids' => 'array',
            'removed_exam_ids' => 'array',
            'resolved_at' => 'immutable_datetime',
        ];
    }
}
