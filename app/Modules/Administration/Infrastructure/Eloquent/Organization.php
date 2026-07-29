<?php

declare(strict_types=1);

namespace App\Modules\Administration\Infrastructure\Eloquent;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Organization extends Model
{
    use HasUlids;

    protected $guarded = [];

    /** @return HasMany<HealthUnit, $this> */
    public function healthUnits(): HasMany
    {
        return $this->hasMany(HealthUnit::class);
    }

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
