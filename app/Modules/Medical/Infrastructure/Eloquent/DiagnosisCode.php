<?php

declare(strict_types=1);

namespace App\Modules\Medical\Infrastructure\Eloquent;

use Illuminate\Database\Eloquent\Model;

final class DiagnosisCode extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
