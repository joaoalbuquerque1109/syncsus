<?php

declare(strict_types=1);

namespace App\Modules\Laboratory\Application\Actions;

use App\Modules\Administration\Infrastructure\Eloquent\HealthUnit;
use App\Modules\Laboratory\Application\Jobs\SubmitLaboratoryOrderJob;
use App\Modules\Laboratory\Domain\Enums\LaboratoryTransmissionStatus;
use App\Modules\Laboratory\Infrastructure\Eloquent\LaboratoryOrderTransmission;
use App\Support\Tenancy\TenantConnectionManager;
use App\Support\Tenancy\TenantContext;

final readonly class DispatchPendingLaboratoryTransmissionsAction
{
    public function __construct(
        private RecoverStaleLaboratoryTransmissionsAction $recoverStale,
        private TenantContext $tenantContext,
        private TenantConnectionManager $connectionManager,
    ) {}

    public function execute(?HealthUnit $unit = null): int
    {
        if (! config('sync_sus.synclab.enabled')) {
            return 0;
        }

        $unit ??= $this->tenantContext->healthUnit();
        $this->recoverStale->execute();

        $transmissions = LaboratoryOrderTransmission::query()
            ->with('integration')
            ->where('health_unit_id', $unit->getKey())
            ->whereIn('status', [
                LaboratoryTransmissionStatus::Pending->value,
                LaboratoryTransmissionStatus::Retrying->value,
            ])
            ->where(fn ($query) => $query->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))
            ->oldest('id')
            ->limit(100)
            ->get();
        $dispatched = 0;
        foreach ($transmissions as $transmission) {
            if (! $transmission->integration->is_active || ! $transmission->integration->transmission_enabled) {
                continue;
            }
            SubmitLaboratoryOrderJob::dispatch(
                (int) $transmission->getKey(),
                (string) $unit->public_id,
                $this->connectionManager->connectionName($unit),
            )->afterCommit();
            $dispatched++;
        }

        return $dispatched;
    }
}
