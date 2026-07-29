<?php

declare(strict_types=1);

namespace App\Modules\Patients\Infrastructure\Eloquent;

use App\Modules\Identity\Infrastructure\Eloquent\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class PatientSocialHistory extends Model
{
    protected $guarded = [];

    /** @return BelongsTo<Patient, $this> */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    /** @return BelongsTo<User, $this> */
    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    protected function casts(): array
    {
        return ['recorded_at' => 'immutable_datetime'];
    }
}
