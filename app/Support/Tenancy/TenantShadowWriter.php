<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final readonly class TenantShadowWriter
{
    /** @var list<string> */
    private const MIRRORED_PIVOTS = [
        'health_professional_queue',
        'health_professional_service_point',
        'panel_queue',
        'queue_service_point',
    ];

    public function __construct(
        private TenantContext $context,
        private TenantConnectionManager $connections,
    ) {}

    public function mirrorSaved(Model $model): void
    {
        $shadow = $this->shadowConnectionFor($model->getConnectionName(), $model->getTable());
        if ($shadow === null || ! $model->exists) {
            return;
        }

        $this->safely($shadow, $model->getTable(), function () use ($shadow, $model): void {
            DB::connection($shadow)->table($model->getTable())->updateOrInsert(
                [$model->getKeyName() => $model->getKey()],
                $model->getAttributes(),
            );
        });
    }

    public function mirrorDeleted(Model $model): void
    {
        $shadow = $this->shadowConnectionFor($model->getConnectionName(), $model->getTable());
        if ($shadow === null) {
            return;
        }

        $this->safely($shadow, $model->getTable(), function () use ($shadow, $model): void {
            DB::connection($shadow)->table($model->getTable())
                ->where($model->getKeyName(), $model->getKey())
                ->delete();
        });
    }

    public function mirrorPivotStatement(QueryExecuted $query): void
    {
        $table = $this->pivotTable($query->sql);
        if ($table === null) {
            return;
        }
        $shadow = $this->shadowConnectionFor($query->connectionName, $table);
        if ($shadow === null) {
            return;
        }

        $this->safely($shadow, $table, function () use ($shadow, $query): void {
            DB::connection($shadow)->statement($query->sql, $query->bindings);
        });
    }

    private function safely(string $shadowConnection, string $table, Closure $write): void
    {
        try {
            $write();
        } catch (Throwable $exception) {
            $this->reportFailure($shadowConnection, $table, $exception);
        }
    }

    private function shadowConnectionFor(?string $sourceConnection, string $table): ?string
    {
        try {
            if (! $this->context->isResolved()
                || $sourceConnection !== $this->connections->legacyConnectionName()) {
                return null;
            }

            return $this->connections->shadowConnectionName($this->context->healthUnit());
        } catch (Throwable $exception) {
            $this->reportFailure('unresolved', $table, $exception);

            return null;
        }
    }

    private function reportFailure(string $shadowConnection, string $table, Throwable $exception): void
    {
        report($exception);
        Log::warning('tenant.shadow_write_failed', [
            'health_unit_public_id' => $this->context->isResolved()
                ? $this->context->healthUnit()->public_id
                : null,
            'shadow_connection' => $shadowConnection,
            'table' => $table,
            'exception' => $exception::class,
        ]);
    }

    private function pivotTable(string $sql): ?string
    {
        if (preg_match('/\b(?:into|update|from)\s+[`"]?([a-z0-9_]+)[`"]?/i', $sql, $matches) !== 1) {
            return null;
        }
        $table = mb_strtolower($matches[1]);

        return in_array($table, self::MIRRORED_PIVOTS, true) ? $table : null;
    }
}
