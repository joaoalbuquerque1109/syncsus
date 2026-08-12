<?php

declare(strict_types=1);

namespace App\Modules\Reports\Application\Jobs;

use App\Modules\Administration\Infrastructure\Eloquent\HealthUnit;
use App\Modules\Reports\Application\Queries\OperationalDashboardQuery;
use App\Modules\Reports\Infrastructure\Eloquent\UnitReportSnapshot;
use App\Support\Tenancy\TenantConnectionManager;
use App\Support\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

final class RefreshUnitReportSnapshotJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $uniqueFor = 240;

    public function __construct(public readonly string $healthUnitPublicId) {}

    public function uniqueId(): string
    {
        return $this->healthUnitPublicId;
    }

    public function handle(
        TenantContext $context,
        TenantConnectionManager $connections,
        OperationalDashboardQuery $dashboard,
    ): void {
        $unit = HealthUnit::query()->where('public_id', $this->healthUnitPublicId)->firstOrFail();
        $connection = $connections->connectionName($unit);
        Log::info('tenant.report_snapshot_started', [
            'health_unit_public_id' => $unit->public_id,
            'tenant_connection' => $connection,
        ]);
        $context->reset();
        $context->resolve($unit, $connection);

        try {
            $metrics = $dashboard->freshMetrics($unit);
            UnitReportSnapshot::query()->updateOrCreate(
                ['health_unit_id' => $unit->getKey(), 'period_date' => today()->toDateString()],
                [
                    'health_unit_public_id' => $unit->public_id,
                    'metrics' => $metrics,
                    'source_connection' => $connection,
                    'generated_at' => now(),
                ],
            );
            Log::info('tenant.report_snapshot_completed', [
                'health_unit_public_id' => $unit->public_id,
                'tenant_connection' => $connection,
            ]);
        } catch (Throwable $exception) {
            Log::error('tenant.report_snapshot_failed', [
                'health_unit_public_id' => $unit->public_id,
                'tenant_connection' => $connection,
                'exception' => $exception::class,
            ]);
            throw $exception;
        } finally {
            $context->reset();
        }
    }
}
