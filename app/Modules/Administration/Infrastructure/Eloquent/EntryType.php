<?php

declare(strict_types=1);

namespace App\Modules\Administration\Infrastructure\Eloquent;

use App\Modules\Queues\Infrastructure\Eloquent\Queue;
use App\Support\Models\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class EntryType extends TenantModel
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

    /** @return BelongsTo<Queue, $this> */
    public function defaultQueue(): BelongsTo
    {
        return $this->belongsTo(Queue::class, 'default_queue_id');
    }

    protected function casts(): array
    {
        return [
            'requires_triage' => 'boolean',
            'allows_provisional_patient' => 'boolean',
            'is_active' => 'boolean',
        ];
    }
}
