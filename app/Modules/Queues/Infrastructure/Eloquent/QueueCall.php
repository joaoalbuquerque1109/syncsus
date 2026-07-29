<?php

declare(strict_types=1);

namespace App\Modules\Queues\Infrastructure\Eloquent;

use App\Modules\Administration\Infrastructure\Eloquent\ServicePoint;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Queues\Domain\Enums\QueueCallType;
use App\Support\Models\HasPublicId;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class QueueCall extends Model
{
    use HasPublicId;

    protected $guarded = [];

    /** @return BelongsTo<QueueEntry, $this> */
    public function entry(): BelongsTo
    {
        return $this->belongsTo(QueueEntry::class, 'queue_entry_id');
    }

    /** @return BelongsTo<Queue, $this> */
    public function queue(): BelongsTo
    {
        return $this->belongsTo(Queue::class);
    }

    /** @return BelongsTo<ServicePoint, $this> */
    public function servicePoint(): BelongsTo
    {
        return $this->belongsTo(ServicePoint::class);
    }

    /** @return BelongsTo<User, $this> */
    public function caller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'called_by');
    }

    public function callTypeEnum(): QueueCallType
    {
        $type = $this->getAttribute('call_type');

        return $type instanceof QueueCallType ? $type : QueueCallType::from((string) $type);
    }

    public function calledAt(): CarbonImmutable
    {
        $calledAt = $this->getAttribute('called_at');

        return $calledAt instanceof CarbonImmutable ? $calledAt : CarbonImmutable::parse((string) $calledAt);
    }

    protected function casts(): array
    {
        return ['call_type' => QueueCallType::class, 'called_at' => 'immutable_datetime'];
    }
}
