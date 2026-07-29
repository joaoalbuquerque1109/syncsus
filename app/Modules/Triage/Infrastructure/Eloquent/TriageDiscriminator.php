<?php

declare(strict_types=1);

namespace App\Modules\Triage\Infrastructure\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class TriageDiscriminator extends Model
{
    protected $guarded = [];

    /** @return BelongsTo<TriageFlowchart, $this> */
    public function flowchart(): BelongsTo
    {
        return $this->belongsTo(TriageFlowchart::class, 'triage_flowchart_id');
    }

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
