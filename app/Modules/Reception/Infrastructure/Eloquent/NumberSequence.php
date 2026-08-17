<?php

declare(strict_types=1);

namespace App\Modules\Reception\Infrastructure\Eloquent;

use App\Support\Models\TenantModel;

final class NumberSequence extends TenantModel
{
    protected $guarded = [];
}
