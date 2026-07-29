<?php

declare(strict_types=1);

namespace App\Modules\Patients\Infrastructure\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class PatientAllergy extends Model
{
    protected $guarded = [];

    /** @return BelongsTo<Patient, $this> */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    protected function casts(): array
    {
        return ['recorded_at' => 'immutable_datetime'];
    }
}
