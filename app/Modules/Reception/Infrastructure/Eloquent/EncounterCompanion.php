<?php

declare(strict_types=1);

namespace App\Modules\Reception\Infrastructure\Eloquent;

use App\Support\Models\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class EncounterCompanion extends TenantModel
{
    protected $guarded = [];

    /** @return BelongsTo<Encounter, $this> */
    public function encounter(): BelongsTo
    {
        return $this->belongsTo(Encounter::class);
    }

    protected function casts(): array
    {
        return ['is_legal_guardian' => 'boolean', 'authorized_at' => 'immutable_datetime'];
    }
}
