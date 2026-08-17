<?php

declare(strict_types=1);

namespace App\Modules\Administration\Infrastructure\Eloquent;

use App\Support\Models\TenantModel;

final class ArrivalMethod extends TenantModel
{
    protected $guarded = [];

    public function resolveOrganization(): ?Organization
    {
        return $this->resolveCoreReference(Organization::class, 'organization_public_id', 'organization_id');
    }

    public function getOrganizationAttribute(): ?Organization
    {
        return $this->relationLoaded('organization') ? $this->getRelation('organization') : $this->resolveOrganization();
    }

    protected function casts(): array
    {
        return ['requires_vehicle_data' => 'boolean', 'is_active' => 'boolean'];
    }
}
