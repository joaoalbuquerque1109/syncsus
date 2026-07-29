<?php

declare(strict_types=1);

namespace App\Modules\Administration\Infrastructure\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ArrivalMethod extends Model
{
    protected $guarded = [];

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    protected function casts(): array
    {
        return ['requires_vehicle_data' => 'boolean', 'is_active' => 'boolean'];
    }
}
