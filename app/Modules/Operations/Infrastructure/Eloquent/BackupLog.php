<?php

declare(strict_types=1);

namespace App\Modules\Operations\Infrastructure\Eloquent;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

final class BackupLog extends Model
{
    use HasUlids;

    protected $guarded = [];

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    protected function casts(): array
    {
        return [
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
        ];
    }
}
