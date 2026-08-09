<?php

declare(strict_types=1);

namespace App\Modules\Laboratory\Infrastructure\Eloquent;

use App\Support\Models\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class LaboratoryMaterial extends TenantModel
{
    protected $guarded = [];

    /** @return BelongsTo<LaboratoryIntegration, $this> */
    public function integration(): BelongsTo
    {
        return $this->belongsTo(LaboratoryIntegration::class, 'laboratory_integration_id');
    }

    /** @return HasMany<LaboratoryExam, $this> */
    public function exams(): HasMany
    {
        return $this->hasMany(LaboratoryExam::class);
    }

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'source_updated_at' => 'immutable_datetime',
        ];
    }
}
