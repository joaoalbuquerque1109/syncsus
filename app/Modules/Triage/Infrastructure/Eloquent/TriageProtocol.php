<?php

declare(strict_types=1);

namespace App\Modules\Triage\Infrastructure\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class TriageProtocol extends Model
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
