<?php

declare(strict_types=1);

namespace App\Modules\Laboratory\Application\Actions;

use App\Modules\Administration\Infrastructure\Eloquent\HealthUnit;
use App\Modules\Laboratory\Application\Jobs\ProcessSynclabExamResultJob;
use App\Modules\Laboratory\Domain\Enums\LaboratoryResultIngestionStatus;
use App\Modules\Laboratory\Infrastructure\Eloquent\LaboratoryIntegration;
use App\Modules\Laboratory\Infrastructure\Eloquent\LaboratoryResultIngestion;
use App\Support\Tenancy\TenantConnectionManager;
use App\Support\Tenancy\TenantContext;

final class DispatchReceivedLaboratoryResultsAction
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly TenantConnectionManager $connectionManager,
    ) {}

    public function execute(?HealthUnit $unit = null): int
    {
        if (! config('sync_sus.synclab.results_enabled')) {
            return 0;
        }

        $unit ??= $this->tenantContext->healthUnit();
        $integrationIds = LaboratoryIntegration::query()
            ->where('health_unit_id', $unit->getKey())
            ->pluck('id');
        $ingestions = LaboratoryResultIngestion::query()
            ->whereIn('laboratory_integration_id', $integrationIds)
            ->where('status', LaboratoryResultIngestionStatus::Received)
            ->where('received_at', '<=', now()->subMinute())
            ->oldest('id')
            ->limit(100)
            ->get();
        foreach ($ingestions as $ingestion) {
            ProcessSynclabExamResultJob::dispatch(
                (int) $ingestion->getKey(),
                (string) $unit->public_id,
                $this->connectionManager->connectionName($unit),
            )->afterCommit();
        }

        return $ingestions->count();
    }
}
