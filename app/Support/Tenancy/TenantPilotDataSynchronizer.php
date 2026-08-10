<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use App\Modules\Administration\Infrastructure\Eloquent\TenantDatabase;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;

final readonly class TenantPilotDataSynchronizer
{
    public function __construct(
        private TenantConnectionManager $connections,
        private TenantDataSet $dataSet,
        private TenantDatabaseLifecycle $lifecycle,
    ) {}

    /** @return array<string, int> */
    public function synchronize(TenantDatabase $tenantDatabase, User $actor): array
    {
        if ($tenantDatabase->stateEnum() !== TenantDatabaseState::Shadow) {
            throw new LogicException('A carga inicial só pode ser executada em SHADOW.');
        }

        $unit = $tenantDatabase->healthUnit()->with('organization')->firstOrFail();
        $source = DB::connection($this->connections->legacyConnectionName());
        $targetName = $this->connections->assertDedicatedConnectionAvailable($tenantDatabase);
        $target = DB::connection($targetName);
        $ids = [];
        $counts = [];
        Schema::connection($targetName)->disableForeignKeyConstraints();

        try {
            foreach ($this->dataSet->definitions() as $definition) {
                $table = $definition['table'];
                $ids[$table] = [];
                $counts[$table] = 0;
                $query = $this->dataSet->sourceQuery($source, $definition, $unit, $ids);
                foreach ($definition['unique'] as $column) {
                    $query->orderBy($column);
                }
                $query->chunk(500, function ($rows) use (
                    $target,
                    $definition,
                    $table,
                    &$ids,
                    &$counts,
                ): void {
                    $payload = $rows->map(static fn (object $row): array => (array) $row)->all();
                    if ($payload === []) {
                        return;
                    }
                    $columns = array_keys($payload[0]);
                    $target->table($table)->upsert(
                        $payload,
                        $definition['unique'],
                        array_values(array_diff($columns, $definition['unique'])),
                    );
                    if (array_key_exists('id', $payload[0])) {
                        array_push($ids[$table], ...array_column($payload, 'id'));
                    }
                    $counts[$table] += count($payload);
                });
            }
        } finally {
            Schema::connection($targetName)->enableForeignKeyConstraints();
        }

        $this->lifecycle->recordOperation(
            $tenantDatabase,
            $actor,
            'initial_data_synchronized',
            ['connection' => $targetName, 'table_counts' => $counts],
        );

        return $counts;
    }
}
