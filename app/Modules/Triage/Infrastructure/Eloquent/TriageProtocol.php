<?php

declare(strict_types=1);

namespace App\Modules\Triage\Infrastructure\Eloquent;

use App\Support\Models\TenantModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class TriageProtocol extends TenantModel
{
    protected $guarded = [];

    /** @return HasMany<TriageFlowchart, $this> */
    public function flowcharts(): HasMany
    {
        return $this->hasMany(TriageFlowchart::class);
    }

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
