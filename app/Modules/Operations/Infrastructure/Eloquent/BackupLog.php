<?php

declare(strict_types=1);

namespace App\Modules\Operations\Infrastructure\Eloquent;

use App\Support\Models\CoreModel;
use App\Support\Models\HasPublicId;

final class BackupLog extends CoreModel
{
    use HasPublicId;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
            'core_reference_at' => 'immutable_datetime',
        ];
    }
}
