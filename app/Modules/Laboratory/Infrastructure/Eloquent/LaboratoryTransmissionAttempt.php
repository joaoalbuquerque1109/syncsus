<?php

declare(strict_types=1);

namespace App\Modules\Laboratory\Infrastructure\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class LaboratoryTransmissionAttempt extends Model
{
    protected $guarded = [];

    /** @return BelongsTo<LaboratoryOrderTransmission, $this> */
    public function transmission(): BelongsTo
    {
        return $this->belongsTo(LaboratoryOrderTransmission::class, 'laboratory_order_transmission_id');
    }

    protected function casts(): array
    {
        return [
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
            'response_payload' => 'encrypted:array',
        ];
    }
}
