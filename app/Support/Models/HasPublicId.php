<?php

declare(strict_types=1);

namespace App\Support\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;

trait HasPublicId
{
    use HasUlids;

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }
}
