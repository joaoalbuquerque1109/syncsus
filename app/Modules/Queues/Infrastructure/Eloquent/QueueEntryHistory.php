<?php

declare(strict_types=1);

namespace App\Modules\Queues\Infrastructure\Eloquent;

use App\Modules\Administration\Infrastructure\Eloquent\ServicePoint;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Queues\Domain\Enums\QueueEntryStatus;
use App\Support\Models\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class QueueEntryHistory extends TenantModel
{
    public $timestamps = false;

    protected $table = 'queue_entry_history';

    protected $guarded = [];

    /** @return BelongsTo<QueueEntry, $this> */
    public function entry(): BelongsTo
    {
        return $this->belongsTo(QueueEntry::class, 'queue_entry_id');
    }

    /** @return BelongsTo<ServicePoint, $this> */
    public function servicePoint(): BelongsTo
    {
        return $this->belongsTo(ServicePoint::class);
    }

    public function resolvePerformer(): ?User
    {
        return User::query()->find($this->performed_by);
    }

    protected function casts(): array
    {
        return [
            'from_status' => QueueEntryStatus::class,
            'to_status' => QueueEntryStatus::class,
            'metadata' => 'array',
            'occurred_at' => 'immutable_datetime',
        ];
    }
}
