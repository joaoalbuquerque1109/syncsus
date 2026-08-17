<?php

declare(strict_types=1);

namespace App\Modules\Laboratory\Infrastructure\Eloquent;

use App\Modules\Administration\Infrastructure\Eloquent\Organization;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Support\Models\HasPublicId;
use App\Support\Models\TenantModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property list<array{external_code: string, display_order: int, exam_id: int|null}> $source_items
 * @property list<int>|null $current_items
 * @property list<string>|null $missing_external_codes
 * @property list<int>|null $added_exam_ids
 * @property list<int>|null $removed_exam_ids
 */
final class ExamGroupImportConflict extends TenantModel
{
    use HasPublicId;

    protected $guarded = [];

    protected $attributes = [
        'status' => 'pending',
    ];

    public function resolveOrganization(): ?Organization
    {
        return $this->resolveCoreReference(Organization::class, 'organization_public_id', 'organization_id');
    }

    /** @return BelongsTo<LaboratoryIntegration, $this> */
    public function integration(): BelongsTo
    {
        return $this->belongsTo(LaboratoryIntegration::class, 'laboratory_integration_id');
    }

    public function resolveGroup(): ?ExamGroup
    {
        return $this->exam_group_id === null ? null : ExamGroup::query()->find($this->exam_group_id);
    }

    public function resolveResolvedBy(): ?User
    {
        return User::query()->find($this->resolved_by);
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
