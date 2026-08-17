<?php

declare(strict_types=1);

namespace App\Modules\Laboratory\Infrastructure\Eloquent;

use App\Modules\Administration\Infrastructure\Eloquent\Organization;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Laboratory\Domain\Enums\ExamCatalogMatchStatus;
use App\Support\Models\HasPublicId;
use App\Support\Models\TenantModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property ExamCatalogMatchStatus $match_status
 * @property string|null $resolution
 * @property string $source_hash
 * @property int|null $suggested_exam_id
 */
final class ExamCatalogImportCandidate extends TenantModel
{
    use HasPublicId;

    protected $guarded = [];

    public function resolveOrganization(): ?Organization
    {
        return $this->resolveCoreReference(Organization::class, 'organization_public_id', 'organization_id');
    }

    /** @return BelongsTo<LaboratoryIntegration, $this> */
    public function integration(): BelongsTo
    {
        return $this->belongsTo(LaboratoryIntegration::class, 'laboratory_integration_id');
    }

    /** @return BelongsTo<LaboratoryExam, $this> */
    public function laboratoryExam(): BelongsTo
    {
        return $this->belongsTo(LaboratoryExam::class);
    }

    public function resolveSuggestedExam(): ?Exam
    {
        return $this->resolveCoreReference(Exam::class, 'suggested_exam_public_id', 'suggested_exam_id');
    }

    /** @return BelongsTo<ExamMapping, $this> */
    public function existingMapping(): BelongsTo
    {
        return $this->belongsTo(ExamMapping::class, 'existing_mapping_id');
    }

    public function resolveResolvedBy(): ?User
    {
        return User::query()->find($this->resolved_by);
    }

    /**
     * @param  Builder<ExamCatalogImportCandidate>  $query
     * @return Builder<ExamCatalogImportCandidate>
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->whereNull('resolution');
    }

    protected function casts(): array
    {
        return [
            'match_status' => ExamCatalogMatchStatus::class,
            'match_confidence' => 'decimal:4',
            'resolved_at' => 'immutable_datetime',
        ];
    }
}
