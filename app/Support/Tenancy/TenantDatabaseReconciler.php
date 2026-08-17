<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use App\Modules\Administration\Infrastructure\Eloquent\TenantDatabase;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use LogicException;

final readonly class TenantDatabaseReconciler
{
    public function __construct(
        private TenantConnectionManager $connections,
        private TenantDataSet $dataSet,
        private TenantDatabaseLifecycle $lifecycle,
    ) {}

    /** @return array{status: string, tables: array<string, array{source_count: int, target_count: int, source_hash: string, target_hash: string, matched: bool}>} */
    public function reconcile(TenantDatabase $tenantDatabase, User $actor): array
    {
        if (! in_array($tenantDatabase->stateEnum(), [TenantDatabaseState::Shadow, TenantDatabaseState::Validating], true)) {
            throw new LogicException('A reconciliação só pode rodar em SHADOW ou VALIDATING.');
        }

        $unit = $tenantDatabase->healthUnit()->with('organization')->firstOrFail();
        $source = DB::connection($this->connections->legacyConnectionName());
        $target = DB::connection($this->connections->assertDedicatedConnectionAvailable($tenantDatabase));
        $ids = [];
        $tables = [];
        $matched = true;

        foreach ($this->dataSet->definitions() as $definition) {
            $table = $definition['table'];
            $ids[$table] = [];
            $sourceQuery = $this->dataSet->sourceQuery($source, $definition, $unit, $ids);
            [$sourceCount, $sourceHash, $sourceIds] = $this->fingerprint($sourceQuery, $definition['unique']);
            $ids[$table] = $sourceIds;
            [$targetCount, $targetHash] = $this->fingerprint($target->table($table), $definition['unique']);
            $tableMatched = $sourceCount === $targetCount && hash_equals($sourceHash, $targetHash);
            $tables[$table] = [
                'source_count' => $sourceCount,
                'target_count' => $targetCount,
                'source_hash' => $sourceHash,
                'target_hash' => $targetHash,
                'matched' => $tableMatched,
            ];
            $matched = $matched && $tableMatched;
        }

        $summary = ['status' => $matched ? 'matched' : 'diverged', 'tables' => $tables];
        $this->lifecycle->recordReconciliation(
            $tenantDatabase,
            $actor,
            $summary['status'],
            ['tables' => $tables, 'manifest_complete' => true],
        );

        return $summary;
    }

    /**
     * @param  list<string>  $unique
     * @return array{int, string, list<int|string>}
     */
    private function fingerprint(Builder $query, array $unique): array
    {
        foreach ($unique as $column) {
            $query->orderBy($column);
        }
        $hash = hash_init('sha256');
        $count = 0;
        $ids = [];
        foreach ($query->cursor() as $row) {
            $attributes = (array) $row;
            ksort($attributes);
            hash_update($hash, json_encode($attributes, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
            $count++;
            if (array_key_exists('id', $attributes)) {
                $ids[] = $attributes['id'];
            }
        }

        return [$count, hash_final($hash), $ids];
    }
}
