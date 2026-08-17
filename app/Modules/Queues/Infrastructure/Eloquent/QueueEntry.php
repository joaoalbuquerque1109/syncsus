<?php

declare(strict_types=1);

namespace App\Modules\Queues\Infrastructure\Eloquent;

use App\Modules\Administration\Infrastructure\Eloquent\ServicePoint;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Queues\Domain\Enums\QueueEntryStatus;
use App\Modules\Reception\Infrastructure\Eloquent\Encounter;
use App\Support\Models\HasPublicId;
use App\Support\Models\TenantModel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class QueueEntry extends TenantModel
{
    use HasPublicId;

    protected $guarded = [];

    /** @return BelongsTo<Queue, $this> */
    public function queue(): BelongsTo
    {
        return $this->belongsTo(Queue::class);
    }

    /** @return BelongsTo<Encounter, $this> */
    public function encounter(): BelongsTo
    {
        return $this->belongsTo(Encounter::class);
    }

    /** @return BelongsTo<ServicePoint, $this> */
    public function servicePoint(): BelongsTo
    {
        return $this->belongsTo(ServicePoint::class);
    }

    public function resolveAssignedUser(): ?User
    {
        return $this->assigned_user_id === null ? null : User::query()->find($this->assigned_user_id);
    }

    public function getAssignedUserAttribute(): ?User
    {
        return $this->relationLoaded('assignedUser') ? $this->getRelation('assignedUser') : $this->resolveAssignedUser();
    }

    /** @return HasMany<QueueCall, $this> */
    public function calls(): HasMany
    {
        return $this->hasMany(QueueCall::class);
    }

    /** @return HasMany<QueueEntryHistory, $this> */
    public function history(): HasMany
    {
        return $this->hasMany(QueueEntryHistory::class);
    }

    public function statusEnum(): QueueEntryStatus
    {
        $status = $this->getAttribute('status');

        return $status instanceof QueueEntryStatus ? $status : QueueEntryStatus::from((string) $status);
    }

    public function version(): int
    {
        return (int) $this->getAttribute('lock_version');
    }

    public function enteredAt(): CarbonImmutable
    {
        $enteredAt = $this->getAttribute('entered_at');

        return $enteredAt instanceof CarbonImmutable ? $enteredAt : CarbonImmutable::parse((string) $enteredAt);
    }

    protected function casts(): array
    {
        return [
            'status' => QueueEntryStatus::class,
            'entered_at' => 'immutable_datetime',
            'first_called_at' => 'immutable_datetime',
            'last_called_at' => 'immutable_datetime',
            'service_started_at' => 'immutable_datetime',
            'exited_at' => 'immutable_datetime',
        ];
    }
}
