<?php

declare(strict_types=1);

namespace App\Modules\Reception\Infrastructure\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ReceptionRecord extends Model
{
    protected $guarded = [];

    /** @return BelongsTo<Encounter, $this> */
    public function encounter(): BelongsTo
    {
        return $this->belongsTo(Encounter::class);
    }
}
