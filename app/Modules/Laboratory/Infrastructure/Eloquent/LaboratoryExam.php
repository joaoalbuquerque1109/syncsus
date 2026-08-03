<?php

declare(strict_types=1);

namespace App\Modules\Laboratory\Infrastructure\Eloquent;

use App\Modules\Medical\Infrastructure\Eloquent\ExamOrderItem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class LaboratoryExam extends Model
{
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

    protected function casts(): array
    {
        return [
            'synonyms' => 'array',
            'is_active' => 'boolean',
            'source_updated_at' => 'immutable_datetime',
        ];
    }
}
