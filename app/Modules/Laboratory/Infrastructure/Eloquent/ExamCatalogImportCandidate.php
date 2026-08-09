<?php

declare(strict_types=1);

namespace App\Modules\Laboratory\Infrastructure\Eloquent;

use App\Modules\Administration\Infrastructure\Eloquent\Organization;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Laboratory\Domain\Enums\ExamCatalogMatchStatus;
use App\Support\Models\HasPublicId;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property ExamCatalogMatchStatus $match_status
 * @property string|null $resolution
 * @property string $source_hash
 * @property int|null $suggested_exam_id
 */
final class ExamCatalogImportCandidate extends Model
{
    use HasPublicId;

    protected $guarded = [];

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

    /** @return BelongsTo<LaboratoryExam, $this> */
    public function laboratoryExam(): BelongsTo
    {
        return $this->belongsTo(LaboratoryExam::class);
    }

    /** @return BelongsTo<Exam, $this> */
    public function suggestedExam(): BelongsTo
    {
        return $this->belongsTo(Exam::class, 'suggested_exam_id');
    }

    /** @return BelongsTo<ExamMapping, $this> */
    public function existingMapping(): BelongsTo
    {
        return $this->belongsTo(ExamMapping::class, 'existing_mapping_id');
    }

    /** @return BelongsTo<User, $this> */
    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
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
