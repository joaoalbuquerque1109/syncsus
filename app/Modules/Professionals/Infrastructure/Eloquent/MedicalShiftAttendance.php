<?php

declare(strict_types=1);

namespace App\Modules\Professionals\Infrastructure\Eloquent;

use App\Modules\Administration\Infrastructure\Eloquent\HealthUnit;
use App\Modules\Administration\Infrastructure\Eloquent\Organization;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Support\Models\HasPublicId;
use App\Support\Models\TenantModel;

final class MedicalShiftAttendance extends TenantModel
{
    use HasPublicId;

    protected $guarded = [];

    public function resolveOrganization(): ?Organization
    {
        return $this->resolveCoreReference(Organization::class, 'organization_public_id', 'organization_id');
    }

    public function resolveHealthUnit(): ?HealthUnit
    {
        return $this->resolveCoreReference(HealthUnit::class, 'health_unit_public_id', 'health_unit_id');
    }

    public function resolveUser(): ?User
    {
        return User::query()->find($this->user_id);
    }

    protected function casts(): array
    {
        return [
            'duty_date' => 'immutable_date',
            'checked_in_at' => 'immutable_datetime',
            'checked_out_at' => 'immutable_datetime',
        ];
    }
}
