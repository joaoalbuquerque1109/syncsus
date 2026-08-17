<?php

declare(strict_types=1);

namespace App\Modules\Laboratory\Application\Jobs;

use App\Modules\Administration\Infrastructure\Eloquent\HealthUnit;
use App\Modules\Laboratory\Application\Actions\RecordLaboratoryTransmissionAuditAction;
use App\Modules\Laboratory\Application\Actions\SubmitLaboratoryOrderTransmissionAction;
use App\Modules\Laboratory\Domain\Enums\LaboratoryTransmissionStatus;
use App\Modules\Laboratory\Infrastructure\Eloquent\LaboratoryOrderTransmission;
use App\Support\Tenancy\TenantContext;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

final class SubmitLaboratoryOrderJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 4;

    public int $uniqueFor = 3600;

    public readonly int $transmissionId;

    public readonly string $healthUnitPublicId;

    public readonly string $tenantConnection;

    public function __construct(
        int $transmissionId,
        ?string $healthUnitPublicId = null,
        ?string $tenantConnection = null,
    ) {
        $context = app(TenantContext::class);
        $this->transmissionId = $transmissionId;
        $this->healthUnitPublicId = $healthUnitPublicId ?? (string) $context->healthUnit()->public_id;
        $this->tenantConnection = $tenantConnection ?? $context->connectionName();
        $this->onQueue((string) config('sync_sus.synclab.queue', 'integrations'));
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function retryUntil(): DateTimeInterface
    {
        return now()->addHours(6);
    }

    public function uniqueId(): string
    {
        return $this->tenantConnection.':'.$this->transmissionId;
    }

    public function handle(SubmitLaboratoryOrderTransmissionAction $action, TenantContext $tenantContext): void
    {
        $previous = $this->capture($tenantContext);
        $this->activate($tenantContext);
        try {
            $action->execute($this->transmissionId);
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
            $transmission = LaboratoryOrderTransmission::query()->find($this->transmissionId);
            if ($transmission === null || in_array($transmission->statusEnum(), [
                LaboratoryTransmissionStatus::Accepted,
                LaboratoryTransmissionStatus::Rejected,
                LaboratoryTransmissionStatus::Cancelled,
            ], true)) {
                return;
            }

            $message = mb_substr($exception?->getMessage() ?? 'Tentativas esgotadas.', 0, 2000);
            $transmission->update([
                'status' => LaboratoryTransmissionStatus::ManualReview,
                'next_attempt_at' => null,
                'worker_token' => null,
                'sending_started_at' => null,
                'lease_expires_at' => null,
                'error_code' => 'attempts_exhausted',
                'last_error' => $message,
            ]);
            $transmission->attempts()->first()?->update([
                'status' => 'manual_review',
                'error_code' => 'attempts_exhausted',
                'error_message' => $message,
                'finished_at' => now(),
            ]);
            app(RecordLaboratoryTransmissionAuditAction::class)->execute(
                $transmission,
                'laboratory.transmission_manual_review',
                ['error_code' => 'attempts_exhausted', 'attempts' => $transmission->attempt_count],
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
