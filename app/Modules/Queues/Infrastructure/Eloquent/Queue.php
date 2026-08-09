<?php

declare(strict_types=1);

namespace App\Modules\Queues\Infrastructure\Eloquent;

use App\Modules\Administration\Infrastructure\Eloquent\Department;
use App\Modules\Administration\Infrastructure\Eloquent\HealthUnit;
use App\Modules\Administration\Infrastructure\Eloquent\ServicePoint;
use App\Modules\Administration\Infrastructure\Eloquent\Specialty;
use App\Support\Models\HasPublicId;
use App\Support\Models\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Queue extends TenantModel
{
    use HasPublicId;

    protected $guarded = [];

    public function resolveHealthUnit(): ?HealthUnit
    {
        return $this->resolveCoreReference(HealthUnit::class, 'health_unit_public_id', 'health_unit_id');
    }

    public function getHealthUnitAttribute(): ?HealthUnit
    {
        return $this->relationLoaded('healthUnit') ? $this->getRelation('healthUnit') : $this->resolveHealthUnit();
    }

    /** @return BelongsTo<Department, $this> */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function resolveSpecialty(): ?Specialty
    {
        return $this->resolveCoreReference(Specialty::class, 'specialty_public_id', 'specialty_id');
    }

    public function getSpecialtyAttribute(): ?Specialty
    {
        return $this->relationLoaded('specialty') ? $this->getRelation('specialty') : $this->resolveSpecialty();
    }

    /** @return HasMany<QueueEntry, $this> */
    public function entries(): HasMany
    {
        return $this->hasMany(QueueEntry::class);
    }

    /** @return BelongsToMany<ServicePoint, $this> */
    public function servicePoints(): BelongsToMany
    {
        return $this->belongsToMany(ServicePoint::class)->withTimestamps();
    }

    /** @return HasMany<QueueCall, $this> */
    public function calls(): HasMany
    {
        return $this->hasMany(QueueCall::class);
    }

    /** @return BelongsToMany<Panel, $this> */
    public function panels(): BelongsToMany
    {
        return $this->belongsToMany(Panel::class)->withTimestamps();
    }

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
