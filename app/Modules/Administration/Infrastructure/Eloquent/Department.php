<?php

declare(strict_types=1);

namespace App\Modules\Administration\Infrastructure\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Department extends Model
{
    protected $guarded = [];

    /** @return BelongsTo<HealthUnit, $this> */
    public function healthUnit(): BelongsTo
    {
        return $this->belongsTo(HealthUnit::class);
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
