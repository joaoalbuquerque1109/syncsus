<?php

declare(strict_types=1);

namespace App\Modules\Laboratory\Application\Jobs;

use App\Modules\Administration\Infrastructure\Eloquent\HealthUnit;
use App\Modules\Laboratory\Application\Actions\ProcessSynclabExamResultAction;
use App\Modules\Laboratory\Application\Actions\RecordLaboratoryResultAuditAction;
use App\Modules\Laboratory\Domain\Enums\LaboratoryResultIngestionStatus;
use App\Modules\Laboratory\Infrastructure\Eloquent\LaboratoryResultIngestion;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

final class ProcessSynclabExamResultJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $uniqueFor = 600;

    public readonly int $ingestionId;

    public readonly string $healthUnitPublicId;

    public readonly string $tenantConnection;

    public function __construct(
        int $ingestionId,
        ?string $healthUnitPublicId = null,
        ?string $tenantConnection = null,
    ) {
        $context = app(TenantContext::class);
        $this->ingestionId = $ingestionId;
        $this->healthUnitPublicId = $healthUnitPublicId ?? (string) $context->healthUnit()->public_id;
        $this->tenantConnection = $tenantConnection ?? $context->connectionName();
        $this->onQueue((string) config('sync_sus.synclab.queue', 'integrations'));
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [30, 120];
    }

    public function uniqueId(): string
    {
        return $this->tenantConnection.':'.$this->ingestionId;
    }

    public function handle(ProcessSynclabExamResultAction $action, TenantContext $tenantContext): void
    {
        $previous = $this->capture($tenantContext);
        $this->activate($tenantContext);
        try {
            $action->execute($this->ingestionId);
        } finally {
            $this->restore($tenantContext, $previous);
        }
    }

    public function failed(?Throwable $exception): void
    {
        $tenantContext = app(TenantContext::class);
        $previous = $this->capture($tenantContext);
        $this->activate($tenantContext);
        try {
            $ingestion = LaboratoryResultIngestion::query()->find($this->ingestionId);
            if ($ingestion === null || $ingestion->statusEnum() !== LaboratoryResultIngestionStatus::Received) {
                return;
            }

            $ingestion->update([
                'status' => LaboratoryResultIngestionStatus::ManualReview,
                'error_code' => 'processing_deferred',
                'last_error' => mb_substr($exception?->getMessage() ?? 'Processamento interrompido.', 0, 2000),
            ]);
            app(RecordLaboratoryResultAuditAction::class)->execute(
                $ingestion,
                'laboratory.result_processing_deferred',
            );
        } finally {
            $this->restore($tenantContext, $previous);
        }
    }

    private function activate(TenantContext $tenantContext): void
    {
        $tenantContext->reset();
        $unit = HealthUnit::query()->where('public_id', $this->healthUnitPublicId)->firstOrFail();
        $tenantContext->resolve($unit, $this->tenantConnection);
    }

    /** @return array{HealthUnit, string}|null */
    private function capture(TenantContext $tenantContext): ?array
    {
        return $tenantContext->isResolved()
            ? [$tenantContext->healthUnit(), $tenantContext->connectionName()]
            : null;
    }

    /** @param array{HealthUnit, string}|null $previous */
    private function restore(TenantContext $tenantContext, ?array $previous): void
    {
        $tenantContext->reset();
        if ($previous !== null) {
            $tenantContext->resolve($previous[0], $previous[1]);
        }
    }
}
