<?php

declare(strict_types=1);

namespace App\Modules\Triage\Infrastructure\Eloquent;

use Illuminate\Database\Eloquent\Model;

final class VitalSignRange extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'hard_min' => 'decimal:2',
            'hard_max' => 'decimal:2',
            'warning_min' => 'decimal:2',
            'warning_max' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }
}
