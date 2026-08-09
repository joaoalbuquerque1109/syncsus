<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use App\Support\Models\CoreModel;

final class PublicLookupIndex extends CoreModel
{
    protected $table = 'public_lookup_index';

    protected $guarded = [];
}
