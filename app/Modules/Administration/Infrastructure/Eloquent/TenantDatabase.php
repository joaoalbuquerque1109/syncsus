<?php

declare(strict_types=1);

namespace App\Modules\Administration\Infrastructure\Eloquent;

use App\Support\Models\CoreModel;
use App\Support\Models\HasPublicId;
use App\Support\Tenancy\TenantDatabaseState;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class TenantDatabase extends CoreModel
{
    use HasPublicId;

    protected $guarded = [];

    public function stateEnum(): TenantDatabaseState
    {
        $state = $this->getAttribute('state');

        return $state instanceof TenantDatabaseState ? $state : TenantDatabaseState::from((string) $state);
    }

    public function hasReconciliation(): bool
    {
        return $this->getAttribute('last_reconciled_at') !== null;
    }

    public function lastReconciledAtLabel(): ?string
    {
        $value = $this->getAttribute('last_reconciled_at');

        return $value instanceof \DateTimeInterface ? $value->format('Y-m-d H:i:s') : null;
    }

    public function reconciliationCoversContinuityEvidence(): bool
    {
        $reconciledAt = $this->getAttribute('last_reconciled_at');
        $restoreTestedAt = $this->getAttribute('restore_tested_at');

        return $reconciledAt instanceof \DateTimeInterface
            && $restoreTestedAt instanceof \DateTimeInterface
            && $reconciledAt >= $restoreTestedAt;
    }

    /** @return BelongsTo<HealthUnit, $this> */
    public function healthUnit(): BelongsTo
    {
        return $this->belongsTo(HealthUnit::class);
    }

    /** @return HasMany<TenantDatabaseEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(TenantDatabaseEvent::class);
    }

    protected function casts(): array
    {
        return [
            'state' => TenantDatabaseState::class,
            'last_reconciliation_summary' => 'array',
            'continuity_evidence' => 'array',
            'last_reconciled_at' => 'immutable_datetime',
            'provisioned_at' => 'immutable_datetime',
            'cutover_at' => 'immutable_datetime',
            'tenant_at' => 'immutable_datetime',
            'rollback_at' => 'immutable_datetime',
            'backup_verified_at' => 'immutable_datetime',
            'restore_tested_at' => 'immutable_datetime',
        ];
    }
}
