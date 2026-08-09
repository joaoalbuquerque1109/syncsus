<?php

declare(strict_types=1);

namespace App\Modules\Administration\Infrastructure\Eloquent;

use App\Support\Models\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Room extends TenantModel
{
    protected $guarded = [];

    /** @return BelongsTo<Department, $this> */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /** @return HasMany<ServicePoint, $this> */
    public function servicePoints(): HasMany
    {
        return $this->hasMany(ServicePoint::class);
    }

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
