<?php

declare(strict_types=1);

namespace App\Modules\Medical\Infrastructure\Eloquent;

use App\Support\Models\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class PrescriptionItem extends TenantModel
{
    protected $guarded = [];

    /** @return BelongsTo<Prescription, $this> */
    public function prescription(): BelongsTo
    {
        return $this->belongsTo(Prescription::class);
    }

    protected function casts(): array
    {
        return [
            'is_immediate' => 'boolean',
            'is_as_needed' => 'boolean',
        ];
    }
}
