<?php

declare(strict_types=1);

namespace App\Modules\Queues\Infrastructure\Eloquent;

use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Reception\Infrastructure\Eloquent\Encounter;
use App\Support\Models\HasPublicId;
use App\Support\Models\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class QueueTransfer extends TenantModel
{
    use HasPublicId;

    protected $guarded = [];

    /** @return BelongsTo<Encounter, $this> */
    public function encounter(): BelongsTo
    {
        return $this->belongsTo(Encounter::class);
    }

    /** @return BelongsTo<QueueEntry, $this> */
    public function sourceEntry(): BelongsTo
    {
        return $this->belongsTo(QueueEntry::class, 'source_entry_id');
    }

    /** @return BelongsTo<QueueEntry, $this> */
    public function destinationEntry(): BelongsTo
    {
        return $this->belongsTo(QueueEntry::class, 'destination_entry_id');
    }

    public function resolveTransferredBy(): ?User
    {
        return User::query()->find($this->transferred_by);
    }

    protected function casts(): array
    {
        return ['priority_preserved' => 'boolean', 'transferred_at' => 'immutable_datetime'];
    }
}
