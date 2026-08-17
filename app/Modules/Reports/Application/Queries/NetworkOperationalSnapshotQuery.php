<?php

declare(strict_types=1);

namespace App\Modules\Reports\Application\Queries;

use App\Modules\Reports\Infrastructure\Eloquent\UnitReportSnapshot;

final class NetworkOperationalSnapshotQuery
{
    /** @return list<array<string, mixed>> */
    public function current(): array
    {
        $snapshots = UnitReportSnapshot::query()
            ->whereIn('id', UnitReportSnapshot::query()
                ->selectRaw('MAX(id)')
                ->groupBy('health_unit_id'))
            ->with('healthUnit')
            ->orderBy('health_unit_id')
            ->get();
        $rows = [];
        foreach ($snapshots as $snapshot) {
            $generatedAt = $snapshot->generatedAt();
            $rows[] = [
                'unit' => $snapshot->healthUnit,
                'metrics' => $snapshot->metricsPayload(),
                'generated_at' => $generatedAt,
                'stale' => $generatedAt->lt(now()->subMinutes(10)),
                'source_connection' => $snapshot->source_connection,
            ];
        }

        return $rows;
    }
}
