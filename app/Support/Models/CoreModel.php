<?php

declare(strict_types=1);

namespace App\Support\Models;

use Illuminate\Database\Eloquent\Model;

abstract class CoreModel extends Model
{
    use UsesCoreConnection;
}
