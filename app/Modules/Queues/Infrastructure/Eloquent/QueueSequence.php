<?php

declare(strict_types=1);

namespace App\Modules\Queues\Infrastructure\Eloquent;

use App\Support\Models\TenantModel;

final class QueueSequence extends TenantModel
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['sequence_date' => 'immutable_date'];
    }
}
