<?php

declare(strict_types=1);

namespace App\Modules\Queues\Infrastructure\Eloquent;

use Illuminate\Database\Eloquent\Model;

final class QueueSequence extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['sequence_date' => 'immutable_date'];
    }
}
