<?php

declare(strict_types=1);

namespace App\Modules\Triage\Infrastructure\Eloquent;

use App\Support\Models\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class TriageFlowchart extends TenantModel
{
    protected $guarded = [];

    /** @return BelongsTo<TriageProtocol, $this> */
    public function protocol(): BelongsTo
    {
        return $this->belongsTo(TriageProtocol::class, 'triage_protocol_id');
    }

    /** @return HasMany<TriageDiscriminator, $this> */
    public function discriminators(): HasMany
    {
        return $this->hasMany(TriageDiscriminator::class);
    }

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
