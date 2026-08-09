<?php

declare(strict_types=1);

namespace App\Modules\Administration\Infrastructure\Eloquent;

use App\Support\Models\TenantModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Department extends TenantModel
{
    protected $guarded = [];

    public function resolveHealthUnit(): ?HealthUnit
    {
        return $this->resolveCoreReference(HealthUnit::class, 'health_unit_public_id', 'health_unit_id');
    }

    public function getHealthUnitAttribute(): ?HealthUnit
    {
        return $this->relationLoaded('healthUnit') ? $this->getRelation('healthUnit') : $this->resolveHealthUnit();
    }

    /** @return HasMany<Room, $this> */
    public function rooms(): HasMany
    {
        return $this->hasMany(Room::class);
    }

    protected function casts(): array
    {
        return ['is_clinical' => 'boolean', 'is_active' => 'boolean'];
    }
}
