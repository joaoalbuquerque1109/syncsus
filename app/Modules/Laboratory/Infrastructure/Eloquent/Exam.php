<?php

declare(strict_types=1);

namespace App\Modules\Laboratory\Infrastructure\Eloquent;

use App\Modules\Administration\Infrastructure\Eloquent\Organization;
use App\Support\Models\HasPublicId;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Exam extends Model
{
    use HasPublicId;

    protected $guarded = [];

    protected $attributes = [
        'is_active' => true,
    ];

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return HasMany<ExamMapping, $this> */
    public function mappings(): HasMany
    {
        return $this->hasMany(ExamMapping::class);
    }

    /** @return HasMany<HealthUnitExam, $this> */
    public function healthUnitExams(): HasMany
    {
        return $this->hasMany(HealthUnitExam::class);
    }

    /** @return HasMany<ExamGroupItem, $this> */
    public function groupItems(): HasMany
    {
        return $this->hasMany(ExamGroupItem::class);
    }

    /**
     * @param  Builder<Exam>  $query
     * @return Builder<Exam>
     */
    public function scopeForOrganization(Builder $query, int $organizationId): Builder
    {
        return $query->where('organization_id', $organizationId);
    }

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }
}
