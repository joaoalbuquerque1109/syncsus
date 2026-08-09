<?php

declare(strict_types=1);

namespace App\Modules\Laboratory\Infrastructure\Eloquent;

use App\Modules\Administration\Infrastructure\Eloquent\HealthUnit;
use App\Modules\Medical\Infrastructure\Eloquent\ExamOrderItem;
use App\Support\Models\HasPublicId;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class LaboratoryExam extends Model
{
    use HasPublicId;

    protected $guarded = [];

    /** @return BelongsTo<LaboratoryIntegration, $this> */
    public function integration(): BelongsTo
    {
        return $this->belongsTo(LaboratoryIntegration::class, 'laboratory_integration_id');
    }

    /** @return BelongsTo<LaboratoryMaterial, $this> */
    public function material(): BelongsTo
    {
        return $this->belongsTo(LaboratoryMaterial::class, 'laboratory_material_id');
    }

    /** @return HasMany<LaboratoryExamComponent, $this> */
    public function components(): HasMany
    {
        return $this->hasMany(LaboratoryExamComponent::class)->orderBy('display_order');
    }

    /** @return HasMany<ExamOrderItem, $this> */
    public function orderItems(): HasMany
    {
        return $this->hasMany(ExamOrderItem::class);
    }

    /**
     * @param  Builder<LaboratoryExam>  $query
     * @return Builder<LaboratoryExam>
     */
    public function scopeAvailableForHealthUnit(Builder $query, HealthUnit $unit): Builder
    {
        return $query
            ->where('laboratory_exams.is_active', true)
            ->whereHas('integration', fn (Builder $integration) => $integration
                ->where('organization_id', $unit->organization_id)
                ->where('health_unit_id', $unit->getKey())
                ->where('is_active', true))
            ->whereExists(function ($mapping) use ($unit): void {
                $mapping->selectRaw('1')
                    ->from('exam_mappings')
                    ->join('exams', 'exams.id', '=', 'exam_mappings.exam_id')
                    ->join('health_unit_exams', function ($join): void {
                        $join->on('health_unit_exams.exam_id', '=', 'exam_mappings.exam_id');
                    })
                    ->whereColumn(
                        'exam_mappings.laboratory_integration_id',
                        'laboratory_exams.laboratory_integration_id',
                    )
                    ->whereColumn('exam_mappings.external_code', 'laboratory_exams.external_code')
                    ->where('exams.organization_id', $unit->organization_id)
                    ->where('exams.is_active', true)
                    ->where('exam_mappings.is_active', true)
                    ->where('health_unit_exams.health_unit_id', $unit->getKey())
                    ->where('health_unit_exams.is_enabled', true);
            });
    }

    protected function casts(): array
    {
        return [
            'synonyms' => 'array',
            'is_active' => 'boolean',
            'source_updated_at' => 'immutable_datetime',
        ];
    }
}
