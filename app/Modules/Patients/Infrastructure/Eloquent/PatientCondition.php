<?php

declare(strict_types=1);

namespace App\Modules\Patients\Infrastructure\Eloquent;

use App\Modules\Patients\Infrastructure\Eloquent\Concerns\BelongsToUnitPatient;
use App\Support\Models\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class PatientCondition extends TenantModel
{
    use BelongsToUnitPatient;

    protected $guarded = [];

    /** @return BelongsTo<Patient, $this> */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    protected function casts(): array
    {
        return ['onset_date' => 'immutable_date'];
    }
}
