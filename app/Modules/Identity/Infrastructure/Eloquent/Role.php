<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Eloquent;

use App\Support\Models\UsesCoreConnection;
use Spatie\Permission\Models\Role as SpatieRole;

final class Role extends SpatieRole
{
    use UsesCoreConnection;
}
