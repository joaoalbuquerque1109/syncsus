<?php

declare(strict_types=1);

namespace App\Modules\Queues\Infrastructure\Eloquent;

use App\Modules\Administration\Infrastructure\Eloquent\Department;
use App\Modules\Administration\Infrastructure\Eloquent\HealthUnit;
use App\Modules\Administration\Infrastructure\Eloquent\ServicePoint;
use App\Modules\Administration\Infrastructure\Eloquent\Specialty;
use App\Modules\Professionals\Infrastructure\Eloquent\HealthProfessional;
use App\Support\Models\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Queue extends Model
{
    use HasPublicId;

    protected $guarded = [];

    /** @return BelongsTo<HealthUnit, $this> */
    public function healthUnit(): BelongsTo
    {
        return $this->belongsTo(HealthUnit::class);
    }

    /** @return BelongsTo<Department, $this> */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /** @return BelongsTo<Specialty, $this> */
    public function specialty(): BelongsTo
    {
        return $this->belongsTo(Specialty::class);
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

    /** @return BelongsToMany<HealthProfessional, $this> */
    public function professionals(): BelongsToMany
    {
        return $this->belongsToMany(HealthProfessional::class, 'health_professional_queue')
            ->withTimestamps();
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
