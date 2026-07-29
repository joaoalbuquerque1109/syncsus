<?php

declare(strict_types=1);

namespace App\Modules\Administration\Infrastructure\Eloquent;

use App\Modules\Queues\Infrastructure\Eloquent\Queue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class EntryType extends Model
{
    protected $guarded = [];

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<Queue, $this> */
    public function defaultQueue(): BelongsTo
    {
        return $this->belongsTo(Queue::class, 'default_queue_id');
    }

    protected function casts(): array
    {
        return [
            'requires_triage' => 'boolean',
            'allows_provisional_patient' => 'boolean',
            'is_active' => 'boolean',
        ];
    }
}
