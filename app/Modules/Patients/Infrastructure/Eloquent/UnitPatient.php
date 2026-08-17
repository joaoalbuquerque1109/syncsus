<?php

declare(strict_types=1);

namespace App\Modules\Patients\Infrastructure\Eloquent;

use App\Modules\Administration\Infrastructure\Eloquent\HealthUnit;
use App\Support\Models\HasPublicId;
use App\Support\Models\TenantModel;

final class UnitPatient extends TenantModel
{
    use HasPublicId;

    protected $guarded = [];

    public function resolvePatient(): ?Patient
    {
        return Patient::query()->where('public_id', $this->patient_public_id)->first();
    }

    public function resolveHealthUnit(): ?HealthUnit
    {
        return HealthUnit::query()->where('public_id', $this->health_unit_public_id)->first();
    }

    protected function casts(): array
    {
        return [
            'first_seen_at' => 'immutable_datetime',
            'last_seen_at' => 'immutable_datetime',
        ];
    }
}
