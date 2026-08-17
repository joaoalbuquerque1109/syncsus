<?php

declare(strict_types=1);

namespace App\Modules\Reception\Infrastructure\Eloquent;

use App\Modules\Reception\Domain\Enums\EncounterStatus;
use App\Support\Models\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class EncounterStatusHistory extends TenantModel
{
    public $timestamps = false;

    protected $table = 'encounter_status_history';

    protected $guarded = [];

    /** @return BelongsTo<Encounter, $this> */
    public function encounter(): BelongsTo
    {
        return $this->belongsTo(Encounter::class);
    }

    protected function casts(): array
    {
        return [
            'from_status' => EncounterStatus::class,
            'to_status' => EncounterStatus::class,
            'metadata' => 'array',
            'changed_at' => 'immutable_datetime',
        ];
    }
}
