<?php

declare(strict_types=1);

namespace App\Modules\Patients\Infrastructure\Eloquent;

use App\Support\Models\CoreModel;
use App\Support\Models\HasPublicId;

final class PatientUnitMigrationConflict extends CoreModel
{
    use HasPublicId;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'candidate_health_unit_public_ids' => 'array',
            'resolved_at' => 'immutable_datetime',
        ];
    }
}
